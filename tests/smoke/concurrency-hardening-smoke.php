<?php
/**
 * Concurrency-hardening smoke (ship-readiness 2.2 + 2.3).
 *
 * 2.2 (#29) — reserve_order_number() allocates strictly-increasing, unique numbers
 *   behind a MySQL advisory lock (no two callers get the same number).
 * 2.3 (#30) — EEM_Stall_Status_Repo::units_occupied_in_window() detects when a
 *   requested stall is already occupied for an overlapping window, so the admin
 *   quick-add can reject a double-book before assigning.
 *
 * Run via: wp eval-file tests/smoke/concurrency-hardening-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;
$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

if ( ! class_exists( 'EEM_Orders_Repository' ) || ! class_exists( 'EEM_Stall_Status_Repo' ) ) {
	echo "  FAIL — required classes missing\n0 passed, 1 failed\n";
	return;
}

/* ---- 2.2: order-number allocation is sequential + unique ---- */
$repo    = new EEM_Orders_Repository();
$numbers = array();
for ( $i = 0; $i < 6; $i++ ) {
	$numbers[] = (int) $repo->reserve_order_number();
}
$unique     = array_unique( $numbers );
$sequential = true;
for ( $i = 1; $i < count( $numbers ); $i++ ) {
	if ( $numbers[ $i ] !== $numbers[ $i - 1 ] + 1 ) { $sequential = false; }
}
$chk( count( $unique ) === count( $numbers ), 'reserve_order_number() returns all-unique numbers (' . implode( ',', $numbers ) . ')' );
$chk( $sequential, 'reserve_order_number() increments by exactly 1 each call' );

/* ---- 2.3: occupancy conflict detection ---- */
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'Occupancy Smoke', 'post_status' => 'publish' ) );
$fake_order_id = 990001;
EEM_Stall_Status_Repo::delete_for_order( $fake_order_id );
EEM_Stall_Status_Repo::create_occupied( $rid, $fake_order_id, array( 'A1', 'A2' ), '2026-09-01', '2026-09-03' );

$conflict = EEM_Stall_Status_Repo::units_occupied_in_window( $rid, array( 'A1', 'A3' ), '2026-09-01', '2026-09-03' );
$chk( in_array( 'A1', $conflict, true ) && ! in_array( 'A3', $conflict, true ), 'occupied A1 flagged, free A3 not — overlapping window' );

$free = EEM_Stall_Status_Repo::units_occupied_in_window( $rid, array( 'A3', 'A4' ), '2026-09-01', '2026-09-03' );
$chk( empty( $free ), 'all-free units return no conflict' );

$other_window = EEM_Stall_Status_Repo::units_occupied_in_window( $rid, array( 'A1' ), '2026-09-10', '2026-09-12' );
$chk( empty( $other_window ), 'A1 is free in a NON-overlapping window' );

$partial_overlap = EEM_Stall_Status_Repo::units_occupied_in_window( $rid, array( 'A1' ), '2026-09-02', '2026-09-05' );
$chk( in_array( 'A1', $partial_overlap, true ), 'A1 flagged on a PARTIALLY overlapping window (shares night 9/02)' );

// Cleanup.
EEM_Stall_Status_Repo::delete_for_order( $fake_order_id );
wp_delete_post( $rid, true );

echo "\n$pass passed, $fail failed\n";
