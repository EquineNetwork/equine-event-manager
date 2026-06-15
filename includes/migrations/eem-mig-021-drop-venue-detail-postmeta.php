<?php
/**
 * Migration 021 — Drop venue detail postmeta rows.
 *
 * Removes the 10 postmeta keys that were migrated into wp_eem_venues detail
 * columns by migration 020. Only deletes rows for posts that have a
 * corresponding canonical venue row (safety net).
 *
 * @package EEM_Plugin
 * @since   2.7.320
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eem_mig_021_drop_venue_detail_postmeta(): void {
	global $wpdb;

	$meta_keys = array(
		'_equine_event_manager_venue_address_1',
		'_equine_event_manager_venue_address_2',
		'_equine_event_manager_venue_city',
		'_equine_event_manager_venue_state',
		'_equine_event_manager_venue_postal_code',
		'_equine_event_manager_venue_phone',
		'_equine_event_manager_venue_website',
		'_en_venue_lat',
		'_en_venue_lng',
		'_en_venue_geocoded_address',
	);

	if ( ! class_exists( 'EEM_Venue' ) ) {
		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-venue.php';
	}

	$venues_table = EEM_Venue::venues_table();
	$source_map   = EEM_Venue::source_map_table();

	$total = 0;
	foreach ( $meta_keys as $key ) {
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE pm FROM {$wpdb->postmeta} pm
			 INNER JOIN {$source_map} sm ON sm.source = 'native' AND sm.source_venue_id = pm.post_id
			 INNER JOIN {$venues_table} v ON v.id = sm.venue_id
			 WHERE pm.meta_key = %s",
			$key
		) ); // phpcs:ignore WordPress.DB
		if ( is_int( $deleted ) ) {
			$total += $deleted;
		}
	}

	update_option( 'eem_mig_021_drop_venue_detail_postmeta_complete', 1 );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( sprintf( '[EEM] Migration 021: deleted %d venue detail postmeta rows.', $total ) );
	}
}
