<?php
/**
 * DropKey WP plugin bootstrap.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP;

use DropKeyWP\Admin\ProductAdmin;
use DropKeyWP\Admin\TestConsole;
use DropKeyWP\Application\CreateLicense;
use DropKeyWP\Application\CreateSubscriptionCheckout;
use DropKeyWP\Application\EnforcePastDueSubscriptions;
use DropKeyWP\Application\ProcessPaymentEvent;
use DropKeyWP\Application\SynchronizeSubscriptionEntitlement;
use DropKeyWP\Database\Installer;
use DropKeyWP\Database\Repositories\ActivationRepository;
use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Database\Repositories\GatewayEventRepository;
use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Database\Schema;
use DropKeyWP\Frontend\ProductCheckout;
use DropKeyWP\Gateways\GatewayManager;
use DropKeyWP\Gateways\PayPal\PayPalGateway;
use DropKeyWP\Gateways\PayPal\PayPalSettings;
use DropKeyWP\REST\CheckoutController;
use DropKeyWP\REST\LicenseController;
use DropKeyWP\REST\WebhookController;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static $instance = null;

	private $booted = false;

	private $gateway_manager;

	/**
	 * Get plugin instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	private function __clone() {}

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->maybe_upgrade_database();

		$this->gateway_manager = new GatewayManager();

		$product_checkout = new ProductCheckout(
			$this->gateway_manager
		);

		$product_checkout->register();

		add_action(
			'rest_api_init',
			array( $this, 'register_rest_routes' )
		);

		add_action(
			'dropkey_wp_subscription_activated',
			array( $this, 'handle_subscription_activated' )
		);

		add_action(
			'dropkey_wp_subscription_past_due',
			array( $this, 'synchronize_subscription_entitlement' )
		);

		add_action(
			'dropkey_wp_subscription_suspended',
			array( $this, 'synchronize_subscription_entitlement' )
		);

		add_action(
			'dropkey_wp_subscription_cancelled',
			array( $this, 'synchronize_subscription_entitlement' )
		);

		add_action(
			'dropkey_wp_subscription_expired',
			array( $this, 'synchronize_subscription_entitlement' )
		);

		add_action(
			'dropkey_wp_enforce_past_due_subscriptions',
			array( $this, 'enforce_past_due_subscriptions' )
		);

		$this->schedule_past_due_enforcement();

		add_action(
			'admin_menu',
			array( $this, 'register_settings_page' )
		);

		if ( is_admin() ) {
			global $wpdb;

			$product_repository = new ProductRepository( $wpdb );

			$product_admin = new ProductAdmin(
				$product_repository
			);

			$product_admin->register();
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$test_console = new TestConsole(
				$this->gateway_manager
			);

			$test_console->register();
		}

		do_action( 'dropkey_wp_loaded' );
	}

	/**
	 * Handle subscription activation.
	 *
	 * A license is created when a pending subscription becomes active.
	 *
	 * @param \DropKeyWP\Domain\Subscription $subscription Activated subscription.
	 * @return void
	 */
	public function handle_subscription_activated( $subscription ) {
		global $wpdb;

		$licenses      = new LicenseRepository( $wpdb );
		$subscriptions = new SubscriptionRepository( $wpdb );
		$products      = new ProductRepository( $wpdb );
		$plans         = new PlanRepository( $wpdb );

		$create_license = new CreateLicense(
			$licenses,
			$subscriptions,
			$products,
			$plans
		);

		$license = $create_license->execute(
			$subscription->get_id()
		);

		if ( is_wp_error( $license ) ) {
			/*
			 * Activation events are idempotent. If the license already
			 * exists, continue with entitlement synchronization.
			 */
			if ( 'dropkey_license_already_exists' !== $license->get_error_code() ) {
				do_action(
					'dropkey_wp_subscription_license_creation_failed',
					$subscription,
					$license
				);

				return;
			}
		}

		$this->synchronize_subscription_entitlement(
			$subscription
		);
	}

	/**
	 * Synchronize a subscription's entitlement with its license.
	 *
	 * @param \DropKeyWP\Domain\Subscription $subscription Subscription.
	 * @return void
	 */
	public function synchronize_subscription_entitlement( $subscription ) {
		global $wpdb;

		$licenses = new LicenseRepository( $wpdb );

		$service = new SynchronizeSubscriptionEntitlement(
			$licenses
		);

		$result = $service->execute( $subscription );

		if ( is_wp_error( $result ) ) {
			do_action(
				'dropkey_wp_subscription_entitlement_sync_failed',
				$subscription,
				$result
			);
		}
	}

	/**
	 * Schedule past-due subscription enforcement.
	 *
	 * @return void
	 */
	private function schedule_past_due_enforcement() {
		if ( ! wp_next_scheduled( 'dropkey_wp_enforce_past_due_subscriptions' ) ) {
			wp_schedule_event(
				time() + HOUR_IN_SECONDS,
				'hourly',
				'dropkey_wp_enforce_past_due_subscriptions'
			);
		}
	}

	/**
	 * Enforce expired payment grace periods.
	 *
	 * @return void
	 */
	public function enforce_past_due_subscriptions() {
		global $wpdb;

		$subscriptions = new SubscriptionRepository( $wpdb );

		$service = new EnforcePastDueSubscriptions(
			$subscriptions
		);

		$service->execute();
	}

	public function register_settings_page() {
		add_options_page(
			__( 'DropKey WP', 'dropkey-wp' ),
			__( 'DropKey WP', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp',
			array( $this, 'render_settings_page' )
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$message = '';
		$error   = '';

		if (
			isset( $_POST['dropkey_wp_save_paypal'] )
			&& check_admin_referer(
				'dropkey_wp_paypal_settings',
				'dropkey_wp_paypal_nonce'
			)
		) {
			$result = $this->save_paypal_settings();

			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$message = __( 'PayPal settings saved.', 'dropkey-wp' );
			}
		}

		if (
			isset( $_POST['dropkey_wp_test_paypal'] )
			&& check_admin_referer(
				'dropkey_wp_paypal_settings',
				'dropkey_wp_paypal_nonce'
			)
		) {
			$result = $this->test_paypal_connection();

			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$message = __(
					'PayPal connection successful. DropKey WP authenticated with PayPal.',
					'dropkey-wp'
				);
			}
		}

		$settings = PayPalSettings::get();

		?>

```
	<div class="wrap">
		<h1><?php echo esc_html__( 'DropKey WP', 'dropkey-wp' ); ?></h1>

		<?php if ( '' !== $message ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $error ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $error ); ?></p>
			</div>
		<?php endif; ?>

		<h2><?php echo esc_html__( 'PayPal', 'dropkey-wp' ); ?></h2>

		<form method="post">
			<?php
			wp_nonce_field(
				'dropkey_wp_paypal_settings',
				'dropkey_wp_paypal_nonce'
			);
			?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="dropkey_wp_paypal_environment">
							<?php echo esc_html__( 'Environment', 'dropkey-wp' ); ?>
						</label>
					</th>
					<td>
						<select
							name="dropkey_wp_paypal_environment"
							id="dropkey_wp_paypal_environment"
						>
							<option
								value="<?php echo esc_attr( PayPalSettings::ENVIRONMENT_SANDBOX ); ?>"
								<?php
								selected(
									$settings['environment'],
									PayPalSettings::ENVIRONMENT_SANDBOX
								);
								?>
							>
								<?php echo esc_html__( 'Sandbox', 'dropkey-wp' ); ?>
							</option>

							<option
								value="<?php echo esc_attr( PayPalSettings::ENVIRONMENT_LIVE ); ?>"
								<?php
								selected(
									$settings['environment'],
									PayPalSettings::ENVIRONMENT_LIVE
								);
								?>
							>
								<?php echo esc_html__( 'Live', 'dropkey-wp' ); ?>
							</option>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_paypal_client_id">
							<?php echo esc_html__( 'Client ID', 'dropkey-wp' ); ?>
						</label>
					</th>
					<td>
						<input
							type="text"
							name="dropkey_wp_paypal_client_id"
							id="dropkey_wp_paypal_client_id"
							class="regular-text"
							value="<?php echo esc_attr( $settings['client_id'] ); ?>"
							autocomplete="off"
						/>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_paypal_client_secret">
							<?php echo esc_html__( 'Client Secret', 'dropkey-wp' ); ?>
						</label>
					</th>
					<td>
						<input
							type="password"
							name="dropkey_wp_paypal_client_secret"
							id="dropkey_wp_paypal_client_secret"
							class="regular-text"
							value=""
							autocomplete="new-password"
						/>

						<p class="description">
							<?php
							echo esc_html__(
								'Leave blank to keep the existing client secret.',
								'dropkey-wp'
							);
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_paypal_webhook_id">
							<?php echo esc_html__( 'Webhook ID', 'dropkey-wp' ); ?>
						</label>
					</th>
					<td>
						<input
							type="text"
							name="dropkey_wp_paypal_webhook_id"
							id="dropkey_wp_paypal_webhook_id"
							class="regular-text"
							value="<?php echo esc_attr( $settings['webhook_id'] ); ?>"
							autocomplete="off"
						/>

						<p class="description">
							<?php
							echo esc_html__(
								'The Webhook ID assigned by PayPal to this webhook URL. This is not the PayPal Client ID.',
								'dropkey-wp'
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button
					type="submit"
					name="dropkey_wp_save_paypal"
					class="button button-primary"
					value="1"
				>
					<?php echo esc_html__( 'Save PayPal Settings', 'dropkey-wp' ); ?>
				</button>

				<button
					type="submit"
					name="dropkey_wp_test_paypal"
					class="button"
					value="1"
				>
					<?php echo esc_html__( 'Test PayPal Connection', 'dropkey-wp' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php
}

private function save_paypal_settings() {
	$environment = isset( $_POST['dropkey_wp_paypal_environment'] )
		? sanitize_key(
			wp_unslash( $_POST['dropkey_wp_paypal_environment'] )
		)
		: PayPalSettings::ENVIRONMENT_SANDBOX;

	$client_id = isset( $_POST['dropkey_wp_paypal_client_id'] )
		? sanitize_text_field(
			wp_unslash( $_POST['dropkey_wp_paypal_client_id'] )
		)
		: '';

	$client_secret = isset( $_POST['dropkey_wp_paypal_client_secret'] )
		? trim(
			wp_unslash( $_POST['dropkey_wp_paypal_client_secret'] )
		)
		: '';

	$webhook_id = isset( $_POST['dropkey_wp_paypal_webhook_id'] )
		? sanitize_text_field(
			wp_unslash( $_POST['dropkey_wp_paypal_webhook_id'] )
		)
		: '';

	return PayPalSettings::save(
		array(
			'environment'   => $environment,
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'webhook_id'    => $webhook_id,
		)
	);
}

private function test_paypal_connection() {
	$gateway = $this->gateway_manager->get( 'paypal' );

	if ( ! $gateway instanceof PayPalGateway ) {
		return new \WP_Error(
			'dropkey_paypal_gateway_unavailable',
			__(
				'The PayPal gateway is not available.',
				'dropkey-wp'
			)
		);
	}

	return $gateway->test_connection();
}

public function register_rest_routes() {
	global $wpdb;

	$licenses    = new LicenseRepository( $wpdb );
	$activations = new ActivationRepository( $wpdb );

	$license_controller = new LicenseController(
		$licenses,
		$activations
	);

	$license_controller->register_routes();

	$customers = new CustomerRepository( $wpdb );
	$products  = new ProductRepository( $wpdb );
	$plans     = new PlanRepository( $wpdb );

	$subscriptions = new SubscriptionRepository( $wpdb );

	$checkout = new CreateSubscriptionCheckout(
		$this->gateway_manager,
		$customers,
		$products,
		$plans,
		$subscriptions
	);

	$checkout_controller = new CheckoutController(
		$checkout
	);

	$checkout_controller->register_routes();

	$events = new GatewayEventRepository( $wpdb );

	$payment_event_processor = new ProcessPaymentEvent(
		$events,
		$subscriptions,
		$licenses
	);

	$webhook_controller = new WebhookController(
		$this->gateway_manager,
		$payment_event_processor
	);

	$webhook_controller->register_routes();
}

public function get_gateway_manager() {
	return $this->gateway_manager;
}

private function maybe_upgrade_database() {
	if ( Installer::get_version() < Schema::VERSION ) {
		Installer::install();
	}
}



}