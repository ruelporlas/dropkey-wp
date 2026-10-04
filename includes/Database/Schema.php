<?php
/**
 * DropKey WP database schema.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const VERSION = 3;

	public static function get_tables( \wpdb $wpdb ) {
		$charset_collate = $wpdb->get_charset_collate();

		return array(
			"CREATE TABLE {$wpdb->prefix}dropkey_products (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				type varchar(50) NOT NULL,
				description longtext DEFAULT NULL,
				status varchar(20) NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY status (status),
				KEY type (type)
			) {$charset_collate};",

			"CREATE TABLE {$wpdb->prefix}dropkey_plans (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				product_id bigint(20) unsigned NOT NULL,
				name varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				price decimal(19,4) NOT NULL,
				currency char(3) NOT NULL,
				billing_interval varchar(20) NOT NULL,
				billing_interval_count int(10) unsigned NOT NULL,
				activation_limit int(10) unsigned NOT NULL,
				status varchar(20) NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY product_slug (product_id,slug),
				KEY product_id (product_id),
				KEY status (status)
			) {$charset_collate};",

			"CREATE TABLE {$wpdb->prefix}dropkey_customers (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				email varchar(191) NOT NULL,
				first_name varchar(100) NOT NULL,
				last_name varchar(100) NOT NULL,
				status varchar(20) NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_id (user_id),
				KEY email (email),
				KEY status (status)
			) {$charset_collate};",

			"CREATE TABLE {$wpdb->prefix}dropkey_subscriptions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				customer_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				plan_id bigint(20) unsigned NOT NULL,
				gateway varchar(50) NOT NULL,
				gateway_subscription_id varchar(191) NOT NULL,
				status varchar(20) NOT NULL,
				current_period_start datetime DEFAULT NULL,
				current_period_end datetime DEFAULT NULL,
				cancel_at_period_end tinyint(1) unsigned NOT NULL DEFAULT 0,
				cancelled_at datetime DEFAULT NULL,
				past_due_at datetime DEFAULT NULL,
				ended_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY gateway_subscription (gateway,gateway_subscription_id),
				KEY customer_id (customer_id),
				KEY product_id (product_id),
				KEY plan_id (plan_id),
				KEY status (status)
			) {$charset_collate};",

			"CREATE TABLE {$wpdb->prefix}dropkey_licenses (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				customer_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				subscription_id bigint(20) unsigned NOT NULL,
				license_key varchar(100) NOT NULL,
				status varchar(20) NOT NULL,
				activation_limit int(10) unsigned NOT NULL,
				expires_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY license_key (license_key),
				UNIQUE KEY subscription_id (subscription_id),
				KEY customer_id (customer_id),
				KEY product_id (product_id),
				KEY status (status)
			) {$charset_collate};",

			"CREATE TABLE {$wpdb->prefix}dropkey_activations (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				site_url varchar(255) NOT NULL,
				site_identifier char(64) NOT NULL,
				api_token_hash char(64) DEFAULT NULL,
				status varchar(20) NOT NULL,
				activated_at datetime DEFAULT NULL,
				last_validated_at datetime DEFAULT NULL,
				deactivated_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY license_site (license_id,site_identifier),
				KEY license_status (license_id,status),
				KEY token_hash (api_token_hash)
			) {$charset_collate};",

			"CREATE TABLE {$wpdb->prefix}dropkey_gateway_mappings (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				gateway varchar(50) NOT NULL,
				entity_type varchar(50) NOT NULL,
				entity_id bigint(20) unsigned NOT NULL,
				external_type varchar(50) NOT NULL,
				external_id varchar(191) NOT NULL,
				metadata longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY entity_mapping (gateway,entity_type,entity_id,external_type),
				UNIQUE KEY external_mapping (gateway,external_type,external_id),
				KEY entity_id (entity_id)
			) {$charset_collate};",

			"CREATE TABLE {$wpdb->prefix}dropkey_gateway_events (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				gateway varchar(50) NOT NULL,
				event_id varchar(191) NOT NULL,
				event_type varchar(100) NOT NULL,
				status varchar(20) NOT NULL,
				payload_hash char(64) NOT NULL,
				payload longtext NOT NULL,
				processed_at datetime DEFAULT NULL,
				error_message text DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY gateway_event (gateway,event_id),
				KEY status (status),
				KEY event_type (event_type)
			) {$charset_collate};",

			"CREATE TABLE {$wpdb->prefix}dropkey_subscription_events (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				subscription_id bigint(20) unsigned NOT NULL,
				event_type varchar(50) NOT NULL,
				previous_status varchar(20) NOT NULL,
				new_status varchar(20) NOT NULL,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY subscription_id (subscription_id),
				KEY event_type (event_type),
				KEY actor_user_id (actor_user_id),
				KEY created_at (created_at)
			) {$charset_collate};",
		);
	}
}