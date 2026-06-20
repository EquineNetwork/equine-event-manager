<?php
/**
 * Migration 032: per-package Early Bird price.
 *
 * Adds an `early_bird_price` column to wp_eem_stay_packages so each stay package
 * can offer a discounted price during the reservation's Early Bird window
 * (Whitney 2026-06-19). NULL = no early-bird price (falls back to the regular
 * package price even inside the early-bird window).
 *
 * Idempotent: column added only when absent.
 *
 * @package EEM_Plugin
 * @since   2.7.507
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run migration 032.
 *
 * @return void
 */
function eem_mig_032_package_early_bird_price(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'eem_stay_packages';
	$cols  = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB

	if ( ! in_array( 'early_bird_price', (array) $cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN early_bird_price decimal(10,2) NULL DEFAULT NULL AFTER price" ); // phpcs:ignore WordPress.DB
	}

	update_option( 'eem_mig_032_package_early_bird_price_complete', 1, false );
}
