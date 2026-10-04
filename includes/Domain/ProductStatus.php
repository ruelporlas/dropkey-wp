<?php
/**
 * Product status definitions.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Domain;

defined( 'ABSPATH' ) || exit;

final class ProductStatus {

	public const ACTIVE   = 'active';
	public const ARCHIVED = 'archived';

	/**
	 * Get all valid product statuses.
	 *
	 * @return array<string>
	 */
	public static function all() {
		return array(
			self::ACTIVE,
			self::ARCHIVED,
		);
	}

	/**
	 * Determine whether a status is valid.
	 *
	 * @param string $status Product status.
	 * @return bool
	 */
	public static function is_valid( $status ) {
		return in_array( $status, self::all(), true );
	}
}