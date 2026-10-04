<?php
/**
 * Activate subscription application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

final class ActivateSubscription {

	private $subscriptions;

	/**
	 * Constructor.
	 *
	 * @param SubscriptionRepository $subscriptions Subscription repository.
	 */
	public function __construct( SubscriptionRepository $subscriptions ) {
		$this->subscriptions = $subscriptions;
	}

	/**
	 * Activate a pending subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return Subscription|\WP_Error
	 */
	public function execute( $subscription_id ) {
		$subscription_id = absint( $subscription_id );

		if ( $subscription_id <= 0 ) {
			return new \WP_Error(
				'dropkey_subscription_id_required',
				__( 'A valid subscription ID is required.', 'dropkey-wp' )
			);
		}

		$subscription = $this->subscriptions->find( $subscription_id );

		if ( ! $subscription ) {
			return new \WP_Error(
				'dropkey_subscription_not_found',
				__( 'The subscription does not exist.', 'dropkey-wp' )
			);
		}

		/*
		 * Activation is intentionally idempotent.
		 *
		 * This is important for payment gateways because the same
		 * confirmation event may be delivered more than once.
		 */
		if ( Subscription::STATUS_ACTIVE === $subscription->get_status() ) {
			return $subscription;
		}

		if ( Subscription::STATUS_PENDING !== $subscription->get_status() ) {
			return new \WP_Error(
				'dropkey_subscription_activation_not_allowed',
				__( 'This subscription cannot be activated from its current status.', 'dropkey-wp' )
			);
		}

		$updated = $this->subscriptions->update_status(
			$subscription_id,
			Subscription::STATUS_ACTIVE
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$activated_subscription = $this->subscriptions->find( $subscription_id );

		if ( ! $activated_subscription ) {
			return new \WP_Error(
				'dropkey_subscription_activation_reload_failed',
				__( 'The activated subscription could not be reloaded.', 'dropkey-wp' )
			);
		}

		do_action(
			'dropkey_wp_subscription_activated',
			$activated_subscription
		);

		return $activated_subscription;
	}
}