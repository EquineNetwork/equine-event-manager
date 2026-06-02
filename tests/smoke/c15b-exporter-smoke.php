<?php
/**
 * C15.B smoke — report exporter (CSV build + cache write/read + 30-day purge).
 *
 * Run: wp eval-file tests/smoke/c15b-exporter-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$ex = new EEM_Report_Exporter();

// ── CSV build ──
$report = array(
	'headers' => array( 'Order #', 'Customer', 'Total' ),
	'rows'    => array(
		array( '#00042', 'Whitney Mitchell', '208.66' ),
		array( '#00043', 'Bob, Jr. "Tex"', '50.00' ), // comma + quotes force CSV quoting
	),
);
$csv = $ex->build_csv( $report );
$check( 'CSV starts with UTF-8 BOM', "\xEF\xBB\xBF" === substr( $csv, 0, 3 ) );
$check( 'CSV has the header row', false !== strpos( $csv, 'Order #' ) && false !== strpos( $csv, 'Customer' ) && false !== strpos( $csv, 'Total' ) );
$check( 'CSV quotes a field containing comma + quotes', false !== strpos( $csv, '"Bob, Jr. ""Tex"""' ) );
$check( 'CSV has both data rows', 2 === substr_count( $csv, "\n" ) - 0 || substr_count( $csv, '#0004' ) === 2 );

// ── Filename convention ──
$fn_all = $ex->export_filename( 'orders', 0, 'csv', '20260424' );
$fn_res = $ex->export_filename( 'revenue', 305, 'pdf', '20260424' );
$check( 'filename (all scope): eem-orders-all-20260424.csv', 'eem-orders-all-20260424.csv' === $fn_all );
$check( 'filename (reservation scope): eem-revenue-305-20260424.pdf', 'eem-revenue-305-20260424.pdf' === $fn_res );

// ── Cache dir creation + protection ──
$dir = $ex->cache_dir();
$check( 'cache_dir created', '' !== $dir && is_dir( $dir ) );
$check( 'cache_dir has deny-all .htaccess', is_readable( $dir . '.htaccess' ) && false !== strpos( (string) file_get_contents( $dir . '.htaccess' ), 'Deny from all' ) );
$check( 'cache_dir has index.html', file_exists( $dir . 'index.html' ) );

// ── Write + read ──
$fname = $ex->export_filename( 'orders', 0, 'csv', current_time( 'Ymd' ) );
$path  = $ex->write_to_cache( $fname, $csv );
$check( 'write_to_cache returns a path', '' !== $path && is_readable( $path ) );
$check( 'cached_exists true after write', $ex->cached_exists( $fname ) );
$check( 'cached_path resolves to the written file', $ex->cached_path( $fname ) === $path );

// ── Purge: an old file is removed, a fresh one survives ──
$old_name  = 'eem-orders-all-20200101.csv';
$old_path  = $ex->write_to_cache( $old_name, 'old' );
touch( $old_path, time() - ( 40 * DAY_IN_SECONDS ) ); // older than 30-day window
$deleted   = $ex->purge_old( 30 );
$check( 'purge_old removed the >30-day-old file', $deleted >= 1 && ! file_exists( $old_path ) );
$check( 'purge_old kept the fresh file', $ex->cached_exists( $fname ) );

// ── Cleanup ──
@unlink( $path );

WP_CLI::log( "\n=== C15.B exporter smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C15.B exporter smoke passed.' );
