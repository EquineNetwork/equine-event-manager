<?php
/**
 * Migration 025: Drop native event postmeta rows after backfill.
 *
 * Only deletes postmeta for en_event posts that have a row in the native
 * events table. Does NOT touch TEC event posts that may also carry
 * flyer_file_id / flyer_url / featured meta keys.
 *
 * @package EEM_Plugin
 * @since   2.7.322
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eem_mig_025_drop_native_event_postmeta(): void {
	global $wpdb;

	$ne_table = $wpdb->prefix . 'eem_native_events';

	$keys = array(
		'_equine_event_manager_event_start_date',
		'_equine_event_manager_event_end_date',
		'_equine_event_manager_event_venue_id',
		'_equine_event_manager_event_producer_id',
		'_equine_event_manager_event_location_label',
		'_equine_event_manager_event_cta_label',
		'_equine_event_manager_event_flyer_file_id',
		'_equine_event_manager_event_flyer_url',
		'_equine_event_manager_event_featured',
		'_en_event_facebook',
		'_en_event_instagram',
		'_en_event_details_summary',
	);

	$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

	// phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare(
		"DELETE pm FROM {$wpdb->postmeta} pm
		 INNER JOIN {$ne_table} ne ON ne.event_id = pm.post_id
		 WHERE pm.meta_key IN ({$placeholders})",
		...$keys
	) );

	update_option( 'eem_mig_025_drop_native_event_postmeta_complete', 1 );
}
