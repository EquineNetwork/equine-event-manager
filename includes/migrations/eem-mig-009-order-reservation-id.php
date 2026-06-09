<?php
/**
 * Migration #009 — backfill the denormalized `reservation_id` column on the
 * stall + RV order tables (CLEANUP #11).
 *
 * The order tables have carried a `reservation_id` column (schema DEFAULT 0)
 * since C12, but the value was only ever recorded inside the `notes` text as a
 * `Reservation setup ID: N` line. Checkout inserts now write the column directly;
 * this migration extracts the id from the notes for every pre-existing row so the
 * Reservations-list orders-count + sort can run as an indexed
 * `WHERE reservation_id = N` query / SQL JOIN instead of a `notes LIKE` scan.
 *
 * Idempotent / flag-gated: only touches rows where `reservation_id <= 0` AND the
 * notes carry a setup id. PHP-side parse (order-table row counts are bounded —
 * one row per stall/RV component, not per unit).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill `reservation_id` on both order tables from the notes setup-id line.
 *
 * @return array{updated:int} Count of rows updated (for telemetry/verification).
 */
function eem_mig_009_order_reservation_id() {
	global $wpdb;

	$flag = 'eem_mig_009_order_reservation_id_complete';
	if ( get_option( $flag ) ) {
		return array( 'updated' => 0 );
	}

	$updated = 0;

	foreach ( array( 'en_stall_reservations', 'en_rv_reservations' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;

		// Guard: table + column must exist.
		$has_col = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'reservation_id'",
				$table
			)
		);
		if ( ! $has_col ) {
			continue;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from a known suffix.
		$rows = $wpdb->get_results(
			"SELECT id, notes FROM `{$table}` WHERE ( reservation_id IS NULL OR reservation_id <= 0 ) AND notes LIKE '%Reservation setup ID:%'",
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			if ( preg_match( '/Reservation setup ID:\s*(\d+)/i', (string) $row['notes'], $m ) ) {
				$rid = absint( $m[1] );
				if ( $rid > 0 ) {
					$wpdb->update( $table, array( 'reservation_id' => $rid ), array( 'id' => (int) $row['id'] ), array( '%d' ), array( '%d' ) );
					$updated++;
				}
			}
		}
	}

	update_option( $flag, time() );
	return array( 'updated' => $updated );
}
