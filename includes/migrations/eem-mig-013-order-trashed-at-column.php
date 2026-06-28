<?php
/**
 * Migration #013 — add the `trashed_at` soft-delete column to both order tables
 * (eem_stall_reservations / eem_rv_reservations) for the Orders Trash lifecycle
 * (v1 #9).
 *
 * Orders are stored as component rows in the two reservation tables; a soft
 * delete sets `trashed_at = NOW()` on every component of an order. The orders
 * list excludes trashed orders by default and surfaces them under a Trash tab
 * with Restore / Delete Permanently actions.
 *
 * Idempotent / flag-gated: only adds the column when it doesn't already exist
 * (new installs get it from create_reservation_tables()).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add `trashed_at datetime DEFAULT NULL` to the stall + RV order tables.
 *
 * @return array{added:int} Count of tables the column was added to.
 */
function eem_mig_013_order_trashed_at_column() {
	global $wpdb;

	$flag = 'eem_mig_013_order_trashed_at_column_complete';
	if ( get_option( $flag ) ) {
		return array( 'added' => 0 );
	}

	$added = 0;

	foreach ( array( 'eem_stall_reservations', 'eem_rv_reservations' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;

		$has_col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'trashed_at'",
				$table
			)
		);
		if ( $has_col ) {
			continue;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from a known suffix; no user input.
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `trashed_at` datetime DEFAULT NULL AFTER `refunded_at`" );
		$added++;
	}

	update_option( $flag, time() );
	return array( 'added' => $added );
}
