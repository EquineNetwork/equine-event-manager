<?php
/**
 * Minified-asset freshness smoke (ship-readiness 3.4 / task #43).
 *
 * Guards against shipping a stale `*.min.css`: re-minifies each source stylesheet
 * with the exact build-step function and asserts the committed `.min` file (a)
 * exists, (b) is byte-identical to a fresh build, and (c) is meaningfully smaller
 * than its source. If someone edits admin.css/public.css and forgets to run
 * `php tools/build-assets.php`, this fails loudly instead of letting production
 * serve outdated styles.
 *
 * Run via: wp eval-file tests/smoke/asset-min-fresh-smoke.php
 *
 * @package EEM_Plugin
 */

$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

$root = defined( 'EQUINE_EVENT_MANAGER_PATH' ) ? EQUINE_EVENT_MANAGER_PATH : dirname( __DIR__, 2 ) . '/';

// Pull in eem_minify_css() + eem_css_targets() WITHOUT triggering a rebuild (the
// build script self-guards on SCRIPT_FILENAME).
require_once $root . 'tools/build-assets.php';

if ( ! function_exists( 'eem_minify_css' ) || ! function_exists( 'eem_css_targets' ) ) {
	echo "  FAIL — build-assets.php did not expose its functions\n0 passed, 1 failed\n";
	return;
}

foreach ( eem_css_targets() as $rel ) {
	$src     = $root . $rel;
	$min_rel = preg_replace( '/\.css$/', '.min.css', $rel );
	$min     = $root . $min_rel;

	$chk( is_readable( $src ), "source present: {$rel}" );
	$chk( is_readable( $min ), "minified present: {$min_rel}" );
	if ( ! is_readable( $src ) || ! is_readable( $min ) ) {
		continue;
	}

	$source    = (string) file_get_contents( $src );
	$committed = (string) file_get_contents( $min );
	$fresh     = eem_minify_css( $source );

	$chk( $committed === $fresh, "{$min_rel} is up to date (run tools/build-assets.php if this fails)" );
	$chk( strlen( $committed ) < strlen( $source ), "{$min_rel} is smaller than source (" . number_format( strlen( $source ) ) . ' → ' . number_format( strlen( $committed ) ) . ' bytes)' );

	// Structural parity: the minified rule-block count matches the source's
	// (comment/string-stripped), proving no rules were dropped.
	$strip = static function ( $css ) {
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = preg_replace( "/\"[^\"]*\"|'[^']*'/", '', (string) $css );
		return (string) $css;
	};
	$src_braces = substr_count( $strip( $source ), '{' );
	$min_braces = substr_count( $strip( $committed ), '{' );
	$chk( $src_braces === $min_braces, "{$min_rel} preserves all {$src_braces} rule blocks (got {$min_braces})" );
}

echo "\n$pass passed, $fail failed\n";
