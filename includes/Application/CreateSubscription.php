<?php
/**
 * Create subscription application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

final class CreateSubscription {

	private $subscriptions;

	private $customers;

	private $products;

	private $plans;

	/**
	 * Constructor.
	 *
	 * @param SubscriptionRepository $subscriptions Subscription repository.
	 * @param CustomerRepository     $customers     Customer repository.
	 * @param ProductRepository      $products      Product repository.
	 * @param PlanRepository         $plans         Plan repository.
	 */
	public function __construct(
		SubscriptionRepository $subscriptions,
		CustomerRepository $customers,
		ProductRepository $products,
		PlanRepository $plans
	) {
		$this->subscriptions = $subscriptions;
		$this->customers     = $customers;
		$this->products      = $products;
		$this->plans         = $plans;
	}

	/**
	 * Create a subscription.
	 *
	 * @param array<string,mixed> $data Subscription data.
	 * @return Subscription|\WP_Error
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

		if ( ! $this->customers->find( $customer_id ) ) {
			return new \WP_Error(
				'dropkey_subscription_customer_not_found',
				__( 'The selected customer does not exist.', 'dropkey-wp' )
			);
		}

		$product = $this->products->find( $product_id );

		if ( ! $product ) {
			return new \WP_Error(
				'dropkey_subscription_product_not_found',
				__( 'The selected product does not exist.', 'dropkey-wp' )
			);
		}

		$plan = $this->plans->find( $plan_id );

		if ( ! $plan ) {
			return new \WP_Error(
				'dropkey_subscription_plan_not_found',
				__( 'The selected plan does not exist.', 'dropkey-wp' )
			);
		}

		/*
		 * A subscription's product and plan must always belong together.
		 */
		if ( $plan->get_product_id() !== $product->get_id() ) {
			return new \WP_Error(
				'dropkey_subscription_product_plan_mismatch',
				__( 'The selected plan does not belong to the selected product.', 'dropkey-wp' )
			);
		}

		$gateway = isset( $data['gateway'] )
			? sanitize_key( $data['gateway'] )
			: '';

		$gateway_subscription_id = isset( $data['gateway_subscription_id'] )
			? sanitize_text_field( $data['gateway_subscription_id'] )
			: '';

		$status = isset( $data['status'] )
			? sanitize_key( $data['status'] )
			: Subscription::STATUS_PENDING;

		$current_period_start = isset( $data['current_period_start'] )
			? sanitize_text_field( $data['current_period_start'] )
			: '';

		$current_period_end = isset( $data['current_period_end'] )
			? sanitize_text_field( $data['current_period_end'] )
			: '';

		$cancel_at_period_end = ! empty( $data['cancel_at_period_end'] ) ? 1 : 0;

		$cancelled_at = isset( $data['cancelled_at'] )
			? sanitize_text_field( $data['cancelled_at'] )
			: '';

		$past_due_at = isset( $data['past_due_at'] )
			? sanitize_text_field( $data['past_due_at'] )
			: '';

		$ended_at = isset( $data['ended_at'] )
			? sanitize_text_field( $data['ended_at'] )
			: '';

		$data = apply_filters(
			'dropkey_wp_subscription_data',
			array(
				'customer_id'             => $customer_id,
				'product_id'              => $product_id,
				'plan_id'                 => $plan_id,
				'gateway'                 => $gateway,
				'gateway_subscription_id' => $gateway_subscription_id,
				'status'                  => $status,
				'current_period_start'    => $current_period_start,
				'current_period_end'      => $current_period_end,
				'cancel_at_period_end'    => $cancel_at_period_end,
				'cancelled_at'            => $cancelled_at,
				'past_due_at'             => $past_due_at,
				'ended_at'                => $ended_at,
			),
			$product,
			$plan
		);

		$subscription = new Subscription( $data );

		$validation = $subscription->validate();

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		/*
		 * A gateway subscription ID is optional at creation time.
		 * It will normally be supplied once the payment gateway creates
		 * the external subscription.
		 */
		if ( '' !== $subscription->get_gateway_subscription_id() ) {
			$existing = $this->subscriptions->find_by_gateway_subscription_id(
				$subscription->get_gateway(),
				$subscription->get_gateway_subscription_id()
			);

			if ( $existing ) {
				return new \WP_Error(
					'dropkey_subscription_gateway_id_exists',
					__( 'A subscription with this gateway subscription ID already exists.', 'dropkey-wp' )
				);
			}
		}

		$created_subscription = $this->subscriptions->create(
			array(
				'customer_id'             => $subscription->get_customer_id(),
				'product_id'              => $subscription->get_product_id(),
				'plan_id'                 => $subscription->get_plan_id(),
				'gateway'                 => $subscription->get_gateway(),
				'gateway_subscription_id' => $subscription->get_gateway_subscription_id(),
				'status'                  => $subscription->get_status(),
				'current_period_start'    => $subscription->get_current_period_start(),
				'current_period_end'      => $subscription->get_current_period_end(),
				'cancel_at_period_end'    => $subscription->get_cancel_at_period_end(),
				'cancelled_at'            => $subscription->get_cancelled_at(),
				'past_due_at'             => $subscription->get_past_due_at(),
				'ended_at'                => $subscription->get_ended_at(),
			)
		);

		if ( is_wp_error( $created_subscription ) ) {
			return $created_subscription;
		}

		do_action(
			'dropkey_wp_subscription_created',
			$created_subscription
		);

		return $created_subscription;
	}
}