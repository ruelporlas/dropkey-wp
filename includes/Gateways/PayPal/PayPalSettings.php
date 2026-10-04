<?php
/**
 * PayPal gateway settings.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Gateways\PayPal;

defined( 'ABSPATH' ) || exit;

final class PayPalSettings {

	/**
	 * WordPress option name.
	 *
	 * @var string
	 */
	private const OPTION_NAME = 'dropkey_wp_paypal_settings';

	/**
	 * Sandbox environment.
	 *
	 * @var string
	 */
	public const ENVIRONMENT_SANDBOX = 'sandbox';

	/**
	 * Live environment.
	 *
	 * @var string
	 */
	public const ENVIRONMENT_LIVE = 'live';

	/**
	 * Get the stored settings.
	 *
	 * @return array
	 */
	public static function get() {
		$settings = get_option(
			self::OPTION_NAME,
			array()
		);

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array(
			'environment'   => self::normalize_environment(
				isset( $settings['environment'] )
					? $settings['environment']
					: self::ENVIRONMENT_SANDBOX
			),
			'client_id'     => isset( $settings['client_id'] )
				? trim( (string) $settings['client_id'] )
				: '',
			'client_secret' => isset( $settings['client_secret'] )
				? trim( (string) $settings['client_secret'] )
				: '',
			'webhook_id'    => isset( $settings['webhook_id'] )
				? trim( (string) $settings['webhook_id'] )
				: '',
		);
	}

	/**
	 * Save settings.
	 *
	 * @param array $settings Settings to save.
	 * @return bool
	 */
	public static function save( array $settings ) {
		$current = self::get();

		$environment = isset( $settings['environment'] )
			? self::normalize_environment( $settings['environment'] )
			: $current['environment'];

		$client_id = isset( $settings['client_id'] )
			? sanitize_text_field( $settings['client_id'] )
			: $current['client_id'];

		/*
		 * An empty client secret means "keep the existing secret".
		 * This allows the settings page to use a password field
		 * without exposing the existing secret back to the browser.
		 */
		$client_secret = $current['client_secret'];

		if (
			isset( $settings['client_secret'] )
			&& '' !== trim( (string) $settings['client_secret'] )
		) {
			$client_secret = trim(
				(string) $settings['client_secret']
			);
		}

		$webhook_id = isset( $settings['webhook_id'] )
			? sanitize_text_field( $settings['webhook_id'] )
			: $current['webhook_id'];

		return update_option(
			self::OPTION_NAME,
			array(
				'environment'   => $environment,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'webhook_id'    => $webhook_id,
			),
			false
		);
	}

	/**
	 * Determine whether PayPal is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$settings = self::get();

		return '' !== $settings['client_id']
			&& '' !== $settings['client_secret'];
	}

	/**
	 * Determine whether webhook verification is configured.
	 *
	 * @return bool
	 */
	public static function is_webhook_configured() {
		$settings = self::get();

		return '' !== $settings['webhook_id'];
	}

	/**
	 * Get the configured environment.
	 *
	 * @return string
	 */
	public static function get_environment() {
		$settings = self::get();

		return $settings['environment'];
	}

	/**
	 * Get the configured client ID.
	 *
	 * @return string
	 */
	public static function get_client_id() {
		$settings = self::get();

		return $settings['client_id'];
	}

	/**
	 * Get the configured client secret.
	 *
	 * @return string
	 */
	public static function get_client_secret() {
		$settings = self::get();

		return $settings['client_secret'];
	}

	/**
	 * Get the configured webhook ID.
	 *
	 * @return string
	 */
	public static function get_webhook_id() {
		$settings = self::get();

		return $settings['webhook_id'];
	}

	/**
	 * Normalize the environment value.
	 *
	 * @param string $environment Environment.
	 * @return string
	 */
	private static function normalize_environment( $environment ) {
		return self::ENVIRONMENT_LIVE === $environment
			? self::ENVIRONMENT_LIVE
			: self::ENVIRONMENT_SANDBOX;
	}
}