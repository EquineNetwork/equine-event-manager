<?php
/**
 * Migration #012 — delete the legacy `_eem_frontend_url_cache` post-meta so
 * pre-existing reservations get the readable virtual event URL (v1 #5).
 *
 * Background: `EEM_Reservations_List_Page::resolve_frontend_url()` used to check
 * `_eem_frontend_url_cache` FIRST and only fall through to the readable virtual
 * route (`/equine-event/{slug}-{id}/`) when no cache existed. Reservations created
 * before the readable-URL system shipped carried a cached value pointing at the
 * OLD `/event/{slug}/` form, so their "View on frontend" links never upgraded to
 * the readable route.
 *
 * The resolver now resolves linked reservations to the readable route up front and
 * ignores the cache — so this migration is belt-and-suspenders cleanup: it removes
 * the now-dead `_eem_frontend_url_cache` meta on every reservation (the resolver
 * recomputes the unlinked content-scan fallback lazily, so nothing breaks).
 *
 * Idempotent / flag-gated. Bounded: one delete per reservation that has the meta.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delete the legacy frontend-URL cache meta from every reservation.
 *
 * @return array{deleted:int} Count of meta rows removed (for telemetry/verification).
 */
function eem_mig_012_frontend_url_cache_cleanup() {
	global $wpdb;

	$flag = 'eem_mig_012_frontend_url_cache_cleanup_complete';
	if ( get_option( $flag ) ) {
		return array( 'deleted' => 0 );
	}

	// delete_metadata( ..., $delete_all = true ) removes the key from every post
	// in one call and clears the meta cache. Bounded by the (small) number of
	// reservations that ever had the legacy cache written.
	$before  = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_eem_frontend_url_cache' )
	);
	delete_metadata( 'post', 0, '_eem_frontend_url_cache', '', true );

	update_option( $flag, time() );
	return array( 'deleted' => $before );
}
