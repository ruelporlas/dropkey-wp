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

	private $change_status;

	/**
	 * Constructor.
	 *
	 * @param SubscriptionRepository $subscriptions Subscription repository.
	 */
	public function __construct( SubscriptionRepository $subscriptions ) {
		$this->subscriptions = $subscriptions;
		$this->change_status = new ChangeSubscriptionStatus(
			$subscriptions
		);
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
				__(
					'This subscription cannot be activated from its current status.',
					'dropkey-wp'
				)
			);
		}

		/*
		 * Route activation through the central lifecycle service so
		 * validation, lifecycle hooks, and audit logging all use the
		 * same application boundary.
		 */
		return $this->change_status->execute(
			$subscription_id,
			Subscription::STATUS_ACTIVE
		);
	}
}