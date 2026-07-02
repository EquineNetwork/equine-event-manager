<?php
/**
 * RV-COMPONENT EXTRA-CHARGE RECONCILE SMOKE (live-browser find, 2026-07-02).
 *
 * "Extra" charges — general add-ons, group charges, and event pre-entries — attach
 * to the STALL component when a stall order exists, else to the RV component
 * (insert_reservation_orders() $attach_*_to). Either way the extra's amount is folded
 * into that component's stored `subtotal` AND itemized as its own receipt line.
 *
 * get_order_stall_breakdown() has always subtracted ALL of these from the stall base,
 * so stall orders reconciled. But the RV base subtracted only the RV *surcharge* — so
 * an RV-only order carrying ANY of the three extras double-counted it: the RV
 * Reservation line showed base+extra at a bogus back-computed rate, and the extra ALSO
 * appeared on its own line, so the receipt's Purchased Items summed to MORE than the
 * subtotal. (The charge was always correct — this was a display reconciliation bug,
 * caught on a real Stripe test order with a pre-entry.)
 *
 * The fix routes the RV base through get_order_rv_attached_extras_total() (add-ons +
 * group + pre-entries), mirroring the stall path, and adds the pre-entry line to the
 * receipt $totals summary (previously omitted). This proves it for ALL THREE extras on
 * an RV-only order, so the bug class — not just the pre-entry instance — is locked:
 *   - the RV Reservation line == base (extra excluded, rate correct);
 *   - the extra is its own line;
 *   - Σ(line totals) == stored pre-fee/tax subtotal (no double-count);
 * plus a stall+pre-entry regression that the stall base still excludes it.
 *
 * Run: wp eval-file tests/smoke/pre-entry-line-item-reconcile-smoke.php
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

global $wpdb;
$PASS = 0; $FAIL = 0; $FAILS = array();
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.01; };
$chk = static function ( $cond, $label ) use ( &$PASS, &$FAIL, &$FAILS ) {
	if ( $cond ) { $PASS++; } else { $FAIL++; $FAILS[] = $label; echo "    FAIL — $label\n"; }
};
$money = static function ( $s ) { return (float) preg_replace( '/[^0-9.\-]/', '', (string) $s ); };

$sc  = new EEM_Shortcodes();
$bli = new ReflectionMethod( 'EEM_Shortcodes', 'build_order_line_items' ); $bli->setAccessible( true );
$brh = new ReflectionMethod( 'EEM_Shortcodes', 'build_receipt_html' );    $brh->setAccessible( true );
$repo = new EEM_Orders_Repository();

$EMAIL_PREFIX = 'rvextra';
$BASE = 160.00; $EXTRA = 45.00; $SUBTOTAL = 205.00; // extra folded into a $160 base → $205 stored subtotal, no fee/tax.

// Seed one fully-distinct order (unique email / customer / event_id / order#) with a
// $160 base component whose stored subtotal ($205) already includes the extra + a
// notes line the extractor parses. Returns the order_key.
$n = 0;
$seed = static function ( $table, $note_line, array $extra_cols ) use ( $wpdb, &$n ) {
	$n++;
	$email = "rvextra{$n}@eem-test.local";
	$token = md5( $email . wp_generate_password( 10, false ) );
	$notes = "Reservation setup ID: 0\nSubmission token: {$token}\n{$note_line}";
	$row = array_merge( array(
		'event_source' => 'tec', 'event_id' => 970000 + $n, 'external_event_id' => '',
		'customer_name' => "RV Extra Tester {$n}", 'email' => $email, 'phone' => '5550004444',
		'stay_type' => 'nightly', 'arrival_date' => '2026-05-08', 'departure_date' => '2026-05-10',
		'unit_price' => 40.00, 'subtotal' => 205.00, 'convenience_fee' => 0.00, 'tax' => 0.00, 'tax_rate' => 0.000, 'total' => 205.00,
		'payment_status' => 'completed', 'payment_gateway' => 'stripe',
		'order_number' => (string) ( 90080 + $n ), 'transaction_id' => "ch_rvx{$n}", 'refund_transaction_id' => '',
		'notes' => $notes, 'created_at' => sprintf( '2026-04-%02d 10:30:00', 10 + $n ),
	), $extra_cols );
	$wpdb->insert( $wpdb->prefix . $table, $row );
	return array( 'key' => md5( sanitize_text_field( $token ) ), 'email' => $email );
};

// Assert an RV-only order reconciles: RV Res line == base, the extra is its own line
// under $section, Σ line totals == subtotal, and the receipt summary reconciles.
$assert_rv = static function ( $order, $section, $label ) use ( $sc, $bli, $brh, $chk, $approx, $money, $BASE, $EXTRA, $SUBTOTAL ) {
	if ( ! is_array( $order ) ) { $chk( false, "$label: order loaded" ); return; }
	$order['event_name'] = $order['reservation_title'] = "RV {$label} Classic";
	$items = $bli->invoke( $sc, $order, false );
	$rv_line = null; $extra_line = null; $sum = 0.0;
	foreach ( $items as $it ) {
		$sum += $money( $it['total'] );
		if ( __( 'RV Res.', 'equine-event-manager' ) === $it['section'] ) { $rv_line = $it; }
		if ( $section === $it['section'] ) { $extra_line = $it; }
	}
	$chk( $rv_line && $approx( $money( $rv_line['total'] ), $BASE ), "$label: RV Res line = base \$160 (not \$205 inflated)" );
	$chk( $rv_line && $approx( $money( $rv_line['rate'] ), 40.00 ), "$label: RV Res rate = \$40 (not back-computed \$51.25)" );
	$chk( $extra_line && $approx( $money( $extra_line['total'] ), $EXTRA ), "$label: extra has its own \$45 '{$section}' line" );
	$chk( $approx( $sum, $SUBTOTAL ), sprintf( '%s: Σ line totals $%.2f == subtotal $%.2f (no double-count)', $label, $sum, $SUBTOTAL ) );
	$html = (string) $brh->invoke( $sc, $order, false );
	$chk( false !== strpos( $html, '$' . number_format_i18n( $BASE, 2 ) ), "$label: receipt summary shows RV Reservation \$160.00" );
};

$emails = array();
try {
	// Seed ALL orders BEFORE the first lookup — EEM_Orders_Repository caches the
	// aggregated order list on first access, so rows added after the first
	// get_order_by_order_key() call would be invisible.
	$a = $seed( 'eem_rv_reservations', 'Pre-Entry: #10.5 Division | Qty: 1 | Subtotal: $45.00', array( 'rv_qty' => 2, 'rv_type' => '' ) );
	$b = $seed( 'eem_rv_reservations', 'Add-On: Golf Cart Rental | Qty: 1 | Subtotal: $45.00', array( 'rv_qty' => 2, 'rv_type' => '' ) );
	$c = $seed( 'eem_rv_reservations', 'Group Charge: Extra Rider | Qty: 1 | Rate: $45.00 | Subtotal: $45.00', array( 'rv_qty' => 2, 'rv_type' => '' ) );
	$d = $seed( 'eem_stall_reservations', 'Pre-Entry: #10.5 Division | Qty: 1 | Subtotal: $45.00', array( 'stall_qty' => 2, 'tack_stall_qty' => 0, 'required_shavings_qty' => 0, 'additional_shavings_qty' => 0 ) );
	$emails = array( $a['email'], $b['email'], $c['email'], $d['email'] );

	// ── CASE A: RV-only + PRE-ENTRY ──
	$assert_rv( $repo->get_order_by_order_key( $a['key'] ), __( 'Pre-Entry', 'equine-event-manager' ), 'pre-entry' );
	// ── CASE B: RV-only + GENERAL ADD-ON ──
	$assert_rv( $repo->get_order_by_order_key( $b['key'] ), __( 'General Add-On', 'equine-event-manager' ), 'add-on' );
	// ── CASE C: RV-only + GROUP CHARGE ──
	$assert_rv( $repo->get_order_by_order_key( $c['key'] ), __( 'Group Res.', 'equine-event-manager' ), 'group' );

	// ── CASE D: stall + PRE-ENTRY (regression — stall base already excludes it) ──
	$stall_order = $repo->get_order_by_order_key( $d['key'] );
	$chk( is_array( $stall_order ), 'stall order loaded' );
	if ( is_array( $stall_order ) ) {
		$stall_order['event_name'] = $stall_order['reservation_title'] = 'Stall Pre-Entry Classic';
		$items = $bli->invoke( $sc, $stall_order, false );
		$stall_line = null; $pe_line = null; $sum = 0.0;
		foreach ( $items as $it ) {
			$sum += $money( $it['total'] );
			if ( __( 'Stall Res.', 'equine-event-manager' ) === $it['section'] ) { $stall_line = $it; }
			if ( __( 'Pre-Entry', 'equine-event-manager' ) === $it['section'] ) { $pe_line = $it; }
		}
		$chk( $stall_line && $approx( $money( $stall_line['total'] ), $BASE ), 'stall: Stall Res line = base $160 (pre-entry excluded)' );
		$chk( $pe_line && $approx( $money( $pe_line['total'] ), $EXTRA ), 'stall: pre-entry has its own $45 line' );
		$chk( $approx( $sum, $SUBTOTAL ), sprintf( 'stall: Σ line totals $%.2f == subtotal $%.2f', $sum, $SUBTOTAL ) );
		$html = (string) $brh->invoke( $sc, $stall_order, false );
		$chk( false !== strpos( $html, '#10.5 Division' ), 'stall receipt summary itemizes the pre-entry (was previously omitted)' );
	}
} finally {
	if ( $emails ) {
		$in = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		foreach ( array( 'eem_stall_reservations', 'eem_rv_reservations' ) as $t ) {
			// phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$t} WHERE email IN ($in)", $emails ) );
		}
	}
}

echo "\n" . ( 0 === $FAIL ? 'OK' : 'FAILURES' ) . " — {$PASS} passed, {$FAIL} failed\n";
if ( $FAIL > 0 ) { echo 'Failures: ' . implode( '; ', $FAILS ) . "\n"; exit( 1 ); }
