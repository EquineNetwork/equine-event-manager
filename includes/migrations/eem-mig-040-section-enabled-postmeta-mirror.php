<?php
/**
 * Migration #040 — mirror section-enabled flags from the config table to post meta.
 *
 * The reservation config TABLE (`wp_eem_reservation_config`) holds the truth for
 * each section toggle (stalls_enabled, rv_enabled, …). But many gates across the
 * plugin read EEM_Reservations_CPT::section_enabled(), which only inspects POST
 * META. Imported reservations (and any created purely via the config table) never
 * had that post meta written, so those gates saw the sections as "off" — most
 * visibly the "Assign Stalls / Assign RV" buttons on the Order Detail page were
 * hidden, and editor sections collapsed, until the admin opened + re-saved the
 * reservation editor.
 *
 * This backfills the canonical `_eem_section_enabled_<slug>` post meta from the
 * config column for every reservation that is missing it. The import write-path
 * now mirrors these at import time (see EEM_Import_Handler), so this migration is
 * the one-time repair for reservations imported before that fix.
 *
 * Idempotent / flag-gated. Queries the config table columns directly (no config
 * object hydration) to stay memory-safe on large seeded sites. Only writes a meta
 * key when NEITHER the canonical nor the legacy key already exists, so a properly
 * saved reservation is never overwritten.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill section-enabled post meta from the config table.
 *
 * @return array{updated:int} Count of post-meta keys written.
 */
function eem_mig_040_section_enabled_postmeta_mirror() {
	global $wpdb;

	$flag = 'eem_mig_040_section_enabled_postmeta_mirror_complete';
	if ( get_option( $flag ) ) {
		return array( 'updated' => 0 );
	}

	if ( ! class_exists( 'EEM_Reservations_CPT' ) ) {
		update_option( $flag, time() );
		return array( 'updated' => 0 );
	}

	$table = $wpdb->prefix . 'eem_reservation_config';
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		update_option( $flag, time() );
		return array( 'updated' => 0 );
	}

	$map     = EEM_Reservations_CPT::SECTION_ENABLED_MAP; // field => slug
	$fields  = array_keys( $map );
	$columns = implode( ', ', array_map( static function ( $f ) {
		return '`' . preg_replace( '/[^a-z0-9_]/', '', $f ) . '`';
	}, $fields ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column + table names are derived from a fixed allow-list.
	$rows = $wpdb->get_results( "SELECT reservation_id, {$columns} FROM {$table}", ARRAY_A );
	$updated = 0;

	foreach ( (array) $rows as $row ) {
		$rid = (int) $row['reservation_id'];
		if ( $rid <= 0 ) {
			continue;
		}

		foreach ( $map as $field => $slug ) {
			if ( ! array_key_exists( $field, $row ) ) {
				continue;
			}
			// Skip if the reservation already carries either the canonical or the
			// legacy toggle key — never clobber an admin-saved value.
			if ( EEM_Reservations_CPT::section_enabled_exists( $rid, $field ) ) {
				continue;
			}
			update_post_meta(
				$rid,
				EEM_Reservations_CPT::section_enabled_meta_key( $field ),
				! empty( $row[ $field ] ) ? 1 : 0
			);
			$updated++;
		}
	}

	update_option( $flag, time() );
	return array( 'updated' => $updated );
}
