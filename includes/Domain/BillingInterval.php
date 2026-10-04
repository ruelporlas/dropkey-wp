<?php
/**
 * Billing interval definitions.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Domain;

defined( 'ABSPATH' ) || exit;

final class BillingInterval {

	public const DAY   = 'day';
	public const WEEK  = 'week';
	public const MONTH = 'month';
	public const YEAR  = 'year';

	/**
	 * Get all valid billing intervals.
	 *
	 * @return array<string>
	 */
	public static function all() {
		return array(
			self::DAY,
			self::WEEK,
			self::MONTH,
			self::YEAR,
		);
	}

	/**
	 * Determine whether an interval is valid.
	 *
	 * @param string $interval Billing interval.
	 * @return bool
	 */
	public static function is_valid( $interval ) {
		return in_array( $interval, self::all(), true );
	}
}