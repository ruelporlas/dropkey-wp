<?php
/**
 * Subscription event repository.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Database\Repositories;

defined( 'ABSPATH' ) || exit;

final class SubscriptionEventRepository {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'dropkey_subscription_events';
	}

	/**
	 * Create a subscription event.
	 *
	 * @param array $data Event data.
	 * @return int|\WP_Error Event ID or error.
	 */
	public function create( array $data ) {
		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'subscription_id' => absint( $data['subscription_id'] ),
				'event_type'      => sanitize_key( $data['event_type'] ),
				'previous_status' => sanitize_key( $data['previous_status'] ),
				'new_status'      => sanitize_key( $data['new_status'] ),
				'actor_user_id'   => absint( $data['actor_user_id'] ),
				'created_at'      => current_time( 'mysql', true ),
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
			)
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'dropkey_subscription_event_create_failed',
				__(
					'The subscription event could not be recorded.',
					'dropkey-wp'
				),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return (int) $this->wpdb->insert_id;
	}
}