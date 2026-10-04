<?php
/**
 * Gateway mapping domain entity.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Domain;

defined( 'ABSPATH' ) || exit;

final class GatewayMapping {

	/**
	 * Mapping ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	private $gateway;

	/**
	 * Internal entity type.
	 *
	 * @var string
	 */
	private $entity_type;

	/**
	 * Internal entity ID.
	 *
	 * @var int
	 */
	private $entity_id;

	/**
	 * External entity type.
	 *
	 * @var string
	 */
	private $external_type;

	/**
	 * External entity ID.
	 *
	 * @var string
	 */
	private $external_id;

	/**
	 * Mapping metadata.
	 *
	 * @var string
	 */
	private $metadata;

	/**
	 * Created timestamp.
	 *
	 * @var string
	 */
	private $created_at;

	/**
	 * Updated timestamp.
	 *
	 * @var string
	 */
	private $updated_at;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Mapping data.
	 */
	public function __construct( array $data ) {
		$this->id            = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->gateway       = isset( $data['gateway'] ) ? (string) $data['gateway'] : '';
		$this->entity_type   = isset( $data['entity_type'] ) ? (string) $data['entity_type'] : '';
		$this->entity_id     = isset( $data['entity_id'] ) ? (int) $data['entity_id'] : 0;
		$this->external_type = isset( $data['external_type'] ) ? (string) $data['external_type'] : '';
		$this->external_id   = isset( $data['external_id'] ) ? (string) $data['external_id'] : '';
		$this->metadata      = isset( $data['metadata'] ) ? (string) $data['metadata'] : '';
		$this->created_at    = isset( $data['created_at'] ) ? (string) $data['created_at'] : '';
		$this->updated_at    = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : '';
	}

	/**
	 * Get mapping ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get gateway ID.
	 *
	 * @return string
	 */
	public function get_gateway() {
		return $this->gateway;
	}

	/**
	 * Get internal entity type.
	 *
	 * @return string
	 */
	public function get_entity_type() {
		return $this->entity_type;
	}

	/**
	 * Get internal entity ID.
	 *
	 * @return int
	 */
	public function get_entity_id() {
		return $this->entity_id;
	}

	/**
	 * Get external entity type.
	 *
	 * @return string
	 */
	public function get_external_type() {
		return $this->external_type;
	}

	/**
	 * Get external entity ID.
	 *
	 * @return string
	 */
	public function get_external_id() {
		return $this->external_id;
	}

	/**
	 * Get metadata.
	 *
	 * @return string
	 */
	public function get_metadata() {
		return $this->metadata;
	}

	/**
	 * Get decoded metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function get_metadata_array() {
		if ( '' === $this->metadata ) {
			return array();
		}

		$metadata = json_decode( $this->metadata, true );

		return is_array( $metadata ) ? $metadata : array();
	}

	/**
	 * Get created timestamp.
	 *
	 * @return string
	 */
	public function get_created_at() {
		return $this->created_at;
	}

	/**
	 * Get updated timestamp.
	 *
	 * @return string
	 */
	public function get_updated_at() {
		return $this->updated_at;
	}
}