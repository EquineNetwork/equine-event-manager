<?php
/**
 * F8 smoke — imported-order receipt line items reconcile to the stored charge.
 *
 * CSV-imported orders can carry a custom stay-type label (e.g. "Thursday–Sunday")
 * that get_billable_stay_units() can't map to a clean billable-night count, and a
 * stored unit_price that doesn't multiply with the date span to the charged
 * subtotal. The old receipt builder recomputed the Stall Res. line as
 * qty × unit_price × nights, so the line overshot the (correct) order total — a
 * $137 charge could render a $285 stall line. The order total + balance + actual
 * charge were always right; only the itemized line display diverged.
 *
 * Fix (get_order_stall_breakdown): derive the stall base from the STORED row
 * subtotal minus the components that render as their own line, so the lines always
 * sum back to the stored subtotal.
 *
 * This smoke seeds an import-shaped row where qty × unit_price × nights ($285) is
 * deliberately != the stored subtotal ($137) and asserts the receipt reconciles.
 *
 * Run via: wp eval-file tests/smoke/f8-imported-receipt-reconcile-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;
$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.02; };
$money  = static function ( $s ) { return (float) preg_replace( '/[^0-9.\-]/', '', (string) $s ); };

if ( ! class_exists( 'EEM_Shortcodes' ) || ! class_exists( 'EEM_Orders_Repository' ) ) {
	echo "  FAIL — required classes missing\n0 passed, 1 failed\n";
	return;
}

$table = $wpdb->prefix . 'eem_stall_reservations';
$onum  = 999820;
$wpdb->delete( $table, array( 'order_number' => $onum ) );

// Import-shaped row: stored subtotal $137, but unit_price $95 × 1 stall × 3 nights
// = $285. The custom stay-type label keeps get_billable_stay_units from collapsing
// the span, so the OLD recompute would render the stall line at $285.
$wpdb->insert( $table, array(
	'reservation_id'  => 0,
	'event_source'    => 'native',
	'event_id'        => 0,
	'customer_name'   => 'Imported Customer',
	'email'           => 'imported@example.com',
	'phone'           => '',
	'stay_type'       => 'thursday_sunday',
	'arrival_date'    => '2026-08-20',
	'departure_date'  => '2026-08-23',
	'stall_qty'       => 1,
	'tack_stall_qty'  => 0,
	'unit_price'      => 95.00,
	'subtotal'        => 137.00,
	'convenience_fee' => 0.00,
	'tax'             => 0.00,
	'tax_rate'        => 0.00,
	'total'           => 137.00,
	'payment_status'  => 'paid',
	'payment_gateway' => 'imported',
	'order_number'    => $onum,
	'transaction_id'  => 'SEED-F8IMPORT',
	'notes'           => "Imported order\n",
	'created_at'      => '2026-06-01 00:00:00',
) );
$chk( $wpdb->insert_id > 0, 'seeded import-shaped order (stored $137, recompute would be $285)' );

$repo  = new EEM_Orders_Repository();
$order = null;
foreach ( $repo->get_orders( '', 'date', 'asc' ) as $o ) {
	if ( (int) $o['order_number'] === $onum ) { $order = $o; break; }
}
$chk( is_array( $order ), 'grouped order resolved' );

if ( is_array( $order ) ) {
	$sc = new EEM_Shortcodes();

	// Breakdown: base must equal the STORED subtotal, not the $285 recompute.
	$breakdown = $sc->get_order_stall_breakdown( $order );
	$chk( $approx( $breakdown['base_subtotal'], 137.00 ), 'stall base = stored $137 (not $285 recompute)' );

	// Receipt line items: the Stall Res. line total must read $137, and Σ lines
	// must reconcile to the stored stall subtotal.
	$ref = new ReflectionMethod( 'EEM_Shortcodes', 'build_order_line_items' );
	$ref->setAccessible( true );
	$items = $ref->invoke( $sc, $order, false ); // no fee line

	$stall_line = null;
	$sum_lines  = 0.0;
	foreach ( $items as $it ) {
		$sum_lines += $money( $it['total'] );
		if ( __( 'Stall Res.', 'equine-event-manager' ) === $it['section'] ) { $stall_line = $it; }
	}
	$chk( is_array( $stall_line ), 'Stall Res. line rendered' );
	if ( is_array( $stall_line ) ) {
		$chk( $approx( $money( $stall_line['total'] ), 137.00 ), 'Stall Res. line total = $137 (was $285 pre-fix)' );
	}
	$chk( $approx( $sum_lines, 137.00 ), 'Σ receipt line items == stored subtotal $137 (reconciles)' );
}

// Cleanup.
$wpdb->delete( $table, array( 'order_number' => $onum ) );

echo "\n$pass passed, $fail failed\n";
