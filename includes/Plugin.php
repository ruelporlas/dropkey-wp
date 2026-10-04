<?php
/**
 * DropKey WP plugin bootstrap.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP;

use DropKeyWP\Admin\ProductAdmin;
use DropKeyWP\Admin\SubscriptionAdmin;
use DropKeyWP\Admin\TestConsole;
use DropKeyWP\Application\ActivateLicense;
use DropKeyWP\Application\ActivateSubscription;
use DropKeyWP\Application\AuthenticateActivation;
use DropKeyWP\Application\ChangeSubscriptionStatus;
use DropKeyWP\Application\CreateCustomer;
use DropKeyWP\Application\CreateLicense;
use DropKeyWP\Application\CreatePlan;
use DropKeyWP\Application\CreateProduct;
use DropKeyWP\Application\CreateSubscription;
use DropKeyWP\Application\CreateSubscriptionCheckout;
use DropKeyWP\Application\DeactivateLicense;
use DropKeyWP\Application\EnforcePastDueSubscriptions;
use DropKeyWP\Application\ProcessPaymentEvent;
use DropKeyWP\Application\SynchronizeSubscriptionEntitlement;
use DropKeyWP\Database\Repositories\ActivationRepository;
use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Database\Repositories\GatewayEventRepository;
use DropKeyWP\Database\Repositories\GatewayMappingRepository;
use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
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
	 * Whether the plugin has already booted.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the plugin instance.
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

		$activation_repository      = new ActivationRepository( $wpdb );
		$customer_repository        = new CustomerRepository( $wpdb );
		$gateway_event_repository   = new GatewayEventRepository( $wpdb );
		$gateway_mapping_repository = new GatewayMappingRepository( $wpdb );
		$license_repository         = new LicenseRepository( $wpdb );
		$plan_repository            = new PlanRepository( $wpdb );
		$product_repository         = new ProductRepository( $wpdb );
		$subscription_repository    = new SubscriptionRepository( $wpdb );

		$gateway_manager = new GatewayManager();

		$create_customer = new CreateCustomer(
			$customer_repository
		);

		$create_product = new CreateProduct(
			$product_repository
		);

		$create_plan = new CreatePlan(
			$plan_repository,
			$product_repository
		);

		$create_subscription = new CreateSubscription(
			$subscription_repository,
			$customer_repository,
			$product_repository,
			$plan_repository
		);

		$change_subscription_status = new ChangeSubscriptionStatus(
			$subscription_repository
		);

		$create_license = new CreateLicense(
			$license_repository,
			$subscription_repository,
			$product_repository,
			$plan_repository
		);

		$activate_subscription = new ActivateSubscription(
			$subscription_repository,
			$license_repository
		);

		$activate_license = new ActivateLicense(
			$license_repository,
			$activation_repository
		);

		$deactivate_license = new DeactivateLicense(
			$license_repository,
			$activation_repository
		);

		$authenticate_activation = new AuthenticateActivation(
			$activation_repository,
			$license_repository
		);

		$synchronize_subscription_entitlement = new SynchronizeSubscriptionEntitlement(
			$license_repository
		);

		$process_payment_event = new ProcessPaymentEvent(
			$gateway_event_repository,
			$subscription_repository,
			$license_repository
		);

		$enforce_past_due_subscriptions = new EnforcePastDueSubscriptions(
			$subscription_repository,
			$license_repository
		);

		$create_subscription_checkout = new CreateSubscriptionCheckout(
			$gateway_manager,
			$customer_repository,
			$product_repository,
			$plan_repository,
			$subscription_repository
		);

		$checkout_controller = new CheckoutController(
			$create_subscription_checkout
		);

		$license_controller = new LicenseController(
			$activate_license,
			$deactivate_license,
			$authenticate_activation
		);

		$webhook_controller = new WebhookController(
			$gateway_manager,
			$process_payment_event
		);

		$product_checkout = new ProductCheckout(
			$product_repository,
			$plan_repository
		);

		$checkout_controller->register();
		$license_controller->register();
		$webhook_controller->register();
		$product_checkout->register();

		add_action(
			'dropkey_wp_enforce_past_due_subscriptions',
			array( $enforce_past_due_subscriptions, 'execute' )
		);

		if ( is_admin() ) {
			$product_admin = new ProductAdmin(
				$product_repository,
				$create_product
			);

			$product_admin->register();

			$subscription_admin = new SubscriptionAdmin(
				$subscription_repository,
				$customer_repository,
				$product_repository,
				$plan_repository
			);

			$subscription_admin->register();

			$test_console = new TestConsole(
				$customer_repository,
				$product_repository,
				$plan_repository,
				$subscription_repository,
				$license_repository,
				$activation_repository,
				$gateway_event_repository,
				$gateway_mapping_repository,
				$gateway_manager,
				$create_customer,
				$create_product,
				$create_plan,
				$create_subscription,
				$create_license,
				$activate_license,
				$deactivate_license,
				$synchronize_subscription_entitlement,
				$enforce_past_due_subscriptions,
				$process_payment_event
			);

			$test_console->register();
		}
	}
}

