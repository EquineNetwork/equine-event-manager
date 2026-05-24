<?php
/**
 * DS-1.A smoke — Design System Fidelity (cross-cutting).
 *
 * Covers per the DS-1.A scope kickoff:
 *   [1]  Google Fonts enqueue registered + on correct CDN URL
 *   [2]  body.eem-shell-page font-family rule shipped in admin.css
 *   [3]  Invoicing route + render method + helper fully stripped
 *   [4]  Stall Charts slug renamed (singular → plural) + sidebar label
 *        renamed ("Stall & RV Charts")
 *   [5]  3 new admin routes wired (Create Order, Collect Payment,
 *        Dashboard) and reachable via add_submenu_page registration
 *   [6]  Orders list Create Order button + Collect pill href use the
 *        new helpers (no more invoicing route)
 *   [7]  Order Detail Payment Outstanding banner is now <a>, not <button>
 *   [8]  EEM_Orders_List_Page::create_order_url + collect_payment_url
 *        static helpers exist + return canonical URLs
 *   [9]  CLEANUP #25 closed: .eem-btn-primary rule no longer uses
 *        --eem-navy (uses --eem-electric)
 *   [10] 4 shipped mockup sidebars updated to post-HANDOFF canonical
 *   [11] enqueue_backend_shell_styles gate includes the 3 new routes
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== DS-1.A SMOKE ===\n";

$admin_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$orders_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-orders-list-page.php' );
$detail_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-order-detail-page.php' );
$css_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );

// ── [1] Google Fonts enqueue ───────────────────────────────────────
echo "\n[1] Google Fonts enqueue (Space Grotesk + IBM Plex Sans)\n";
ok( "admin.php enqueue method references 'eem-google-fonts' handle",
	str_contains( $admin_src, "wp_enqueue_style(\n\t\t\t'eem-google-fonts'" ) || str_contains( $admin_src, "'eem-google-fonts'" ),
	$pass, $fail, $log );
ok( 'enqueue points at fonts.googleapis.com CDN',
	str_contains( $admin_src, 'fonts.googleapis.com/css2' ),
	$pass, $fail, $log );
ok( 'enqueue requests Space Grotesk family',
	str_contains( $admin_src, 'family=Space+Grotesk' ),
	$pass, $fail, $log );
ok( 'enqueue requests IBM Plex Sans family',
	str_contains( $admin_src, 'family=IBM+Plex+Sans' ) || str_contains( $admin_src, 'IBM+Plex+Sans' ),
	$pass, $fail, $log );
ok( 'enqueue uses display=swap to avoid FOIT',
	str_contains( $admin_src, 'display=swap' ),
	$pass, $fail, $log );
ok( 'admin.css depends on eem-google-fonts (load order: fonts before admin.css)',
	str_contains( $admin_src, "'eem-admin', EQUINE_EVENT_MANAGER_URL . 'assets/css/admin.css', array( 'eem-google-fonts'" ),
	$pass, $fail, $log );

// ── [2] body.eem-shell-page font-family rule ────────────────────────
echo "\n[2] body-level font-family rule\n";
ok( 'admin.css contains body.eem-shell-page font-family rule',
	(bool) preg_match( '/body\.eem-shell-page\s*\{[^}]*font-family\s*:\s*var\(\s*--eem-font-ui\s*\)/s', $css_src ),
	$pass, $fail, $log );

// ── [3] Invoicing strip ─────────────────────────────────────────────
echo "\n[3] Invoicing route + method + helper stripped\n";
ok( "no add_submenu_page() registers 'equine-event-manager-invoicing'",
	false === strpos( $admin_src, "'equine-event-manager-invoicing'" ),
	$pass, $fail, $log );
ok( 'no render_invoicing_page method',
	false === strpos( $admin_src, 'function render_invoicing_page' ),
	$pass, $fail, $log );
ok( 'no get_invoicing_reservation_options helper',
	false === strpos( $admin_src, 'function get_invoicing_reservation_options' ),
	$pass, $fail, $log );
ok( 'no $invoicing_hook private property',
	false === strpos( $admin_src, '$invoicing_hook' ),
	$pass, $fail, $log );
ok( 'no body class eem-shell-page--invoicing assignment',
	false === strpos( $admin_src, "eem-shell-page--invoicing'" ),
	$pass, $fail, $log );
ok( 'orders list no longer routes Create Order at invoicing',
	false === strpos( $orders_src, "page=equine-event-manager-invoicing" ),
	$pass, $fail, $log );

// ── [4] Stall Charts slug + sidebar label rename ────────────────────
echo "\n[4] Stall Charts → Stall & RV Charts rename\n";
ok( "add_submenu_page uses singular-to-plural slug 'equine-event-manager-stall-charts'",
	str_contains( $admin_src, "'equine-event-manager-stall-charts'" ),
	$pass, $fail, $log );
ok( "sidebar menu label is 'Stall & RV Charts'",
	str_contains( $admin_src, "__( 'Stall & RV Charts'" ),
	$pass, $fail, $log );
ok( 'no remaining singular slug refs in admin.php (outside comments)',
	(bool) preg_match( "/equine-event-manager-stall-chart[^s'\"]/", $admin_src ) === false || substr_count( $admin_src, "equine-event-manager-stall-chart'" ) === 0,
	$pass, $fail, $log );

// ── [5] 3 new admin routes registered ───────────────────────────────
echo "\n[5] New admin routes (create-order, collect-payment, dashboard)\n";
ok( 'EEM_Create_Order_Page class loaded', class_exists( 'EEM_Create_Order_Page' ), $pass, $fail, $log );
ok( 'EEM_Collect_Payment_Page class loaded', class_exists( 'EEM_Collect_Payment_Page' ), $pass, $fail, $log );
ok( 'EEM_Create_Order_Page::render method exists', method_exists( 'EEM_Create_Order_Page', 'render' ), $pass, $fail, $log );
ok( 'EEM_Collect_Payment_Page::render method exists', method_exists( 'EEM_Collect_Payment_Page', 'render' ), $pass, $fail, $log );
ok( 'create-order menu slug constant',  'equine-event-manager-create-order'  === EEM_Create_Order_Page::MENU_SLUG,  $pass, $fail, $log );
ok( 'collect-payment menu slug constant', 'equine-event-manager-collect-payment' === EEM_Collect_Payment_Page::MENU_SLUG, $pass, $fail, $log );
ok( 'admin.php register_admin_pages binds Create Order',
	str_contains( $admin_src, "array( 'EEM_Create_Order_Page', 'render' )" ),
	$pass, $fail, $log );
ok( 'admin.php register_admin_pages binds Collect Payment',
	str_contains( $admin_src, "array( 'EEM_Collect_Payment_Page', 'render' )" ),
	$pass, $fail, $log );
ok( 'admin.php register_admin_pages registers Dashboard stub',
	str_contains( $admin_src, "'equine-event-manager-dashboard'" ) && str_contains( $admin_src, 'render_dashboard_stub_page' ),
	$pass, $fail, $log );
ok( 'render_dashboard_stub_page method exists',
	method_exists( 'EEM_Admin', 'render_dashboard_stub_page' ),
	$pass, $fail, $log );

// ── [6] Orders list href updates ────────────────────────────────────
echo "\n[6] Orders list Create Order + Collect pill href\n";
ok( 'orders list "+ Create Order" button uses create_order_url helper',
	str_contains( $orders_src, 'self::create_order_url()' ),
	$pass, $fail, $log );
ok( 'orders list per-row Collect pill uses collect_payment_url helper',
	str_contains( $orders_src, '$collect_url = self::collect_payment_url' ),
	$pass, $fail, $log );

// ── [7] Order Detail banner button → anchor ─────────────────────────
echo "\n[7] Order Detail Payment Outstanding banner conversion\n";
ok( 'banner uses <a> not <button>',
	str_contains( $detail_src, "<a class=\"eem-btn eem-btn-collect-banner\" href=" ),
	$pass, $fail, $log );
ok( 'old <button data-eem-action="order-collect-single"> removed',
	false === strpos( $detail_src, "data-eem-action=\"order-collect-single\"" ),
	$pass, $fail, $log );

// ── [8] New static helpers on EEM_Orders_List_Page ──────────────────
echo "\n[8] create_order_url + collect_payment_url helpers\n";
ok( 'create_order_url method exists',
	method_exists( 'EEM_Orders_List_Page', 'create_order_url' ),
	$pass, $fail, $log );
ok( 'collect_payment_url method exists',
	method_exists( 'EEM_Orders_List_Page', 'collect_payment_url' ),
	$pass, $fail, $log );
ok( 'create_order_url returns admin URL with correct page param',
	str_contains( EEM_Orders_List_Page::create_order_url(), 'page=equine-event-manager-create-order' ),
	$pass, $fail, $log );
ok( 'collect_payment_url returns admin URL with page + order_key params',
	str_contains( EEM_Orders_List_Page::collect_payment_url( 'TEST-KEY-123' ), 'page=equine-event-manager-collect-payment' ) &&
	str_contains( EEM_Orders_List_Page::collect_payment_url( 'TEST-KEY-123' ), 'order_key=TEST-KEY-123' ),
	$pass, $fail, $log );

// ── [9] CLEANUP #25 — .eem-btn-primary Electric Blue ────────────────
echo "\n[9] CLEANUP #25 — Electric Blue primary CTA\n";
ok( '.eem-btn-primary uses --eem-electric (not --eem-navy)',
	(bool) preg_match( '/\.eem-btn-primary,\s*\n?\s*a\.eem-btn-primary\s*\{\s*background:\s*var\(\s*--eem-electric\s*\)/s', $css_src ),
	$pass, $fail, $log );
ok( '.eem-btn-primary:hover uses --eem-electric-hover',
	(bool) preg_match( '/\.eem-btn-primary:hover,\s*\n?\s*a\.eem-btn-primary:hover\s*\{\s*background:\s*var\(\s*--eem-electric-hover\s*\)/s', $css_src ),
	$pass, $fail, $log );

// ── [10] 4 mockup sidebars updated ──────────────────────────────────
echo "\n[10] 4 shipped mockup sidebars updated to post-HANDOFF canonical\n";
foreach ( array( 'settings_page.html', 'reservations_page.html', 'orders_page.html', 'order_detail_page.html' ) as $f ) {
	$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . '.mockups/' . $f );
	ok( "{$f}: sidebar contains 'Stall &amp; RV Charts'",
		str_contains( $src, '>Stall &amp; RV Charts<' ),
		$pass, $fail, $log );
	ok( "{$f}: sidebar no longer contains 'Invoicing' entry",
		false === strpos( $src, '>Invoicing</div>' ),
		$pass, $fail, $log );
}

// ── [11] enqueue gate includes 3 new routes ─────────────────────────
echo "\n[11] enqueue_backend_shell_styles gate covers new routes\n";
ok( "enqueue gate includes 'equine-event-manager-create-order'",
	substr_count( $admin_src, "'equine-event-manager-create-order'" ) >= 2,
	$pass, $fail, $log );
ok( "enqueue gate includes 'equine-event-manager-collect-payment'",
	substr_count( $admin_src, "'equine-event-manager-collect-payment'" ) >= 2,
	$pass, $fail, $log );
ok( "enqueue gate includes 'equine-event-manager-dashboard'",
	substr_count( $admin_src, "'equine-event-manager-dashboard'" ) >= 2,
	$pass, $fail, $log );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
