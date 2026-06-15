<?php
/**
 * Migration 020 — Backfill venue detail columns from postmeta.
 *
 * Reads all en_venue posts, resolves their canonical venue_id, and copies
 * address/geo/contact postmeta into the new detail columns on wp_eem_venues.
 *
 * @package EEM_Plugin
 * @since   2.7.320
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eem_mig_020_venue_detail_backfill(): void {
	global $wpdb;

	if ( ! class_exists( 'EEM_Venue' ) ) {
		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-venue.php';
	}

	EEM_Venue::create_tables();

	$venue_ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'en_venue' AND post_status IN ('publish','draft','pending','private','trash')"
	);

	$meta_map = array(
		'address_1'   => '_equine_event_manager_venue_address_1',
		'address_2'   => '_equine_event_manager_venue_address_2',
		'city'        => '_equine_event_manager_venue_city',
		'state'       => '_equine_event_manager_venue_state',
		'postal_code' => '_equine_event_manager_venue_postal_code',
		'phone'       => '_equine_event_manager_venue_phone',
		'website'     => '_equine_event_manager_venue_website',
	);

	$count = 0;
	foreach ( $venue_ids as $post_id ) {
		$post_id  = (int) $post_id;
		$venue_id = EEM_Venue::venue_id_for_post( $post_id );

		if ( $venue_id <= 0 ) {
			$venue_id = EEM_Venue::sync_native_venue( $post_id );
		}
		if ( $venue_id <= 0 ) {
			continue;
		}

		$data = array();
		foreach ( $meta_map as $col => $meta_key ) {
			$data[ $col ] = (string) get_post_meta( $post_id, $meta_key, true );
		}
		$data['lat']              = (string) get_post_meta( $post_id, '_en_venue_lat', true );
		$data['lng']              = (string) get_post_meta( $post_id, '_en_venue_lng', true );
		$data['geocoded_address'] = (string) get_post_meta( $post_id, '_en_venue_geocoded_address', true );

		if ( '' !== $data['lat'] && is_numeric( $data['lat'] ) ) {
			$data['lat'] = (float) $data['lat'];
		} else {
			$data['lat'] = null;
		}
		if ( '' !== $data['lng'] && is_numeric( $data['lng'] ) ) {
			$data['lng'] = (float) $data['lng'];
		} else {
			$data['lng'] = null;
		}

		EEM_Venue::save_detail( $venue_id, $data );
		++$count;
	}

	update_option( 'eem_mig_020_venue_detail_backfill_complete', 1 );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( sprintf( '[EEM] Migration 020: backfilled %d venue detail records into wp_eem_venues.', $count ) );
	}
}
