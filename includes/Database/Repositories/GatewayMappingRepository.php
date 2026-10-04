<?php
/**
 * Gateway mapping repository.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Database\Repositories;

use DropKeyWP\Domain\GatewayMapping;

defined( 'ABSPATH' ) || exit;

final class GatewayMappingRepository {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Database table.
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
		$this->table = $wpdb->prefix . 'dropkey_gateway_mappings';
	}

	/**
	 * Find mapping by ID.
	 *
	 * @param int $id Mapping ID.
	 * @return GatewayMapping|null
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

		return new GatewayMapping( $row );
	}

	/**
	 * Find mapping by internal entity.
	 *
	 * @param string $gateway     Gateway ID.
	 * @param string $entity_type Internal entity type.
	 * @param int    $entity_id   Internal entity ID.
	 * @return GatewayMapping|null
	 */
	public function find_by_entity( $gateway, $entity_type, $entity_id ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE gateway = %s
				AND entity_type = %s
				AND entity_id = %d
				LIMIT 1",
				$gateway,
				$entity_type,
				$entity_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return new GatewayMapping( $row );
	}

	/**
	 * Find mapping by external entity.
	 *
	 * @param string $gateway       Gateway ID.
	 * @param string $external_type External entity type.
	 * @param string $external_id   External entity ID.
	 * @return GatewayMapping|null
	 */
	public function find_by_external( $gateway, $external_type, $external_id ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE gateway = %s
				AND external_type = %s
				AND external_id = %s
				LIMIT 1",
				$gateway,
				$external_type,
				$external_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return new GatewayMapping( $row );
	}

	/**
	 * Create a gateway mapping.
	 *
	 * @param array<string,mixed> $data Mapping data.
	 * @return GatewayMapping|\WP_Error
	 */
	public function create( array $data ) {
		$created_at = current_time( 'mysql', true );
		$updated_at = $created_at;

		$metadata = isset( $data['metadata'] )
			? $data['metadata']
			: '';

		if ( is_array( $metadata ) ) {
			$metadata = wp_json_encode( $metadata );
		}

		if ( false === $metadata ) {
			$metadata = '';
		}

		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'gateway'       => isset( $data['gateway'] ) ? sanitize_key( $data['gateway'] ) : '',
				'entity_type'   => isset( $data['entity_type'] ) ? sanitize_key( $data['entity_type'] ) : '',
				'entity_id'     => isset( $data['entity_id'] ) ? absint( $data['entity_id'] ) : 0,
				'external_type' => isset( $data['external_type'] ) ? sanitize_key( $data['external_type'] ) : '',
				'external_id'   => isset( $data['external_id'] ) ? sanitize_text_field( $data['external_id'] ) : '',
				'metadata'      => $metadata,
				'created_at'    => $created_at,
				'updated_at'    => $updated_at,
			),
			array(
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
				'dropkey_gateway_mapping_create_failed',
				__( 'The gateway mapping could not be created.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return $this->find( (int) $this->wpdb->insert_id );
	}
}