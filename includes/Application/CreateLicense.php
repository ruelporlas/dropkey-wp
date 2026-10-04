<?php
/**
 * Create license application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\License;
use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

final class CreateLicense {

	private $licenses;

	private $subscriptions;

	private $products;

	private $plans;

	public function __construct(
		LicenseRepository $licenses,
		SubscriptionRepository $subscriptions,
		ProductRepository $products,
		PlanRepository $plans
	) {
		$this->licenses      = $licenses;
		$this->subscriptions = $subscriptions;
		$this->products      = $products;
		$this->plans         = $plans;
	}

	/**
	 * Create a license for an active subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return License|\WP_Error
	 */
	public function execute( $subscription_id ) {
		$subscription_id = absint( $subscription_id );

		if ( $subscription_id <= 0 ) {
			return new \WP_Error(
				'dropkey_license_subscription_required',
				__( 'A valid subscription is required.', 'dropkey-wp' )
			);
		}

		$subscription = $this->subscriptions->find( $subscription_id );

		if ( ! $subscription ) {
			return new \WP_Error(
				'dropkey_license_subscription_not_found',
				__( 'The subscription does not exist.', 'dropkey-wp' )
			);
		}

		if ( Subscription::STATUS_ACTIVE !== $subscription->get_status() ) {
			return new \WP_Error(
				'dropkey_license_subscription_not_active',
				__( 'A license can only be created for an active subscription.', 'dropkey-wp' )
			);
		}

		if ( $this->licenses->find_by_subscription_id( $subscription_id ) ) {
			return new \WP_Error(
				'dropkey_license_already_exists',
				__( 'A license already exists for this subscription.', 'dropkey-wp' )
			);
		}

		$product = $this->products->find( $subscription->get_product_id() );

		if ( ! $product ) {
			return new \WP_Error(
				'dropkey_license_product_not_found',
				__( 'The subscription product does not exist.', 'dropkey-wp' )
			);
		}

		$plan = $this->plans->find( $subscription->get_plan_id() );

		if ( ! $plan ) {
			return new \WP_Error(
				'dropkey_license_plan_not_found',
				__( 'The subscription plan does not exist.', 'dropkey-wp' )
			);
		}

		if ( $plan->get_product_id() !== $product->get_id() ) {
			return new \WP_Error(
				'dropkey_license_product_plan_mismatch',
				__( 'The subscription product and plan do not match.', 'dropkey-wp' )
			);
		}

		try {
			$random_bytes = random_bytes( 16 );
		} catch ( \Exception $exception ) {
			return new \WP_Error(
				'dropkey_license_key_generation_failed',
				__( 'A secure license key could not be generated.', 'dropkey-wp' )
			);
		}

		$hex = strtoupper( bin2hex( $random_bytes ) );

		$license_key = 'DK-' .
			substr( $hex, 0, 4 ) . '-' .
			substr( $hex, 4, 4 ) . '-' .
			substr( $hex, 8, 4 ) . '-' .
			substr( $hex, 12, 4 );

		$data = apply_filters(
			'dropkey_wp_license_data',
			array(
				'customer_id'      => $subscription->get_customer_id(),
				'product_id'       => $subscription->get_product_id(),
				'subscription_id'  => $subscription->get_id(),
				'license_key'      => $license_key,
				'status'           => License::STATUS_ACTIVE,
				'activation_limit' => $plan->get_activation_limit(),
				'expires_at'       => $subscription->get_current_period_end(),
			),
			$subscription,
			$product,
			$plan
		);

		$license = new License( $data );

		$validation = $license->validate();

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( $this->licenses->find_by_key( $license->get_license_key() ) ) {
			return new \WP_Error(
				'dropkey_license_key_exists',
				__( 'The generated license key already exists. Please try again.', 'dropkey-wp' )
			);
		}

		$created_license = $this->licenses->create(
			array(
				'customer_id'      => $license->get_customer_id(),
				'product_id'       => $license->get_product_id(),
				'subscription_id'  => $license->get_subscription_id(),
				'license_key'      => $license->get_license_key(),
				'status'           => $license->get_status(),
				'activation_limit' => $license->get_activation_limit(),
				'expires_at'       => $license->get_expires_at(),
			)
		);

		if ( is_wp_error( $created_license ) ) {
			return $created_license;
		}

		do_action(
			'dropkey_wp_license_created',
			$created_license
		);

		return $created_license;
	}
}