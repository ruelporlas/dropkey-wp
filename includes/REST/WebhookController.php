<?php
/**
 * DropKey WP webhook REST controller.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\REST;

use DropKeyWP\Application\ProcessPaymentEvent;
use DropKeyWP\Gateways\GatewayManager;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class WebhookController {

	private $gateways;

	private $processor;

	public function __construct(
		GatewayManager $gateways,
		ProcessPaymentEvent $processor
	) {
		$this->gateways  = $gateways;
		$this->processor = $processor;
	}

	public function register_routes() {
		register_rest_route(
			'dropkey-wp/v1',
			'/webhooks/(?P<gateway>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'gateway' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $value ) {
							return '' !== sanitize_key( $value );
						},
					),
				),
			)
		);
	}

	public function permission_callback() {
		return true;
	}

	public function handle_webhook( WP_REST_Request $request ) {
		$this->write_webhook_debug(
			'WebhookController reached.',
			array(
				'method' => $request->get_method(),
				'route'  => $request->get_route(),
			)
		);

		$gateway_id = sanitize_key(
			$request->get_param( 'gateway' )
		);

		$this->write_webhook_debug(
			'Gateway identified.',
			array(
				'gateway' => $gateway_id,
			)
		);

		$gateway = $this->gateways->get( $gateway_id );

		if ( ! $gateway ) {
			$this->write_webhook_debug(
				'Gateway not found.',
				array(
					'gateway' => $gateway_id,
				)
			);

			return new \WP_Error(
				'dropkey_webhook_gateway_not_found',
				__( 'The requested payment gateway is not registered.', 'dropkey-wp' ),
				array(
					'status' => 404,
				)
			);
		}

		$payload = $request->get_body();

		$headers = array();

		foreach ( $request->get_headers() as $name => $values ) {
			$headers[ $name ] = is_array( $values )
				? reset( $values )
				: $values;
		}

		$this->write_webhook_debug(
			'Request data collected.',
			array(
				'payload_length' => strlen( $payload ),
				'header_names'   => array_keys( $headers ),
			)
		);

		$event = $gateway->process_webhook(
			$payload,
			$headers
		);

		if ( is_wp_error( $event ) ) {
			$this->write_webhook_debug(
				'Gateway process_webhook returned an error.',
				array(
					'error_code' => $event->get_error_code(),
					'message'    => $event->get_error_message(),
				)
			);

			return $event;
		}

		if ( ! is_array( $event ) ) {
			$this->write_webhook_debug(
				'Gateway returned an invalid event.'
			);

			return new \WP_Error(
				'dropkey_webhook_invalid_event',
				__( 'The payment gateway returned an invalid webhook event.', 'dropkey-wp' ),
				array(
					'status' => 400,
				)
			);
		}

		$event['gateway'] = $gateway_id;
		$event['payload'] = $payload;

		$this->write_webhook_debug(
			'Passing event to ProcessPaymentEvent.',
			array(
				'event_id'   => isset( $event['event_id'] )
					? $event['event_id']
					: '',
				'event_type' => isset( $event['event_type'] )
					? $event['event_type']
					: '',
			)
		);

		$result = $this->processor->execute( $event );

		if ( is_wp_error( $result ) ) {
			$this->write_webhook_debug(
				'ProcessPaymentEvent returned an error.',
				array(
					'error_code' => $result->get_error_code(),
					'message'    => $result->get_error_message(),
				)
			);

			return $result;
		}

		$this->write_webhook_debug(
			'Webhook processing completed.',
			array(
				'event_id' => $result->get_event_id(),
				'status'   => $result->get_status(),
			)
		);

		return new WP_REST_Response(
			array(
				'success'  => true,
				'event_id' => $result->get_event_id(),
				'status'   => $result->get_status(),
			),
			200
		);
	}

	/**
	 * Store temporary webhook diagnostics.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	private function write_webhook_debug(
		$message,
		array $context = array()
	) {
		$debug = get_option(
			'dropkey_wp_paypal_webhook_debug',
			array()
		);

		if ( ! is_array( $debug ) ) {
			$debug = array();
		}

		$debug[] = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'message' => sanitize_text_field( $message ),
			'context' => $context,
		);

		if ( count( $debug ) > 50 ) {
			$debug = array_slice(
				$debug,
				-50
			);
		}

		update_option(
			'dropkey_wp_paypal_webhook_debug',
			$debug,
			false
		);
	}
}