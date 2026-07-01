<?php
/**
 * Mark-as-Paid modal pre-fill reconcile smoke (bug #15).
 *
 * The Order Detail "Mark as Paid" modal (render_mark_paid_modal) records a
 * cash/check payment. Its amount field must pre-fill with the composed
 * OUTSTANDING (grand incl. admin-added items/discount − ledger collected), not
 * the component-only base $order['total'] — otherwise an adjusted order
 * under-records the payment when the admin accepts the default.
 *
 * Run: wp eval-file tests/smoke/mark-paid-prefill-outstanding-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- source presence: mark-paid pre-fill derives from get_order_outstanding --
$src   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-order-detail-page.php' );
$start = strpos( $src, 'function render_mark_paid_modal' );
$end   = strpos( $src, 'function ', $start + 10 );
$fn    = ( false !== $start ) ? substr( $src, $start, ( false !== $end ? $end - $start : 3000 ) ) : '';
$check( 'render_mark_paid_modal body isolated', '' !== $fn );
$check(
	'mark-paid amount pre-fill uses get_order_outstanding(), not bare $order[total]',
	false !== strpos( $fn, 'get_order_outstanding( $order_key, $order )' )
);

// --- behavioral: outstanding == composed grand for a fresh unpaid adjusted order
$repo = new EEM_Orders_Repository();
if ( method_exists( $repo, 'get_order_outstanding' ) && class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
	$target = null;
	foreach ( $repo->get_orders() as $o ) {
		$adj = EEM_Order_Adjustments_Repo::get_for_order( $o['order_key'] ?? '' );
		if ( ! empty( $adj['custom_items'] ) || ! empty( $adj['discount'] ) ) { $target = $o; break; }
	}
	if ( $target ) {
		$outstanding = (float) $repo->get_order_outstanding( $target['order_key'], $target );
		$base        = (float) $target['total'];
		// The pre-fill differs from the base whenever there's an adjustment and/or a
		// prior partial payment — the exact condition the fix addresses.
		$composed = EEM_Order_Adjustments_Repo::compose_order_totals( $target, $adj );
		$net      = (float) $repo->get_net_collected( $target['order_key'], $target );
		$expected = round( max( 0.0, (float) $composed['grand_total'] - $net ), 2 );
		$check( 'outstanding pre-fill == composed grand − net collected', abs( $outstanding - $expected ) < 0.005 );
		$check( 'outstanding pre-fill diverges from base total when adjusted', abs( $outstanding - $base ) > 0.005 || $net > 0.005 );
	} else {
		echo "  ..  - no adjusted order in fixtures; behavioral check skipped\n";
	}
} else {
	echo "  ..  - get_order_outstanding / adjustments repo unavailable; behavioral check skipped\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
