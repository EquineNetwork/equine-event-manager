<?php
/**
 * C13.B.2.a — server-side [en_reservation] embed on Create Order page.
 *
 * What landed:
 *   1. render() detects ?reservation_id=N, validates it, and when valid:
 *      - Renders outer workspace as <div> (not <form>) to avoid nested forms
 *      - Calls render_reservation_card_picked() instead of the picker select
 *      - Calls render_embedded_sections() instead of the 4 stub cards
 *   2. render_reservation_card_picked() — linked-reservation display (blue pill
 *      + Change link navigating back to base URL)
 *   3. render_embedded_sections() — do_shortcode('[en_reservation id=N]') wrapped
 *      in .eem-co-form-embed; pricing JS embedded inline (is_admin() path)
 *   4. admin.php enqueue_backend_shell_styles() — EEM_Events::render_frontend_styles()
 *      for public.css when ?reservation_id is set on the create-order page
 *   5. admin.css — .eem-co-form-embed scope rules (section hide, layout strip, rail hide)
 *   6. admin.css — .eem-co-linked-res component (linked picker display)
 *
 * Source-presence smoke only. Mandatory browser self-verify required for computed/
 * runtime claims (pricing JS executing, sections showing, linked pill rendering).
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c13b2a_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C13.B.2.a — EMBEDDED RESERVATION FORM SMOKE ===\n";

$admin_css  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$co_page    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-create-order-page.php' );
$admin_main = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );

// Strip comments before scanning PHP source for operative tokens.
$strip = function( $s ) {
	$s = preg_replace( '~/\*.*?\*/~s', '', $s );
	$s = preg_replace( '~//[^\n]*~', '', $s );
	return $s;
};
$co_clean    = $strip( $co_page );
$admin_clean = $strip( $admin_main );


// ── [1] Version bump ─────────────────────────────────────────────────────────
echo "\n[1] Version bump to 2.7.3\n";
c13b2a_ok(
	'EQUINE_EVENT_MANAGER_VERSION >= 2.7.3',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.7.3', '>=' ),
	$pass, $fail, $log
);


