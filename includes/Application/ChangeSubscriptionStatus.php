<?php
/**
 * Change subscription status application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\SubscriptionEventRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

final class ChangeSubscriptionStatus {

	/**
	 * Subscription repository.
	 *
	 * @var SubscriptionRepository
	 */
	private $subscriptions;

	/**
	 * Subscription event repository.
	 *
	 * @var SubscriptionEventRepository
	 */
	private $subscription_events;

	/**
	 * Constructor.
	 *
	 * The event repository remains optional for backwards compatibility
	 * with existing application services that instantiate this service
	 * directly.
	 *
	 * @param SubscriptionRepository      $subscriptions       Subscription repository.
	 * @param SubscriptionEventRepository $subscription_events Subscription event repository.
	 */
	public function __construct(
		SubscriptionRepository $subscriptions,
		SubscriptionEventRepository $subscription_events = null
	) {
		$this->subscriptions = $subscriptions;

		if ( $subscription_events ) {
			$this->subscription_events = $subscription_events;
		} else {
			global $wpdb;

			$this->subscription_events = new SubscriptionEventRepository(
				$wpdb
			);
		}
	}

	/**
	 * Execute a subscription status change.
	 *
	 * A subscription-specific database advisory lock serializes concurrent
	 * lifecycle transitions for the same subscription. This prevents two
	 * simultaneous webhook requests from both evaluating the same old
	 * status and then overwriting each other's lifecycle state.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $new_status      New status.
	 * @return Subscription|\WP_Error Updated subscription or error.
	 */
	public function execute( $subscription_id, $new_status ) {
		$subscription_id = absint( $subscription_id );
		$new_status      = sanitize_key( $new_status );

		if ( $subscription_id <= 0 ) {
			return new \WP_Error(
				'dropkey_subscription_id_required',
				__( 'A valid subscription ID is required.', 'dropkey-wp' )
			);
		}

		if (
			! in_array(
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
			)
		) {
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

		global $wpdb;

		$lock_name = $this->get_lock_name( $subscription_id );

		$lock_acquired = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s, %d)',
				$lock_name,
				10
			)
		);

		if ( '1' !== (string) $lock_acquired ) {
			return new \WP_Error(
				'dropkey_subscription_status_lock_failed',
				__(
					'The subscription is currently being updated. Please try again.',
					'dropkey-wp'
				)
			);
		}

		try {
			/*
			 * Reload after acquiring the lock. The status read before the
			 * lock may already be stale if another request completed a
			 * transition while this request was waiting.
			 */
			$subscription = $this->subscriptions->find(
				$subscription_id
			);

			if ( ! $subscription ) {
				return new \WP_Error(
					'dropkey_subscription_not_found',
					__( 'The subscription does not exist.', 'dropkey-wp' )
				);
			}

			$current_status = $subscription->get_status();

			/*
			 * A request to set the subscription to its existing status
			 * is intentionally idempotent.
			 *
			 * This check must happen after acquiring the lock so a request
			 * that was waiting behind another transition does not fire a
			 * duplicate lifecycle action.
			 */
			if ( $current_status === $new_status ) {
				return $subscription;
			}

			if (
				! $this->is_transition_allowed(
					$current_status,
					$new_status
				)
			) {
				return new \WP_Error(
					'dropkey_subscription_transition_not_allowed',
					__(
						'This subscription status transition is not allowed.',
						'dropkey-wp'
					),
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

			if ( Subscription::STATUS_PAST_DUE === $new_status ) {
				$past_due_at = $subscription->get_past_due_at();

				if (
					empty( $past_due_at )
					|| '0000-00-00 00:00:00' === $past_due_at
				) {
					$past_due_result = $this->subscriptions->mark_past_due(
						$subscription_id,
						current_time( 'mysql', true )
					);

					if ( is_wp_error( $past_due_result ) ) {
						return $past_due_result;
					}
				}
			}

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
					__(
						'The updated subscription could not be reloaded.',
						'dropkey-wp'
					)
				);
			}

			/*
			 * Record the lifecycle transition after the subscription has
			 * successfully reached its new state.
			 *
			 * Audit logging must not make an otherwise successful status
			 * change appear to have failed.
			 */
			$this->record_status_change(
				$subscription_id,
				$current_status,
				$new_status
			);

			/*
			 * Fire the lifecycle action while the subscription lock is
			 * still held. This keeps the status transition and its immediate
			 * entitlement/license synchronization serialized for this
			 * subscription.
			 */
			$this->fire_status_action( $updated_subscription );

			return $updated_subscription;
		} finally {
			/*
			 * GET_LOCK() is connection-scoped, so release it through the
			 * same $wpdb connection that acquired it.
			 */
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT RELEASE_LOCK(%s)',
					$lock_name
				)
			);
		}
	}

	/**
	 * Build the advisory lock name for a subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return string
	 */
	private function get_lock_name( $subscription_id ) {
		return 'dropkey_subscription_status_' . absint( $subscription_id );
	}

	/**
	 * Record a lifecycle status change.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $previous_status Previous status.
	 * @param string $new_status      New status.
	 * @return void
	 */
	private function record_status_change(
		$subscription_id,
		$previous_status,
		$new_status
	) {
		$result = $this->subscription_events->create(
			array(
				'subscription_id' => $subscription_id,
				'event_type'      => 'status_changed',
				'previous_status' => $previous_status,
				'new_status'      => $new_status,
				'actor_user_id'   => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			error_log(
				sprintf(
					'DropKey WP: Failed to record subscription lifecycle event for subscription #%d (%s -> %s): %s',
					absint( $subscription_id ),
					$previous_status,
					$new_status,
					$result->get_error_message()
				)
			);
		}
	}

	/**
	 * Determine whether a status transition is allowed.
	 *
	 * @param string $current_status Current status.
	 * @param string $new_status     New status.
	 * @return bool
	 */
	private function is_transition_allowed(
		$current_status,
		$new_status
	) {
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

	/**
	 * Fire the lifecycle action for the new status.
	 *
	 * @param Subscription $subscription Updated subscription.
	 * @return void
	 */
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