<?php
/**
 * Process payment gateway events.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\GatewayEventRepository;
use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\GatewayEvent;
use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

final class ProcessPaymentEvent {

	private $events;

	private $subscriptions;

	private $licenses;

	private $change_status;

	private $synchronize_entitlement;

	public function __construct(
		GatewayEventRepository $events,
		SubscriptionRepository $subscriptions,
		LicenseRepository $licenses
	) {
		$this->events                  = $events;
		$this->subscriptions           = $subscriptions;
		$this->licenses                = $licenses;
		$this->change_status           = new ChangeSubscriptionStatus(
			$subscriptions
		);
		$this->synchronize_entitlement = new SynchronizeSubscriptionEntitlement(
			$licenses
		);
	}

	/**
	 * Process a normalized gateway event.
	 *
	 * @param array $event Normalized gateway event.
	 * @return GatewayEvent|WP_Error
	 */
	public function execute( array $event ) {
		$gateway = isset( $event['gateway'] )
			? sanitize_key( $event['gateway'] )
			: '';

		$event_id = isset( $event['event_id'] )
			? sanitize_text_field( $event['event_id'] )
			: '';

		$event_type = isset( $event['event_type'] )
			? sanitize_text_field( $event['event_type'] )
			: '';

		$payload = isset( $event['payload'] )
			? (string) $event['payload']
			: '';

		if ( '' === $gateway || '' === $event_id || '' === $event_type ) {
			return new \WP_Error(
				'dropkey_gateway_event_invalid',
				__( 'The gateway event is invalid.', 'dropkey-wp' )
			);
		}

		if ( '' === $payload ) {
			return new \WP_Error(
				'dropkey_gateway_event_payload_required',
				__( 'The gateway event payload is required.', 'dropkey-wp' )
			);
		}

		$payload_hash = hash( 'sha256', $payload );

		$record = $this->events->find_by_gateway_event(
			$gateway,
			$event_id
		);

		if ( $record ) {
			if ( GatewayEvent::STATUS_PROCESSED === $record->get_status() ) {
				return $record;
			}

			/*
			 * A provider event ID must represent one immutable payload.
			 * Do not process a different payload under an existing event ID.
			 */
			if ( $record->get_payload_hash() !== $payload_hash ) {
				return new \WP_Error(
					'dropkey_gateway_event_payload_mismatch',
					__(
						'The gateway event payload does not match the previously recorded event.',
						'dropkey-wp'
					)
				);
			}

			/*
			 * A previously failed event may safely be processed again.
			 */
			if ( GatewayEvent::STATUS_FAILED === $record->get_status() ) {
				$reset = $this->events->reset_for_retry(
					$record->get_id()
				);

				if ( is_wp_error( $reset ) ) {
					return $reset;
				}

				$record = $this->events->find_by_gateway_event(
					$gateway,
					$event_id
				);

				if ( ! $record ) {
					return new \WP_Error(
						'dropkey_gateway_event_reload_failed',
						__(
							'The gateway event could not be reloaded for retry.',
							'dropkey-wp'
						)
					);
				}
			}
		} else {
			$record = $this->events->create(
				array(
					'gateway'      => $gateway,
					'event_id'     => $event_id,
					'event_type'   => $event_type,
					'status'       => GatewayEvent::STATUS_RECEIVED,
					'payload_hash' => $payload_hash,
					'payload'      => $payload,
				)
			);

			if ( is_wp_error( $record ) ) {
				/*
				 * Another request may have created the same event between
				 * our lookup and insert. Reload it before treating this
				 * as a genuine failure.
				 */
				$record = $this->events->find_by_gateway_event(
					$gateway,
					$event_id
				);

				if ( ! $record ) {
					return new \WP_Error(
						'dropkey_gateway_event_create_failed',
						__(
							'The gateway event could not be recorded.',
							'dropkey-wp'
						)
					);
				}

				if ( GatewayEvent::STATUS_PROCESSED === $record->get_status() ) {
					return $record;
				}

				if ( $record->get_payload_hash() !== $payload_hash ) {
					return new \WP_Error(
						'dropkey_gateway_event_payload_mismatch',
						__(
							'The gateway event payload does not match the previously recorded event.',
							'dropkey-wp'
						)
					);
				}

				if ( GatewayEvent::STATUS_FAILED === $record->get_status() ) {
					$reset = $this->events->reset_for_retry(
						$record->get_id()
					);

					if ( is_wp_error( $reset ) ) {
						return $reset;
					}

					$record = $this->events->find_by_gateway_event(
						$gateway,
						$event_id
					);

					if ( ! $record ) {
						return new \WP_Error(
							'dropkey_gateway_event_reload_failed',
							__(
								'The gateway event could not be reloaded for retry.',
								'dropkey-wp'
							)
						);
					}
				}
			}
		}

		/*
		 * Claim the event atomically. Only one concurrent request can
		 * change received -> processing.
		 */
		$processing = $this->events->claim_for_processing(
			$record->get_id()
		);

		if ( is_wp_error( $processing ) ) {
			if (
				'dropkey_gateway_event_already_processing' ===
				$processing->get_error_code()
			) {
				return new \WP_Error(
					'dropkey_gateway_event_processing',
					__(
						'The gateway event is already being processed.',
						'dropkey-wp'
					)
				);
			}

			return $processing;
		}

		$decoded = json_decode( $record->get_payload(), true );

		if ( ! is_array( $decoded ) ) {
			$error = new \WP_Error(
				'dropkey_gateway_event_invalid_json',
				__(
					'The gateway event payload is not valid JSON.',
					'dropkey-wp'
				)
			);

			$this->events->update_status(
				$record->get_id(),
				GatewayEvent::STATUS_FAILED,
				$error->get_error_message()
			);

			do_action(
				'dropkey_wp_gateway_event_failed',
				$record,
				$error
			);

			return $error;
		}

		$result = $this->process_subscription_event(
			$gateway,
			$record->get_event_type(),
			$decoded
		);

		if ( is_wp_error( $result ) ) {
			$this->events->update_status(
				$record->get_id(),
				GatewayEvent::STATUS_FAILED,
				$result->get_error_message()
			);

			do_action(
				'dropkey_wp_gateway_event_failed',
				$record,
				$result
			);

			return $result;
		}

		$processed = $this->events->mark_processed(
			$record->get_id()
		);

		if ( is_wp_error( $processed ) ) {
			return $processed;
		}

		$processed_event = $this->events->find_by_gateway_event(
			$gateway,
			$event_id
		);

		do_action(
			'dropkey_wp_gateway_event_processed',
			$processed_event ? $processed_event : $record,
			$result
		);

		return $processed_event ? $processed_event : $record;
	}

	/**
	 * Process a subscription-related event.
	 *
	 * @param string $gateway    Gateway ID.
	 * @param string $event_type Gateway event type.
	 * @param array  $payload    Decoded event payload.
	 * @return true|WP_Error
	 */
	private function process_subscription_event(
		$gateway,
		$event_type,
		array $payload
	) {
		$status = $this->map_event_to_status( $event_type );

		if ( '' === $status ) {
			return true;
		}

		$resource = isset( $payload['resource'] )
			&& is_array( $payload['resource'] )
			? $payload['resource']
			: array();

		$gateway_subscription_id = isset( $resource['id'] )
			? sanitize_text_field( $resource['id'] )
			: '';

		if ( '' === $gateway_subscription_id ) {
			return new \WP_Error(
				'dropkey_gateway_subscription_id_missing',
				__(
					'The gateway subscription ID is missing from the event.',
					'dropkey-wp'
				)
			);
		}

		$subscription = $this->subscriptions->find_by_gateway_subscription_id(
			$gateway,
			$gateway_subscription_id
		);

		if ( ! $subscription ) {
			return new \WP_Error(
				'dropkey_gateway_subscription_not_found',
				__(
					'The corresponding DropKey subscription could not be found.',
					'dropkey-wp'
				)
			);
		}

		$period = $this->get_subscription_period(
			$gateway,
			$event_type,
			$resource
		);

		if ( is_wp_error( $period ) ) {
			return $period;
		}

		if ( $period ) {
			$period_result = $this->subscriptions->update_period(
				$subscription->get_id(),
				$period['start'],
				$period['end']
			);

			if ( is_wp_error( $period_result ) ) {
				return $period_result;
			}

			$subscription = $this->subscriptions->find(
				$subscription->get_id()
			);

			if ( ! $subscription ) {
				return new \WP_Error(
					'dropkey_subscription_reload_failed',
					__(
						'The subscription could not be reloaded after its billing period was updated.',
						'dropkey-wp'
					)
				);
			}

			$entitlement = $this->synchronize_entitlement->execute(
				$subscription
			);

			if ( is_wp_error( $entitlement ) ) {
				return $entitlement;
			}
		}

		if ( $subscription->get_status() === $status ) {
			return true;
		}

		$result = $this->change_status->execute(
			$subscription->get_id(),
			$status
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Extract the current billing period from a gateway resource.
	 *
	 * @param string $gateway    Gateway ID.
	 * @param string $event_type Gateway event type.
	 * @param array  $resource   Gateway resource.
	 * @return array|false|\WP_Error
	 */
	private function get_subscription_period(
		$gateway,
		$event_type,
		array $resource
	) {
		if ( 'paypal' !== $gateway ) {
			return false;
		}

		if (
			'BILLING.SUBSCRIPTION.ACTIVATED' !== $event_type
			&& 'BILLING.SUBSCRIPTION.PAYMENT.SUCCEEDED' !== $event_type
		) {
			return false;
		}

		$billing_info = isset( $resource['billing_info'] )
			&& is_array( $resource['billing_info'] )
			? $resource['billing_info']
			: array();

		$period_start = '';

		if ( 'BILLING.SUBSCRIPTION.ACTIVATED' === $event_type ) {
			$period_start = isset( $resource['start_time'] )
				? (string) $resource['start_time']
				: '';
		} else {
			$last_payment = isset( $billing_info['last_payment'] )
				&& is_array( $billing_info['last_payment'] )
				? $billing_info['last_payment']
				: array();

			$period_start = isset( $last_payment['time'] )
				? (string) $last_payment['time']
				: '';
		}

		$period_end = isset( $billing_info['next_billing_time'] )
			? (string) $billing_info['next_billing_time']
			: '';

		$period_start = $this->paypal_datetime_to_utc_mysql(
			$period_start
		);

		$period_end = $this->paypal_datetime_to_utc_mysql(
			$period_end
		);

		if ( '' === $period_start || '' === $period_end ) {
			return false;
		}

		return array(
			'start' => $period_start,
			'end'   => $period_end,
		);
	}

	/**
	 * Convert a PayPal RFC3339 datetime to a UTC MySQL datetime.
	 *
	 * @param string $datetime PayPal datetime.
	 * @return string
	 */
	private function paypal_datetime_to_utc_mysql( $datetime ) {
		$datetime = trim( (string) $datetime );

		if ( '' === $datetime ) {
			return '';
		}

		try {
			$date = new \DateTimeImmutable(
				$datetime,
				new \DateTimeZone( 'UTC' )
			);

			return $date
				->setTimezone( new \DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $exception ) {
			return '';
		}
	}

	/**
	 * Map a gateway event to a DropKey subscription status.
	 *
	 * @param string $event_type Gateway event type.
	 * @return string
	 */
	private function map_event_to_status( $event_type ) {
		$map = array(
			'BILLING.SUBSCRIPTION.ACTIVATED'          => Subscription::STATUS_ACTIVE,
			'BILLING.SUBSCRIPTION.PAYMENT.SUCCEEDED' => Subscription::STATUS_ACTIVE,
			'BILLING.SUBSCRIPTION.PAYMENT.FAILED'    => Subscription::STATUS_PAST_DUE,
			'BILLING.SUBSCRIPTION.SUSPENDED'         => Subscription::STATUS_SUSPENDED,
			'BILLING.SUBSCRIPTION.CANCELLED'         => Subscription::STATUS_CANCELLED,
			'BILLING.SUBSCRIPTION.EXPIRED'           => Subscription::STATUS_EXPIRED,
		);

		return isset( $map[ $event_type ] )
			? $map[ $event_type ]
			: '';
	}
}