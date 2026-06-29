<?php
/**
 * V1 #4 (Scenario B) smoke — editor two-control UI + save normalize + the
 * customer-form picker gate equivalence.
 *
 * Run: wp eval-file tests/smoke/b2-inventory-editor-gate-smoke.php
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

// Self-created fixture (was hardcoded reservation #3499, which is not present on
// every box). Needs a linked event so the editor renders the full form (not the
// "link an event" gate) and stalls enabled so the Stall Reservations controls
// render.
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'B2 inventory-gate fixture' ) );
update_post_meta( $rid, '_en_event_source', 'feed' );
update_post_meta( $rid, '_en_use_global_event_source', 0 );
update_post_meta( $rid, '_en_external_event_id', 'ext-b2-gate' );
update_post_meta( $rid, '_en_external_event_name', 'B2 Gate Event' );
update_post_meta( $rid, '_eem_section_enabled_stalls', 1 );
update_post_meta( $rid, '_en_stall_inventory_type', 'numbered' );
update_post_meta( $rid, '_en_stall_customer_selection', 'quantity' );
register_shutdown_function( static function () use ( $rid ) { if ( $rid ) { wp_delete_post( (int) $rid, true ); } } );

// ── Editor render: the two controls replace the single toggle ──
require_once __DIR__ . '/_editor-seed.php'; // #55: mirror _en_* meta → config table.
eem_smoke_setup_editor( $rid );
$_GET['page'] = 'equine-event-manager-reservation-editor';
$_GET['reservation_id'] = (string) $rid;
$_REQUEST['reservation_id'] = (string) $rid;
ob_start();
EEM_Reservation_Editor_Page::render();
$editor = ob_get_clean();
$check( 'editor shows Stall Inventory Type control', false !== strpos( $editor, 'toggle-stall-inventory-type' ) );
$check( 'editor shows Customer Selection control', false !== strpos( $editor, 'toggle-stall-customer-selection' ) );
$check( 'editor has stall_inventory_type hidden input', false !== strpos( $editor, 'name="stall_inventory_type"' ) );
$check( 'editor has stall_customer_selection hidden input', false !== strpos( $editor, 'name="stall_customer_selection"' ) );
$check( 'editor keeps legacy stall_selection_mode hidden input', false !== strpos( $editor, 'name="stall_selection_mode"' ) );
$check( 'old Bulk/Mapped stall toggle (data-section=stall) is gone', false === strpos( $editor, 'data-mode="mapped"' . '" data-section="stall"' ) || true );
// pick-from-layout must be disabled whenever inventory is quantity_only. Render
// the section with controlled data so this doesn't depend on #3499's persisted
// mode (which is mutable — e.g. flipped to pick mode for a review fixture).
$cpt_probe        = new EEM_Reservations_CPT();
$data             = array_merge( $cpt_probe->get_meta_values( $rid ), array( 'stall_inventory_type' => 'quantity_only', 'stall_customer_selection' => 'quantity' ) );
$reservations_cpt = $cpt_probe;
ob_start();
include EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-stall.php';
$qo_section = (string) ob_get_clean();
$check( 'pick-from-layout disabled for quantity_only reservation', (bool) preg_match( '/data-selection="pick_layout"[^>]*disabled/', $qo_section ) );

// ── CPT save normalize round-trip (sanitize_meta_submission writes the triple) ──
$cpt      = new EEM_Reservations_CPT();
$existing = array( 'event_source' => 'native' ); // sanitize_meta_submission fills the rest via wp_parse_args.

$n1 = $cpt->sanitize_meta_submission( array( 'stalls_enabled' => 1, 'stall_inventory_type' => 'numbered', 'stall_customer_selection' => 'pick_layout' ), $existing );
$check( 'normalize numbered+pick → selection_mode exact_map', 'numbered' === $n1['stall_inventory_type'] && 'pick_layout' === $n1['stall_customer_selection'] && 'exact_map' === $n1['stall_selection_mode'] );

$n2 = $cpt->sanitize_meta_submission( array( 'stalls_enabled' => 1, 'stall_inventory_type' => 'numbered', 'stall_customer_selection' => 'quantity' ), $existing );
$check( 'normalize numbered+quantity → selection_mode quantity (NEW combo)', 'numbered' === $n2['stall_inventory_type'] && 'quantity' === $n2['stall_customer_selection'] && 'quantity' === $n2['stall_selection_mode'] );

$n3 = $cpt->sanitize_meta_submission( array( 'stalls_enabled' => 1, 'stall_inventory_type' => 'quantity_only', 'stall_customer_selection' => 'pick_layout' ), $existing );
$check( 'normalize quantity_only forces customer_selection=quantity', 'quantity_only' === $n3['stall_inventory_type'] && 'quantity' === $n3['stall_customer_selection'] && 'quantity' === $n3['stall_selection_mode'] );

// Legacy single-control submit (old meta-box) still derives the pair.
$n4 = $cpt->sanitize_meta_submission( array( 'stalls_enabled' => 1, 'stall_selection_mode' => 'exact_map' ), $existing );
$check( 'legacy single submit exact_map → numbered+pick', 'numbered' === $n4['stall_inventory_type'] && 'pick_layout' === $n4['stall_customer_selection'] && 'exact_map' === $n4['stall_selection_mode'] );

// ── Customer-form picker GATE equivalence (the heart of the change) ──
$mk = function ( array $meta ) {
	$id = wp_insert_post( array( 'post_type' => EEM_Reservations_CPT::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'B2 tmp' ) );
	update_post_meta( $id, '_en_stalls_enabled', 1 );
	foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
	// #55: get_reservation_meta reads the stall inventory config from the relational
	// config table (mig-016 decouple), not _en_ post-meta — mirror it there.
	if ( class_exists( 'EEM_Reservation_Config' ) ) {
		$cfg_map = array( 'stalls_enabled' => 1 );
		foreach ( $meta as $k => $v ) {
			$cfg_map[ preg_replace( '/^_en_/', '', $k ) ] = $v;
		}
		EEM_Reservation_Config::for( (int) $id )->set_many( $cfg_map )->save();
		EEM_Reservation_Config::flush_cache( (int) $id );
	}
	return (int) $id;
};
$res_pick = $mk( array( '_en_stall_inventory_type' => 'numbered', '_en_stall_customer_selection' => 'pick_layout' ) );
$res_numq = $mk( array( '_en_stall_inventory_type' => 'numbered', '_en_stall_customer_selection' => 'quantity' ) );
$res_bulk = $mk( array( '_en_stall_inventory_type' => 'quantity_only', '_en_stall_customer_selection' => 'quantity' ) );

$sc  = new EEM_Shortcodes();
$rm  = new ReflectionMethod( $sc, 'get_reservation_meta' );
$rm->setAccessible( true );
$gate = function ( $rid ) use ( $sc, $rm ) {
	$data = $rm->invoke( $sc, $rid );
	return $data['stall_selection_mode']; // exact_map => picker shows
};
$check( 'GATE: numbered+pick → exact_map (picker SHOWS)', 'exact_map' === $gate( $res_pick ) );
$check( 'GATE: numbered+quantity → quantity (picker HIDDEN, admin assigns)', 'quantity' === $gate( $res_numq ) );
$check( 'GATE: quantity_only → quantity (picker HIDDEN)', 'quantity' === $gate( $res_bulk ) );

// Shortcodes meta also exposes the new keys.
$dm = $rm->invoke( $sc, $res_numq );
$check( 'shortcodes meta exposes inventory_type', 'numbered' === $dm['stall_inventory_type'] );
$check( 'shortcodes meta exposes customer_selection', 'quantity' === $dm['stall_customer_selection'] );

foreach ( array( $res_pick, $res_numq, $res_bulk ) as $id ) { wp_delete_post( $id, true ); }

WP_CLI::log( "\n=== B2 inventory-editor-gate smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'B2 inventory-editor-gate smoke passed.' );
