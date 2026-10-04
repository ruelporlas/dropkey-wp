<?php
/**
 * PayPal payment gateway.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Gateways\PayPal;

use DropKeyWP\Database\Repositories\GatewayMappingRepository;
use DropKeyWP\Gateways\Contracts\PaymentGatewayInterface;

defined( 'ABSPATH' ) || exit;

final class PayPalGateway implements PaymentGatewayInterface {

	private const SANDBOX_API_URL = 'https://api-m.sandbox.paypal.com';

	private const LIVE_API_URL = 'https://api-m.paypal.com';

	private const GATEWAY_ID = 'paypal';

	private const WEBHOOK_CERT_CACHE_TTL = DAY_IN_SECONDS;

	private const WEBHOOK_TIMESTAMP_TOLERANCE = 300;

	private $client_id;

	private $client_secret;

	private $webhook_id;

	private $sandbox;

	private $mappings;

	public function __construct(
		$client_id,
		$client_secret,
		$sandbox = true,
		GatewayMappingRepository $mappings = null,
		$webhook_id = ''
	) {
		$this->client_id     = trim( (string) $client_id );
		$this->client_secret = trim( (string) $client_secret );
		$this->sandbox       = (bool) $sandbox;
		$this->mappings      = $mappings;
		$this->webhook_id    = trim( (string) $webhook_id );
	}

	public function get_id() {
		return self::GATEWAY_ID;
	}

	public function get_name() {
		return __( 'PayPal', 'dropkey-wp' );
	}

	public function is_available() {
		return '' !== $this->client_id
			&& '' !== $this->client_secret;
	}

	public function test_connection() {
		$result = $this->get_access_token();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	public function create_subscription_checkout( array $context ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error(
				'dropkey_paypal_not_configured',
				__( 'PayPal is not configured.', 'dropkey-wp' )
			);
		}

		if ( ! $this->mappings instanceof GatewayMappingRepository ) {
			return new \WP_Error(
				'dropkey_paypal_mapping_repository_missing',
				__(
					'The PayPal gateway mapping repository is not available.',
					'dropkey-wp'
				)
			);
		}

		$product  = isset( $context['product'] ) ? $context['product'] : null;
		$plan     = isset( $context['plan'] ) ? $context['plan'] : null;
		$customer = isset( $context['customer'] ) ? $context['customer'] : null;

		if ( ! $product || ! $plan || ! $customer ) {
			return new \WP_Error(
				'dropkey_paypal_invalid_checkout_context',
				__( 'The PayPal checkout context is incomplete.', 'dropkey-wp' )
			);
		}

		$paypal_product_id = $this->resolve_product( $product );

		if ( is_wp_error( $paypal_product_id ) ) {
			return $paypal_product_id;
		}

		$paypal_plan_id = $this->resolve_plan(
			$plan,
			$paypal_product_id,
			$context
		);

		if ( is_wp_error( $paypal_plan_id ) ) {
			return $paypal_plan_id;
		}

		$payload = array(
			'plan_id' => $paypal_plan_id,
		);

		$subscriber = array();

		if ( method_exists( $customer, 'get_email' ) ) {
			$email = $customer->get_email();

			if ( '' !== $email ) {
				$subscriber['email_address'] = $email;
			}
		}

		if (
			method_exists( $customer, 'get_first_name' )
			&& method_exists( $customer, 'get_last_name' )
		) {
			$given_name = $customer->get_first_name();
			$surname    = $customer->get_last_name();

			if ( '' !== $given_name || '' !== $surname ) {
				$subscriber['name'] = array(
					'given_name' => $given_name,
					'surname'    => $surname,
				);
			}
		}

		if ( ! empty( $subscriber ) ) {
			$payload['subscriber'] = $subscriber;
		}

		$start_time = isset( $context['start_time'] )
			? sanitize_text_field( $context['start_time'] )
			: '';

		if ( '' !== $start_time ) {
			$payload['start_time'] = $start_time;
		}

		$return_url = isset( $context['return_url'] )
			? esc_url_raw( $context['return_url'] )
			: '';

		$cancel_url = isset( $context['cancel_url'] )
			? esc_url_raw( $context['cancel_url'] )
			: '';

		if ( '' !== $return_url && '' !== $cancel_url ) {
			$payload['application_context'] = array(
				'return_url' => $return_url,
				'cancel_url' => $cancel_url,
			);
		}

		$result = $this->request(
			'POST',
			'/v1/billing/subscriptions',
			$payload,
			array(
				'PayPal-Request-Id' => $this->generate_request_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$subscription_id = isset( $result['id'] )
			? sanitize_text_field( $result['id'] )
			: '';

		if ( '' === $subscription_id ) {
			return new \WP_Error(
				'dropkey_paypal_subscription_id_missing',
				__( 'PayPal did not return a subscription ID.', 'dropkey-wp' ),
				array(
					'provider_response' => $result,
				)
			);
		}

		$approval_url = '';

		if ( ! empty( $result['links'] ) && is_array( $result['links'] ) ) {
			foreach ( $result['links'] as $link ) {
				if (
					isset( $link['rel'], $link['href'] )
					&& 'approve' === $link['rel']
				) {
					$approval_url = esc_url_raw( $link['href'] );
					break;
				}
			}
		}

		return array(
			'gateway'                  => self::GATEWAY_ID,
			'gateway_subscription_id' => $subscription_id,
			'approval_url'             => $approval_url,
			'status'                   => isset( $result['status'] )
				? sanitize_key( strtolower( $result['status'] ) )
				: '',
			'provider_product_id'      => $paypal_product_id,
			'provider_plan_id'         => $paypal_plan_id,
			'provider_response'        => $result,
		);
	}

	private function resolve_product( $product ) {
		$product_id = $product->get_id();

		$mapping = $this->mappings->find_by_entity(
			self::GATEWAY_ID,
			'product',
			$product_id
		);

		if ( $mapping ) {
			$external_id = $mapping->get_external_id();

			if ( '' !== $external_id ) {
				return $external_id;
			}
		}

		$name        = $product->get_name();
		$description = '';

		if ( method_exists( $product, 'get_description' ) ) {
			$description = wp_strip_all_tags(
				(string) $product->get_description()
			);
		}

		$payload = array(
			'name'        => $this->truncate( $name, 127 ),
			'type'        => 'SERVICE',
			'category'    => 'SOFTWARE',
			'description' => $this->truncate(
				'' !== $description ? $description : $name,
				256
			),
		);

		$result = $this->request(
			'POST',
			'/v1/catalogs/products',
			$payload,
			array(
				'PayPal-Request-Id' => $this->generate_request_id(),
				'Prefer'            => 'return=representation',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$external_id = isset( $result['id'] )
			? sanitize_text_field( $result['id'] )
			: '';

		if ( '' === $external_id ) {
			return new \WP_Error(
				'dropkey_paypal_product_id_missing',
				__( 'PayPal did not return a product ID.', 'dropkey-wp' ),
				array(
					'provider_response' => $result,
				)
			);
		}

		$mapping_result = $this->mappings->create(
			array(
				'gateway'       => self::GATEWAY_ID,
				'entity_type'   => 'product',
				'entity_id'     => $product_id,
				'external_type' => 'product',
				'external_id'   => $external_id,
				'metadata'      => array(
					'name' => $name,
				),
			)
		);

		if ( is_wp_error( $mapping_result ) ) {
			return $mapping_result;
		}

		do_action(
			'dropkey_wp_gateway_product_created',
			$product,
			self::GATEWAY_ID,
			$external_id,
			$result
		);

		return $external_id;
	}

	private function resolve_plan( $plan, $paypal_product_id, array $context ) {
		$plan_id = $plan->get_id();

		$mapping = $this->mappings->find_by_entity(
			self::GATEWAY_ID,
			'plan',
			$plan_id
		);

		if ( $mapping ) {
			$external_id = $mapping->get_external_id();

			if ( '' !== $external_id ) {
				return $external_id;
			}
		}

		$interval_unit = strtolower(
			(string) $plan->get_billing_interval()
		);

		$interval_count = absint(
			$plan->get_billing_interval_count()
		);

		$interval_map = array(
			'day'    => 'DAY',
			'days'   => 'DAY',
			'week'   => 'WEEK',
			'weeks'  => 'WEEK',
			'month'  => 'MONTH',
			'months' => 'MONTH',
			'year'   => 'YEAR',
			'years'  => 'YEAR',
		);

		if ( isset( $interval_map[ $interval_unit ] ) ) {
			$interval_unit = $interval_map[ $interval_unit ];
		} else {
			$interval_unit = strtoupper( $interval_unit );
		}

		if ( $interval_count < 1 ) {
			$interval_count = 1;
		}

		$name        = $plan->get_name();
		$description = '';

		if ( method_exists( $plan, 'get_description' ) ) {
			$description = wp_strip_all_tags(
				(string) $plan->get_description()
			);
		}

		$price = number_format(
			(float) $plan->get_price(),
			2,
			'.',
			''
		);

		$currency = strtoupper(
			(string) $plan->get_currency()
		);

		$payload = array(
			'product_id'          => $paypal_product_id,
			'name'                => $this->truncate( $name, 127 ),
			'description'         => $this->truncate(
				'' !== $description ? $description : $name,
				127
			),
			'status'              => 'ACTIVE',
			'billing_cycles'      => array(
				array(
					'frequency' => array(
						'interval_unit'  => $interval_unit,
						'interval_count' => $interval_count,
					),
					'tenure_type'    => 'REGULAR',
					'sequence'       => 1,
					'total_cycles'   => 0,
					'pricing_scheme' => array(
						'fixed_price' => array(
							'value'         => $price,
							'currency_code' => $currency,
						),
					),
				),
			),
			'payment_preferences' => array(
				'auto_bill_outstanding'     => true,
				'payment_failure_threshold' => 1,
			),
		);

		$result = $this->request(
			'POST',
			'/v1/billing/plans',
			$payload,
			array(
				'PayPal-Request-Id' => $this->generate_request_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$external_id = isset( $result['id'] )
			? sanitize_text_field( $result['id'] )
			: '';

		if ( '' === $external_id ) {
			return new \WP_Error(
				'dropkey_paypal_plan_id_missing',
				__( 'PayPal did not return a billing plan ID.', 'dropkey-wp' ),
				array(
					'provider_response' => $result,
				)
			);
		}

		$status = isset( $result['status'] )
			? strtoupper( $result['status'] )
			: '';

		if ( 'ACTIVE' !== $status ) {
			$activation = $this->request(
				'POST',
				'/v1/billing/plans/' . rawurlencode( $external_id ) . '/activate',
				array(),
				array(
					'PayPal-Request-Id' => $this->generate_request_id(),
				)
			);

			if ( is_wp_error( $activation ) ) {
				return $activation;
			}
		}

		$mapping_result = $this->mappings->create(
			array(
				'gateway'       => self::GATEWAY_ID,
				'entity_type'   => 'plan',
				'entity_id'     => $plan_id,
				'external_type' => 'plan',
				'external_id'   => $external_id,
				'metadata'      => array(
					'product_external_id'     => $paypal_product_id,
					'name'                   => $name,
					'price'                  => $price,
					'currency'               => $currency,
					'billing_interval'       => $interval_unit,
					'billing_interval_count' => $interval_count,
				),
			)
		);

		if ( is_wp_error( $mapping_result ) ) {
			return $mapping_result;
		}

		do_action(
			'dropkey_wp_gateway_plan_created',
			$plan,
			self::GATEWAY_ID,
			$external_id,
			$result
		);

		return $external_id;
	}

	public function get_subscription( $gateway_subscription_id ) {
		$gateway_subscription_id = sanitize_text_field(
			$gateway_subscription_id
		);

		if ( '' === $gateway_subscription_id ) {
			return new \WP_Error(
				'dropkey_paypal_subscription_id_required',
				__( 'A PayPal subscription ID is required.', 'dropkey-wp' )
			);
		}

		return $this->request(
			'GET',
			'/v1/billing/subscriptions/' . rawurlencode( $gateway_subscription_id )
		);
	}

	public function cancel_subscription( $gateway_subscription_id, $reason = '' ) {
		$gateway_subscription_id = sanitize_text_field(
			$gateway_subscription_id
		);

		if ( '' === $gateway_subscription_id ) {
			return new \WP_Error(
				'dropkey_paypal_subscription_id_required',
				__( 'A PayPal subscription ID is required.', 'dropkey-wp' )
			);
		}

		$result = $this->request(
			'POST',
			'/v1/billing/subscriptions/' . rawurlencode( $gateway_subscription_id ) . '/cancel',
			array(
				'reason' => $this->truncate(
					sanitize_text_field( $reason ),
					128
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	public function process_webhook( $payload, array $headers ) {
		$payload = (string) $payload;

		$this->webhook_debug(
			'Webhook received.',
			array(
				'payload_length' => strlen( $payload ),
				'header_names'   => array_keys( $headers ),
			)
		);

		if ( '' === trim( $payload ) ) {
			return new \WP_Error(
				'dropkey_paypal_invalid_webhook',
				__( 'The PayPal webhook payload is empty.', 'dropkey-wp' ),
				array(
					'status' => 400,
				)
			);
		}

		$data = json_decode( $payload, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'dropkey_paypal_invalid_webhook',
				__( 'The PayPal webhook payload is invalid JSON.', 'dropkey-wp' ),
				array(
					'status' => 400,
				)
			);
		}

		$event_id = isset( $data['id'] )
			? sanitize_text_field( $data['id'] )
			: '';

		$event_type = isset( $data['event_type'] )
			? sanitize_text_field( $data['event_type'] )
			: '';

		if ( '' === $event_id || '' === $event_type ) {
			return new \WP_Error(
				'dropkey_paypal_invalid_webhook',
				__(
					'The PayPal webhook is missing required event information.',
					'dropkey-wp'
				),
				array(
					'status' => 400,
				)
			);
		}

		$verification = $this->verify_webhook_signature(
			$payload,
			$headers
		);

		if ( is_wp_error( $verification ) ) {
			$this->webhook_debug(
				'Webhook signature verification failed.',
				array(
					'error_code' => $verification->get_error_code(),
					'message'    => $verification->get_error_message(),
					'data'       => $verification->get_error_data(),
				)
			);

			return $verification;
		}

		$this->webhook_debug(
			'Webhook signature verification succeeded.',
			array(
				'event_id'   => $event_id,
				'event_type' => $event_type,
			)
		);

		return array(
			'gateway'    => self::GATEWAY_ID,
			'event_id'   => $event_id,
			'event_type' => $event_type,
			'payload'    => $payload,
			'headers'    => $headers,
		);
	}

	/**
	 * Verify a PayPal webhook signature using the raw payload.
	 *
	 * PayPal's REST webhook documentation requires the original raw
	 * request body when calculating the CRC32 value.
	 *
	 * @param string $payload Raw webhook body.
	 * @param array  $headers Request headers.
	 * @return true|WP_Error
	 */
	private function verify_webhook_signature(
		$payload,
		array $headers
	) {
		if ( '' === $this->webhook_id ) {
			return new \WP_Error(
				'dropkey_paypal_webhook_id_missing',
				__(
					'PayPal webhook verification is not configured.',
					'dropkey-wp'
				),
				array(
					'status' => 503,
				)
			);
		}

		$transmission_id = $this->get_header(
			$headers,
			'paypal-transmission-id'
		);

		$transmission_time = $this->get_header(
			$headers,
			'paypal-transmission-time'
		);

		$transmission_sig = $this->get_header(
			$headers,
			'paypal-transmission-sig'
		);

		$cert_url = $this->get_header(
			$headers,
			'paypal-cert-url'
		);

		$auth_algo = $this->get_header(
			$headers,
			'paypal-auth-algo'
		);

		if (
			'' === $transmission_id
			|| '' === $transmission_time
			|| '' === $transmission_sig
			|| '' === $cert_url
			|| '' === $auth_algo
		) {
			return new \WP_Error(
				'dropkey_paypal_webhook_headers_missing',
				__(
					'The PayPal webhook is missing required signature headers.',
					'dropkey-wp'
				),
				array(
					'status' => 400,
				)
			);
		}

		if ( ! $this->is_valid_cert_url( $cert_url ) ) {
			return new \WP_Error(
				'dropkey_paypal_webhook_invalid_cert_url',
				__(
					'The PayPal webhook certificate URL is invalid.',
					'dropkey-wp'
				),
				array(
					'status' => 400,
				)
			);
		}

		$transmission_timestamp = strtotime(
			$transmission_time
		);

		if ( false === $transmission_timestamp ) {
			return new \WP_Error(
				'dropkey_paypal_webhook_invalid_timestamp',
				__(
					'The PayPal webhook transmission timestamp is invalid.',
					'dropkey-wp'
				),
				array(
					'status' => 400,
				)
			);
		}

		if (
			abs( time() - $transmission_timestamp )
			> self::WEBHOOK_TIMESTAMP_TOLERANCE
		) {
			return new \WP_Error(
				'dropkey_paypal_webhook_timestamp_expired',
				__(
					'The PayPal webhook transmission timestamp is outside the allowed window.',
					'dropkey-wp'
				),
				array(
					'status' => 400,
				)
			);
		}

		$crc32 = sprintf(
			'%u',
			crc32( $payload )
		);

		$verification_string =
			$transmission_id
			. '|'
			. $transmission_time
			. '|'
			. $this->webhook_id
			. '|'
			. $crc32;

		$this->webhook_debug(
			'PayPal webhook signature verification prepared.',
			array(
				'transmission_id_present' => true,
				'auth_algo'               => $auth_algo,
				'crc32'                   => $crc32,
			)
		);

		$certificate = $this->get_webhook_certificate(
			$cert_url
		);

		if ( is_wp_error( $certificate ) ) {
			return $certificate;
		}

		$signature = base64_decode(
			$transmission_sig,
			true
		);

		if ( false === $signature ) {
			return new \WP_Error(
				'dropkey_paypal_webhook_invalid_signature',
				__(
					'The PayPal webhook signature is invalid.',
					'dropkey-wp'
				),
				array(
					'status' => 400,
				)
			);
		}

		$public_key = openssl_pkey_get_public(
			$certificate
		);

		if ( false === $public_key ) {
			return new \WP_Error(
				'dropkey_paypal_webhook_public_key_invalid',
				__(
					'The PayPal webhook certificate contains an invalid public key.',
					'dropkey-wp'
				),
				array(
					'status' => 502,
				)
			);
		}

		$verification = openssl_verify(
			$verification_string,
			$signature,
			$public_key,
			OPENSSL_ALGO_SHA256
		);

		if ( is_resource( $public_key ) ) {
			openssl_free_key( $public_key );
		}

		if ( 1 !== $verification ) {
			return new \WP_Error(
				'dropkey_paypal_webhook_signature_invalid',
				__(
					'PayPal webhook signature verification failed.',
					'dropkey-wp'
				),
				array(
					'status' => 400,
				)
			);
		}

		return true;
	}

	private function get_webhook_certificate( $cert_url ) {
		$cache_key = 'dropkey_wp_paypal_webhook_cert_' . md5(
			$cert_url
		);

		$cached = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			$cert_url,
			array(
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'dropkey_paypal_webhook_certificate_request_failed',
				__(
					'The PayPal webhook certificate could not be downloaded.',
					'dropkey-wp'
				),
				array(
					'status'  => 502,
					'details' => $response->get_error_message(),
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code(
			$response
		);

		$certificate = wp_remote_retrieve_body(
			$response
		);

		if (
			200 !== $status_code
			|| '' === trim( $certificate )
		) {
			return new \WP_Error(
				'dropkey_paypal_webhook_certificate_invalid',
				__(
					'PayPal returned an invalid webhook certificate.',
					'dropkey-wp'
				),
				array(
					'status'      => 502,
					'status_code' => $status_code,
				)
			);
		}

		$cache_key = sanitize_key( $cache_key );

		set_transient(
			$cache_key,
			$certificate,
			self::WEBHOOK_CERT_CACHE_TTL
		);

		return $certificate;
	}

	private function is_valid_cert_url( $cert_url ) {
		$parsed = wp_parse_url( $cert_url );

		if ( ! is_array( $parsed ) ) {
			return false;
		}

		if (
			empty( $parsed['scheme'] )
			|| 'https' !== strtolower( $parsed['scheme'] )
		) {
			return false;
		}

		if ( empty( $parsed['host'] ) ) {
			return false;
		}

		$host = strtolower(
			rtrim(
				$parsed['host'],
				'.'
			)
		);

		if ( 'paypal.com' === $host ) {
			return true;
		}

		return (bool) preg_match(
			'/^[a-z0-9.-]+\.paypal\.com$/i',
			$host
		);
	}

	private function get_header( array $headers, $name ) {
		$name = strtolower(
			str_replace( '_', '-', (string) $name )
		);

		foreach ( $headers as $header_name => $value ) {
			$normalized_header_name = strtolower(
				str_replace( '_', '-', (string) $header_name )
			);

			if ( $normalized_header_name !== $name ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = reset( $value );
			}

			return trim( (string) $value );
		}

		return '';
	}

	private function webhook_debug(
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
			'time'    => current_time( 'mysql', true ),
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

	private function request(
		$method,
		$path,
		array $body = array(),
		array $headers = array()
	) {
		$access_token = $this->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$request_headers = array_merge(
			array(
				'Authorization' => 'Bearer ' . $access_token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
			$headers
		);

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => $request_headers,
		);

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request(
			$this->get_api_url() . $path,
			$args
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'dropkey_paypal_http_error',
				$response->get_error_message()
			);
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		$data = json_decode(
			$response_body,
			true
		);

		if (
			$status_code < 200
			|| $status_code >= 300
		) {
			return $this->create_api_error(
				$status_code,
				$data,
				$response_body
			);
		}

		if ( '' === trim( $response_body ) ) {
			return array();
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'dropkey_paypal_invalid_response',
				__( 'PayPal returned an invalid response.', 'dropkey-wp' ),
				array(
					'status_code' => $status_code,
				)
			);
		}

		return $data;
	}

	private function get_access_token() {
		if (
			'' === $this->client_id
			|| '' === $this->client_secret
		) {
			return new \WP_Error(
				'dropkey_paypal_credentials_missing',
				__( 'PayPal credentials are not configured.', 'dropkey-wp' )
			);
		}

		$response = wp_remote_post(
			$this->get_api_url() . '/v1/oauth2/token',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode(
						$this->client_id
						. ':'
						. $this->client_secret
					),
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body' => array(
					'grant_type' => 'client_credentials',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'dropkey_paypal_oauth_error',
				$response->get_error_message()
			);
		}

		$status_code = wp_remote_retrieve_response_code(
			$response
		);

		$response_body = wp_remote_retrieve_body(
			$response
		);

		$data = json_decode(
			$response_body,
			true
		);

		if (
			$status_code < 200
			|| $status_code >= 300
		) {
			return $this->create_api_error(
				$status_code,
				$data,
				$response_body
			);
		}

		if (
			! is_array( $data )
			|| empty( $data['access_token'] )
		) {
			return new \WP_Error(
				'dropkey_paypal_oauth_token_missing',
				__( 'PayPal did not return an access token.', 'dropkey-wp' )
			);
		}

		return sanitize_text_field(
			$data['access_token']
		);
	}

	private function get_api_url() {
		return $this->sandbox
			? self::SANDBOX_API_URL
			: self::LIVE_API_URL;
	}

	private function generate_request_id() {
		return wp_generate_uuid4();
	}

	private function create_api_error(
		$status_code,
		$data,
		$raw_body
	) {
		$message = __(
			'PayPal API request failed.',
			'dropkey-wp'
		);

		if ( is_array( $data ) ) {
			if ( ! empty( $data['message'] ) ) {
				$message = sanitize_text_field(
					$data['message']
				);
			} elseif (
				! empty(
					$data['details'][0]['description']
				)
			) {
				$message = sanitize_text_field(
					$data['details'][0]['description']
				);
			}
		}

		return new \WP_Error(
			'dropkey_paypal_api_error',
			$message,
			array(
				'status_code' => absint( $status_code ),
				'response'    => is_array( $data )
					? $data
					: $raw_body,
			)
		);
	}

	private function truncate( $value, $limit ) {
		$value = sanitize_text_field( $value );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr(
				$value,
				0,
				$limit
			);
		}

		return substr(
			$value,
			0,
			$limit
		);
	}
}