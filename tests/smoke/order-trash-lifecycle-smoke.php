<?php
/**
 * Orders soft-delete / Trash lifecycle smoke (v1 #9 — data layer).
 *
 * Orders gain a `trashed_at` soft-delete column on both component tables.
 * trash_order() stamps it on every component; restore_order() clears it;
 * delete_order() hard-deletes (and can find trashed orders). get_orders()
 * excludes trashed by default, returns only trashed with 'only', and both with
 * 'all' — so the existing list / counts / reports hide trashed orders.
 *
 * Run: wp eval-file tests/smoke/order-trash-lifecycle-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

global $wpdb;

// --- schema: trashed_at column on both order tables ------------------------
foreach ( array( 'en_stall_reservations', 'en_rv_reservations' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	$has   = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'trashed_at'",
		$table
	) );
	$check( "{$suffix} has the trashed_at column", 1 === $has );
}

// --- migration is idempotent / flag-gated ----------------------------------
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-013-order-trashed-at-column.php';
delete_option( 'eem_mig_013_order_trashed_at_column_complete' );
$mig = eem_mig_013_order_trashed_at_column();
$check( 'migration runs (column already present → adds 0)', isset( $mig['added'] ) && 0 === $mig['added'] );
$check( 'migration sets its completion flag', (bool) get_option( 'eem_mig_013_order_trashed_at_column_complete' ) );

// --- repo methods exist ----------------------------------------------------
$repo = new EEM_Orders_Repository();
$check( 'trash_order + restore_order defined', method_exists( $repo, 'trash_order' ) && method_exists( $repo, 'restore_order' ) );

// --- trash / restore round-trip against a real order -----------------------
$all = $repo->get_orders( '', 'date', 'desc', '', 'all' );
if ( ! $all ) {
	echo "  ..  - no orders in fixtures; lifecycle round-trip skipped\n";
} else {
	$key       = $all[0]['order_key'];
	$base_count = count( $repo->get_orders() ); // default = exclude trashed.

	$repo->trash_order( $key );
	$after_default = $repo->get_orders();
	$after_only    = $repo->get_orders( '', 'date', 'desc', '', 'only' );
	$check( 'trashed order drops out of the default list', count( $after_default ) === $base_count - 1 );
	$check( 'trashed order appears under "only" trashed', 1 === count( array_filter( $after_only, static function ( $o ) use ( $key ) { return $o['order_key'] === $key; } ) ) );
	$only_match = array_values( array_filter( $after_only, static function ( $o ) use ( $key ) { return $o['order_key'] === $key; } ) );
	$check( 'trashed order carries trashed=true + a trashed_at', ! empty( $only_match ) && ! empty( $only_match[0]['trashed'] ) && ! empty( $only_match[0]['trashed_at'] ) );
	$check( '"all" includes the trashed order', 1 === count( array_filter( $repo->get_orders( '', 'date', 'desc', '', 'all' ), static function ( $o ) use ( $key ) { return $o['order_key'] === $key; } ) ) );

	// --- list-page UI: Trash tab + counts + trashed-row actions ------------
	if ( $a = get_users( array( 'role' => 'administrator', 'number' => 1 ) ) ) { wp_set_current_user( $a[0]->ID ); }
	$counts = EEM_Orders_List_Repo::counts_by_billing_status();
	$check( 'counts_by_billing_status includes a trash count >= 1', isset( $counts['trash'] ) && $counts['trash'] >= 1 );
	$check( 'billing_tabs includes the Trash tab', array_key_exists( 'trash', EEM_Orders_List_Repo::billing_tabs() ) );

	$tab_page = EEM_Orders_List_Repo::get_paginated( array( 'billing_status' => 'trash', 'per_page' => 50 ) );
	$check( 'Trash tab paginated list contains the trashed order', 1 === count( array_filter( $tab_page['items'], static function ( $o ) use ( $key ) { return $o['order_key'] === $key; } ) ) );

	$_GET = array( 'page' => 'equine-event-manager-orders', 'billing' => 'trash' );
	$lp = new EEM_Orders_List_Page();
	ob_start(); $lp->render(); $html = (string) ob_get_clean();
	$_GET = array();
	$check( 'trashed row renders the Restore action', false !== strpos( $html, 'data-eem-action="order-restore"' ) );
	$check( 'trashed row renders Delete Permanently', false !== strpos( $html, 'data-eem-action="order-delete-permanently"' ) );
	$check( 'trashed row does NOT render Move to Trash', false === strpos( $html, 'data-eem-action="order-trash"' ) );

	$repo->restore_order( $key );
	$check( 'restored order returns to the default list', count( $repo->get_orders() ) === $base_count );
	$restored = $repo->get_order( $key );
	$check( 'restored order has trashed=false', is_array( $restored ) && empty( $restored['trashed'] ) );
}

// --- handlers + wiring source presence -------------------------------------
$lp_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-orders-list-page.php' );
$loader = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );
$check( 'handle_trash now trashes (no deferred stub)', false !== strpos( $lp_src, 'trash_order( (string) $order' ) && false === strpos( $lp_src, "redirect_with_notice( 'order_trash_deferred' )" ) );
$check( 'restore + delete-permanently handlers defined', method_exists( 'EEM_Orders_List_Page', 'handle_restore' ) && method_exists( 'EEM_Orders_List_Page', 'handle_delete_permanently' ) );
$check( 'admin_post actions registered', false !== strpos( $loader, 'admin_post_eem_order_restore' ) && false !== strpos( $loader, 'admin_post_eem_order_delete_permanently' ) );

// --- registered in the activator -------------------------------------------
$act = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-activator.php' );
$check( 'eem-mig-013 registered in the activator', false !== strpos( $act, 'eem_mig_013_order_trashed_at_column' ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
