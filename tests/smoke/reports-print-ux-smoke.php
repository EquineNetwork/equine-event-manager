<?php
/**
 * Reports + Stall-chart print UX smoke (2.7.198).
 *
 * - Reports: the confusing "Export all reports as ZIP" card is removed (the
 *   backend ZIP path stays, smoke-tested by c15e); PDF exports open INLINE in a
 *   new tab (no Chrome "insecure download blocked"); CSV still downloads.
 * - Stall chart print view: a ?view=location|customer|both choice renders only
 *   the chosen section(s), with a toolbar toggle; the Print View button passes
 *   the current tab; print CSS tones down the green "Available" flood.
 *
 * View filtering was browser-verified (view=customer → only the By Customer
 * roster, 0 availability pills).
 *
 * Run: wp eval-file tests/smoke/reports-print-ux-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$root    = dirname( __DIR__, 2 );
$reports = (string) file_get_contents( $root . '/admin/class-eem-reports-page.php' );
$admin   = (string) file_get_contents( $root . '/admin/class-equine-event-manager-admin.php' );
$css     = (string) file_get_contents( $root . '/assets/css/admin.css' );

// --- Reports: ZIP card removed --------------------------------------------
$check( 'ZIP card is no longer rendered', ! str_contains( $reports, '$this->render_zip_card(' ) );
$check( 'render_zip_card method removed', ! str_contains( $reports, 'function render_zip_card(' ) );
$check( 'subtitle no longer mentions ZIP', ! str_contains( $reports, 'grab everything at once as a ZIP' ) );
// backend ZIP path retained (smoke-tested elsewhere).
$check( 'backend generate_zip retained', str_contains( $reports, 'private static function generate_zip(' ) );

// --- Reports: PDF opens inline in a new tab -------------------------------
$check( 'PDF export form opens in a new tab', str_contains( $reports, "( 'pdf' === \$format ) ? ' target=\"_blank\"' : ''" ) );
$check( 'PDF served inline, others as attachment', str_contains( $reports, "( 'pdf' === \$ext ) ? 'inline' : 'attachment'" ) );

// --- Print view: choose section -------------------------------------------
$check( 'print page reads a ?view param', str_contains( $admin, "\$pv_view = isset( \$_GET['view'] )" ) );
$check( 'view is whitelisted to location/customer/both', str_contains( $admin, "array( 'location', 'customer', 'both' ), true )" ) );
$check( 'By Location gated on view != customer', str_contains( $admin, "'customer' !== \$pv_view && ! empty( \$grid['stall_rows'] )" ) );
$check( 'By Customer gated on view != location', str_contains( $admin, "'location' !== \$pv_view && ! empty( \$order_rows )" ) );
$check( 'toolbar has the 3-way view toggle', str_contains( $admin, 'pv-view-toggle' ) && str_contains( $admin, 'pv-view-btn' ) );
$check( 'Print View button passes the current tab as view', str_contains( $admin, "'&view=' . ( 'customer' === \$tab ? 'customer' : 'location' )" ) );

// --- Print CSS ------------------------------------------------------------
$check( 'CSS styles the view toggle', str_contains( $css, '.pv-view-btn' ) && str_contains( $css, '.pv-view-btn.is-active' ) );
$check( 'print CSS tones down Available cells', (bool) preg_match( '/@media print\b.*\.pv-occ-avail\s*\{[^}]*background:\s*transparent/s', $css ) );
$check( 'view toggle uses color-only hover (no underline)', ! preg_match( '/\.pv-view-btn[^{]*\{[^}]*text-decoration:\s*underline/', $css ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
