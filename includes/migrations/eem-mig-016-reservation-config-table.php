<?php
/**
 * Migration 016: Create wp_eem_reservation_config relational table and
 * backfill from wp_postmeta.
 *
 * Phase 2.1 of the postmeta → relational de-coupling plan
 * (docs/WORKPLAN-postmeta-decouple.md). Creates a single flat table with
 * scalar columns for queryable/indexed fields and JSON columns for
 * free-form array structures. Then copies every published/draft/private
 * en_reservation's config from postmeta into the new table.
 *
 * Idempotent: skips reservations whose row already exists (INSERT IGNORE).
 * Safe to re-run on plugin update.
 *
 * @package EEM_Plugin
 * @since   2.7.314
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run migration 016.
 *
 * @return void
 */
function eem_mig_016_reservation_config_table(): void {
	global $wpdb;

	// 1. Ensure the table exists (idempotent via dbDelta).
	EEM_Reservation_Config::create_table();

	// 2. Backfill from postmeta for every en_reservation.
	$table = $wpdb->prefix . 'eem_reservation_config';

	$reservation_ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = 'en_reservation'
		 AND post_status IN ('publish','draft','pending','private','future','trash')
		 ORDER BY ID ASC"
	);

	if ( empty( $reservation_ids ) ) {
		update_option( 'eem_mig_016_reservation_config_table_complete', 1 );
		return;
	}

	$cpt = new EEM_Reservations_CPT();

	foreach ( $reservation_ids as $rid ) {
		$rid = (int) $rid;

		// Skip if row already exists.
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE reservation_id = %d", $rid )
		);
		if ( $exists ) {
			continue;
		}

		// Hydrate from postmeta ONLY. The second arg ($prefer_postmeta=true) is
		// mandatory here: the default get_meta_values() path reads through
		// EEM_Reservation_Config::for(), but this migration runs *before* the
		// table has a row for $rid — so that path would recurse infinitely
		// (get_meta_values → Config::for → hydrate → get_meta_values …) until
		// the stack overflows. Reading postmeta directly is also correct: this
		// backfill's whole job is to copy the postmeta values into the table.
		$values = $cpt->get_meta_values( $rid, true );

		// Build the relational row from the hydrated values.
		EEM_Reservation_Config::insert_from_values( $rid, $values );
	}

	update_option( 'eem_mig_016_reservation_config_table_complete', 1 );
}
