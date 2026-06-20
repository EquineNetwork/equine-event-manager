<?php
/**
 * Migration 034: per-type additional shavings items column.
 *
 * Adds `additional_shavings_items` TEXT column to `wp_en_stall_reservations`
 * to store a JSON breakdown of additional shavings bags sold by product type
 * (e.g. [{"name":"Large Flake","qty":3,"price":12.00,"subtotal":36.00}]).
 *
 * The existing `additional_shavings_qty` and `additional_shavings_subtotal`
 * columns stay intact for backward compatibility and hold the aggregated
 * totals; this column adds the per-type detail needed for the Shavings report.
 *
 * Idempotent: column added only when absent.
 *
 * @package EEM_Plugin
 * @since   2.7.521
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run migration 034.
 *
 * @return void
 */
function eem_mig_034_additional_shavings_items(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'en_stall_reservations';
	$cols  = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB

	if ( ! in_array( 'additional_shavings_items', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN additional_shavings_items TEXT NULL DEFAULT NULL AFTER additional_shavings_qty" ); // phpcs:ignore WordPress.DB
	}

	update_option( 'eem_mig_034_additional_shavings_items_complete', 1, false );
}
