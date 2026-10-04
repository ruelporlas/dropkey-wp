<?php
/**
 * Create a subscription checkout.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\Plan;
use DropKeyWP\Domain\PlanStatus;
use DropKeyWP\Domain\ProductStatus;
use DropKeyWP\Domain\Subscription;
use DropKeyWP\Gateways\GatewayManager;

defined( 'ABSPATH' ) || exit;

final class CreateSubscriptionCheckout {

	/**
	 * Gateway manager.
	 *
	 * @var GatewayManager
	 */
	private $gateways;

	/**
	 * Customer repository.
	 *
	 * @var CustomerRepository
	 */
	private $customers;

	/**
	 * Product repository.
	 *
	 * @var ProductRepository
	 */
	private $products;

	/**
	 * Plan repository.
	 *
	 * @var PlanRepository
	 */
	private $plans;

	/**
	 * Subscription repository.
	 *
	 * @var SubscriptionRepository
	 */
	private $subscriptions;

	/**
	 * Constructor.
	 *
	 * @param GatewayManager         $gateways       Gateway manager.
	 * @param CustomerRepository     $customers      Customer repository.
	 * @param ProductRepository      $products       Product repository.
	 * @param PlanRepository         $plans          Plan repository.
	 * @param SubscriptionRepository $subscriptions Subscription repository.
	 */
	public function __construct(
		GatewayManager $gateways,
		CustomerRepository $customers,
		ProductRepository $products,
		PlanRepository $plans,
		SubscriptionRepository $subscriptions
	) {
		$this->gateways       = $gateways;
		$this->customers      = $customers;
		$this->products       = $products;
		$this->plans          = $plans;
		$this->subscriptions = $subscriptions;
	}

	/**
	 * Create a subscription checkout.
	 *
	 * Free plans are fulfilled locally and immediately. Paid plans
	 * continue through the selected payment gateway.
	 *
	 * @param array<string,mixed> $data Checkout data.
	 * @return array|\WP_Error
	 */
	public function execute( array $data ) {
		$customer_id = isset( $data['customer_id'] )
			? absint( $data['customer_id'] )
			: 0;

		$product_id = isset( $data['product_id'] )
			? absint( $data['product_id'] )
			: 0;

		$plan_id = isset( $data['plan_id'] )
			? absint( $data['plan_id'] )
			: 0;

		$gateway_id = isset( $data['gateway'] )
			? sanitize_key( $data['gateway'] )
			: '';

		if ( $customer_id <= 0 ) {
			return new \WP_Error(
				'dropkey_checkout_customer_required',
				__( 'A valid customer is required.', 'dropkey-wp' )
			);
		}

		if ( $product_id <= 0 ) {
			return new \WP_Error(
				'dropkey_checkout_product_required',
				__( 'A valid product is required.', 'dropkey-wp' )
			);
		}

		if ( $plan_id <= 0 ) {
			return new \WP_Error(
				'dropkey_checkout_plan_required',
				__( 'A valid plan is required.', 'dropkey-wp' )
			);
		}

		$customer = $this->customers->find( $customer_id );

		if ( ! $customer ) {
			return new \WP_Error(
				'dropkey_checkout_customer_not_found',
				__( 'The customer does not exist.', 'dropkey-wp' )
			);
		}

		$product = $this->products->find( $product_id );

		if ( ! $product ) {
			return new \WP_Error(
				'dropkey_checkout_product_not_found',
				__( 'The product does not exist.', 'dropkey-wp' )
			);
		}

		if ( ProductStatus::ACTIVE !== $product->get_status() ) {
			return new \WP_Error(
				'dropkey_checkout_product_unavailable',
				__(
					'The selected product is not currently available for purchase.',
					'dropkey-wp'
				)
			);
		}

		$plan = $this->plans->find( $plan_id );

		if ( ! $plan ) {
			return new \WP_Error(
				'dropkey_checkout_plan_not_found',
				__( 'The plan does not exist.', 'dropkey-wp' )
			);
		}

		if ( PlanStatus::ACTIVE !== $plan->get_status() ) {
			return new \WP_Error(
				'dropkey_checkout_plan_unavailable',
				__(
					'The selected plan is not currently available for purchase.',
					'dropkey-wp'
				)
			);
		}

		if ( $plan->get_product_id() !== $product->get_id() ) {
			return new \WP_Error(
				'dropkey_checkout_product_plan_mismatch',
				__(
					'The selected plan does not belong to the selected product.',
					'dropkey-wp'
				)
			);
		}

		/*
		 * Free plans never enter the payment gateway layer.
		 *
		 * The application boundary decides that the plan is free;
		 * the frontend is not trusted to make this determination.
		 */
		if ( $plan->is_free() ) {
			return $this->create_free_subscription(
				$customer,
				$product,
				$plan
			);
		}

		if ( '' === $gateway_id ) {
			return new \WP_Error(
				'dropkey_checkout_gateway_required',
				__( 'A payment gateway is required for paid plans.', 'dropkey-wp' )
			);
		}

		$gateway = $this->gateways->get( $gateway_id );

		if ( ! $gateway ) {
			return new \WP_Error(
				'dropkey_checkout_gateway_not_found',
				__( 'The selected payment gateway does not exist.', 'dropkey-wp' )
			);
		}

		if ( ! $gateway->is_available() ) {
			return new \WP_Error(
				'dropkey_checkout_gateway_unavailable',
				__(
					'The selected payment gateway is not currently available.',
					'dropkey-wp'
				)
			);
		}

		$return_url = isset( $data['return_url'] )
			? esc_url_raw( $data['return_url'] )
			: '';

		$cancel_url = isset( $data['cancel_url'] )
			? esc_url_raw( $data['cancel_url'] )
			: '';

		$redirect_validation = $this->validate_redirect_urls(
			$return_url,
			$cancel_url
		);

		if ( is_wp_error( $redirect_validation ) ) {
			return $redirect_validation;
		}

		$context = array(
			'customer'   => $customer,
			'product'    => $product,
			'plan'       => $plan,
			'gateway'    => $gateway_id,
			'return_url' => $return_url,
			'cancel_url' => $cancel_url,
		);

		$result = $gateway->create_subscription_checkout( $context );

		if ( is_wp_error( $result ) ) {
			do_action(
				'dropkey_wp_subscription_checkout_failed',
				$result,
				$customer,
				$product,
				$plan,
				$gateway_id
			);

			return $result;
		}

		if ( ! is_array( $result ) ) {
			return new \WP_Error(
				'dropkey_checkout_invalid_gateway_response',
				__(
					'The payment gateway returned an invalid checkout response.',
					'dropkey-wp'
				)
			);
		}

		$gateway_subscription_id = $this->get_gateway_subscription_id(
			$result
		);

		if ( '' === $gateway_subscription_id ) {
			return new \WP_Error(
				'dropkey_checkout_gateway_subscription_missing',
				__(
					'The payment gateway did not return a subscription ID.',
					'dropkey-wp'
				)
			);
		}

		$existing_subscription =
			$this->subscriptions->find_by_gateway_subscription_id(
				$gateway_id,
				$gateway_subscription_id
			);

		if ( $existing_subscription ) {
			$result['gateway']            = $gateway_id;
			$result['subscription_id']    = $existing_subscription->get_id();
			$result['local_subscription'] = $existing_subscription;

			do_action(
				'dropkey_wp_subscription_checkout_created',
				$result,
				$existing_subscription
			);

			return $result;
		}

		$subscription = new Subscription(
			array(
				'customer_id'             => $customer->get_id(),
				'product_id'              => $product->get_id(),
				'plan_id'                 => $plan->get_id(),
				'gateway'                => $gateway_id,
				'gateway_subscription_id' => $gateway_subscription_id,
				'status'                 => Subscription::STATUS_PENDING,
				'current_period_start'   => null,
				'current_period_end'     => null,
				'cancel_at_period_end'   => false,
				'cancelled_at'           => null,
				'past_due_at'            => null,
				'ended_at'               => null,
			)
		);

		$validation = $subscription->validate();

		if ( is_wp_error( $validation ) ) {
			$this->compensate_gateway_subscription(
				$gateway,
				$gateway_subscription_id,
				$validation,
				$gateway_id
			);

			return $validation;
		}

		$created_subscription = $this->subscriptions->create(
			array(
				'customer_id'             => $subscription->get_customer_id(),
				'product_id'              => $subscription->get_product_id(),
				'plan_id'                 => $subscription->get_plan_id(),
				'gateway'                => $subscription->get_gateway(),
				'gateway_subscription_id' => $subscription->get_gateway_subscription_id(),
				'status'                 => $subscription->get_status(),
				'current_period_start'    => $subscription->get_current_period_start(),
				'current_period_end'      => $subscription->get_current_period_end(),
				'cancel_at_period_end'    => $subscription->get_cancel_at_period_end(),
				'cancelled_at'            => $subscription->get_cancelled_at(),
				'past_due_at'             => null,
				'ended_at'                => null,
			)
		);

		if ( is_wp_error( $created_subscription ) ) {
			$existing_subscription =
				$this->subscriptions->find_by_gateway_subscription_id(
					$gateway_id,
					$gateway_subscription_id
				);

			if ( $existing_subscription ) {
				$result['gateway']            = $gateway_id;
				$result['subscription_id']    = $existing_subscription->get_id();
				$result['local_subscription'] = $existing_subscription;

				do_action(
					'dropkey_wp_subscription_checkout_created',
					$result,
					$existing_subscription
				);

				return $result;
			}

			$this->compensate_gateway_subscription(
				$gateway,
				$gateway_subscription_id,
				$created_subscription,
				$gateway_id
			);

			do_action(
				'dropkey_wp_subscription_checkout_local_creation_failed',
				$result,
				$created_subscription,
				$gateway_id,
				$gateway_subscription_id
			);

			return $created_subscription;
		}

		$result['gateway']            = $gateway_id;
		$result['subscription_id']    = $created_subscription->get_id();
		$result['local_subscription'] = $created_subscription;

		do_action(
			'dropkey_wp_subscription_checkout_created',
			$result,
			$created_subscription
		);

		return $result;
	}

	/**
	 * Create an immediately active free subscription.
	 *
	 * A free subscription is perpetual for the current MVP. It does
	 * not have a billing period end and does not require a provider.
	 *
	 * The advisory lock serializes concurrent attempts to obtain the
	 * same free plan and prevents duplicate subscriptions/licenses.
	 *
	 * @param object $customer Customer entity.
	 * @param object $product  Product entity.
	 * @param Plan   $plan     Plan entity.
	 * @return array|\WP_Error
	 */
	private function create_free_subscription(
		$customer,
		$product,
		Plan $plan
	) {
		global $wpdb;

		$lock_name = $this->get_free_subscription_lock_name(
			$customer->get_id(),
			$product->get_id(),
			$plan->get_id()
		);

		$lock_result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK( %s, %d )',
				$lock_name,
				10
			)
		);

		if ( '1' !== (string) $lock_result ) {
			return new \WP_Error(
				'dropkey_free_subscription_locked',
				__(
					'This free plan is currently being processed. Please try again.',
					'dropkey-wp'
				)
			);
		}

		try {
			/*
			 * Re-check existing customer subscriptions while holding
			 * the lock. A free plan is obtained only once for a given
			 * customer/product/plan combination.
			 */
			$existing_subscriptions = $this->subscriptions->all_by_customer(
				$customer->get_id()
			);

			foreach ( $existing_subscriptions as $existing_subscription ) {
				if (
					(int) $existing_subscription->get_product_id() === (int) $product->get_id()
					&&
					(int) $existing_subscription->get_plan_id() === (int) $plan->get_id()
					&&
					Subscription::STATUS_EXPIRED !== $existing_subscription->get_status()
				) {
					return array(
						'gateway'              => 'free',
						'free'                 => true,
						'subscription_id'      => $existing_subscription->get_id(),
						'local_subscription'   => $existing_subscription,
						'approval_url'         => '',
					);
				}
			}

			$subscription_reference = 'free-' . wp_generate_uuid4();
			$period_start           = current_time( 'mysql', true );

			$subscription = new Subscription(
				array(
					'customer_id'             => $customer->get_id(),
					'product_id'              => $product->get_id(),
					'plan_id'                 => $plan->get_id(),
					'gateway'                => 'free',
					'gateway_subscription_id' => $subscription_reference,
					'status'                 => Subscription::STATUS_ACTIVE,
					'current_period_start'   => $period_start,
					'current_period_end'     => null,
					'cancel_at_period_end'   => false,
					'cancelled_at'           => null,
					'past_due_at'            => null,
					'ended_at'               => null,
				)
			);

			$validation = $subscription->validate();

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			$created_subscription = $this->subscriptions->create(
				array(
					'customer_id'             => $subscription->get_customer_id(),
					'product_id'              => $subscription->get_product_id(),
					'plan_id'                 => $subscription->get_plan_id(),
					'gateway'                => $subscription->get_gateway(),
					'gateway_subscription_id' => $subscription->get_gateway_subscription_id(),
					'status'                 => $subscription->get_status(),
					'current_period_start'    => $subscription->get_current_period_start(),
					'current_period_end'      => $subscription->get_current_period_end(),
					'cancel_at_period_end'    => $subscription->get_cancel_at_period_end(),
					'cancelled_at'            => $subscription->get_cancelled_at(),
					'past_due_at'             => $subscription->get_past_due_at(),
					'ended_at'                => $subscription->get_ended_at(),
				)
			);

			if ( is_wp_error( $created_subscription ) ) {
				/*
				 * The unique gateway/reference combination makes the
				 * generated reference collision-safe. A database
				 * failure remains a real checkout failure.
				 */
				return $created_subscription;
			}

			/*
			 * Reuse the normal lifecycle pipeline.
			 *
			 * Plugin.php already listens for this action and will:
			 * 1. create the license;
			 * 2. synchronize the entitlement.
			 */
			do_action(
				'dropkey_wp_subscription_activated',
				$created_subscription->get_id()
			);

			$result = array(
				'gateway'              => 'free',
				'free'                 => true,
				'subscription_id'      => $created_subscription->get_id(),
				'local_subscription'   => $created_subscription,
				'approval_url'         => '',
			);

			do_action(
				'dropkey_wp_subscription_checkout_created',
				$result,
				$created_subscription
			);

			return $result;
		} finally {
			/*
			 * RELEASE_LOCK() must use the same database connection
			 * that acquired the advisory lock.
			 */
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT RELEASE_LOCK( %s )',
					$lock_name
				)
			);
		}
	}

	/**
	 * Generate the free subscription concurrency lock name.
	 *
	 * @param int $customer_id Customer ID.
	 * @param int $product_id  Product ID.
	 * @param int $plan_id     Plan ID.
	 * @return string
	 */
	private function get_free_subscription_lock_name(
		$customer_id,
		$product_id,
		$plan_id
	) {
		return 'dropkey_free_checkout_' .
			absint( $customer_id ) . '_' .
			absint( $product_id ) . '_' .
			absint( $plan_id );
	}

	/**
	 * Validate checkout redirect URLs.
	 *
	 * @param string $return_url Return URL.
	 * @param string $cancel_url Cancel URL.
	 * @return true|\WP_Error
	 */
	private function validate_redirect_urls( $return_url, $cancel_url ) {
		$urls = array(
			'return_url' => $return_url,
			'cancel_url' => $cancel_url,
		);

		$home_url = home_url( '/' );
		$home     = wp_parse_url( $home_url );

		if (
			! is_array( $home )
			|| empty( $home['host'] )
		) {
			return new \WP_Error(
				'dropkey_checkout_redirect_origin_invalid',
				__(
					'The checkout return destination could not be validated.',
					'dropkey-wp'
				)
			);
		}

		$allowed_host = strtolower(
			rtrim(
				(string) $home['host'],
				'.'
			)
		);

		$allowed_port = isset( $home['port'] )
			? (int) $home['port']
			: null;

		$allowed_scheme = isset( $home['scheme'] )
			? strtolower( (string) $home['scheme'] )
			: '';

		foreach ( $urls as $name => $url ) {
			if ( '' === $url ) {
				continue;
			}

			$parsed = wp_parse_url( $url );

			if (
				! is_array( $parsed )
				|| empty( $parsed['host'] )
			) {
				return new \WP_Error(
					'dropkey_checkout_' . $name . '_invalid',
					__(
						'The checkout redirect URL is invalid.',
						'dropkey-wp'
					)
				);
			}

			$host = strtolower(
				rtrim(
					(string) $parsed['host'],
					'.'
				)
			);

			if ( $host !== $allowed_host ) {
				return new \WP_Error(
					'dropkey_checkout_' . $name . '_forbidden',
					__(
						'Checkout redirect URLs must belong to this website.',
						'dropkey-wp'
					)
				);
			}

			if (
				$allowed_scheme !== ''
				&& isset( $parsed['scheme'] )
				&& strtolower( (string) $parsed['scheme'] ) !== $allowed_scheme
			) {
				return new \WP_Error(
					'dropkey_checkout_' . $name . '_forbidden',
					__(
						'Checkout redirect URLs must use this website\'s protocol.',
						'dropkey-wp'
					)
				);
			}

			$parsed_port = isset( $parsed['port'] )
				? (int) $parsed['port']
				: null;

			if ( $parsed_port !== $allowed_port ) {
				return new \WP_Error(
					'dropkey_checkout_' . $name . '_forbidden',
					__(
						'Checkout redirect URLs must use this website\'s origin.',
						'dropkey-wp'
					)
				);
			}

			if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
				return new \WP_Error(
					'dropkey_checkout_' . $name . '_invalid',
					__(
						'Checkout redirect URLs may not contain embedded credentials.',
						'dropkey-wp'
					)
				);
			}
		}

		return true;
	}

	/**
	 * Attempt to compensate for a provider subscription.
	 *
	 * @param object    $gateway                  Payment gateway.
	 * @param string    $gateway_subscription_id Provider subscription ID.
	 * @param \WP_Error $local_error              Local persistence error.
	 * @param string    $gateway_id              Gateway ID.
	 * @return void
	 */
	private function compensate_gateway_subscription(
		$gateway,
		$gateway_subscription_id,
		\WP_Error $local_error,
		$gateway_id
	) {
		if (
			! is_object( $gateway )
			|| ! method_exists( $gateway, 'cancel_subscription' )
		) {
			$error = new \WP_Error(
				'dropkey_checkout_compensation_unavailable',
				__(
					'The payment gateway does not support subscription cancellation.',
					'dropkey-wp'
				)
			);

			$this->record_compensation_failure(
				$gateway_id,
				$gateway_subscription_id,
				$local_error,
				$error
			);

			return;
		}

		$cancel_result = $gateway->cancel_subscription(
			$gateway_subscription_id,
			__(
				'DropKey WP could not complete local subscription creation.',
				'dropkey-wp'
			)
		);

		if ( is_wp_error( $cancel_result ) ) {
			$this->record_compensation_failure(
				$gateway_id,
				$gateway_subscription_id,
				$local_error,
				$cancel_result
			);

			return;
		}

		do_action(
			'dropkey_wp_subscription_checkout_compensated',
			$gateway_id,
			$gateway_subscription_id,
			$local_error
		);
	}

	/**
	 * Record a failed provider compensation attempt.
	 *
	 * @param string    $gateway_id              Gateway ID.
	 * @param string    $gateway_subscription_id Provider subscription ID.
	 * @param \WP_Error $local_error             Local persistence error.
	 * @param \WP_Error $compensation_error      Compensation error.
	 * @return void
	 */
	private function record_compensation_failure(
		$gateway_id,
		$gateway_subscription_id,
		\WP_Error $local_error,
		\WP_Error $compensation_error
	) {
		error_log(
			sprintf(
				'DropKey WP: Failed to compensate provider subscription %s for gateway %s after local checkout persistence failed. Local error: %s. Compensation error: %s',
				sanitize_text_field( $gateway_subscription_id ),
				sanitize_key( $gateway_id ),
				$local_error->get_error_message(),
				$compensation_error->get_error_message()
			)
		);

		do_action(
			'dropkey_wp_subscription_checkout_compensation_failed',
			$gateway_id,
			$gateway_subscription_id,
			$local_error,
			$compensation_error
		);
	}

	/**
	 * Extract the provider subscription ID.
	 *
	 * @param array $result Gateway checkout response.
	 * @return string
	 */
	private function get_gateway_subscription_id( array $result ) {
		if (
			isset( $result['gateway_subscription_id'] )
			&& '' !== trim( (string) $result['gateway_subscription_id'] )
		) {
			return sanitize_text_field(
				$result['gateway_subscription_id']
			);
		}

		if (
			isset( $result['subscription_id'] )
			&& '' !== trim( (string) $result['subscription_id'] )
		) {
			return sanitize_text_field(
				$result['subscription_id']
			);
		}

		if (
			isset( $result['id'] )
			&& '' !== trim( (string) $result['id'] )
		) {
			return sanitize_text_field(
				$result['id']
			);
		}

		return '';
	}
}