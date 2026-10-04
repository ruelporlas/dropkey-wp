<?php
/**
 * Deactivate license application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\ActivationRepository;
use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Domain\Activation;

defined( 'ABSPATH' ) || exit;

final class DeactivateLicense {

	/**
	 * License repository.
	 *
	 * @var LicenseRepository
	 */
	private $licenses;

	/**
	 * Activation repository.
	 *
	 * @var ActivationRepository
	 */
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
	 * Deactivate an activation.
	 *
	 * A license-specific activation lock is not sufficient here because
	 * different activations belonging to the same license may legitimately
	 * be deactivated at the same time. The lock therefore targets the
	 * individual activation record.
	 *
	 * @param int $activation_id Activation ID.
	 * @return Activation|\WP_Error
	 */
	public function execute( $activation_id ) {
		$activation_id = absint( $activation_id );

		if ( $activation_id <= 0 ) {
			return new \WP_Error(
				'dropkey_activation_invalid',
				__( 'A valid activation is required.', 'dropkey-wp' )
			);
		}

		$activation = $this->activations->find( $activation_id );

		if ( ! $activation ) {
			return new \WP_Error(
				'dropkey_activation_not_found',
				__( 'The activation does not exist.', 'dropkey-wp' )
			);
		}

		global $wpdb;

		$lock_name = $this->get_lock_name( $activation_id );

		$lock_acquired = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s, %d)',
				$lock_name,
				10
			)
		);

		if ( '1' !== (string) $lock_acquired ) {
			return new \WP_Error(
				'dropkey_activation_lock_failed',
				__(
					'The activation is currently being updated. Please try again.',
					'dropkey-wp'
				)
			);
		}

		try {
			/*
			 * Reload after acquiring the lock. The activation read before
			 * the lock may already be stale if another request changed it
			 * while this request was waiting.
			 */
			$activation = $this->activations->find(
				$activation_id
			);

			if ( ! $activation ) {
				return new \WP_Error(
					'dropkey_activation_not_found',
					__( 'The activation does not exist.', 'dropkey-wp' )
				);
			}

			/*
			 * Deactivation is idempotent.
			 *
			 * This check must happen after acquiring the lock so concurrent
			 * requests cannot both perform the transition and fire the
			 * lifecycle action.
			 */
			if ( Activation::STATUS_DEACTIVATED === $activation->get_status() ) {
				return $activation;
			}

			$deactivated = $this->activations->deactivate(
				$activation->get_id()
			);

			if ( is_wp_error( $deactivated ) ) {
				return $deactivated;
			}

			$license = $this->licenses->find(
				$deactivated->get_license_id()
			);

			if ( $license ) {
				do_action(
					'dropkey_wp_license_deactivated',
					$license,
					$deactivated
				);
			}

			return $deactivated;
		} finally {
			/*
			 * GET_LOCK() is connection-scoped, so release it through the
			 * same $wpdb connection that acquired it.
			 */
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT RELEASE_LOCK(%s)',
					$lock_name
				)
			);
		}
	}

	/**
	 * Build the advisory lock name for an activation.
	 *
	 * @param int $activation_id Activation ID.
	 * @return string
	 */
	private function get_lock_name( $activation_id ) {
		return 'dropkey_activation_deactivation_' . absint( $activation_id );
	}
}