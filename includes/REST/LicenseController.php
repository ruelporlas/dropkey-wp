<?php
/**
 * DropKey WP license REST controller.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\REST;

use DropKeyWP\Application\ActivateLicense;
use DropKeyWP\Application\AuthenticateActivation;
use DropKeyWP\Application\DeactivateLicense;
use DropKeyWP\Application\ValidateLicense;
use DropKeyWP\Database\Repositories\ActivationRepository;
use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Database\Repositories\ProductRepository;

defined( 'ABSPATH' ) || exit;

final class LicenseController {

	private const NAMESPACE = 'dropkey-wp/v1';

	private $licenses;

	private $activations;

	private $products;

	private $authenticate;

	/**
	 * Constructor.
	 *
	 * @param LicenseRepository    $licenses    License repository.
	 * @param ActivationRepository $activations Activation repository.
	 */
	public function __construct(
		LicenseRepository $licenses,
		ActivationRepository $activations
	) {
		$this->licenses    = $licenses;
		$this->activations = $activations;

		$wpdb = $this->get_wpdb();

		$this->products = new ProductRepository( $wpdb );

		$this->authenticate = new AuthenticateActivation(
			$this->activations
		);
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/license/activate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'activate' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'license_key' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'product' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'site_url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/license/deactivate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deactivate' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'api_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/license/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'api_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'product' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Activate a license.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function activate( \WP_REST_Request $request ) {
		$license_key  = $request->get_param( 'license_key' );
		$product_slug = $request->get_param( 'product' );
		$site_url     = $request->get_param( 'site_url' );

		if ( '' === $license_key ) {
			return new \WP_Error(
				'dropkey_license_key_required',
				__( 'A license key is required.', 'dropkey-wp' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $product_slug ) {
			return new \WP_Error(
				'dropkey_product_required',
				__( 'A product is required.', 'dropkey-wp' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $site_url ) {
			return new \WP_Error(
				'dropkey_site_url_required',
				__( 'A valid site URL is required.', 'dropkey-wp' ),
				array( 'status' => 400 )
			);
		}

		$license = $this->licenses->find_by_key( $license_key );

		if ( ! $license ) {
			return new \WP_Error(
				'dropkey_license_not_found',
				__( 'The license does not exist.', 'dropkey-wp' ),
				array( 'status' => 404 )
			);
		}

		$product = $this->products->find_by_slug( $product_slug );

		if ( ! $product ) {
			return new \WP_Error(
				'dropkey_product_not_found',
				__( 'The product does not exist.', 'dropkey-wp' ),
				array( 'status' => 404 )
			);
		}

		if ( $license->get_product_id() !== $product->get_id() ) {
			return new \WP_Error(
				'dropkey_product_mismatch',
				__( 'The license does not belong to this product.', 'dropkey-wp' ),
				array( 'status' => 403 )
			);
		}

		$service = new ActivateLicense(
			$this->licenses,
			$this->activations
		);

		$result = $service->execute(
			$license->get_id(),
			$site_url
		);

		if ( is_wp_error( $result ) ) {
			$this->set_error_status( $result );

			return $result;
		}

		$activation = $result['activation'];

		return new \WP_REST_Response(
			array(
				'success' => true,
				'activation' => array(
					'id'       => $activation->get_id(),
					'site_url' => $activation->get_site_url(),
					'status'   => $activation->get_status(),
				),
				'api_token' => $result['api_token'],
				'new_token' => $result['new_token'],
			),
			200
		);
	}

	/**
	 * Deactivate an authenticated activation.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function deactivate( \WP_REST_Request $request ) {
		$api_token = $request->get_param( 'api_token' );

		if ( '' === $api_token ) {
			return new \WP_Error(
				'dropkey_api_token_required',
				__( 'An API token is required.', 'dropkey-wp' ),
				array( 'status' => 400 )
			);
		}

		$activation = $this->authenticate->execute( $api_token );

		if ( is_wp_error( $activation ) ) {
			$this->set_error_status( $activation );

			return $activation;
		}

		$service = new DeactivateLicense(
			$this->licenses,
			$this->activations
		);

		$result = $service->execute(
			$activation->get_id()
		);

		if ( is_wp_error( $result ) ) {
			$this->set_error_status( $result );

			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'activation' => array(
					'id'       => $result->get_id(),
					'site_url' => $result->get_site_url(),
					'status'   => $result->get_status(),
				),
			),
			200
		);
	}

	/**
	 * Validate an authenticated activation.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function validate( \WP_REST_Request $request ) {
		$api_token    = $request->get_param( 'api_token' );
		$product_slug = $request->get_param( 'product' );

		if ( '' === $api_token ) {
			return new \WP_Error(
				'dropkey_api_token_required',
				__( 'An API token is required.', 'dropkey-wp' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $product_slug ) {
			return new \WP_Error(
				'dropkey_validation_product_required',
				__( 'A product is required.', 'dropkey-wp' ),
				array( 'status' => 400 )
			);
		}

		$service = new ValidateLicense(
			$this->licenses,
			$this->activations,
			$this->products,
			$this->authenticate
		);

		$result = $service->execute(
			$api_token,
			$product_slug
		);

		if ( is_wp_error( $result ) ) {
			$this->set_error_status( $result );

			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'valid'   => $result['valid'],
				'license' => array(
					'id'     => $result['license_id'],
					'status' => $result['license_status'],
				),
				'product' => array(
					'id' => $result['product_id'],
				),
				'activation' => array(
					'id'     => $result['activation_id'],
					'status' => $result['activation_status'],
				),
				'expires_at'        => $result['expires_at'],
				'last_validated_at' => $result['last_validated_at'],
			),
			200
		);
	}

	/**
	 * Add an HTTP status to a known application error.
	 *
	 * Existing error data is preserved. If an application error does not
	 * explicitly define an HTTP status, it defaults to 500 rather than
	 * being returned as a misleading successful HTTP response.
	 *
	 * @param \WP_Error $error Application error.
	 * @return void
	 */
	private function set_error_status( \WP_Error $error ) {
		$code = $error->get_error_code();

		$statuses = array(
			'dropkey_license_key_required'         => 400,
			'dropkey_product_required'             => 400,
			'dropkey_site_url_required'            => 400,
			'dropkey_validation_product_required' => 400,
			'dropkey_api_token_required'           => 400,

			'dropkey_license_invalid'              => 400,
			'dropkey_activation_invalid_site_url' => 400,

			'dropkey_license_not_found'            => 404,
			'dropkey_product_not_found'            => 404,
			'dropkey_activation_not_found'         => 404,

			'dropkey_product_mismatch'             => 403,
			'dropkey_license_not_active'           => 403,
			'dropkey_license_expired'              => 403,
			'dropkey_activation_not_active'        => 403,

			'dropkey_api_token_invalid'            => 401,

			'dropkey_activation_limit_reached'     => 409,

			'dropkey_activation_locked'            => 409,
			'dropkey_activation_lock_failed'       => 409,
		);

		$existing_data = $error->get_error_data();

		/*
		 * Preserve an explicitly supplied HTTP status. Repository errors
		 * may also contain diagnostic data such as db_error, so the mere
		 * presence of error data must not prevent status assignment.
		 */
		if (
			is_array( $existing_data )
			&& isset( $existing_data['status'] )
			&& is_numeric( $existing_data['status'] )
		) {
			return;
		}

		$status = isset( $statuses[ $code ] )
			? $statuses[ $code ]
			: 500;

		$data = is_array( $existing_data )
			? $existing_data
			: array();

		$data['status'] = $status;

		$error->add_data(
			$data
		);
	}

	/**
	 * Get the WordPress database object.
	 *
	 * @return \wpdb
	 */
	private function get_wpdb() {
		global $wpdb;

		return $wpdb;
	}
}