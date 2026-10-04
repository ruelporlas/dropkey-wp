<?php
/**
 * Authenticate a license activation using its API token.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\ActivationRepository;

defined( 'ABSPATH' ) || exit;

final class AuthenticateActivation {

	/**
	 * Activation repository.
	 *
	 * @var ActivationRepository
	 */
	private $activation_repository;

	/**
	 * Constructor.
	 *
	 * @param ActivationRepository $activation_repository Activation repository.
	 */
	public function __construct( ActivationRepository $activation_repository ) {
		$this->activation_repository = $activation_repository;
	}

	/**
	 * Authenticate an activation using its API token.
	 *
	 * @param string $api_token Raw API token.
	 * @return \DropKeyWP\Domain\Activation|\WP_Error
	 */
	public function execute( $api_token ) {
		$api_token = trim( (string) $api_token );

		if ( '' === $api_token ) {
			return new \WP_Error(
				'dropkey_api_token_required',
				__( 'An API token is required.', 'dropkey-wp' )
			);
		}

		$token_hash = hash( 'sha256', $api_token );

		$activation = $this->activation_repository->find_by_token_hash( $token_hash );

		if ( ! $activation ) {
			return new \WP_Error(
				'dropkey_api_token_invalid',
				__( 'The API token is invalid.', 'dropkey-wp' )
			);
		}

		if ( ! $activation->is_active() ) {
			return new \WP_Error(
				'dropkey_activation_not_active',
				__( 'The activation is no longer active.', 'dropkey-wp' )
			);
		}

		return $activation;
	}
}