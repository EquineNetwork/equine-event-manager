<?php
/**
 * Seed one fully-configured reservation per event source (TEC / GEMS / Native)
 * so all three checkout paths have realistic data for testing.
 *
 * - TEC:    reservation 5990 (2026 Southeast Region Super Sort) — already complete.
 * - GEMS:   reservation 6519 (NTR- Rapid City, SD) — copy the Southeast RV map in
 *           so it has both a stall AND an RV map.
 * - Native: create a NEW reservation linked to native event 13644
 *           (Summer Sizzler Barrel Bash 2026) and clone 5990's full config
 *           (stall + RV maps, rows, zones, pricing) into it.
 *
 * Idempotent: the native reservation is keyed by a marker meta value; re-running
 * reuses the same post instead of creating duplicates. Orders / draw sheets are
 * seeded by the dedicated seed scripts (run those after this).
 *
 * RUN: wp eval-file tools/seed-three-sources.php
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'EEM_Reservation_Config' ) ) {
	fwrite( STDERR, "EEM_Reservation_Config not loaded.\n" );
	return;
}

$donor_id        = 5990;   // TEC Southeast — donor for the full map config.
$gems_id         = 6519;   // GEMS NTR — needs the RV map copied in.
$native_event_id = 13644;  // Native event "Summer Sizzler Barrel Bash 2026".
$marker_key      = '_eem_seed_three_sources_native';

echo "== Seed three sources ==\n";

// ---- 1. Clone donor config (drop identity + source-specific fields) ----------
$donor = EEM_Reservation_Config::for( $donor_id )->all();
unset( $donor['reservation_id'], $donor['updated_at'] );

// ---- 2. Find or create the native reservation --------------------------------
$existing = get_posts(
	array(
		'post_type'   => 'en_reservation',
		'post_status' => array( 'publish', 'draft' ),
		'meta_key'    => $marker_key,
		'meta_value'  => '1',
		'fields'      => 'ids',
		'numberposts' => 1,
	)
);

$event_title = get_the_title( $native_event_id );
$native_id   = $existing ? (int) $existing[0] : 0;

if ( ! $native_id ) {
	$native_id = (int) wp_insert_post(
		array(
			'post_type'   => 'en_reservation',
			'post_status' => 'publish',
			'post_title'  => $event_title ? $event_title : 'Native Event Reservation',
		)
	);
	if ( ! $native_id || is_wp_error( $native_id ) ) {
		fwrite( STDERR, "Failed to create native reservation.\n" );
		return;
	}
	update_post_meta( $native_id, $marker_key, '1' );
	echo "Created native reservation #{$native_id} ({$event_title}).\n";
} else {
	wp_update_post( array( 'ID' => $native_id, 'post_title' => $event_title ? $event_title : 'Native Event Reservation' ) );
	echo "Reusing native reservation #{$native_id}.\n";
}

// ---- 3. Write the cloned config + native source linkage ----------------------
$native_cfg = $donor;
$native_cfg['event_source']             = 'native';
$native_cfg['use_global_event_source']  = 0;
$native_cfg['event_id']                 = $native_event_id;
$native_cfg['external_event_id']        = '';
$native_cfg['external_event_name']      = '';
$native_cfg['event_feed_url']           = '';

EEM_Reservation_Config::for( $native_id )->set_many( $native_cfg )->save();
EEM_Reservation_Config::flush_cache( $native_id );

// Mirror the canonical source keys to post-meta so legacy readers agree.
update_post_meta( $native_id, '_en_event_source', 'native' );
update_post_meta( $native_id, '_en_event_id', $native_event_id );
update_post_meta( $native_id, '_en_use_global_event_source', '0' );

echo "Native reservation config cloned from donor #{$donor_id}, linked to event #{$native_event_id}.\n";

// ---- 4. Copy the RV map from donor into the GEMS reservation ------------------
$rv_keys = array( 'rv_map', 'rv_rows', 'rv_zones', 'rv_lots', 'blocked_rv_lots', 'stall_chart_rv_blocks', 'stall_chart_blocked_rv_units', 'rv_enabled', 'rv_selection_mode', 'rv_inventory_type', 'rv_inventory', 'rv_lot_selection_enabled' );
$gems_patch = array();
foreach ( $rv_keys as $k ) {
	if ( array_key_exists( $k, $donor ) ) {
		$gems_patch[ $k ] = $donor[ $k ];
	}
}
if ( $gems_patch ) {
	EEM_Reservation_Config::for( $gems_id )->set_many( $gems_patch )->save();
	EEM_Reservation_Config::flush_cache( $gems_id );
	echo "Copied RV map (" . count( $gems_patch ) . " fields) from donor #{$donor_id} into GEMS reservation #{$gems_id}.\n";
}

echo "Done. Reservations: TEC #{$donor_id}, GEMS #{$gems_id}, Native #{$native_id} (event #{$native_event_id}).\n";
echo "Next: run tools/seed-test-data.php (orders) and tools/seed-sheets-results-demo.php (draw sheets).\n";
