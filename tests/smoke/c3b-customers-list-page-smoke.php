<?php
/**
 * V1 commit #3.B smoke — Customers list page render + wiring.
 *
 * Run: wp eval-file tests/smoke/c3b-customers-list-page-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

wp_set_current_user( 1 );

$render = function ( array $get ) {
	$saved = $_GET;
	$_GET  = array_merge( array( 'page' => 'equine-event-manager-customers' ), $get );
	ob_start();
	EEM_Customers_List_Page::render();
	$html = ob_get_clean();
	$_GET = $saved;
	return $html;
};

// ── Default render ──
$html = $render( array( 'paged' => '1' ) );
$check( 'render produced output', strlen( $html ) > 2000 );
foreach ( array(
	'eem-customers-table', 'eem-customers-toolbar', 'Customer', 'Email',
	'Total Orders', 'Total Spent', 'Last Activity', 'eem-pagination', 'eem-sort-icon',
) as $m ) {
	$check( "render contains '{$m}'", false !== strpos( $html, $m ) );
}

// Content density: at most PER_PAGE rows, and they carry real names + profile links.
$repo  = new EEM_Customer_Profile_Repo();
$data  = $repo->get_customer_list( array( 'per_page' => 20, 'paged' => 1 ) );
$rows_on_page = substr_count( $html, 'eem-customers-name' );
if ( 0 === (int) $data['total'] ) {
	WP_CLI::log( 'C#3.B SKIP content-density assertions — no customer data; run seed-test-data.php' );
} else {
	$check( 'page shows up to PER_PAGE (20) customer rows', $rows_on_page > 0 && $rows_on_page <= 20 );
	$check( 'rows match repo page-1 count', $rows_on_page === count( $data['rows'] ) );
	$first_name = isset( $data['rows'][0]['name'] ) ? $data['rows'][0]['name'] : '';
	$check( 'first customer name is rendered', '' !== $first_name && false !== strpos( $html, esc_html( $first_name ) ) );
	$check( 'rows link to the profile route (customer_email)', false !== strpos( $html, 'customer_email=' ) );
	$check( 'rows have mailto links', false !== strpos( $html, 'mailto:' ) );
}

// ── Sortable headers ──
foreach ( array( 'orderby=last_name', 'orderby=orders', 'orderby=spent', 'orderby=activity' ) as $sortlink ) {
	$check( "sort link present: {$sortlink}", false !== strpos( $html, $sortlink ) );
}
// Active sort marks the header.
$sorted_html = $render( array( 'orderby' => 'spent', 'order' => 'desc' ) );
$check( 'active sort column gets is-sorted class', false !== strpos( $sorted_html, 'is-sorted' ) );

// ── Pagination ──
if ( (int) $data['pages'] >= 2 ) {
	$check( 'pagination links to page 2', false !== strpos( $html, 'paged=2' ) );
	// Page 2 renders different first row than page 1.
	$p2 = $render( array( 'paged' => '2' ) );
	$check( 'page 2 renders without error', strlen( $p2 ) > 2000 );
}

// ── Search ── (guarded: requires seed customer named Delgado)
$search_html = $render( array( 's' => 'delgado' ) );
$delgado_rows = substr_count( $search_html, 'eem-customers-name' );
if ( false !== stripos( $search_html, 'delgado' ) ) {
	$check( 'search renders matching customer', true );
	if ( $rows_on_page > 0 ) {
		$check( 'search narrows the result set', $delgado_rows < $rows_on_page );
	}
} else {
	WP_CLI::log( '  SKIP delgado search assertions (seed customer not in DB — run seed-test-data.php)' );
}

// ── url() builder ──
$u = EEM_Customers_List_Page::url( array( 'orderby' => 'spent', 'paged' => 3 ) );
$check( 'url() includes page slug', false !== strpos( $u, 'equine-event-manager-customers' ) );
$check( 'url() includes args', false !== strpos( $u, 'orderby=spent' ) && false !== strpos( $u, 'paged=3' ) );

// ── Wiring (source presence) ──
$boot = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );
$adm  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$check( 'bootstrap requires the list page', false !== strpos( $boot, 'class-eem-customers-list-page.php' ) );
$check( 'menu registers EEM_Customers_List_Page', false !== strpos( $adm, "array( 'EEM_Customers_List_Page', 'render' )" ) );
$check( 'submenu order includes the customers slug', false !== strpos( $adm, "'equine-event-manager-customers'" ) );
$check( 'body-class branch for customers list', false !== strpos( $adm, 'eem-shell-page--customers' ) );

WP_CLI::log( "\n=== C#3.B customers-list-page smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C#3.B customers-list-page smoke passed.' );
