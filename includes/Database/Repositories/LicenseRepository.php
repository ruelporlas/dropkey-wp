<?php
/**
 * License repository.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Database\Repositories;

use DropKeyWP\Domain\License;

defined( 'ABSPATH' ) || exit;

final class LicenseRepository {

	private $wpdb;

	private $table;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'dropkey_licenses';
	}

	/**
	 * Find a license by ID.
	 *
	 * @param int $id License ID.
	 * @return License|null
	 */
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

		return new License( $row );
	}

	/**
	 * Find a license by license key.
	 *
	 * @param string $license_key License key.
	 * @return License|null
	 */
	public function find_by_key( $license_key ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE license_key = %s LIMIT 1",
				$license_key
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return new License( $row );
	}

	/**
	 * Find the license associated with a subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return License|null
	 */
	public function find_by_subscription_id( $subscription_id ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE subscription_id = %d LIMIT 1",
				$subscription_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return new License( $row );
	}

	/**
	 * Get licenses belonging to a customer.
	 *
	 * @param int $customer_id Customer ID.
	 * @return License[]
	 */
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

		$licenses = array();

		foreach ( $rows as $row ) {
			$licenses[] = new License( $row );
		}

		return $licenses;
	}

	/**
	 * Create a license.
	 *
	 * @param array<string,mixed> $data License data.
	 * @return License|\WP_Error
	 */
	public function create( array $data ) {
		$created_at = current_time( 'mysql', true );

		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'customer_id'      => $data['customer_id'],
				'product_id'       => $data['product_id'],
				'subscription_id'  => $data['subscription_id'],
				'license_key'      => $data['license_key'],
				'status'           => $data['status'],
				'activation_limit' => $data['activation_limit'],
				'expires_at'       => $data['expires_at'],
				'created_at'       => $created_at,
				'updated_at'       => $created_at,
			),
			array(
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'dropkey_license_create_failed',
				__( 'The license could not be created.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return $this->find( (int) $this->wpdb->insert_id );
	}

	/**
	 * Update a license status.
	 *
	 * @param int    $license_id License ID.
	 * @param string $status     New license status.
	 * @return License|\WP_Error
	 */
	public function update_status( $license_id, $status ) {
		$license_id = absint( $license_id );
		$status     = (string) $status;

		if ( $license_id <= 0 ) {
			return new \WP_Error(
				'dropkey_license_id_required',
				__( 'A valid license ID is required.', 'dropkey-wp' )
			);
		}

		if ( ! in_array(
			$status,
			array(
				License::STATUS_ACTIVE,
				License::STATUS_SUSPENDED,
				License::STATUS_EXPIRED,
				License::STATUS_REVOKED,
			),
			true
		) ) {
			return new \WP_Error(
				'dropkey_license_status_invalid',
				__( 'License status is invalid.', 'dropkey-wp' )
			);
		}

		$updated_at = current_time( 'mysql', true );

		$updated = $this->wpdb->update(
			$this->table,
			array(
				'status'     => $status,
				'updated_at' => $updated_at,
			),
			array(
				'id' => $license_id,
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
				'dropkey_license_update_failed',
				__( 'The license could not be updated.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return $this->find( $license_id );
	}

	/**
	 * Update a license status and expiry.
	 *
	 * This is used when a subscription entitlement changes and both
	 * the license lifecycle state and paid-through date must remain
	 * synchronized with the subscription.
	 *
	 * @param int    $license_id License ID.
	 * @param string $status     New license status.
	 * @param string $expires_at License expiry datetime.
	 * @return License|\WP_Error
	 */
	public function update_entitlement(
		$license_id,
		$status,
		$expires_at
	) {
		$license_id = absint( $license_id );
		$status     = (string) $status;
		$expires_at = (string) $expires_at;

		if ( $license_id <= 0 ) {
			return new \WP_Error(
				'dropkey_license_id_required',
				__( 'A valid license ID is required.', 'dropkey-wp' )
			);
		}

		if ( ! in_array(
			$status,
			array(
				License::STATUS_ACTIVE,
				License::STATUS_SUSPENDED,
				License::STATUS_EXPIRED,
				License::STATUS_REVOKED,
			),
			true
		) ) {
			return new \WP_Error(
				'dropkey_license_status_invalid',
				__( 'License status is invalid.', 'dropkey-wp' )
			);
		}

		if ( '' === $expires_at ) {
			return new \WP_Error(
				'dropkey_license_expiry_required',
				__( 'A license expiry date is required.', 'dropkey-wp' )
			);
		}

		$updated_at = current_time( 'mysql', true );

		$updated = $this->wpdb->update(
			$this->table,
			array(
				'status'     => $status,
				'expires_at' => $expires_at,
				'updated_at' => $updated_at,
			),
			array(
				'id' => $license_id,
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
				'dropkey_license_entitlement_update_failed',
				__(
					'The license entitlement could not be updated.',
					'dropkey-wp'
				),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return $this->find( $license_id );
	}
}