<?php
/**
 * Admin print receipt totals reconcile smoke (bug #17).
 *
 * EEM_Admin::handle_order_print() renders a printable receipt/invoice. Its
 * Totals section must reflect the LIVE order: order-level custom line items,
 * discount, the fee that follows admin-added items (effective_fees), and tax —
 * so the itemized lines sum to the composed grand. The hero + the Amount Paid /
 * Balance Due rows must read from the ledger (net collected / outstanding), not
 * the bare component base $order['total'].
 *
 * handle_order_print is nonce-gated and echoes-then-exits, so this asserts the
 * render source shape plus the load-bearing behavioral fact (composed grand
 * diverges from base for an adjusted order).
 *
 * Run: wp eval-file tests/smoke/print-receipt-totals-reconcile-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$src   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$start = strpos( $src, 'function handle_order_print' );
$end   = strpos( $src, "\n\tpublic function ", $start + 20 );
if ( false === $end ) { $end = strpos( $src, "\n\tprivate function ", $start + 20 ); }
$fn    = ( false !== $start ) ? substr( $src, $start, ( false !== $end ? $end - $start : 6000 ) ) : '';
$check( 'handle_order_print body isolated', '' !== $fn );

$check( 'computes composed grand via compose_order_totals', false !== strpos( $fn, 'EEM_Order_Adjustments_Repo::compose_order_totals( $order, $print_adj )' ) );
$check( 'renders custom-item rows', false !== strpos( $fn, 'foreach ( $print_custom_items as $print_ci_row )' ) );
$check( 'renders a Tax row', false !== strpos( $fn, "esc_html_e( 'Tax', 'equine-event-manager' )" ) );
$check( 'renders a Discount row', false !== strpos( $fn, '$print_discount_amt > 0' ) );
$check( 'uses effective (adjusted) fees, not bare $order[fees]', false !== strpos( $fn, '$print_effective_fees > 0' ) );
$check( 'Order Total row uses composed grand', false !== strpos( $fn, 'format_money( $print_grand_total )' ) );
$check( 'hero + balance read from ledger (net collected / outstanding)', false !== strpos( $fn, '$print_net_collected' ) && false !== strpos( $fn, '$print_outstanding' ) );
$check( 'hero no longer prints bare $order[total]', false === strpos( $fn, "\$format_money( \$order['total'] )" ) );

// --- behavioral: composed grand reconciles to its parts + diverges from base -
if ( class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
	$repo   = new EEM_Orders_Repository();
	$target = null; $adj = null;
	foreach ( $repo->get_orders() as $o ) {
		$a = EEM_Order_Adjustments_Repo::get_for_order( $o['order_key'] ?? '' );
		if ( ! empty( $a['custom_items'] ) || ! empty( $a['discount'] ) ) { $target = $o; $adj = $a; break; }
	}
	if ( $target ) {
		$composed = EEM_Order_Adjustments_Repo::compose_order_totals( $target, $adj );
		$grand    = (float) $composed['grand_total'];
		$base     = (float) $target['total'];
		$check( 'adjusted order: composed grand diverges from base total', abs( $grand - $base ) > 0.005 );

		// The itemized reconciliation the print view now guarantees:
		// base_components (base − base_fees − base_tax) + effective_fees + tax
		// + custom_total − discount == grand.
		$base_fees   = (float) ( $target['fees'] ?? 0 );
		$base_tax    = (float) ( $target['tax'] ?? 0 );
		$components  = $base - $base_fees - $base_tax;
		$custom_tot  = (float) ( $adj['custom_items_total'] ?? 0 );
		$disc        = ( isset( $adj['discount'] ) && is_array( $adj['discount'] ) ) ? (float) $adj['discount']['amount'] : 0.0;
		$eff_fees    = (float) $composed['effective_fees'];
		$recomputed  = round( $components + $eff_fees + $base_tax + $custom_tot - $disc, 2 );
		$check( 'itemized print lines reconcile to composed grand', abs( $recomputed - round( $grand, 2 ) ) < 0.02 );
	} else {
		echo "  ..  - no adjusted order in fixtures; behavioral reconcile skipped\n";
	}
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
