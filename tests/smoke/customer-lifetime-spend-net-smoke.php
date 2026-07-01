<?php
/**
 * Customer "Lifetime Spend" / "Total Spent" = net collected smoke (bug #19).
 *
 * The Customer Profile "Lifetime Spend" KPI and the Customers list "Total Spent"
 * column represent money the customer actually PAID, net of refunds (ledger net
 * collected) — NOT the bare component base $order['total'] (which misses
 * admin-added items / discount and does not net refunds). Order-value columns
 * (per-order "Total", per-reservation total, average order value) use the booked
 * grand (adjustment-aware) instead.
 *
 * Run: wp eval-file tests/smoke/customer-lifetime-spend-net-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- source presence ---------------------------------------------------------
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-customer-profile-repo.php' );
$check( 'order_net_collected() helper defined', false !== strpos( $src, 'private function order_net_collected( array $o ): float' ) );
$check( 'order_grand_total() helper defined', false !== strpos( $src, 'private function order_grand_total( array $o ): float' ) );
$check( 'Lifetime Spend accumulates net collected', false !== strpos( $src, '$spend += $this->order_net_collected( $o );' ) );
$check( 'Total Spent (list) accumulates net collected', false !== strpos( $src, "\$by_email[ \$email ]['spent_raw'] += \$this->order_net_collected( \$o );" ) );
$check( 'avg order value uses booked grand', false !== strpos( $src, '$gross += $this->order_grand_total( $o );' ) );
$check( 'per-order Total column uses booked grand', false !== strpos( $src, "'total'        => \$this->money( \$this->order_grand_total( \$o ) )" ) );
$check( 'per-reservation total uses booked grand', false !== strpos( $src, "\$by_res[ \$key ]['total_raw'] += \$this->order_grand_total( \$o );" ) );

// --- behavioral: net collected helper reads the ledger ----------------------
$repo    = new EEM_Customer_Profile_Repo();
$orders  = new EEM_Orders_Repository();
$m_net   = new ReflectionMethod( 'EEM_Customer_Profile_Repo', 'order_net_collected' );
$m_net->setAccessible( true );

// unpaid order → $0 spent
$unpaid = null;
foreach ( $orders->get_orders() as $o ) {
	if ( in_array( $o['status_slug'] ?? '', array( 'unpaid', 'invoice-sent' ), true ) ) { $unpaid = $o; break; }
}
if ( $unpaid ) {
	$check( 'unpaid order contributes $0 to spend', (float) $m_net->invoke( $repo, $unpaid ) < 0.005 );
} else {
	echo "  ..  - no unpaid order in fixtures; spend-zero check skipped\n";
}

// a refunded order → net collected < its base total (refund netted out)
$refunded = null;
foreach ( $orders->get_orders() as $o ) {
	if ( 'refunded' === ( $o['status_slug'] ?? '' ) && (float) ( $o['total'] ?? 0 ) > 0 ) { $refunded = $o; break; }
}
if ( $refunded ) {
	$net = (float) $m_net->invoke( $repo, $refunded );
	$check( 'refunded order: net collected <= base total (refund netted)', $net <= (float) $refunded['total'] + 0.005 );
} else {
	echo "  ..  - no refunded order in fixtures; refund-net check skipped\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
