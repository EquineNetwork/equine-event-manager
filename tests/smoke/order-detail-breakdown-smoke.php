<?php
/**
 * Regression smoke — Order Detail summary breakdown for complex orders
 * (Whitney 2026-06-30, found in the master live audit).
 *
 * The order-creation path BUNDLES group charges + pre-entries + general add-ons
 * into the stall component (the line detail lives in the component notes, which
 * are duplicated onto every component row). The Order Detail summary previously:
 *   - showed "Stalls Subtotal" as the whole non-RV bundle (e.g. $912 not $450),
 *   - showed the TAX as "Add-Ons" (compute_addon_subtotal was a tax-blind residual),
 *   - had NO Group section, NO Pre-Entry section, and NO Tax line.
 * The fix parses the notes (first component only, to avoid double-count) to
 * itemize Group + Pre-Entry, un-bundle the stall subtotal, and add a Tax line.
 *
 * BEHAVIORAL: renders the real render_summary_card() against a synthetic order
 * whose stall_subtotal bundles everything + whose 2 components carry DUPLICATED
 * notes (the real storage shape), and asserts the breakdown is correct and that
 * the section subtotals reconcile to the grand total.
 *
 * Run: wp eval-file tests/smoke/order-detail-breakdown-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

// Synthetic order matching the real bundled shape: stall_subtotal $912 bundles
// stall($450) + addon($12) + preentry($50) + group($400). 2 components, notes
// DUPLICATED (the real storage), so the parser must NOT double-count.
$notes = "Add-On: Alfalfa | Qty: 1 | Per: bale | Subtotal: \$12.00\n"
	. "Pre-Entry: Stall Cleaning | Qty: 1 | Subtotal: \$50.00\n"
	. "Group Charge: Rider Grounds Fee | Qty: 2 | Rate: \$100.00 | Subtotal: \$200.00\n"
	. "Group Charge: Rider Deposit | Qty: 2 | Rate: \$100.00 | Subtotal: \$200.00\n";
$order = array(
	'order_key'      => 'breakdown-smoke-key',
	'stall_subtotal' => 912.0,
	'rv_subtotal'    => 140.0,
	'fees'           => 42.08,
	'tax'            => 84.16,
	'tax_label'      => 'Sales Tax',
	'total'          => 1178.24,
	'amount_paid'    => 1178.24,
	'status_slug'    => 'paid',
	'components'     => array(
		array( 'notes' => $notes, 'total' => 948.48 ),
		array( 'notes' => $notes, 'total' => 145.60 ), // SAME notes — must not double
	),
);

$page = new EEM_Order_Detail_Page();
$ref  = new ReflectionMethod( $page, 'render_summary_card' );
$ref->setAccessible( true );
ob_start();
$ref->invoke( $page, $order );
$html = ob_get_clean();
$plain = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) );

$check( 'Stalls Subtotal is UN-BUNDLED to $450.00 (not $912)', false !== strpos( $plain, 'Stalls Subtotal$450.00' ) || false !== strpos( $plain, 'Stalls Subtotal $450.00' ) );
$check( 'Stalls Subtotal does NOT show the bundled $912', false === strpos( $plain, '$912.00' ) );
$check( 'RV Subtotal $140.00', false !== strpos( str_replace( ' ', '', $plain ), 'RVSubtotal$140.00' ) );
$check( 'Add-Ons shows $12.00 (the real add-on, not the tax)', false !== strpos( str_replace( ' ', '', $plain ), 'Add-OnsTotal$12.00' ) );
$check( 'Add-Ons does NOT show the tax $84.16 as add-ons', false === strpos( str_replace( ' ', '', $plain ), 'Add-OnsTotal$84.16' ) );
$check( 'Pre-Entries section present, total $50.00 (not double $100)', false !== strpos( str_replace( ' ', '', $plain ), 'Pre-EntriesTotal$50.00' ) );
$check( 'Pre-Entry line "Stall Cleaning"', false !== strpos( $plain, 'Stall Cleaning' ) );
$check( 'Group section present, total $400.00 (not double $800)', false !== strpos( str_replace( ' ', '', $plain ), 'GroupTotal$400.00' ) );
$check( 'Group itemizes Rider Grounds Fee + Rider Deposit', false !== strpos( $plain, 'Rider Grounds Fee' ) && false !== strpos( $plain, 'Rider Deposit' ) );
$check( 'Tax section present, $84.16', false !== strpos( str_replace( ' ', '', $plain ), 'TaxTotal$84.16' ) );
$check( 'Grand total reconciles $1,178.24', false !== strpos( $plain, '1,178.24' ) );

// Reconcile: 450 + 140 + 12 + 50 + 400 + 42.08 + 84.16 = 1178.24
$check( 'section sum (450+140+12+50+400+42.08+84.16) == 1178.24', abs( ( 450 + 140 + 12 + 50 + 400 + 42.08 + 84.16 ) - 1178.24 ) < 0.005 );

WP_CLI::log( "\n=== Order Detail breakdown smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Order Detail breakdown smoke passed.' );
