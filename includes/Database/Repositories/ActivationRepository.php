<?php
/**
 * Activation repository.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Database\Repositories;

use DropKeyWP\Domain\Activation;

defined( 'ABSPATH' ) || exit;

final class ActivationRepository {

	private $wpdb;

	private $table;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'dropkey_activations';
	}

	public function find( $id ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
				absint( $id )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return new Activation( $row );
	}

	public function find_by_license_id( $license_id ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE license_id = %d ORDER BY id DESC LIMIT 1",
				absint( $license_id )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return new Activation( $row );
	}

	public function find_by_license_and_site( $license_id, $site_identifier ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE license_id = %d AND site_identifier = %s LIMIT 1",
				absint( $license_id ),
				$site_identifier
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return new Activation( $row );
	}

	public function find_by_token_hash( $token_hash ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE api_token_hash = %s LIMIT 1",
				$token_hash
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return new Activation( $row );
	}

	public function count_active_by_license( $license_id ) {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE license_id = %d AND status = %s",
				absint( $license_id ),
				Activation::STATUS_ACTIVE
			)
		);
	}

	public function create( array $data ) {
		$now = current_time( 'mysql', true );

		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'license_id'        => absint( $data['license_id'] ),
				'site_url'          => $data['site_url'],
				'site_identifier'   => $data['site_identifier'],
				'api_token_hash'    => $data['api_token_hash'],
				'status'            => $data['status'],
				'activated_at'      => $data['activated_at'],
				'last_validated_at' => $data['last_validated_at'],
				'deactivated_at'    => $data['deactivated_at'],
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array(
				'%d',
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
				'dropkey_activation_create_failed',
				__( 'The activation could not be created.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return $this->find( $this->wpdb->insert_id );
	}

	public function reactivate( $id, $site_url, $api_token_hash ) {
		$now = current_time( 'mysql', true );

		$updated = $this->wpdb->update(
			$this->table,
			array(
				'site_url'          => $site_url,
				'api_token_hash'    => $api_token_hash,
				'status'            => Activation::STATUS_ACTIVE,
				'activated_at'      => $now,
				'last_validated_at' => $now,
				'deactivated_at'    => null,
				'updated_at'        => $now,
			),
			array(
				'id' => absint( $id ),
			),
			array(
				'%s',
				'%s',
				'%s',
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
				'dropkey_activation_update_failed',
				__( 'The activation could not be updated.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return $this->find( $id );
	}

	public function deactivate( $id ) {
		$now = current_time( 'mysql', true );

		$updated = $this->wpdb->update(
			$this->table,
			array(
				'status'         => Activation::STATUS_DEACTIVATED,
				'deactivated_at' => $now,
				'updated_at'     => $now,
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
				'dropkey_activation_update_failed',
				__( 'The activation could not be updated.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return $this->find( $id );
	}

	public function touch_validation( $id ) {
		$now = current_time( 'mysql', true );

		$updated = $this->wpdb->update(
			$this->table,
			array(
				'last_validated_at' => $now,
				'updated_at'        => $now,
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
				'dropkey_activation_validation_update_failed',
				__( 'The activation validation timestamp could not be updated.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return $this->find( $id );
	}
}