<?php
/**
 * Customer domain entity.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Domain;

defined( 'ABSPATH' ) || exit;

final class Customer {

	/**
	 * Active customer status.
	 *
	 * @var string
	 */
	public const STATUS_ACTIVE = 'active';

	/**
	 * Inactive customer status.
	 *
	 * @var string
	 */
	public const STATUS_INACTIVE = 'inactive';

	/**
	 * Customer ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * WordPress user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Customer email.
	 *
	 * @var string
	 */
	private $email;

	/**
	 * First name.
	 *
	 * @var string
	 */
	private $first_name;

	/**
	 * Last name.
	 *
	 * @var string
	 */
	private $last_name;

	/**
	 * Customer status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Created timestamp.
	 *
	 * @var string
	 */
	private $created_at;

	/**
	 * Updated timestamp.
	 *
	 * @var string
	 */
	private $updated_at;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Customer data.
	 */
	public function __construct( array $data ) {
		$this->id         = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->user_id    = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		$this->email      = isset( $data['email'] ) ? (string) $data['email'] : '';
		$this->first_name = isset( $data['first_name'] ) ? (string) $data['first_name'] : '';
		$this->last_name  = isset( $data['last_name'] ) ? (string) $data['last_name'] : '';
		$this->status     = isset( $data['status'] )
			? (string) $data['status']
			: self::STATUS_ACTIVE;
		$this->created_at = isset( $data['created_at'] ) ? (string) $data['created_at'] : '';
		$this->updated_at = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : '';
	}

	/**
	 * Validate the customer.
	 *
	 * @return true|\WP_Error
	 */
	public function validate() {
		$errors = new \WP_Error();

		if ( $this->user_id <= 0 ) {
			$errors->add(
				'dropkey_customer_user_required',
				__( 'A valid WordPress user is required.', 'dropkey-wp' )
			);
		}

		if ( '' === $this->email || ! is_email( $this->email ) ) {
			$errors->add(
				'dropkey_customer_email_invalid',
				__( 'A valid customer email address is required.', 'dropkey-wp' )
			);
		}

		if ( ! in_array(
			$this->status,
			array(
				self::STATUS_ACTIVE,
				self::STATUS_INACTIVE,
			),
			true
		) ) {
			$errors->add(
				'dropkey_customer_status_invalid',
				__( 'Customer status is invalid.', 'dropkey-wp' )
			);
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return true;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_user_id() {
		return $this->user_id;
	}

	public function get_email() {
		return $this->email;
	}

	public function get_first_name() {
		return $this->first_name;
	}

	public function get_last_name() {
		return $this->last_name;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_created_at() {
		return $this->created_at;
	}

	public function get_updated_at() {
		return $this->updated_at;
	}
}