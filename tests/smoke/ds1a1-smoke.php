<?php
/**
 * DS-1.A.1 smoke — fix-up surfaced by DS-1.A visual verify.
 *
 *   [1] `a.eem-btn:hover` / `:focus` carries `text-decoration: none`
 *       (kills the underline-on-hover regression on anchor-rendered
 *       button variants — root cause: `.eem-page a:hover` specificity
 *       (0,2,1) beat the base `.eem-btn:hover` (0,2,0)).
 *   [2] Mockup preview stubs render as `<iframe srcdoc>` instead of
 *       inline-injected `<div>` of mockup body HTML — hard CSS boundary
 *       prevents the mockup's inline `<style>` block from cascading out.
 *   [3] Create Order is registered as a HIDDEN submenu (parent='') —
 *       NOT a sidebar entry. Matches the Collect Payment + Order Detail
 *       hidden-submenu pattern.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function ds1a1_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== DS-1.A.1 SMOKE ===\n";

$admin_src  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$create_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-create-order-page.php' );
$collect_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-collect-payment-page.php' );
$css_src    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );

// ── [1] Issue 1: text-decoration:none on anchor-rendered buttons ────
echo "\n[1] Anchor button hover/focus text-decoration\n";
ds1a1_ok( 'admin.css contains a.eem-btn:hover { text-decoration: none }',
	(bool) preg_match( '/a\.eem-btn:hover\s*,\s*\n?\s*a\.eem-btn:focus\s*\{\s*text-decoration\s*:\s*none/s', $css_src ),
	$pass, $fail, $log );
ds1a1_ok( '.eem-btn-primary:hover block still present (no regression on existing rules)',
	(bool) preg_match( '/\.eem-btn-primary:hover/', $css_src ),
	$pass, $fail, $log );

// ── [2] Issue 2: mockup preview is iframe srcdoc ────────────────────
echo "\n[2] Mockup preview iframe isolation\n";
ds1a1_ok( 'Create Order render_mockup_preview emits <iframe with srcdoc',
	str_contains( $create_src, '<iframe class="eem-mockup-preview"' ) &&
	str_contains( $create_src, 'srcdoc=' ),
	$pass, $fail, $log );
ds1a1_ok( 'Collect Payment render_mockup_preview emits <iframe with srcdoc',
	str_contains( $collect_src, '<iframe class="eem-mockup-preview"' ) &&
	str_contains( $collect_src, 'srcdoc=' ),
	$pass, $fail, $log );
ds1a1_ok( 'Create Order no longer inline-injects mockup body via <div>',
	false === strpos( $create_src, "'<div class=\"eem-mockup-preview\">'" ),
	$pass, $fail, $log );
ds1a1_ok( 'Collect Payment no longer inline-injects mockup body via <div>',
	false === strpos( $collect_src, "'<div class=\"eem-mockup-preview\">'" ),
	$pass, $fail, $log );
ds1a1_ok( 'iframe carries sandbox="allow-same-origin" (defense-in-depth)',
	str_contains( $create_src, 'sandbox="allow-same-origin"' ) &&
	str_contains( $collect_src, 'sandbox="allow-same-origin"' ),
	$pass, $fail, $log );
ds1a1_ok( 'admin.css .eem-mockup-preview sizes the iframe (width + height)',
	(bool) preg_match( '/\.eem-mockup-preview\s*\{[^}]*width:\s*100%[^}]*height:\s*80vh/s', $css_src ),
	$pass, $fail, $log );

// ── [3] Issue 4: Create Order registered as HIDDEN submenu ──────────
echo "\n[3] Create Order hidden-submenu registration\n";
// Match: add_submenu_page( '', __( 'Create Order' ... — empty parent slug.
ds1a1_ok( "Create Order add_submenu_page uses empty parent slug (hidden)",
	(bool) preg_match( "/add_submenu_page\(\s*\n?\s*''\s*,\s*\n?\s*__\(\s*'Create Order'/s", $admin_src ),
	$pass, $fail, $log );
ds1a1_ok( "Create Order NOT registered as visible submenu under MENU_SLUG",
	(bool) preg_match( "/add_submenu_page\(\s*\n?\s*self::MENU_SLUG\s*,\s*\n?\s*__\(\s*'Create Order'/s", $admin_src ) === false,
	$pass, $fail, $log );
// Runtime sidebar absence — fire admin_menu, then probe $submenu.
wp_set_current_user( 1 );
do_action( 'admin_menu' );
global $submenu;
$create_in_sidebar = false;
$collect_in_sidebar = false;
$dashboard_in_sidebar = false;
$parent_slug = EEM_Admin::MENU_SLUG;
if ( isset( $submenu[ $parent_slug ] ) ) {
	foreach ( $submenu[ $parent_slug ] as $item ) {
		// $item[2] is the slug.
		if ( ! isset( $item[2] ) ) { continue; }
		if ( 'equine-event-manager-create-order' === $item[2] )      { $create_in_sidebar = true; }
		if ( 'equine-event-manager-collect-payment' === $item[2] )   { $collect_in_sidebar = true; }
		if ( 'equine-event-manager-dashboard' === $item[2] )         { $dashboard_in_sidebar = true; }
	}
}
ds1a1_ok( 'Create Order does NOT appear in $submenu[equine-event-manager] sidebar',
	! $create_in_sidebar, $pass, $fail, $log );
ds1a1_ok( 'Collect Payment does NOT appear in $submenu[equine-event-manager] sidebar',
	! $collect_in_sidebar, $pass, $fail, $log );
ds1a1_ok( 'Dashboard DOES appear in $submenu[equine-event-manager] sidebar',
	$dashboard_in_sidebar, $pass, $fail, $log );

// ── [4] DS-1.A.1.1: anchor button COLOR specificity coverage ────────
echo "\n[4] Anchor button color specificity (DS-1.A.1.1)\n";
ds1a1_ok( '.eem-btn-collect-banner has a. chain on base block (color: #fff coverage)',
	(bool) preg_match( '/\.eem-btn-collect-banner,\s*\n\s*a\.eem-btn-collect-banner[^{]*\{[^}]*color:\s*#fff/s', $css_src ),
	$pass, $fail, $log );
ds1a1_ok( '.eem-btn-collect-banner has a. chain on :hover/:focus (color: #fff coverage)',
	(bool) preg_match( '/a\.eem-btn-collect-banner:hover[^{]*\{[^}]*color:\s*#fff/s', $css_src ),
	$pass, $fail, $log );
ds1a1_ok( '.eem-btn-ghost has a. chain (Back to Orders + Edit Reservation anchor coverage)',
	(bool) preg_match( '/\.eem-btn-ghost,\s*\n\s*a\.eem-btn-ghost\s*\{/s', $css_src ),
	$pass, $fail, $log );
ds1a1_ok( '.eem-btn-teal has a. chain (defensive coverage)',
	(bool) preg_match( '/\.eem-btn-teal,\s*\n\s*a\.eem-btn-teal\s*\{/s', $css_src ),
	$pass, $fail, $log );
ds1a1_ok( '.eem-btn-danger has a. chain (defensive coverage)',
	(bool) preg_match( '/\.eem-btn-danger,\s*\n\s*a\.eem-btn-danger\s*\{/s', $css_src ),
	$pass, $fail, $log );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
