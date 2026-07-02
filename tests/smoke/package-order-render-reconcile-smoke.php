<?php
/**
 * PACKAGE-ORDER RENDER RECONCILE SMOKE (V1 Full Verification Pass — gap closure).
 *
 * Stay Packages were covered at the CHARGE side (package-pricing-engine-smoke:
 * flat price × qty, billed once not per-night) and at the label level
 * (format_stay_type_label / build_order_line_items renders the package NAME +
 * "Package" units). The coverage audit's 🟠 gap was that a PERSISTED package
 * order is never reconciled across the RENDER surfaces — so a flat-priced package
 * could diverge between charge, stored, the receipt/email itemization, Order
 * Detail, and Reports without any test noticing.
 *
 * This seeds a real stall package on a reservation, drives one order through the
 * SAME chain the gateway uses, persists it, then reconciles every money surface:
 *   get_reservation_meta → sanitize_submission → resolve_stall_tier_submission →
 *   calculate_submission_totals → insert_reservation_orders
 *   → build_order_line_items(order, true)        (EMAIL/PDF receipt itemization)
 *   → EEM_Order_Detail_Page::compute_grand_total (admin Order Detail banner)
 *   → EEM_Reports_Repo::order_grand_total         (Reports booked total)
 *
 * Invariants: package bills FLAT (price × qty, NOT × nights); charge == stored;
 * Σ(email/receipt lines incl. fee + tax) == stored; the stall line carries the
 * package NAME + "Package" units; Order Detail grand == stored; Reports booked
 * total == stored. A flat-package dollar dropped or per-night-inflated on ANY of
 * these surfaces trips the smoke.
 *
 * Run: wp eval-file tests/smoke/package-order-render-reconcile-smoke.php
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

if ( ! class_exists( 'EEM_Stay_Packages_Repo' ) || ! class_exists( 'EEM_Reservation_Config' ) ) {
	echo "  ..  - packages/config classes missing — skipping.\n0 passed, 0 failed\n";
	return;
}

global $wpdb;
$PASS = 0; $FAIL = 0; $FAILS = array();
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.01; };
$chk = static function ( $cond, $label ) use ( &$PASS, &$FAIL, &$FAILS ) {
	if ( $cond ) { $PASS++; } else { $FAIL++; $FAILS[] = $label; echo "    FAIL — $label\n"; }
};
$money = static function ( $s ) { return (float) preg_replace( '/[^0-9.\-]/', '', (string) $s ); };

$sc = new EEM_Shortcodes();
$R = static function ( $name ) { $m = new ReflectionMethod( 'EEM_Shortcodes', $name ); $m->setAccessible( true ); return $m; };
$meta        = $R( 'get_reservation_meta' );
$sanitize    = $R( 'sanitize_submission' );
$calc        = $R( 'calculate_submission_totals' );
$insert      = $R( 'insert_reservation_orders' );
$buildItems  = $R( 'build_order_line_items' );
$resolveTier = $R( 'resolve_stall_tier_submission' );
$arp = new ReflectionProperty( 'EEM_Shortcodes', 'active_reservation_id' );
$arp->setAccessible( true );

// Global fee 4% + tax 8% (restored/deleted after — robust, never leaks).
$prev_fee = get_option( EEM_Settings_Repo::OPTION_CONVENIENCE_FEE );
$prev_tax = get_option( EEM_Settings_Repo::OPTION_TAX );
EEM_Settings_Repo::update_tax( array( 'apply' => true, 'default_rate' => 8.0, 'label' => 'Sales Tax' ) );
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => true, 'type' => 'percentage', 'value' => 4.0, 'label' => 'Non-Refundable Convenience Fee' ) );
$TAX_RATE = 8.0;
$EMAIL    = 'package-render@eem-test.local';

// Everything that mutates the GLOBAL fee/tax runs inside try/finally so the
// restore ALWAYS fires — even on a fatal — and can never leak the site settings
// into the next smoke (the exact failure mode that broke 5 order smokes the first
// time this ran and fatal-errored before its restore block).
$rid = 0;
try {

// --- seed a reservation in stall PACKAGE mode + one flat $200 / 3-night package -
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_title' => 'PACKAGE RENDER HARNESS RES', 'post_status' => 'publish' ) );
EEM_Reservation_Config::for( $rid )->set_many( array(
	'stalls_enabled'      => 1,
	'stall_pricing_mode'  => 'packages',
	'stall_nightly_rate'  => 40.0, // present but unused in package mode; proves flat wins.
) )->save();

$PKG_PRICE = 200.0;
$PKG_QTY   = 2;
$pkg_id = (int) EEM_Stay_Packages_Repo::insert( array(
	'reservation_id' => $rid,
	'type'           => 'stall',
	'name'           => 'Weekend Package',
	'start_date'     => '2026-09-18',
	'end_date'       => '2026-09-21', // 3-night window — proves flat (NOT ×3).
	'price'          => $PKG_PRICE,
	'sort_order'     => 0,
	'max_quantity'   => 0,
) );
$chk( $pkg_id > 0, 'seeded a stall package row' );

// --- drive the REAL engine chain (mirrors the gateway) ----------------------
$data   = $meta->invoke( $sc, $rid );
$chk( is_array( $data ), 'get_reservation_meta returned config' );

$_POST = array(
	'stall_stay_type'                => 'pkg_' . $pkg_id,
	'stall_qty_pkg_' . $pkg_id       => (string) $PKG_QTY,
	'stall_same_for_all'             => '1',
	'stall_arrival_date'             => '2026-09-18',
	'stall_departure_date'           => '2026-09-21',
);
$arp->setValue( $sc, (int) $rid );
$sub = $sanitize->invoke( $sc, $data );
$sub['first_name'] = 'Package'; $sub['last_name'] = 'Renderer'; $sub['email'] = $EMAIL;
$sub['invoice_type'] = 'customer';
$sub['submission_token'] = wp_generate_uuid4();
$sub = $resolveTier->invoke( $sc, $sub, $data, $rid );

// Force the open flags true so pricing proceeds on the synthetic reservation
// (availability gating is covered elsewhere; this smoke is about money).
$status = array( 'stalls_open' => true, 'rv_open' => true, 'shavings_open' => true );

$totals   = $calc->invoke( $sc, $data, $sub, $status, $rid );
$subtotal = (float) $totals['subtotal'];
$stall_sub = (float) ( $totals['stall_subtotal'] ?? 0 );
$charge_total = (float) $totals['total'];

// INVARIANT 1 — flat: stall subtotal == price × qty, and NOT × 3 nights.
$chk( $approx( $stall_sub, $PKG_PRICE * $PKG_QTY ), sprintf( 'package bills FLAT: stall_subtotal %.2f == price×qty %.2f', $stall_sub, $PKG_PRICE * $PKG_QTY ) );
$chk( ! $approx( $stall_sub, $PKG_PRICE * $PKG_QTY * 3 ), sprintf( 'package does NOT bill per-night (%.2f != ×3 %.2f)', $stall_sub, $PKG_PRICE * $PKG_QTY * 3 ) );

$exp_fee = round( $subtotal * 0.04, 2 );
$exp_tax = round( $subtotal * ( $TAX_RATE / 100 ), 2 );
$chk( $approx( $charge_total, $subtotal + $exp_fee + $exp_tax ), sprintf( 'charge %.2f == sub+fee+tax', $charge_total ) );

// --- write path → stored total == charge ------------------------------------
$pay = array( 'payment_status' => 'paid', 'payment_gateway' => 'stripe', 'transaction_id' => 'TEST-' . $sub['submission_token'] );
$res = $insert->invoke( $sc, $rid, $data, $sub, $status, $pay );
$chk( ! empty( $res['success'] ), 'order persisted' );

$order = ( new EEM_Orders_Repository() )->get_order_by_submission_token( $sub['submission_token'] );
$chk( is_array( $order ), 'order reloaded' );

if ( is_array( $order ) ) {
	$stored_total = (float) ( $order['total'] ?? 0 );
	$chk( $approx( $stored_total, $charge_total ), sprintf( 'stored total %.2f == CHARGE %.2f', $stored_total, $charge_total ) );

	// INVARIANT — EMAIL/PDF receipt itemization reconciles + carries the package name.
	$items = $buildItems->invoke( $sc, $order, true );
	$sum_lines = 0.0;
	$stall_row = null;
	foreach ( $items as $it ) {
		$sum_lines += $money( $it['total'] );
		if ( __( 'Stall Res.', 'equine-event-manager' ) === ( $it['section'] ?? '' ) ) { $stall_row = $it; }
	}
	$chk( $approx( $sum_lines, $stored_total ), sprintf( 'RECONCILE receipt Σlines %.2f == stored %.2f (package flat dollars reach the document)', $sum_lines, $stored_total ) );
	$chk( $stall_row && false !== strpos( (string) $stall_row['desc'], 'Weekend Package' ) && false === stripos( (string) $stall_row['desc'], 'Pkg_' ), 'receipt stall line carries the package NAME (not the raw pkg_<id>)' );
	$chk( $stall_row && __( 'Package', 'equine-event-manager' ) === ( $stall_row['units'] ?? '' ), 'receipt stall line units column reads "Package"' );
	$chk( $stall_row && $approx( $money( $stall_row['total'] ), $PKG_PRICE * $PKG_QTY ), sprintf( 'receipt stall line total %.2f == flat price×qty %.2f', $stall_row ? $money( $stall_row['total'] ) : 0, $PKG_PRICE * $PKG_QTY ) );

	// INVARIANT — admin Order Detail banner grand == stored (no adjustments here).
	if ( class_exists( 'EEM_Order_Detail_Page' ) ) {
		$cgt = new ReflectionMethod( 'EEM_Order_Detail_Page', 'compute_grand_total' );
		$cgt->setAccessible( true );
		$od_grand = (float) $cgt->invoke( new EEM_Order_Detail_Page(), $order );
		$chk( $approx( $od_grand, $stored_total ), sprintf( 'Order Detail grand %.2f == stored %.2f', $od_grand, $stored_total ) );
	}

	// INVARIANT — Reports booked total == stored (package order fully reported).
	// order_grand_total() is a private instance method (a no-op for unadjusted
	// orders), so reflect + invoke it exactly as reports-grand-total-adjustments does.
	if ( class_exists( 'EEM_Reports_Repo' ) && method_exists( 'EEM_Reports_Repo', 'order_grand_total' ) ) {
		$rgt = new ReflectionMethod( 'EEM_Reports_Repo', 'order_grand_total' );
		$rgt->setAccessible( true );
		$rpt_grand = (float) $rgt->invoke( new EEM_Reports_Repo(), $order );
		$chk( $approx( $rpt_grand, $stored_total ), sprintf( 'Reports booked total %.2f == stored %.2f', $rpt_grand, $stored_total ) );
	}
}

} finally {
	// ALWAYS restore the global fee/tax + clean up seeded rows — runs even if the
	// body above threw a fatal, so the site settings can never leak to the next smoke.
	$_POST = array();
	if ( false !== $prev_fee ) { update_option( EEM_Settings_Repo::OPTION_CONVENIENCE_FEE, $prev_fee, false ); } else { delete_option( EEM_Settings_Repo::OPTION_CONVENIENCE_FEE ); }
	if ( false !== $prev_tax ) { update_option( EEM_Settings_Repo::OPTION_TAX, $prev_tax, false ); } else { delete_option( EEM_Settings_Repo::OPTION_TAX ); }
	foreach ( array( 'eem_stall_reservations', 'eem_rv_reservations' ) as $eem_t ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$eem_t} WHERE email = %s", $EMAIL ) );
	}
	if ( $rid > 0 ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eem_stay_packages WHERE reservation_id = %d", $rid ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE ID = %d", $rid ) );
	}
}

echo "\n" . ( 0 === $FAIL ? 'OK' : 'FAILURES' ) . " — {$PASS} passed, {$FAIL} failed\n";
if ( $FAIL > 0 ) { echo 'Failures: ' . implode( '; ', $FAILS ) . "\n"; exit( 1 ); }
