<?php
/**
 * DropKey WP development test console.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Admin;

use DropKeyWP\Application\ActivateSubscription;
use DropKeyWP\Application\ChangeSubscriptionStatus;
use DropKeyWP\Application\CreateSubscription;
use DropKeyWP\Application\DeactivateLicense;
use DropKeyWP\Application\EnforcePastDueSubscriptions;
use DropKeyWP\Application\ProcessPaymentEvent;
use DropKeyWP\Database\Repositories\ActivationRepository;
use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Database\Repositories\GatewayEventRepository;
use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\Activation;
use DropKeyWP\Domain\Subscription;
use DropKeyWP\Gateways\GatewayManager;

defined( 'ABSPATH' ) || exit;

final class TestConsole {

	/**
	 * Current test subscription option.
	 *
	 * @var string
	 */
	private const TEST_SUBSCRIPTION_OPTION = 'dropkey_wp_test_subscription_id';

	/**
	 * Last manually entered PayPal subscription ID.
	 *
	 * @var string
	 */
	private const TEST_PAYPAL_SUBSCRIPTION_OPTION = 'dropkey_wp_test_paypal_subscription_id';

	/**
	 * Gateway manager.
	 *
	 * @var GatewayManager
	 */
	private $gateway_manager;

	/**
	 * Constructor.
	 *
	 * @param GatewayManager $gateway_manager Gateway manager.
	 */
	public function __construct( GatewayManager $gateway_manager ) {
		$this->gateway_manager = $gateway_manager;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_action(
			'admin_menu',
			array( $this, 'register_admin_page' )
		);
	}

	/**
	 * Register the admin page.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_management_page(
			__( 'DropKey WP Test Console', 'dropkey-wp' ),
			__( 'DropKey WP Test Console', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-test-console',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the test console.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$message = '';

		if (
			isset( $_POST['dropkey_wp_test_action'] )
			&& check_admin_referer( 'dropkey_wp_test_console' )
		) {
			$message = $this->handle_action(
				sanitize_key(
					wp_unslash( $_POST['dropkey_wp_test_action'] )
				)
			);
		}

		/*
		 * Always load the context after the requested action has completed.
		 * This ensures the Test Console displays the current database values,
		 * including values changed by the webhook simulator.
		 */
		$gateway_status = $this->get_gateway_status();
		$context        = $this->get_test_context();

		/*
		 * Resolve the PayPal-selected local subscription independently from
		 * the generic current test subscription. This allows the last manually
		 * entered PayPal subscription ID to drive the PayPal webhook simulator.
		 */
		$paypal_subscription_id       = $this->get_paypal_subscription_id();
		$paypal_local_subscription_id = $this->get_local_subscription_id_by_paypal_id(
			$paypal_subscription_id
		);

		/*
		 * The generic current subscription remains useful for the Test Gateway
		 * workflow. The PayPal-linked subscription is used when PayPal is the
		 * selected webhook gateway.
		 */
		$current_subscription_id = $this->get_current_subscription_id();

		$webhook_subscription_id = $paypal_local_subscription_id > 0
			? $paypal_local_subscription_id
			: $current_subscription_id;

		$webhook_subscription = null;

		if ( $webhook_subscription_id > 0 ) {
			$subscriptions = new SubscriptionRepository( $this->get_wpdb() );
			$webhook_subscription = $subscriptions->find(
				$webhook_subscription_id
			);
		}

		$webhook_gateway = $webhook_subscription
			? $webhook_subscription->get_gateway()
			: 'paypal';

		/*
		 * Load the raw database tables after all actions have completed.
		 * These queries intentionally read the live database so the console
		 * can be used instead of phpMyAdmin during development/testing.
		 */
		$database_state = $this->get_database_state();

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'DropKey WP Test Console', 'dropkey-wp' ); ?></h1>

			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-info is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<div
				style="
					background:#fff;
					border:1px solid #dcdcde;
					padding:20px;
					margin-top:20px;
					max-width:1100px;
				"
			>
				<h2 style="margin-top:0;">
					<?php echo esc_html__( 'Gateway Manager', 'dropkey-wp' ); ?>
				</h2>

				<?php if ( empty( $gateway_status ) ) : ?>
					<p>
						<?php echo esc_html__( 'No payment gateways are registered.', 'dropkey-wp' ); ?>
					</p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Gateway', 'dropkey-wp' ); ?></th>
								<th><?php echo esc_html__( 'ID', 'dropkey-wp' ); ?></th>
								<th><?php echo esc_html__( 'Available', 'dropkey-wp' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $gateway_status as $gateway ) : ?>
								<tr>
									<td>
										<strong>
											<?php echo esc_html( $gateway['name'] ); ?>
										</strong>
									</td>
									<td>
										<code>
											<?php echo esc_html( $gateway['id'] ); ?>
										</code>
									</td>
									<td>
										<?php if ( $gateway['available'] ) : ?>
											<span style="color:#008a20;font-weight:600;">
												<?php echo esc_html__( 'Yes', 'dropkey-wp' ); ?>
											</span>
										<?php else : ?>
											<span style="color:#b32d2e;font-weight:600;">
												<?php echo esc_html__( 'No — not configured', 'dropkey-wp' ); ?>
											</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div
				style="
					background:#fff;
					border:1px solid #dcdcde;
					padding:20px;
					margin-top:20px;
					max-width:1100px;
				"
			>
				<h2 style="margin-top:0;">
					<?php echo esc_html__( 'PayPal Subscription Check', 'dropkey-wp' ); ?>
				</h2>

				<p>
					<?php
					echo esc_html__(
						'Query the current status of a PayPal subscription directly from the PayPal API. The last manually entered PayPal subscription ID is remembered and becomes the default value for the next check.',
						'dropkey-wp'
					);
					?>
				</p>

				<form method="post">
					<?php wp_nonce_field( 'dropkey_wp_test_console' ); ?>

					<input
						type="hidden"
						name="dropkey_wp_test_action"
						value="check_paypal_subscription"
					/>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="dropkey-paypal-subscription-id">
										<?php echo esc_html__( 'PayPal Subscription ID', 'dropkey-wp' ); ?>
									</label>
								</th>
								<td>
									<input
										type="text"
										id="dropkey-paypal-subscription-id"
										name="paypal_subscription_id"
										value="<?php echo esc_attr( $paypal_subscription_id ); ?>"
										class="regular-text"
										autocomplete="off"
									/>
									<p class="description">
										<?php
										echo esc_html__(
											'Enter a PayPal subscription ID manually. After checking it, this value will be remembered until you replace it with another ID.',
											'dropkey-wp'
										);
										?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php
					submit_button(
						__( 'Check PayPal Subscription', 'dropkey-wp' ),
						'primary',
						'submit',
						false
					);
					?>
				</form>
			</div>

			<div
				style="
					background:#fff;
					border:1px solid #dcdcde;
					padding:20px;
					margin-top:20px;
					max-width:1100px;
				"
			>
				<h2 style="margin-top:0;">
					<?php echo esc_html__( 'Webhook Event Simulator', 'dropkey-wp' ); ?>
				</h2>

				<p>
					<?php
					echo esc_html__(
						'Simulate a normalized payment gateway event without contacting PayPal. The event is sent through the same ProcessPaymentEvent service used by the live webhook controller, including GatewayEvent recording, subscription status transitions, lifecycle hooks, and license entitlement synchronization.',
						'dropkey-wp'
					);
					?>
				</p>

				<form method="post">
					<?php wp_nonce_field( 'dropkey_wp_test_console' ); ?>

					<input
						type="hidden"
						name="dropkey_wp_test_action"
						value="simulate_webhook"
					/>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="dropkey-webhook-subscription-id">
										<?php echo esc_html__( 'DropKey Subscription', 'dropkey-wp' ); ?>
									</label>
								</th>
								<td>
									<input
										type="number"
										id="dropkey-webhook-subscription-id"
										name="webhook_subscription_id"
										value="<?php echo esc_attr( $webhook_subscription_id > 0 ? $webhook_subscription_id : '' ); ?>"
										class="small-text"
										min="1"
										step="1"
										readonly
										data-paypal-subscription-id="<?php echo esc_attr( $paypal_local_subscription_id > 0 ? $paypal_local_subscription_id : '' ); ?>"
										data-current-subscription-id="<?php echo esc_attr( $current_subscription_id > 0 ? $current_subscription_id : '' ); ?>"
									/>
									<p class="description">
										<?php if ( $paypal_local_subscription_id > 0 ) : ?>
											<?php
											echo esc_html__(
												'Automatically resolved from the PayPal Subscription ID above. This local subscription is linked to the entered PayPal subscription ID.',
												'dropkey-wp'
											);
											?>
										<?php elseif ( '' !== $paypal_subscription_id ) : ?>
											<?php
											echo esc_html__(
												'The saved PayPal subscription ID does not currently have a matching local DropKey subscription. No PayPal subscription can be selected until the local mapping exists.',
												'dropkey-wp'
											);
											?>
										<?php else : ?>
											<?php
											echo esc_html__(
												'No PayPal subscription has been selected yet. The current Test Console subscription is shown until a PayPal subscription is checked.',
												'dropkey-wp'
											);
											?>
										<?php endif; ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="dropkey-webhook-gateway">
										<?php echo esc_html__( 'Gateway', 'dropkey-wp' ); ?>
									</label>
								</th>
								<td>
									<select
										id="dropkey-webhook-gateway"
										name="webhook_gateway"
									>
										<?php foreach ( $gateway_status as $gateway ) : ?>
											<option
												value="<?php echo esc_attr( $gateway['id'] ); ?>"
												<?php selected( $webhook_gateway, $gateway['id'] ); ?>
											>
												<?php echo esc_html( $gateway['name'] . ' (' . $gateway['id'] . ')' ); ?>
											</option>
										<?php endforeach; ?>

										<?php if ( ! empty( $gateway_status ) ) : ?>
											<option
												value="test"
												<?php selected( 'test', $webhook_gateway ); ?>
											>
												<?php echo esc_html__( 'Test Gateway (test)', 'dropkey-wp' ); ?>
											</option>
										<?php endif; ?>
									</select>
									<p class="description">
										<?php
										echo esc_html__(
											'The gateway defaults to the gateway of the resolved subscription. You may select another gateway when testing a different current Test Console subscription.',
											'dropkey-wp'
										);
										?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="dropkey-webhook-event-type">
										<?php echo esc_html__( 'Event Type', 'dropkey-wp' ); ?>
									</label>
								</th>
								<td>
									<select
										id="dropkey-webhook-event-type"
										name="webhook_event_type"
									>
										<option value="BILLING.SUBSCRIPTION.ACTIVATED">
											BILLING.SUBSCRIPTION.ACTIVATED
										</option>

										<option value="BILLING.SUBSCRIPTION.PAYMENT.SUCCEEDED">
											BILLING.SUBSCRIPTION.PAYMENT.SUCCEEDED
										</option>

										<option value="BILLING.SUBSCRIPTION.PAYMENT.FAILED">
											BILLING.SUBSCRIPTION.PAYMENT.FAILED
										</option>

										<option value="BILLING.SUBSCRIPTION.SUSPENDED">
											BILLING.SUBSCRIPTION.SUSPENDED
										</option>

										<option value="BILLING.SUBSCRIPTION.CANCELLED">
											BILLING.SUBSCRIPTION.CANCELLED
										</option>

										<option value="BILLING.SUBSCRIPTION.EXPIRED">
											BILLING.SUBSCRIPTION.EXPIRED
										</option>

										<option value="BILLING.SUBSCRIPTION.UPDATED">
											BILLING.SUBSCRIPTION.UPDATED
										</option>
									</select>

									<p class="description">
										<?php
										echo esc_html__(
											'Lifecycle events are passed through ProcessPaymentEvent. UPDATED is included to test valid events that do not currently change the local subscription status.',
											'dropkey-wp'
										);
										?>
									</p>
								</th>
							</tr>

							<tr>
								<th scope="row">
									<label for="dropkey-webhook-event-id">
										<?php echo esc_html__( 'Event ID', 'dropkey-wp' ); ?>
									</label>
								</th>
								<td>
									<input
										type="text"
										id="dropkey-webhook-event-id"
										name="webhook_event_id"
										value="<?php echo esc_attr( 'TEST-' . strtoupper( wp_generate_uuid4() ) ); ?>"
										class="regular-text"
										autocomplete="off"
									/>
									<p class="description">
										<?php
										echo esc_html__(
											'Use a new ID for a fresh event. Reusing the same ID tests duplicate-event handling.',
											'dropkey-wp'
										);
										?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php
					submit_button(
						__( 'Simulate Webhook Event', 'dropkey-wp' ),
						'primary',
						'submit',
						false
					);
					?>
				</form>
			</div>

			<div
				style="
					background:#fff;
					border:1px solid #dcdcde;
					padding:20px;
					margin-top:20px;
					max-width:1100px;
				"
			>
				<h2 style="margin-top:0;">
					<?php echo esc_html__( 'Test Data', 'dropkey-wp' ); ?>
				</h2>

				<p>
					<?php
					echo esc_html__(
						'Create a fresh subscription and license using the existing test customer, product, and plan.',
						'dropkey-wp'
					);
					?>
				</p>

				<form method="post">
					<?php wp_nonce_field( 'dropkey_wp_test_console' ); ?>

					<input
						type="hidden"
						name="dropkey_wp_test_action"
						value="create_fresh"
					/>

					<?php
					submit_button(
						__( 'Create Fresh Test Data', 'dropkey-wp' ),
						'primary',
						'submit',
						false
					);
					?>
				</form>
			</div>

			<?php if ( $context ) : ?>
				<div
					style="
						background:#fff;
						border:1px solid #dcdcde;
						padding:20px;
						margin-top:20px;
						max-width:1100px;
					"
				>
					<h2 style="margin-top:0;">
						<?php echo esc_html__( 'Database State', 'dropkey-wp' ); ?>
					</h2>

					<p>
						<?php
						echo esc_html__(
							'Read-only view of the current subscription, license, and activation records used by the test console. The values below are loaded directly from the DropKey database after every console action.',
							'dropkey-wp'
						);
						?>
					</p>

					<form method="post" style="margin-bottom:20px;">
						<?php wp_nonce_field( 'dropkey_wp_test_console' ); ?>

						<input
							type="hidden"
							name="dropkey_wp_test_action"
							value="refresh_database_state"
						/>

						<?php
						submit_button(
							__( 'Refresh Database State', 'dropkey-wp' ),
							'secondary',
							'submit',
							false
						);
						?>
					</form>

					<h3>
						<?php echo esc_html__( 'Current Subscription', 'dropkey-wp' ); ?>
					</h3>

					<table class="widefat striped">
						<tbody>
							<tr>
								<th style="width:220px;">
									<?php echo esc_html__( 'ID', 'dropkey-wp' ); ?>
								</th>
								<td>
									<code>
										#<?php echo esc_html( $context['subscription']->get_id() ); ?>
									</code>
								</td>
							</tr>

							<tr>
								<th>
									<?php echo esc_html__( 'Product', 'dropkey-wp' ); ?>
								</th>
								<td>
									<?php echo esc_html( $context['product']->get_name() ); ?>
									<code>#<?php echo esc_html( $context['product']->get_id() ); ?></code>
								</td>
							</tr>

							<tr>
								<th>
									<?php echo esc_html__( 'Plan', 'dropkey-wp' ); ?>
								</th>
								<td>
									<?php echo esc_html( $context['plan']->get_name() ); ?>
									<code>#<?php echo esc_html( $context['plan']->get_id() ); ?></code>
								</td>
							</tr>

							<tr>
								<th>
									<?php echo esc_html__( 'Gateway', 'dropkey-wp' ); ?>
								</th>
								<td>
									<code>
										<?php echo esc_html( $context['subscription']->get_gateway() ); ?>
									</code>
								</td>
							</tr>

							<tr>
								<th>
									<?php echo esc_html__( 'Gateway Subscription ID', 'dropkey-wp' ); ?>
								</th>
								<td>
									<code>
										<?php echo esc_html( $context['subscription']->get_gateway_subscription_id() ); ?>
									</code>
								</td>
							</tr>

							<tr>
								<th>
									<?php echo esc_html__( 'Status', 'dropkey-wp' ); ?>
								</th>
								<td>
									<strong>
										<?php echo esc_html( $context['subscription']->get_status() ); ?>
									</strong>
								</td>
							</tr>

							<tr>
								<th>
									<?php echo esc_html__( 'Current Period Start', 'dropkey-wp' ); ?>
								</th>
								<td>
									<code>
										<?php
										echo esc_html(
											$context['subscription']->get_current_period_start()
										);
										?>
									</code>
								</td>
							</tr>

							<tr>
								<th>
									<?php echo esc_html__( 'Current Period End', 'dropkey-wp' ); ?>
								</th>
								<td>
									<strong>
										<code>
											<?php
											echo esc_html(
												$context['subscription']->get_current_period_end()
											);
											?>
										</code>
									</strong>
								</td>
							</tr>

							<?php if ( $context['subscription']->get_past_due_at() ) : ?>
								<tr>
									<th>
										<?php echo esc_html__( 'Past Due At', 'dropkey-wp' ); ?>
									</th>
									<td>
										<code>
											<?php echo esc_html( $context['subscription']->get_past_due_at() ); ?>
										</code>
									</td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>

					<h3 style="margin-top:24px;">
						<?php echo esc_html__( 'Current License', 'dropkey-wp' ); ?>
					</h3>

					<?php if ( $context['license'] ) : ?>
						<table class="widefat striped">
							<tbody>
								<tr>
									<th style="width:220px;">
										<?php echo esc_html__( 'ID', 'dropkey-wp' ); ?>
									</th>
									<td>
										<code>
											#<?php echo esc_html( $context['license']->get_id() ); ?>
										</code>
									</td>
								</tr>

								<tr>
									<th>
										<?php echo esc_html__( 'License Key', 'dropkey-wp' ); ?>
									</th>
									<td>
										<code>
											<?php echo esc_html( $context['license']->get_license_key() ); ?>
										</code>
									</td>
								</tr>

								<tr>
									<th>
										<?php echo esc_html__( 'Status', 'dropkey-wp' ); ?>
									</th>
									<td>
										<strong>
											<?php echo esc_html( $context['license']->get_status() ); ?>
										</strong>
									</td>
								</tr>

								<tr>
									<th>
										<?php echo esc_html__( 'Activation Limit', 'dropkey-wp' ); ?>
									</th>
									<td>
										<?php echo esc_html( $context['license']->get_activation_limit() ); ?>
									</td>
								</tr>

								<tr>
									<th>
										<?php echo esc_html__( 'Expires At', 'dropkey-wp' ); ?>
									</th>
									<td>
										<strong>
											<code>
												<?php echo esc_html( $context['license']->get_expires_at() ); ?>
											</code>
										</strong>
									</td>
								</tr>

								<tr>
									<th>
										<?php echo esc_html__( 'Subscription ID', 'dropkey-wp' ); ?>
									</th>
									<td>
										<code>
											#<?php echo esc_html( $context['subscription']->get_id() ); ?>
										</code>
									</td>
								</tr>
							</tbody>
						</table>
					<?php else : ?>
						<p>
							<?php echo esc_html__( 'No license is associated with this subscription.', 'dropkey-wp' ); ?>
						</p>
					<?php endif; ?>

					<h3 style="margin-top:24px;">
						<?php echo esc_html__( 'Current Activation', 'dropkey-wp' ); ?>
					</h3>

					<?php if ( $context['activation'] ) : ?>
						<table class="widefat striped">
							<tbody>
								<tr>
									<th style="width:220px;">
										<?php echo esc_html__( 'ID', 'dropkey-wp' ); ?>
									</th>
									<td>
										<code>
											#<?php echo esc_html( $context['activation']->get_id() ); ?>
										</code>
									</td>
								</tr>

								<tr>
									<th>
										<?php echo esc_html__( 'Status', 'dropkey-wp' ); ?>
									</th>
									<td>
										<strong>
											<?php echo esc_html( $context['activation']->get_status() ); ?>
										</strong>
									</td>
								</tr>

								<tr>
									<th>
										<?php echo esc_html__( 'License ID', 'dropkey-wp' ); ?>
									</th>
									<td>
										<code>
											#<?php echo esc_html( $context['license']->get_id() ); ?>
										</code>
									</td>
								</tr>
							</tbody>
						</table>
					<?php else : ?>
						<p>
							<?php echo esc_html__( 'No activation is associated with this license.', 'dropkey-wp' ); ?>
						</p>
					<?php endif; ?>

					<?php $this->render_database_table( $database_state['subscriptions'] ); ?>

					<?php $this->render_database_table( $database_state['licenses'] ); ?>
				</div>

				<div
					style="
						background:#fff;
						border:1px solid #dcdcde;
						padding:20px;
						margin-top:20px;
						max-width:1100px;
					"
				>
					<h2 style="margin-top:0;">
						<?php echo esc_html__( 'Subscription Lifecycle', 'dropkey-wp' ); ?>
					</h2>

					<?php $next_statuses = $this->get_next_statuses( $context['subscription']->get_status() ); ?>

					<?php if ( empty( $next_statuses ) ) : ?>
						<p>
							<?php echo esc_html__( 'No further lifecycle transitions are available for this subscription.', 'dropkey-wp' ); ?>
						</p>
					<?php else : ?>
						<div style="display:flex;gap:8px;flex-wrap:wrap;">
							<?php foreach ( $next_statuses as $status ) : ?>
								<form method="post">
									<?php wp_nonce_field( 'dropkey_wp_test_console' ); ?>

									<input
										type="hidden"
										name="dropkey_wp_test_action"
										value="change_status"
									/>

									<input
										type="hidden"
										name="status"
										value="<?php echo esc_attr( $status ); ?>"
									/>

									<?php
									submit_button(
										sprintf(
											/* translators: %s: subscription status. */
											__( 'Set %s', 'dropkey-wp' ),
											$status
										),
										'secondary',
										'submit',
										false
									);
									?>
								</form>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ( Subscription::STATUS_PAST_DUE === $context['subscription']->get_status() ) : ?>
						<div style="margin-top:16px;">
							<form method="post">
								<?php wp_nonce_field( 'dropkey_wp_test_console' ); ?>

								<input
									type="hidden"
									name="dropkey_wp_test_action"
									value="expire_grace_period"
								/>

								<?php
								submit_button(
									__( 'Expire 7-Day Grace Period', 'dropkey-wp' ),
									'secondary',
									'submit',
									false
								);
								?>
							</form>

							<p class="description">
								<?php
								echo esc_html__(
									'Moves the recorded past-due timestamp back more than 7 days and immediately runs grace-period enforcement.',
									'dropkey-wp'
								);
								?>
							</p>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( $context['activation'] ) : ?>
					<div
						style="
							background:#fff;
							border:1px solid #dcdcde;
							padding:20px;
							margin-top:20px;
							max-width:1100px;
						"
					>
						<h2 style="margin-top:0;">
							<?php echo esc_html__( 'Activation', 'dropkey-wp' ); ?>
						</h2>

						<?php if ( Activation::STATUS_ACTIVE === $context['activation']->get_status() ) : ?>
							<form method="post">
								<?php wp_nonce_field( 'dropkey_wp_test_console' ); ?>

								<input
									type="hidden"
									name="dropkey_wp_test_action"
									value="deactivate_activation"
								/>

								<input
									type="hidden"
									name="activation_id"
									value="<?php echo esc_attr( $context['activation']->get_id() ); ?>"
								/>

								<?php
								submit_button(
									__( 'Deactivate Activation', 'dropkey-wp' ),
									'secondary',
									'submit',
									false
								);
								?>
							</form>
						<?php else : ?>
							<form method="post">
								<?php wp_nonce_field( 'dropkey_wp_test_console' ); ?>

								<input
									type="hidden"
									name="dropkey_wp_test_action"
									value="reactivate_activation"
								/>

								<input
									type="hidden"
									name="activation_id"
									value="<?php echo esc_attr( $context['activation']->get_id() ); ?>"
								/>

								<?php
								submit_button(
									__( 'Reactivate Activation', 'dropkey-wp' ),
									'secondary',
									'submit',
									false
								);
								?>
							</form>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const gatewayField = document.getElementById('dropkey-webhook-gateway');
				const subscriptionField = document.getElementById('dropkey-webhook-subscription-id');

				if (!gatewayField || !subscriptionField) {
					return;
				}

				const paypalSubscriptionId = subscriptionField.dataset.paypalSubscriptionId || '';
				const currentSubscriptionId = subscriptionField.dataset.currentSubscriptionId || '';

				function syncWebhookSubscription() {
					if ('paypal' === gatewayField.value && paypalSubscriptionId) {
						subscriptionField.value = paypalSubscriptionId;
						return;
					}

					subscriptionField.value = currentSubscriptionId;
				}

				gatewayField.addEventListener('change', syncWebhookSubscription);

				syncWebhookSubscription();
			});
		</script>
		<?php
	}

	/**
	 * Render a raw database table.
	 *
	 * The table definition and columns are read directly from the database
	 * so the Test Console reflects the actual installed schema.
	 *
	 * @param array $table_state Database table state.
	 * @return void
	 */
	private function render_database_table( array $table_state ) {
		?>
		<div style="margin-top:32px;">
			<div
				style="
					display:flex;
					align-items:center;
					justify-content:space-between;
					gap:16px;
					flex-wrap:wrap;
					margin-bottom:10px;
				"
			>
				<h3 style="margin:0;">
					<?php echo esc_html( $table_state['label'] ); ?>
				</h3>

				<?php if ( ! empty( $table_state['table_name'] ) ) : ?>
					<code>
						<?php echo esc_html( $table_state['table_name'] ); ?>
					</code>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $table_state['error'] ) ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php echo esc_html( $table_state['error'] ); ?>
					</p>
				</div>
			<?php elseif ( empty( $table_state['columns'] ) ) : ?>
				<p>
					<?php echo esc_html__( 'No columns were found for this table.', 'dropkey-wp' ); ?>
				</p>
			<?php elseif ( empty( $table_state['rows'] ) ) : ?>
				<div
					style="
						border:1px solid #dcdcde;
						background:#f6f7f7;
						padding:14px 16px;
					"
				>
					<?php echo esc_html__( 'The table currently contains no entries.', 'dropkey-wp' ); ?>
				</div>
			<?php else : ?>
				<div
					style="
						overflow-x:auto;
						border:1px solid #dcdcde;
						background:#fff;
					"
				>
					<table
						class="widefat striped"
						style="min-width:max-content;"
					>
						<thead>
							<tr>
								<?php foreach ( $table_state['columns'] as $column ) : ?>
									<th
										style="
											white-space:nowrap;
											vertical-align:top;
										"
									>
										<code>
											<?php echo esc_html( $column ); ?>
										</code>
									</th>
								<?php endforeach; ?>
							</tr>
						</thead>

						<tbody>
							<?php foreach ( $table_state['rows'] as $row ) : ?>
								<?php
								$is_current = false;

								if (
									'subscriptions' === $table_state['key']
									&& isset( $row['id'] )
									&& (int) $row['id'] === $this->get_current_subscription_id()
								) {
									$is_current = true;
								}

								if (
									'licenses' === $table_state['key']
									&& isset( $row['subscription_id'] )
									&& (int) $row['subscription_id'] === $this->get_current_subscription_id()
								) {
									$is_current = true;
								}
								?>

								<tr
									<?php if ( $is_current ) : ?>
										style="box-shadow:inset 4px 0 0 #2271b1;"
									<?php endif; ?>
								>
									<?php foreach ( $table_state['columns'] as $column ) : ?>
										<td
											style="
												vertical-align:top;
												white-space:nowrap;
												max-width:500px;
											"
										>
											<?php
											$value = isset( $row[ $column ] )
												? $row[ $column ]
												: '';
											?>

											<?php if ( '' === (string) $value || null === $value ) : ?>
												<em style="color:#646970;">
													<?php echo esc_html__( 'NULL / empty', 'dropkey-wp' ); ?>
												</em>
											<?php elseif ( 'license_key' === $column ) : ?>
												<code>
													<?php echo esc_html( $value ); ?>
												</code>
											<?php elseif ( 'payload' === $column ) : ?>
												<code
													style="
														display:block;
														white-space:normal;
														word-break:break-word;
														max-width:600px;
													"
												>
													<?php echo esc_html( $value ); ?>
												</code>
											<?php else : ?>
												<code>
													<?php echo esc_html( $value ); ?>
												</code>
											<?php endif; ?>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<p class="description" style="margin-top:8px;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of rows. */
							__( '%d entries found. The table is read-only.', 'dropkey-wp' ),
							count( $table_state['rows'] )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get raw database state for the tables used by the console.
	 *
	 * @return array
	 */
	private function get_database_state() {
		global $wpdb;

		return array(
			'subscriptions' => $this->get_database_table(
				$wpdb->prefix . 'dropkey_subscriptions',
				__( 'All Subscriptions', 'dropkey-wp' ),
				'subscriptions'
			),
			'licenses'      => $this->get_database_table(
				$wpdb->prefix . 'dropkey_licenses',
				__( 'All Licenses', 'dropkey-wp' ),
				'licenses'
			),
		);
	}

	/**
	 * Get a raw database table.
	 *
	 * @param string $table_name Full database table name.
	 * @param string $label      Display label.
	 * @param string $key        Internal table key.
	 * @return array
	 */
	private function get_database_table(
		$table_name,
		$label,
		$key
	) {
		global $wpdb;

		$state = array(
			'key'        => $key,
			'label'      => $label,
			'table_name' => $table_name,
			'columns'    => array(),
			'rows'       => array(),
			'error'      => '',
		);

		/*
		 * Table names are constructed internally from the WordPress database
		 * prefix and fixed DropKey table names. They are never taken from
		 * request data.
		 */
		$columns = $wpdb->get_results(
			"SHOW COLUMNS FROM {$table_name}",
			ARRAY_A
		);

		if ( null === $columns ) {
			$state['error'] = sprintf(
				/* translators: %s: database error. */
				__( 'The database table could not be inspected: %s', 'dropkey-wp' ),
				$wpdb->last_error
			);

			return $state;
		}

		foreach ( $columns as $column ) {
			if ( isset( $column['Field'] ) ) {
				$state['columns'][] = $column['Field'];
			}
		}

		if ( empty( $state['columns'] ) ) {
			return $state;
		}

		$rows = $wpdb->get_results(
			"SELECT * FROM {$table_name} ORDER BY id DESC",
			ARRAY_A
		);

		if ( null === $rows ) {
			$state['error'] = sprintf(
				/* translators: %s: database error. */
				__( 'The database table could not be read: %s', 'dropkey-wp' ),
				$wpdb->last_error
			);

			return $state;
		}

		$state['rows'] = $rows;

		return $state;
	}

	/**
	 * Handle a console action.
	 *
	 * @param string $action Action name.
	 * @return string
	 */
	private function handle_action( $action ) {
		switch ( $action ) {
			case 'create_fresh':
				return $this->create_fresh_test_data();

			case 'check_paypal_subscription':
				return $this->check_paypal_subscription();

			case 'simulate_webhook':
				return $this->simulate_webhook_event();

			case 'refresh_database_state':
				return __( 'Database state refreshed.', 'dropkey-wp' );

			case 'change_status':
				return $this->change_subscription_status();

			case 'expire_grace_period':
				return $this->expire_grace_period();

			case 'deactivate_activation':
				return $this->deactivate_activation();

			case 'reactivate_activation':
				return $this->reactivate_activation();

			default:
				return __( 'Unknown test action.', 'dropkey-wp' );
		}
	}

	/**
	 * Simulate a normalized webhook event.
	 *
	 * @return string
	 */
	private function simulate_webhook_event() {
		global $wpdb;

		$subscription_id = isset( $_POST['webhook_subscription_id'] )
			? absint( $_POST['webhook_subscription_id'] )
			: 0;

		$gateway = isset( $_POST['webhook_gateway'] )
			? sanitize_key( wp_unslash( $_POST['webhook_gateway'] ) )
			: '';

		$event_type = isset( $_POST['webhook_event_type'] )
			? sanitize_text_field( wp_unslash( $_POST['webhook_event_type'] ) )
			: '';

		$event_id = isset( $_POST['webhook_event_id'] )
			? sanitize_text_field( wp_unslash( $_POST['webhook_event_id'] ) )
			: '';

		/*
		 * When PayPal is selected, always resolve the local subscription from
		 * the saved PayPal Subscription ID instead of trusting a stale or
		 * manually supplied local subscription ID.
		 */
		$paypal_subscription_id = $this->get_paypal_subscription_id();

		if ( 'paypal' === $gateway && '' !== $paypal_subscription_id ) {
			$resolved_subscription_id = $this->get_local_subscription_id_by_paypal_id(
				$paypal_subscription_id
			);

			if ( $resolved_subscription_id <= 0 ) {
				return sprintf(
					/* translators: %s: PayPal subscription ID. */
					__(
						'PayPal subscription %s is not linked to a local DropKey subscription. The webhook was not simulated.',
						'dropkey-wp'
					),
					$paypal_subscription_id
				);
			}

			$subscription_id = $resolved_subscription_id;
		}

		if ( $subscription_id <= 0 ) {
			return __( 'A valid DropKey subscription ID is required.', 'dropkey-wp' );
		}

		if ( '' === $gateway ) {
			return __( 'A gateway is required.', 'dropkey-wp' );
		}

		if ( '' === $event_type ) {
			return __( 'An event type is required.', 'dropkey-wp' );
		}

		if ( '' === $event_id ) {
			$event_id = 'TEST-' . strtoupper( wp_generate_uuid4() );
		}

		$subscriptions = new SubscriptionRepository( $wpdb );
		$subscription  = $subscriptions->find( $subscription_id );

		if ( ! $subscription ) {
			return sprintf(
				/* translators: %d: subscription ID. */
				__( 'DropKey subscription #%d was not found.', 'dropkey-wp' ),
				$subscription_id
			);
		}

		$subscription_gateway = $subscription->get_gateway();

		if ( $subscription_gateway !== $gateway ) {
			return sprintf(
				/* translators: 1: subscription ID, 2: gateway. */
				__(
					'Subscription #%1$d belongs to the "%2$s" gateway. Select that gateway for the simulated event.',
					'dropkey-wp'
				),
				$subscription_id,
				$subscription_gateway
			);
		}

		$gateway_subscription_id = $subscription->get_gateway_subscription_id();

		if ( '' === $gateway_subscription_id ) {
			return sprintf(
				/* translators: %d: subscription ID. */
				__(
					'Subscription #%d does not have a gateway subscription ID.',
					'dropkey-wp'
				),
				$subscription_id
			);
		}

		$resource = $this->get_simulated_webhook_resource(
			$subscription,
			$event_type
		);

		if ( is_wp_error( $resource ) ) {
			return $resource->get_error_message();
		}

		$payload = wp_json_encode(
			array(
				'id'            => $event_id,
				'create_time'   => gmdate( 'c' ),
				'resource_type' => 'subscription',
				'event_type'    => $event_type,
				'summary'       => 'DropKey WP simulated webhook event',
				'resource'      => $resource,
				'event_version' => '1.0',
			)
		);

		if ( false === $payload ) {
			return __( 'The simulated webhook payload could not be created.', 'dropkey-wp' );
		}

		$events   = new GatewayEventRepository( $wpdb );
		$licenses = new LicenseRepository( $wpdb );

		$processor = new ProcessPaymentEvent(
			$events,
			$subscriptions,
			$licenses
		);

		$result = $processor->execute(
			array(
				'gateway'    => $gateway,
				'event_id'   => $event_id,
				'event_type' => $event_type,
				'payload'    => $payload,
			)
		);

		if ( is_wp_error( $result ) ) {
			return sprintf(
				/* translators: 1: event type, 2: error message. */
				__(
					'Webhook simulation failed. Event: %1$s. Error: %2$s',
					'dropkey-wp'
				),
				$event_type,
				$result->get_error_message()
			);
		}

		/*
		 * Keep the processed subscription as the current Test Console
		 * subscription so Database State, Lifecycle, and Activation sections
		 * all immediately refer to the same record.
		 */
		update_option(
			self::TEST_SUBSCRIPTION_OPTION,
			$subscription_id,
			false
		);

		$updated_subscription = $subscriptions->find(
			$subscription_id
		);

		$status = $updated_subscription
			? $updated_subscription->get_status()
			: 'unknown';

		return sprintf(
			/* translators: 1: event type, 2: event ID, 3: event status, 4: subscription ID, 5: subscription status. */
			__(
				'Webhook processed successfully. Event: %1$s. Event ID: %2$s. Event status: %3$s. Subscription #%4$d status: %5$s. Database state refreshed.',
				'dropkey-wp'
			),
			$event_type,
			$result->get_event_id(),
			$result->get_status(),
			$subscription_id,
			$status
		);
	}

	/**
	 * Build a realistic simulated provider resource.
	 *
	 * @param Subscription $subscription Local subscription.
	 * @param string       $event_type Event type.
	 * @return array|\WP_Error
	 */
	private function get_simulated_webhook_resource(
		Subscription $subscription,
		$event_type
	) {
		$gateway_subscription_id = $subscription->get_gateway_subscription_id();

		$resource = array(
			'id'      => $gateway_subscription_id,
			'status'  => $this->get_simulated_provider_status( $event_type ),
			'plan_id' => 'TEST-PLAN',
		);

		if ( 'paypal' !== $subscription->get_gateway() ) {
			return $resource;
		}

		if ( 'BILLING.SUBSCRIPTION.ACTIVATED' === $event_type ) {
			$period_start = $subscription->get_current_period_start();
			$period_end   = $subscription->get_current_period_end();

			if ( '' === $period_start || '' === $period_end ) {
				return new \WP_Error(
					'dropkey_test_missing_period',
					__(
						'The simulated PayPal activation requires current subscription period dates.',
						'dropkey-wp'
					)
				);
			}

			$resource['start_time'] = $this->mysql_datetime_to_paypal(
				$period_start
			);

			$resource['billing_info'] = array(
				'next_billing_time' => $this->mysql_datetime_to_paypal(
					$period_end
				),
			);

			return $resource;
		}

		if ( 'BILLING.SUBSCRIPTION.PAYMENT.SUCCEEDED' === $event_type ) {
			$current_period_end = $subscription->get_current_period_end();

			if ( '' === $current_period_end ) {
				return new \WP_Error(
					'dropkey_test_missing_period',
					__(
						'The simulated PayPal renewal requires a current subscription period end date.',
						'dropkey-wp'
					)
				);
			}

			$period_start = $this->create_simulated_next_period_start(
				$current_period_end
			);

			$period_end = $this->create_simulated_next_period_end(
				$period_start
			);

			if ( '' === $period_start || '' === $period_end ) {
				return new \WP_Error(
					'dropkey_test_invalid_period',
					__(
						'The simulated PayPal renewal period could not be calculated.',
						'dropkey-wp'
					)
				);
			}

			$resource['billing_info'] = array(
				'last_payment' => array(
					'time' => $this->mysql_datetime_to_paypal(
						$period_start
					),
				),
				'next_billing_time' => $this->mysql_datetime_to_paypal(
					$period_end
				),
			);

			return $resource;
		}

		return $resource;
	}

	/**
	 * Create the simulated next period start.
	 *
	 * @param string $current_period_end Current period end.
	 * @return string
	 */
	private function create_simulated_next_period_start(
		$current_period_end
	) {
		try {
			$date = new \DateTimeImmutable(
				$current_period_end,
				new \DateTimeZone( 'UTC' )
			);

			return $date->setTimezone(
				new \DateTimeZone( 'UTC' )
			)->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $exception ) {
			return '';
		}
	}

	/**
	 * Create the simulated next period end.
	 *
	 * @param string $period_start Period start.
	 * @return string
	 */
	private function create_simulated_next_period_end(
		$period_start
	) {
		try {
			$date = new \DateTimeImmutable(
				$period_start,
				new \DateTimeZone( 'UTC' )
			);

			return $date
				->modify( '+1 month' )
				->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $exception ) {
			return '';
		}
	}

	/**
	 * Convert a MySQL UTC datetime to a PayPal UTC datetime.
	 *
	 * @param string $datetime MySQL UTC datetime.
	 * @return string
	 */
	private function mysql_datetime_to_paypal( $datetime ) {
		$datetime = trim( (string) $datetime );

		if ( '' === $datetime ) {
			return '';
		}

		try {
			$date = new \DateTimeImmutable(
				$datetime,
				new \DateTimeZone( 'UTC' )
			);

			return $date
				->setTimezone( new \DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d\TH:i:s\Z' );
		} catch ( \Exception $exception ) {
			return '';
		}
	}

	/**
	 * Get the simulated provider status for an event.
	 *
	 * @param string $event_type Event type.
	 * @return string
	 */
	private function get_simulated_provider_status( $event_type ) {
		switch ( $event_type ) {
			case 'BILLING.SUBSCRIPTION.ACTIVATED':
			case 'BILLING.SUBSCRIPTION.PAYMENT.SUCCEEDED':
				return 'ACTIVE';

			case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
				return 'PAST_DUE';

			case 'BILLING.SUBSCRIPTION.SUSPENDED':
				return 'SUSPENDED';

			case 'BILLING.SUBSCRIPTION.CANCELLED':
				return 'CANCELLED';

			case 'BILLING.SUBSCRIPTION.EXPIRED':
				return 'EXPIRED';

			default:
				return 'ACTIVE';
		}
	}

	/**
	 * Check the current status of a PayPal subscription.
	 *
	 * The manually entered PayPal ID is persisted independently from the
	 * generic Test Console subscription. When a matching local DropKey
	 * subscription exists, it becomes the current Test Console subscription.
	 *
	 * @return string
	 */
	private function check_paypal_subscription() {
		$subscription_id = isset( $_POST['paypal_subscription_id'] )
			? sanitize_text_field( wp_unslash( $_POST['paypal_subscription_id'] ) )
			: '';

		/*
		 * Persist the exact manually entered value before contacting PayPal.
		 * This makes the field retain the user's latest input even if the
		 * PayPal API returns an error.
		 */
		update_option(
			self::TEST_PAYPAL_SUBSCRIPTION_OPTION,
			$subscription_id,
			false
		);

		if ( '' === $subscription_id ) {
			return __( 'No PayPal subscription ID was supplied.', 'dropkey-wp' );
		}

		$gateway = $this->gateway_manager->get( 'paypal' );

		if ( ! $gateway ) {
			return __( 'The PayPal gateway is not registered.', 'dropkey-wp' );
		}

		if ( ! $gateway->is_available() ) {
			return __( 'PayPal is not configured.', 'dropkey-wp' );
		}

		$result = $gateway->get_subscription( $subscription_id );

		if ( is_wp_error( $result ) ) {
			return sprintf(
				/* translators: %s: error message. */
				__( 'PayPal subscription check failed: %s', 'dropkey-wp' ),
				$result->get_error_message()
			);
		}

		$status = isset( $result['status'] )
			? strtoupper( sanitize_text_field( $result['status'] ) )
			: '';

		$paypal_id = isset( $result['id'] )
			? sanitize_text_field( $result['id'] )
			: $subscription_id;

		/*
		 * Save the ID returned by PayPal as the remembered value. In normal
		 * operation this will match the manually entered ID, while also
		 * allowing PayPal's response to remain the authoritative identifier.
		 */
		update_option(
			self::TEST_PAYPAL_SUBSCRIPTION_OPTION,
			$paypal_id,
			false
		);

		$plan_id = isset( $result['plan_id'] )
			? sanitize_text_field( $result['plan_id'] )
			: '';

		$next_billing_time = '';

		if (
			isset(
				$result['billing_info'],
				$result['billing_info']['next_billing_time']
			)
		) {
			$next_billing_time = sanitize_text_field(
				$result['billing_info']['next_billing_time']
			);
		}

		if ( '' === $status ) {
			return sprintf(
				/* translators: %s: PayPal subscription ID. */
				__(
					'PayPal returned the subscription %s, but no status was present.',
					'dropkey-wp'
				),
				$paypal_id
			);
		}

		$message = sprintf(
			/* translators: 1: PayPal subscription ID, 2: status. */
			__(
				'PayPal subscription %1$s status: %2$s.',
				'dropkey-wp'
			),
			$paypal_id,
			$status
		);

		if ( '' !== $plan_id ) {
			$message .= sprintf(
				/* translators: %s: PayPal plan ID. */
				__( ' Plan: %s.', 'dropkey-wp' ),
				$plan_id
			);
		}

		if ( '' !== $next_billing_time ) {
			$message .= sprintf(
				/* translators: %s: next billing time. */
				__( ' Next billing: %s.', 'dropkey-wp' ),
				$next_billing_time
			);
		}

		/*
		 * Resolve the PayPal subscription against the local DropKey database.
		 */
		$local_subscription_id = $this->get_local_subscription_id_by_paypal_id(
			$paypal_id
		);

		if ( $local_subscription_id > 0 ) {
			update_option(
				self::TEST_SUBSCRIPTION_OPTION,
				$local_subscription_id,
				false
			);

			$message .= sprintf(
				/* translators: %d: local DropKey subscription ID. */
				__(
					' Local DropKey subscription: #%d. This subscription is now selected for the Test Console and Webhook Simulator.',
					'dropkey-wp'
				),
				$local_subscription_id
			);
		} else {
			/*
			 * Do not allow a previously selected local subscription to remain
			 * silently associated with a PayPal ID that has no local mapping.
			 */
			delete_option( self::TEST_SUBSCRIPTION_OPTION );

			$message .= sprintf(
				/* translators: %s: PayPal subscription ID. */
				__(
					' No local DropKey subscription is linked to PayPal subscription %s. The Webhook Simulator will not use an unrelated subscription.',
					'dropkey-wp'
				),
				$paypal_id
			);
		}

		return $message;
	}

	/**
	 * Get the last manually entered PayPal subscription ID.
	 *
	 * @return string
	 */
	private function get_paypal_subscription_id() {
		return sanitize_text_field(
			(string) get_option(
				self::TEST_PAYPAL_SUBSCRIPTION_OPTION,
				''
			)
		);
	}

	/**
	 * Find a local DropKey subscription by its PayPal subscription ID.
	 *
	 * @param string $paypal_subscription_id PayPal subscription ID.
	 * @return int
	 */
	private function get_local_subscription_id_by_paypal_id(
		$paypal_subscription_id
	) {
		global $wpdb;

		$paypal_subscription_id = sanitize_text_field(
			$paypal_subscription_id
		);

		if ( '' === $paypal_subscription_id ) {
			return 0;
		}

		$table_name = $wpdb->prefix . 'dropkey_subscriptions';

		$subscription_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				FROM {$table_name}
				WHERE gateway = %s
				AND gateway_subscription_id = %s
				LIMIT 1",
				'paypal',
				$paypal_subscription_id
			)
		);

		return $subscription_id
			? (int) $subscription_id
			: 0;
	}

	/**
	 * Get the WordPress database object.
	 *
	 * @return \wpdb
	 */
	private function get_wpdb() {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * Create fresh test subscription and license.
	 *
	 * @return string
	 */
	private function create_fresh_test_data() {
		global $wpdb;

		$customers     = new CustomerRepository( $wpdb );
		$products      = new ProductRepository( $wpdb );
		$plans         = new PlanRepository( $wpdb );
		$subscriptions = new SubscriptionRepository( $wpdb );
		$licenses      = new LicenseRepository( $wpdb );

		$customer = $customers->find( 1 );
		$product  = $products->find( 1 );
		$plan     = $plans->find( 1 );

		if ( ! $customer || ! $product || ! $plan ) {
			return __(
				'Required test customer, product, or plan was not found.',
				'dropkey-wp'
			);
		}

		$create_subscription = new CreateSubscription(
			$subscriptions,
			$customers,
			$products,
			$plans
		);

		$subscription = $create_subscription->execute(
			array(
				'customer_id'              => $customer->get_id(),
				'product_id'               => $product->get_id(),
				'plan_id'                  => $plan->get_id(),
				'gateway'                  => 'test',
				'gateway_subscription_id' => 'test_subscription_' . wp_generate_uuid4(),
				'current_period_start'     => gmdate( 'Y-m-d H:i:s' ),
				'current_period_end'       => gmdate(
					'Y-m-d H:i:s',
					strtotime( '+1 month' )
				),
			)
		);

		if ( is_wp_error( $subscription ) ) {
			return $subscription->get_error_message();
		}

		$activate_subscription = new ActivateSubscription(
			$subscriptions
		);

		$subscription = $activate_subscription->execute(
			$subscription->get_id()
		);

		if ( is_wp_error( $subscription ) ) {
			return $subscription->get_error_message();
		}

		$license = $licenses->find_by_subscription_id(
			$subscription->get_id()
		);

		if ( ! $license ) {
			return __(
				'Subscription was activated, but no license was created.',
				'dropkey-wp'
			);
		}

		/*
		 * Create Fresh Test Data changes the generic current Test Console
		 * subscription. It deliberately does NOT overwrite the remembered
		 * PayPal Subscription ID because that value belongs to the PayPal
		 * Subscription Check field.
		 */
		update_option(
			self::TEST_SUBSCRIPTION_OPTION,
			$subscription->get_id(),
			false
		);

		return sprintf(
			/* translators: 1: subscription ID, 2: license ID. */
			__(
				'Fresh test data created. Subscription #%1$d, License #%2$d.',
				'dropkey-wp'
			),
			$subscription->get_id(),
			$license->get_id()
		);
	}

	/**
	 * Change the current subscription status.
	 *
	 * @return string
	 */
	private function change_subscription_status() {
		global $wpdb;

		$subscription_id = $this->get_current_subscription_id();

		if ( ! $subscription_id ) {
			return __( 'No current test subscription found.', 'dropkey-wp' );
		}

		$status = isset( $_POST['status'] )
			? sanitize_key( wp_unslash( $_POST['status'] ) )
			: '';

		if ( '' === $status ) {
			return __( 'No subscription status was supplied.', 'dropkey-wp' );
		}

		$subscriptions = new SubscriptionRepository( $wpdb );

		$service = new ChangeSubscriptionStatus(
			$subscriptions
		);

		$result = $service->execute(
			$subscription_id,
			$status
		);

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		return sprintf(
			/* translators: 1: subscription ID, 2: subscription status. */
			__(
				'Subscription #%1$d changed to %2$s.',
				'dropkey-wp'
			),
			$result->get_id(),
			$result->get_status()
		);
	}

	/**
	 * Expire the current subscription's payment grace period.
	 *
	 * @return string
	 */
	private function expire_grace_period() {
		global $wpdb;

		$subscription_id = $this->get_current_subscription_id();

		if ( ! $subscription_id ) {
			return __( 'No current test subscription found.', 'dropkey-wp' );
		}

		$subscriptions = new SubscriptionRepository( $wpdb );

		$subscription = $subscriptions->find(
			$subscription_id
		);

		if ( ! $subscription ) {
			return __(
				'Current test subscription was not found.',
				'dropkey-wp'
			);
		}

		if ( Subscription::STATUS_PAST_DUE !== $subscription->get_status() ) {
			return __(
				'The current test subscription must be past_due before its grace period can be expired.',
				'dropkey-wp'
			);
		}

		$past_due_at = gmdate(
			'Y-m-d H:i:s',
			time() - ( 8 * DAY_IN_SECONDS )
		);

		$marked = $subscriptions->mark_past_due(
			$subscription_id,
			$past_due_at
		);

		if ( is_wp_error( $marked ) ) {
			return $marked->get_error_message();
		}

		$service = new EnforcePastDueSubscriptions(
			$subscriptions
		);

		$suspended = $service->execute();

		if ( in_array( $subscription_id, $suspended, true ) ) {
			return sprintf(
				/* translators: %d: subscription ID. */
				__(
					'Grace period expired. Subscription #%d is now suspended.',
					'dropkey-wp'
				),
				$subscription_id
			);
		}

		return sprintf(
			/* translators: %d: subscription ID. */
			__(
				'Grace-period enforcement ran, but subscription #%d was not suspended.',
				'dropkey-wp'
			),
			$subscription_id
		);
	}

	/**
	 * Deactivate the current activation.
	 *
	 * @return string
	 */
	private function deactivate_activation() {
		global $wpdb;

		$activation_id = isset( $_POST['activation_id'] )
			? absint( $_POST['activation_id'] )
			: 0;

		if ( ! $activation_id ) {
			return __( 'No activation was supplied.', 'dropkey-wp' );
		}

		$activations = new ActivationRepository( $wpdb );
		$licenses    = new LicenseRepository( $wpdb );

		$service = new DeactivateLicense(
			$activations,
			$licenses
		);

		$result = $service->execute( $activation_id );

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		return sprintf(
			/* translators: %d: activation ID. */
			__(
				'Activation #%d deactivated.',
				'dropkey-wp'
			),
			$result->get_id()
		);
	}

	/**
	 * Reactivate the current activation.
	 *
	 * @return string
	 */
	private function reactivate_activation() {
		global $wpdb;

		$activation_id = isset( $_POST['activation_id'] )
			? absint( $_POST['activation_id'] )
			: 0;

		if ( ! $activation_id ) {
			return __( 'No activation was supplied.', 'dropkey-wp' );
		}

		$activations = new ActivationRepository( $wpdb );
		$activation  = $activations->find( $activation_id );

		if ( ! $activation ) {
			return __( 'Activation was not found.', 'dropkey-wp' );
		}

		if ( Activation::STATUS_ACTIVE === $activation->get_status() ) {
			return __( 'Activation is already active.', 'dropkey-wp' );
		}

		$result = $activations->reactivate(
			$activation_id,
			wp_generate_password( 64, false, false )
		);

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		return sprintf(
			/* translators: %d: activation ID. */
			__(
				'Activation #%d reactivated.',
				'dropkey-wp'
			),
			$result->get_id()
		);
	}

	/**
	 * Get the current test context.
	 *
	 * @return array|null
	 */
	private function get_test_context() {
		global $wpdb;

		$subscription_id = $this->get_current_subscription_id();

		if ( ! $subscription_id ) {
			return null;
		}

		$subscriptions = new SubscriptionRepository( $wpdb );
		$products      = new ProductRepository( $wpdb );
		$plans         = new PlanRepository( $wpdb );
		$licenses      = new LicenseRepository( $wpdb );
		$activations   = new ActivationRepository( $wpdb );

		$subscription = $subscriptions->find( $subscription_id );

		if ( ! $subscription ) {
			return null;
		}

		$product = $products->find( $subscription->get_product_id() );
		$plan    = $plans->find( $subscription->get_plan_id() );

		$license = $licenses->find_by_subscription_id(
			$subscription->get_id()
		);

		$activation = null;

		if ( $license ) {
			$activation = $activations->find_by_license_id(
				$license->get_id()
			);
		}

		if ( ! $product || ! $plan ) {
			return null;
		}

		return array(
			'product'      => $product,
			'plan'         => $plan,
			'subscription' => $subscription,
			'license'      => $license,
			'activation'   => $activation,
		);
	}

	/**
	 * Get the current test subscription ID.
	 *
	 * There is intentionally no arbitrary fallback subscription.
	 *
	 * @return int
	 */
	private function get_current_subscription_id() {
		$subscription_id = (int) get_option(
			self::TEST_SUBSCRIPTION_OPTION,
			0
		);

		return $subscription_id > 0
			? $subscription_id
			: 0;
	}

	/**
	 * Get valid next subscription statuses.
	 *
	 * @param string $status Current status.
	 * @return string[]
	 */
	private function get_next_statuses( $status ) {
		$transitions = array(
			Subscription::STATUS_PENDING => array(
				Subscription::STATUS_ACTIVE,
				Subscription::STATUS_CANCELLED,
			),
			Subscription::STATUS_ACTIVE => array(
				Subscription::STATUS_PAST_DUE,
				Subscription::STATUS_SUSPENDED,
				Subscription::STATUS_CANCELLED,
				Subscription::STATUS_EXPIRED,
			),
			Subscription::STATUS_PAST_DUE => array(
				Subscription::STATUS_ACTIVE,
				Subscription::STATUS_SUSPENDED,
				Subscription::STATUS_CANCELLED,
				Subscription::STATUS_EXPIRED,
			),
			Subscription::STATUS_SUSPENDED => array(
				Subscription::STATUS_ACTIVE,
				Subscription::STATUS_CANCELLED,
				Subscription::STATUS_EXPIRED,
			),
			Subscription::STATUS_CANCELLED => array(
				Subscription::STATUS_EXPIRED,
			),
			Subscription::STATUS_EXPIRED => array(),
		);

		return isset( $transitions[ $status ] )
			? $transitions[ $status ]
			: array();
	}

	/**
	 * Get gateway status information.
	 *
	 * @return array
	 */
	private function get_gateway_status() {
		$status = array();

		foreach ( $this->gateway_manager->all() as $gateway ) {
			$status[] = array(
				'id'        => $gateway->get_id(),
				'name'      => $gateway->get_name(),
				'available' => $gateway->is_available(),
			);
		}

		return $status;
	}
}