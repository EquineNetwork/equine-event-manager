<?php
/**
 * HOSTED-RECEIPT RENDER RECONCILE SMOKE (V1 Full Verification Pass — gap closure).
 *
 * c12-hosted-receipt-smoke proves the hosted order page can be LOOKED UP by
 * order_key + builds the token URL, but never RENDERS it and reconciles the money
 * shown. The coverage audit flagged that the customer-facing hosted receipt (and
 * its PDF twin) could display a total that diverges from the canonical composer —
 * a "displayed ≠ actual" risk — with no test noticing.
 *
 * build_receipt_html() recomputes the grand total INLINE (its own path, separate
 * from receipt_grand_total()) and renders it. Both must agree with
 * EEM_Order_Adjustments_Repo::compose_order_totals() — the single source the admin
 * Order Detail, Collect Payment, and email all use. This seeds a 2-component
 * (stall + RV) order, renders BOTH surfaces, and reconciles:
 *   - unadjusted: rendered grand == stored total == composer grand;
 *   - after an admin-added custom line item: the RENDERED hosted + PDF receipt
 *     both track the NEW composer grand (not the stale base), and the custom line
 *     description appears on the customer's receipt.
 *
 * A hosted receipt that showed the pre-adjustment total, or a render path that
 * diverged from the composer, trips this smoke.
 *
 * Run: wp eval-file tests/smoke/hosted-receipt-reconcile-smoke.php
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }
if ( ! class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
	echo "  ..  - adjustments repo missing — skipping.\n0 passed, 0 failed\n";
	return;
}

global $wpdb;
$PASS = 0; $FAIL = 0; $FAILS = array();
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.01; };
$chk = static function ( $cond, $label ) use ( &$PASS, &$FAIL, &$FAILS ) {
	if ( $cond ) { $PASS++; } else { $FAIL++; $FAILS[] = $label; echo "    FAIL — $label\n"; }
};
$m = static function ( $v ) { return '$' . number_format_i18n( (float) $v, 2 ); };

$sc = new EEM_Shortcodes();
$grandR = new ReflectionMethod( 'EEM_Shortcodes', 'receipt_grand_total' );
$grandR->setAccessible( true );
$htmlR = new ReflectionMethod( 'EEM_Shortcodes', 'build_receipt_html' );
$htmlR->setAccessible( true );

$EMAIL = 'hosted-reconcile@eem-test.local';
$token = md5( 'hosted-reconcile-' . wp_generate_password( 12, false ) );
$order_key = md5( sanitize_text_field( $token ) );
$notes = "Reservation setup ID: 0\nSubmission token: {$token}";
$order = null;

try {
	// --- seed a 2-component (stall + RV) order sharing one order_key -----------
	$common = array(
		'event_source' => 'tec', 'event_id' => 0, 'external_event_id' => '',
		'customer_name' => 'Hosted Reconcile', 'email' => $EMAIL, 'phone' => '5550002222',
		'stay_type' => 'nightly', 'arrival_date' => '2026-05-08', 'departure_date' => '2026-05-10',
		'payment_status' => 'completed', 'payment_gateway' => 'stripe',
		'order_number' => '90077', 'transaction_id' => 'ch_hr', 'refund_transaction_id' => '',
		'notes' => $notes, 'created_at' => '2026-04-24 10:30:00',
	);
	$wpdb->insert( $wpdb->prefix . 'eem_stall_reservations', array_merge( $common, array(
		'stall_qty' => 2, 'tack_stall_qty' => 0, 'required_shavings_qty' => 0, 'additional_shavings_qty' => 0,
		'unit_price' => 50.00, 'subtotal' => 100.00, 'convenience_fee' => 0.00, 'tax' => 0.00, 'tax_rate' => 0.000, 'total' => 100.00,
	) ) );
	$wpdb->insert( $wpdb->prefix . 'eem_rv_reservations', array_merge( $common, array(
		'rv_qty' => 1, 'rv_type' => '', 'unit_price' => 50.00, 'subtotal' => 50.00,
		'convenience_fee' => 0.00, 'tax' => 0.00, 'tax_rate' => 0.000, 'total' => 50.00,
	) ) );

	$order = ( new EEM_Orders_Repository() )->get_order_by_order_key( $order_key );
	$chk( is_array( $order ), 'composite order loaded by order_key' );
	if ( is_array( $order ) ) {
		$order['event_name'] = '2026 Hosted Reconcile Classic';
		$order['reservation_title'] = '2026 Hosted Reconcile Classic';
		$BASE = 150.0; // stall 100 + rv 50.
		$chk( $approx( (float) $order['total'], $BASE ), sprintf( 'stored total aggregates to %.2f (stall+rv)', $BASE ) );

		// ── UNADJUSTED: composer grand == stored == receipt_grand_total ──
		$composed0 = EEM_Order_Adjustments_Repo::compose_order_totals( $order, EEM_Order_Adjustments_Repo::get_for_order( $order_key ) );
		$chk( $approx( $composed0['grand_total'], $BASE ), 'unadjusted composer grand == stored' );
		$chk( $approx( (float) $grandR->invoke( $sc, $order ), $BASE ), 'receipt_grand_total == stored (unadjusted)' );

		// ── render BOTH surfaces + assert the grand + components appear ──
		$hosted0 = (string) $htmlR->invoke( $sc, $order, false );
		$pdf0    = (string) $htmlR->invoke( $sc, $order, true );
		$chk( '' !== $hosted0 && '' !== $pdf0, 'hosted + PDF receipts render non-empty' );
		$chk( false !== strpos( $hosted0, $m( $BASE ) ), 'hosted receipt shows the grand total ' . $m( $BASE ) );
		$chk( false !== strpos( $pdf0, $m( $BASE ) ), 'PDF receipt shows the grand total ' . $m( $BASE ) );
		$chk( false !== strpos( $hosted0, __( 'Stall Reservation', 'equine-event-manager' ) ), 'hosted receipt itemizes the Stall Reservation component' );
		$chk( false !== strpos( $hosted0, __( 'RV Reservation', 'equine-event-manager' ) ), 'hosted receipt itemizes the RV Reservation component' );

		// ── ADJUSTED: admin adds a $40 custom line item ──
		EEM_Order_Adjustments_Repo::insert_custom_item( $order_key, 'Damage Fee', 40.00 );
		$adj1      = EEM_Order_Adjustments_Repo::get_for_order( $order_key );
		$composed1 = EEM_Order_Adjustments_Repo::compose_order_totals( $order, $adj1 );
		$GRAND1    = (float) $composed1['grand_total'];
		$chk( $GRAND1 > $BASE + 0.01, sprintf( 'composer grand rose with the custom item (%.2f > %.2f)', $GRAND1, $BASE ) );
		$chk( $approx( (float) $grandR->invoke( $sc, $order ), $GRAND1 ), 'receipt_grand_total tracks the composer after the adjustment' );

		// The RENDERED hosted + PDF receipts must show the NEW grand, not the stale base.
		$hosted1 = (string) $htmlR->invoke( $sc, $order, false );
		$pdf1    = (string) $htmlR->invoke( $sc, $order, true );
		$chk( false !== strpos( $hosted1, $m( $GRAND1 ) ), 'hosted receipt renders the ADJUSTED grand ' . $m( $GRAND1 ) );
		$chk( false !== strpos( $pdf1, $m( $GRAND1 ) ), 'PDF receipt renders the ADJUSTED grand ' . $m( $GRAND1 ) );
		$chk( false !== strpos( $hosted1, 'Damage Fee' ), 'hosted receipt shows the admin-added custom line description' );
	}
} finally {
	// ALWAYS clean up seeded rows + adjustments (even on a fatal), so nothing leaks.
	if ( '' !== $order_key ) { EEM_Order_Adjustments_Repo::replace_custom_items( $order_key, array() ); }
	foreach ( array( 'eem_stall_reservations', 'eem_rv_reservations' ) as $eem_t ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$eem_t} WHERE email = %s", $EMAIL ) );
	}
}

echo "\n" . ( 0 === $FAIL ? 'OK' : 'FAILURES' ) . " — {$PASS} passed, {$FAIL} failed\n";
if ( $FAIL > 0 ) { echo 'Failures: ' . implode( '; ', $FAILS ) . "\n"; exit( 1 ); }
