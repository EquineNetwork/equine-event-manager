<?php
/**
 * Reports booked-total adjustment-awareness smoke (bug #18).
 *
 * The Reports repo's revenue figures (Orders report Total column, Revenue report
 * Total + Net, Reservations report revenue aggregate, Customer LTV) must reflect
 * the BOOKED grand total = base + admin-added custom line items − discount, not
 * the bare stored $order['total']. Otherwise adjusted orders are under-reported.
 * The order_grand_total() helper is a no-op for legacy / unadjusted orders.
 *
 * Run: wp eval-file tests/smoke/reports-grand-total-adjustments-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- source presence: the four aggregations route through order_grand_total ---
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-reports-repo.php' );
$check( 'order_grand_total() helper defined', false !== strpos( $src, 'private function order_grand_total( array $order ): float' ) );
$check( 'revenue report Total routes through order_grand_total', false !== strpos( $src, '$total    = $this->order_grand_total( $o );' ) );
$check( 'reservations rev routes through order_grand_total', false !== strpos( $src, "\$agg[ \$key ]['rev']    += \$this->order_grand_total( \$o );" ) );
$check( 'customer LTV routes through order_grand_total', false !== strpos( $src, "\$agg[ \$key ]['ltv'] += \$this->order_grand_total( \$o );" ) );
$check( 'orders report Total column routes through order_grand_total', false !== strpos( $src, '$this->money( $this->order_grand_total( $o ) )' ) );

// --- behavioral: helper lifts adjusted orders, no-ops unadjusted ones --------
if ( class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
	$repo   = new EEM_Orders_Repository();
	$m = new ReflectionMethod( 'EEM_Reports_Repo', 'order_grand_total' );
	$m->setAccessible( true );
	$reports = new EEM_Reports_Repo();

	$adjusted = null; $plain = null;
	foreach ( $repo->get_orders() as $o ) {
		$a = EEM_Order_Adjustments_Repo::get_for_order( $o['order_key'] ?? '' );
		if ( null === $adjusted && ( ! empty( $a['custom_items'] ) || ! empty( $a['discount'] ) ) ) { $adjusted = $o; }
		if ( null === $plain && empty( $a['custom_items'] ) && empty( $a['discount'] ) ) { $plain = $o; }
		if ( $adjusted && $plain ) { break; }
	}
	if ( $adjusted ) {
		$g = (float) $m->invoke( $reports, $adjusted );
		$check( 'adjusted order booked total diverges from base', abs( $g - (float) $adjusted['total'] ) > 0.005 );
	} else {
		echo "  ..  - no adjusted order in fixtures; lift check skipped\n";
	}
	if ( $plain ) {
		$g = (float) $m->invoke( $reports, $plain );
		$check( 'unadjusted order booked total == base (no-op)', abs( $g - (float) $plain['total'] ) < 0.005 );
	} else {
		echo "  ..  - no unadjusted order in fixtures; no-op check skipped\n";
	}
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
