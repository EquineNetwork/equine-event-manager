<?php
/**
 * Migration 024: Backfill wp_eem_native_events from en_event postmeta.
 *
 * @package EEM_Plugin
 * @since   2.7.322
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eem_mig_024_native_event_backfill(): void {
	global $wpdb;

	if ( ! class_exists( 'EEM_Native_Event_Repo' ) ) {
		return;
	}

	EEM_Native_Event_Repo::create_table();

	$post_ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'en_event' AND post_status IN ('publish','draft','trash','private','future')"
	); // phpcs:ignore WordPress.DB

	foreach ( $post_ids as $pid ) {
		$pid = (int) $pid;

		EEM_Native_Event_Repo::save( $pid, array(
			'start_date'      => (string) get_post_meta( $pid, '_equine_event_manager_event_start_date', true ),
			'end_date'        => (string) get_post_meta( $pid, '_equine_event_manager_event_end_date', true ),
			'venue_id'        => absint( get_post_meta( $pid, '_equine_event_manager_event_venue_id', true ) ),
			'producer_id'     => absint( get_post_meta( $pid, '_equine_event_manager_event_producer_id', true ) ),
			'location_label'  => (string) get_post_meta( $pid, '_equine_event_manager_event_location_label', true ),
			'cta_label'       => (string) get_post_meta( $pid, '_equine_event_manager_event_cta_label', true ),
			'flyer_file_id'   => absint( get_post_meta( $pid, '_equine_event_manager_event_flyer_file_id', true ) ),
			'flyer_url'       => (string) get_post_meta( $pid, '_equine_event_manager_event_flyer_url', true ),
			'featured'        => absint( get_post_meta( $pid, '_equine_event_manager_event_featured', true ) ),
			'facebook'        => (string) get_post_meta( $pid, '_en_event_facebook', true ),
			'instagram'       => (string) get_post_meta( $pid, '_en_event_instagram', true ),
			'details_summary' => (string) get_post_meta( $pid, '_en_event_details_summary', true ),
		) );
	}

	update_option( 'eem_mig_024_native_event_backfill_complete', 1 );
}
