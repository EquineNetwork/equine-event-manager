<?php
/**
 * Migration 028: backfill the per-rate-type surcharge object onto existing RV rows.
 *
 * Each rv_row historically carried a single `nightly_surcharge` float. Slice 2
 * introduces a per-rate-type `surcharge` object ({nightly, packages:{}}). This
 * migration adds `surcharge` to every existing row that lacks it, derived from
 * the legacy nightly amount. The legacy `nightly_surcharge` field is preserved as
 * a back-compat mirror until the Slice 4/5 readers move to the object.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add a `surcharge` object to every existing RV row that lacks one.
 *
 * @return void
 */
function eem_mig_028_rv_row_surcharge_object(): void {
	if ( ! class_exists( 'EEM_Reservation_Config' ) ) {
		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-reservation-config.php';
	}
	if ( ! class_exists( 'EEM_Surcharge' ) ) {
		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-surcharge.php';
	}

	global $wpdb;
	if ( EEM_Reservation_Config::table_exists() ) {
		// rv_rows is a dedicated JSON column — only reservations whose column holds
		// real row data need migrating, so query those directly instead of
		// hydrating every reservation's (potentially large stall_map/rv_map) config.
		// Keeps the one-time migration fast and memory-safe on large seeded sites.
		$table = $wpdb->prefix . 'eem_reservation_config';
		$ids   = $wpdb->get_col( "SELECT reservation_id FROM {$table} WHERE rv_rows IS NOT NULL AND rv_rows <> '' AND rv_rows <> '[]' AND rv_rows <> 'null'" ); // phpcs:ignore WordPress.DB.PreparedSQL -- table name from prefix, no user input.
	} else {
		$ids = get_posts(
			array(
				'post_type'      => 'en_reservation',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);
	}

	if ( empty( $ids ) ) {
		return;
	}

	foreach ( $ids as $rid ) {
		$cfg  = EEM_Reservation_Config::for( (int) $rid );
		$rows = $cfg->get( 'rv_rows' );

		if ( is_array( $rows ) && ! empty( $rows ) ) {
			$changed = false;
			foreach ( $rows as $i => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( isset( $row['surcharge'] ) && is_array( $row['surcharge'] ) ) {
					continue; // Already migrated.
				}
				$rows[ $i ]['surcharge'] = EEM_Surcharge::from_legacy_nightly( $row['nightly_surcharge'] ?? 0 );
				$changed                 = true;
			}

			if ( $changed ) {
				$cfg->set( 'rv_rows', $rows );
				$cfg->save();
			}
		}

		// Evict the per-reservation config instance so its (potentially large —
		// stall_map / rv_map JSON) hydrated data does not accumulate across the
		// full reservation set and exhaust memory on large seeded sites.
		EEM_Reservation_Config::flush_cache( (int) $rid );
		unset( $cfg, $rows );
	}
}

eem_mig_028_rv_row_surcharge_object();
