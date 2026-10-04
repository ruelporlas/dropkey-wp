<?php
/**
 * Enforce subscription payment grace periods.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

final class EnforcePastDueSubscriptions {

	/**
	 * Payment grace period in seconds.
	 */
	public const GRACE_PERIOD_SECONDS = 604800;

	private $subscriptions;

	private $change_status;

	public function __construct( SubscriptionRepository $subscriptions ) {
		$this->subscriptions = $subscriptions;
		$this->change_status = new ChangeSubscriptionStatus( $subscriptions );
	}

	/**
	 * Enforce the payment grace period.
	 *
	 * @return array<int,int> Subscription IDs that were suspended.
	 */
	public function execute() {
		$subscriptions = $this->subscriptions->find_past_due_expired(
			self::GRACE_PERIOD_SECONDS
		);

		$suspended = array();

		foreach ( $subscriptions as $subscription ) {
			/*
			 * The repository query already limits this to past_due
			 * subscriptions. This additional check protects against
			 * unexpected repository results.
			 */
			if ( Subscription::STATUS_PAST_DUE !== $subscription->get_status() ) {
				continue;
			}

			$result = $this->change_status->execute(
				$subscription->get_id(),
				Subscription::STATUS_SUSPENDED
			);

			if ( is_wp_error( $result ) ) {
				do_action(
					'dropkey_wp_subscription_grace_period_enforcement_failed',
					$subscription,
					$result
				);

				continue;
			}

			$suspended[] = $subscription->get_id();

			do_action(
				'dropkey_wp_subscription_grace_period_expired',
				$result
			);
		}

		return $suspended;
	}
}