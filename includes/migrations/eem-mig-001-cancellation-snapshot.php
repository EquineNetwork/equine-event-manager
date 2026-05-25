<?php
/**
 * Migration #001 — Cancellation policy snapshot (C7.A).
 *
 * Option C migration per HANDOFF 9.3. Reads the existing global
 * `cancellation_policy` wp_option and writes that value into every
 * existing reservation's `_eem_cancellation_policy_override` post-meta.
 * Snapshotting the policy onto every existing reservation as it was at
 * purchase time preserves contract integrity — the cancellation terms
 * a customer agreed to at purchase IS the contract; future event-
 * default changes must not retroactively affect existing reservations.
 *
 * Idempotency:
 *   - Top-level: gated by the `eem_mig_001_cancellation_snapshot_complete`
 *     wp_option flag (set on successful completion to a timestamp). Runs
 *     once per install.
 *   - Row-level: any reservation that already has a non-empty override
 *     meta is SKIPPED — won't overwrite an admin's customization that
 *     happened pre-migration somehow.
 *
 * Batching: 200 reservations per page to keep memory + execution time
 * bounded on sites with many reservations.
 *
 * Failure recovery: if a partial run aborts (DB error, timeout, fatal),
 * the flag stays unset → next admin load re-fires through `maybe_upgrade`
 * → picks up where it left off (row-level idempotency skips already-
 * snapshotted reservations). When all reservations have a value (either
 * pre-existing or freshly snapshotted), the flag flips to a timestamp.
 *
 * Empty-global edge case: if the global `cancellation_policy` wp_option
 * is empty/missing, the migration writes nothing but still sets the
 * flag (records that the snapshot opportunity was evaluated).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run the cancellation policy snapshot. Safe to invoke at any time —
 * the completion flag gates effective execution.
 *
 * @return array{snapshotted:int, skipped:int, scanned:int, source:string}
 *         Per-call metrics for logging / wp-cli output.
 */
function eem_mig_001_cancellation_snapshot() {
	$flag_key = 'eem_mig_001_cancellation_snapshot_complete';
	if ( get_option( $flag_key ) ) {
		return array( 'snapshotted' => 0, 'skipped' => 0, 'scanned' => 0, 'source' => 'already-complete' );
	}

	$global_policy = (string) get_option( 'cancellation_policy', '' );

	// Empty-global edge case: nothing to snapshot. Flip flag anyway so
	// we don't re-evaluate on every admin load.
	if ( '' === trim( $global_policy ) ) {
		update_option( $flag_key, time() );
		return array( 'snapshotted' => 0, 'skipped' => 0, 'scanned' => 0, 'source' => 'empty-global' );
	}

	$snapshotted = 0;
	$skipped     = 0;
	$scanned     = 0;
	$paged       = 1;
	$per_page    = 200;

	do {
		$reservations = get_posts( array(
			'post_type'      => 'en_reservation',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		if ( empty( $reservations ) ) {
			break;
		}

		foreach ( $reservations as $rid ) {
			$scanned++;
			$existing = get_post_meta( (int) $rid, '_eem_cancellation_policy_override', true );
			if ( '' !== trim( (string) $existing ) ) {
				$skipped++;
				continue;
			}
			update_post_meta( (int) $rid, '_eem_cancellation_policy_override', $global_policy );
			$snapshotted++;
		}

		$paged++;
	} while ( count( $reservations ) === $per_page );

	update_option( $flag_key, time() );

	return array(
		'snapshotted' => $snapshotted,
		'skipped'     => $skipped,
		'scanned'     => $scanned,
		'source'      => 'global-wp-option',
	);
}
