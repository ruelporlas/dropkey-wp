<?php
/**
 * DropKey WP plugin bootstrap.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP;

use DropKeyWP\Admin\CustomerAdmin;
use DropKeyWP\Admin\LicenseAdmin;
use DropKeyWP\Admin\ProductAdmin;
use DropKeyWP\Admin\SubscriptionAdmin;
use DropKeyWP\Admin\TestConsole;
use DropKeyWP\Application\ChangeSubscriptionStatus;
use DropKeyWP\Application\CreateLicense;
use DropKeyWP\Application\CreateSubscriptionCheckout;
use DropKeyWP\Application\EnforcePastDueSubscriptions;
use DropKeyWP\Application\ProcessPaymentEvent;
use DropKeyWP\Application\SynchronizeSubscriptionEntitlement;
use DropKeyWP\Database\Repositories\ActivationRepository;
use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Database\Repositories\GatewayEventRepository;
use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Database\Repositories\SubscriptionEventRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Frontend\CustomerAccount;
use DropKeyWP\Frontend\ProductCheckout;
use DropKeyWP\Gateways\GatewayManager;
use DropKeyWP\REST\CheckoutController;
use DropKeyWP\REST\LicenseController;
use DropKeyWP\REST\WebhookController;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin bootstrap class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether the plugin has booted.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

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

		global $wpdb;

		$activation_repository         = new ActivationRepository( $wpdb );
		$customer_repository           = new CustomerRepository( $wpdb );
		$gateway_event_repository      = new GatewayEventRepository( $wpdb );
		$license_repository            = new LicenseRepository( $wpdb );
		$plan_repository               = new PlanRepository( $wpdb );
		$product_repository            = new ProductRepository( $wpdb );
		$subscription_event_repository = new SubscriptionEventRepository( $wpdb );
		$subscription_repository       = new SubscriptionRepository( $wpdb );

		$gateway_manager = new GatewayManager();

		$create_license = new CreateLicense(
			$license_repository,
			$subscription_repository,
			$product_repository,
			$plan_repository
		);

		$synchronize_subscription_entitlement =
			new SynchronizeSubscriptionEntitlement(
				$license_repository
			);

		add_action(
			'dropkey_wp_subscription_activated',
			array(
				$create_license,
				'execute',
			),
			10,
			1
		);

		add_action(
			'dropkey_wp_subscription_activated',
			array(
				$synchronize_subscription_entitlement,
				'execute',
			),
			20,
			1
		);

		add_action(
			'dropkey_wp_subscription_past_due',
			array(
				$synchronize_subscription_entitlement,
				'execute',
			),
			10,
			1
		);

		add_action(
			'dropkey_wp_subscription_suspended',
			array(
				$synchronize_subscription_entitlement,
				'execute',
			),
			10,
			1
		);

		add_action(
			'dropkey_wp_subscription_expired',
			array(
				$synchronize_subscription_entitlement,
				'execute',
			),
			10,
			1
		);

		$process_payment_event = new ProcessPaymentEvent(
			$gateway_event_repository,
			$subscription_repository,
			$license_repository,
			$gateway_manager
		);

		$enforce_past_due_subscriptions = new EnforcePastDueSubscriptions(
			$subscription_repository
		);

		$create_subscription_checkout = new CreateSubscriptionCheckout(
			$gateway_manager,
			$customer_repository,
			$product_repository,
			$plan_repository,
			$subscription_repository
		);

		$change_subscription_status = new ChangeSubscriptionStatus(
			$subscription_repository,
			$subscription_event_repository
		);

		$checkout_controller = new CheckoutController(
			$create_subscription_checkout
		);

		$license_controller = new LicenseController(
			$license_repository,
			$activation_repository
		);

		$webhook_controller = new WebhookController(
			$gateway_manager,
			$process_payment_event
		);

		add_action(
			'rest_api_init',
			array( $checkout_controller, 'register_routes' )
		);

		add_action(
			'rest_api_init',
			array( $license_controller, 'register_routes' )
		);

		add_action(
			'rest_api_init',
			array( $webhook_controller, 'register_routes' )
		);

		$product_checkout = new ProductCheckout(
			$gateway_manager
		);

		$product_checkout->register();

		$customer_account = new CustomerAccount();

		$customer_account->register();

		add_action(
			'dropkey_wp_enforce_past_due_subscriptions',
			array( $enforce_past_due_subscriptions, 'execute' )
		);

		if ( is_admin() ) {

			$product_admin = new ProductAdmin(
				$product_repository
			);

			$product_admin->register();

			$customer_admin = new CustomerAdmin(
				$customer_repository
			);

			$customer_admin->register();

			$license_admin = new LicenseAdmin(
				$license_repository,
				$activation_repository,
				$customer_repository,
				$product_repository
			);

			$license_admin->register();

			$subscription_admin = new SubscriptionAdmin(
				$subscription_repository,
				$customer_repository,
				$product_repository,
				$plan_repository,
				$change_subscription_status
			);

			$subscription_admin->register();

			$test_console = new TestConsole(
				$gateway_manager
			);

			$test_console->register();
		}
	}
}