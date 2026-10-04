<?php
/**
 * Gateway event repository.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Database\Repositories;

use DropKeyWP\Domain\GatewayEvent;

defined( 'ABSPATH' ) || exit;

final class GatewayEventRepository {

	private const PROCESSING_TIMEOUT_SECONDS = 300;

	private $wpdb;

	private $table;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'dropkey_gateway_events';
	}

	/**
	 * Find an event by gateway and provider event ID.
	 *
	 * @param string $gateway  Gateway identifier.
	 * @param string $event_id Provider event ID.
	 * @return GatewayEvent|null
	 */
	public function find_by_gateway_event( $gateway, $event_id ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE gateway = %s
				AND event_id = %s
				LIMIT 1",
				$gateway,
				$event_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return new GatewayEvent( $row );
	}

	/**
	 * Create a gateway event.
	 *
	 * @param array $data Event data.
	 * @return GatewayEvent|\WP_Error
	 */
	public function create( array $data ) {
		$created_at = current_time( 'mysql', true );

		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'gateway'       => $data['gateway'],
				'event_id'      => $data['event_id'],
				'event_type'    => $data['event_type'],
				'status'        => $data['status'],
				'payload_hash'  => $data['payload_hash'],
				'payload'       => $data['payload'],
				'processed_at'  => isset( $data['processed_at'] )
					? $data['processed_at']
					: null,
				'error_message' => isset( $data['error_message'] )
					? $data['error_message']
					: null,
				'created_at'    => $created_at,
				'updated_at'    => $created_at,
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'dropkey_gateway_event_create_failed',
				__( 'The gateway event could not be recorded.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		$event = $this->find_by_gateway_event(
			$data['gateway'],
			$data['event_id']
		);

		if ( ! $event ) {
			return new \WP_Error(
				'dropkey_gateway_event_reload_failed',
				__(
					'The recorded gateway event could not be reloaded.',
					'dropkey-wp'
				)
			);
		}

		return $event;
	}

	/**
	 * Atomically claim an event for processing.
	 *
	 * The updated_at value written during the claim becomes the processing
	 * ownership token. A processing request may finalize the event only when
	 * that token still matches the row.
	 *
	 * A processing event can be reclaimed after its lease becomes stale.
	 *
	 * @param int $id Event ID.
	 * @return string|\WP_Error Processing ownership token.
	 */
	public function claim_for_processing( $id ) {
		$id = absint( $id );

		if ( $id <= 0 ) {
			return new \WP_Error(
				'dropkey_gateway_event_claim_invalid',
				__(
					'A valid gateway event ID is required.',
					'dropkey-wp'
				)
			);
		}

		$claimed_at = current_time( 'mysql', true );

		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table}
				SET status = %s,
					processed_at = NULL,
					error_message = NULL,
					updated_at = %s
				WHERE id = %d
				AND (
					status = %s
					OR (
						status = %s
						AND updated_at <= UTC_TIMESTAMP() - INTERVAL %d SECOND
					)
				)",
				GatewayEvent::STATUS_PROCESSING,
				$claimed_at,
				$id,
				GatewayEvent::STATUS_RECEIVED,
				GatewayEvent::STATUS_PROCESSING,
				self::PROCESSING_TIMEOUT_SECONDS
			)
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'dropkey_gateway_event_claim_failed',
				__(
					'The gateway event could not be claimed for processing.',
					'dropkey-wp'
				),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		if ( 1 !== (int) $updated ) {
			return new \WP_Error(
				'dropkey_gateway_event_already_processing',
				__(
					'The gateway event is already being processed or is no longer available for processing.',
					'dropkey-wp'
				)
			);
		}

		return $claimed_at;
	}

	/**
	 * Update event status.
	 *
	 * When a processing ownership token is supplied, the update is performed
	 * only if this request still owns the processing lease.
	 *
	 * @param int         $id                    Event ID.
	 * @param string      $status                Event status.
	 * @param string|null $error_message         Error message.
	 * @param string|null $processing_claimed_at Processing ownership token.
	 * @return true|\WP_Error
	 */
	public function update_status(
		$id,
		$status,
		$error_message = null,
		$processing_claimed_at = null
	) {
		$id = absint( $id );

		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);

		$formats = array(
			'%s',
			'%s',
		);

		if ( null !== $error_message ) {
			$data['error_message'] = $error_message;
			$formats[]             = '%s';
		}

		if ( null !== $processing_claimed_at ) {
			$sql = "UPDATE {$this->table}
				SET status = %s,
					updated_at = %s";

			$params = array(
				$status,
				$data['updated_at'],
			);

			if ( null !== $error_message ) {
				$sql      .= ', error_message = %s';
				$params[] = $error_message;
			}

			$sql .= "
				WHERE id = %d
				AND status = %s
				AND updated_at = %s";

			$params[] = $id;
			$params[] = GatewayEvent::STATUS_PROCESSING;
			$params[] = $processing_claimed_at;

			$updated = $this->wpdb->query(
				$this->wpdb->prepare(
					$sql,
					$params
				)
			);

			if ( false === $updated ) {
				return new \WP_Error(
					'dropkey_gateway_event_update_failed',
					__( 'The gateway event could not be updated.', 'dropkey-wp' ),
					array(
						'db_error' => $this->wpdb->last_error,
					)
				);
			}

			if ( 1 !== (int) $updated ) {
				return new \WP_Error(
					'dropkey_gateway_event_processing_ownership_lost',
					__(
						'The gateway event processing ownership is no longer held by this request.',
						'dropkey-wp'
					)
				);
			}

			return true;
		}

		$updated = $this->wpdb->update(
			$this->table,
			$data,
			array(
				'id' => $id,
			),
			$formats,
			array(
				'%d',
			)
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'dropkey_gateway_event_update_failed',
				__( 'The gateway event could not be updated.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return true;
	}

	/**
	 * Reset an event so it can be processed again.
	 *
	 * @param int $id Event ID.
	 * @return true|\WP_Error
	 */
	public function reset_for_retry( $id ) {
		$updated_at = current_time( 'mysql', true );

		$updated = $this->wpdb->update(
			$this->table,
			array(
				'status'        => GatewayEvent::STATUS_RECEIVED,
				'processed_at'  => null,
				'error_message' => null,
				'updated_at'    => $updated_at,
			),
			array(
				'id' => absint( $id ),
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
			),
			array(
				'%d',
			)
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'dropkey_gateway_event_retry_reset_failed',
				__(
					'The gateway event could not be reset for retry.',
					'dropkey-wp'
				),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return true;
	}

	/**
	 * Mark an event as processed.
	 *
	 * When a processing ownership token is supplied, the event is marked
	 * processed only if this request still owns the processing lease.
	 *
	 * @param int         $id                    Event ID.
	 * @param string|null $processing_claimed_at Processing ownership token.
	 * @return true|\WP_Error
	 */
	public function mark_processed( $id, $processing_claimed_at = null ) {
		$id         = absint( $id );
		$updated_at = current_time( 'mysql', true );

		if ( null !== $processing_claimed_at ) {
			$updated = $this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$this->table}
					SET status = %s,
						processed_at = %s,
						updated_at = %s,
						error_message = NULL
					WHERE id = %d
					AND status = %s
					AND updated_at = %s",
					GatewayEvent::STATUS_PROCESSED,
					$updated_at,
					$updated_at,
					$id,
					GatewayEvent::STATUS_PROCESSING,
					$processing_claimed_at
				)
			);

			if ( false === $updated ) {
				return new \WP_Error(
					'dropkey_gateway_event_processed_failed',
					__(
						'The gateway event could not be marked as processed.',
						'dropkey-wp'
					),
					array(
						'db_error' => $this->wpdb->last_error,
					)
				);
			}

			if ( 1 !== (int) $updated ) {
				return new \WP_Error(
					'dropkey_gateway_event_processing_ownership_lost',
					__(
						'The gateway event processing ownership is no longer held by this request.',
						'dropkey-wp'
					)
				);
			}

			return true;
		}

		$updated = $this->wpdb->update(
			$this->table,
			array(
				'status'        => GatewayEvent::STATUS_PROCESSED,
				'processed_at'  => $updated_at,
				'updated_at'    => $updated_at,
				'error_message' => null,
			),
			array(
				'id' => $id,
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
			),
			array(
				'%d',
			)
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'dropkey_gateway_event_processed_failed',
				__(
					'The gateway event could not be marked as processed.',
					'dropkey-wp'
				),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return true;
	}
}