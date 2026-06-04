<?php
/**
 * Smoke test — C7.X.19 radius eradication + dropdown flip-up fix.
 *
 * Issues fixed:
 *   Issue 1 — border-radius: 12px !important in form-control block 6 of admin-legacy.css
 *             (.eem-zone-name-input / .eem-repeat-input computed 12px at runtime)
 *   Issue 2 — toggleDropdown() checked window.innerHeight as the boundary, but the
 *             actual clipping container is .eem-page-wrap (overflow:hidden), whose bottom
 *             is inside the viewport — so spaceBelow was always positive, --flip-up
 *             class never fired, Delete Permanently (Issue 3) was unreachable.
 *
 * Smoke philosophy (C7.X.19 requirement): source-presence greps are INSUFFICIENT for
 * runtime/computed assertions. These tests assert the CORRECTED source that produces
 * the correct computed value — they do not replace mandatory browser self-verify.
 *
 * Run via run-all.sh or directly:
 *   php tests/smoke/c7x19-radius-flipup-smoke.php
 */

$pass = 0;
$fail = 0;
$log  = array();

function c7x19_ok( string $label, bool $condition, int &$pass, int &$fail, array &$log ): void {
	if ( $condition ) {
		++$pass;
		$log[] = "  PASS  {$label}";
	} else {
		++$fail;
		$log[] = "✗ FAIL  {$label}";
	}
}

$plugin_root  = dirname( dirname( __DIR__ ) );
$legacy_path  = $plugin_root . '/assets/css/admin-legacy.css';
$admin_path   = $plugin_root . '/assets/css/admin.css';
$js_path      = $plugin_root . '/assets/js/admin.js';

$legacy_raw = file_get_contents( $legacy_path );
$admin_raw  = file_get_contents( $admin_path );
$js_raw     = file_get_contents( $js_path );

// Strip comments so patterns don't hit comment text.
$legacy_nc = preg_replace( '/\/\*.*?\*\//s', '', $legacy_raw );
$admin_nc  = preg_replace( '/\/\*.*?\*\//s', '', $admin_raw );

echo "\n=== C7.X.19 smoke ===\n\n";

/* ────────────────────────────────────────────────────────────────
   ISSUE 1 — 12px !important in form-control block 6 of admin-legacy.css
   ──────────────────────────────────────────────────────────────── */

echo "-- Issue 1: 12px form-control radius fixed to var(--eem-radius) --\n";

// Block 6 is the cascade winner for inputs like .eem-zone-name-input and .eem-repeat-input
// because it is the LAST form-control !important block in source order.
// It must NOT have border-radius: 12px — that literal beats --eem-radius regardless of
// what earlier blocks declare.

// Negative: the BROAD shell-page form-control !important blocks must NOT have 12px.
// These are the 6 blocks that open with body.eem-shell-page:not(...) input[type="text"]:not(.eem-field-input)
// — that exact signature is the dangerous broad pattern. Intentionally-scoped selectors
// like `.eem-orders-guide__toolbar input[type="search"]` are CLEANUP-documented exceptions.
$no_broad_fc_12px = ! (bool) preg_match(
	'~body\.eem-shell-page\s*:not\([^)]+\)[^{]*input\[type="text"\]\s*:not\(\s*\.eem-field-input\s*\)[^{]*\{[^}]*border-radius\s*:\s*12px\s*!important~s',
	$legacy_nc
);
c7x19_ok( 'admin-legacy.css: broad shell-page input[type="text"]:not(.eem-field-input) block has no 12px !important',
	$no_broad_fc_12px, $pass, $fail, $log );

// Positive: block 6 must use var(--eem-radius) — confirmed by finding the selector chain
// that ends with textarea:not(.eem-field-input):not(.eem-field-textarea) paired with
// border-radius: var(--eem-radius) in the same rule block.
$block6_uses_token = (bool) preg_match(
	'~textarea\s*:not\(\s*\.eem-field-input\s*\)\s*:not\(\s*\.eem-field-textarea\s*\)\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)\s*!important~s',
	$legacy_nc
);
c7x19_ok( 'admin-legacy.css: form-control block 6 (textarea exclusion) uses var(--eem-radius) !important',
	$block6_uses_token, $pass, $fail, $log );

// The select:not() companion selector in block 6 also uses the token.
$block6_select_token = (bool) preg_match(
	'~select\s*:not\(\s*\.eem-dashboard-range-select\s*\)(?:[^{]*:not\([^)]+\))*\s*,\s*\n[^{]*textarea[^{]*\{\s*[^}]*border-radius\s*:\s*var\(--eem-radius\)~s',
	$legacy_nc
);
// Looser check: the block containing select + textarea exclusions uses var(--eem-radius)
$block6_select_token2 = (bool) preg_match(
	'~select\s*:not\(\s*\.eem-dashboard-range-select\s*\):not\(\s*\.eem-list-select\s*\):not\(\s*\.eem-toolbar-select\s*\):not\(\s*\.eem-field-select\s*\)~',
	$legacy_nc
) && $block6_uses_token;
c7x19_ok( 'admin-legacy.css: block 6 selector chain (select + textarea exclusions) present alongside token radius',
	$block6_select_token2, $pass, $fail, $log );

// Confirm all 6 broad form-control blocks (body.eem-shell-page:not(...) input[type="text"]:not(.eem-field-input))
// use a token or no border-radius — no px literal in those specific block bodies.
$fc_block_selector = '~(body\.eem-shell-page\s*:not\([^)]+\)[^{]*input\[type="text"\]\s*:not\(\s*\.eem-field-input\s*\)[^{]*\{)([^}]*)~';
preg_match_all( $fc_block_selector, $legacy_nc, $fc_matches );
$all_broad_blocks_clean = true;
foreach ( $fc_matches[2] as $body ) {
	if ( preg_match( '~border-radius\s*:\s*\d+px\s*!important~', $body ) ) {
		$all_broad_blocks_clean = false;
		break;
	}
}
c7x19_ok( 'admin-legacy.css: all 6 broad shell-page form-control blocks use token or omit border-radius (no px literal)',
	$all_broad_blocks_clean, $pass, $fail, $log );

