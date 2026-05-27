<?php
/**
 * C7.X navigation fix-up smoke.
 *
 * Asserts the post-Build-to-Mockup navigation wire is correct:
 *   [1] EEM_Reservation_Editor_Page::url() returns the canonical
 *       admin.php?page=…&reservation_id=N pattern.
 *   [2] Reservations list desktop row Edit anchor uses the new URL
 *       (NOT the legacy WP CPT post.php?post=N&action=edit).
 *   [3] Reservations list mobile card Edit anchor uses the new URL.
 *   [4] Dashboard "Upcoming Reservations" row anchors use the new URL.
 *   [5] Orders list event-name anchors use the new URL.
 *   [6] Order Detail "View Reservation" / "Edit Reservation" anchors
 *       use the new URL (both meta-line + action-bar).
 *   [7] maybe_redirect_legacy_edit() targets the new URL for an
 *       en_reservation post (probe via WP `wp_redirect` filter).
 *
 * The 7 in-scope `get_edit_post_link()` call sites that were rewired:
 *   - admin/class-eem-reservations-list-page.php (desktop + mobile)
 *   - admin/class-eem-dashboard-page.php
 *   - admin/class-eem-orders-list-page.php (desktop event-link x2 +
 *     mobile event-link)
 *   - admin/class-eem-order-detail-page.php (meta-line + action-bar)
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7xn_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.X NAVIGATION FIX-UP SMOKE ===\n";

wp_set_current_user( 1 );

// Clean stale fixtures.
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'C7.X Nav' ) ) as $stale ) {
	wp_delete_post( $stale->ID, true );
}

$rid = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => 'C7.X Nav ' . wp_generate_password( 6, false, false ),
) );

// ── [1] url() helper ─────────────────────────────────────────────
echo "\n[1] EEM_Reservation_Editor_Page::url() helper\n";
$expected_pattern = '#/wp-admin/admin\.php\?page=equine-event-manager-reservation-editor&reservation_id=' . $rid . '$#';
$url              = EEM_Reservation_Editor_Page::url( $rid );
c7xn_ok( 'url(N) returns admin.php?page=…&reservation_id=N', (bool) preg_match( $expected_pattern, $url ), $pass, $fail, $log, $url );
$url0 = EEM_Reservation_Editor_Page::url( 0 );
c7xn_ok( 'url(0) returns base URL without reservation_id arg', false === strpos( $url0, 'reservation_id=' ), $pass, $fail, $log, $url0 );

// Anti-pattern guard: NEVER returns the legacy WP CPT edit URL.
c7xn_ok( 'url(N) does NOT contain post.php?post=', false === strpos( $url, 'post.php?post=' ), $pass, $fail, $log );

$new_url_regex = '#/wp-admin/admin\.php\?page=equine-event-manager-reservation-editor&reservation_id=\d+#';

// ── [2] Reservations list desktop row ───────────────────────────
echo "\n[2] Reservations list — desktop row\n";
$_GET = array(); // reset query state for render
$list_page = new EEM_Reservations_List_Page();
ob_start(); $list_page->render(); $list_html = (string) ob_get_clean();
$desktop_matches = array();
preg_match_all( '#<a class="eem-res-name" href="([^"]+)"#', $list_html, $desktop_matches );
c7xn_ok( 'desktop row has at least one eem-res-name anchor', ! empty( $desktop_matches[1] ), $pass, $fail, $log );
$desktop_all_new = true;
foreach ( (array) $desktop_matches[1] as $href ) {
	$decoded = html_entity_decode( $href );
	if ( ! preg_match( $new_url_regex, $decoded ) ) {
		$desktop_all_new = false;
		$log[] = "    debug: stray href={$decoded}";
		break;
	}
}
c7xn_ok( 'every desktop eem-res-name href matches new editor URL pattern', $desktop_all_new, $pass, $fail, $log );
c7xn_ok( 'no desktop eem-res-name href contains post.php?post=', false === strpos( $list_html, 'post.php?post=' ), $pass, $fail, $log );

// ── [3] Reservations list mobile cards ──────────────────────────
echo "\n[3] Reservations list — mobile card\n";
$mobile_matches = array();
preg_match_all( '#<a class="eem-mob-res-name" href="([^"]+)"#', $list_html, $mobile_matches );
c7xn_ok( 'mobile row has at least one eem-mob-res-name anchor', ! empty( $mobile_matches[1] ), $pass, $fail, $log );
$mobile_all_new = true;
foreach ( (array) $mobile_matches[1] as $href ) {
	if ( ! preg_match( $new_url_regex, html_entity_decode( $href ) ) ) {
		$mobile_all_new = false;
		break;
	}
}
c7xn_ok( 'every mobile eem-mob-res-name href matches new editor URL pattern', $mobile_all_new, $pass, $fail, $log );

// ── [4] Dashboard upcoming-rows ─────────────────────────────────
echo "\n[4] Dashboard — Upcoming Reservations row\n";
// Render private static via reflection (matches the smoke discipline
// for testing private renders elsewhere in the suite).
$ref = new ReflectionClass( 'EEM_Dashboard_Page' );
if ( $ref->hasMethod( 'render_upcoming_card' ) ) {
	$m = $ref->getMethod( 'render_upcoming_card' );
	$m->setAccessible( true );
	$rows = array(
		array(
			'id'             => $rid,
			'name'           => 'C7.X Nav fixture',
			'date_range'     => 'Jan 1 – Jan 3, 2026',
			'opens_in'       => array( 'label' => '', 'tone' => 'neutral' ),
			'tags'           => array(),
			'stall_progress' => array( 'assigned' => 0, 'total' => 0, 'percent' => 0 ),
			'orders'         => 0,
			'revenue'        => '$0.00',
		),
	);
	ob_start(); $m->invoke( null, $rows ); $dash_html = (string) ob_get_clean();
	$dash_matches = array();
	preg_match_all( '#<a class="eem-dashboard-res-row" href="([^"]+)"#', $dash_html, $dash_matches );
	c7xn_ok( 'dashboard renders at least one upcoming-row anchor', ! empty( $dash_matches[1] ), $pass, $fail, $log );
	$dash_all_new = true;
	foreach ( (array) $dash_matches[1] as $href ) {
		if ( ! preg_match( $new_url_regex, html_entity_decode( $href ) ) ) { $dash_all_new = false; break; }
	}
	c7xn_ok( 'every dashboard upcoming-row href matches new editor URL pattern', $dash_all_new, $pass, $fail, $log );
	// View-all link routes to the new reservations list, not the legacy WP edit.php.
	c7xn_ok( 'dashboard "View all" link does NOT use edit.php?post_type=', false === strpos( $dash_html, 'edit.php?post_type=en_reservation' ), $pass, $fail, $log );
} else {
	c7xn_ok( 'EEM_Dashboard_Page::render_upcoming_card exists', false, $pass, $fail, $log, 'method not found' );
}

// ── [5] Orders list event-link ──────────────────────────────────
echo "\n[5] Orders list — event-name anchor\n";
$src_orders_list = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-orders-list-page.php' );
c7xn_ok( 'orders list source has 0 get_edit_post_link() calls', 0 === substr_count( $src_orders_list, 'get_edit_post_link(' ), $pass, $fail, $log );
c7xn_ok( 'orders list source uses EEM_Reservation_Editor_Page::url(', false !== strpos( $src_orders_list, 'EEM_Reservation_Editor_Page::url(' ), $pass, $fail, $log );

// ── [6] Order Detail "View Reservation" / "Edit Reservation" ────
echo "\n[6] Order Detail — reservation anchors\n";
$src_order_detail = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-order-detail-page.php' );
c7xn_ok( 'order detail source has 0 get_edit_post_link() calls', 0 === substr_count( $src_order_detail, 'get_edit_post_link(' ), $pass, $fail, $log );
c7xn_ok( 'order detail source uses EEM_Reservation_Editor_Page::url(', false !== strpos( $src_order_detail, 'EEM_Reservation_Editor_Page::url(' ), $pass, $fail, $log );

// Reservations list + Dashboard source guards (defense-in-depth against
// future regressions where someone re-introduces get_edit_post_link).
$src_res_list  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservations-list-page.php' );
$src_dashboard = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-dashboard-page.php' );
c7xn_ok( 'reservations list source has 0 get_edit_post_link() calls', 0 === substr_count( $src_res_list, 'get_edit_post_link(' ), $pass, $fail, $log );
c7xn_ok( 'dashboard source has 0 get_edit_post_link() calls',         0 === substr_count( $src_dashboard, 'get_edit_post_link(' ), $pass, $fail, $log );

// ── [7] Legacy URL redirect ─────────────────────────────────────
echo "\n[7] Legacy post.php?post=N&action=edit → new editor redirect\n";
c7xn_ok( 'maybe_redirect_legacy_edit method exists',           method_exists( 'EEM_Reservation_Editor_Page', 'maybe_redirect_legacy_edit' ), $pass, $fail, $log );
c7xn_ok( 'resolve_legacy_edit_redirect_url method exists',     method_exists( 'EEM_Reservation_Editor_Page', 'resolve_legacy_edit_redirect_url' ), $pass, $fail, $log );

// Probe the testable resolver (returns URL or null without exit()).
// Hooked at load-post.php so the dispatcher does the actual
// wp_safe_redirect+exit in the real request path.
$_GET = array( 'post' => (string) $rid, 'action' => 'edit' );
$resolved = EEM_Reservation_Editor_Page::resolve_legacy_edit_redirect_url();
$_GET = array();
c7xn_ok( 'resolver returns a URL for en_reservation post', is_string( $resolved ) && '' !== $resolved, $pass, $fail, $log );
if ( is_string( $resolved ) ) {
	c7xn_ok( 'resolver URL matches new editor pattern', (bool) preg_match( $new_url_regex, $resolved ), $pass, $fail, $log, $resolved );
}

// Non-en_reservation post should NOT redirect.
$other_pid = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'C7.X Nav non-res' ) );
$_GET = array( 'post' => (string) $other_pid, 'action' => 'edit' );
$other_resolved = EEM_Reservation_Editor_Page::resolve_legacy_edit_redirect_url();
$_GET = array();
c7xn_ok( 'resolver returns null for non-en_reservation post', null === $other_resolved, $pass, $fail, $log );
wp_delete_post( $other_pid, true );

// No $_GET['post'] at all should NOT redirect (avoid surprise hijacking
// of unrelated post.php loads).
$_GET = array();
c7xn_ok( 'resolver returns null when $_GET[post] is absent', null === EEM_Reservation_Editor_Page::resolve_legacy_edit_redirect_url(), $pass, $fail, $log );

// Action other than 'edit' (e.g. 'trash') should NOT redirect.
$_GET = array( 'post' => (string) $rid, 'action' => 'trash' );
c7xn_ok( 'resolver returns null when action is not edit', null === EEM_Reservation_Editor_Page::resolve_legacy_edit_redirect_url(), $pass, $fail, $log );
$_GET = array();

// Verify the load-post.php hook is wired (defense against a future
// commit that removes the add_action() line in the bootstrap).
global $wp_filter;
$wired = false;
if ( isset( $wp_filter['load-post.php'] ) ) {
	foreach ( $wp_filter['load-post.php']->callbacks as $cbs ) {
		foreach ( $cbs as $cb ) {
			if ( is_array( $cb['function'] ) && 'EEM_Reservation_Editor_Page' === $cb['function'][0] && 'maybe_redirect_legacy_edit' === $cb['function'][1] ) {
				$wired = true;
			}
		}
	}
}
c7xn_ok( 'maybe_redirect_legacy_edit is hooked to load-post.php', $wired, $pass, $fail, $log );

wp_delete_post( $rid, true );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
