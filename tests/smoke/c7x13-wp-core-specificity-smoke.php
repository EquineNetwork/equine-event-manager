<?php
/**
 * C7.X.13 — WP core forms.css specificity-tie fix.
 *
 * Whitney's C7.X.12 visual verify caught a residual seam: the
 * `.eem-price-input` left border-radius was still visibly rounded
 * even though admin.css declared `border-radius: 0 4px 4px 0;`.
 *
 * Root cause: WordPress core `wp-admin/css/forms.css:42-56` applies
 * `border-radius: 2px` to `input[type="number"]` (and 9 other input
 * types). That selector has specificity (0,1,1). Our
 * `.eem-price-input` has specificity (0,1,0). WP core wins, rounds
 * all four corners to 2px → seam visible on the input's left.
 *
 * The C4 → C7.X.4 → C7.X.10 → C7.X.11 → C7.X.12 cascade work was
 * exhaustive on admin-legacy.css but never enumerated WP core CSS
 * as a cascade source. FIVE commits looked at the wrong file.
 *
 * Fix: prefix every affix/field input class with `input.` to bump
 * specificity from (0,1,0) to (0,1,1) — tying WP core. At a tie,
 * cascade order wins; admin.css enqueues after WP forms.css → our
 * rule wins. Same fix applied to .eem-pct-input,
 * .eem-repeat-price-in, .eem-zone-price-in, .eem-field-input (all
 * face the identical WP-core override; .eem-price-input was just
 * the most visually obvious manifestation).
 *
 * Structural follow-up landed in CLAUDE.md: extend the
 * `Container-flex parity check` audit sub-step (C7.X.12) with
 * `cross-stylesheet cascade enumeration` — WP core CSS + theme CSS
 * + plugin's own files are ALL in scope when chasing a cascade
 * winner. The five-commit miss came from grepping admin-legacy.css
 * exhaustively without ever looking outside the plugin.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7x13_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.X.13 — WP CORE FORMS.CSS SPECIFICITY-TIE SMOKE ===\n";

$admin_css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
// Strip CSS block comments before bare-selector scanning so that
// class names mentioned in `/* Existing C1.2 primitives reused: ... */`
// audit-trail comments don't trip the absence-assertions.
$admin_css_no_comments = preg_replace( '#/\*.*?\*/#s', '', $admin_css );

// ── [1] Each affix/field input class has the `input.` prefix ─────
echo "\n[1] Selectors prefixed with `input.` to tie WP core specificity\n";

$classes_needing_prefix = array(
	'eem-price-input',
	'eem-pct-input',
	'eem-repeat-price-in',
	'eem-zone-price-in',
	'eem-field-input',
);

foreach ( $classes_needing_prefix as $cls ) {
	// PRESENCE: the prefixed selector appears at least once.
	c7x13_ok( "admin.css declares `input.{$cls}` selector somewhere",
		false !== strpos( $admin_css, "input.{$cls}" ),
		$pass, $fail, $log );

	// ABSENCE: no UNPREFIXED `.classname` rule-opening anywhere in the
	// file. Regex matches `.classname` followed by class-/pseudo-/space
	// /comma/brace boundary but NOT preceded by `input.` (which would
	// indicate the safe prefixed form). Catches future regressions
	// where someone re-introduces the bare class selector.
	// (Allow comments / human-readable text matches — only flag
	// CSS-selector positions: line starts with whitespace then `.cls`,
	// or selector list item `, .cls`.)
	$bare_pattern = '~(?:^|,)\s*\.' . preg_quote( $cls, '~' ) . '(?:[:\s,{]|\s*\{)~m';
	c7x13_ok( "admin.css has 0 unprefixed `.{$cls}` selectors (bare-class rule-openings, comments excluded)",
		0 === preg_match_all( $bare_pattern, $admin_css_no_comments ),
		$pass, $fail, $log );
}

// ── [2] Specifically verify .eem-price-input has correct radii ──
echo "\n[2] .eem-price-input border-radius (left corners 0)\n";

c7x13_ok( 'input.eem-price-input rule has border-radius: 0 ... ... 0 (left corners zero)',
	(bool) preg_match(
		'~input\.eem-price-input\s*\{[^}]*border-radius\s*:\s*0\s+var\(--eem-radius\)\s+var\(--eem-radius\)\s+0\s*;~s',
		$admin_css
	),
	$pass, $fail, $log );

// ── [3] WP core's overriding rule actually exists in this install ─
echo "\n[3] Confirm the WP core forms.css rule we're tying actually exists\n";
$wp_forms_css_path = ABSPATH . 'wp-admin/css/forms.css';
if ( file_exists( $wp_forms_css_path ) ) {
	$wp_forms = file_get_contents( $wp_forms_css_path );
	c7x13_ok( 'WP core forms.css contains `input[type="number"]` selector (root-cause confirmation)',
		false !== strpos( $wp_forms, 'input[type="number"]' ),
		$pass, $fail, $log );
	c7x13_ok( 'WP core forms.css contains `border-radius: 2px` rule (the overriding declaration)',
		false !== strpos( $wp_forms, 'border-radius: 2px;' ),
		$pass, $fail, $log );
} else {
	c7x13_ok( 'WP core forms.css present on disk', false, $pass, $fail, $log, "not at {$wp_forms_css_path}" );
}

// ── [4] Cache-bust constant bumped ──────────────────────────────
echo "\n[4] EQUINE_EVENT_MANAGER_VERSION cache-bust\n";
// C7.X.14 — forward-compatible (each cache-bust bump shouldn't trip).
c7x13_ok( 'EQUINE_EVENT_MANAGER_VERSION >= 2.3.2 (cache-bust at C7.X.13)',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.2', '>=' ),
	$pass, $fail, $log, EQUINE_EVENT_MANAGER_VERSION );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
