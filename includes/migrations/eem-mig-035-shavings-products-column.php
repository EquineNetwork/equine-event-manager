<?php
/**
 * Migration 035: additional_shavings_products column on wp_eem_reservation_config.
 *
 * The Additional Shavings feature (2.7.521) stores admin-defined shavings
 * product types as JSON in `additional_shavings_products`. The config table
 * schema was updated to declare the column; this migration adds it to existing
 * installs via ALTER TABLE so dbDelta doesn't need to run.
 *
 * Idempotent: column added only when absent.
 *
 * @package EEM_Plugin
 * @since   2.7.522
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run migration 035.
 *
 * @return void
 */
function eem_mig_035_shavings_products_column(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'eem_reservation_config';

	// Table may not exist on fresh installs (dbDelta handles it) — skip silently.
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
	if ( $exists !== $table ) {
		update_option( 'eem_mig_035_shavings_products_column_complete', 1, false );
		return;
	}

	$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB
	if ( ! in_array( 'additional_shavings_products', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN additional_shavings_products text NULL DEFAULT NULL AFTER additional_shavings_price" ); // phpcs:ignore WordPress.DB
	}

	update_option( 'eem_mig_035_shavings_products_column_complete', 1, false );
}
