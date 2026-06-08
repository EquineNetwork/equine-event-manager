<?php
/**
 * Migration #007 — rename section-enabled toggles to the canonical
 * `_eem_section_enabled_<shortkey>` post-meta scheme (CLEANUP #44).
 *
 * Each reservation section's on/off state historically lived under an
 * inconsistent legacy `_en_<field>_enabled` key (`_en_checkin_checkout_enabled`,
 * `_en_general_addons_enabled`, `_en_convenience_fee_enabled`, etc.). This
 * migration snapshots every legacy value onto the canonical key
 * ({@see EEM_Reservations_CPT::SECTION_ENABLED_MAP}) for every existing
 * reservation so behavior is preserved exactly across the rename.
 *
 * The legacy `_en_<field>_enabled` keys are LEFT in place (read-no-write) as a
 * historical record and as the read fallback in
 * {@see EEM_Reservations_CPT::read_section_enabled_raw()}. Idempotent: a
 * reservation already carrying the canonical key is skipped so a re-run never
 * clobbers a later admin edit; flag-gated so it runs once per install.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copy every legacy section-enabled value onto its canonical key for all
 * reservations that don't already carry the canonical key.
 *
 * @return array{scanned:int, updated:int} Counts for telemetry/verification.
 */
function eem_mig_007_section_enabled_rename() {
	$flag = 'eem_mig_007_section_enabled_rename_complete';
	if ( get_option( $flag ) ) {
		return array( 'scanned' => 0, 'updated' => 0 );
	}

	// Resolve the field => short-key map from the canonical source of truth.
	$map = class_exists( 'EEM_Reservations_CPT' )
		? EEM_Reservations_CPT::SECTION_ENABLED_MAP
		: array(
			'stalls_enabled'             => 'stalls',
			'rv_enabled'                 => 'rv',
			'checkin_checkout_enabled'   => 'checkin',
			'general_addons_enabled'     => 'addons',
			'group_reservations_enabled' => 'group',
			'convenience_fee_enabled'    => 'fees',
			'venue_agreement_enabled'    => 'agreement',
		);

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
			$rid = (int) $rid;
			foreach ( $map as $field => $shortkey ) {
				$canonical = '_eem_section_enabled_' . $shortkey;
				$legacy    = '_en_' . $field;
				// Never clobber a reservation that already has the canonical key
				// (idempotent re-run safety). Only copy when the legacy key exists.
				if ( metadata_exists( 'post', $rid, $canonical ) ) {
					continue;
				}
				if ( ! metadata_exists( 'post', $rid, $legacy ) ) {
					continue;
				}
				update_post_meta( $rid, $canonical, get_post_meta( $rid, $legacy, true ) );
				$updated++;
			}
		}
		$paged++;
	} while ( count( $ids ) === 200 );

	update_option( $flag, time() );
	return array( 'scanned' => $scanned, 'updated' => $updated );
}
