<?php
/**
 * Synchronize a subscription's entitlement with its license.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Domain\License;
use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

final class SynchronizeSubscriptionEntitlement {

	/**
	 * License repository.
	 *
	 * @var LicenseRepository
	 */
	private $licenses;

	/**
	 * Plan repository.
	 *
	 * @var PlanRepository
	 */
	private $plans;

	/**
	 * Constructor.
	 *
	 * @param LicenseRepository $licenses License repository.
	 * @param PlanRepository    $plans    Plan repository.
	 */
	public function __construct(
		LicenseRepository $licenses,
		PlanRepository $plans
	) {
		$this->licenses = $licenses;
		$this->plans    = $plans;
	}

	/**
	 * Synchronize the license associated with a subscription.
	 *
	 * @param Subscription $subscription Subscription.
	 * @return License|null|\WP_Error
	 */
	public function execute( Subscription $subscription ) {
		$license = $this->licenses->find_by_subscription_id(
			$subscription->get_id()
		);

		if ( ! $license ) {
			return null;
		}

		if ( License::STATUS_REVOKED === $license->get_status() ) {
			return $license;
		}

		$license_status = $this->get_license_status(
			$subscription->get_status()
		);

		if ( null === $license_status ) {
			return $license;
		}

		$expires_at = $subscription->get_current_period_end();

		/*
		 * A free plan is perpetual for the current MVP. Its
		 * subscription has no billing period end, so its license
		 * expiration must also remain null.
		 *
		 * Paid subscriptions still require a real billing period end.
		 */
		$plan = $this->plans->find(
			$subscription->get_plan_id()
		);

		if (
			( null === $expires_at || '' === $expires_at )
			&&
			( ! $plan || ! $plan->is_free() )
		) {
			$error = new \WP_Error(
				'dropkey_license_expiry_missing',
				__(
					'The subscription billing period end is required to synchronize the license entitlement.',
					'dropkey-wp'
				)
			);

			$this->handle_sync_error(
				$subscription,
				$license,
				$error
			);

			return $error;
		}

		if (
			$license->get_status() === $license_status
			&& $license->get_expires_at() === $expires_at
		) {
			return $license;
		}

		$updated = $this->licenses->update_entitlement(
			$license->get_id(),
			$license_status,
			$expires_at
		);

		if ( is_wp_error( $updated ) ) {
			$this->handle_sync_error(
				$subscription,
				$license,
				$updated
			);

			return $updated;
		}

		if ( $license->get_status() !== $updated->get_status() ) {
			$this->fire_status_action( $updated );
		}

		do_action(
			'dropkey_wp_subscription_entitlement_synchronized',
			$updated,
			$subscription
		);

		return $updated;
	}

	/**
	 * Handle an entitlement synchronization failure.
	 *
	 * @param Subscription $subscription Subscription.
	 * @param License      $license      Existing license.
	 * @param \WP_Error    $error        Synchronization error.
	 * @return void
	 */
	private function handle_sync_error(
		Subscription $subscription,
		License $license,
		\WP_Error $error
	) {
		error_log(
			sprintf(
				'DropKey WP: Failed to synchronize license entitlement for subscription #%d and license #%d: %s',
				absint( $subscription->get_id() ),
				absint( $license->get_id() ),
				$error->get_error_message()
			)
		);

		do_action(
			'dropkey_wp_subscription_entitlement_sync_failed',
			$error,
			$subscription,
			$license
		);
	}

	/**
	 * Determine the license status for a subscription status.
	 *
	 * @param string $subscription_status Subscription status.
	 * @return string|null
	 */
	private function get_license_status( $subscription_status ) {
		switch ( $subscription_status ) {
			case Subscription::STATUS_ACTIVE:
			case Subscription::STATUS_PAST_DUE:
				return License::STATUS_ACTIVE;

			case Subscription::STATUS_SUSPENDED:
				return License::STATUS_SUSPENDED;

			case Subscription::STATUS_EXPIRED:
				return License::STATUS_EXPIRED;

			case Subscription::STATUS_PENDING:
			case Subscription::STATUS_CANCELLED:
			default:
				return null;
		}
	}

	/**
	 * Fire the appropriate license status action.
	 *
	 * @param License $license Updated license.
	 * @return void
	 */
	private function fire_status_action( License $license ) {
		switch ( $license->get_status() ) {
			case License::STATUS_ACTIVE:
				do_action(
					'dropkey_wp_license_activated',
					$license
				);
				break;

			case License::STATUS_SUSPENDED:
				do_action(
					'dropkey_wp_license_suspended',
					$license
				);
				break;

			case License::STATUS_EXPIRED:
				do_action(
					'dropkey_wp_license_expired',
					$license
				);
				break;

			case License::STATUS_REVOKED:
				do_action(
					'dropkey_wp_license_revoked',
					$license
				);
				break;
		}
	}
}