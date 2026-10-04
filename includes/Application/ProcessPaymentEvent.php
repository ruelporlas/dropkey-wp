<?php
/**
 * Process payment gateway events.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\GatewayEventRepository;
use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\GatewayEvent;
use DropKeyWP\Domain\Subscription;
use DropKeyWP\Gateways\Contracts\PaymentGatewayInterface;
use DropKeyWP\Gateways\GatewayManager;

defined( 'ABSPATH' ) || exit;

final class ProcessPaymentEvent {

	private $events;

	private $subscriptions;

	private $licenses;

	private $gateways;

	private $change_status;

	private $create_license;

	private $synchronize_entitlement;

	public function __construct(
		GatewayEventRepository $events,
		SubscriptionRepository $subscriptions,
		LicenseRepository $licenses,
		GatewayManager $gateways = null
	) {
		$this->events        = $events;
		$this->subscriptions = $subscriptions;
		$this->licenses      = $licenses;
		$this->gateways      = $gateways
			? $gateways
			: new GatewayManager();

		$this->change_status = new ChangeSubscriptionStatus(
			$subscriptions
		);

		/*
		 * ProcessPaymentEvent intentionally keeps its existing four-argument
		 * constructor so existing Plugin bootstrap code remains compatible.
		 *
		 * CreateLicense needs the product and plan repositories, so construct
		 * those repositories from the current WordPress database connection.
		 */
		global $wpdb;

		$product_repository = new ProductRepository( $wpdb );
		$plan_repository    = new PlanRepository( $wpdb );

		$this->create_license = new CreateLicense(
			$licenses,
			$subscriptions,
			$product_repository,
			$plan_repository
		);

		$this->synchronize_entitlement =
			new SynchronizeSubscriptionEntitlement(
				$licenses
			);
	}

	/**
	 * Process a normalized gateway event.
	 *
	 * @param array $event Normalized gateway event.
	 * @return GatewayEvent|\WP_Error
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

				if (
					$record->get_payload_hash() !== $payload_hash
				) {
					return new \WP_Error(
						'dropkey_gateway_event_payload_mismatch',
						__(
							'The gateway event payload does not match the previously recorded event.',
							'dropkey-wp'
						)
					);
				}

				if (
					GatewayEvent::STATUS_PROCESSED ===
					$record->get_status()
				) {
					return $record;
				}

				if (
					GatewayEvent::STATUS_FAILED ===
					$record->get_status()
				) {
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

		$processing = $this->events->claim_for_processing(
			$record->get_id()
		);

		if ( is_wp_error( $processing ) ) {
			if (
				'dropkey_gateway_event_already_processing' ===
				$processing->get_error_code()
			) {
				/*
				 * Another request owns this event. The event is already
				 * durably recorded, so acknowledge the webhook instead
				 * of causing the provider to retry it unnecessarily.
				 */
				return $record;
			}

			return $processing;
		}

		$record = $this->events->find_by_gateway_event(
			$gateway,
			$event_id
		);

		if ( ! $record ) {
			return new \WP_Error(
				'dropkey_gateway_event_reload_failed',
				__(
					'The gateway event could not be reloaded after being claimed.',
					'dropkey-wp'
				)
			);
		}

		$decoded = json_decode(
			$record->get_payload(),
			true
		);

		if ( ! is_array( $decoded ) ) {
			$error = new \WP_Error(
				'dropkey_gateway_event_invalid_json',
				__(
					'The gateway event payload is not valid JSON.',
					'dropkey-wp'
				)
			);

			$this->fail_event( $record, $error );

			return $error;
		}

		$result = $this->process_subscription_event(
			$gateway,
			$record->get_event_type(),
			$decoded
		);

		if ( is_wp_error( $result ) ) {
			$this->fail_event(
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
	 * Mark an event as failed and notify integrations.
	 *
	 * @param GatewayEvent $record Event.
	 * @param \WP_Error    $error  Error.
	 * @return void
	 */
	private function fail_event(
		GatewayEvent $record,
		\WP_Error $error
	) {
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
	}

	/**
	 * Process a subscription-related event.
	 *
	 * @param string $gateway    Gateway ID.
	 * @param string $event_type Gateway event type.
	 * @param array  $payload    Decoded gateway payload.
	 * @return true|\WP_Error
	 */
	private function process_subscription_event(
		$gateway,
		$event_type,
		array $payload
	) {
		if ( '' === $this->map_event_to_status( $event_type ) ) {
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

		$subscription =
			$this->subscriptions->find_by_gateway_subscription_id(
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

		/*
		 * PayPal webhook delivery is at-least-once and events can arrive
		 * out of order. The event describes what happened, while the
		 * current subscription resource describes the provider's state now.
		 */
		$gateway_instance = $this->gateways->get(
			$gateway
		);

		if ( ! $gateway_instance instanceof PaymentGatewayInterface ) {
			return new \WP_Error(
				'dropkey_gateway_not_available',
				__(
					'The payment gateway required to reconcile this event is not available.',
					'dropkey-wp'
				)
			);
		}

		$remote_subscription = $gateway_instance->get_subscription(
			$gateway_subscription_id
		);

		if ( is_wp_error( $remote_subscription ) ) {
			return $remote_subscription;
		}

		if ( ! is_array( $remote_subscription ) ) {
			return new \WP_Error(
				'dropkey_gateway_subscription_invalid_response',
				__(
					'The payment gateway returned an invalid subscription response.',
					'dropkey-wp'
				)
			);
		}

		$status = $this->resolve_remote_status(
			$event_type,
			$remote_subscription
		);

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$period = $this->get_remote_subscription_period(
			$remote_subscription
		);

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
		}

		/*
		 * Change the subscription status before synchronizing the
		 * entitlement. The lifecycle action fired by ChangeSubscriptionStatus
		 * receives the newly persisted subscription state.
		 */
		if ( $subscription->get_status() !== $status ) {
			$result = $this->change_status->execute(
				$subscription->get_id(),
				$status
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			/*
			 * WordPress actions do not return callback errors. Therefore
			 * explicitly ensure an ACTIVE subscription has a license before
			 * the gateway event is marked as successfully processed.
			 *
			 * CreateLicense is idempotent, so this is safe even when the
			 * activation lifecycle hook already created the license.
			 */
			if ( Subscription::STATUS_ACTIVE === $status ) {
				$license = $this->create_license->execute(
					$subscription->get_id()
				);

				if ( is_wp_error( $license ) ) {
					return $license;
				}
			}

			$subscription = $this->subscriptions->find(
				$subscription->get_id()
			);

			if ( ! $subscription ) {
				return new \WP_Error(
					'dropkey_subscription_reload_failed',
					__(
						'The subscription could not be reloaded after its status was changed.',
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

			return true;
		}

		/*
		 * When the status does not change, there is no lifecycle action
		 * to synchronize the entitlement. This is the normal renewal path:
		 * the billing period changes while the subscription remains ACTIVE.
		 *
		 * Ensure the license exists before synchronizing the entitlement.
		 */
		if ( Subscription::STATUS_ACTIVE === $status ) {
			$license = $this->create_license->execute(
				$subscription->get_id()
			);

			if ( is_wp_error( $license ) ) {
				return $license;
			}
		}

		$subscription = $this->subscriptions->find(
			$subscription->get_id()
		);

		if ( ! $subscription ) {
			return new \WP_Error(
				'dropkey_subscription_reload_failed',
				__(
					'The subscription could not be reloaded before entitlement synchronization.',
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

		return true;
	}

	/**
	 * Resolve local status from the provider's current subscription state.
	 *
	 * @param string $event_type          Event type.
	 * @param array  $remote_subscription Current provider resource.
	 * @return string|\WP_Error
	 */
	private function resolve_remote_status(
		$event_type,
		array $remote_subscription
	) {
		$remote_status = isset( $remote_subscription['status'] )
			? strtoupper(
				sanitize_key(
					$remote_subscription['status']
				)
			)
			: '';

		switch ( $remote_status ) {
			case 'ACTIVE':
				if (
					'BILLING.SUBSCRIPTION.PAYMENT.FAILED' ===
					$event_type
				) {
					$billing_info = isset(
						$remote_subscription['billing_info']
					) && is_array(
						$remote_subscription['billing_info']
					)
						? $remote_subscription['billing_info']
						: array();

					$failed_payments_count = isset(
						$billing_info['failed_payments_count']
					)
						? absint(
							$billing_info['failed_payments_count']
						)
						: 0;

					/*
					 * PayPal resets the failed-payment count after a
					 * successful payment. This makes the current provider
					 * state safer than comparing webhook timestamps.
					 */
					if ( $failed_payments_count > 0 ) {
						return Subscription::STATUS_PAST_DUE;
					}

					return Subscription::STATUS_ACTIVE;
				}

				return Subscription::STATUS_ACTIVE;

			case 'SUSPENDED':
				return Subscription::STATUS_SUSPENDED;

			case 'CANCELLED':
				return Subscription::STATUS_CANCELLED;

			case 'EXPIRED':
				return Subscription::STATUS_EXPIRED;

			case 'APPROVAL_PENDING':
			case 'APPROVED':
				return Subscription::STATUS_PENDING;

			default:
				return new \WP_Error(
					'dropkey_gateway_subscription_status_invalid',
					__(
						'The payment gateway returned an unsupported subscription status.',
						'dropkey-wp'
					),
					array(
						'gateway_status' => $remote_status,
					)
				);
		}
	}

	/**
	 * Get the current provider billing period.
	 *
	 * @param array $remote_subscription Current provider resource.
	 * @return array|false
	 */
	private function get_remote_subscription_period(
		array $remote_subscription
	) {
		$billing_info = isset(
			$remote_subscription['billing_info']
		) && is_array(
			$remote_subscription['billing_info']
		)
			? $remote_subscription['billing_info']
			: array();

		$period_start = '';

		if (
			isset( $billing_info['last_payment']['time'] )
		) {
			$period_start = (string) $billing_info['last_payment']['time'];
		}

		if ( '' === $period_start ) {
			$period_start = isset(
				$remote_subscription['start_time']
			)
				? (string) $remote_subscription['start_time']
				: '';
		}

		$period_end = isset(
			$billing_info['next_billing_time']
		)
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
	 * Convert a PayPal RFC3339 datetime to UTC MySQL datetime.
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
	 * Map a gateway event to a subscription status.
	 *
	 * @param string $event_type Event type.
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

