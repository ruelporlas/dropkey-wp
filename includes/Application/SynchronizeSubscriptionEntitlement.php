<?php
/**
 * Synchronize a subscription's entitlement with its license.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Domain\License;
use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

final class SynchronizeSubscriptionEntitlement {

	private $licenses;

	/**
	 * Constructor.
	 *
	 * @param LicenseRepository $licenses License repository.
	 */
	public function __construct( LicenseRepository $licenses ) {
		$this->licenses = $licenses;
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

		$license_status = $this->get_license_status(
			$subscription->get_status()
		);

		/*
		 * Some subscription states intentionally do not change
		 * the license entitlement.
		 *
		 * In particular, cancellation is not entitlement loss.
		 * The license remains active until the paid period ends.
		 */
		if ( null === $license_status ) {
			return $license;
		}

		$expires_at = $subscription->get_current_period_end();

		/*
		 * A license entitlement must have a corresponding subscription
		 * period end. Do not overwrite an existing valid expiry with
		 * an empty value.
		 */
		if ( '' === $expires_at || null === $expires_at ) {
			return new \WP_Error(
				'dropkey_license_expiry_missing',
				__(
					'The subscription billing period end is required to synchronize the license entitlement.',
					'dropkey-wp'
				)
			);
		}

		/*
		 * Keep the operation idempotent. Repeated gateway events should
		 * not generate unnecessary database writes or repeated license
		 * transition actions when both the status and expiry are already
		 * synchronized.
		 */
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
			return $updated;
		}

		/*
		 * Only fire a status action when the license status actually
		 * changed. A billing-period renewal that merely extends the
		 * expiry must not be treated as a new license activation.
		 */
		if ( $license->get_status() !== $updated->get_status() ) {
			$this->fire_status_action(
				$updated
			);
		}

		return $updated;
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