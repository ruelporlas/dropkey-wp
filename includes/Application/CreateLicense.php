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

	/**
	 * License repository.
	 *
	 * @var LicenseRepository
	 */
	private $licenses;

	/**
	 * Subscription repository.
	 *
	 * @var SubscriptionRepository
	 */
	private $subscriptions;

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
	 * Constructor.
	 *
	 * @param LicenseRepository       $licenses      License repository.
	 * @param SubscriptionRepository $subscriptions Subscription repository.
	 * @param ProductRepository      $products      Product repository.
	 * @param PlanRepository         $plans          Plan repository.
	 */
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
	 * This operation is idempotent and serialized per subscription.
	 * The lock prevents concurrent lifecycle events from both
	 * observing that no license exists and creating separate licenses.
	 *
	 * The subscription is reloaded after acquiring the lock so that
	 * license creation always uses the latest persisted subscription
	 * state.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return License|\WP_Error
	 */
	public function execute( $subscription_id ) {
		global $wpdb;

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
				__(
					'A license can only be created for an active subscription.',
					'dropkey-wp'
				)
			);
		}

		/*
		 * Acquire a database-level advisory lock for this subscription.
		 *
		 * MySQL releases advisory locks automatically if the database
		 * connection closes, so a fatal request failure cannot leave
		 * a permanent application lock behind.
		 */
		$lock_name = $this->get_lock_name( $subscription_id );

		$lock_result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK( %s, %d )',
				$lock_name,
				10
			)
		);

		if ( '1' !== (string) $lock_result ) {
			return new \WP_Error(
				'dropkey_license_creation_locked',
				__(
					'License creation is currently being processed. Please try again.',
					'dropkey-wp'
				)
			);
		}

		try {
			/*
			 * Re-check the license after acquiring the lock.
			 *
			 * Another request may have created the license while this
			 * request was waiting for the lock.
			 */
			$existing_license = $this->licenses->find_by_subscription_id(
				$subscription_id
			);

			if ( $existing_license ) {
				return $existing_license;
			}

			/*
			 * Reload the subscription after acquiring the lock.
			 *
			 * The subscription may have changed while this request was
			 * waiting. Never create an active license from a stale
			 * subscription object.
			 */
			$subscription = $this->subscriptions->find(
				$subscription_id
			);

			if ( ! $subscription ) {
				return new \WP_Error(
					'dropkey_license_subscription_not_found',
					__( 'The subscription does not exist.', 'dropkey-wp' )
				);
			}

			if ( Subscription::STATUS_ACTIVE !== $subscription->get_status() ) {
				return new \WP_Error(
					'dropkey_license_subscription_not_active',
					__(
						'A license can only be created for an active subscription.',
						'dropkey-wp'
					)
				);
			}

			$product = $this->products->find(
				$subscription->get_product_id()
			);

			if ( ! $product ) {
				return new \WP_Error(
					'dropkey_license_product_not_found',
					__( 'The subscription product does not exist.', 'dropkey-wp' )
				);
			}

			$plan = $this->plans->find(
				$subscription->get_plan_id()
			);

			if ( ! $plan ) {
				return new \WP_Error(
					'dropkey_license_plan_not_found',
					__( 'The subscription plan does not exist.', 'dropkey-wp' )
				);
			}

			if ( $plan->get_product_id() !== $product->get_id() ) {
				return new \WP_Error(
					'dropkey_license_product_plan_mismatch',
					__(
						'The subscription product and plan do not match.',
						'dropkey-wp'
					)
				);
			}

			try {
				$random_bytes = random_bytes( 16 );
			} catch ( \Exception $exception ) {
				return new \WP_Error(
					'dropkey_license_key_generation_failed',
					__(
						'A secure license key could not be generated.',
						'dropkey-wp'
					)
				);
			}

			$hex = strtoupper(
				bin2hex( $random_bytes )
			);

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
					__(
						'The generated license key already exists. Please try again.',
						'dropkey-wp'
					)
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
		} finally {
			/*
			 * RELEASE_LOCK() must run on the same database connection
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
	 * Generate the advisory lock name for a subscription.
	 *
	 * MySQL limits user-level lock names to 64 characters. The
	 * subscription ID is numeric, so this remains safely below that
	 * limit while keeping the lock namespace specific to DropKey WP.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return string
	 */
	private function get_lock_name( $subscription_id ) {
		return 'dropkey_license_subscription_' . absint(
			$subscription_id
		);
	}
}