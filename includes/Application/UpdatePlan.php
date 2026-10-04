<?php
/**
 * Update plan application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Domain\BillingInterval;
use DropKeyWP\Domain\Plan;
use DropKeyWP\Domain\PlanStatus;

defined( 'ABSPATH' ) || exit;

final class UpdatePlan {

	/**
	 * Plan repository.
	 *
	 * @var PlanRepository
	 */
	private $plans;

	/**
	 * Product repository.
	 *
	 * @var ProductRepository
	 */
	private $products;

	/**
	 * Constructor.
	 *
	 * @param PlanRepository    $plans    Plan repository.
	 * @param ProductRepository $products Product repository.
	 */
	public function __construct(
		PlanRepository $plans,
		ProductRepository $products
	) {
		$this->plans    = $plans;
		$this->products = $products;
	}

	/**
	 * Update a plan.
	 *
	 * @param int                  $plan_id Plan ID.
	 * @param array<string,mixed> $data    Plan data.
	 * @return Plan|\WP_Error
	 */
	public function execute( $plan_id, array $data ) {
		$plan_id = absint( $plan_id );

		if ( $plan_id < 1 ) {
			return new \WP_Error(
				'dropkey_plan_invalid_id',
				__( 'The plan ID is invalid.', 'dropkey-wp' )
			);
		}

		$existing_plan = $this->plans->find( $plan_id );

		if ( ! $existing_plan ) {
			return new \WP_Error(
				'dropkey_plan_not_found',
				__( 'The plan could not be found.', 'dropkey-wp' )
			);
		}

		$product_id = isset( $data['product_id'] )
			? absint( $data['product_id'] )
			: $existing_plan->get_product_id();

		if ( $product_id < 1 ) {
			return new \WP_Error(
				'dropkey_plan_product_required',
				__( 'A valid product is required.', 'dropkey-wp' )
			);
		}

		if ( ! $this->products->find( $product_id ) ) {
			return new \WP_Error(
				'dropkey_plan_product_not_found',
				__( 'The selected product does not exist.', 'dropkey-wp' )
			);
		}

		$name = isset( $data['name'] )
			? sanitize_text_field( $data['name'] )
			: $existing_plan->get_name();

		$slug = isset( $data['slug'] )
			? sanitize_title( $data['slug'] )
			: sanitize_title( $name );

		$price = isset( $data['price'] )
			? trim( (string) $data['price'] )
			: $existing_plan->get_price();

		$currency = isset( $data['currency'] )
			? strtoupper( sanitize_text_field( $data['currency'] ) )
			: $existing_plan->get_currency();

		$billing_interval = isset( $data['billing_interval'] )
			? sanitize_key( $data['billing_interval'] )
			: $existing_plan->get_billing_interval();

		$billing_interval_count = isset( $data['billing_interval_count'] )
			? absint( $data['billing_interval_count'] )
			: $existing_plan->get_billing_interval_count();

		$activation_limit = isset( $data['activation_limit'] )
			? absint( $data['activation_limit'] )
			: $existing_plan->get_activation_limit();

		$status = isset( $data['status'] )
			? sanitize_key( $data['status'] )
			: $existing_plan->get_status();

		$data = apply_filters(
			'dropkey_wp_plan_update_data',
			array(
				'id'                     => $plan_id,
				'product_id'             => $product_id,
				'name'                   => $name,
				'slug'                   => $slug,
				'price'                  => $price,
				'currency'               => $currency,
				'billing_interval'       => $billing_interval,
				'billing_interval_count' => $billing_interval_count,
				'activation_limit'       => $activation_limit,
				'status'                 => $status,
			),
			$existing_plan
		);

		$product_id = isset( $data['product_id'] )
			? absint( $data['product_id'] )
			: 0;

		$name = isset( $data['name'] )
			? sanitize_text_field( $data['name'] )
			: '';

		$slug = isset( $data['slug'] )
			? sanitize_title( $data['slug'] )
			: sanitize_title( $name );

		$price = isset( $data['price'] )
			? trim( (string) $data['price'] )
			: '';

		$currency = isset( $data['currency'] )
			? strtoupper( sanitize_text_field( $data['currency'] ) )
			: '';

		$billing_interval = isset( $data['billing_interval'] )
			? sanitize_key( $data['billing_interval'] )
			: '';

		$billing_interval_count = isset( $data['billing_interval_count'] )
			? absint( $data['billing_interval_count'] )
			: 0;

		$activation_limit = isset( $data['activation_limit'] )
			? absint( $data['activation_limit'] )
			: 0;

		$status = isset( $data['status'] )
			? sanitize_key( $data['status'] )
			: '';

		if ( $product_id < 1 ) {
			return new \WP_Error(
				'dropkey_plan_product_required',
				__( 'A valid product is required.', 'dropkey-wp' )
			);
		}

		if ( ! $this->products->find( $product_id ) ) {
			return new \WP_Error(
				'dropkey_plan_product_not_found',
				__( 'The selected product does not exist.', 'dropkey-wp' )
			);
		}

		if ( '' === $name ) {
			return new \WP_Error(
				'dropkey_plan_name_required',
				__( 'Plan name is required.', 'dropkey-wp' )
			);
		}

		if ( '' === $slug ) {
			return new \WP_Error(
				'dropkey_plan_slug_required',
				__( 'Plan slug is required.', 'dropkey-wp' )
			);
		}

		$slug_plan = $this->plans->find_by_product_and_slug(
			$product_id,
			$slug
		);

		if (
			$slug_plan
			&& $slug_plan->get_id() !== $plan_id
		) {
			return new \WP_Error(
				'dropkey_plan_slug_exists',
				__( 'A plan with this slug already exists for this product.', 'dropkey-wp' )
			);
		}

		if ( ! BillingInterval::is_valid( $billing_interval ) ) {
			return new \WP_Error(
				'dropkey_plan_billing_interval_invalid',
				__( 'Billing interval is invalid.', 'dropkey-wp' )
			);
		}

		if ( ! PlanStatus::is_valid( $status ) ) {
			return new \WP_Error(
				'dropkey_plan_status_invalid',
				__( 'Plan status is invalid.', 'dropkey-wp' )
			);
		}

		$plan = new Plan(
			array(
				'id'                     => $plan_id,
				'product_id'             => $product_id,
				'name'                   => $name,
				'slug'                   => $slug,
				'price'                  => $price,
				'currency'               => $currency,
				'billing_interval'       => $billing_interval,
				'billing_interval_count' => $billing_interval_count,
				'activation_limit'       => $activation_limit,
				'status'                 => $status,
				'created_at'             => $existing_plan->get_created_at(),
				'updated_at'             => $existing_plan->get_updated_at(),
			)
		);

		$validation = $plan->validate();

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$updated_plan = $this->plans->update(
			$plan_id,
			array(
				'product_id'             => $plan->get_product_id(),
				'name'                   => $plan->get_name(),
				'slug'                   => $plan->get_slug(),
				'price'                  => $plan->get_price(),
				'currency'               => $plan->get_currency(),
				'billing_interval'       => $plan->get_billing_interval(),
				'billing_interval_count' => $plan->get_billing_interval_count(),
				'activation_limit'       => $plan->get_activation_limit(),
				'status'                 => $plan->get_status(),
			)
		);

		if ( is_wp_error( $updated_plan ) ) {
			return $updated_plan;
		}

		/**
		 * Fires after a plan has been updated.
		 *
		 * @param Plan $updated_plan  Updated plan.
		 * @param Plan $previous_plan Previous plan state.
		 */
		do_action(
			'dropkey_wp_plan_updated',
			$updated_plan,
			$existing_plan
		);

		return $updated_plan;
	}
}