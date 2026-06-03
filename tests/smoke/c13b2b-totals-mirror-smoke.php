<?php
/**
 * C13.B.2.b — Mirror embedded pricing-engine totals into the Create Order rail.
 *
 * What landed:
 *   1. coSyncTotals() IIFE in admin.js — reads [data-eem-summary-row]:not([hidden])
 *      from the hidden .eem-reservation-workspace__rail and rebuilds
 *      .eem-co-summary-lines; updates [data-eem-co-summary-total] with grand total.
 *   2. Wired to input + change events scoped to .eem-co-form-embed; DOMContentLoaded init.
 *   3. render_summary_card(string $embedded_title) — new optional param; emits
 *      <div class="eem-co-summary-event" data-eem-co-summary-event> when title non-empty.
 *   4. render() passes $embedded_title to render_summary_card().
 *   5. window.eemCreateOrder.reservationTitle localized in JS block.
 *   6. admin.css .eem-co-summary-event rule matching mockup .rail-event pattern.
 *
 * Source-presence smoke only. Mandatory browser self-verify required:
 *   - Add a stall on Create Order + reservation_id=3499 → rail shows "Stall Subtotal" line.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c13b2b_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C13.B.2.b — TOTALS MIRROR SMOKE ===\n";

$admin_css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$js_src    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$co_page   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-create-order-page.php' );

$strip = function( $s ) {
	$s = preg_replace( '~/\*.*?\*/~s', '', $s );
	$s = preg_replace( '~//[^\n]*~', '', $s );
	return $s;
};
$js_clean = $strip( $js_src );
$co_clean = $strip( $co_page );


// ── [1] Version bump ─────────────────────────────────────────────────────────
echo "\n[1] Version bump to 2.7.4\n";
c13b2b_ok(
	'EQUINE_EVENT_MANAGER_VERSION >= 2.7.4',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.7.4', '>=' ),
	$pass, $fail, $log
);


// ── [2] JS — coSyncTotals() function ─────────────────────────────────────────
echo "\n[2] admin.js — coSyncTotals() function\n";
c13b2b_ok(
	'coSyncTotals() function declared',
	false !== strpos( $js_clean, 'function coSyncTotals()' ),
	$pass, $fail, $log
);
c13b2b_ok(
	'Reads .eem-co-form-embed embed context',
	false !== strpos( $js_clean, "querySelector('.eem-co-form-embed')" ),
	$pass, $fail, $log
);
c13b2b_ok(
	'Queries [data-eem-summary-row]:not([hidden]) for visible rows',
	false !== strpos( $js_clean, "[data-eem-summary-row]:not([hidden])" ),
	$pass, $fail, $log
);
// Confirmed via DOM inspection: pricing engine uses data-eem-total="total",
// not "grand_total". The smoke asserts the corrected key.
c13b2b_ok(
	'Reads [data-eem-total="total"] for grand total (confirmed key, not "grand_total")',
	false !== strpos( $js_clean, '[data-eem-total="total"]' ),
	$pass, $fail, $log
);
c13b2b_ok(
	'Queries [data-eem-co-summary-lines] target container',
	false !== strpos( $js_clean, '[data-eem-co-summary-lines]' ),
	$pass, $fail, $log
);
c13b2b_ok(
	'Queries [data-eem-co-summary-total] total span',
	false !== strpos( $js_clean, '[data-eem-co-summary-total]' ),
	$pass, $fail, $log
);
c13b2b_ok(
	'Emits .eem-co-summary-line rows with label + price spans',
	false !== strpos( $js_clean, "eem-co-summary-line'" ) && false !== strpos( $js_clean, "eem-co-summary-line-label'" ) && false !== strpos( $js_clean, "eem-co-summary-line-price'" ),
	$pass, $fail, $log
);
c13b2b_ok(
	'Preserves [data-eem-co-summary-event] node across rebuilds',
	false !== strpos( $js_clean, '[data-eem-co-summary-event]' ),
	$pass, $fail, $log
);
c13b2b_ok(
	'Empty-state p[data-eem-co-summary-empty] restored when no rows',
	false !== strpos( $js_clean, "data-eem-co-summary-empty" ),
	$pass, $fail, $log
);


// ── [3] JS — event wiring ────────────────────────────────────────────────────
echo "\n[3] admin.js — coSyncTotals() event wiring\n";
c13b2b_ok(
	'input event listener calls coSyncTotals() when inside .eem-co-form-embed',
	(bool) preg_match( "/addEventListener\s*\(\s*'input'.*?coSyncTotals/s", $js_clean ),
	$pass, $fail, $log
);
c13b2b_ok(
	'change event listener calls coSyncTotals() when inside .eem-co-form-embed',
	(bool) preg_match( "/addEventListener\s*\(\s*'change'.*?coSyncTotals/s", $js_clean ),
	$pass, $fail, $log
);
c13b2b_ok(
	'DOMContentLoaded init calls coSyncTotals() when .eem-co-form-embed present',
	(bool) preg_match( "/addEventListener\s*\(\s*'DOMContentLoaded'.*?coSyncTotals/s", $js_clean ),
	$pass, $fail, $log
);
c13b2b_ok(
	'.eem-co-form-embed closest() scope guard on input event',
	(bool) preg_match( "/closest\s*\(\s*['\"]\.eem-co-form-embed['\"].*?coSyncTotals/s", $js_clean ),
	$pass, $fail, $log
);


