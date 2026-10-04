<?php
/**
 * DropKey WP database installer.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Database;

defined( 'ABSPATH' ) || exit;

final class Installer {

	private const VERSION_OPTION = 'dropkey_wp_db_version';

	/**
	 * Install or upgrade the database schema.
	 *
	 * @return void
	 */
	public static function install() {
		$current_version = self::get_version();

		if ( $current_version >= Schema::VERSION ) {
			return;
		}

		self::run_schema();

		update_option(
			self::VERSION_OPTION,
			Schema::VERSION,
			false
		);
	}

	/**
	 * Run the database schema through dbDelta.
	 *
	 * @return void
	 */
	private static function run_schema() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::get_tables( $wpdb ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Get the installed database schema version.
	 *
	 * @return int
	 */
	public static function get_version() {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}
}