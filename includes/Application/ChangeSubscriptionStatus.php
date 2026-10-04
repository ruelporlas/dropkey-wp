<?php
/**
 * Change subscription status application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

final class ChangeSubscriptionStatus {

	private $subscriptions;

	public function __construct( SubscriptionRepository $subscriptions ) {
		$this->subscriptions = $subscriptions;
	}

	public function execute( $subscription_id, $new_status ) {
		$subscription_id = absint( $subscription_id );
		$new_status      = sanitize_key( $new_status );

		if ( $subscription_id <= 0 ) {
			return new \WP_Error(
				'dropkey_subscription_id_required',
				__( 'A valid subscription ID is required.', 'dropkey-wp' )
			);
		}

		if ( ! in_array(
			$new_status,
			array(
				Subscription::STATUS_PENDING,
				Subscription::STATUS_ACTIVE,
				Subscription::STATUS_PAST_DUE,
				Subscription::STATUS_SUSPENDED,
				Subscription::STATUS_CANCELLED,
				Subscription::STATUS_EXPIRED,
			),
			true
		) ) {
			return new \WP_Error(
				'dropkey_subscription_status_invalid',
				__( 'Subscription status is invalid.', 'dropkey-wp' )
			);
		}

		$subscription = $this->subscriptions->find( $subscription_id );

		if ( ! $subscription ) {
			return new \WP_Error(
				'dropkey_subscription_not_found',
				__( 'The subscription does not exist.', 'dropkey-wp' )
			);
		}

		$current_status = $subscription->get_status();

		/*
		 * Keep repeated lifecycle events idempotent.
		 */
		if ( $current_status === $new_status ) {
			return $subscription;
		}

		if ( ! $this->is_transition_allowed(
			$current_status,
			$new_status
		) ) {
			return new \WP_Error(
				'dropkey_subscription_transition_not_allowed',
				__( 'This subscription status transition is not allowed.', 'dropkey-wp' ),
				array(
					'current_status' => $current_status,
					'new_status'     => $new_status,
				)
			);
		}

		$updated = $this->subscriptions->update_status(
			$subscription_id,
			$new_status
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		/*
		 * Record the time the subscription entered past_due.
		 *
		 * This timestamp starts the payment grace period.
		 */
		if ( Subscription::STATUS_PAST_DUE === $new_status ) {
			$past_due_at = $subscription->get_past_due_at();

			if ( empty( $past_due_at ) || '0000-00-00 00:00:00' === $past_due_at ) {
				$past_due_result = $this->subscriptions->mark_past_due(
					$subscription_id,
					current_time( 'mysql', true )
				);

				if ( is_wp_error( $past_due_result ) ) {
					return $past_due_result;
				}
			}
		}

		/*
		 * A recovered payment clears the previous grace-period timestamp.
		 */
		if ( Subscription::STATUS_ACTIVE === $new_status ) {
			$clear_result = $this->subscriptions->clear_past_due(
				$subscription_id
			);

			if ( is_wp_error( $clear_result ) ) {
				return $clear_result;
			}
		}

		$updated_subscription = $this->subscriptions->find(
			$subscription_id
		);

		if ( ! $updated_subscription ) {
			return new \WP_Error(
				'dropkey_subscription_reload_failed',
				__( 'The updated subscription could not be reloaded.', 'dropkey-wp' )
			);
		}

		$this->fire_status_action( $updated_subscription );

		return $updated_subscription;
	}

	private function is_transition_allowed( $current_status, $new_status ) {
		$transitions = array(
			Subscription::STATUS_PENDING => array(
				Subscription::STATUS_ACTIVE,
				Subscription::STATUS_CANCELLED,
			),

			Subscription::STATUS_ACTIVE => array(
				Subscription::STATUS_PAST_DUE,
				Subscription::STATUS_SUSPENDED,
				Subscription::STATUS_CANCELLED,
				Subscription::STATUS_EXPIRED,
			),

			Subscription::STATUS_PAST_DUE => array(
				Subscription::STATUS_ACTIVE,
				Subscription::STATUS_SUSPENDED,
				Subscription::STATUS_CANCELLED,
				Subscription::STATUS_EXPIRED,
			),

			Subscription::STATUS_SUSPENDED => array(
				Subscription::STATUS_ACTIVE,
				Subscription::STATUS_CANCELLED,
				Subscription::STATUS_EXPIRED,
			),

			Subscription::STATUS_CANCELLED => array(
				Subscription::STATUS_EXPIRED,
			),

			Subscription::STATUS_EXPIRED => array(),
		);

		if ( ! isset( $transitions[ $current_status ] ) ) {
			return false;
		}

		return in_array(
			$new_status,
			$transitions[ $current_status ],
			true
		);
	}

	private function fire_status_action( Subscription $subscription ) {
		switch ( $subscription->get_status() ) {
			case Subscription::STATUS_ACTIVE:
				do_action(
					'dropkey_wp_subscription_activated',
					$subscription
				);
				break;

			case Subscription::STATUS_PAST_DUE:
				do_action(
					'dropkey_wp_subscription_past_due',
					$subscription
				);
				break;

			case Subscription::STATUS_SUSPENDED:
				do_action(
					'dropkey_wp_subscription_suspended',
					$subscription
				);
				break;

			case Subscription::STATUS_CANCELLED:
				do_action(
					'dropkey_wp_subscription_cancelled',
					$subscription
				);
				break;

			case Subscription::STATUS_EXPIRED:
				do_action(
					'dropkey_wp_subscription_expired',
					$subscription
				);
				break;
		}
	}
}