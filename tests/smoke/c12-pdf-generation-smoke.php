<?php
/**
 * C12 increment 3 smoke — receipt PDF generation (EEM_PDF + generate_receipt_pdf).
 *
 * Asserts the Dompdf engine is available, generate_receipt_pdf() produces valid
 * %PDF bytes with remote loading disabled, and the PDF-mode HTML embeds the logo
 * as a data: URI (not a remote URL Dompdf can't load).
 *
 * Run: wp eval-file tests/smoke/c12-pdf-generation-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Test' ) );
update_post_meta( $rid, '_en_required_shavings_price', '22.00' );

$order = array(
	'order_key' => 'pdf', 'order_number' => '20', 'created_at' => '2026-04-24 10:30:00',
	'reservation_id' => $rid, 'reservation_title' => '2026 Southeast Region Super Sort',
	'event_name' => '2026 Southeast Region Super Sort', 'event_dates' => 'May 8, 2026 – May 10, 2026',
	'customer_name' => 'Whitney Mitchell', 'email' => 'info@wmpromotions.com', 'phone' => '5593935352',
	'type_labels' => array( 'stall' => 'Stall' ),
	'total' => 208.66, 'fees' => 6.56, 'tax' => 14.10, 'tax_rate' => 7.500, 'transaction_id' => 'ch_1',
	'notes' => "Note\n\nBilling Name: Whitney Mitchell\nBilling Address: 12253 Avenue 472\nOrange Cove, California 93646\nReservation setup ID: {$rid}",
	'components' => array(),
	'stall_quantity' => 1, 'stall_subtotal' => 64.00, 'stall_arrival_date' => '2026-05-08', 'stall_departure_date' => '2026-05-10',
	'stall_stay_type' => 'nightly', 'required_shavings_qty' => 2, 'additional_shavings_qty' => 0,
	'rv_quantity' => 0, 'rv_arrival_date' => '', 'rv_departure_date' => '', 'rv_stay_type' => 'nightly', 'rv_subtotal' => 0.00, 'rv_type' => '',
);

$s = new EEM_Shortcodes();

$check( 'EEM_PDF engine is available', EEM_PDF::is_available() );

$pdf = $s->generate_receipt_pdf( $order );
$check( 'generate_receipt_pdf returns non-empty bytes', strlen( $pdf ) > 1000 );
$check( 'output is a valid %PDF stream', '%PDF' === substr( $pdf, 0, 4 ) );

// PDF-mode HTML embeds the logo as a data URI (so Dompdf, remote-disabled, can render it).
$ref = new ReflectionMethod( 'EEM_Shortcodes', 'build_receipt_html' );
$ref->setAccessible( true );
$pdf_html = (string) $ref->invoke( $s, $order, true );
$check( 'PDF-mode logo is a data: URI', false !== strpos( $pdf_html, 'src="data:image' ) );
$check( 'PDF-mode HTML has NO remote http logo src', false === strpos( $pdf_html, 'src="http' ) );

// Empty HTML / unavailable engine degrades to '' rather than fataling.
$check( 'EEM_PDF::render("") returns empty string', '' === EEM_PDF::render( '' ) );

wp_delete_post( $rid, true );

WP_CLI::log( "\n=== C12 PDF smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C12 PDF generation smoke passed.' );
