<?php
/**
 * Product type definitions.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Domain;

defined( 'ABSPATH' ) || exit;

final class ProductType {

	public const WORDPRESS_PLUGIN = 'wordpress_plugin';
	public const WORDPRESS_THEME  = 'wordpress_theme';
	public const DIGITAL_DOWNLOAD = 'digital_download';
	public const OTHER            = 'other';

	/**
	 * Get all valid product types.
	 *
	 * @return array<string>
	 */
	public static function all() {
		return array(
			self::WORDPRESS_PLUGIN,
			self::WORDPRESS_THEME,
			self::DIGITAL_DOWNLOAD,
			self::OTHER,
		);
	}

	/**
	 * Determine whether a product type is valid.
	 *
	 * @param string $type Product type.
	 * @return bool
	 */
	public static function is_valid( $type ) {
		return in_array( $type, self::all(), true );
	}
}