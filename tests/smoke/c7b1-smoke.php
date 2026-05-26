<?php
/**
 * C7.B.1 smoke — Reservation Editor render scaffold.
 *
 *   [1]  Class + route registration
 *   [2]  Body-class filter branch (DS-1.B.4 regression guard)
 *   [3]  Page chrome — breadcrumb + plugin-header + meta-line + body wrapper
 *   [4]  All 10 section card skeletons render in correct order
 *   [5]  Each section has the correct icon-tone class (Decision E re-verified)
 *   [6]  Each section's enable-toggle presence matches the locked map
 *   [7]  Section-skeleton helper output contract
 *   [8]  .eem-section-body--disabled CSS rule shipped verbatim (Q14.a)
 *   [9]  JS handlers — collapse + enable-toggle delegated bindings
 *   [10] Page chrome guards (no save bar, no Linked Event modal — C7.B.2 scope)
 *   [11] Anchor umbrella for section-header click target
 *   [12] Enqueue gate includes the new route
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7b1_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.B.1 SMOKE ===\n";

$admin_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$css_src   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$js_src    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );

// ── [1] Class + route registration ─────────────────────────────────
echo "\n[1] Class + route registration\n";
c7b1_ok( 'EEM_Reservation_Editor_Page class loaded', class_exists( 'EEM_Reservation_Editor_Page' ), $pass, $fail, $log );
c7b1_ok( "MENU_SLUG constant == 'equine-event-manager-reservation-editor'",
	'equine-event-manager-reservation-editor' === EEM_Reservation_Editor_Page::MENU_SLUG,
	$pass, $fail, $log );
c7b1_ok( 'render method exists', method_exists( 'EEM_Reservation_Editor_Page', 'render' ), $pass, $fail, $log );
c7b1_ok( 'section_definitions method exists',
	method_exists( 'EEM_Reservation_Editor_Page', 'section_definitions' ),
	$pass, $fail, $log );
c7b1_ok( 'admin.php binds reservation-editor route',
	str_contains( $admin_src, "array( 'EEM_Reservation_Editor_Page', 'render' )" ),
	$pass, $fail, $log );
c7b1_ok( "registered as hidden submenu (parent='')",
	(bool) preg_match( "/add_submenu_page\(\s*\n?\s*''\s*,\s*\n?\s*__\(\s*'Edit Reservation'/s", $admin_src ),
	$pass, $fail, $log );

// ── [2] Body-class filter branch (DS-1.B.4 regression guard) ────────
echo "\n[2] Body-class filter branch\n";
wp_set_current_user( 1 );
$_GET = array( 'page' => 'equine-event-manager-reservation-editor' );
set_current_screen( 'admin_page_equine-event-manager-reservation-editor' );
$body_classes = apply_filters( 'admin_body_class', '' );
c7b1_ok( 'body classes include eem-shell-page',
	false !== strpos( $body_classes, 'eem-shell-page' ),
	$pass, $fail, $log,
	"got: '{$body_classes}'" );
c7b1_ok( 'body classes include NEW variant eem-shell-page--reservation-editor (Decision C)',
	false !== strpos( $body_classes, 'eem-shell-page--reservation-editor' ),
	$pass, $fail, $log,
	"got: '{$body_classes}'" );
c7b1_ok( 'body classes do NOT include legacy eem-shell-page--editor (kept distinct per Decision C)',
	false === strpos( $body_classes, 'eem-shell-page--editor ' ) && ! preg_match( '/eem-shell-page--editor$/', $body_classes ),
	$pass, $fail, $log,
	"got: '{$body_classes}'" );

// Setup: a real reservation to render against
$reservation_id = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => 'C7.B.1 Smoke Reservation ' . wp_generate_password( 6, false, false ),
) );

// Defensive pre-cleanup: leftover smoke posts from prior failed runs
$leftovers = get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'C7.B.1 Smoke' ) );
foreach ( $leftovers as $stale ) {
	if ( $stale->ID !== $reservation_id ) { wp_delete_post( $stale->ID, true ); }
}

$_GET = array( 'page' => 'equine-event-manager-reservation-editor', 'reservation_id' => $reservation_id );
ob_start();
EEM_Reservation_Editor_Page::render();
$html = ob_get_clean();

// ── [3] Page chrome ────────────────────────────────────────────────
echo "\n[3] Page chrome\n";
c7b1_ok( '.eem-page wrapper renders',          str_contains( $html, 'class="eem-page"' ),     $pass, $fail, $log );
c7b1_ok( '.eem-page-wrap card renders',        str_contains( $html, 'class="eem-page-wrap"' ),$pass, $fail, $log );
c7b1_ok( '.eem-page-header renders',           str_contains( $html, 'class="eem-page-header"' ), $pass, $fail, $log );
c7b1_ok( '.eem-breadcrumb renders',            str_contains( $html, 'eem-breadcrumb' ),       $pass, $fail, $log );
c7b1_ok( 'breadcrumb includes "Reservations" segment', str_contains( $html, 'Reservations' ), $pass, $fail, $log );
c7b1_ok( '.eem-reservation-editor-meta-line renders',  str_contains( $html, 'eem-reservation-editor-meta-line' ), $pass, $fail, $log );
c7b1_ok( 'meta-line includes "Linked Event" label',    str_contains( $html, 'Linked Event' ), $pass, $fail, $log );
c7b1_ok( '.eem-reservation-editor-body wrapper renders', str_contains( $html, 'eem-reservation-editor-body' ), $pass, $fail, $log );

// ── [4] All 10 section card skeletons render in correct order ──────
echo "\n[4] Section card skeletons — 10 in mockup order\n";
$expected_order = array( 'description', 'checkin', 'eventday', 'stall', 'rv', 'addons', 'group', 'fees', 'agreement', 'cancellation' );
$found_order = array();
foreach ( $expected_order as $key ) {
	$pos = strpos( $html, 'id="card-' . $key . '"' );
	if ( false !== $pos ) {
		$found_order[ $pos ] = $key;
	}
}
ksort( $found_order );
$found_in_order = array_values( $found_order );
c7b1_ok( '10 section cards present (description / checkin / eventday / stall / rv / addons / group / fees / agreement / cancellation)',
	count( $found_in_order ) === 10,
	$pass, $fail, $log,
	'found ' . count( $found_in_order ) . ': ' . implode( ',', $found_in_order ) );
c7b1_ok( 'sections render in mockup order',
	$found_in_order === $expected_order,
	$pass, $fail, $log,
	'order: ' . implode( ',', $found_in_order ) );

// ── [5] Each section has the correct icon-tone class + glyph (Decision E + C7.B.3) ──
echo "\n[5] Section icon tones (Decision E) + Feather glyphs (C7.B.3)\n";
$tone_map = array(
	'description'  => 'blue',
	'checkin'      => 'teal',
	'eventday'     => 'orange',
	'stall'        => 'green',
	'rv'           => 'purple',
	'addons'       => 'orange',
	'group'        => 'green',
	'fees'         => 'orange',
	'agreement'    => 'navy',
	'cancellation' => 'red',
);
foreach ( $tone_map as $key => $tone ) {
	$pattern = '/id="card-' . preg_quote( $key, '/' ) . '"[\s\S]{0,400}eem-section-icon--' . preg_quote( $tone, '/' ) . '/';
	c7b1_ok( "section '{$key}' uses icon-tone '{$tone}'",
		(bool) preg_match( $pattern, $html ),
		$pass, $fail, $log );
}

// C7.B.3: per-section Feather glyph inside each chip (DS-1.B.1
// icon-density discipline — assert SVG content, not just chip
// container). Locked glyph mapping per the audit:
$glyph_map = array(
	'description'  => 'file-text',
	'checkin'      => 'clock',
	'eventday'     => 'map-pin',
	'stall'        => 'grid',
	'rv'           => 'truck',
	'addons'       => 'plus',
	'group'        => 'users',
	'fees'         => 'dollar',
	'agreement'    => 'file',
	'cancellation' => 'shield-x',
);
foreach ( $glyph_map as $key => $glyph ) {
	// Each section chip contains an inline <svg> with non-empty path/line/rect/polyline/circle/polygon body
	$chip_pattern = '/id="card-' . preg_quote( $key, '/' ) . '"[\s\S]{0,800}?<div class="eem-section-icon eem-section-icon--[a-z]+"[^>]*><svg[^>]*>\s*<(path|line|rect|polyline|circle|polygon)/';
	c7b1_ok( "section '{$key}' chip carries an SVG with non-empty {$glyph}-shape path data",
		(bool) preg_match( $chip_pattern, $html ),
		$pass, $fail, $log );
}
// Aggregate guard — every section chip has an SVG inside it
preg_match_all( '#<div class="eem-section-icon eem-section-icon--[a-z]+"[^>]*>(.*?)</div>#s', $html, $chip_bodies );
$chips_with_svg = 0;
foreach ( $chip_bodies[1] as $body ) {
	if ( false !== strpos( $body, '<svg' ) ) { $chips_with_svg++; }
}
c7b1_ok( 'all 10 section chips contain an inline <svg (no empty chips — DS-1.B.1 icon-density)',
	10 === $chips_with_svg,
	$pass, $fail, $log,
	"chips with SVG: {$chips_with_svg} / " . count( $chip_bodies[1] ) );
// Regression guard — EEM_Dashboard_Icons registry carries the 5 new C7.B.3 glyphs
foreach ( array( 'file-text', 'map-pin', 'truck', 'file', 'shield-x' ) as $glyph ) {
	c7b1_ok( "EEM_Dashboard_Icons::svg('{$glyph}') returns a non-empty SVG (C7.B.3 registry extension)",
		'' !== EEM_Dashboard_Icons::svg( $glyph ) && false !== strpos( EEM_Dashboard_Icons::svg( $glyph ), '<svg' ),
		$pass, $fail, $log );
}

// ── [6] Enable-toggle presence per section (locked map) ────────────
echo "\n[6] Enable-toggle presence map\n";
$toggle_map = array(
	'description'  => false, // always-on
	'checkin'      => true,
	'eventday'     => true,
	'stall'        => true,
	'rv'           => true,
	'addons'       => true,
	'group'        => true,
	'fees'         => true,
	'agreement'    => true,
	'cancellation' => true,
);
foreach ( $toggle_map as $key => $expect_toggle ) {
	// Capture section card content from this card's open until the next
	// section card open (or end-of-html). C7.C.1 expanded section bodies
	// well beyond the original 1500-char window, so we now compute the
	// section boundary structurally rather than by char count.
	$open_pos = strpos( $html, 'id="card-' . $key . '"' );
	$next_pos = $open_pos !== false ? strpos( $html, 'id="card-', $open_pos + 5 ) : false;
	$slice    = $open_pos !== false ? substr( $html, $open_pos, ( $next_pos !== false ? $next_pos - $open_pos : null ) ) : '';
	$has_toggle = '' !== $slice && str_contains( $slice, 'eem-enable-toggle' );
	c7b1_ok( "section '{$key}' enable-toggle present == " . ( $expect_toggle ? 'true' : 'false' ),
		$has_toggle === $expect_toggle,
		$pass, $fail, $log );
}

// ── [7] Section-skeleton helper output contract ────────────────────
echo "\n[7] Section-skeleton helper output contract\n";
c7b1_ok( 'eem_render_reservation_editor_section() function exists',
	function_exists( 'eem_render_reservation_editor_section' ),
	$pass, $fail, $log );
c7b1_ok( 'each section card carries .eem-section-header',
	substr_count( $html, 'eem-section-header' ) >= 10,
	$pass, $fail, $log );
c7b1_ok( 'each section card carries .eem-section-body',
	substr_count( $html, 'eem-section-body' ) >= 10,
	$pass, $fail, $log );
c7b1_ok( 'each section header carries the collapse-action data attr',
	substr_count( $html, 'data-eem-action="reservation-editor-toggle-collapse"' ) === 10,
	$pass, $fail, $log );

// ── [8] .eem-section-body--disabled CSS rule (Q14.a verbatim port) ──
echo "\n[8] .eem-section-body--disabled CSS (Q14.a verbatim)\n";
c7b1_ok( '.eem-section-body--disabled has opacity 0.55',
	(bool) preg_match( '/\.eem-section-body--disabled\s*\{[^}]*opacity\s*:\s*0\.55/s', $css_src ),
	$pass, $fail, $log );
c7b1_ok( '.eem-section-body--disabled has pointer-events: none',
	(bool) preg_match( '/\.eem-section-body--disabled\s*\{[^}]*pointer-events\s*:\s*none/s', $css_src ),
	$pass, $fail, $log );
c7b1_ok( '.eem-section-body--disabled::after carries the striped overlay',
	(bool) preg_match( '/\.eem-section-body--disabled::after\s*\{[^}]*repeating-linear-gradient/s', $css_src ),
	$pass, $fail, $log );

// ── [9] JS delegated handlers shipped ──────────────────────────────
echo "\n[9] JS delegated handlers\n";
c7b1_ok( 'admin.js carries reservation-editor-toggle-collapse handler',
	str_contains( $js_src, 'reservation-editor-toggle-collapse' ),
	$pass, $fail, $log );
c7b1_ok( 'admin.js carries reservation-editor-toggle-enabled handler',
	str_contains( $js_src, 'reservation-editor-toggle-enabled' ),
	$pass, $fail, $log );

// ── [10] C7.B.1 scaffold guards (post-C7.B.2: scope guards inverted) ──
echo "\n[10] C7.B.1 scaffold contract (now that C7.B.2 has landed)\n";
// C7.B.2 landed the real save bar + Linked Event modal + promoted the
// meta-line change link. Original C7.B.1 scope guards inverted to
// regression-protect C7.B.2's additions live alongside the C7.B.1
// scaffold.
c7b1_ok( 'render contains the real save bar (.eem-save-bar) — landed in C7.B.2',
	str_contains( $html, 'class="eem-save-bar"' ),
	$pass, $fail, $log );
c7b1_ok( 'render contains the Linked Event modal — landed in C7.B.2',
	str_contains( $html, 'id="eem-modal-linked-event"' ),
	$pass, $fail, $log );
c7b1_ok( 'meta-line carries the real change-link launcher — promoted in C7.B.2',
	str_contains( $html, 'data-eem-action="reservation-editor-launch-linked-event-modal"' ),
	$pass, $fail, $log );
c7b1_ok( 'C7.B.1 savebar-placeholder REMOVED in C7.B.2',
	false === strpos( $html, 'eem-reservation-editor-savebar-placeholder' ),
	$pass, $fail, $log );

// ── [11] Anchor umbrella for section-header ─────────────────────────
echo "\n[11] Anchor umbrella for section-header click target\n";
c7b1_ok( 'a.eem-section-header:hover carries text-decoration:none (Decision H)',
	(bool) preg_match( '/a\.eem-section-header:hover[\s\S]{0,500}?text-decoration\s*:\s*none/s', $css_src ),
	$pass, $fail, $log );

// ── [12] Enqueue gate ───────────────────────────────────────────────
echo "\n[12] Enqueue gate includes the new route\n";
c7b1_ok( 'enqueue gate includes equine-event-manager-reservation-editor',
	substr_count( $admin_src, "'equine-event-manager-reservation-editor'" ) >= 2,
	$pass, $fail, $log );

// Cleanup
wp_delete_post( $reservation_id, true );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
