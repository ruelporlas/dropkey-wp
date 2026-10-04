<?php
/**
 * Payment gateway contract.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Gateways\Contracts;

defined( 'ABSPATH' ) || exit;

interface PaymentGatewayInterface {

	/**
	 * Get the gateway identifier.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Get the gateway display name.
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Determine whether the gateway is configured and available.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Create a subscription checkout.
	 *
	 * The gateway receives the DropKey product, plan, customer,
	 * and checkout context and is responsible for translating
	 * those records into its provider-specific subscription flow.
	 *
	 * Provider resources such as products and billing plans should
	 * be created or resolved automatically by the gateway when
	 * required. They must not require manual administrator mapping.
	 *
	 * @param array $context Checkout context.
	 * @return array|\WP_Error
	 */
	public function create_subscription_checkout( array $context );

	/**
	 * Retrieve a remote subscription.
	 *
	 * @param string $gateway_subscription_id Remote subscription ID.
	 * @return array|\WP_Error
	 */
	public function get_subscription( $gateway_subscription_id );

	/**
	 * Cancel a remote subscription.
	 *
	 * @param string $gateway_subscription_id Remote subscription ID.
	 * @param string $reason                  Optional cancellation reason.
	 * @return true|\WP_Error
	 */
	public function cancel_subscription( $gateway_subscription_id, $reason = '' );

	/**
	 * Process a gateway webhook request.
	 *
	 * The gateway implementation is responsible for validating
	 * the request according to the gateway's webhook requirements
	 * and returning a normalized event representation.
	 *
	 * @param string $payload Raw request body.
	 * @param array  $headers Request headers.
	 * @return array|\WP_Error
	 */
	public function process_webhook( $payload, array $headers );
}