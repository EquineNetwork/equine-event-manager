<?php
/**
 * Order Detail banner grand-total parity + floor smoke (bugs #22, #23).
 *
 * #22 — EEM_Order_Detail_Page::compute_grand_total() (the top-of-page Balance
 * Due / Refund Owed banner) computed base + custom − discount inline, DROPPING
 * the custom-item convenience fee + tax that compose_order_totals() adds. It
 * must agree with the composer (the same source the Order Summary card,
 * get_order_outstanding, and Collect Payment use).
 *
 * #23 — a percentage discount frozen at set-time can exceed the base after an
 * Edit-Dates shorten; compose_order_totals() grand_total (and compute_grand_
 * total) must floor at 0 so a negative grand can't inflate the displayed
 * "Refund Owed" (paid − grand).
 *
 * Run: wp eval-file tests/smoke/order-detail-grand-total-parity-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- source presence ---------------------------------------------------------
$detail = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-order-detail-page.php' );
$check( 'compute_grand_total delegates to compose_order_totals', false !== strpos( $detail, "\$composed = EEM_Order_Adjustments_Repo::compose_order_totals( \$order, \$adj );" ) && false !== strpos( $detail, "return round( max( 0.0, (float) \$composed['grand_total'] ), 2 );" ) );

$repo_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-order-adjustments-repo.php' );
$check( 'composer grand_total floors at 0', false !== strpos( $repo_src, "'grand_total'     => round( max( 0.0, \$base_total + \$custom_total + \$custom_fee + \$custom_tax - \$discount_amt - \$fee_removed ), 2 )," ) );

// --- behavioral: banner parity with the composer -----------------------------
$reflect = new ReflectionMethod( 'EEM_Order_Detail_Page', 'compute_grand_total' );
$reflect->setAccessible( true );
$page = new EEM_Order_Detail_Page();

if ( class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
	$repo = new EEM_Orders_Repository();
	$target = null; $adj = null;
	foreach ( $repo->get_orders() as $o ) {
		$a = EEM_Order_Adjustments_Repo::get_for_order( $o['order_key'] ?? '' );
		if ( ! empty( $a['custom_items'] ) || ! empty( $a['discount'] ) ) { $target = $o; $adj = $a; break; }
	}
	if ( $target ) {
		$banner   = (float) $reflect->invoke( $page, $target );
		$composed = EEM_Order_Adjustments_Repo::compose_order_totals( $target, $adj );
		$check( 'banner grand == composer grand (custom fee+tax included)', abs( $banner - round( max( 0.0, (float) $composed['grand_total'] ), 2 ) ) < 0.005 );
	} else {
		echo "  ..  - no adjusted order in fixtures; parity check skipped\n";
	}

	// --- behavioral: over-discount floors to 0, never negative ----------------
	// Synthetic order: $300 base, a frozen $500 discount (an Edit-Dates shorten
	// of a formerly-larger order). Composed grand must be 0, not −200.
	$synthetic = array( 'total' => 300.00, 'fees' => 0.0, 'tax' => 0.0, 'reservation_id' => 0 );
	$over_adj  = array(
		'custom_items'       => array(),
		'custom_items_total' => 0.0,
		'discount'           => array( 'type' => 'dollar', 'amount' => 500.00, 'reason' => 'test' ),
	);
	$c = EEM_Order_Adjustments_Repo::compose_order_totals( $synthetic, $over_adj );
	$check( 'over-discount ($500 on $300) floors grand to $0, not negative', (float) $c['grand_total'] >= -0.005 && (float) $c['grand_total'] < 0.005 );
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
