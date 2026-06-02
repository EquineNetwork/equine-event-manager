<?php
/**
 * Migration #004 — Split the single stall "selection mode" into two settings.
 *
 * Scenario B (V1 #4): the old single `_en_stall_selection_mode` ∈ {quantity,
 * exact_map} becomes two independent settings:
 *   - `_en_stall_inventory_type`     ∈ {quantity_only, numbered}
 *   - `_en_stall_customer_selection` ∈ {quantity, pick_layout}
 *
 * This one-time sweep backfills the two new keys for every existing reservation:
 *   quantity   → (quantity_only, quantity)
 *   exact_map  → (numbered,      pick_layout)
 *
 * The legacy `_en_stall_selection_mode` key is PRESERVED (the resolver still
 * reads it as a fallback). The migration is purely additive — it never deletes
 * or overwrites the legacy key. It is also a backstop, not a hard dependency:
 * `EEM_Reservations_CPT::resolve_stall_pair()` derives the same pair on the fly
 * for any reservation the sweep hasn't touched yet, so reads are always correct.
 *
 * Flag-gated (runs once) AND row-level idempotent: a reservation that already
 * has both new keys is skipped, so re-running changes nothing.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill the stall inventory-type / customer-selection pair from the legacy
 * single mode.
 *
 * @return array{migrated:int, skipped:int, source:string}
 */
function eem_mig_004_stall_inventory_split() {
	$flag_key = 'eem_mig_004_stall_inventory_split_complete';
	if ( get_option( $flag_key ) ) {
		return array( 'migrated' => 0, 'skipped' => 0, 'source' => 'already-complete' );
	}

	if ( ! class_exists( 'EEM_Reservations_CPT' ) ) {
		// Dependency not loaded yet — defer; re-runs on next admin load.
		return array( 'migrated' => 0, 'skipped' => 0, 'source' => 'deps-missing' );
	}

	$reservation_ids = get_posts( array(
		'post_type'        => EEM_Reservations_CPT::POST_TYPE,
		'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page'   => -1,
		'fields'           => 'ids',
		'suppress_filters' => true,
	) );

	$migrated = 0;
	$skipped  = 0;

	foreach ( (array) $reservation_ids as $reservation_id ) {
		$reservation_id = (int) $reservation_id;

		// Row-level idempotency: already split → leave untouched.
		if ( metadata_exists( 'post', $reservation_id, '_en_stall_inventory_type' )
			&& metadata_exists( 'post', $reservation_id, '_en_stall_customer_selection' ) ) {
			$skipped++;
			continue;
		}

		$legacy = get_post_meta( $reservation_id, '_en_stall_selection_mode', true );
		$legacy = in_array( $legacy, array( 'quantity', 'exact_map' ), true ) ? $legacy : 'quantity';

		$type = ( 'exact_map' === $legacy ) ? 'numbered' : 'quantity_only';
		$sel  = ( 'exact_map' === $legacy ) ? 'pick_layout' : 'quantity';

		update_post_meta( $reservation_id, '_en_stall_inventory_type', $type );
		update_post_meta( $reservation_id, '_en_stall_customer_selection', $sel );
		$migrated++;
	}

	update_option( $flag_key, time() );

	return array(
		'migrated' => $migrated,
		'skipped'  => $skipped,
		'source'   => 'sweep',
	);
}
