<?php
/**
 * Subscription domain entity.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Domain;

defined( 'ABSPATH' ) || exit;

final class Subscription {

	public const STATUS_PENDING    = 'pending';
	public const STATUS_ACTIVE     = 'active';
	public const STATUS_PAST_DUE   = 'past_due';
	public const STATUS_SUSPENDED  = 'suspended';
	public const STATUS_CANCELLED  = 'cancelled';
	public const STATUS_EXPIRED    = 'expired';

	private $id;
	private $customer_id;
	private $product_id;
	private $plan_id;
	private $gateway;
	private $gateway_subscription_id;
	private $status;
	private $current_period_start;
	private $current_period_end;
	private $cancel_at_period_end;
	private $cancelled_at;
	private $past_due_at;
	private $ended_at;
	private $created_at;
	private $updated_at;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Subscription data.
	 */
	public function __construct( array $data ) {
		$this->id                       = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->customer_id              = isset( $data['customer_id'] ) ? (int) $data['customer_id'] : 0;
		$this->product_id               = isset( $data['product_id'] ) ? (int) $data['product_id'] : 0;
		$this->plan_id                  = isset( $data['plan_id'] ) ? (int) $data['plan_id'] : 0;
		$this->gateway                  = isset( $data['gateway'] ) ? sanitize_key( $data['gateway'] ) : '';
		$this->gateway_subscription_id  = isset( $data['gateway_subscription_id'] )
			? (string) $data['gateway_subscription_id']
			: '';
		$this->status                   = isset( $data['status'] )
			? (string) $data['status']
			: self::STATUS_PENDING;
		$this->current_period_start     = isset( $data['current_period_start'] )
			? (string) $data['current_period_start']
			: '';
		$this->current_period_end       = isset( $data['current_period_end'] )
			? (string) $data['current_period_end']
			: '';
		$this->cancel_at_period_end     = ! empty( $data['cancel_at_period_end'] ) ? 1 : 0;
		$this->cancelled_at             = isset( $data['cancelled_at'] )
			? (string) $data['cancelled_at']
			: '';
		$this->past_due_at              = isset( $data['past_due_at'] )
			? (string) $data['past_due_at']
			: '';
		$this->ended_at                 = isset( $data['ended_at'] )
			? (string) $data['ended_at']
			: '';
		$this->created_at               = isset( $data['created_at'] )
			? (string) $data['created_at']
			: '';
		$this->updated_at               = isset( $data['updated_at'] )
			? (string) $data['updated_at']
			: '';
	}

	/**
	 * Validate the subscription.
	 *
	 * @return true|\WP_Error
	 */
	public function validate() {
		$errors = new \WP_Error();

		if ( $this->customer_id <= 0 ) {
			$errors->add(
				'dropkey_subscription_customer_required',
				__( 'A valid customer is required.', 'dropkey-wp' )
			);
		}

		if ( $this->product_id <= 0 ) {
			$errors->add(
				'dropkey_subscription_product_required',
				__( 'A valid product is required.', 'dropkey-wp' )
			);
		}

		if ( $this->plan_id <= 0 ) {
			$errors->add(
				'dropkey_subscription_plan_required',
				__( 'A valid plan is required.', 'dropkey-wp' )
			);
		}

		if ( '' === $this->gateway ) {
			$errors->add(
				'dropkey_subscription_gateway_required',
				__( 'A payment gateway is required.', 'dropkey-wp' )
			);
		}

		if ( ! in_array(
			$this->status,
			array(
				self::STATUS_PENDING,
				self::STATUS_ACTIVE,
				self::STATUS_PAST_DUE,
				self::STATUS_SUSPENDED,
				self::STATUS_CANCELLED,
				self::STATUS_EXPIRED,
			),
			true
		) ) {
			$errors->add(
				'dropkey_subscription_status_invalid',
				__( 'Subscription status is invalid.', 'dropkey-wp' )
			);
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return true;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_customer_id() {
		return $this->customer_id;
	}

	public function get_product_id() {
		return $this->product_id;
	}

	public function get_plan_id() {
		return $this->plan_id;
	}

	public function get_gateway() {
		return $this->gateway;
	}

	public function get_gateway_subscription_id() {
		return $this->gateway_subscription_id;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_current_period_start() {
		return $this->current_period_start;
	}

	public function get_current_period_end() {
		return $this->current_period_end;
	}

	public function get_cancel_at_period_end() {
		return $this->cancel_at_period_end;
	}

	public function get_cancelled_at() {
		return $this->cancelled_at;
	}

	public function get_past_due_at() {
		return $this->past_due_at;
	}

	public function get_ended_at() {
		return $this->ended_at;
	}

	public function get_created_at() {
		return $this->created_at;
	}

	public function get_updated_at() {
		return $this->updated_at;
	}
}