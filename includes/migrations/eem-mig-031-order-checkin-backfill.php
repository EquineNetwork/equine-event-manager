<?php
/**
 * Migration #031 — backfill the per-ORDER customer check-in store
 * (`wp_eem_order_checkin`) from the legacy per-stall-night check-in data in
 * `wp_eem_stall_status`.
 *
 * The check-in model moved from "one status per stall per night" to "one status
 * per customer (order), covering their whole party — stalls AND RV" so RV-only
 * customers can be checked in too, and so a single value drives both the Daily
 * Movement page and the Stall Charts By-Customer table. This migration carries
 * any check-in state staff already set under the old model into the new store so
 * nothing is silently reset to Pending Arrival.
 *
 * Mapping: the order's effective (MIN across nights) legacy status maps to the
 * three customer statuses — occupied → occupied (Pending Arrival), checked_in →
 * checked_in, and checked_out / needs_cleaning / clean → checked_out (the
 * customer has left). The legacy store keys on order_id = wp_eem_stall_reservations.id,
 * so we resolve each to its (reservation_id, order_number) to key the new store.
 *
 * Idempotent / flag-gated. Only the new store is written; the legacy tables are
 * left untouched.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill wp_eem_order_checkin from the legacy stall-status data.
 *
 * @return array{written:int} Count of order rows written (for verification).
 */
function eem_mig_031_order_checkin_backfill() {
	global $wpdb;

	$flag = 'eem_mig_031_order_checkin_backfill_complete';
	if ( get_option( $flag ) ) {
		return array( 'written' => 0 );
	}

	$status_table  = $wpdb->prefix . 'eem_stall_status';
	$checkin_table = $wpdb->prefix . 'eem_order_checkin';
	$sr_table      = $wpdb->prefix . 'eem_stall_reservations';

	// Guard: both tables must exist (create_tables runs first via activate()).
	$have_status  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $status_table ) );
	$have_checkin = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $checkin_table ) );
	if ( ! $have_status || ! $have_checkin ) {
		update_option( $flag, time() );
		return array( 'written' => 0 );
	}

	// Effective legacy status per order (MIN across the order's nights), joined to
	// the stall reservation row for its reservation_id + order_number.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix; no user input.
	$rows = $wpdb->get_results(
		"SELECT sr.reservation_id AS reservation_id, sr.order_number AS order_number,
		        MIN(ss.status) AS eff_status
		 FROM {$status_table} ss
		 INNER JOIN {$sr_table} sr ON sr.id = ss.order_id
		 WHERE sr.order_number <> ''
		 GROUP BY sr.reservation_id, sr.order_number",
		ARRAY_A
	);

	$map = array(
		'occupied'       => 'occupied',
		'checked_in'     => 'checked_in',
		'checked_out'    => 'checked_out',
		'needs_cleaning' => 'checked_out',
		'clean'          => 'checked_out',
	);

	$written = 0;
	foreach ( (array) $rows as $row ) {
		$status = $map[ (string) $row['eff_status'] ] ?? 'occupied';
		// Only the "moved past Pending Arrival" states are worth carrying over.
		if ( 'occupied' === $status ) {
			continue;
		}
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$checkin_table} ( reservation_id, order_number, status, updated_by, updated_at )
				 VALUES ( %d, %s, %s, %d, %s )
				 ON DUPLICATE KEY UPDATE status = VALUES(status)",
				(int) $row['reservation_id'],
				(string) $row['order_number'],
				$status,
				0,
				current_time( 'mysql' )
			)
		);
		$written++;
	}

	update_option( $flag, time() );
	return array( 'written' => $written );
}
