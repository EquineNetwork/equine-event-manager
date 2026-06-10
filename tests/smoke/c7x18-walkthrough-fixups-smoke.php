<?php
/**
 * Smoke test — C7.X.18 visual-verify fix-ups.
 *
 * Issues fixed:
 *   A1 — textarea :not() exclusion class mismatch (was .eem-field-textarea, actual class is .eem-field-input)
 *   A2 — hardcoded border-radius: 4px values in admin.css converted to var(--eem-radius)
 *   B  — plugin .button selector leakage into WP Media Library modal (.button-link not excluded)
 *   C  — meatballs dropdown flip-up CSS + JS when row near bottom of container
 *
 * Run via run-all.sh or directly:
 *   wp eval-file tests/smoke/c7x18-walkthrough-fixups-smoke.php
 */

$pass = 0;
$fail = 0;
$log  = array();

function c7x18_ok( string $label, bool $condition, int &$pass, int &$fail, array &$log ): void {
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

echo "\n=== C7.X.18 smoke ===\n\n";

/* ────────────────────────────────────────────────────────────────
   ISSUE A1 — Textarea exclusion class now includes .eem-field-input
   ──────────────────────────────────────────────────────────────── */

echo "-- Issue A1: textarea exclusion class mismatch --\n";

// OLD pattern (wrong): textarea:not(.eem-field-textarea)   — does NOT catch .eem-field-input
// NEW pattern (right): textarea:not(.eem-field-input):not(.eem-field-textarea)
$old_bare = (bool) preg_match(
	'~textarea\s*:not\(\s*\.eem-field-textarea\s*\)\s*[{,]~',
	$legacy_nc
);
c7x18_ok( 'admin-legacy.css: no bare textarea:not(.eem-field-textarea) without .eem-field-input guard',
	! $old_bare, $pass, $fail, $log );

$new_pattern = (bool) preg_match(
	'~textarea\s*:not\(\s*\.eem-field-input\s*\)\s*:not\(\s*\.eem-field-textarea\s*\)~',
	$legacy_nc
);
c7x18_ok( 'admin-legacy.css: textarea :not(.eem-field-input):not(.eem-field-textarea) pattern present',
	$new_pattern, $pass, $fail, $log );

// DS-1.A / C16 UPDATE (2.7.173): the dead classic-CPT-editor form-control blocks
// (body.eem-shell-page--editor + body.post-type-en_reservation.post-php /
// .post-new-php) that hosted the textarea exclusion chain were REMOVED — those
// screens redirect to the custom route and never paint. The chain now survives
// where it's genuinely load-bearing (the media-modal scoped block). The real
// invariant is just that the corrected chain still exists at least once (asserted
// above); the prior "≥4 blocks" count was an artifact of the dead blocks.
$all_matches = preg_match_all(
	'~textarea\s*:not\(\s*\.eem-field-input\s*\)\s*:not\(\s*\.eem-field-textarea\s*\)~',
	$legacy_nc,
	$textarea_hits
);
c7x18_ok( "admin-legacy.css: textarea :not() chain survives in ≥1 live scoped block (got {$all_matches})",
	$all_matches >= 1, $pass, $fail, $log );
c7x18_ok( 'admin-legacy.css: dead classic-editor textarea blocks stay removed',
	false === strpos( $legacy_nc, 'body.eem-shell-page--editor textarea:not(' )
	&& false === strpos( $legacy_nc, 'body.post-type-en_reservation.post-php textarea:not(' ),
	$pass, $fail, $log );

/* ────────────────────────────────────────────────────────────────
   ISSUE A2 — Hardcoded border-radius: 4px values converted to token
   ──────────────────────────────────────────────────────────────── */

echo "\n-- Issue A2: hardcoded border-radius: 4px → var(--eem-radius) --\n";

// Confirmed offenders from DevTools — these MUST use the token.
$must_use_token = array(
	'.eem-zone-delete-btn'       => '~\.eem-zone-delete-btn\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-btn-upload'            => '~\.eem-btn-upload\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-btn-delete'            => '~\.eem-btn-delete\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-zone-add-btn'          => '~\.eem-zone-add-btn\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-btn-add'               => '~\.eem-btn-add\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-btn-file-del'          => '~\.eem-btn-file-del\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-fee-mode-btn'          => '~\.eem-fee-mode-btn\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-toggle-label-row'      => '~\.eem-toggle-label-row\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-btn-save-draft'        => '~\.eem-btn-save-draft\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-btn-update'            => '~\.eem-btn-update\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-btn-danger-sm'         => '~\.eem-btn-danger-sm\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-btn-manage-layout'     => '~\.eem-btn-manage-layout\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-zone-color-swatch'     => '~\.eem-zone-color-swatch\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	'.eem-zone-price-wrap'       => '~\.eem-zone-price-wrap\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
	// NOTE: .eem-zone-color-preset (preset-picker popover) was removed in f73f5eb when
	// RV zone colors became a single getZoneColor() auto-palette. Class no longer exists;
	// assertion dropped (feature intentionally refactored away, not a regression).
	'.eem-inherited-default-banner-icon' => '~\.eem-inherited-default-banner-icon\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)~',
);

foreach ( $must_use_token as $selector => $pattern ) {
	$found = (bool) preg_match( $pattern, $admin_nc );
	c7x18_ok( "admin.css: {$selector} uses var(--eem-radius) for border-radius",
		$found, $pass, $fail, $log );
}

// Affix segments use corner-specific syntax: var(--eem-radius) 0 0 var(--eem-radius)
$affix_sym = (bool) preg_match(
	'~\.eem-zone-price-sym\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)\s+0\s+0\s+var\(--eem-radius\)~',
	$admin_nc
);
c7x18_ok( 'admin.css: .eem-zone-price-sym uses var(--eem-radius) 0 0 var(--eem-radius)',
	$affix_sym, $pass, $fail, $log );

$repeat_sym = (bool) preg_match(
	'~\.eem-repeat-price-sym\s*\{[^}]*border-radius\s*:\s*var\(--eem-radius\)\s+0\s+0\s+var\(--eem-radius\)~',
	$admin_nc
);
c7x18_ok( 'admin.css: .eem-repeat-price-sym uses var(--eem-radius) 0 0 var(--eem-radius)',
	$repeat_sym, $pass, $fail, $log );

// Intentional exceptions: pill badge on ::after and circles should NOT be touched.
$pill_badge = (bool) preg_match(
	'~\.eem-btn-manage-layout::after\s*\{[^}]*border-radius\s*:\s*8px~',
	$admin_nc
);
c7x18_ok( 'admin.css: .eem-btn-manage-layout::after badge still has 8px (intentional pill)',
	$pill_badge, $pass, $fail, $log );

/* ────────────────────────────────────────────────────────────────
   ISSUE B — Plugin .button selectors exclude .button-link
   ──────────────────────────────────────────────────────────────── */

echo "\n-- Issue B: plugin button selectors exclude .button-link --\n";

// Both button blocks that leak into media modal must exclude .button-link.
// The pattern: .button:not(.button-primary):not(.button-link-delete):not(.button-link)
$button_not_link = preg_match_all(
	'~\.button\s*:not\(\s*\.button-primary\s*\)\s*:not\(\s*\.button-link-delete\s*\)\s*:not\(\s*\.button-link\s*\)~',
	$legacy_nc,
	$btn_hits
);
c7x18_ok( "admin-legacy.css: .button:not(.button-primary):not(.button-link-delete):not(.button-link) present ≥2 times (got {$button_not_link})",
	$button_not_link >= 2, $pass, $fail, $log );

// The bare .button selector in block 3 must also exclude .button-link
$bare_button_not_link = (bool) preg_match(
	'~body\.eem-shell-page[^{]*\.button\s*:not\(\s*\.button-link\s*\)[^{]*\{~',
	$legacy_nc
);
c7x18_ok( 'admin-legacy.css: bare .button selector in shell-page block excludes .button-link',
	$bare_button_not_link, $pass, $fail, $log );

// OLD bare .button (without exclusion) should NOT appear as a standalone selector in the
// shell-page context (it was the root cause of the leak).
$old_bare_button = (bool) preg_match(
	'~body\.eem-shell-page:not\([^)]+\)[^{]*[^:]\.button\s*[,{]~',
	$legacy_nc
);
c7x18_ok( 'admin-legacy.css: no bare unscoped .button, selector in shell-page context',
	! $old_bare_button, $pass, $fail, $log );

/* ────────────────────────────────────────────────────────────────
   ISSUE C — Flip-up dropdown CSS + JS wired
   ──────────────────────────────────────────────────────────────── */

echo "\n-- Issue C: meatballs dropdown flip-up --\n";

// CSS: .eem-row-menu-wrap--flip-up .eem-row-dropdown selector exists
$flip_css = (bool) preg_match(
	'~\.eem-row-menu-wrap--flip-up\s+\.eem-row-dropdown\s*\{[^}]*bottom\s*:~',
	$admin_nc
);
c7x18_ok( 'admin.css: .eem-row-menu-wrap--flip-up .eem-row-dropdown { bottom: ... } defined',
	$flip_css, $pass, $fail, $log );

// CSS: flip-up must set top: auto to override the default top: calc(100% + 4px)
$flip_top_auto = (bool) preg_match(
	'~\.eem-row-menu-wrap--flip-up\s+\.eem-row-dropdown\s*\{[^}]*top\s*:\s*auto~',
	$admin_nc
);
c7x18_ok( 'admin.css: flip-up rule sets top: auto to cancel default top positioning',
	$flip_top_auto, $pass, $fail, $log );

// JS: toggleDropdown adds the flip-up class
$js_flip_add = (bool) preg_match(
	'~classList\.add\(\s*[\'"]eem-row-menu-wrap--flip-up[\'"]\s*\)~',
	$js_raw
);
c7x18_ok( 'admin.js: toggleDropdown adds eem-row-menu-wrap--flip-up when overflow detected',
	$js_flip_add, $pass, $fail, $log );

// JS: closeAllDropdowns removes the flip-up class to clean up
$js_flip_remove = (bool) preg_match(
	'~classList\.remove\(\s*[\'"]eem-row-menu-wrap--flip-up[\'"]\s*\)~',
	$js_raw
);
c7x18_ok( 'admin.js: closeAllDropdowns removes eem-row-menu-wrap--flip-up on close',
	$js_flip_remove, $pass, $fail, $log );

// JS: uses getBoundingClientRect + window.innerHeight to measure overflow
$js_measure = (bool) preg_match(
	'~getBoundingClientRect\(\)~',
	$js_raw
) && (bool) preg_match(
	'~window\.innerHeight~',
	$js_raw
);
c7x18_ok( 'admin.js: toggleDropdown uses getBoundingClientRect + window.innerHeight for overflow check',
	$js_measure, $pass, $fail, $log );

/* ────────────────────────────────────────────────────────────────
   VERSION BUMP
   ──────────────────────────────────────────────────────────────── */

echo "\n-- Version bump --\n";

$main_php = file_get_contents( $plugin_root . '/equine-event-manager.php' );
// Self-consistency check: the plugin-header `Version:` must match the
// EQUINE_EVENT_MANAGER_VERSION constant. (Was a hardcoded-version assertion;
// the plugin bumps regularly, so assert header/constant agreement instead.)
preg_match( '~^\s*\*\s*Version:\s*([0-9][0-9.]*)~mi', $main_php, $hdr_m );
preg_match( "~define\(\s*'EQUINE_EVENT_MANAGER_VERSION',\s*'([0-9][0-9.]*)'\s*\)~", $main_php, $const_m );
$hdr_ver   = $hdr_m[1]   ?? '';
$const_ver = $const_m[1] ?? '';
c7x18_ok( "equine-event-manager.php: header Version ({$hdr_ver}) matches EQUINE_EVENT_MANAGER_VERSION ({$const_ver})",
	$hdr_ver !== '' && $hdr_ver === $const_ver,
	$pass, $fail, $log );

/* ────────────────────────────────────────────────────────────────
   SUMMARY
   ──────────────────────────────────────────────────────────────── */

echo "\n";
foreach ( $log as $line ) {
	echo $line . "\n";
}

printf( "\n=== RESULT: %d passed, %d failed ===\n\n", $pass, $fail );
