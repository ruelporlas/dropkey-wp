<?php
/**
 * Activate license application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\ActivationRepository;
use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Domain\Activation;
use DropKeyWP\Domain\License;

defined( 'ABSPATH' ) || exit;

final class ActivateLicense {

	private $licenses;

	private $activations;

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
	}

	/**
	 * Activate a license for a site.
	 *
	 * Activation and reactivation are serialized per license so that
	 * concurrent requests cannot both observe an available activation
	 * slot and exceed the configured activation limit.
	 *
	 * @param int    $license_id License ID.
	 * @param string $site_url   Site URL.
	 * @return array|\WP_Error
	 */
	public function execute( $license_id, $site_url ) {
		global $wpdb;

		$license_id = absint( $license_id );
		$site_url   = esc_url_raw( $site_url );

		if ( $license_id <= 0 ) {
			return new \WP_Error(
				'dropkey_license_invalid',
				__( 'A valid license is required.', 'dropkey-wp' )
			);
		}

		if ( '' === $site_url ) {
			return new \WP_Error(
				'dropkey_activation_invalid_site_url',
				__( 'A valid site URL is required.', 'dropkey-wp' )
			);
		}

		$license = $this->licenses->find( $license_id );

		if ( ! $license ) {
			return new \WP_Error(
				'dropkey_license_not_found',
				__( 'The license does not exist.', 'dropkey-wp' )
			);
		}

		if ( License::STATUS_ACTIVE !== $license->get_status() ) {
			return new \WP_Error(
				'dropkey_license_not_active',
				__( 'The license is not active.', 'dropkey-wp' )
			);
		}

		/*
		 * An active status alone is not sufficient for activation.
		 * The license must also have a current entitlement period.
		 */
		if ( $this->has_expired( $license->get_expires_at() ) ) {
			return new \WP_Error(
				'dropkey_license_expired',
				__( 'The license has expired.', 'dropkey-wp' )
			);
		}

		$site_identifier = $this->get_site_identifier( $site_url );

		/*
		 * Serialize all activation operations for this license.
		 *
		 * The activation limit is a shared resource. Without this lock,
		 * two simultaneous requests can both observe the same active
		 * activation count and both create an activation.
		 *
		 * MySQL releases the advisory lock automatically if the database
		 * connection closes, so a fatal request failure cannot leave
		 * a permanent application lock behind.
		 */
		$lock_name = $this->get_lock_name( $license_id );

		$lock_result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK( %s, %d )',
				$lock_name,
				10
			)
		);

		if ( '1' !== (string) $lock_result ) {
			return new \WP_Error(
				'dropkey_activation_locked',
				__(
					'License activation is currently being processed. Please try again.',
					'dropkey-wp'
				)
			);
		}

		try {
			/*
			 * Reload the license after acquiring the lock.
			 *
			 * Another request may have changed the license entitlement
			 * while this request was waiting.
			 */
			$license = $this->licenses->find( $license_id );

			if ( ! $license ) {
				return new \WP_Error(
					'dropkey_license_not_found',
					__( 'The license does not exist.', 'dropkey-wp' )
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

			/*
			 * Check whether this exact site already has an activation.
			 *
			 * This check happens after acquiring the lock so that a
			 * concurrent activation request for the same site cannot
			 * race with this request.
			 */
			$existing_activation = $this->activations->find_by_license_and_site(
				$license_id,
				$site_identifier
			);

			/*
			 * An already-active site is idempotent.
			 *
			 * Do not generate or rotate its token.
			 */
			if ( $existing_activation ) {
				if (
					Activation::STATUS_ACTIVE ===
					$existing_activation->get_status()
				) {
					return array(
						'activation' => $existing_activation,
						'api_token'  => '',
						'new_token'  => false,
					);
				}

				/*
				 * A deactivated activation can be reused.
				 *
				 * Reactivation still occurs while holding the license
				 * lock because it consumes an active activation slot.
				 */
				$active_count = $this->activations->count_active_by_license(
					$license_id
				);

				if ( $active_count >= $license->get_activation_limit() ) {
					return new \WP_Error(
						'dropkey_activation_limit_reached',
						__(
							'The activation limit for this license has been reached.',
							'dropkey-wp'
						)
					);
				}

				$api_token = $this->generate_api_token();

				if ( is_wp_error( $api_token ) ) {
					return $api_token;
				}

				$api_token_hash = hash( 'sha256', $api_token );

				$activation = $this->activations->reactivate(
					$existing_activation->get_id(),
					$site_url,
					$api_token_hash
				);

				if ( is_wp_error( $activation ) ) {
					return $activation;
				}

				do_action(
					'dropkey_wp_license_activated',
					$license,
					$activation
				);

				return array(
					'activation' => $activation,
					'api_token'  => $api_token,
					'new_token'  => true,
				);
			}

			/*
			 * Count active activations only after acquiring the lock.
			 *
			 * This makes the count + create operation effectively
			 * atomic from the application's perspective.
			 */
			$active_count = $this->activations->count_active_by_license(
				$license_id
			);

			if ( $active_count >= $license->get_activation_limit() ) {
				return new \WP_Error(
					'dropkey_activation_limit_reached',
					__(
						'The activation limit for this license has been reached.',
						'dropkey-wp'
					)
				);
			}

			$api_token = $this->generate_api_token();

			if ( is_wp_error( $api_token ) ) {
				return $api_token;
			}

			$api_token_hash = hash( 'sha256', $api_token );
			$now            = current_time( 'mysql', true );

			$activation = new Activation(
				array(
					'license_id'        => $license_id,
					'site_url'          => $site_url,
					'site_identifier'   => $site_identifier,
					'api_token_hash'    => $api_token_hash,
					'status'            => Activation::STATUS_ACTIVE,
					'activated_at'      => $now,
					'last_validated_at' => $now,
					'deactivated_at'    => null,
				)
			);

			$validation = $activation->validate();

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			$created_activation = $this->activations->create(
				array(
					'license_id'        => $activation->get_license_id(),
					'site_url'          => $activation->get_site_url(),
					'site_identifier'   => $activation->get_site_identifier(),
					'api_token_hash'    => $activation->get_api_token_hash(),
					'status'            => $activation->get_status(),
					'activated_at'      => $activation->get_activated_at(),
					'last_validated_at' => $activation->get_last_validated_at(),
					'deactivated_at'    => $activation->get_deactivated_at(),
				)
			);

			if ( is_wp_error( $created_activation ) ) {
				return $created_activation;
			}

			do_action(
				'dropkey_wp_license_activated',
				$license,
				$created_activation
			);

			return array(
				'activation' => $created_activation,
				'api_token'  => $api_token,
				'new_token'  => true,
			);
		} finally {
			/*
			 * RELEASE_LOCK() must run on the same database connection
			 * that acquired the advisory lock.
			 */
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT RELEASE_LOCK( %s )',
					$lock_name
				)
			);
		}
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

	/**
	 * Generate a cryptographically secure API token.
	 *
	 * @return string|\WP_Error
	 */
	private function generate_api_token() {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Exception $exception ) {
			return new \WP_Error(
				'dropkey_activation_token_generation_failed',
				__(
					'A secure activation token could not be generated.',
					'dropkey-wp'
				)
			);
		}
	}

	/**
	 * Generate a normalized site identifier.
	 *
	 * @param string $site_url Site URL.
	 * @return string
	 */
	private function get_site_identifier( $site_url ) {
		return hash(
			'sha256',
			untrailingslashit( strtolower( $site_url ) )
		);
	}

	/**
	 * Generate the advisory lock name for a license.
	 *
	 * MySQL limits user-level lock names to 64 characters. The license
	 * ID is numeric, so this remains safely below that limit while
	 * keeping the lock namespace specific to DropKey WP.
	 *
	 * @param int $license_id License ID.
	 * @return string
	 */
	private function get_lock_name( $license_id ) {
		return 'dropkey_license_activation_' . absint( $license_id );
	}
}