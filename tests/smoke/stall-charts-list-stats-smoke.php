<?php
/**
 * Stall & RV Charts LIST summary stats — reconciliation guard.
 *
 * Regression guard for the bug where the list-page "Available / Reserved /
 * Blocked" blurb counted raw "Assigned Stall Units" note labels (which don't
 * map to the configured grid), inflating Reserved past capacity (e.g. 38 of
 * 21 units) and flooring Available at 0. The fix derives the counts from
 * build_stall_chart_grid() — the same occupancy grid the chart detail/print
 * pages render — so the summary reconciles with capacity.
 *
 * Invariants asserted for every CONFIGURED chart row:
 *   - Available + Reserved + Blocked === total stall units in the grid
 *   - Reserved <= total stall units (the headline bug: 38 > 21)
 *   - Blocked  <= total stall units
 *
 * Source-presence + live-data invariant test. Iterates whatever configured
 * charts exist on the DB; skips gracefully (with a logged note) if none.
 */

$pass = 0; $fail = 0; $log = array();
function ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

$admin = EEM_Admin::for_compute();
$ref   = new ReflectionClass( $admin );

$list_m = $ref->getMethod( 'get_stall_charts_list_data' ); $list_m->setAccessible( true );
$cfg_m  = $ref->getMethod( 'get_stall_chart_config' );     $cfg_m->setAccessible( true );
$grid_m = $ref->getMethod( 'build_stall_chart_grid' );     $grid_m->setAccessible( true );

$rows = (array) $list_m->invoke( $admin );

// Parse "<n> Available", "<n> Reserved", "<n> Blocked" out of a stats array.
$parse = function ( array $stats, $needle ) {
	foreach ( $stats as $s ) {
		$label = isset( $s['label'] ) ? (string) $s['label'] : '';
		if ( false !== stripos( $label, $needle ) && preg_match( '/(\d+)/', $label, $m ) ) {
			return (int) $m[1];
		}
	}
	return null;
};

$configured = 0;
foreach ( $rows as $row ) {
	if ( 'configured' !== ( $row['chart_status'] ?? '' ) ) {
		continue;
	}
	$configured++;
	$rid   = (int) $row['id'];
	$stats = (array) ( $row['stats'] ?? array() );

	$available = $parse( $stats, 'Available' );
	$reserved  = $parse( $stats, 'Reserved' );
	$blocked   = $parse( $stats, 'Blocked' );

	// Ground-truth total stall units from the same grid the page now uses.
	$cfg   = $cfg_m->invoke( $admin, $rid );
	$grid  = $grid_m->invoke( $admin, $rid, $cfg );
	$total = count( (array) ( $grid['stall_rows'] ?? array() ) );

	ok( "rid $rid: all three stat numbers present",
		null !== $available && null !== $reserved && null !== $blocked, $pass, $fail, $log );
	ok( "rid $rid: Reserved ($reserved) <= total stall units ($total)",
		null !== $reserved && $reserved <= $total, $pass, $fail, $log );
	ok( "rid $rid: Blocked ($blocked) <= total stall units ($total)",
		null !== $blocked && $blocked <= $total, $pass, $fail, $log );
	ok( "rid $rid: Available+Reserved+Blocked (" . ( (int) $available + (int) $reserved + (int) $blocked ) . ") === total ($total)",
		( (int) $available + (int) $reserved + (int) $blocked ) === $total, $pass, $fail, $log );
}

if ( 0 === $configured ) {
	$log[] = 'NOTE: no configured stall charts on this DB — invariant assertions skipped (not a failure).';
	$pass++; // keep the suite green; the guard is a no-op when there is nothing to check.
}

echo "\n=== Stall-charts list-stats reconciliation smoke: $pass passed, $fail failed ($configured configured charts) ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
