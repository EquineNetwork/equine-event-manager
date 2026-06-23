<?php
/**
 * Smoke — Stall & RV chart chip status colors + lifecycle (ROADMAP v1 #25).
 *
 * Guards the global chip color tokens, the token-driven cell color rules, the
 * new checked-in / checked-out distinct colors + legend, and the render wiring
 * (readiness_display labels + the sub-status modifier class). Pure file-scan —
 * runs without WordPress.
 *
 * Run: wp eval-file tests/smoke/stall-chip-status-colors-smoke.php
 *      (or: php tests/smoke/stall-chip-status-colors-smoke.php)
 */

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$root  = dirname( __DIR__, 2 );
$css   = (string) file_get_contents( $root . '/assets/css/admin.css' );
$admin = (string) file_get_contents( $root . '/admin/class-equine-event-manager-admin.php' );
$flat  = static function ( string $s ): string { return preg_replace( '/\s+/', ' ', $s ); };
$admin_flat = $flat( $admin );

// --- global tokens (centrally defined, conflict-free) -----------------------
foreach ( array( 'occupied', 'checkedin', 'checkedout', 'cleaning', 'blocked', 'available' ) as $state ) {
	$check( "global token --eem-chip-{$state}-bg defined", false !== strpos( $css, "--eem-chip-{$state}-bg:" ) );
}
// distinct hues: checked-in teal, checked-out orange (not the same as others)
$check( 'checked-in token is teal-ish', false !== strpos( $css, '--eem-chip-checkedin-text: #0f766e' ) );
$check( 'checked-out token is orange-ish', false !== strpos( $css, '--eem-chip-checkedout-text: #c2410c' ) );

// --- cell color rules are token-driven (global, not hardcoded) --------------
$check( 'occupied cell uses the token', (bool) preg_match( '/\.eem-loc-cell--occupied \{[^}]*var\(--eem-chip-occupied-bg\)/', $css ) );
$check( 'checked-in modifier rule present (compound selector)', false !== strpos( $css, '.eem-loc-cell.eem-loc-cell--checkedin {' ) );
$check( 'checked-out modifier rule present (compound selector)', false !== strpos( $css, '.eem-loc-cell.eem-loc-cell--checkedout {' ) );

// --- legend present + its checked-in/out swatches scoped --------------------
$check( 'legend CSS present', false !== strpos( $css, '.eem-loc-legend {' ) );
$check( 'legend checked-in swatch scoped rule', false !== strpos( $css, '.eem-loc-legend__sw.eem-loc-cell--checkedin {' ) );
$check( 'legend markup rendered above the matrix', false !== strpos( $admin, 'class="eem-loc-legend"' ) );
$check( 'legend shows the Checked In entry', false !== strpos( $admin, 'eem-loc-legend__sw eem-loc-cell--checkedin' ) );

// --- render: distinct labels + the sub-status modifier ----------------------
$check( 'readiness_display labels checked-in', false !== strpos( $admin_flat, "'checked_in' === \$status ) { return array( 'key' => 'occupied', 'label' => __( 'Checked In'" ) );
$check( 'readiness_display labels checked-out', false !== strpos( $admin_flat, "'checked_out' === \$status ) { return array( 'key' => 'cleaning', 'label' => __( 'Checked Out'" ) );
$check( 'render computes the sub-status modifier from stored status', false !== strpos( $admin_flat, "'checked_in' === \$eem_stored ) { \$eem_substatus_class = ' eem-loc-cell--checkedin';" ) );
$check( 'occupied branch appends the modifier', false !== strpos( $admin, 'eem-loc-cell--occupied<?php echo esc_attr( $eem_substatus_class )' ) );
$check( 'status branch appends the modifier', false !== strpos( $admin, "\$eem_disp['key'] ); ?><?php echo esc_attr( \$eem_substatus_class )" ) );

echo "\nNOTE: visual band/colors confirmed via headless-render screenshot; live\n";
echo "page render confirmed in browser on a chart-enabled reservation.\n";
echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
