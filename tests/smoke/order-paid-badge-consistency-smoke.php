<?php
/**
 * Smoke — Order Detail "Paid" badge never contradicts the Balance-Due banner /
 * Order Summary on edited orders (ROADMAP v1 #6).
 *
 * A 'paid' order whose total grew after line-item edits has a genuine
 * uncollected balance. All three payment surfaces must agree:
 *   - header status badge  → "Balance Due" (compute_display_status)
 *   - Payment Outstanding banner → rendered (render_payment_banner)
 *   - Order Summary grand total → "Balance Due" (render_summary_card)
 * A fully-paid order shows none of those (badge "Paid", no banner,
 * summary "Total Paid").
 *
 * Run: wp eval-file tests/smoke/order-paid-badge-consistency-smoke.php
 */

$passed = 0;
$failed = 0;
$check  = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$page = new EEM_Order_Detail_Page();
$disp = new ReflectionMethod( 'EEM_Order_Detail_Page', 'compute_display_status' ); $disp->setAccessible( true );
$bann = new ReflectionMethod( 'EEM_Order_Detail_Page', 'render_payment_banner' );  $bann->setAccessible( true );
$summ = new ReflectionMethod( 'EEM_Order_Detail_Page', 'render_summary_card' );     $summ->setAccessible( true );

// order_key empty so compute_grand_total uses $order['total'] directly (no repo).
$edited_paid = array( 'total' => 150.0, 'amount_paid' => 100.0, 'status_slug' => 'paid', 'stall_subtotal' => 150.0 );
$fully_paid  = array( 'total' => 100.0, 'amount_paid' => 100.0, 'status_slug' => 'paid', 'stall_subtotal' => 100.0 );

// ── Edited-up paid order: all three surfaces say Balance Due ────────────────
$badge = $disp->invoke( $page, $edited_paid, 'paid', 'paid', 'Paid' );
$check( 'edited paid: badge overridden to Balance Due', 'Balance Due' === $badge['label'] && 'unpaid' === $badge['css'] );

ob_start(); $bann->invoke( $page, $edited_paid, 'paid' ); $banner_html = (string) ob_get_clean();
$check( 'edited paid: Payment Outstanding banner renders', str_contains( $banner_html, 'Payment Outstanding' ) );
$check( 'edited paid: banner shows the $50 balance', str_contains( $banner_html, '50.00' ) );

ob_start(); $summ->invoke( $page, $edited_paid ); $summary_html = (string) ob_get_clean();
$check( 'edited paid: summary shows Balance Due', str_contains( $summary_html, 'Balance Due' ) );

// ── Fully-paid order: none of the balance signals fire ─────────────────────
$badge = $disp->invoke( $page, $fully_paid, 'paid', 'paid', 'Paid' );
$check( 'fully paid: badge stays Paid', 'Paid' === $badge['label'] && 'paid' === $badge['css'] );

ob_start(); $bann->invoke( $page, $fully_paid, 'paid' ); $banner_html = (string) ob_get_clean();
$check( 'fully paid: no Payment Outstanding banner', '' === trim( $banner_html ) );

ob_start(); $summ->invoke( $page, $fully_paid ); $summary_html = (string) ob_get_clean();
$check( 'fully paid: summary shows Total Paid (not Balance Due)', str_contains( $summary_html, 'Total Paid' ) && ! str_contains( $summary_html, 'Balance Due' ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
