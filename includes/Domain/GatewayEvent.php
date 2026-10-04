<?php
/**
 * Gateway event domain entity.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Domain;

defined( 'ABSPATH' ) || exit;

final class GatewayEvent {

	public const STATUS_RECEIVED  = 'received';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_PROCESSED = 'processed';
	public const STATUS_FAILED    = 'failed';

	private $id;
	private $gateway;
	private $event_id;
	private $event_type;
	private $status;
	private $payload_hash;
	private $payload;
	private $processed_at;
	private $error_message;
	private $created_at;
	private $updated_at;

	public function __construct( array $data ) {
		$this->id            = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->gateway       = isset( $data['gateway'] ) ? sanitize_key( $data['gateway'] ) : '';
		$this->event_id      = isset( $data['event_id'] ) ? (string) $data['event_id'] : '';
		$this->event_type    = isset( $data['event_type'] ) ? (string) $data['event_type'] : '';
		$this->status        = isset( $data['status'] ) ? (string) $data['status'] : '';
		$this->payload_hash  = isset( $data['payload_hash'] ) ? (string) $data['payload_hash'] : '';
		$this->payload       = isset( $data['payload'] ) ? (string) $data['payload'] : '';
		$this->processed_at  = isset( $data['processed_at'] ) ? (string) $data['processed_at'] : '';
		$this->error_message = isset( $data['error_message'] ) ? (string) $data['error_message'] : '';
		$this->created_at    = isset( $data['created_at'] ) ? (string) $data['created_at'] : '';
		$this->updated_at    = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : '';
	}

	public function get_id() {
		return $this->id;
	}

	public function get_gateway() {
		return $this->gateway;
	}

	public function get_event_id() {
		return $this->event_id;
	}

	public function get_event_type() {
		return $this->event_type;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_payload_hash() {
		return $this->payload_hash;
	}

	public function get_payload() {
		return $this->payload;
	}

	public function get_processed_at() {
		return $this->processed_at;
	}

	public function get_error_message() {
		return $this->error_message;
	}

	public function get_created_at() {
		return $this->created_at;
	}

	public function get_updated_at() {
		return $this->updated_at;
	}
}