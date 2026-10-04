<?php
/**
 * Plugin Name: DropKey WP
 * Plugin URI: https://dropkeywp.com/
 * Description: Digital product subscription and licensing for WordPress.
 * Version: 0.1.0
 * Author: DropKey WP
 * Author URI: https://dropkeywp.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dropkey-wp
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * @package DropKeyWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * DropKey WP version.
 */
define( 'DROPKEY_WP_VERSION', '0.1.0' );

/**
 * DropKey WP plugin file.
 */
define( 'DROPKEY_WP_FILE', __FILE__ );

/**
 * DropKey WP plugin directory.
 */
define( 'DROPKEY_WP_DIR', plugin_dir_path( __FILE__ ) );

/**
 * DropKey WP plugin URL.
 */
define( 'DROPKEY_WP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload DropKey WP classes.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
function dropkey_wp_autoload( $class ) {
	$prefix   = 'DropKeyWP\\';
	$base_dir = __DIR__ . '/includes/';

	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}

	$relative_class = substr( $class, strlen( $prefix ) );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

spl_autoload_register( 'dropkey_wp_autoload' );

/*
 * Load the main plugin class explicitly.
 *
 * Invalidate a potentially stale OPcache entry first. This is
 * particularly important on hosts where OPcache timestamp validation
 * may be disabled.
 */
$dropkey_wp_plugin_class_file = __DIR__ . '/includes/Plugin.php';

if (
	function_exists( 'opcache_invalidate' )
	&& file_exists( $dropkey_wp_plugin_class_file )
) {
	opcache_invalidate( $dropkey_wp_plugin_class_file, true );
}

require_once $dropkey_wp_plugin_class_file;

/**
 * Install the database when the plugin is activated.
 *
 * @return void
 */
function dropkey_wp_activate() {
	\DropKeyWP\Database\Installer::install();
}

register_activation_hook( __FILE__, 'dropkey_wp_activate' );

/**
 * Bootstrap DropKey WP.
 *
 * @return void
 */
function dropkey_wp_bootstrap() {
	\DropKeyWP\Plugin::instance()->boot();
}

add_action( 'plugins_loaded', 'dropkey_wp_bootstrap' );