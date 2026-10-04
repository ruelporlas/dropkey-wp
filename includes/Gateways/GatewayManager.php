<?php
/**
 * DropKey WP gateway manager.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Gateways;

use DropKeyWP\Database\Repositories\GatewayMappingRepository;
use DropKeyWP\Gateways\Contracts\PaymentGatewayInterface;
use DropKeyWP\Gateways\PayPal\PayPalGateway;
use DropKeyWP\Gateways\PayPal\PayPalSettings;

defined( 'ABSPATH' ) || exit;

final class GatewayManager {

	private $gateways = array();

	public function __construct() {
		$this->register_default_gateways();

		$this->gateways = apply_filters(
			'dropkey_wp_payment_gateways',
			$this->gateways
		);
	}

	public function get( $gateway_id ) {
		$gateway_id = sanitize_key( $gateway_id );

		if (
			isset( $this->gateways[ $gateway_id ] )
			&& $this->gateways[ $gateway_id ] instanceof PaymentGatewayInterface
		) {
			return $this->gateways[ $gateway_id ];
		}

		return null;
	}

	public function all() {
		return $this->gateways;
	}

	public function has( $gateway_id ) {
		return null !== $this->get( $gateway_id );
	}

	public function available() {
		$available = array();

		foreach ( $this->gateways as $gateway_id => $gateway ) {
			if ( $gateway->is_available() ) {
				$available[ $gateway_id ] = $gateway;
			}
		}

		return $available;
	}

	private function register_default_gateways() {
		global $wpdb;

		$paypal_settings = PayPalSettings::get();
		$mappings        = new GatewayMappingRepository( $wpdb );

		$this->register(
			new PayPalGateway(
				$paypal_settings['client_id'],
				$paypal_settings['client_secret'],
				PayPalSettings::ENVIRONMENT_SANDBOX === $paypal_settings['environment'],
				$mappings,
				$paypal_settings['webhook_id']
			)
		);
	}

	private function register( PaymentGatewayInterface $gateway ) {
		$gateway_id = sanitize_key(
			$gateway->get_id()
		);

		if ( '' === $gateway_id ) {
			return false;
		}

		$this->gateways[ $gateway_id ] = $gateway;

		return true;
	}
}