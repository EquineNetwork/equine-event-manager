<?php
/**
 * Add-Ons report smoke — verifies the per-day matrix + summary + note section.
 *
 * Seeds one stall order with two general add-on note lines spanning a 3-day stay,
 * then asserts the daily report builds dynamic per-type columns, day-multiplied
 * totals, the totals row, and the "Total Purchased" note section; and that the
 * all-reservations summary sums raw purchased units.
 *
 * Run: wp eval-file tests/smoke/addons-report-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

global $wpdb;
$stall_table = $wpdb->prefix . 'eem_stall_reservations';

$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Add-On Smoke Res' ) );
$tok = md5( 'addon-' . wp_generate_password( 8, false ) );

// 3-day stay (May 8–10). Two add-ons: Hay x2, Buckets x1.
$notes = "Reservation setup ID: {$rid}\nSubmission token: {$tok}\n"
	. "Add-On: Hay | Qty: 2 | Per: each | Subtotal: \$20.00\n"
	. "Add-On: Buckets | Qty: 1 | Per: each | Subtotal: \$5.00\n";

$wpdb->insert( $stall_table, array(
	'event_source' => 'tec', 'event_id' => 0, 'external_event_id' => '',
	'customer_name' => 'Addon Tester', 'email' => 'addon-' . wp_generate_password( 6, false ) . '@example.test', 'phone' => '5550000000',
	'stall_qty' => 1, 'tack_stall_qty' => 0, 'stay_type' => 'nightly',
	'arrival_date' => '2026-05-08', 'departure_date' => '2026-05-10', 'required_shavings_qty' => 0, 'additional_shavings_qty' => 0,
	'unit_price' => 45.00, 'subtotal' => 45.00, 'convenience_fee' => 0.00, 'tax' => 0.00, 'tax_rate' => 0, 'total' => 45.00,
	'payment_status' => 'completed', 'payment_gateway' => 'stripe', 'order_number' => '96001', 'transaction_id' => 'cha1', 'refund_transaction_id' => '',
	'notes' => $notes, 'created_at' => '2026-04-10 09:00:00',
) );

$repo = new EEM_Reports_Repo();

// ── Daily (single reservation) ──
$daily = $repo->addons_report( array( 'reservation_id' => $rid ) );
$check( 'daily: slug is addons', 'addons' === ( $daily['slug'] ?? '' ) );
$check( 'daily: dynamic column for Hay', in_array( 'Hay', $daily['headers'], true ) );
$check( 'daily: dynamic column for Buckets', in_array( 'Buckets', $daily['headers'], true ) );
$check( 'daily: has event_header', '' !== ( $daily['event_header'] ?? '' ) );
$check( 'daily: has a pinned totals row', 1 === ( $daily['summary_row_count'] ?? 0 ) );
// 3 days + 1 totals row.
$check( 'daily: 4 rows (totals + 3 days)', 4 === count( $daily['rows'] ) );

// Totals row last cell = grand total = (2+1) per day * 3 days = 9.
$totals_row = $daily['rows'][0];
$check( 'daily: grand total = 9 (3 units * 3 days)', '9' === (string) end( $totals_row ) );

// Note section = raw purchased (Hay 2, Buckets 1), NOT day-multiplied.
$ns = $daily['note_sections'][0] ?? array();
$check( 'daily: note section is Total Purchased', false !== strpos( (string) ( $ns['label'] ?? '' ), 'Total Purchased' ) );
$purchased = array();
foreach ( (array) ( $ns['rows'] ?? array() ) as $r ) { $purchased[ $r[0] ] = $r[1]; }
$check( 'daily: purchased Hay = 2 (raw, not day-multiplied)', '2' === ( $purchased['Hay'] ?? '' ) );
$check( 'daily: purchased Buckets = 1', '1' === ( $purchased['Buckets'] ?? '' ) );

// ── Summary (all reservations) ──
$summary = $repo->addons_report( array() );
$found = false;
foreach ( (array) $summary['rows'] as $r ) {
	if ( 'Add-On Smoke Res' === $r[0] ) { $found = true; $check( 'summary: units = 3 (raw 2+1)', '3' === (string) $r[1] ); }
}
$check( 'summary: seeded reservation present', $found );

// Cleanup.
$wpdb->delete( $stall_table, array( 'order_number' => '96001' ) );
wp_delete_post( $rid, true );

WP_CLI::log( "\n{$pass} passed, {$fail} failed" );
if ( $fail > 0 ) { WP_CLI::halt( 1 ); }
