<?php
/**
 * Confirmation-email totals-row reconcile smoke (bug #13 family).
 *
 * The confirmation email's totals section renders a "Total" row (ctx key
 * `total_paid`) beneath the itemized line items. For an order carrying
 * adjustments (custom line items / discount) the line items include those
 * adjustments, so the "Total" row MUST equal the composed grand
 * (base + custom items − discount) — not the bare reservation `$order['total']`
 * — or the email visibly fails to add up.
 *
 * This asserts `total_paid` routes through `receipt_grand_total()` (the composed
 * grand) rather than the raw base total.
 *
 * Run: wp eval-file tests/smoke/confirmation-email-totals-reconcile-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- source presence: total_paid must derive from the composed grand ---------
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
$check(
	"total_paid ctx uses receipt_grand_total() (composed), not \$order['total']",
	(bool) preg_match( "/'total_paid'\s*=>\s*'\\\$'\s*\.\s*number_format_i18n\(\s*\\\$this->receipt_grand_total\(/", $src )
);
$check( 'receipt_grand_total() helper exists', method_exists( 'EEM_Shortcodes', 'receipt_grand_total' ) );

// --- behavioral: composed grand > base when an adjustment is present ---------
$sc = new EEM_Shortcodes();
$rg = new ReflectionMethod( 'EEM_Shortcodes', 'receipt_grand_total' );
$rg->setAccessible( true );

// Find a real order that carries adjustments (custom items / discount) so the
// composed grand diverges from the stored base total. get_for_order() returns a
// structural array (custom_items[] + discount) that is never "empty" — inspect
// the actual payload, not the wrapper.
$repo   = new EEM_Orders_Repository();
$target = null;
if ( class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
	foreach ( $repo->get_orders() as $o ) {
		$adj = EEM_Order_Adjustments_Repo::get_for_order( $o['order_key'] ?? '' );
		if ( ! empty( $adj['custom_items'] ) || ! empty( $adj['discount'] ) ) { $target = $o; break; }
	}
}
if ( $target ) {
	$grand = (float) $rg->invoke( $sc, $target );
	$base  = (float) $target['total'];
	$check( 'composed grand differs from base when adjustments present', abs( $grand - $base ) > 0.005 );
} else {
	echo "  ..  - no adjusted order in fixtures; divergence check skipped\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
