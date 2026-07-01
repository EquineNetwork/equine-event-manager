<?php
/**
 * Custom-item Tax-line reconcile smoke (bug #21).
 *
 * When an order carries a custom line item AND a tax rate is set, the composer
 * taxes the custom item (custom_tax) and folds it into grand_total, so the
 * charge is right. But Order Detail + the receipt rendered the Tax LINE from raw
 * $order['tax'] (base only), so the itemization came up short by custom_tax and
 * failed to add up. Both surfaces must consume the composer's effective_tax
 * (= base_tax + custom_tax), mirroring how they already consume effective_fees.
 *
 * Run: wp eval-file tests/smoke/custom-item-tax-line-reconcile-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- source presence: both surfaces consume effective_tax --------------------
$detail = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-order-detail-page.php' );
$check( 'Order Detail Tax line uses composed effective_tax', false !== strpos( $detail, "\$tax      = (float) \$composed['effective_tax'];" ) );

$sc = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
$check( 'Receipt tax derives from effective_tax', false !== strpos( $sc, "\$receipt_effective_tax  = (float) \$eem_composed['effective_tax'];" ) );
$check( 'Receipt ctx tax renders $receipt_effective_tax, not $order[tax]', false !== strpos( $sc, "'tax'                 => \$receipt_effective_tax > 0 ? \$money( \$receipt_effective_tax ) : ''," ) );

// --- behavioral: effective_tax includes custom-item tax, and the summary
// subtotal + effective_fee + effective_tax + custom − discount == grand -------
if ( class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
	$repo   = new EEM_Orders_Repository();
	$target = null; $adj = null;
	foreach ( $repo->get_orders() as $o ) {
		$a = EEM_Order_Adjustments_Repo::get_for_order( $o['order_key'] ?? '' );
		if ( ! empty( $a['custom_items'] ) ) { $target = $o; $adj = $a; break; }
	}
	if ( $target ) {
		$c        = EEM_Order_Adjustments_Repo::compose_order_totals( $target, $adj );
		$base_tax = (float) ( $target['tax'] ?? 0 );
		$eff_tax  = (float) $c['effective_tax'];
		// effective_tax >= base_tax always; strictly greater iff a tax rate applied to the custom item.
		$check( 'effective_tax >= base_tax (custom-item tax folded in)', $eff_tax + 0.005 >= $base_tax );

		// Full reconciliation the two surfaces now render:
		$base_total = (float) ( $target['total'] ?? 0 );
		$base_fees  = (float) ( $target['fees'] ?? 0 );
		$subtotal   = $base_total - $base_fees - $base_tax;                // component subtotal
		$custom_tot = (float) ( $adj['custom_items_total'] ?? 0 );
		$disc       = ( isset( $adj['discount'] ) && is_array( $adj['discount'] ) ) ? (float) $adj['discount']['amount'] : 0.0;
		$recomputed = round( $subtotal + (float) $c['effective_fees'] + $eff_tax + $custom_tot - $disc, 2 );
		$check( 'summary reconciles: subtotal + eff_fee + eff_tax + custom − discount == grand', abs( $recomputed - round( (float) $c['grand_total'], 2 ) ) < 0.02 );
	} else {
		echo "  ..  - no custom-item order in fixtures; reconcile check skipped\n";
	}
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
