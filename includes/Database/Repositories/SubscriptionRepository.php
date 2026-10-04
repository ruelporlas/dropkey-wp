<?php
/**
 * Subscription repository.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Database\Repositories;

use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

final class SubscriptionRepository {

	private $wpdb;

	private $table;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'dropkey_subscriptions';
	}

	public function find( $id ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return new Subscription( $row );
	}

	public function find_by_gateway_subscription_id(
		$gateway,
		$gateway_subscription_id
	) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE gateway = %s
				AND gateway_subscription_id = %s
				LIMIT 1",
				$gateway,
				$gateway_subscription_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return new Subscription( $row );
	}

	public function all_by_customer( $customer_id ) {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE customer_id = %d
				ORDER BY id DESC",
				$customer_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$subscriptions = array();

		foreach ( $rows as $row ) {
			$subscriptions[] = new Subscription( $row );
		}

		return $subscriptions;
	}

	public function create( array $data ) {
		$created_at = current_time( 'mysql', true );

		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'customer_id'             => $data['customer_id'],
				'product_id'              => $data['product_id'],
				'plan_id'                 => $data['plan_id'],
				'gateway'                 => $data['gateway'],
				'gateway_subscription_id' => $data['gateway_subscription_id'],
				'status'                  => $data['status'],
				'current_period_start'    => $data['current_period_start'],
				'current_period_end'      => $data['current_period_end'],
				'cancel_at_period_end'    => $data['cancel_at_period_end'],
				'cancelled_at'            => $data['cancelled_at'],
				'past_due_at'             => $data['past_due_at'],
				'ended_at'                => $data['ended_at'],
				'created_at'              => $created_at,
				'updated_at'              => $created_at,
			),
			array(
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'dropkey_subscription_create_failed',
				__( 'The subscription could not be created.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return $this->find( (int) $this->wpdb->insert_id );
	}

	/**
	 * Update the current billing period.
	 *
	 * @param int    $id           Subscription ID.
	 * @param string $period_start UTC datetime.
	 * @param string $period_end   UTC datetime.
	 * @return true|\WP_Error
	 */
	public function update_period( $id, $period_start, $period_end ) {
		$updated = $this->wpdb->update(
			$this->table,
			array(
				'current_period_start' => $period_start,
				'current_period_end'   => $period_end,
				'updated_at'           => current_time( 'mysql', true ),
			),
			array(
				'id' => absint( $id ),
			),
			array(
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
				'dropkey_subscription_period_update_failed',
				__(
					'The subscription billing period could not be updated.',
					'dropkey-wp'
				),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return true;
	}

	public function update_status( $id, $status ) {
		$updated_at = current_time( 'mysql', true );

		$updated = $this->wpdb->update(
			$this->table,
			array(
				'status'     => $status,
				'updated_at' => $updated_at,
			),
			array(
				'id' => absint( $id ),
			),
			array(
				'%s',
				'%s',
			),
			array(
				'%d',
			)
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'dropkey_subscription_update_failed',
				__( 'The subscription could not be updated.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return true;
	}

	public function mark_past_due( $id, $past_due_at ) {
		$updated = $this->wpdb->update(
			$this->table,
			array(
				'past_due_at' => $past_due_at,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array(
				'id' => absint( $id ),
			),
			array(
				'%s',
				'%s',
			),
			array(
				'%d',
			)
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'dropkey_subscription_past_due_update_failed',
				__( 'The subscription past-due state could not be recorded.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return true;
	}

	public function clear_past_due( $id ) {
		$updated = $this->wpdb->update(
			$this->table,
			array(
				'past_due_at' => null,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array(
				'id' => absint( $id ),
			),
			array(
				'%s',
				'%s',
			),
			array(
				'%d',
			)
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'dropkey_subscription_past_due_clear_failed',
				__( 'The subscription payment state could not be cleared.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return true;
	}

	public function find_past_due_expired( $grace_period_seconds ) {
		$grace_period_seconds = absint( $grace_period_seconds );

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE status = %s
				AND past_due_at IS NOT NULL
				AND past_due_at <> '0000-00-00 00:00:00'
				AND past_due_at <= UTC_TIMESTAMP() - INTERVAL %d SECOND
				ORDER BY id ASC",
				Subscription::STATUS_PAST_DUE,
				$grace_period_seconds
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$subscriptions = array();

		foreach ( $rows as $row ) {
			$subscriptions[] = new Subscription( $row );
		}

		return $subscriptions;
	}
}

