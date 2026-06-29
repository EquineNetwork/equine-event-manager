<?php
/**
 * Stall chart "suggested vs saved" clarity smoke (2.7.194).
 *
 * The chart auto-fills unassigned orders into the first open stalls for a
 * PROPOSED layout, persisted only when the admin clicks Generate Assignments or
 * moves a name manually. This adds: a per-cell suggested flag + dashed "draft"
 * pill styling, an "N orders not saved yet" banner, and an inline Generate link.
 *
 * Source-structure assertions only — the runtime render (banner copy, dashed
 * italic amber pills, Generate link wiring) was browser-verified on the live
 * chart at ship time.
 *
 * Run: wp eval-file tests/smoke/stall-chart-suggested-vs-saved-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admin_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/class-equine-event-manager-admin.php' );
$css_src   = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/admin.css' );

// --- grid builder: saved-vs-suggested distinction + count ------------------
$check( 'grid marks stall cells with a saved-set comparison',
	str_contains( $admin_src, '$saved_stall_set = array_fill_keys' ) && str_contains( $admin_src, "'suggested'        => \$unit_suggested" ) );
$check( 'grid marks RV cells suggested too',
	str_contains( $admin_src, '$saved_rv_set = array_fill_keys' ) && str_contains( $admin_src, "'suggested'        => \$rv_unit_suggested" ) );
$check( 'grid tracks unsaved orders + returns a count',
	str_contains( $admin_src, '$unsaved_order_keys' ) && str_contains( $admin_src, "'unsaved_order_count' => count( \$unsaved_order_keys )" ) );
// saved = present in the order's persisted "Assigned Stall Units" notes.
$check( 'suggested = NOT in the persisted assigned-units notes',
	str_contains( $admin_src, '$unit_suggested = ! isset( $saved_stall_set[ (string) $unit ] )' ) );

// #55: the auto-SUGGEST visual treatment was DELIBERATELY REMOVED (Whitney
// 2026-06-24): "stalls stay Available until manually assigned; no auto-suggest
// push." The grid still COMPUTES saved-vs-unsaved (asserted above, used by the
// list-page count), but the per-pill dashed/italic "draft" styling, the
// data-suggested attribute, the SR hint, and the amber suggestion banner were
// all dropped. Assert the removal so the smoke matches shipped behavior.
$check( 'suggestion banner is gated off (Whitney 2026-06-24 removal)',
	str_contains( $admin_src, 'Suggestion banner removed (Whitney 2026-06-24)' ) );
$check( 'no per-pill --suggested draft modifier emitted',
	! str_contains( $admin_src, "eem-occ-pill--suggested' : ''" ) && ! str_contains( $admin_src, 'data-suggested="' ) );
$check( 'no "(suggested — not saved)" SR hint emitted',
	! str_contains( $admin_src, '(suggested — not saved)' ) );
$check( 'no dashed-legend tip line emitted',
	! str_contains( $admin_src, 'A dashed outline means the placement is auto-suggested' ) );

// --- CSS -------------------------------------------------------------------
// The dashed/italic draft pill styling was removed with the feature; only the
// chevron-color hook survives (harmless dead rule). Assert no dashed-italic
// draft treatment remains.
$check( 'CSS: no dashed+italic suggested-pill draft styling',
	! ( str_contains( $css_src, '.eem-occ-pill--suggested' ) && str_contains( $css_src, 'border-style: dashed' ) && str_contains( $css_src, 'font-style: italic' ) ) );
$check( 'CSS: inline link-btn defined', str_contains( $css_src, '.eem-link-btn' ) );
// Hygiene: no underline added anywhere in the new CSS.
$check( 'CSS: link-btn hover uses color only (no underline)',
	(bool) preg_match( '/\.eem-link-btn:hover[^{]*\{[^}]*color:[^}]*\}/', $css_src ) && ! preg_match( '/\.eem-link-btn[^{]*\{[^}]*text-decoration:\s*underline/', $css_src ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
