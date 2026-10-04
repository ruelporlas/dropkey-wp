<?php
/**
 * Create plan application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Domain\Plan;

defined( 'ABSPATH' ) || exit;

final class CreatePlan {

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
	 * Create a plan.
	 *
	 * Free plans are normalized to zero price and no billing
	 * interval before domain validation and persistence.
	 *
	 * @param array<string,mixed> $data Plan data.
	 * @return Plan|\WP_Error
	 */
	public function execute( array $data ) {
		$product_id = isset( $data['product_id'] )
			? absint( $data['product_id'] )
			: 0;

		if ( $product_id <= 0 ) {
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
			: '';

		$slug = isset( $data['slug'] )
			? sanitize_title( $data['slug'] )
			: sanitize_title( $name );

		$pricing_type = isset( $data['pricing_type'] )
			? sanitize_key( $data['pricing_type'] )
			: Plan::PRICING_TYPE_PAID;

		$price = isset( $data['price'] )
			? trim( (string) $data['price'] )
			: '0.0000';

		$currency = isset( $data['currency'] )
			? strtoupper( sanitize_text_field( $data['currency'] ) )
			: 'USD';

		$billing_interval = isset( $data['billing_interval'] )
			? sanitize_key( $data['billing_interval'] )
			: Plan::INTERVAL_MONTH;

		$billing_interval_count = isset( $data['billing_interval_count'] )
			? absint( $data['billing_interval_count'] )
			: 1;

		$activation_limit = isset( $data['activation_limit'] )
			? absint( $data['activation_limit'] )
			: 1;

		$status = isset( $data['status'] )
			? sanitize_key( $data['status'] )
			: Plan::STATUS_ACTIVE;

		/*
		 * Free plans are not recurring payment plans.
		 *
		 * Normalize these values at the application boundary rather
		 * than relying on browser-side field visibility.
		 */
		if ( Plan::PRICING_TYPE_FREE === $pricing_type ) {
			$price                  = '0.0000';
			$billing_interval       = '';
			$billing_interval_count = 0;
		}

		$data = apply_filters(
			'dropkey_wp_plan_data',
			array(
				'product_id'             => $product_id,
				'name'                   => $name,
				'slug'                   => $slug,
				'pricing_type'           => $pricing_type,
				'price'                  => $price,
				'currency'               => $currency,
				'billing_interval'       => $billing_interval,
				'billing_interval_count' => $billing_interval_count,
				'activation_limit'       => $activation_limit,
				'status'                 => $status,
			)
		);

		$product_id = isset( $data['product_id'] )
			? absint( $data['product_id'] )
			: 0;

		$name = isset( $data['name'] )
			? sanitize_text_field( $data['name'] )
			: '';

		$slug = isset( $data['slug'] )
			? sanitize_title( $data['slug'] )
			: '';

		$pricing_type = isset( $data['pricing_type'] )
			? sanitize_key( $data['pricing_type'] )
			: '';

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

		/*
		 * A filter may intentionally change the pricing type, so
		 * normalize again after the filter before constructing the
		 * domain entity.
		 */
		if ( Plan::PRICING_TYPE_FREE === $pricing_type ) {
			$price                  = '0.0000';
			$billing_interval       = '';
			$billing_interval_count = 0;
		}

		$plan = new Plan(
			array(
				'product_id'             => $product_id,
				'name'                   => $name,
				'slug'                   => $slug,
				'pricing_type'           => $pricing_type,
				'price'                  => $price,
				'currency'               => $currency,
				'billing_interval'       => $billing_interval,
				'billing_interval_count' => $billing_interval_count,
				'activation_limit'       => $activation_limit,
				'status'                 => $status,
			)
		);

		$validation = $plan->validate();

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( ! $this->products->find( $product_id ) ) {
			return new \WP_Error(
				'dropkey_plan_product_not_found',
				__( 'The selected product does not exist.', 'dropkey-wp' )
			);
		}

		if ( $this->plans->find_by_product_and_slug( $product_id, $slug ) ) {
			return new \WP_Error(
				'dropkey_plan_slug_exists',
				__( 'A plan with this slug already exists for this product.', 'dropkey-wp' )
			);
		}

		$created_plan = $this->plans->create(
			array(
				'product_id'             => $plan->get_product_id(),
				'name'                   => $plan->get_name(),
				'slug'                   => $plan->get_slug(),
				'pricing_type'           => $plan->get_pricing_type(),
				'price'                  => $plan->get_price(),
				'currency'               => $plan->get_currency(),
				'billing_interval'       => $plan->get_billing_interval(),
				'billing_interval_count' => $plan->get_billing_interval_count(),
				'activation_limit'       => $plan->get_activation_limit(),
				'status'                 => $plan->get_status(),
			)
		);

		if ( is_wp_error( $created_plan ) ) {
			return $created_plan;
		}

		do_action(
			'dropkey_wp_plan_created',
			$created_plan
		);

		return $created_plan;
	}
}