// ── [2] render() — ?reservation_id detection and validation ─────────────────
echo "\n[2] render() — reservation_id detection + validation\n";
c13b2a_ok(
	'absint( $_GET[\'reservation_id\'] ) read in render()',
	false !== strpos( $co_clean, "absint( wp_unslash( \$_GET['reservation_id'] )" ),
	$pass, $fail, $log
);
c13b2a_ok(
	'Post-type guard EEM_Reservations_CPT::POST_TYPE check present',
	false !== strpos( $co_clean, 'EEM_Reservations_CPT::POST_TYPE !== $embedded_post->post_type' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'Publish-status guard on embedded_post present',
	false !== strpos( $co_clean, "'publish' !== \$embedded_post->post_status" ),
	$pass, $fail, $log
);
c13b2a_ok(
	'embedded_dates assembled from _en_available_start_date / _en_available_end_date',
	false !== strpos( $co_clean, '_en_available_start_date' ) && false !== strpos( $co_clean, '_en_available_end_date' ),
	$pass, $fail, $log
);


// ── [3] render() — outer wrapper is <div> when rid > 0 ──────────────────────
echo "\n[3] render() — outer workspace uses <div> (not <form>) when reservation embedded\n";
c13b2a_ok(
	'Conditional <div class="eem-co-workspace" … data-eem-co-has-embed="1"> branch present',
	false !== strpos( $co_page, 'data-eem-co-has-embed="1"' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'Both </div> and </form> closing branches present (conditional close)',
	false !== strpos( $co_page, '</div>' ) && false !== strpos( $co_page, '</form>' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'Corresponding <form class="eem-co-workspace" branch still present (no-embed fallback)',
	false !== strpos( $co_page, '<form class="eem-co-workspace"' ),
	$pass, $fail, $log
);


// ── [4] render_reservation_card_picked() method ──────────────────────────────
echo "\n[4] render_reservation_card_picked() — linked-reservation display\n";
c13b2a_ok(
	'render_reservation_card_picked() method declared',
	false !== strpos( $co_clean, 'private static function render_reservation_card_picked' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'Docblock: @param int $rid present',
	false !== strpos( $co_page, '@param int    $rid   Reservation post ID.' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'.eem-co-linked-res component emitted',
	false !== strpos( $co_page, 'eem-co-linked-res' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'.eem-co-linked-res__change link emitted (anchor, not data-eem-action)',
	false !== strpos( $co_page, 'eem-co-linked-res__change' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'Change link href uses add_query_arg(page, MENU_SLUG) — navigates back to base URL',
	false !== strpos( $co_clean, 'add_query_arg( \'page\', self::MENU_SLUG' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'Change link text wrapped in esc_html_e()',
	false !== strpos( $co_page, "esc_html_e( 'Change'" ),
	$pass, $fail, $log
);
c13b2a_ok(
	'esc_html( $title ) used for reservation name',
	false !== strpos( $co_page, 'esc_html( $title )' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'esc_html( $dates ) used for date range',
	false !== strpos( $co_page, 'esc_html( $dates )' ),
	$pass, $fail, $log
);


// ── [5] render_embedded_sections() method ────────────────────────────────────
echo "\n[5] render_embedded_sections() — do_shortcode embed\n";
c13b2a_ok(
	'render_embedded_sections() method declared',
	false !== strpos( $co_clean, 'private static function render_embedded_sections' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'Docblock: @param int $rid present',
	false !== strpos( $co_page, '@param int $rid Reservation post ID (already validated' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'do_shortcode() called with [en_reservation id=',
	false !== strpos( $co_clean, "do_shortcode( sprintf( '[en_reservation id=\"%d\"]'" ),
	$pass, $fail, $log
);
c13b2a_ok(
	'.eem-co-form-embed wrapper div emitted',
	false !== strpos( $co_page, 'eem-co-form-embed' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'window.eemCreateOrder.reservationId localized in JS block',
	false !== strpos( $co_page, 'window.eemCreateOrder.reservationId' ),
	$pass, $fail, $log
);


// ── [6] admin.php — public.css enqueue for Create Order + reservation_id ─────
echo "\n[6] admin.php — EEM_Events::render_frontend_styles() on create-order + reservation_id\n";
c13b2a_ok(
	"'equine-event-manager-create-order' === \$page guard present",
	false !== strpos( $admin_clean, "'equine-event-manager-create-order' === \$page" ),
	$pass, $fail, $log
);
c13b2a_ok(
	"empty( \$_GET['reservation_id'] ) check present",
	false !== strpos( $admin_clean, "! empty( \$_GET['reservation_id'] )" ),
	$pass, $fail, $log
);
c13b2a_ok(
	'EEM_Events::render_frontend_styles() call present in conditional block',
	false !== strpos( $admin_clean, 'EEM_Events::render_frontend_styles()' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'class_exists( EEM_Events ) guard present',
	false !== strpos( $admin_clean, "class_exists( 'EEM_Events' )" ),
	$pass, $fail, $log
);


// ── [7] admin.css — .eem-co-form-embed scope rules ───────────────────────────
echo "\n[7] admin.css — .eem-co-form-embed scope rules\n";
c13b2a_ok(
	'.eem-co-form-embed .eem-reservation-workspace__rail { display: none } present',
	(bool) preg_match( '/\.eem-co-form-embed\s+\.eem-reservation-workspace__rail\s*\{[^}]*display\s*:\s*none/s', $admin_css ),
	$pass, $fail, $log
);
c13b2a_ok(
	'.eem-co-form-embed .eem-reservation-workspace__main flex-column rule present',
	(bool) preg_match( '/\.eem-co-form-embed\s+\.eem-reservation-workspace__main\s*\{[^}]*display\s*:\s*flex/s', $admin_css ),
	$pass, $fail, $log
);
c13b2a_ok(
	'.eem-co-form-embed .eem-reservation-section:not([data-eem-section="stall"]):not([data-eem-section="rv"]):not([data-eem-section="addons"]):not([data-eem-section="group"]) { display: none } present',
	(bool) preg_match(
		'~\.eem-co-form-embed\s+\.eem-reservation-section:not\(\[data-eem-section="stall"\]\):not\(\[data-eem-section="rv"\]\):not\(\[data-eem-section="addons"\]\):not\(\[data-eem-section="group"\]\)\s*\{[^}]*display\s*:\s*none~s',
		$admin_css
	),
	$pass, $fail, $log
);
c13b2a_ok(
	'.eem-co-form-embed .eem-reservation-form-wrap padding: 0 present (strip chrome)',
	(bool) preg_match( '/\.eem-co-form-embed\s+\.eem-reservation-form-wrap\s*\{[^}]*padding\s*:\s*0/s', $admin_css ),
	$pass, $fail, $log
);
c13b2a_ok(
	'.eem-co-form-embed .eem-reservation-workspace { display: block } present',
	(bool) preg_match( '/\.eem-co-form-embed\s+\.eem-reservation-workspace\s*\{\s*display\s*:\s*block/s', $admin_css ),
	$pass, $fail, $log
);


// ── [8] admin.css — .eem-co-linked-res component ─────────────────────────────
echo "\n[8] admin.css — .eem-co-linked-res component\n";
c13b2a_ok(
	'.eem-co-linked-res base rule with display:flex present',
	(bool) preg_match( '/\.eem-co-linked-res\s*\{[^}]*display\s*:\s*flex/s', $admin_css ),
	$pass, $fail, $log
);
c13b2a_ok(
	'.eem-co-linked-res__name rule present',
	false !== strpos( $admin_css, '.eem-co-linked-res__name' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'.eem-co-linked-res__dates rule present',
	false !== strpos( $admin_css, '.eem-co-linked-res__dates' ),
	$pass, $fail, $log
);
c13b2a_ok(
	'.eem-co-linked-res__change rule present',
	false !== strpos( $admin_css, '.eem-co-linked-res__change' ),
	$pass, $fail, $log
);
// Anchor-button discipline (DS-1.A.1 lesson): anchor-capable button class must
// have explicit color + text-decoration: none for :hover state.
c13b2a_ok(
	'a.eem-co-linked-res__change:hover anchor-hover rule present (anchor-button discipline)',
	false !== strpos( $admin_css, 'a.eem-co-linked-res__change:hover' ),
	$pass, $fail, $log
);
// text-decoration: underline is banned per CLAUDE.md hygiene rule #8.
c13b2a_ok(
	'No text-decoration: underline added in .eem-co-linked-res* rules',
	! (bool) preg_match( '/\.eem-co-linked-res[^{]*\{[^}]*text-decoration\s*:\s*underline/s', $admin_css ),
	$pass, $fail, $log
);


// ── [9] No !important in new CSS ─────────────────────────────────────────────
echo "\n[9] Hygiene — no !important in new .eem-co-form-embed / .eem-co-linked-res rules\n";
// Extract the C13.B.2.a CSS block (everything after the C13.B.2.a comment sentinel).
$b2a_css_offset = strpos( $admin_css, 'C13.B.2.a' );
$b2a_css = $b2a_css_offset !== false ? substr( $admin_css, $b2a_css_offset ) : '';
c13b2a_ok(
	'No !important in .eem-co-form-embed or .eem-co-linked-res rules (hygiene rule #6)',
	! (bool) preg_match( '/\.(eem-co-form-embed|eem-co-linked-res)[^{]*\{[^}]*!important/s', $b2a_css ),
	$pass, $fail, $log
);


// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n";
foreach ( $log as $line ) { echo $line . "\n"; }
echo "\n" . ( $fail === 0 ? 'ALL PASS' : "FAILURES: {$fail}" ) . " — {$pass} passed, {$fail} failed\n\n";
exit( $fail > 0 ? 1 : 0 );
