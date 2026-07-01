<?php
/**
 * Hosted invoice page "Amount Due" reconcile smoke (bug #14).
 *
 * The customer-facing hosted invoice page (maybe_render_invoice_payment_page →
 * render_invoice_payment_page) must DISPLAY the same amount the gateway CHARGES.
 * The charge paths (ajax_create_invoice_payment_intent / handle_invoice_payment_
 * submission) reassign $order['total'] to the composed OUTSTANDING via
 * get_order_amount_due(); the display path must do the same, or an adjusted /
 * partially-paid order shows "Amount Due: $base" while the card is charged the
 * outstanding.
 *
 * The success page's "Total Paid" line must reflect the ledger net collected,
 * not the re-read base component total.
 *
 * Run: wp eval-file tests/smoke/invoice-page-amount-due-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );

// --- source presence: display path reassigns total to the outstanding --------
// Isolate maybe_render_invoice_payment_page so the assertion can't be satisfied
// by the charge-path reassignments elsewhere in the file.
$start = strpos( $src, 'function maybe_render_invoice_payment_page' );
$end   = strpos( $src, 'function ajax_create_invoice_payment_intent' );
$display_fn = ( false !== $start && false !== $end && $end > $start ) ? substr( $src, $start, $end - $start ) : '';
$check( 'maybe_render_invoice_payment_page body isolated', '' !== $display_fn );
$check(
	'display path reassigns total to get_order_amount_due before render',
	false !== strpos( $display_fn, "\$order['total'] = \$this->get_order_amount_due( \$order, \$order['order_key'] );" )
);

// --- source presence: success page "Total Paid" uses ledger net collected ----
$s_start = strpos( $src, 'function render_invoice_payment_success_page' );
$s_end   = strpos( $src, 'function ', $s_start + 10 );
$success_fn = ( false !== $s_start ) ? substr( $src, $s_start, ( false !== $s_end ? $s_end - $s_start : 4000 ) ) : '';
$check(
	'success page Total Paid uses get_order_net_collected(), not $order[total]',
	false !== strpos( $success_fn, "get_order_net_collected( \$order )" )
	&& false === strpos( $success_fn, "format_money( (float) \$order['total'] )" )
);

// --- behavioral: for an adjusted order, outstanding != base total ------------
// Proves the reassignment is load-bearing — without it the page would show a
// different number than the charge.
$sc = new EEM_Shortcodes();
$due = new ReflectionMethod( 'EEM_Shortcodes', 'get_order_amount_due' );
$due->setAccessible( true );

$repo   = new EEM_Orders_Repository();
$target = null;
if ( class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
	foreach ( $repo->get_orders() as $o ) {
		$adj = EEM_Order_Adjustments_Repo::get_for_order( $o['order_key'] ?? '' );
		if ( ! empty( $adj['custom_items'] ) || ! empty( $adj['discount'] ) ) { $target = $o; break; }
	}
}
if ( $target ) {
	$outstanding = (float) $due->invoke( $sc, $target, $target['order_key'] );
	$base        = (float) $target['total'];
	$check( 'adjusted order: displayed amount-due (outstanding) diverges from base', abs( $outstanding - $base ) > 0.005 );
} else {
	echo "  ..  - no adjusted order in fixtures; divergence check skipped\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
