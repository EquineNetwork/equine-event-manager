<?php
/**
 * Smoke — Stall & RV Charts blue metrics bar (ROADMAP v1 #26).
 *
 * The chart detail page's KPI bar is restyled to the blue Daily-Movement hero
 * look and enriched with "In Use" metrics. This guards the markup + styling
 * against regression: the six metric cells are emitted, the In-Use values are
 * wired to the grid's peak-used counts, and the bar carries the navy gradient
 * (no longer a white card). Pure file-scan — runs without WordPress.
 *
 * Run: wp eval-file tests/smoke/stall-chart-metrics-bar-smoke.php
 *      (or: php tests/smoke/stall-chart-metrics-bar-smoke.php)
 */

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$root  = dirname( __DIR__, 2 );
$admin = (string) file_get_contents( $root . '/admin/class-equine-event-manager-admin.php' );
$css   = (string) file_get_contents( $root . '/assets/css/admin.css' );

// Collapse whitespace so substring checks survive re-alignment.
$flat = static function ( string $s ): string { return preg_replace( '/\s+/', ' ', $s ); };
$admin_flat = $flat( $admin );

// --- markup: the six metric cells (3 per inventory section) -----------------
foreach ( array(
	'Total Stalls', 'In Use Stalls', 'Available Stalls',
	'Total RV Lots', 'In Use RV Lots', 'Available RV Lots',
) as $label ) {
	$check( "metric present: {$label}", false !== strpos( $admin, $label ) );
}

// --- In-Use values wired to the grid's peak-used counts ---------------------
$check( 'In-Use stalls read peak_stalls_used', false !== strpos( $admin_flat, "\$eem_used_stalls = (int) ( \$grid['peak_stalls_used']" ) );
$check( 'In-Use RV reads peak_rv_used', false !== strpos( $admin_flat, "\$eem_used_rv = (int) ( \$grid['peak_rv_used']" ) );
$check( 'Available stalls = total - in use', false !== strpos( $admin_flat, '$eem_avail_stalls = max( 0, $stall_count - $eem_used_stalls )' ) );

// --- the bar keeps its filter hooks (data-inv-section) ----------------------
$check( 'bar keeps stalls inv-section hook', substr_count( $admin, 'data-inv-section="stalls"' ) >= 3 );
$check( 'bar keeps rv inv-section hook', substr_count( $admin, 'data-inv-section="rv"' ) >= 3 );

// --- CSS: blue gradient band, white numbers, cyan "available" ---------------
$kpi_css = '';
if ( preg_match( '/\.eem-shell-page--stall-charts \.eem-sc-kpi-bar \{.*?\}/s', $css, $m ) ) {
	$kpi_css = $m[0];
}
$check( 'KPI bar uses the navy gradient', false !== strpos( $kpi_css, 'linear-gradient(135deg, #1668F2' ) );
$check( 'KPI bar no longer a white card', false === strpos( $kpi_css, 'background: #fff' ) );
$check( 'KPI numbers render white', (bool) preg_match( '/\.eem-sc-kpi-num \{[^}]*color: #fff/', $css ) );
$check( 'available metric uses cyan accent', (bool) preg_match( '/\.eem-sc-kpi-num--green \{ color: #00e5ee/', $css ) );

echo "\nNOTE: the rendered numbers + visual band are confirmed in browser on a\n";
echo "chart-enabled reservation (e.g. NTR 6519).\n";
echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
