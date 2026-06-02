<?php
/**
 * C9.B smoke — Customer Profile page render + wiring.
 *
 * Run: wp eval-file tests/smoke/c9b-customer-profile-page-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

// Pick the busiest customer email.
$orders_repo = new EEM_Orders_Repository();
$counts = array();
foreach ( $orders_repo->get_orders( '', 'date', 'desc' ) as $o ) {
	$e = strtolower( trim( (string) ( $o['email'] ?? '' ) ) );
	if ( '' !== $e ) { $counts[ $e ] = ( $counts[ $e ] ?? 0 ) + 1; }
}
arsort( $counts );
$email = (string) array_key_first( $counts );

wp_set_current_user( 1 );

// ── Render ──
$_GET['customer_email'] = $email;
$_GET['page']           = 'equine-event-manager-customer';
ob_start();
EEM_Customer_Profile_Page::render();
$html = ob_get_clean();

$check( 'render produced output', strlen( $html ) > 1000 );
foreach ( array(
	'eem-cp-stats-grid', 'eem-cp-stat-card--electric', 'eem-cp-stat-card--teal',
	'eem-cp-details-grid', 'eem-cp-notes', 'eem-cp-table-section',
	'Reservation History', 'Activity Log', 'eem-status-badge', 'eem-type-badge',
) as $m ) {
	$check( "render contains '{$m}'", false !== strpos( $html, $m ) );
}
$check( 'render has Send Email mailto', false !== strpos( $html, 'mailto:' . $email ) || false !== strpos( $html, 'mailto:' . rawurlencode( $email ) ) );
$check( 'render has Export CSV action', false !== strpos( $html, 'eem_export_customer_csv' ) );
$check( 'render has save-customer-note button', false !== strpos( $html, 'data-eem-action="save-customer-note"' ) );
$check( 'render has customer-note host w/ email', false !== strpos( $html, 'data-eem-email="' . esc_attr( $email ) . '"' ) );
$check( 'render carries a note nonce', false !== strpos( $html, 'data-eem-nonce="' ) );
$check( 'no leftover stub placeholder text', false === strpos( $html, 'planned roadmap' ) );

// Content-density: the stat value carries the real lifetime spend ($...).
$repo    = new EEM_Customer_Profile_Repo();
$profile = $repo->get_profile( $email );
$check( 'render shows real lifetime spend value', false !== strpos( $html, '>' . $profile['stats']['lifetime_spend'] . '<' ) );

// ── Unknown customer → graceful not-found (no fatal) ──
$_GET['customer_email'] = 'ghost-' . md5( $email ) . '@nowhere.test';
ob_start();
EEM_Customer_Profile_Page::render();
$missing = ob_get_clean();
$check( 'unknown customer renders not-found card', false !== strpos( $missing, 'no orders on record' ) );

// ── Note AJAX round-trip via the handler (Reflection-free; call save_note) ──
$original = $repo->get_note( $email );
$probe    = 'C9.B smoke ' . wp_generate_password( 6, false );
$repo->save_note( $email, $probe );
$check( 'note persists + reads back', $repo->get_note( $email ) === $probe );
$repo->save_note( $email, $original );

// ── Export CSV dataset shape (build via exporter, same path the handler uses) ──
$exporter = new EEM_Report_Exporter();
$rows = array();
foreach ( $profile['orders'] as $o ) {
	$rows[] = array( $o['order_number'], $o['event_name'], implode( ' / ', array_values( $o['type_labels'] ) ), $o['status_label'], $o['date'], $o['total'] );
}
$csv = $exporter->build_csv( array( 'headers' => array( 'Order #', 'Event', 'Type', 'Status', 'Date', 'Total' ), 'rows' => $rows ) );
$check( 'CSV export is BOM-prefixed', "\xEF\xBB\xBF" === substr( $csv, 0, 3 ) );
$check( 'CSV has a header row', false !== strpos( $csv, 'Order #' ) );

// ── Wiring: enqueue allowlist + body-class + AJAX hook registered ──
$admin_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$check( 'enqueue allowlist includes customer slug', false !== strpos( $admin_src, "'equine-event-manager-customer'" ) );
$check( 'body-class branch includes customer variant', false !== strpos( $admin_src, 'eem-shell-page--customer' ) );
$check( 'ajax_save_customer_note hook is registered', has_action( 'wp_ajax_eem_save_customer_note' ) !== false );
$check( 'export_customer_csv hook is registered', has_action( 'admin_post_eem_export_customer_csv' ) !== false );

// ── Stub callback now points at the real page ──
$list_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-orders-list-page.php' );
$check( 'stub registration delegates to EEM_Customer_Profile_Page', false !== strpos( $list_src, "'EEM_Customer_Profile_Page', 'render'" ) );

WP_CLI::log( "\n=== C9.B customer-profile-page smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C9.B customer-profile-page smoke passed.' );
