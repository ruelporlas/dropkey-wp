<?php
/**
 * Plan repository.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Database\Repositories;

use DropKeyWP\Domain\Plan;

defined( 'ABSPATH' ) || exit;

final class PlanRepository {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Plans table name.
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
		$this->table = $wpdb->prefix . 'dropkey_plans';
	}

	/**
	 * Find a plan by ID.
	 *
	 * @param int $id Plan ID.
	 * @return Plan|null
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

		return new Plan( $row );
	}

	/**
	 * Find a plan by product and slug.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $slug       Plan slug.
	 * @return Plan|null
	 */
	public function find_by_product_and_slug( $product_id, $slug ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE product_id = %d AND slug = %s LIMIT 1",
				$product_id,
				$slug
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return new Plan( $row );
	}

	/**
	 * Get all plans for a product.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $status     Optional status filter.
	 * @return Plan[]
	 */
	public function all_by_product( $product_id, $status = '' ) {
		if ( '' !== $status ) {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE product_id = %d AND status = %s ORDER BY id DESC",
					$product_id,
					$status
				),
				ARRAY_A
			);
		} else {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE product_id = %d ORDER BY id DESC",
					$product_id
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$plans = array();

		foreach ( $rows as $row ) {
			$plans[] = new Plan( $row );
		}

		return $plans;
	}

	/**
	 * Create a plan.
	 *
	 * @param array<string,mixed> $data Plan data.
	 * @return Plan|\WP_Error
	 */
	public function create( array $data ) {
		$created_at = current_time( 'mysql', true );

		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'product_id'             => $data['product_id'],
				'name'                   => $data['name'],
				'slug'                   => $data['slug'],
				'price'                  => $data['price'],
				'currency'               => $data['currency'],
				'billing_interval'       => $data['billing_interval'],
				'billing_interval_count' => $data['billing_interval_count'],
				'activation_limit'       => $data['activation_limit'],
				'status'                 => $data['status'],
				'created_at'             => $created_at,
				'updated_at'             => $created_at,
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'dropkey_plan_create_failed',
				__( 'The plan could not be created.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return $this->find( (int) $this->wpdb->insert_id );
	}
}