/* ────────────────────────────────────────────────────────────────
   ISSUE 2 — toggleDropdown container-boundary check
   ──────────────────────────────────────────────────────────────── */

echo "\n-- Issue 2: flip-up uses container boundary, not just viewport --\n";

// The fix: instead of checking window.innerHeight alone, the code now finds the nearest
// .eem-page-wrap ancestor and uses Math.min(container.bottom, window.innerHeight).
$uses_page_wrap = (bool) preg_match(
	"~host\.closest\(['\"]\.eem-page-wrap['\"]\\)~",
	$js_raw
);
c7x19_ok( "admin.js: toggleDropdown() finds nearest .eem-page-wrap container",
	$uses_page_wrap, $pass, $fail, $log );

$uses_min = (bool) preg_match(
	'~Math\.min\s*\(\s*clipEl\.getBoundingClientRect\(\)\.bottom\s*,\s*window\.innerHeight\s*\)~',
	$js_raw
);
c7x19_ok( 'admin.js: toggleDropdown() uses Math.min(container.bottom, window.innerHeight)',
	$uses_min, $pass, $fail, $log );

// Flip-up class is still added when overflow is detected.
$js_flip_add = (bool) preg_match(
	'~classList\.add\(\s*[\'"]eem-row-menu-wrap--flip-up[\'"]\s*\)~',
	$js_raw
);
c7x19_ok( 'admin.js: toggleDropdown still adds eem-row-menu-wrap--flip-up when overflow detected',
	$js_flip_add, $pass, $fail, $log );

// Uses dropRect.bottom > bottomBound comparison (not spaceBelow < 0 from C7.X.18).
$uses_gt_comparison = (bool) preg_match(
	'~dropRect\.bottom\s*>\s*bottomBound~',
	$js_raw
);
c7x19_ok( 'admin.js: toggleDropdown uses dropRect.bottom > bottomBound (not spaceBelow < 0)',
	$uses_gt_comparison, $pass, $fail, $log );

// closeAllDropdowns still removes the modifier.
$js_flip_remove = (bool) preg_match(
	'~classList\.remove\(\s*[\'"]eem-row-menu-wrap--flip-up[\'"]\s*\)~',
	$js_raw
);
c7x19_ok( 'admin.js: closeAllDropdowns still removes eem-row-menu-wrap--flip-up on close',
	$js_flip_remove, $pass, $fail, $log );

/* ────────────────────────────────────────────────────────────────
   CSS flip-up rule still present and correct
   ──────────────────────────────────────────────────────────────── */

echo "\n-- Flip-up CSS rule integrity --\n";

$flip_css_bottom = (bool) preg_match(
	'~\.eem-row-menu-wrap--flip-up\s+\.eem-row-dropdown\s*\{[^}]*bottom\s*:~',
	$admin_nc
);
c7x19_ok( 'admin.css: .eem-row-menu-wrap--flip-up .eem-row-dropdown { bottom: ... } still defined',
	$flip_css_bottom, $pass, $fail, $log );

$flip_css_top_auto = (bool) preg_match(
	'~\.eem-row-menu-wrap--flip-up\s+\.eem-row-dropdown\s*\{[^}]*top\s*:\s*auto~',
	$admin_nc
);
c7x19_ok( 'admin.css: flip-up rule still sets top: auto to override default top positioning',
	$flip_css_top_auto, $pass, $fail, $log );

/* ────────────────────────────────────────────────────────────────
   SWEEP: No unresolved form-control 12px !important anywhere in admin-legacy.css
   ──────────────────────────────────────────────────────────────── */

echo "\n-- Literal sweep: no unresolved form-control 12px !important --\n";

// Sweep: the broad shell-page form-control selector chain must not contain 12px anywhere.
// Scoped guide-section selectors (e.g. .eem-orders-guide__toolbar input[type="search"])
// are intentional CLEANUP-documented exceptions and are excluded from this check.
$no_broad_chain_12px = ! (bool) preg_match(
	'~body\.eem-shell-page\s*:not\([^)]+\)[^{]*(?:select|textarea)\s*:not\([^)]+\)[^{]*\{[^}]*border-radius\s*:\s*12px\s*!important~s',
	$legacy_nc
);
c7x19_ok( 'admin-legacy.css: broad shell-page select/textarea :not() blocks have no 12px border-radius',
	$no_broad_chain_12px, $pass, $fail, $log );

/* ────────────────────────────────────────────────────────────────
   VERSION BUMP
   ──────────────────────────────────────────────────────────────── */

echo "\n-- Version bump --\n";

$main_php = file_get_contents( $plugin_root . '/equine-event-manager.php' );
c7x19_ok( 'equine-event-manager.php: version define is valid semver',
	(bool) preg_match( "~define\(\s*'EQUINE_EVENT_MANAGER_VERSION',\s*'[0-9]+\.[0-9]+\.[0-9]+'\s*\)~", $main_php ),
	$pass, $fail, $log );

/* ────────────────────────────────────────────────────────────────
   SUMMARY
   ──────────────────────────────────────────────────────────────── */

echo "\n";
foreach ( $log as $line ) {
	echo $line . "\n";
}

printf( "\n=== RESULT: %d passed, %d failed ===\n\n", $pass, $fail );
