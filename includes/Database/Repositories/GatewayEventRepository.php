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

	private const LOCK_TIMEOUT_SECONDS = 0;

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
	 * A MySQL named lock is held by the current database session for the
	 * entire processing operation. If the PHP request terminates, MySQL
	 * automatically releases the lock and another request can retry.
	 *
	 * @param int $id Event ID.
	 * @return true|\WP_Error
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

		$lock_name = $this->get_processing_lock_name( $id );

		$lock_result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT GET_LOCK(%s, %d)',
				$lock_name,
				self::LOCK_TIMEOUT_SECONDS
			)
		);

		if ( null === $lock_result ) {
			return new \WP_Error(
				'dropkey_gateway_event_lock_failed',
				__(
					'The gateway event processing lock could not be acquired.',
					'dropkey-wp'
				),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		if ( 1 !== (int) $lock_result ) {
			return new \WP_Error(
				'dropkey_gateway_event_already_processing',
				__(
					'The gateway event is already being processed or is no longer available for processing.',
					'dropkey-wp'
				)
			);
		}

		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table}
				SET status = %s,
					processed_at = NULL,
					error_message = NULL,
					updated_at = %s
				WHERE id = %d
				AND status IN (%s, %s)",
				GatewayEvent::STATUS_PROCESSING,
				current_time( 'mysql', true ),
				$id,
				GatewayEvent::STATUS_RECEIVED,
				GatewayEvent::STATUS_PROCESSING
			)
		);

		if ( false === $updated ) {
			$this->release_processing_lock( $id );

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
			$this->release_processing_lock( $id );

			return new \WP_Error(
				'dropkey_gateway_event_already_processing',
				__(
					'The gateway event is already being processed or is no longer available for processing.',
					'dropkey-wp'
				)
			);
		}

		return true;
	}

	/**
	 * Update event status.
	 *
	 * This method does not automatically release the processing lock because
	 * status updates may also be used outside the processing workflow.
	 *
	 * @param int         $id            Event ID.
	 * @param string      $status        Event status.
	 * @param string|null $error_message Error message.
	 * @return true|\WP_Error
	 */
	public function update_status( $id, $status, $error_message = null ) {
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

		$updated = $this->wpdb->update(
			$this->table,
			$data,
			array(
				'id' => absint( $id ),
			),
			$formats,
			array(
				'%d',
			)
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'dropkey_gateway_event_update_failed',
				__(
					'The gateway event could not be updated.',
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
	 * Mark an event as processed and release its processing lock.
	 *
	 * @param int $id Event ID.
	 * @return true|\WP_Error
	 */
	public function mark_processed( $id ) {
		$id         = absint( $id );
		$updated_at = current_time( 'mysql', true );

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

		$release_result = $this->release_processing_lock( $id );

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

		if ( is_wp_error( $release_result ) ) {
			return $release_result;
		}

		return true;
	}

	/**
	 * Release the processing lock for an event.
	 *
	 * @param int $id Event ID.
	 * @return true|\WP_Error
	 */
	public function release_processing_lock( $id ) {
		$id = absint( $id );

		if ( $id <= 0 ) {
			return new \WP_Error(
				'dropkey_gateway_event_lock_release_invalid',
				__(
					'A valid gateway event ID is required.',
					'dropkey-wp'
				)
			);
		}

		$lock_name = $this->get_processing_lock_name( $id );

		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT RELEASE_LOCK(%s)',
				$lock_name
			)
		);

		if ( null === $result ) {
			return new \WP_Error(
				'dropkey_gateway_event_lock_release_failed',
				__(
					'The gateway event processing lock could not be released.',
					'dropkey-wp'
				),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		/*
		 * RELEASE_LOCK() returns 1 when this session released the lock,
		 * 0 when another session owns it, and NULL when the lock does not
		 * exist. A missing lock is safe because the processing session has
		 * already lost ownership.
		 */
		if ( 0 === (int) $result ) {
			return new \WP_Error(
				'dropkey_gateway_event_lock_not_owned',
				__(
					'The gateway event processing lock is no longer owned by this request.',
					'dropkey-wp'
				)
			);
		}

		return true;
	}

	/**
	 * Build the MySQL named lock used for one gateway event.
	 *
	 * @param int $id Event ID.
	 * @return string
	 */
	private function get_processing_lock_name( $id ) {
		$database_name = '';

		if ( isset( $this->wpdb->dbname ) ) {
			$database_name = (string) $this->wpdb->dbname;
		}

		if ( '' === $database_name && defined( 'DB_NAME' ) ) {
			$database_name = (string) DB_NAME;
		}

		$database_hash = substr(
			hash( 'sha256', $database_name ),
			0,
			12
		);

		return 'dropkey_wp_' . $database_hash . '_gateway_event_' . absint( $id );
	}
}