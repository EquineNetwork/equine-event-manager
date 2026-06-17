<?php
/**
 * Migration 027: Collapse RV Zones into Rows.
 *
 * Copies nightly surcharge from rv_zones onto each rv_row (matched by zone_id).
 * After this migration, rv_rows carry their own nightly_surcharge and the
 * separate rv_zones config key is no longer needed for pricing.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eem_mig_027_rv_zones_to_rows(): void {
	$reservations = get_posts( array(
		'post_type'      => 'en_reservation',
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids',
	) );

	if ( empty( $reservations ) ) {
		return;
	}

	if ( ! class_exists( 'EEM_Reservation_Config' ) ) {
		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-reservation-config.php';
	}

	foreach ( $reservations as $rid ) {
		$cfg   = EEM_Reservation_Config::for( (int) $rid );
		$zones = $cfg->get( 'rv_zones' );
		$rows  = $cfg->get( 'rv_rows' );

		if ( ! is_array( $zones ) || empty( $zones ) || ! is_array( $rows ) || empty( $rows ) ) {
			continue;
		}

		$changed = false;
		foreach ( $rows as $i => $row ) {
			if ( isset( $row['nightly_surcharge'] ) && (float) $row['nightly_surcharge'] > 0 ) {
				continue;
			}
			$zone_id = isset( $row['zone_id'] ) ? (string) $row['zone_id'] : '';
			if ( '' === $zone_id || ! isset( $zones[ (int) $zone_id ] ) ) {
				continue;
			}
			$zone = $zones[ (int) $zone_id ];
			$surcharge = isset( $zone['nightly'] ) ? (float) $zone['nightly'] : 0.0;
			$rows[ $i ]['nightly_surcharge'] = number_format( $surcharge, 2, '.', '' );
			unset( $rows[ $i ]['zone_id'] );
			$changed = true;
		}

		if ( $changed ) {
			$cfg->set( 'rv_rows', $rows );
			$cfg->save();
		}
	}
}

eem_mig_027_rv_zones_to_rows();
