<?php
/**
 * Reservation overview "Revenue" = net collected smoke (bug #16).
 *
 * The reservation editor overview metric labeled "Revenue"
 * (EEM_Admin::get_reservation_overview_data → revenue_total) must sum the money
 * actually COLLECTED per order, net of refunds (ledger net collected) — NOT the
 * gross base order total, which would count unpaid orders, ignore refunds, and
 * miss custom-item / discount adjustments. Matches the Dashboard revenue
 * definition (2.7.721).
 *
 * Run: wp eval-file tests/smoke/reservation-revenue-net-collected-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- source presence: revenue accumulates get_net_collected, not base total --
$src   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$start = strpos( $src, 'function get_reservation_overview_data' );
$end   = strpos( $src, "\n\tprivate function ", $start + 20 );
if ( false === $end ) { $end = strpos( $src, "\n\tpublic function ", $start + 20 ); }
$fn    = ( false !== $start ) ? substr( $src, $start, ( false !== $end ? $end - $start : 4000 ) ) : '';
$check( 'get_reservation_overview_data body isolated', '' !== $fn );
$check(
	'revenue_total accumulates ledger get_net_collected()',
	false !== strpos( $fn, '$this->orders_repository->get_net_collected(' )
);
$check(
	'revenue_total no longer blindly sums $order[total]',
	false === strpos( $fn, "\$revenue_total            += (float) ( isset( \$order['total'] ) ? \$order['total'] : 0 );" )
);

// --- behavioral: an unpaid order contributes $0 to revenue -------------------
$repo   = new EEM_Orders_Repository();
$unpaid = null;
foreach ( $repo->get_orders() as $o ) {
	if ( in_array( $o['status_slug'] ?? '', array( 'unpaid', 'invoice-sent' ), true ) ) { $unpaid = $o; break; }
}
if ( $unpaid && method_exists( $repo, 'get_net_collected' ) ) {
	$net = (float) $repo->get_net_collected( (string) $unpaid['order_key'], $unpaid );
	$check( 'unpaid order contributes $0 collected (not its base total)', $net < 0.005 );
} else {
	echo "  ..  - no unpaid order in fixtures; behavioral check skipped\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
