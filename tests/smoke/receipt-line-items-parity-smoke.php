<?php
/**
 * Receipt / Order-Detail line-item PARITY smoke — the structural guard that
 * stops charge lines from being silently dropped off a receipt (the order
 * #00009 class of bug: additional shavings charged but missing from the
 * receipt + folded into the stall line).
 *
 * Seeds a real order with stalls + required shavings + additional shavings
 * (per-product JSON), then asserts the SHARED line-item builder
 * (EEM_Shortcodes::build_order_line_items — used by the customer receipt, PDF,
 * and confirmation email) and the breakdown (get_order_stall_breakdown) BOTH:
 *   - emit an explicit Additional Shavings line (the regression)
 *   - reconcile: Σ(displayed stall-section line totals) == the stall subtotal
 *
 * Invariant under test: every dollar in the charged subtotal appears as a
 * visible line item. If a future change drops a line, the sum won't match and
 * this fails.
 *
 * Run via: wp eval-file tests/smoke/receipt-line-items-parity-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;
$pass = 0;
$fail = 0;
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.005; };
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};
$money = static function ( $s ) { return (float) preg_replace( '/[^0-9.\-]/', '', (string) $s ); };

// Reservation config: $35/night stalls, required shavings $10/bag.
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'ReceiptParity', 'post_status' => 'publish' ) );
EEM_Reservation_Config::for( $rid )->set_many( array(
	'stalls_enabled'              => 1,
	'stall_nightly_rate'          => 35.0,
	'required_shavings_enabled'   => 1,
	'required_shavings_per_stall' => 2,
	'required_shavings_price'     => 10.0,
	'additional_shavings_enabled' => 1,
	'convenience_fee_enabled'     => 0,
) )->save();
EEM_Reservation_Config::flush_cache( $rid );

// Seed a paid stall order: 2 stalls × 4 nights @ $35 = $280; required shavings
// 2 × $10 = $20; additional shavings 2 × $10 = $20 (per-product JSON). The
// stored stall subtotal includes all three = $320.
$table = $wpdb->prefix . 'en_stall_reservations';
$onum  = 999500;
$wpdb->delete( $table, array( 'order_number' => $onum ) );
$wpdb->insert( $table, array(
	'reservation_id'          => $rid,
	'event_source'            => 'native',
	'event_id'                => 0,
	'customer_name'           => 'Parity Tester',
	'email'                   => 'parity@example.com',
	'phone'                   => '',
	'stay_type'               => 'nightly',
	'arrival_date'            => '2026-08-19',
	'departure_date'          => '2026-08-23',
	'stall_qty'               => 2,
	'tack_stall_qty'          => 0,
	'required_shavings_qty'   => 2,
	'additional_shavings_qty' => 2,
	'additional_shavings_items' => wp_json_encode( array( array( 'name' => 'Pine Shavings', 'qty' => 2, 'price' => 10.0, 'subtotal' => 20.0 ) ) ),
	'unit_price'              => 35.00,
	'subtotal'                => 320.00,
	'convenience_fee'         => 0.00,
	'total'                   => 320.00,
	'payment_status'          => 'paid',
	'payment_gateway'         => 'stripe',
	'order_number'            => $onum,
	'transaction_id'          => 'SEED-PARITY',
	'notes'                   => "Assigned Stall Units: 285, 286\nTack Stalls: 285\n"
		. "Add-On: Hay Bale | Qty: 2 | Per: bale | Subtotal: \$30.00\n"
		. "Group Charge: Rider Grounds Fee | Qty: 3 | Rate: \$20.00 | Subtotal: \$60.00\n"
		. "Group Charge: Rider Deposit | Qty: 3 | Rate: \$50.00 | Subtotal: \$150.00\n"
		. "Pre-Entry: Stall Cleaning | Qty: 2 | Subtotal: \$50.00",
	'created_at'              => '2026-06-01 00:00:00',
) );
$chk( $wpdb->insert_id > 0, 'seeded stall order ($320 = 280 stalls + 20 req + 20 add shavings)' );

$sc   = new EEM_Shortcodes();
$repo = new EEM_Orders_Repository();
$order = null;
foreach ( $repo->get_orders( '', 'date', 'asc' ) as $o ) {
	if ( (int) $o['order_number'] === $onum ) { $order = $o; break; }
}
$chk( is_array( $order ), 'grouped order resolved from repository' );

if ( is_array( $order ) ) {
	// Breakdown must split additional shavings out (was $0 via the legacy field).
	$bd_ref = new ReflectionMethod( 'EEM_Shortcodes', 'get_order_stall_breakdown' );
	$bd_ref->setAccessible( true );
	$bd = $bd_ref->invoke( $sc, $order );
	$chk( $approx( $bd['base_subtotal'], 280.0 ), 'breakdown base (stalls) = $280 (not $300 — additional shavings no longer folded in)' );
	$chk( $approx( $bd['required_shavings_subtotal'], 20.0 ), 'breakdown required shavings = $20' );
	$chk( $approx( $bd['additional_shavings_subtotal'], 20.0 ), 'breakdown additional shavings = $20 (from per-product JSON)' );

	// Shared line-item builder (receipt / PDF / email) must emit all three lines.
	$li_ref = new ReflectionMethod( 'EEM_Shortcodes', 'build_order_line_items' );
	$li_ref->setAccessible( true );
	$items = $li_ref->invoke( $sc, $order, false );

	$descs = array();
	$stall_section_total = 0.0;
	foreach ( $items as $it ) {
		$descs[] = (string) $it['desc'];
		$sec = (string) $it['section'];
		if ( in_array( $sec, array( 'Stall Res.', 'Stall Premium', 'Stall Product' ), true ) ) {
			$stall_section_total += $money( $it['total'] );
		}
	}
	$chk( in_array( 'Required Shavings', $descs, true ), 'receipt line items include Required Shavings' );
	$chk( in_array( 'Additional Shavings', $descs, true ), 'receipt line items include Additional Shavings (the #00009 regression)' );

	// Notes-derived charge lines must ALL itemize (no silent fold into subtotal).
	$by_desc = array();
	foreach ( $items as $it ) { $by_desc[ (string) $it['desc'] ] = $money( $it['total'] ); }
	$chk( isset( $by_desc['Hay Bale'] ) && $approx( $by_desc['Hay Bale'], 30.0 ), 'receipt includes General Add-On "Hay Bale" = $30' );
	$chk( isset( $by_desc['Rider Grounds Fee'] ) && $approx( $by_desc['Rider Grounds Fee'], 60.0 ), 'receipt includes Group "Rider Grounds Fee" = $60' );
	$chk( isset( $by_desc['Rider Deposit'] ) && $approx( $by_desc['Rider Deposit'], 150.0 ), 'receipt includes Group "Rider Deposit" = $150' );
	$chk( isset( $by_desc['Stall Cleaning'] ) && $approx( $by_desc['Stall Cleaning'], 50.0 ), 'receipt includes Pre-Entry "Stall Cleaning" = $50 (pre-entry line gap fix)' );

	// INVARIANT: stall-section line items reconcile to the stall subtotal — no
	// dollar silently dropped from the receipt.
	$chk( $approx( $stall_section_total, (float) $order['stall_subtotal'] ), 'INVARIANT: Σ(stall line items) $' . number_format( $stall_section_total, 2 ) . ' == stall subtotal $' . number_format( (float) $order['stall_subtotal'], 2 ) );
}

$wpdb->delete( $table, array( 'order_number' => $onum ) );
wp_delete_post( $rid, true );

echo "\nDone. PASS=$pass FAIL=$fail\n";
