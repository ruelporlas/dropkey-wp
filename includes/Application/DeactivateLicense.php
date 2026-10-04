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

	private $licenses;

	private $activations;

	public function __construct(
		LicenseRepository $licenses,
		ActivationRepository $activations
	) {
		$this->licenses    = $licenses;
		$this->activations = $activations;
	}

	/**
	 * Deactivate an authenticated activation.
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

		/*
		 * Deactivation is idempotent.
		 *
		 * If the activation is already deactivated, return it
		 * without modifying the record or firing the action again.
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
	}
}