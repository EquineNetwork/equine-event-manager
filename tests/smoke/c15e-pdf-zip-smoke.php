<?php
/**
 * C15.E smoke — report PDF + ZIP export.
 *
 * Run: wp eval-file tests/smoke/c15e-pdf-zip-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$exporter = new EEM_Report_Exporter();
$cleanup  = array();

// ── PDF export of a single report ──
$pdf_res = EEM_Reports_Page::generate_export( 'orders', array(), 'pdf' );
$check( 'generate_export(orders, pdf) succeeded', is_array( $pdf_res ) && isset( $pdf_res['path'] ) );
if ( is_array( $pdf_res ) ) {
	$cleanup[] = $pdf_res['path'];
	$bytes     = (string) file_get_contents( $pdf_res['path'] );
	$check( 'PDF file is a valid %PDF stream', '%PDF' === substr( $bytes, 0, 4 ) );
	$check( 'PDF filename ends .pdf', '.pdf' === substr( $pdf_res['filename'], -4 ) );
}

// ── build_pdf with empty rows still renders a valid PDF (no-data card) ──
$empty_pdf = $exporter->build_pdf( array( 'title' => 'Empty', 'headers' => array( 'A' ), 'rows' => array() ) );
$check( 'build_pdf with no rows returns valid %PDF', '%PDF' === substr( $empty_pdf, 0, 4 ) );

// ── ZIP export (all 6 reports × CSV + PDF) ──
if ( $exporter->zip_available() ) {
	$zip_res = EEM_Reports_Page::generate_export( '', array(), 'zip' );
	$check( 'generate_export(zip) succeeded', is_array( $zip_res ) && isset( $zip_res['path'] ) );
	if ( is_array( $zip_res ) ) {
		$cleanup[] = $zip_res['path'];
		$zbytes    = (string) file_get_contents( $zip_res['path'] );
		$check( 'ZIP file has PK magic bytes', 'PK' === substr( $zbytes, 0, 2 ) );
		$check( 'ZIP filename ends .zip', '.zip' === substr( $zip_res['filename'], -4 ) );

		$za = new ZipArchive();
		if ( true === $za->open( $zip_res['path'] ) ) {
			$count = $za->numFiles;
			$has_orders_csv = false !== $za->locateName( 'orders.csv' );
			$has_orders_pdf = false !== $za->locateName( 'orders.pdf' );
			$has_refund_csv = false !== $za->locateName( 'refund_log.csv' );
			$za->close();
			$check( 'ZIP contains 12 entries (6 CSV + 6 PDF)', 12 === $count );
			$check( 'ZIP contains orders.csv + orders.pdf', $has_orders_csv && $has_orders_pdf );
			$check( 'ZIP contains refund_log.csv', $has_refund_csv );
		} else {
			$check( 'ZIP opens with ZipArchive', false );
		}
	}
} else {
	WP_CLI::warning( 'ZipArchive not available — ZIP assertions skipped (graceful degrade verified below).' );
	$check( 'generate_export(zip) WP_Errors gracefully without ZipArchive', is_wp_error( EEM_Reports_Page::generate_export( '', array(), 'zip' ) ) );
}

// ── Cleanup ──
foreach ( $cleanup as $p ) { @unlink( $p ); }

WP_CLI::log( "\n=== C15.E PDF/ZIP smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C15.E PDF/ZIP smoke passed.' );
