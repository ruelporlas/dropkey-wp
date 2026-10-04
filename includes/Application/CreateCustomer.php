<?php
/**
 * Create customer application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Domain\Customer;

defined( 'ABSPATH' ) || exit;

final class CreateCustomer {

	/**
	 * Customer repository.
	 *
	 * @var CustomerRepository
	 */
	private $customers;

	/**
	 * Constructor.
	 *
	 * @param CustomerRepository $customers Customer repository.
	 */
	public function __construct( CustomerRepository $customers ) {
		$this->customers = $customers;
	}

	/**
	 * Create a DropKey customer from a WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return Customer|\WP_Error
	 */
	public function execute( $user_id ) {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'dropkey_customer_user_required',
				__( 'A valid WordPress user is required.', 'dropkey-wp' )
			);
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return new \WP_Error(
				'dropkey_customer_user_not_found',
				__( 'The WordPress user could not be found.', 'dropkey-wp' )
			);
		}

		$existing_customer = $this->customers->find_by_user_id( $user_id );

		if ( $existing_customer ) {
			return $existing_customer;
		}

		$data = array(
			'user_id'    => $user->ID,
			'email'      => sanitize_email( $user->user_email ),
			'first_name' => sanitize_text_field( $user->first_name ),
			'last_name'  => sanitize_text_field( $user->last_name ),
			'status'     => Customer::STATUS_ACTIVE,
		);

		$data = apply_filters(
			'dropkey_wp_customer_data',
			$data,
			$user
		);

		$customer = new Customer( $data );

		$validation = $customer->validate();

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$created_customer = $this->customers->create(
			array(
				'user_id'    => $customer->get_user_id(),
				'email'      => $customer->get_email(),
				'first_name' => $customer->get_first_name(),
				'last_name'  => $customer->get_last_name(),
				'status'     => $customer->get_status(),
			)
		);

		if ( is_wp_error( $created_customer ) ) {
			return $created_customer;
		}

		do_action(
			'dropkey_wp_customer_created',
			$created_customer
		);

		return $created_customer;
	}
}