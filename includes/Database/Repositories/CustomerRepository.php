<?php
/**
 * Customer repository.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Database\Repositories;

use DropKeyWP\Domain\Customer;

defined( 'ABSPATH' ) || exit;

final class CustomerRepository {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Customers table name.
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
		$this->table = $wpdb->prefix . 'dropkey_customers';
	}

	/**
	 * Find a customer by ID.
	 *
	 * @param int $id Customer ID.
	 * @return Customer|null
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

		return new Customer( $row );
	}

	/**
	 * Get all customers.
	 *
	 * @param string $status Optional customer status.
	 * @return Customer[]
	 */
	public function all( $status = '' ) {
		if ( '' !== $status ) {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table}
					WHERE status = %s
					ORDER BY id DESC",
					$status
				),
				ARRAY_A
			);
		} else {
			$rows = $this->wpdb->get_results(
				"SELECT * FROM {$this->table}
				ORDER BY id DESC",
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$customers = array();

		foreach ( $rows as $row ) {
			$customers[] = new Customer( $row );
		}

		return $customers;
	}

	/**
	 * Find a customer by WordPress user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return Customer|null
	 */
	public function find_by_user_id( $user_id ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE user_id = %d LIMIT 1",
				$user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return new Customer( $row );
	}

	/**
	 * Find a customer by email address.
	 *
	 * @param string $email Customer email.
	 * @return Customer|null
	 */
	public function find_by_email( $email ) {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE email = %s LIMIT 1",
				$email
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return new Customer( $row );
	}

	/**
	 * Create a customer.
	 *
	 * @param array<string,mixed> $data Customer data.
	 * @return Customer|\WP_Error
	 */
	public function create( array $data ) {
		$created_at = current_time( 'mysql', true );

		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'user_id'    => $data['user_id'],
				'email'      => $data['email'],
				'first_name' => $data['first_name'],
				'last_name'  => $data['last_name'],
				'status'     => $data['status'],
				'created_at' => $created_at,
				'updated_at' => $created_at,
			),
			array(
				'%d',
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
				'dropkey_customer_create_failed',
				__( 'The customer could not be created.', 'dropkey-wp' ),
				array(
					'db_error' => $this->wpdb->last_error,
				)
			);
		}

		return $this->find( (int) $this->wpdb->insert_id );
	}
}