<?php
/**
 * Cash/check convenience-fee WAIVER smoke (backend Paid-Cash path).
 *
 * Whitney decision: the convenience fee is a pass-through of the card-processing
 * cost. Front-end checkout is always card → always carries the fee. The admin
 * Collect Payment "Paid Cash" tab is the ONLY place an offline (cash/check)
 * payment is recorded, and there the fee is removed from the order.
 *
 * Asserts the two-part mechanism end-to-end against a seeded order:
 *   1. EEM_Orders_Repository::waive_convenience_fee() zeroes each component's
 *      convenience_fee, drops it from the row total, and tags the notes marker.
 *   2. EEM_Order_Adjustments_Repo::compose_order_totals() reads the marker and
 *      forces effective_fees = 0 + subtracts the base fee from grand_total, so
 *      every surface (Order Detail / receipt / Collect Payment) goes fee-free.
 * Also asserts the waiver is idempotent and leaves subtotal + tax untouched.
 *
 * Run via: wp eval-file tests/smoke/cash-waiver-fee-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;
$pass = 0;
$fail = 0;
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.02; };
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

if ( ! class_exists( 'EEM_Orders_Repository' ) || ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
	echo "  FAIL — required classes missing\n0 passed, 1 failed\n";
	return;
}

$table = $wpdb->prefix . 'eem_stall_reservations';
$onum  = 999810;
$wpdb->delete( $table, array( 'order_number' => $onum ) );

// Seed: $100 stall subtotal + $4 fee + $6 tax = $110 total.
$wpdb->insert( $table, array(
	'reservation_id'  => 0,
	'event_source'    => 'native',
	'event_id'        => 0,
	'customer_name'   => 'Cash Waiver',
	'email'           => 'cashwaiver@example.com',
	'phone'           => '',
	'stay_type'       => 'nightly',
	'arrival_date'    => '2026-09-01',
	'departure_date'  => '2026-09-03',
	'stall_qty'       => 2,
	'tack_stall_qty'  => 0,
	'unit_price'      => 50.00,
	'subtotal'        => 100.00,
	'convenience_fee' => 4.00,
	'tax'             => 6.00,
	'tax_rate'        => 6.00,
	'total'           => 104.00,
	'payment_status'  => 'pending',
	'payment_gateway' => 'stripe',
	'order_number'    => $onum,
	'transaction_id'  => 'SEED-CASHWAIVER',
	'notes'           => "Seed: cash waiver\n",
	'created_at'      => '2026-06-01 00:00:00',
) );
$chk( $wpdb->insert_id > 0, 'seeded order ($100 sub + $4 fee + $6 tax)' );

$repo  = new EEM_Orders_Repository();
$order = null;
foreach ( $repo->get_orders( '', 'date', 'asc' ) as $o ) {
	if ( (int) $o['order_number'] === $onum ) { $order = $o; break; }
}
$chk( is_array( $order ), 'grouped order resolved' );

if ( is_array( $order ) ) {
	$order_key = (string) $order['order_key'];
	$adj       = EEM_Order_Adjustments_Repo::get_for_order( $order_key );

	// --- BEFORE waiver: fee is present everywhere ---
	$before = EEM_Order_Adjustments_Repo::compose_order_totals( $order, $adj );
	$chk( $approx( $order['fees'], 4.00 ), 'grouped fees = $4 before waiver' );
	$chk( $approx( $before['effective_fees'], 4.00 ), 'compose effective_fees = $4 before waiver' );
	$chk( empty( $before['fee_waived'] ), 'fee_waived flag false before waiver' );
	// grand_total = total($104, incl fee+tax... wait tax is separate column) →
	// base_total $104 + base_tax $6 = $110.
	$chk( $approx( $before['grand_total'], 110.00 ), 'grand_total = $110 before waiver (sub+fee+tax)' );

	// --- Record the waiver ---
	$waived = $repo->waive_convenience_fee( $order_key );
	$chk( $approx( $waived, 4.00 ), 'waive_convenience_fee() returned $4' );

	// --- AFTER waiver: fresh read, fee gone everywhere ---
	$repo2  = new EEM_Orders_Repository();
	$after  = $repo2->get_order( $order_key );
	$chk( is_array( $after ), 'order still readable after waiver' );
	$chk( false !== stripos( (string) $after['notes'], 'Convenience Fee Waived' ), 'notes carry waiver marker' );
	$chk( $approx( $after['fees'], 0.0 ), 'grouped fees zeroed after waiver' );

	$adj2   = EEM_Order_Adjustments_Repo::get_for_order( $order_key );
	$comp2  = EEM_Order_Adjustments_Repo::compose_order_totals( $after, $adj2 );
	$chk( ! empty( $comp2['fee_waived'] ), 'fee_waived flag true after waiver' );
	$chk( $approx( $comp2['effective_fees'], 0.0 ), 'effective_fees zero after waiver' );
	// grand_total drops by exactly the $4 fee; subtotal ($100) + tax ($6) intact → $106.
	$chk( $approx( $comp2['grand_total'], 106.00 ), 'grand_total = $106 after waiver (fee removed, tax kept)' );
	$chk( $approx( $comp2['effective_tax'], 6.00 ), 'tax untouched by waiver' );

	// --- Idempotency ---
	$second = $repo2->waive_convenience_fee( $order_key );
	$chk( $approx( $second, 0.0 ), 'second waiver is a no-op (idempotent)' );
	$repo3  = new EEM_Orders_Repository();
	$after3 = $repo3->get_order( $order_key );
	$comp3  = EEM_Order_Adjustments_Repo::compose_order_totals( $after3, EEM_Order_Adjustments_Repo::get_for_order( $order_key ) );
	$chk( $approx( $comp3['grand_total'], 106.00 ), 'grand_total unchanged after second waiver' );
}

// Cleanup.
$wpdb->delete( $table, array( 'order_number' => $onum ) );

echo "\n$pass passed, $fail failed\n";
