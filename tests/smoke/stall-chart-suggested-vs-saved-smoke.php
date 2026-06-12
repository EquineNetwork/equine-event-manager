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

// --- pill styling: suggested modifier --------------------------------------
$check( 'occupied pill carries --suggested + data-suggested when unsaved',
	str_contains( $admin_src, "eem-occ-pill--suggested' : ''" ) && str_contains( $admin_src, 'data-suggested="' ) );
$check( 'suggested pill has a screen-reader "not saved" hint',
	str_contains( $admin_src, '(suggested — not saved)' ) );

// --- guidance banner -------------------------------------------------------
$check( 'unsaved banner rendered only when count > 0',
	str_contains( $admin_src, '$eem_unsaved = isset( $grid[\'unsaved_order_count\']' ) && str_contains( $admin_src, 'if ( $eem_unsaved > 0 )' ) );
$check( 'banner has pluralized count copy',
	str_contains( $admin_src, "aren't saved yet" ) || str_contains( $admin_src, "aren\\'t saved yet" ) );
$check( 'banner Generate link reuses the real auto-assign action',
	str_contains( $admin_src, 'class="eem-link-btn" data-eem-action="stall-chart-auto-assign-all"' ) );
$check( 'Tip line explains the dashed = suggested legend',
	str_contains( $admin_src, 'A dashed outline means the placement is auto-suggested' ) );

// --- CSS -------------------------------------------------------------------
$check( 'CSS: suggested pill is dashed + italic (draft look)',
	str_contains( $css_src, '.eem-occ-pill--suggested' ) && str_contains( $css_src, 'border-style: dashed' ) && str_contains( $css_src, 'font-style: italic' ) );
$check( 'CSS: unsaved banner styled', str_contains( $css_src, '.eem-stall-chart-unsaved-banner' ) );
$check( 'CSS: inline link-btn defined', str_contains( $css_src, '.eem-link-btn' ) );
// Hygiene: no underline added anywhere in the new CSS.
$check( 'CSS: link-btn hover uses color only (no underline)',
	(bool) preg_match( '/\.eem-link-btn:hover[^{]*\{[^}]*color:[^}]*\}/', $css_src ) && ! preg_match( '/\.eem-link-btn[^{]*\{[^}]*text-decoration:\s*underline/', $css_src ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
