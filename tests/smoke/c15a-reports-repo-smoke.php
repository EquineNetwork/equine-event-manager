<?php
/**
 * C15.A smoke — Reports repo (6 filter-aware query builders).
 *
 * Seeds 3 orders across 2 reservations (one refunded, two sharing a customer),
 * then asserts each report's shape + key aggregates, and that the reservation /
 * date / status filters narrow the dataset.
 *
 * Run: wp eval-file tests/smoke/c15a-reports-repo-smoke.php
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
$stall_table = $wpdb->prefix . 'en_stall_reservations';
$rv_table    = $wpdb->prefix . 'en_rv_reservations';

$rid_a = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'C15 Res A' ) );
$rid_b = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'C15 Res B' ) );
update_post_meta( $rid_a, '_en_stall_inventory', '100' );
update_post_meta( $rid_a, '_en_rv_inventory', '20' );

$t1 = md5( 'c15o1-' . wp_generate_password( 8, false ) );
$t2 = md5( 'c15o2-' . wp_generate_password( 8, false ) );
$t3 = md5( 'c15o3-' . wp_generate_password( 8, false ) );
// Unique emails so the customer-aggregation test can't collide with demo data.
$alice_email = 'c15alice-' . wp_generate_password( 6, false ) . '@example.test';
$bob_email   = 'c15bob-' . wp_generate_password( 6, false ) . '@example.test';

// Order 1 — Res A, stall, Alice, completed, 2026-04-10. grouped total = total + tax = 93 + 7 = 100.
$wpdb->insert( $stall_table, array(
	'event_source' => 'tec', 'event_id' => 0, 'external_event_id' => '',
	'customer_name' => 'Alice Rider', 'email' => $alice_email, 'phone' => '5551110001',
	'stall_qty' => 2, 'tack_stall_qty' => 0, 'stay_type' => 'nightly',
	'arrival_date' => '2026-05-08', 'departure_date' => '2026-05-10', 'required_shavings_qty' => 0, 'additional_shavings_qty' => 0,
	'unit_price' => 45.00, 'subtotal' => 90.00, 'convenience_fee' => 3.00, 'tax' => 7.00, 'tax_rate' => 7.5, 'total' => 93.00,
	'payment_status' => 'completed', 'payment_gateway' => 'stripe', 'order_number' => '95001', 'transaction_id' => 'ch1', 'refund_transaction_id' => '',
	'notes' => "Reservation setup ID: {$rid_a}\nSubmission token: {$t1}", 'created_at' => '2026-04-10 09:00:00',
) );
// Order 2 — Res A, RV, Alice (same customer), REFUNDED, 2026-04-20. grouped total = 47 + 3 = 50.
$wpdb->insert( $rv_table, array(
	'event_source' => 'tec', 'event_id' => 0, 'external_event_id' => '',
	'customer_name' => 'Alice Rider', 'email' => $alice_email, 'phone' => '5551110001',
	'rv_qty' => 1, 'rv_type' => '', 'stay_type' => 'nightly',
	'arrival_date' => '2026-05-08', 'departure_date' => '2026-05-10',
	'unit_price' => 47.00, 'subtotal' => 47.00, 'convenience_fee' => 0.00, 'tax' => 3.00, 'tax_rate' => 7.5, 'total' => 47.00,
	'payment_status' => 'refunded', 'payment_gateway' => 'stripe', 'order_number' => '95002', 'transaction_id' => 'ch2', 'refund_transaction_id' => 're_2',
	'refunded_at' => '2026-04-22 12:00:00',
	'notes' => "Reservation setup ID: {$rid_a}\nSubmission token: {$t2}\nRefunded Amount: 50.00\nRefund Reason: Customer cancelled", 'created_at' => '2026-04-20 09:00:00',
) );
// Order 3 — Res B, stall, Bob, completed, 2026-05-01. grouped total = 60 + 0 = 60.
$wpdb->insert( $stall_table, array(
	'event_source' => 'tec', 'event_id' => 0, 'external_event_id' => '',
	'customer_name' => 'Bob Groom', 'email' => $bob_email, 'phone' => '5552220002',
	'stall_qty' => 1, 'tack_stall_qty' => 0, 'stay_type' => 'nightly',
	'arrival_date' => '2026-05-08', 'departure_date' => '2026-05-10', 'required_shavings_qty' => 0, 'additional_shavings_qty' => 0,
	'unit_price' => 57.00, 'subtotal' => 57.00, 'convenience_fee' => 3.00, 'tax' => 0.00, 'tax_rate' => 0, 'total' => 60.00,
	'payment_status' => 'completed', 'payment_gateway' => 'authorize_net', 'order_number' => '95003', 'transaction_id' => 'ch3', 'refund_transaction_id' => '',
	'notes' => "Reservation setup ID: {$rid_b}\nSubmission token: {$t3}", 'created_at' => '2026-05-01 09:00:00',
) );

$repo    = new EEM_Reports_Repo();
$all     = array();
$only_a  = array( 'reservation_id' => $rid_a );

// Helper: filter a report's rows to just our seeded orders (by order # prefix 950).
$mine = static function ( array $report ) {
	return array_values( array_filter( $report['rows'], static function ( $r ) {
		return false !== strpos( implode( '|', array_map( 'strval', $r ) ), '#9500' );
	} ) );
};

// ── Orders report ──
$orders = $repo->orders_report( $all );
$orows  = $mine( $orders );
$check( 'orders: headers include Tax column', in_array( 'Tax', $orders['headers'], true ) );
$check( 'orders: 3 seeded rows present', 3 === count( $orows ) );
$check( 'orders: a row shows tax 7.00', false !== strpos( implode( '|', array_map( 'strval', $orows[0] ) ), '7.00' ) || false !== strpos( implode( '|', array_map( 'strval', $orows[1] ) ), '7.00' ) || false !== strpos( implode( '|', array_map( 'strval', $orows[2] ) ), '7.00' ) );

// ── Reservations report ──
$res = $repo->reservations_report( $all );
$resA = null;
foreach ( $res['rows'] as $r ) { if ( 'C15 Res A' === $r[0] ) { $resA = $r; } }
$check( 'reservations: Res A row found', null !== $resA );
$check( 'reservations: Res A has 2 orders', null !== $resA && '2' === (string) $resA[2] );
$check( 'reservations: Res A revenue 150.00', null !== $resA && false !== strpos( $resA[3], '150.00' ) );
$check( 'reservations: Res A occupancy uses capacity 100 (2%)', null !== $resA && false !== strpos( $resA[7], '2%' ) );

// ── Revenue report ──
$rev = $repo->revenue_report( $all );
$rev_refund_row = null;
foreach ( $rev['rows'] as $r ) { if ( false !== strpos( $r[1], '95002' ) ) { $rev_refund_row = $r; } }
$check( 'revenue: refunded order shows Refunded 50.00', null !== $rev_refund_row && false !== strpos( $rev_refund_row[9], '50.00' ) );
$check( 'revenue: refunded order Net 0.00', null !== $rev_refund_row && false !== strpos( $rev_refund_row[10], '0.00' ) );

// ── Customer List report ──
$cust = $repo->customer_list_report( $all );
$alice = null;
foreach ( $cust['rows'] as $r ) { if ( $alice_email === $r[1] ) { $alice = $r; } }
$check( 'customers: Alice aggregated to 2 orders', null !== $alice && '2' === (string) $alice[3] );
$check( 'customers: Alice lifetime value 150.00', null !== $alice && false !== strpos( $alice[4], '150.00' ) );

// ── Refund Log report ──
$ref = $repo->refund_log_report( $all );
$ref_row = null;
foreach ( $ref['rows'] as $r ) { if ( false !== strpos( $r[0], '95002' ) ) { $ref_row = $r; } }
$check( 'refunds: order 95002 logged', null !== $ref_row );
$check( 'refunds: amount 50.00', null !== $ref_row && false !== strpos( $ref_row[5], '50.00' ) );
$check( 'refunds: reason captured', null !== $ref_row && false !== strpos( $ref_row[6], 'Customer cancelled' ) );

// ── Stall Occupancy report ──
$occ = $repo->stall_occupancy_report( $all );
$occA = null;
foreach ( $occ['rows'] as $r ) { if ( 'C15 Res A' === $r[0] ) { $occA = $r; } }
$check( 'occupancy: Res A capacity 100', null !== $occA && '100' === (string) $occA[1] );
$check( 'occupancy: Res A stalls booked 2', null !== $occA && '2' === (string) $occA[2] );

// ── Filters ──
$check( 'filter by reservation A narrows orders to 2', 2 === count( $mine( $repo->orders_report( $only_a ) ) ) );
$check( 'filter by date 04-15..04-30 narrows to order 95002', 1 === count( $mine( $repo->orders_report( array( 'date_from' => '2026-04-15', 'date_to' => '2026-04-30' ) ) ) ) );
$check( 'filter by status refunded narrows to order 95002', 1 === count( $mine( $repo->orders_report( array( 'status' => 'refunded' ) ) ) ) );

// ── Cleanup ──
$wpdb->query( $wpdb->prepare( "DELETE FROM {$stall_table} WHERE order_number IN (%s,%s)", '95001', '95003' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$rv_table} WHERE order_number = %s", '95002' ) );
wp_delete_post( $rid_a, true );
wp_delete_post( $rid_b, true );

WP_CLI::log( "\n=== C15.A reports repo smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C15.A reports repo smoke passed.' );
