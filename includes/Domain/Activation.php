<?php
/**
 * DropKey WP activation entity.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Domain;

defined( 'ABSPATH' ) || exit;

final class Activation {

	public const STATUS_ACTIVE      = 'active';
	public const STATUS_DEACTIVATED = 'deactivated';

	private $id;
	private $license_id;
	private $site_url;
	private $site_identifier;
	private $api_token_hash;
	private $status;
	private $activated_at;
	private $last_validated_at;
	private $deactivated_at;
	private $created_at;
	private $updated_at;

	/**
	 * Constructor.
	 *
	 * @param array $data Activation data.
	 */
	public function __construct( array $data ) {
		$this->id                 = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->license_id         = isset( $data['license_id'] ) ? (int) $data['license_id'] : 0;
		$this->site_url            = isset( $data['site_url'] ) ? (string) $data['site_url'] : '';
		$this->site_identifier     = isset( $data['site_identifier'] ) ? (string) $data['site_identifier'] : '';
		$this->api_token_hash      = isset( $data['api_token_hash'] ) ? (string) $data['api_token_hash'] : '';
		$this->status              = isset( $data['status'] ) ? (string) $data['status'] : '';
		$this->activated_at        = isset( $data['activated_at'] ) ? (string) $data['activated_at'] : '';
		$this->last_validated_at   = isset( $data['last_validated_at'] ) ? (string) $data['last_validated_at'] : '';
		$this->deactivated_at      = isset( $data['deactivated_at'] ) ? (string) $data['deactivated_at'] : '';
		$this->created_at          = isset( $data['created_at'] ) ? (string) $data['created_at'] : '';
		$this->updated_at          = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : '';
	}

	/**
	 * Validate the activation.
	 *
	 * @return true|\WP_Error
	 */
	public function validate() {
		if ( $this->license_id <= 0 ) {
			return new \WP_Error(
				'dropkey_activation_license_required',
				__( 'A valid license is required.', 'dropkey-wp' )
			);
		}

		if ( '' === $this->site_url ) {
			return new \WP_Error(
				'dropkey_activation_site_url_required',
				__( 'A site URL is required.', 'dropkey-wp' )
			);
		}

		if ( '' === $this->site_identifier ) {
			return new \WP_Error(
				'dropkey_activation_site_identifier_required',
				__( 'A site identifier is required.', 'dropkey-wp' )
			);
		}

		if ( ! self::is_valid_status( $this->status ) ) {
			return new \WP_Error(
				'dropkey_activation_invalid_status',
				__( 'The activation status is invalid.', 'dropkey-wp' )
			);
		}

		return true;
	}

	/**
	 * Check whether a status is valid.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_valid_status( $status ) {
		return in_array(
			$status,
			array(
				self::STATUS_ACTIVE,
				self::STATUS_DEACTIVATED,
			),
			true
		);
	}

	/**
	 * Check whether the activation is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return self::STATUS_ACTIVE === $this->status;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_license_id() {
		return $this->license_id;
	}

	public function get_site_url() {
		return $this->site_url;
	}

	public function get_site_identifier() {
		return $this->site_identifier;
	}

	public function get_api_token_hash() {
		return $this->api_token_hash;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_activated_at() {
		return $this->activated_at;
	}

	public function get_last_validated_at() {
		return $this->last_validated_at;
	}

	public function get_deactivated_at() {
		return $this->deactivated_at;
	}

	public function get_created_at() {
		return $this->created_at;
	}

	public function get_updated_at() {
		return $this->updated_at;
	}
}