<?php
/**
 * C15.C-a smoke — export dispatch (generate_export: report -> CSV -> cache -> log).
 *
 * Run: wp eval-file tests/smoke/c15c-export-dispatch-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

// Endpoints registered.
$check( 'admin_post_eem_reports_export registered', has_action( 'admin_post_eem_reports_export' ) !== false );
$check( 'admin_post_eem_reports_download registered', has_action( 'admin_post_eem_reports_download' ) !== false );

// read_filters sanitization.
$f = EEM_Reports_Page::read_filters( array( 'reservation_id' => '12abc', 'date_from' => '2026-04-01', 'date_to' => '2026-04-30', 'status' => 'Refunded' ) );
$check( 'read_filters casts reservation_id', 12 === $f['reservation_id'] );
$check( 'read_filters lowercases status', 'refunded' === $f['status'] );

// generate_export (CSV) for each report slug → cached file exists + is CSV.
$exporter = new EEM_Report_Exporter();
$made     = array();
foreach ( EEM_Reports_Repo::REPORTS as $slug ) {
	$res = EEM_Reports_Page::generate_export( $slug, array(), 'csv' );
	$ok  = is_array( $res ) && isset( $res['path'] ) && is_readable( $res['path'] );
	$check( "generate_export('{$slug}') wrote a cached CSV", $ok );
	if ( $ok ) {
		$made[] = $res['path'];
		$head   = substr( (string) file_get_contents( $res['path'] ), 0, 3 );
		$check( "  '{$slug}' file is BOM-prefixed CSV", "\xEF\xBB\xBF" === $head );
	}
}

// Bad slug + pending format degrade to WP_Error, not fatal.
$check( 'unknown slug returns WP_Error', is_wp_error( EEM_Reports_Page::generate_export( 'nope', array(), 'csv' ) ) );
$check( 'pdf format returns WP_Error (pending C15.E)', is_wp_error( EEM_Reports_Page::generate_export( 'orders', array(), 'pdf' ) ) );

// Export was logged to the history table.
global $wpdb;
$logged = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}en_report_exports WHERE export_scope = 'all'" );
$check( 'export-history rows were written', $logged >= 1 );

// Cleanup the cached files we created.
foreach ( $made as $p ) { @unlink( $p ); }

WP_CLI::log( "\n=== C15.C-a export dispatch smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C15.C-a export dispatch smoke passed.' );
