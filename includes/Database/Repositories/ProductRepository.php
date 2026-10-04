<?php
/**
 * Product repository.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Database\Repositories;

use DropKeyWP\Domain\Product;

defined( 'ABSPATH' ) || exit;

final class ProductRepository {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Products table name.
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
		$this->table = $wpdb->prefix . 'dropkey_products';
	}

	/**
	 * Find a product by ID.
	 *
	 * @param int $id Product ID.
	 * @return Product|null
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

		return new Product( $row );
	}

	/**
	 * Find a product by slug.
	 *
	 * @param string $slug Product slug.
	 * @return Product|null
	 */
	public function find_by_slug( $slug ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE slug = %s LIMIT 1",
				$slug
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return new Product( $row );
	}

	/**
	 * Get all products.
	 *
	 * @param string $status Optional status filter.
	 * @return Product[]
	 */
	public function all( $status = '' ) {
		if ( '' !== $status ) {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE status = %s ORDER BY id DESC",
					$status
				),
				ARRAY_A
			);
		} else {
			$rows = $this->wpdb->get_results(
				"SELECT * FROM {$this->table} ORDER BY id DESC",
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$products = array();

		foreach ( $rows as $row ) {
			$products[] = new Product( $row );
		}

		return $products;
	}

	/**
	 * Create a product.
	 *
	 * @param array<string,mixed> $data Product data.
	 * @return Product|\WP_Error
	 */
	public function create( array $data ) {
		$created_at = current_time( 'mysql', true );
		$updated_at = $created_at;

		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'name'        => $data['name'],
				'slug'        => $data['slug'],
				'type'        => $data['type'],
				'description' => $data['description'],
				'status'      => $data['status'],
				'created_at'  => $created_at,
				'updated_at'  => $updated_at,
			),
			array(
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
				'dropkey_product_create_failed',
				__( 'The product could not be created.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return $this->find( (int) $this->wpdb->insert_id );
	}

	/**
	 * Update a product.
	 *
	 * @param int                  $id Product ID.
	 * @param array<string,mixed> $data Product data.
	 * @return Product|\WP_Error
	 */
	public function update( $id, array $data ) {
		$updated_at = current_time( 'mysql', true );

		$updated = $this->wpdb->update(
			$this->table,
			array(
				'name'        => $data['name'],
				'slug'        => $data['slug'],
				'type'        => $data['type'],
				'description' => $data['description'],
				'status'      => $data['status'],
				'updated_at'  => $updated_at,
			),
			array(
				'id' => $id,
			),
			array(
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
				'dropkey_product_update_failed',
				__( 'The product could not be updated.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		$product = $this->find( $id );

		if ( ! $product ) {
			return new \WP_Error(
				'dropkey_product_not_found',
				__( 'The product could not be found after updating.', 'dropkey-wp' )
			);
		}

		return $product;
	}
}