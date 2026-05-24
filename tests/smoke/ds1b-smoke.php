<?php
/**
 * DS-1.B smoke — Admin Dashboard render against .mockups/dashboard_page.html.
 *
 *   [1]  Class + route + sidebar visibility
 *   [2]  Range filter renders all 5 options with correct default
 *   [3]  KPI grid — 4 cards, each with value + label + sub
 *   [4]  Em-dash placeholders (CLEANUP #37/#38/#39) preserve "—" not "0"
 *   [5]  Upcoming Reservations content density
 *   [6]  Needs Attention content density + 6 rows
 *   [7]  Recent Orders — 5-digit #NNNNN via canonical helper + status pill
 *   [8]  Quick Actions — 4 tiles + correct href routing per kickoff
 *   [9]  Revenue chart — bars + total
 *   [10] This Week — 5 rows
 *   [11] CLEANUP entries #37/#38/#39/#40 present
 *   [12] CSS — Dashboard component selectors shipped + anchor umbrella coverage
 *   [13] JS — dashboard-range-change handler shipped
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function ds1b_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== DS-1.B SMOKE ===\n";

$admin_src   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$css_src     = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$js_src      = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$cleanup_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'CLEANUP.md' );

// ── [1] Class + route + sidebar visibility ──────────────────────────
echo "\n[1] Class + route registration\n";
ds1b_ok( 'EEM_Dashboard_Page class loaded',  class_exists( 'EEM_Dashboard_Page' ),  $pass, $fail, $log );
ds1b_ok( 'EEM_Dashboard_Repo class loaded',  class_exists( 'EEM_Dashboard_Repo' ),  $pass, $fail, $log );
ds1b_ok( 'MENU_SLUG constant correct',       'equine-event-manager-dashboard' === EEM_Dashboard_Page::MENU_SLUG, $pass, $fail, $log );
ds1b_ok( 'admin.php binds dashboard route to EEM_Dashboard_Page::render',
	str_contains( $admin_src, "array( 'EEM_Dashboard_Page', 'render' )" ),
	$pass, $fail, $log );
ds1b_ok( 'render_dashboard_stub_page callback NO LONGER wired',
	false === strpos( $admin_src, "array( \$this, 'render_dashboard_stub_page' )" ),
	$pass, $fail, $log );
wp_set_current_user( 1 );
do_action( 'admin_menu' );
global $submenu;
$dashboard_in_sidebar = false;
if ( isset( $submenu[ EEM_Admin::MENU_SLUG ] ) ) {
	foreach ( $submenu[ EEM_Admin::MENU_SLUG ] as $item ) {
		if ( isset( $item[2] ) && 'equine-event-manager-dashboard' === $item[2] ) {
			$dashboard_in_sidebar = true;
		}
	}
}
ds1b_ok( 'Dashboard IS visible in the sidebar', $dashboard_in_sidebar, $pass, $fail, $log );

// ── Render once for inspection ──────────────────────────────────────
$_GET = array( 'page' => 'equine-event-manager-dashboard' );
ob_start();
EEM_Dashboard_Page::render();
$html = ob_get_clean();

// ── [2] Range filter ────────────────────────────────────────────────
echo "\n[2] Range filter\n";
ds1b_ok( 'range select rendered with dashboard-range-change action',
	str_contains( $html, 'data-eem-action="dashboard-range-change"' ),
	$pass, $fail, $log );
foreach ( array( 'last-7' => 'Last 7 days', 'last-30' => 'Last 30 days', 'last-90' => 'Last 90 days', 'this-year' => 'This year', 'all-time' => 'All time' ) as $val => $label ) {
	ds1b_ok( "range option '{$label}' (value={$val}) present",
		str_contains( $html, 'value="' . $val . '"' ) && str_contains( $html, $label ),
		$pass, $fail, $log );
}
ds1b_ok( 'last-30 is selected by default',
	(bool) preg_match( '/value="last-30"\s+selected/', $html ),
	$pass, $fail, $log );

// ── [3] KPI grid — 4 cards with value + label + sub ─────────────────
echo "\n[3] KPI grid\n";
ds1b_ok( 'KPI grid container present', str_contains( $html, 'eem-dashboard-kpi-grid' ), $pass, $fail, $log );
foreach ( array( 'blue', 'orange', 'green', 'red' ) as $tone ) {
	ds1b_ok( "KPI card tone {$tone} present",
		str_contains( $html, 'eem-dashboard-kpi-card--' . $tone ),
		$pass, $fail, $log );
}
ds1b_ok( 'KPI label "Total Revenue" rendered',        str_contains( $html, 'Total Revenue' ),        $pass, $fail, $log );
ds1b_ok( 'KPI label "Outstanding Payments" rendered', str_contains( $html, 'Outstanding Payments' ), $pass, $fail, $log );
ds1b_ok( 'KPI label "Total Orders" rendered',         str_contains( $html, 'Total Orders' ),         $pass, $fail, $log );
ds1b_ok( 'KPI label "Unassigned Stalls" rendered',    str_contains( $html, 'Unassigned Stalls' ),    $pass, $fail, $log );
ds1b_ok( 'KPI value class rendered', substr_count( $html, 'eem-dashboard-kpi-value' ) >= 4, $pass, $fail, $log );
ds1b_ok( 'KPI sub class rendered',   substr_count( $html, 'eem-dashboard-kpi-sub'   ) >= 4, $pass, $fail, $log );

// ── [4] Em-dash placeholders (CLEANUP #37/#38) ──────────────────────
echo "\n[4] Em-dash placeholders (graceful-degrade contract)\n";
// Unassigned Stalls KPI value must be "—", not "0".
ds1b_ok( 'Unassigned Stalls KPI renders "—" not "0"',
	(bool) preg_match( '/Unassigned Stalls.*?eem-dashboard-kpi-value">—</s', $html ),
	$pass, $fail, $log );
// Stall progress count em-dash — render carries "— / —" pattern.
ds1b_ok( 'Stall progress shows "— / —" (CLEANUP #38)',
	str_contains( $html, '— / —' ),
	$pass, $fail, $log );

// ── [5] Upcoming Reservations content density ───────────────────────
echo "\n[5] Upcoming Reservations\n";
ds1b_ok( 'Upcoming Reservations card title rendered',
	str_contains( $html, 'Upcoming Reservations' ),
	$pass, $fail, $log );
ds1b_ok( 'Upcoming Reservations "View all" link present',
	str_contains( $html, 'edit.php?post_type=en_reservation' ),
	$pass, $fail, $log );
// When at least one reservation exists, expect row chrome — otherwise empty-state.
$has_res_query = new WP_Query( array( 'post_type' => 'en_reservation', 'posts_per_page' => 1, 'fields' => 'ids' ) );
if ( ! empty( $has_res_query->posts ) ) {
	ds1b_ok( 'at least one .eem-dashboard-res-row rendered when reservations exist',
		str_contains( $html, 'eem-dashboard-res-row' ),
		$pass, $fail, $log );
} else {
	ds1b_ok( 'empty-state rendered when no reservations',
		str_contains( $html, 'No upcoming reservations' ),
		$pass, $fail, $log );
}

// ── [6] Needs Attention card ────────────────────────────────────────
echo "\n[6] Needs Attention card\n";
ds1b_ok( 'Needs Attention title rendered', str_contains( $html, 'Needs Attention' ), $pass, $fail, $log );
ds1b_ok( '6 attention rows rendered', substr_count( $html, 'eem-dashboard-attention-row' ) === 6, $pass, $fail, $log );
ds1b_ok( 'attention-count "6 items" pill rendered',
	(bool) preg_match( '/eem-dashboard-attention-count">6 items?</', $html ),
	$pass, $fail, $log );
// CLEANUP #40 marker — agreement em-dash.
ds1b_ok( 'agreement em-dash row title contains "— customers haven\'t signed the agreement"',
	str_contains( $html, "— customers haven&#039;t signed the agreement" ) || str_contains( $html, "— customers haven't signed the agreement" ),
	$pass, $fail, $log );

// ── [7] Recent Orders — 5-digit + status pill ───────────────────────
echo "\n[7] Recent Orders\n";
ds1b_ok( 'Recent Orders title rendered', str_contains( $html, 'Recent Orders' ), $pass, $fail, $log );
// At least one 5-digit padded order number "#NNNNN" present (seed DB has 30 orders).
ds1b_ok( '5-digit padded order # rendered (#NNNNN)',
	(bool) preg_match( '/#\d{5}/', $html ),
	$pass, $fail, $log );
ds1b_ok( 'Recent Orders row uses .eem-dashboard-order-row class',
	str_contains( $html, 'eem-dashboard-order-row' ),
	$pass, $fail, $log );
ds1b_ok( 'order detail href translates via order_detail_url helper (carries order_key)',
	(bool) preg_match( '/eem-dashboard-order-row" href="[^"]*order_key=/', $html ),
	$pass, $fail, $log );
ds1b_ok( 'status pill class .eem-status-badge rendered on at least one order',
	str_contains( $html, 'eem-status-badge' ),
	$pass, $fail, $log );

// ── [8] Quick Actions ───────────────────────────────────────────────
echo "\n[8] Quick Actions\n";
ds1b_ok( 'Quick Actions title rendered', str_contains( $html, 'Quick Actions' ), $pass, $fail, $log );
ds1b_ok( '4 quick-action tiles rendered', substr_count( $html, 'eem-dashboard-qa-btn' ) === 4, $pass, $fail, $log );
// DS-1.B.5: param renamed status → billing (Orders list reads ?billing=).
ds1b_ok( 'Quick Actions "Collect Payment" tile routes to orders&billing=unpaid (NOT status, NOT collect-payment)',
	(bool) preg_match( '/eem-dashboard-qa-btn" href="[^"]*page=equine-event-manager-orders[^"]*billing=unpaid[^"]*">\s*<span class="eem-dashboard-qa-icon eem-dashboard-qi-purple/s', $html ),
	$pass, $fail, $log );
ds1b_ok( 'Needs Attention "orders awaiting payment" row uses billing=unpaid (not status=)',
	(bool) preg_match( '/eem-dashboard-attention-row" href="[^"]*page=equine-event-manager-orders[^"]*billing=unpaid/', $html ),
	$pass, $fail, $log );
ds1b_ok( 'No Dashboard hrefs use the wrong ?status= param against Orders (regression guard)',
	0 === preg_match( '/page=equine-event-manager-orders[^"]*[?&]status=/', $html ),
	$pass, $fail, $log );
ds1b_ok( 'Quick Actions "Export Report" tile routes to reports',
	(bool) preg_match( '/eem-dashboard-qa-btn" href="[^"]*page=equine-event-manager-reports/', $html ),
	$pass, $fail, $log );
ds1b_ok( 'Quick Actions "Create Order" tile routes via create_order_url',
	str_contains( $html, 'page=equine-event-manager-create-order' ),
	$pass, $fail, $log );

// ── [9] Revenue chart ───────────────────────────────────────────────
echo "\n[9] Revenue chart\n";
ds1b_ok( 'Revenue chart title rendered', str_contains( $html, 'Revenue by Reservation' ), $pass, $fail, $log );
ds1b_ok( 'Revenue chart total label rendered',
	str_contains( $html, 'Total collected (all time)' ),
	$pass, $fail, $log );
// Chart bars OR empty-state, depending on seed data.
$bars_count = substr_count( $html, 'eem-dashboard-rev-bar-wrap' );
ds1b_ok( 'Revenue chart renders bars OR empty-state',
	$bars_count >= 1 || str_contains( $html, 'No revenue recorded yet' ),
	$pass, $fail, $log,
	'bars rendered: ' . $bars_count
);

// ── [10] This Week ──────────────────────────────────────────────────
echo "\n[10] This Week\n";
ds1b_ok( 'This Week title rendered', str_contains( $html, 'This Week' ), $pass, $fail, $log );
ds1b_ok( '5 This Week rows rendered', substr_count( $html, 'eem-dashboard-tw-row' ) === 5, $pass, $fail, $log );
ds1b_ok( 'This Week "Stalls assigned" row is em-dash (CLEANUP #39)',
	(bool) preg_match( '/Stalls assigned<\/span>\s*<span class="eem-dashboard-tw-value[^"]*">—</', $html ),
	$pass, $fail, $log );

// ── [11] CLEANUP entries ────────────────────────────────────────────
echo "\n[11] CLEANUP entries\n";
ds1b_ok( 'CLEANUP #37 (Unassigned Stalls KPI) present',                            str_contains( $cleanup_src, '### 37. Dashboard Unassigned Stalls KPI' ),  $pass, $fail, $log );
ds1b_ok( 'CLEANUP #38 (Upcoming Reservations stall progress) present',             str_contains( $cleanup_src, '### 38. Dashboard Upcoming Reservations' ), $pass, $fail, $log );
ds1b_ok( 'CLEANUP #39 (C8-dependent attention rows + This Week) present',          str_contains( $cleanup_src, '### 39. Dashboard — C8-dependent' ),       $pass, $fail, $log );
ds1b_ok( 'CLEANUP #40 (C11-dependent agreement row) present',                      str_contains( $cleanup_src, '### 40. Dashboard Needs Attention — C11' ), $pass, $fail, $log );

// ── [12] CSS — Dashboard component selectors + anchor umbrella ──────
echo "\n[12] CSS coverage\n";
ds1b_ok( '.eem-dashboard-kpi-grid CSS shipped',     str_contains( $css_src, '.eem-dashboard-kpi-grid' ),    $pass, $fail, $log );
ds1b_ok( '.eem-dashboard-rev-bar CSS shipped',      str_contains( $css_src, '.eem-dashboard-rev-bar' ),     $pass, $fail, $log );
ds1b_ok( '.eem-dashboard-qa-btn CSS shipped',       str_contains( $css_src, '.eem-dashboard-qa-btn' ),      $pass, $fail, $log );
ds1b_ok( '.eem-dashboard-attention-row CSS shipped',str_contains( $css_src, '.eem-dashboard-attention-row' ),$pass, $fail, $log );
// Anchor umbrella from DS-1.A.1 still in place (Dashboard buttons inherit).
ds1b_ok( 'anchor-btn umbrella `a.eem-btn:hover` still present',
	(bool) preg_match( '/a\.eem-btn:hover[^{]*\{[^}]*text-decoration\s*:\s*none/s', $css_src ),
	$pass, $fail, $log );

// ── [14] DS-1.B.1: Icon-density (SVG glyph presence, not just container) ──
echo "\n[14] Icon-density (DS-1.B.1)\n";
$svg_count = substr_count( $html, '<svg' );
ds1b_ok( "Render contains >=20 inline <svg tags (mockup has 22), actual={$svg_count}",
	$svg_count >= 20,
	$pass, $fail, $log );
// Each KPI card carries an icon inside its label.
preg_match_all( '#<div class="eem-dashboard-kpi-card[^"]*">.*?<div class="eem-dashboard-kpi-label">(.*?)</div>#s', $html, $kpi_blocks );
ds1b_ok( 'every KPI card label contains an <svg',
	! empty( $kpi_blocks[1] ) && count( array_filter( $kpi_blocks[1], function( $h ) { return false !== strpos( $h, '<svg' ); } ) ) === count( $kpi_blocks[1] ),
	$pass, $fail, $log );
// Every Quick Action tile carries an icon.
preg_match_all( '#<span class="eem-dashboard-qa-icon[^"]*">(.*?)</span>#s', $html, $qa_blocks );
ds1b_ok( 'every Quick Action tile icon container contains an <svg',
	! empty( $qa_blocks[1] ) && count( array_filter( $qa_blocks[1], function( $h ) { return false !== strpos( $h, '<svg' ); } ) ) === count( $qa_blocks[1] ),
	$pass, $fail, $log );
// Every attention row carries an icon.
preg_match_all( '#<span class="eem-dashboard-attention-icon[^"]*">(.*?)</span>#s', $html, $att_blocks );
ds1b_ok( 'every attention row icon container contains an <svg',
	! empty( $att_blocks[1] ) && count( array_filter( $att_blocks[1], function( $h ) { return false !== strpos( $h, '<svg' ); } ) ) === count( $att_blocks[1] ),
	$pass, $fail, $log );
// Card titles carry icons.
preg_match_all( '#<div class="eem-card-title">(.*?)</div>#s', $html, $title_blocks );
ds1b_ok( 'every card title contains an <svg (6 cards: Upcoming/Attention/Recent/Quick/Revenue/ThisWeek)',
	count( $title_blocks[1] ) >= 6 && count( array_filter( $title_blocks[1], function( $h ) { return false !== strpos( $h, '<svg' ); } ) ) === count( $title_blocks[1] ),
	$pass, $fail, $log );
// SVG bodies are non-empty (path/line/rect/polyline/circle/polygon present).
ds1b_ok( 'rendered SVGs contain non-empty path data (path|line|rect|circle|polyline|polygon)',
	(bool) preg_match( '/<svg[^>]*>\s*<(path|line|rect|circle|polyline|polygon)/', $html ),
	$pass, $fail, $log );

// ── [15] DS-1.B.1: status-pill class-prefix regression guard ────────
echo "\n[15] Status pill class prefix\n";
ds1b_ok( 'Recent Orders status pill uses eem-status-<slug> class (not bare slug)',
	(bool) preg_match( '/<span class="eem-status-badge eem-status-(paid|unpaid|invoice|refunded|cancelled|partial)/', $html ),
	$pass, $fail, $log );
ds1b_ok( 'NO bare-slug status class shipped (regression guard)',
	0 === preg_match( '/<span class="eem-status-badge (paid|unpaid|invoice|refunded|cancelled|partial)"/', $html ),
	$pass, $fail, $log );

// ── [16] DS-1.B.1: greeting capitalization + trend deltas ──────────
echo "\n[16] Greeting capitalization + trend deltas\n";
ds1b_ok( 'greeting renders with capitalised name (Good morning|afternoon|evening, X)',
	(bool) preg_match( '/Good (morning|afternoon|evening)(,\s+[A-Z]|\s+·)/', $html ),
	$pass, $fail, $log );
// Trend delta — either ↑/↓ N% on Total Revenue OR "—" fallback when no prior data.
ds1b_ok( 'Total Revenue KPI carries trend delta (↑/↓ N% or —)',
	(bool) preg_match( '/Total Revenue.*?eem-dashboard-kpi-tone--(up|down|flat|warn)/s', $html ),
	$pass, $fail, $log );
ds1b_ok( 'Total Orders KPI carries trend delta (↑/↓ N or —)',
	(bool) preg_match( '/Total Orders.*?eem-dashboard-kpi-tone--(up|down|flat|warn)/s', $html ),
	$pass, $fail, $log );

// ── [13] JS — range-change handler ──────────────────────────────────
echo "\n[13] JS — range filter handler\n";
ds1b_ok( 'dashboard-range-change handler present in admin.js',
	str_contains( $js_src, 'dashboard-range-change' ),
	$pass, $fail, $log );
ds1b_ok( 'handler rebuilds URL with ?range= param',
	str_contains( $js_src, "searchParams.set('range'" ) || str_contains( $js_src, 'searchParams.set("range"' ),
	$pass, $fail, $log );

// ── [17] DS-1.B.2 regression guards ────────────────────────────────
echo "\n[17] DS-1.B.2 — anchor umbrella + padding + chart bar + qa tints\n";
// Anchor umbrella: each Dashboard anchor class has explicit text-decoration:none coverage.
foreach ( array( 'res-row', 'attention-row', 'order-row', 'qa-btn' ) as $cls ) {
	ds1b_ok( "a.eem-dashboard-{$cls} has text-decoration:none umbrella",
		(bool) preg_match( '/a\.eem-dashboard-' . preg_quote( $cls, '/' ) . ':hover[\s\S]{0,800}?text-decoration\s*:\s*none/', $css_src ),
		$pass, $fail, $log );
}
// Card-link no-underline fix (cross-page mockup convention).
ds1b_ok( '.eem-card-link:hover NO LONGER uses text-decoration: underline',
	0 === preg_match( '/\.eem-card-link:hover\s*\{\s*text-decoration\s*:\s*underline/', $css_src ),
	$pass, $fail, $log );

// DS-1.B.3 (final): dashboard-body horizontal padding must MIRROR
// page-header horizontal padding at every breakpoint so card box
// left-edge === page title text left-edge.
ds1b_ok( '.eem-dashboard-body desktop padding = 18px horizontal (matches .eem-page-header desktop)',
	(bool) preg_match( '/^\.eem-dashboard-body\s*\{\s*padding:\s*18px\s+18px/m', $css_src ),
	$pass, $fail, $log );
ds1b_ok( '.eem-dashboard-body tablet padding = 14px horizontal (matches .eem-page-header @1024 override)',
	(bool) preg_match( '/@media\s*\(\s*max-width:\s*1024px\s*\)\s*\{[\s\S]*?\.eem-dashboard-body\s*\{\s*padding:\s*14px\s+14px/', $css_src ),
	$pass, $fail, $log );
ds1b_ok( '.eem-dashboard-body mobile padding = 14px horizontal (matches .eem-page-header @767 override)',
	(bool) preg_match( '/@media\s*\(\s*max-width:\s*767px\s*\)\s*\{[\s\S]*?\.eem-dashboard-body\s*\{\s*padding:\s*12px\s+14px/', $css_src ),
	$pass, $fail, $log );
// Parity guard — extract page-header padding at desktop and dashboard-body padding at desktop, confirm horizontal values match.
preg_match( '/^\.eem-page-header\s*\{\s*padding:\s*\d+px\s+(\d+)px/m', $css_src, $hdr_m );
preg_match( '/^\.eem-dashboard-body\s*\{\s*padding:\s*\d+px\s+(\d+)px/m', $css_src, $body_m );
ds1b_ok( "desktop padding parity: page-header horizontal ({$hdr_m[1]}px) == dashboard-body horizontal ({$body_m[1]}px)",
	isset( $hdr_m[1] ) && isset( $body_m[1] ) && $hdr_m[1] === $body_m[1],
	$pass, $fail, $log );

// Rev-chart bar — wrap stretches to fill the 90px container so % heights resolve.
ds1b_ok( '.eem-dashboard-rev-bar-wrap uses align-self:stretch (chart bar height bug fix)',
	(bool) preg_match( '/\.eem-dashboard-rev-bar-wrap\s*\{[^}]*align-self\s*:\s*stretch/s', $css_src ),
	$pass, $fail, $log );
ds1b_ok( '.eem-dashboard-rev-bar-wrap uses justify-content:flex-end (children stack from bottom)',
	(bool) preg_match( '/\.eem-dashboard-rev-bar-wrap\s*\{[^}]*justify-content\s*:\s*flex-end/s', $css_src ),
	$pass, $fail, $log );
ds1b_ok( '.eem-dashboard-rev-bars retains explicit 90px height',
	(bool) preg_match( '/\.eem-dashboard-rev-bars\s*\{[^}]*height\s*:\s*90px/s', $css_src ),
	$pass, $fail, $log );

// Quick Actions tints — verify the deeper local values shipped (not the pale shared tokens).
ds1b_ok( '.eem-dashboard-qi-blue uses deepened tint #DBE9FE (not the pale --eem-info-bg)',
	(bool) preg_match( '/\.eem-dashboard-qi-blue\s*\{\s*background\s*:\s*#DBE9FE/i', $css_src ),
	$pass, $fail, $log );
ds1b_ok( '.eem-dashboard-qi-green deepened',
	(bool) preg_match( '/\.eem-dashboard-qi-green\s*\{\s*background\s*:\s*#D1FAE5/i', $css_src ),
	$pass, $fail, $log );
ds1b_ok( '.eem-dashboard-qi-purple deepened',
	(bool) preg_match( '/\.eem-dashboard-qi-purple\s*\{\s*background\s*:\s*#E5E0FF/i', $css_src ),
	$pass, $fail, $log );
ds1b_ok( '.eem-dashboard-qi-orange deepened',
	(bool) preg_match( '/\.eem-dashboard-qi-orange\s*\{\s*background\s*:\s*#FFE3CC/i', $css_src ),
	$pass, $fail, $log );

// ── [18] DS-1.B.4 — body class + sidebar position ───────────────────
echo "\n[18] DS-1.B.4 — body shell class + sidebar position\n";
// Body-class filter: simulate Dashboard page context and assert shell class added.
$_GET = array( 'page' => 'equine-event-manager-dashboard' );
set_current_screen( 'admin_page_equine-event-manager-dashboard' );
$dash_body_classes = apply_filters( 'admin_body_class', '' );
ds1b_ok( 'Dashboard body classes include eem-shell-page',
	false !== strpos( $dash_body_classes, 'eem-shell-page' ),
	$pass, $fail, $log, "got: '{$dash_body_classes}'" );
ds1b_ok( 'Dashboard body classes include eem-shell-page--dashboard',
	false !== strpos( $dash_body_classes, 'eem-shell-page--dashboard' ),
	$pass, $fail, $log, "got: '{$dash_body_classes}'" );

// Sidebar position: Dashboard must be the FIRST entry under MENU_SLUG.
global $submenu;
if ( isset( $submenu[ EEM_Admin::MENU_SLUG ] ) ) {
	$first = reset( $submenu[ EEM_Admin::MENU_SLUG ] );
	$first_slug = isset( $first[2] ) ? (string) $first[2] : '';
	ds1b_ok( 'Dashboard is the FIRST entry in Event Manager submenu',
		'equine-event-manager-dashboard' === $first_slug,
		$pass, $fail, $log, "first submenu slug is '{$first_slug}'" );
} else {
	ds1b_ok( 'Event Manager submenu exists', false, $pass, $fail, $log, 'submenu not registered' );
}

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
