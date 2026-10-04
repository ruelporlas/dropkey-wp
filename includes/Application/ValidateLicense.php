<?php
/**
 * Validate license application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\ActivationRepository;
use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Domain\Activation;
use DropKeyWP\Domain\License;

defined( 'ABSPATH' ) || exit;

final class ValidateLicense {

	private $licenses;

	private $activations;

	private $products;

	private $authenticate;

	public function __construct(
		LicenseRepository $licenses,
		ActivationRepository $activations,
		ProductRepository $products,
		AuthenticateActivation $authenticate
	) {
		$this->licenses     = $licenses;
		$this->activations  = $activations;
		$this->products     = $products;
		$this->authenticate = $authenticate;
	}

	/**
	 * Validate an authenticated license activation.
	 *
	 * @param string $api_token API token issued for the activation.
	 * @param string $product_slug Product slug.
	 * @return array|\WP_Error
	 */
	public function execute( $api_token, $product_slug ) {
		$api_token    = trim( (string) $api_token );
		$product_slug = sanitize_title( $product_slug );

		if ( '' === $api_token ) {
			return new \WP_Error(
				'dropkey_api_token_required',
				__( 'An API token is required.', 'dropkey-wp' )
			);
		}

		if ( '' === $product_slug ) {
			return new \WP_Error(
				'dropkey_validation_product_required',
				__( 'A product is required.', 'dropkey-wp' )
			);
		}

		$activation = $this->authenticate->execute( $api_token );

		if ( is_wp_error( $activation ) ) {
			return $activation;
		}

		$license = $this->licenses->find(
			$activation->get_license_id()
		);

		if ( ! $license ) {
			return new \WP_Error(
				'dropkey_license_not_found',
				__( 'The license associated with this activation does not exist.', 'dropkey-wp' )
			);
		}

		$product = $this->products->find_by_slug( $product_slug );

		if ( ! $product ) {
			return new \WP_Error(
				'dropkey_product_not_found',
				__( 'The product does not exist.', 'dropkey-wp' )
			);
		}

		if ( $license->get_product_id() !== $product->get_id() ) {
			return new \WP_Error(
				'dropkey_product_mismatch',
				__( 'The license does not belong to this product.', 'dropkey-wp' )
			);
		}

		if ( License::STATUS_ACTIVE !== $license->get_status() ) {
			return new \WP_Error(
				'dropkey_license_not_active',
				__( 'The license is not active.', 'dropkey-wp' )
			);
		}

		if ( $this->has_expired( $license->get_expires_at() ) ) {
			return new \WP_Error(
				'dropkey_license_expired',
				__( 'The license has expired.', 'dropkey-wp' )
			);
		}

		$validated_activation = $this->activations->touch_validation(
			$activation->get_id()
		);

		if ( is_wp_error( $validated_activation ) ) {
			return $validated_activation;
		}

		return array(
			'valid'             => true,
			'license_id'        => $license->get_id(),
			'product_id'        => $license->get_product_id(),
			'activation_id'     => $validated_activation->get_id(),
			'license_status'    => $license->get_status(),
			'activation_status' => $validated_activation->get_status(),
			'expires_at'        => $license->get_expires_at(),
			'last_validated_at' => $validated_activation->get_last_validated_at(),
		);
	}

	/**
	 * Determine whether a license expiration date has passed.
	 *
	 * @param string $expires_at Expiration timestamp.
	 * @return bool
	 */
	private function has_expired( $expires_at ) {
		if ( '' === $expires_at ) {
			return false;
		}

		$expires_timestamp = strtotime( $expires_at );

		if ( false === $expires_timestamp ) {
			return false;
		}

		return $expires_timestamp < current_time( 'timestamp', true );
	}
}