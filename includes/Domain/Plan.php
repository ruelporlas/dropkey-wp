<?php
/**
 * Plan domain entity.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Domain;

defined( 'ABSPATH' ) || exit;

final class Plan {

	/**
	 * Active status.
	 *
	 * @var string
	 */
	public const STATUS_ACTIVE = 'active';

	/**
	 * Archived status.
	 *
	 * @var string
	 */
	public const STATUS_ARCHIVED = 'archived';

	/**
	 * Supported billing intervals.
	 *
	 * @var string
	 */
	public const INTERVAL_DAY = 'day';

	/**
	 * @var string
	 */
	public const INTERVAL_WEEK = 'week';

	/**
	 * @var string
	 */
	public const INTERVAL_MONTH = 'month';

	/**
	 * @var string
	 */
	public const INTERVAL_YEAR = 'year';

	/**
	 * Plan ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $product_id;

	/**
	 * Plan name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Plan slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Plan price.
	 *
	 * Stored as a decimal string to avoid floating-point money issues.
	 *
	 * @var string
	 */
	private $price;

	/**
	 * Currency.
	 *
	 * @var string
	 */
	private $currency;

	/**
	 * Billing interval.
	 *
	 * @var string
	 */
	private $billing_interval;

	/**
	 * Billing interval count.
	 *
	 * @var int
	 */
	private $billing_interval_count;

	/**
	 * Activation limit.
	 *
	 * @var int
	 */
	private $activation_limit;

	/**
	 * Status.
	 *
	 * @var string
	 */
	private $status;

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
	 * @param array<string,mixed> $data Plan data.
	 */
	public function __construct( array $data ) {
		$this->id                     = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->product_id             = isset( $data['product_id'] ) ? (int) $data['product_id'] : 0;
		$this->name                   = isset( $data['name'] ) ? (string) $data['name'] : '';
		$this->slug                   = isset( $data['slug'] ) ? (string) $data['slug'] : '';
		$this->price                  = isset( $data['price'] ) ? (string) $data['price'] : '0.0000';
		$this->currency               = isset( $data['currency'] ) ? strtoupper( (string) $data['currency'] ) : '';
		$this->billing_interval       = isset( $data['billing_interval'] ) ? (string) $data['billing_interval'] : '';
		$this->billing_interval_count = isset( $data['billing_interval_count'] )
			? (int) $data['billing_interval_count']
			: 1;
		$this->activation_limit       = isset( $data['activation_limit'] )
			? (int) $data['activation_limit']
			: 1;
		$this->status                 = isset( $data['status'] )
			? (string) $data['status']
			: self::STATUS_ACTIVE;
		$this->created_at              = isset( $data['created_at'] ) ? (string) $data['created_at'] : '';
		$this->updated_at              = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : '';
	}

	/**
	 * Validate the plan.
	 *
	 * @return true|\WP_Error
	 */
	public function validate() {
		$errors = new \WP_Error();

		if ( $this->product_id <= 0 ) {
			$errors->add(
				'dropkey_plan_product_required',
				__( 'A valid product is required.', 'dropkey-wp' )
			);
		}

		if ( '' === trim( $this->name ) ) {
			$errors->add(
				'dropkey_plan_name_required',
				__( 'Plan name is required.', 'dropkey-wp' )
			);
		}

		if ( '' === trim( $this->slug ) ) {
			$errors->add(
				'dropkey_plan_slug_required',
				__( 'Plan slug is required.', 'dropkey-wp' )
			);
		}

		if ( ! preg_match( '/^\d+(?:\.\d{1,4})?$/', $this->price ) ) {
			$errors->add(
				'dropkey_plan_price_invalid',
				__( 'Plan price must be a valid non-negative amount.', 'dropkey-wp' )
			);
		}

		if ( ! preg_match( '/^[A-Z]{3}$/', $this->currency ) ) {
			$errors->add(
				'dropkey_plan_currency_invalid',
				__( 'Currency must be a valid three-letter currency code.', 'dropkey-wp' )
			);
		}

		if ( ! in_array(
			$this->billing_interval,
			array(
				self::INTERVAL_DAY,
				self::INTERVAL_WEEK,
				self::INTERVAL_MONTH,
				self::INTERVAL_YEAR,
			),
			true
		) ) {
			$errors->add(
				'dropkey_plan_billing_interval_invalid',
				__( 'Billing interval is invalid.', 'dropkey-wp' )
			);
		}

		if ( $this->billing_interval_count < 1 ) {
			$errors->add(
				'dropkey_plan_billing_interval_count_invalid',
				__( 'Billing interval count must be at least 1.', 'dropkey-wp' )
			);
		}

		if ( $this->activation_limit < 1 ) {
			$errors->add(
				'dropkey_plan_activation_limit_invalid',
				__( 'Activation limit must be at least 1.', 'dropkey-wp' )
			);
		}

		if ( ! in_array(
			$this->status,
			array(
				self::STATUS_ACTIVE,
				self::STATUS_ARCHIVED,
			),
			true
		) ) {
			$errors->add(
				'dropkey_plan_status_invalid',
				__( 'Plan status is invalid.', 'dropkey-wp' )
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

	public function get_product_id() {
		return $this->product_id;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_slug() {
		return $this->slug;
	}

	public function get_price() {
		return $this->price;
	}

	public function get_currency() {
		return $this->currency;
	}

	public function get_billing_interval() {
		return $this->billing_interval;
	}

	public function get_billing_interval_count() {
		return $this->billing_interval_count;
	}

	public function get_activation_limit() {
		return $this->activation_limit;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_created_at() {
		return $this->created_at;
	}

	public function get_updated_at() {
		return $this->updated_at;
	}
}