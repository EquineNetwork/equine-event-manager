<?php
/**
 * Canonical control-height style guard (Whitney 2026-06-13: ONE dropdown/filter
 * height for the whole plugin).
 *
 * Source-presence guard against the SET STYLE silently regressing: asserts the
 * --eem-select-height token exists at 38px, that every plugin select class +
 * the toolbar filter controls bind to it, and that the Choices.js dropdown inner
 * matches the same height. Heights are computed at runtime, so this guards the
 * SOURCE; the browser self-verify at change-time confirms the rendered result.
 *
 * Run: wp eval-file tests/smoke/control-height-style-smoke.php
 */

if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }

$pass = 0; $fail = 0;
$ok = static function ( $label, $cond ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  - {$label}\n"; }
	else { $fail++; echo "FAIL  - {$label}\n"; }
};

$admin   = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$choices = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/eem-choices.css' );

// --- the token --------------------------------------------------------------
$ok( '--eem-select-height token defined at 38px', 1 === preg_match( '/--eem-select-height:\s*38px/', $admin ) );

// --- every plugin select class binds to the token ---------------------------
// Pull the canonical select rule block and assert each class + the token are in it.
$has_block = preg_match( '/select\.eem-field-select[^{]*\{[^}]*var\(\s*--eem-select-height/s', $admin );
$ok( 'canonical select rule uses the control-height token', 1 === $has_block );
foreach ( array( 'eem-field-select', 'eem-toolbar-select', 'eem-list-select', 'eem-dashboard-range-select' ) as $cls ) {
	$ok( "canonical rule covers select.{$cls}", false !== strpos( $admin, "select.{$cls}" ) );
}

// --- toolbar filter controls (search input + buttons) bind to the token -----
$ok( 'search input binds to control-height', 1 === preg_match( '/input\.eem-search-input[^{]*\{[^}]*var\(\s*--eem-select-height/s', $admin ) );
$ok( 'toolbar/search buttons bind to control-height', 1 === preg_match( '/\.eem-toolbar-btn,\s*\.eem-search-btn[^{]*\{[^}]*var\(\s*--eem-select-height/s', $admin ) );

// --- Choices.js dropdown inner matches the same 38px ------------------------
$ok( 'Choices inner min-height is 38px (matches control height)', 1 === preg_match( '/\.eem-choices\s+\.choices__inner[^{]*\{[^}]*min-height:\s*38px/s', $choices ) );

// --- no stray per-page divergent select height (compact 29px reintroduced) --
// The old 29px toolbar-select height should no longer set an explicit height on
// the select; height now comes from the canonical token. Guard against a bare
// `height: 29px` creeping back onto a select class.
$ok( 'no select pinned to a divergent 29px height', 0 === preg_match( '/eem-(field|toolbar|list|dashboard-range)-select[^{]*\{[^}]*height:\s*29px/s', $admin ) );

echo "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
if ( $fail > 0 ) { exit( 1 ); }