// ── [4] PHP — render_summary_card() signature + event-name div ───────────────
echo "\n[4] PHP — render_summary_card() accepts \$embedded_title param\n";
c13b2b_ok(
	'render_summary_card( string $embedded_title = \'\' ) signature updated',
	false !== strpos( $co_clean, 'private static function render_summary_card( string $embedded_title' ),
	$pass, $fail, $log
);
c13b2b_ok(
	'@param string $embedded_title docblock present',
	false !== strpos( $co_page, '@param string $embedded_title' ),
	$pass, $fail, $log
);
c13b2b_ok(
	'data-eem-co-summary-event div emitted when title non-empty',
	false !== strpos( $co_page, 'data-eem-co-summary-event' ),
	$pass, $fail, $log
);
c13b2b_ok(
	'esc_html( $embedded_title ) used in event-name output',
	false !== strpos( $co_page, 'esc_html( $embedded_title )' ),
	$pass, $fail, $log
);
c13b2b_ok(
	'render_summary_card( $embedded_title ) called in render()',
	false !== strpos( $co_clean, 'self::render_summary_card( $embedded_title )' ),
	$pass, $fail, $log
);


// ── [5] PHP — reservationTitle localized ─────────────────────────────────────
echo "\n[5] PHP — window.eemCreateOrder.reservationTitle localized\n";
c13b2b_ok(
	'window.eemCreateOrder.reservationTitle assigned in render()',
	false !== strpos( $co_page, 'window.eemCreateOrder.reservationTitle' ),
	$pass, $fail, $log
);
c13b2b_ok(
	'wp_json_encode( $embedded_title ) used for localization',
	false !== strpos( $co_clean, 'wp_json_encode( $embedded_title )' ),
	$pass, $fail, $log
);


// ── [6] admin.css — .eem-co-summary-event rule ───────────────────────────────
echo "\n[6] admin.css — .eem-co-summary-event rule\n";
c13b2b_ok(
	'.eem-co-summary-event rule present',
	false !== strpos( $admin_css, '.eem-co-summary-event' ),
	$pass, $fail, $log
);
c13b2b_ok(
	'Uses var(--eem-navy) for color (token, not hardcoded)',
	(bool) preg_match( '/\.eem-co-summary-event\s*\{[^}]*color\s*:\s*var\(--eem-navy\)/s', $admin_css ),
	$pass, $fail, $log
);
c13b2b_ok(
	'No !important in .eem-co-summary-event rule (hygiene rule #6)',
	! (bool) preg_match( '/\.eem-co-summary-event\s*\{[^}]*!important/s', $admin_css ),
	$pass, $fail, $log
);


// ── [7] Hygiene — no !important in new JS-created elements ───────────────────
echo "\n[7] Hygiene\n";
// The C13.B.2.b block starts after the coSyncTotals marker in JS.
$b2b_js_offset = strpos( $js_src, 'C13.B.2.b' );
$b2b_js = $b2b_js_offset !== false ? substr( $js_src, $b2b_js_offset ) : '';
c13b2b_ok(
	'No text-decoration: underline in new CSS (hygiene rule #8)',
	! (bool) preg_match( '/\.eem-co-summary-event[^{]*\{[^}]*text-decoration\s*:\s*underline/s', $admin_css ),
	$pass, $fail, $log
);
// IIFE wraps coSyncTotals: verify (function () { appears BEFORE function coSyncTotals
// in the B.2.b block, and the IIFE is closed with })() at the end of the block.
$iife_pos  = strpos( $b2b_js, '(function ()' );
$fn_pos    = strpos( $b2b_js, 'function coSyncTotals' );
$iife_close = strrpos( $b2b_js, '})();' );
c13b2b_ok(
	'coSyncTotals() lives inside an IIFE (not polluting global scope)',
	false !== $iife_pos && false !== $fn_pos && false !== $iife_close
	&& $iife_pos < $fn_pos && $fn_pos < $iife_close,
	$pass, $fail, $log
);


// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n";
foreach ( $log as $line ) { echo $line . "\n"; }
echo "\n" . ( $fail === 0 ? 'ALL PASS' : "FAILURES: {$fail}" ) . " — {$pass} passed, {$fail} failed\n\n";
exit( $fail > 0 ? 1 : 0 );
