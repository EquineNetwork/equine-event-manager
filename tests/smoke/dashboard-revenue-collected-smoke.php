<?php
/**
 * Dashboard "Revenue" KPI = amount COLLECTED, not booked total (Whitney decision
 * 2026-06-27). A partially-paid order must contribute only what's been paid to
 * Revenue, and only what's still owed to Outstanding.
 *
 * Pure-logic test of EEM_Dashboard_Repo::compute_revenue_outstanding_totals()
 * with synthetic grouped-order arrays (no DB).
 *
 * Run via: wp eval-file tests/smoke/dashboard-revenue-collected-smoke.php
 *
 * @package EEM_Plugin
 */

$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.01; };

if ( ! class_exists( 'EEM_Dashboard_Repo' ) ) {
	echo "  FAIL — EEM_Dashboard_Repo missing\n0 passed, 1 failed\n";
	return;
}

$orders = array(
	array( 'status_slug' => 'paid',           'total' => 200.0, 'amount_paid' => 200.0 ), // fully collected
	array( 'status_slug' => 'partially-paid', 'total' => 200.0, 'amount_paid' => 50.0 ),  // $50 in, $150 owed
	array( 'status_slug' => 'unpaid',         'total' => 100.0, 'amount_paid' => 0.0 ),    // nothing in, $100 owed
	array( 'status_slug' => 'invoice-sent',   'total' => 80.0,  'amount_paid' => 0.0 ),    // nothing in, $80 owed
);

$repo = new EEM_Dashboard_Repo();
$m = new ReflectionMethod( 'EEM_Dashboard_Repo', 'compute_revenue_outstanding_totals' );
$m->setAccessible( true );
$res = $m->invoke( $repo, $orders );

// Revenue = collected = 200 + 50 + 0 + 0 = 250 (NOT 400 booked).
$chk( $approx( $res['revenue'], 250.0 ), 'Revenue counts collected only ($250, not $400 booked)' );
// Outstanding = remaining = 150 + 100 + 80 = 330 (partial counts $150, not $200).
$chk( $approx( $res['outstanding_amount'], 330.0 ), 'Outstanding counts remaining balance ($330)' );
$chk( 3 === (int) $res['outstanding_count'], 'Outstanding count = 3 orders with a balance' );

echo "\n$pass passed, $fail failed\n";
