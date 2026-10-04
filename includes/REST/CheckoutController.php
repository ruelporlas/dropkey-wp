<?php
/**
 * Checkout REST controller.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\REST;

use DropKeyWP\Application\CreateSubscriptionCheckout;

defined( 'ABSPATH' ) || exit;

final class CheckoutController {

	/**
	 * Subscription checkout service.
	 *
	 * @var CreateSubscriptionCheckout
	 */
	private $checkout;

	/**
	 * Constructor.
	 *
	 * @param CreateSubscriptionCheckout $checkout Checkout service.
	 */
	public function __construct( CreateSubscriptionCheckout $checkout ) {
		$this->checkout = $checkout;
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'dropkey-wp/v1',
			'/checkout/subscription',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_subscription_checkout' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'customer_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'product_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'plan_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'gateway' => array(
						/*
						 * Free plans do not use a payment gateway.
						 * The application layer decides whether the
						 * selected plan actually requires one.
						 */
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
					'return_url' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'cancel_url' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * Check whether the current user can use checkout.
	 *
	 * @return bool
	 */
	public function permission_callback() {
		return is_user_logged_in();
	}

	/**
	 * Create a subscription checkout.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_subscription_checkout( \WP_REST_Request $request ) {
		$customer_id     = absint( $request->get_param( 'customer_id' ) );
		$current_user_id = absint( get_current_user_id() );

		if ( $customer_id <= 0 || $current_user_id <= 0 ) {
			return new \WP_Error(
				'dropkey_checkout_invalid_customer',
				__( 'A valid customer is required.', 'dropkey-wp' ),
				array(
					'status'          => 400,
					'current_user_id' => $current_user_id,
					'customer_id'     => $customer_id,
				)
			);
		}

		global $wpdb;

		$customers = new \DropKeyWP\Database\Repositories\CustomerRepository(
			$wpdb
		);

		$customer = $customers->find( $customer_id );

		if ( ! $customer ) {
			return new \WP_Error(
				'dropkey_checkout_customer_not_found',
				__( 'The customer does not exist.', 'dropkey-wp' ),
				array(
					'status'          => 404,
					'current_user_id' => $current_user_id,
					'customer_id'     => $customer_id,
				)
			);
		}

		$customer_user_id = absint( $customer->get_user_id() );

		if ( $customer_user_id !== $current_user_id ) {
			return new \WP_Error(
				'dropkey_checkout_customer_forbidden',
				__(
					'You are not authorized to use this customer account.',
					'dropkey-wp'
				),
				array(
					'status'           => 403,
					'customer_id'      => $customer_id,
					'customer_user_id' => $customer_user_id,
					'current_user_id'  => $current_user_id,
				)
			);
		}

		$result = $this->checkout->execute(
			array(
				'customer_id' => $customer_id,
				'product_id'  => absint(
					$request->get_param( 'product_id' )
				),
				'plan_id'     => absint(
					$request->get_param( 'plan_id' )
				),
				'gateway'     => sanitize_key(
					$request->get_param( 'gateway' )
				),
				'return_url'  => esc_url_raw(
					$request->get_param( 'return_url' )
				),
				'cancel_url'  => esc_url_raw(
					$request->get_param( 'cancel_url' )
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}
}