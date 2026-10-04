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
use DropKeyWP\Domain\Subscription;
use DropKeyWP\Gateways\GatewayManager;

defined( 'ABSPATH' ) || exit;

final class CreateSubscriptionCheckout {

	private $gateways;

	private $customers;

	private $products;

	private $plans;

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
	 * Create a gateway checkout and local pending subscription.
	 *
	 * @param array $data Checkout data.
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

		if ( '' === $gateway_id ) {
			return new \WP_Error(
				'dropkey_checkout_gateway_required',
				__( 'A payment gateway is required.', 'dropkey-wp' )
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

		$plan = $this->plans->find( $plan_id );

		if ( ! $plan ) {
			return new \WP_Error(
				'dropkey_checkout_plan_not_found',
				__( 'The plan does not exist.', 'dropkey-wp' )
			);
		}

		if ( $plan->get_product_id() !== $product->get_id() ) {
			return new \WP_Error(
				'dropkey_checkout_product_plan_mismatch',
				__( 'The selected plan does not belong to the selected product.', 'dropkey-wp' )
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
				__( 'The selected payment gateway is not currently available.', 'dropkey-wp' )
			);
		}

		$context = array(
			'customer'   => $customer,
			'product'    => $product,
			'plan'       => $plan,
			'gateway'    => $gateway_id,
			'return_url' => isset( $data['return_url'] )
				? esc_url_raw( $data['return_url'] )
				: '',
			'cancel_url' => isset( $data['cancel_url'] )
				? esc_url_raw( $data['cancel_url'] )
				: '',
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
				__( 'The payment gateway returned an invalid checkout response.', 'dropkey-wp' )
			);
		}

		$gateway_subscription_id = $this->get_gateway_subscription_id(
			$result
		);

		if ( '' === $gateway_subscription_id ) {
			return new \WP_Error(
				'dropkey_checkout_gateway_subscription_missing',
				__( 'The payment gateway did not return a subscription ID.', 'dropkey-wp' )
			);
		}

		$existing_subscription = $this->subscriptions->find_by_gateway_subscription_id(
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
				'product_id'             => $product->get_id(),
				'plan_id'                 => $plan->get_id(),
				'gateway'                => $gateway_id,
				'gateway_subscription_id' => $gateway_subscription_id,
				'status'                 => Subscription::STATUS_PENDING,
				'current_period_start'   => null,
				'current_period_end'     => null,
				'cancel_at_period_end'   => false,
				'cancelled_at'           => null,
				'past_due_at'             => null,
				'ended_at'                => null,
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
				'current_period_start'   => $subscription->get_current_period_start(),
				'current_period_end'     => $subscription->get_current_period_end(),
				'cancel_at_period_end'   => $subscription->get_cancel_at_period_end(),
				'cancelled_at'           => $subscription->get_cancelled_at(),
				'past_due_at'             => null,
				'ended_at'                => null,
			)
		);

		if ( is_wp_error( $created_subscription ) ) {
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
	 * Extract the provider subscription ID from a gateway response.
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