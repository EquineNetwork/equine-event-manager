<?php
/**
 * eem-mig-015 — unify existing native `en_venue` posts with canonical EEM_Venue.
 *
 * The native venue ↔ canonical `EEM_Venue` link (durable back-reference post-meta
 * `_eem_canonical_venue_id` + name sync) is written on every venue save going
 * forward. This one-time pass backfills the link for every `en_venue` that
 * already exists, so legacy venues are unified without needing a re-save. Each
 * call to EEM_Venue::sync_native_venue() is idempotent (resolve is keyed on the
 * post id), so re-running is harmless.
 *
 * Option-guarded in run_one_time_migrations(); only runs when EEM_Venue exists.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill the canonical-venue link for all existing native venues.
 *
 * @return void
 */
function eem_mig_015_native_venue_unify() {
	if ( ! class_exists( 'EEM_Venue' ) || ! post_type_exists( 'en_venue' ) ) {
		// Native events not registered this load — leave the guard unset so the
		// backfill retries on a later activation when en_venue exists.
		return;
	}

	EEM_Venue::create_tables();

	$venue_ids = get_posts(
		array(
			'post_type'      => 'en_venue',
			'post_status'    => 'any',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'suppress_filters' => true,
		)
	);

	foreach ( (array) $venue_ids as $venue_id ) {
		EEM_Venue::sync_native_venue( (int) $venue_id );
	}

	update_option( 'eem_mig_015_native_venue_unify_complete', 1 );
}
