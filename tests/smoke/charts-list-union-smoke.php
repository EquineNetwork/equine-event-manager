<?php
/**
 * Stall & RV Charts list UNION smoke — regression guard for the catastrophic
 * "0 reservations / No stall charts yet" bug.
 *
 * Root cause: EEM_Reservation_Config::with_stalls_or_rv() queried the config
 * table ONLY when it existed. The config table is lazily populated, so a
 * reservation whose enable flags live in postmeta (but has no config row yet)
 * was silently dropped from the charts list once the config table first
 * appeared. Fix: union the authoritative postmeta meta_query with the
 * config-table result.
 *
 * Run via: wp eval-file tests/smoke/charts-list-union-smoke.php
 *
 * @package EEM_Plugin
 */

$pass = 0;
$fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

$chk( EEM_Reservation_Config::table_exists(), 'config table exists (precondition: the state that triggered the bug)' );

// Reservation A: postmeta stalls_enabled=1, NO config row written.
$a = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'UnionA Postmeta-only', 'post_status' => 'publish' ) );
update_post_meta( $a, EEM_Reservations_CPT::section_enabled_meta_key( 'stalls_enabled' ), '1' );

// Reservation B: postmeta rv_enabled via legacy key only.
$b = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'UnionB Legacy-key', 'post_status' => 'publish' ) );
update_post_meta( $b, '_en_rv_enabled', '1' );

// Reservation C: config row only (migrated), no postmeta flag.
$c = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'UnionC Config-only', 'post_status' => 'publish' ) );
EEM_Reservation_Config::flush_cache( $c );
EEM_Reservation_Config::for( $c )->set( 'stalls_enabled', 1 )->save();

// Reservation D: neither — must NOT appear.
$d = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'UnionD None', 'post_status' => 'publish' ) );

$ids = EEM_Reservation_Config::with_stalls_or_rv( array( 'publish', 'draft', 'future', 'pending', 'private' ), 200 );

$chk( in_array( $a, $ids, true ), 'postmeta-only stalls reservation IS listed (the regression)' );
$chk( in_array( $b, $ids, true ), 'legacy-key rv reservation IS listed' );
$chk( in_array( $c, $ids, true ), 'config-row reservation IS listed' );
$chk( ! in_array( $d, $ids, true ), 'reservation with neither flag is NOT listed' );
$chk( count( $ids ) === count( array_unique( $ids ) ), 'result has no duplicate IDs (union dedupe works)' );

foreach ( array( $a, $b, $c, $d ) as $rid ) { wp_delete_post( $rid, true ); }

echo "\nDone. PASS=$pass FAIL=$fail\n";
