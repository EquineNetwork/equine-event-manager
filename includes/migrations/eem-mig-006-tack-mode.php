<?php
/**
 * Migration #006 — convert the boolean tack toggle to the 3-mode tack control.
 *
 * T1 replaced the v2 #4 boolean `_en_stall_tack_designation_enabled` (1 = let
 * customers designate a tack stall) with `_en_stall_tack_mode`
 * ('off' | 'admin' | 'customer'). This migration snapshots the old boolean onto
 * the new key for every existing reservation so their behavior is preserved:
 *   old 1 (or absent → defaulted ON) → 'customer'
 *   old 0                            → 'off'
 * The old key is left in place (read-no-write) as a historical record. Runs once
 * per install (flag-gated, idempotent); reservations already carrying the new
 * key are skipped so a re-run never clobbers an admin's later choice.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the new tack mode for a single reservation from its legacy boolean.
 *
 * @param int $reservation_id Reservation post id.
 * @return string 'off' | 'customer' (this migration never produces 'admin').
 */
function eem_mig_006_resolve_mode( $reservation_id ) {
	// Absent legacy meta means the field predates v2 #4, where the selector was
	// always shown — i.e. equivalent to ON → 'customer'.
	if ( ! metadata_exists( 'post', (int) $reservation_id, '_en_stall_tack_designation_enabled' ) ) {
		return 'customer';
	}
	$legacy = get_post_meta( (int) $reservation_id, '_en_stall_tack_designation_enabled', true );
	return ( '' === (string) $legacy || (int) $legacy >= 1 ) ? 'customer' : 'off';
}

/**
 * Write `_en_stall_tack_mode` for every reservation that lacks it.
 *
 * @return array{scanned:int, updated:int}
 */
function eem_mig_006_tack_mode() {
	$flag = 'eem_mig_006_tack_mode_complete';
	if ( get_option( $flag ) ) {
		return array( 'scanned' => 0, 'updated' => 0 );
	}

	$scanned = 0;
	$updated = 0;
	$paged   = 1;

	do {
		$ids = get_posts( array(
			'post_type'      => 'en_reservation',
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'paged'          => $paged,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
		if ( empty( $ids ) ) {
			break;
		}
		foreach ( $ids as $rid ) {
			$scanned++;
			// Never clobber a reservation that already carries the new key.
			if ( metadata_exists( 'post', (int) $rid, '_en_stall_tack_mode' ) ) {
				continue;
			}
			update_post_meta( (int) $rid, '_en_stall_tack_mode', eem_mig_006_resolve_mode( (int) $rid ) );
			$updated++;
		}
		$paged++;
	} while ( count( $ids ) === 200 );

	update_option( $flag, time() );
	return array( 'scanned' => $scanned, 'updated' => $updated );
}
