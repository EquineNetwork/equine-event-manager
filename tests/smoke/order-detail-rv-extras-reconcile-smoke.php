<?php
/**
 * ORDER-DETAIL RV-EXTRAS RECONCILE SMOKE (live-browser find, 2026-07-02, order #93198).
 *
 * The admin Order Detail page renders its OWN money breakdown (EEM_Order_Detail_Page),
 * separate from the customer receipt. On an RV-only order carrying add-on / group /
 * pre-entry charges (which fold into rv_subtotal when there's no stall component), two
 * bugs surfaced live:
 *   1. the RV Subtotal line showed the raw stored rv_subtotal (extras included) while
 *      the Add-Ons / Pre-Entries sections ALSO listed them — so the summary sections
 *      summed to MORE than Total Paid;
 *   2. the Add-Ons card's compute_addon_subtotal() was fee/tax-blind
 *      (total − stall − rv − fees, no tax) and returned the TAX value instead of the
 *      add-on charge when the add-on was folded into rv_subtotal.
 *
 * Both are fixed by get_rv_base_subtotal_display() (un-folds RV-attached extras) and
 * the tax-aware, note-parsing compute_addon_subtotal(). These are pure display
 * computations over an $order array, so this exercises them directly on controlled
 * order shapes (no DB aggregation, which recomputes totals from tax_rate and would
 * mask the unit under test).
 *
 * Run: wp eval-file tests/smoke/order-detail-rv-extras-reconcile-smoke.php
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }
if ( ! class_exists( 'EEM_Order_Detail_Page' ) ) { echo "  ..  - Order Detail page missing — skipping.\n0 passed, 0 failed\n"; return; }

$PASS = 0; $FAIL = 0; $FAILS = array();
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.01; };
$chk = static function ( $cond, $label ) use ( &$PASS, &$FAIL, &$FAILS ) {
	if ( $cond ) { $PASS++; } else { $FAIL++; $FAILS[] = $label; echo "    FAIL — $label\n"; }
};

$page   = new EEM_Order_Detail_Page();
$rvBase = new ReflectionMethod( 'EEM_Order_Detail_Page', 'get_rv_base_subtotal_display' ); $rvBase->setAccessible( true );
$addon  = new ReflectionMethod( 'EEM_Order_Detail_Page', 'compute_addon_subtotal' );        $addon->setAccessible( true );

// Base $280 + $24 add-on + $50 pre-entry = $354 subtotal; 3% fee + 8% tax on $354.
$RVBASE = 280.00; $ADD = 24.00; $PRE = 50.00; $SUB = 354.00; $FEE = 10.62; $TAX = 28.32; $TOTAL = 392.94;
$notes  = "Reservation setup ID: 0\nAdd-On: Alfalfa | Qty: 2 | Subtotal: \$24.00\nPre-Entry: Stall Cleaning | Qty: 1 | Subtotal: \$50.00";

// ── CASE A: RV-only order (the bug) — extras folded into rv_subtotal ──────────
$rv = array( 'rv_subtotal' => $SUB, 'stall_subtotal' => 0.0, 'fees' => $FEE, 'tax' => $TAX, 'total' => $TOTAL, 'components' => array( array( 'notes' => $notes ) ) );
$rv_base = (float) $rvBase->invoke( $page, $rv );
$add_val = (float) $addon->invoke( $page, $rv );
$chk( $approx( $rv_base, $RVBASE ), sprintf( 'RV: Subtotal display = base $%.2f (not the extras-inclusive $%.2f)', $RVBASE, $SUB ) );
$chk( $approx( $add_val, $ADD ), sprintf( 'RV: Add-On subtotal = $%.2f (not the $%.2f tax value)', $ADD, $TAX ) );
$sections = $rv_base + $add_val + $PRE + $FEE + $TAX;
$chk( $approx( $sections, $TOTAL ), sprintf( 'RV: summary sections Σ $%.2f == Total Paid $%.2f (no double-count)', $sections, $TOTAL ) );

// ── CASE B: stall order (no-regression) — extras fold into stall_subtotal ─────
$st = array( 'rv_subtotal' => 0.0, 'stall_subtotal' => $SUB, 'fees' => $FEE, 'tax' => $TAX, 'total' => $TOTAL, 'components' => array( array( 'notes' => $notes ) ) );
$chk( $approx( (float) $rvBase->invoke( $page, $st ), 0.0 ), 'stall: RV base display = $0 (extras attach to stall, not RV)' );
$chk( $approx( (float) $addon->invoke( $page, $st ), $ADD ), 'stall: Add-On subtotal = $24 (note-parsed, tax-aware)' );

// ── CASE C: out-of-band add-on (not folded, no notes) — residual carries it ──
$oob = array( 'rv_subtotal' => 280.0, 'stall_subtotal' => 0.0, 'fees' => 8.40, 'tax' => 22.40, 'total' => 334.80, 'components' => array( array( 'notes' => 'Reservation setup ID: 0' ) ) );
// total 334.80 = rv 280 + addon 24 + fee 8.40 + tax 22.40 → residual add-on = 24.
$chk( $approx( (float) $addon->invoke( $page, $oob ), 24.0 ), 'out-of-band add-on = $24 via residual (no notes)' );

echo "\n" . ( 0 === $FAIL ? 'OK' : 'FAILURES' ) . " — {$PASS} passed, {$FAIL} failed\n";
if ( $FAIL > 0 ) { echo 'Failures: ' . implode( '; ', $FAILS ) . "\n"; exit( 1 ); }
