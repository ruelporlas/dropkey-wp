<?php
/**
 * License domain entity.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Domain;

defined( 'ABSPATH' ) || exit;

final class License {

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_SUSPENDED = 'suspended';
	public const STATUS_EXPIRED  = 'expired';
	public const STATUS_REVOKED  = 'revoked';

	private $id;
	private $customer_id;
	private $product_id;
	private $subscription_id;
	private $license_key;
	private $status;
	private $activation_limit;
	private $expires_at;
	private $created_at;
	private $updated_at;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data License data.
	 */
	public function __construct( array $data ) {
		$this->id               = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->customer_id      = isset( $data['customer_id'] ) ? (int) $data['customer_id'] : 0;
		$this->product_id       = isset( $data['product_id'] ) ? (int) $data['product_id'] : 0;
		$this->subscription_id  = isset( $data['subscription_id'] ) ? (int) $data['subscription_id'] : 0;
		$this->license_key      = isset( $data['license_key'] ) ? (string) $data['license_key'] : '';
		$this->status           = isset( $data['status'] ) ? (string) $data['status'] : self::STATUS_ACTIVE;
		$this->activation_limit = isset( $data['activation_limit'] ) ? (int) $data['activation_limit'] : 1;
		$this->expires_at       = isset( $data['expires_at'] ) ? (string) $data['expires_at'] : '';
		$this->created_at       = isset( $data['created_at'] ) ? (string) $data['created_at'] : '';
		$this->updated_at       = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : '';
	}

	/**
	 * Validate the license.
	 *
	 * @return true|\WP_Error
	 */
	public function validate() {
		$errors = new \WP_Error();

		if ( $this->customer_id <= 0 ) {
			$errors->add(
				'dropkey_license_customer_required',
				__( 'A valid customer is required.', 'dropkey-wp' )
			);
		}

		if ( $this->product_id <= 0 ) {
			$errors->add(
				'dropkey_license_product_required',
				__( 'A valid product is required.', 'dropkey-wp' )
			);
		}

		if ( $this->subscription_id <= 0 ) {
			$errors->add(
				'dropkey_license_subscription_required',
				__( 'A valid subscription is required.', 'dropkey-wp' )
			);
		}

		if ( '' === $this->license_key ) {
			$errors->add(
				'dropkey_license_key_required',
				__( 'A license key is required.', 'dropkey-wp' )
			);
		}

		if ( ! in_array(
			$this->status,
			array(
				self::STATUS_ACTIVE,
				self::STATUS_SUSPENDED,
				self::STATUS_EXPIRED,
				self::STATUS_REVOKED,
			),
			true
		) ) {
			$errors->add(
				'dropkey_license_status_invalid',
				__( 'License status is invalid.', 'dropkey-wp' )
			);
		}

		if ( $this->activation_limit < 1 ) {
			$errors->add(
				'dropkey_license_activation_limit_invalid',
				__( 'License activation limit must be at least 1.', 'dropkey-wp' )
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

	public function get_customer_id() {
		return $this->customer_id;
	}

	public function get_product_id() {
		return $this->product_id;
	}

	public function get_subscription_id() {
		return $this->subscription_id;
	}

	public function get_license_key() {
		return $this->license_key;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_activation_limit() {
		return $this->activation_limit;
	}

	public function get_expires_at() {
		return $this->expires_at;
	}

	public function get_created_at() {
		return $this->created_at;
	}

	public function get_updated_at() {
		return $this->updated_at;
	}
}