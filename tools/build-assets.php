<?php
/**
 * Production asset minifier (ship-readiness 3.4 / task #43).
 *
 * Generates `*.min.css` siblings for the plugin's large stylesheets so the admin
 * + public pages can serve a smaller payload in production. The enqueue layer
 * (EEM_Admin::asset_src) loads the `.min` file only when it exists AND WP_DEBUG
 * is off AND the `eem_use_minified_assets` filter is true — otherwise it falls
 * back to the readable source, so a missing or stale min file can never break a
 * page.
 *
 * The CSS minifier is intentionally conservative and string-safe: it protects
 * quoted strings (so a `content: "a , b"` can't be corrupted), strips comments,
 * collapses whitespace, and removes spaces only around structural punctuation.
 * It does NOT attempt risky value rewriting (color shortening, unit stripping).
 *
 * JS minification is deliberately out of scope here — doing it safely needs a
 * real parser (string/regex-literal/ASI hazards), not a regex pass. The JS files
 * continue to ship unminified until a proper minifier is wired (tracked as a
 * follow-up). CSS is the larger payload anyway.
 *
 * Usage (run before committing a release, from the plugin root):
 *   php tools/build-assets.php
 *
 * Run by the freshness smoke (tests/smoke/asset-min-fresh-smoke.php) too, which
 * fails if a committed `.min.css` is stale relative to its source.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

/**
 * The stylesheets that get a minified sibling. Keep this list in sync with the
 * enqueue sites that call EEM_Admin::asset_src().
 *
 * @return string[]
 */
function eem_css_targets(): array {
	return array(
		'assets/css/admin.css',
		'assets/css/public.css',
	);
}

/**
 * Conservatively minify a CSS string without risking value corruption.
 *
 * @param string $css Source CSS.
 * @return string Minified CSS.
 */
function eem_minify_css( string $css ): string {
	// Large stylesheets (admin.css is ~800KB) blow PCRE's default backtrack /
	// recursion limits, which makes preg_replace return null and silently wipe the
	// file. Raise the limits well above any single stylesheet's size.
	ini_set( 'pcre.backtrack_limit', '100000000' );
	ini_set( 'pcre.recursion_limit', '100000000' );

	// Guard: any pass that returns null (still hit a limit) aborts loudly rather
	// than emitting an empty file.
	$guard = static function ( $result, string $stage ) {
		if ( null === $result ) {
			fwrite( STDERR, "ABORT: regex pass '{$stage}' returned null (PCRE limit)\n" );
			exit( 1 );
		}
		return (string) $result;
	};

	// 1. Strip /* … */ comments FIRST. This must precede string protection: CSS
	// comments routinely contain apostrophes/quotes ("don't", "user's"), and
	// protecting strings first would let a quote inside one comment open a bogus
	// "string" that swallows real CSS (braces and all) up to the next quote. The
	// opposite hazard (a literal `/* */` inside a content string) effectively
	// never occurs, so comments-first is the safe order for real stylesheets.
	$css = $guard( preg_replace( '#/\*.*?\*/#s', '', $css ), 'strip-comments' );

	// 2. Protect quoted strings so later passes can't touch their interiors. A
	// simple, NON-recursive pattern is used deliberately: the escaped-quote-aware
	// form `(?:[^"\\]|\\.)*` recurses per character and exhausts the PCRE
	// recursion / JIT stack on an 800KB+ stylesheet. CSS effectively never escapes
	// quotes inside content strings, so `"[^"]*"` is safe here.
	$strings = array();
	$css     = $guard(
		preg_replace_callback(
			'/"[^"]*"|\'[^\']*\'/',
			static function ( $m ) use ( &$strings ) {
				$key             = "\x01" . count( $strings ) . "\x01";
				$strings[ $key ] = $m[0];
				return $key;
			},
			(string) $css
		),
		'protect-strings'
	);

	// 3. Collapse all whitespace runs to a single space.
	$css = $guard( preg_replace( '/\s+/', ' ', (string) $css ), 'collapse-whitespace' );

	// 4. Remove spaces ONLY around punctuation that never carries semantic
	// whitespace: block braces, the declaration separator, and value/selector
	// commas. Deliberately EXCLUDES `:` (selector `a :hover` ≠ `a:hover`), the
	// combinators `> ~ +`, and especially `+`/`-` — `calc(100% + 4px)` REQUIRES
	// the surrounding spaces and becomes invalid without them.
	$css = $guard( preg_replace( '/\s*([{};,])\s*/', '$1', (string) $css ), 'strip-structural-space' );

	// 5. Drop the redundant final semicolon before a closing brace.
	$css = str_replace( ';}', '}', (string) $css );

	$css = trim( (string) $css );

	// 6. Restore protected strings.
	return strtr( $css, $strings );
}

/**
 * Build every minified target, with output sanity checks. Returns false if any
 * target failed its check (the min file is NOT written in that case).
 *
 * @param string $root Plugin root with trailing slash.
 * @return bool
 */
function eem_build_assets( string $root ): bool {
	$done = 0;
	foreach ( eem_css_targets() as $eem_rel ) {
		$src = $root . $eem_rel;
		if ( ! is_readable( $src ) ) {
			fwrite( STDERR, "skip (missing): {$eem_rel}\n" );
			continue;
		}
		$min_rel  = preg_replace( '/\.css$/', '.min.css', $eem_rel );
		$min_path = $root . $min_rel;

		$source   = (string) file_get_contents( $src );
		$minified = eem_minify_css( $source );

		// Safety on the OUTPUT (the meaningful invariants — string-vs-comment
		// ordering makes a source-side rule-count comparison unreliable):
		//   - the minified CSS is brace-balanced,
		//   - it actually shrank but didn't lose the bulk of its content,
		//   - it still carries a large number of rule blocks (didn't get nuked).
		// Count STRUCTURAL braces only — strip strings first so a `content: "{"`
		// glyph can't read as an unbalanced block.
		$min_structural = preg_replace( '/"[^"]*"|\'[^\']*\'/', '', $minified );
		$min_balanced   = substr_count( (string) $min_structural, '{' ) === substr_count( (string) $min_structural, '}' );
		$rule_blocks    = substr_count( (string) $min_structural, '{' );
		$shrank         = strlen( $minified ) < strlen( $source );
		$kept_bulk      = strlen( $minified ) > strlen( $source ) * 0.3;
		if ( ! $min_balanced || ! $shrank || ! $kept_bulk || $rule_blocks < 1 ) {
			fwrite( STDERR, "ABORT {$eem_rel}: sanity check failed (balanced={$min_balanced} shrank={$shrank} kept_bulk={$kept_bulk} rules={$rule_blocks})\n" );
			return false;
		}

		file_put_contents( $min_path, $minified );
		$before = strlen( $source );
		$after  = strlen( $minified );
		$pct    = $before > 0 ? round( 100 * ( 1 - $after / $before ) ) : 0;
		echo "{$min_rel}: " . number_format( $before ) . ' -> ' . number_format( $after ) . " bytes (-{$pct}%)\n";
		$done++;
	}
	echo "minified {$done} file(s)\n";
	return true;
}

// Only build when this file is the script being executed directly (e.g.
// `php tools/build-assets.php`). When a smoke or other tool just wants
// eem_minify_css()/eem_css_targets(), it `require`s this file without triggering
// a rebuild.
if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) === realpath( __FILE__ ) ) {
	exit( eem_build_assets( dirname( __DIR__ ) . '/' ) ? 0 : 1 );
}
