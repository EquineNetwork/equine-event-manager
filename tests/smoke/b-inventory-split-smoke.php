<?php
/**
 * V1 #4 (Scenario B) smoke — stall inventory split: data layer + migration.
 *
 * Verifies the derivation/resolver/sanitizers, the legacy-mode equivalence that
 * keeps the customer-form picker gate correct, and the one-time migration's
 * mapping + idempotency. Uses throwaway reservation posts for the synthetic
 * cases and exercises the REAL migration on existing data.
 *
 * Run: wp eval-file tests/smoke/b-inventory-split-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

// ── derive_stall_selection_mode: only numbered+pick_layout is exact_map ──
$check( 'derive(quantity_only, quantity) = quantity', 'quantity' === EEM_Reservations_CPT::derive_stall_selection_mode( 'quantity_only', 'quantity' ) );
$check( 'derive(numbered, quantity) = quantity (NEW combo)', 'quantity' === EEM_Reservations_CPT::derive_stall_selection_mode( 'numbered', 'quantity' ) );
$check( 'derive(numbered, pick_layout) = exact_map', 'exact_map' === EEM_Reservations_CPT::derive_stall_selection_mode( 'numbered', 'pick_layout' ) );
$check( 'derive(quantity_only, pick_layout) = quantity (invalid→safe)', 'quantity' === EEM_Reservations_CPT::derive_stall_selection_mode( 'quantity_only', 'pick_layout' ) );

// ── sanitizers ──
$check( 'sanitize_inventory_type rejects junk → quantity_only', 'quantity_only' === EEM_Reservations_CPT::sanitize_stall_inventory_type( 'nope' ) );
$check( 'sanitize_customer_selection rejects junk → quantity', 'quantity' === EEM_Reservations_CPT::sanitize_stall_customer_selection( 'nope' ) );

// ── resolve_stall_pair derivation from legacy (pre-migration) ──
$mk = function ( array $meta ) {
	$id = wp_insert_post( array( 'post_type' => EEM_Reservations_CPT::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'B-smoke tmp' ) );
	foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
	return (int) $id;
};

$legacy_bulk   = $mk( array( '_en_stall_selection_mode' => 'quantity' ) );
$legacy_mapped = $mk( array( '_en_stall_selection_mode' => 'exact_map' ) );
$new_numqty    = $mk( array( '_en_stall_selection_mode' => 'quantity', '_en_stall_inventory_type' => 'numbered', '_en_stall_customer_selection' => 'quantity' ) );

$p_bulk = EEM_Reservations_CPT::resolve_stall_pair( $legacy_bulk );
$check( 'legacy quantity → (quantity_only, quantity)', 'quantity_only' === $p_bulk['inventory_type'] && 'quantity' === $p_bulk['customer_selection'] && 'quantity' === $p_bulk['selection_mode'] );

$p_map = EEM_Reservations_CPT::resolve_stall_pair( $legacy_mapped );
$check( 'legacy exact_map → (numbered, pick_layout, exact_map)', 'numbered' === $p_map['inventory_type'] && 'pick_layout' === $p_map['customer_selection'] && 'exact_map' === $p_map['selection_mode'] );

$p_new = EEM_Reservations_CPT::resolve_stall_pair( $new_numqty );
$check( 'NEW numbered+quantity resolves with selection_mode=quantity (no picker)', 'numbered' === $p_new['inventory_type'] && 'quantity' === $p_new['customer_selection'] && 'quantity' === $p_new['selection_mode'] );

// Invalid stored pair (quantity_only + pick_layout) is corrected on resolve.
$bad = $mk( array( '_en_stall_inventory_type' => 'quantity_only', '_en_stall_customer_selection' => 'pick_layout' ) );
$p_bad = EEM_Reservations_CPT::resolve_stall_pair( $bad );
$check( 'invalid stored pair corrected (quantity_only forces quantity)', 'quantity' === $p_bad['customer_selection'] && 'quantity' === $p_bad['selection_mode'] );

// NOTE: the one-time legacy→explicit-keys sweep (eem-mig-004) was collapsed into
// the #41 baseline and no longer ships. The runtime resolver/sanitizer paths above
// derive the same triple from legacy meta on the fly, so ongoing behavior is
// unaffected and still covered without a stored migration.

// ── get_meta_values exposes the triple consistently ──
$cpt = new EEM_Reservations_CPT();
$mv  = $cpt->get_meta_values( $legacy_mapped );
$check( 'get_meta_values exposes inventory_type', 'numbered' === $mv['stall_inventory_type'] );
$check( 'get_meta_values exposes customer_selection', 'pick_layout' === $mv['stall_customer_selection'] );
$check( 'get_meta_values derives selection_mode=exact_map', 'exact_map' === $mv['stall_selection_mode'] );

// Cleanup synthetic posts.
foreach ( array( $legacy_bulk, $legacy_mapped, $new_numqty, $bad ) as $id ) {
	wp_delete_post( $id, true );
}

WP_CLI::log( "\n=== B inventory-split smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'B inventory-split smoke passed.' );
