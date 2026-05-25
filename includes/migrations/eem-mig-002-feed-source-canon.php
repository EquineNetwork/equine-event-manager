<?php
/**
 * Migration #002 — 'external' → 'feed' canonicalization (C7.A).
 *
 * Per Q4.5: `'feed'` is the canonical event_source value; `'external'`
 * is a legacy synonym. The repo normalizes on write (`normalize_event_source()`)
 * but pre-existing rows in `wp_eem_event_defaults` written before that
 * normalization shipped need a one-time canonicalization sweep.
 *
 * On a fresh install (no pre-existing 'external' rows): no-op + flag
 * set. On an install that somehow accumulated 'external' rows: single
 * UPDATE statement flips them to 'feed', composite-PK collisions
 * (event_id present under BOTH 'external' AND 'feed') are detected and
 * 'external' rows deleted (the 'feed' canonical row wins).
 *
 * Trivially fast (single UPDATE on a small table). Flag-gated to
 * avoid re-evaluation on every admin load.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run the feed/external source canonicalization.
 *
 * @return array{updated:int, deleted_collisions:int, source:string}
 */
function eem_mig_002_feed_source_canon() {
	$flag_key = 'eem_mig_002_feed_source_canon_complete';
	if ( get_option( $flag_key ) ) {
		return array( 'updated' => 0, 'deleted_collisions' => 0, 'source' => 'already-complete' );
	}

	if ( ! class_exists( 'EEM_Event_Defaults_Repo' ) ) {
		// Table doesn't exist yet — defer; will re-run on next maybe_upgrade.
		return array( 'updated' => 0, 'deleted_collisions' => 0, 'source' => 'table-missing' );
	}

	global $wpdb;
	$table = EEM_Event_Defaults_Repo::table_name();

	// Detect composite-PK collisions first: event_ids that exist under
	// BOTH 'external' AND 'feed'. Delete the 'external' rows in those
	// cases (the canonical 'feed' row wins).
	$collisions = $wpdb->get_col(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT a.event_id FROM {$table} a
		 INNER JOIN {$table} b
		   ON a.event_id = b.event_id
		  AND a.event_source = 'external'
		  AND b.event_source = 'feed'"
	);

	$deleted_collisions = 0;
	if ( ! empty( $collisions ) ) {
		foreach ( $collisions as $event_id ) {
			$deleted = $wpdb->delete(
				$table,
				array( 'event_id' => (string) $event_id, 'event_source' => 'external' )
			);
			if ( false !== $deleted ) {
				$deleted_collisions += (int) $deleted;
			}
		}
	}

	// Now flip remaining 'external' rows to 'feed' (no collision risk
	// after the prior step).
	$updated = $wpdb->update(
		$table,
		array( 'event_source' => 'feed' ),
		array( 'event_source' => 'external' )
	);
	if ( false === $updated ) {
		$updated = 0;
	}

	update_option( $flag_key, time() );

	return array(
		'updated'            => (int) $updated,
		'deleted_collisions' => $deleted_collisions,
		'source'             => 'sql-canonicalize',
	);
}
