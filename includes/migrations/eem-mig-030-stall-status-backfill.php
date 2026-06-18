<?php
/**
 * Migration #030 — backfill `wp_eem_stall_status` 'occupied' rows for every
 * already-assigned stall order that predates the check-in/out feature (#223).
 *
 * The per-stall-per-night status table is normally populated by
 * EEM_Stall_Status_Repo::create_occupied() at assignment-save time. Orders that
 * were assigned before that wiring existed (or seeded directly into
 * wp_en_stall_reservations) have assignments in their notes but NO status rows,
 * so the hotel-style check-in / check-out / needs-cleaning tracker has nothing to
 * transition. This backfill reads each order's `Assigned Stall Units:` note line
 * and creates the missing 'occupied' rows.
 *
 * Reversible + additive: only INSERTs into the new status table (idempotent —
 * create_occupied skips rows that already exist; the flag gates a re-run). No
 * existing data is modified. Operates on the flat wp_en_stall_reservations table
 * (no per-reservation config hydration) so it's safe on large/seeded sites.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create missing 'occupied' status rows for assigned stall orders.
 *
 * @return array{orders:int, rows:int} Orders touched + status rows inserted.
 */
function eem_mig_030_stall_status_backfill() {
	global $wpdb;

	$flag = 'eem_mig_030_stall_status_backfill_complete';
	if ( get_option( $flag ) ) {
		return array( 'orders' => 0, 'rows' => 0 );
	}
	if ( ! class_exists( 'EEM_Stall_Status_Repo' ) ) {
		return array( 'orders' => 0, 'rows' => 0 );
	}

	$table = $wpdb->prefix . 'en_stall_reservations';

	// Assigned, non-trashed stall components with a reservation + a date window.
	$rows = $wpdb->get_results(
		"SELECT id, reservation_id, arrival_date, departure_date, notes
		 FROM `{$table}`
		 WHERE reservation_id IS NOT NULL AND reservation_id > 0
		   AND trashed_at IS NULL
		   AND notes LIKE '%Assigned Stall Units:%'",
		ARRAY_A
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal, no user input.

	$orders = 0;
	$inserted = 0;

	foreach ( (array) $rows as $row ) {
		$notes = (string) $row['notes'];
		if ( ! preg_match( '/Assigned Stall Units:\s*([^\r\n]+)/', $notes, $m ) ) {
			continue;
		}
		$units = array_values( array_filter( array_map( 'trim', explode( ',', $m[1] ) ) ) );
		if ( empty( $units ) ) {
			continue;
		}

		$count = EEM_Stall_Status_Repo::create_occupied(
			(int) $row['reservation_id'],
			(int) $row['id'],
			$units,
			(string) $row['arrival_date'],
			(string) $row['departure_date'],
			0
		);
		if ( $count > 0 ) {
			$orders++;
			$inserted += $count;
		}
	}

	update_option( $flag, time() );
	return array( 'orders' => $orders, 'rows' => $inserted );
}
