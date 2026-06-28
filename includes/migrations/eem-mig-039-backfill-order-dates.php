<?php
/**
 * Migration #039 — backfill arrival/departure (and effective) dates on order
 * rows that carry only a stay-type LABEL (e.g. "Thursday-Sunday") but no actual
 * calendar dates.
 *
 * CSV-imported stay-package orders stored the stay-type name without resolving
 * it to dates, leaving arrival_date/departure_date NULL. That blanked the
 * Arrival/Departure columns on the Stall Chart and broke date-aware stall
 * allocation (every order collided on stalls 1..qty because occupancy can't be
 * spaced without dates). This derives the dates from the reservation's stay
 * packages (matching by weekday name) and writes them back.
 *
 * Idempotent / flag-gated. Only touches rows missing arrival_date.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_match_stay_package_dates' ) ) {
	require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-import-handler.php';
}

/**
 * Backfill order dates from stay packages.
 *
 * @return array{updated:int} Count of order rows updated.
 */
function eem_mig_039_backfill_order_dates() {
	global $wpdb;

	$flag = 'eem_mig_039_backfill_order_dates_complete';
	if ( get_option( $flag ) ) {
		return array( 'updated' => 0 );
	}

	$pkg_table = $wpdb->prefix . 'eem_stay_packages';
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pkg_table ) ) || ! class_exists( 'EEM_Stay_Packages_Repo' ) ) {
		update_option( $flag, time() );
		return array( 'updated' => 0 );
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
	$res_ids = $wpdb->get_col( "SELECT DISTINCT reservation_id FROM {$pkg_table}" );
	$updated = 0;

	foreach ( (array) $res_ids as $rid ) {
		$rid    = (int) $rid;
		$all_pk = EEM_Stay_Packages_Repo::get_packages( $rid );
		$by_type = array(
			$wpdb->prefix . 'eem_stall_reservations' => array_values( array_filter( $all_pk, static function ( $p ) { return ( $p['type'] ?? '' ) === 'stall'; } ) ),
			$wpdb->prefix . 'eem_rv_reservations'     => array_values( array_filter( $all_pk, static function ( $p ) { return ( $p['type'] ?? '' ) === 'rv'; } ) ),
		);

		foreach ( $by_type as $table => $pks ) {
			if ( empty( $pks ) ) {
				continue;
			}
			// arrival_date is a DATE column — comparing it to '' is invalid under
			// strict SQL mode and breaks the whole predicate, so match NULL and
			// the zero-date sentinel only.
			$rows = $wpdb->get_results( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix.
				"SELECT id, stay_type FROM {$table} WHERE reservation_id = %d AND ( arrival_date IS NULL OR arrival_date = '0000-00-00' )",
				$rid
			), ARRAY_A );

			foreach ( (array) $rows as $r ) {
				list( $arr, $dep ) = eem_match_stay_package_dates( (string) $r['stay_type'], $pks );
				if ( '' === $arr || '' === $dep ) {
					continue;
				}
				$wpdb->update(
					$table,
					array(
						'arrival_date'         => $arr,
						'departure_date'       => $dep,
						'effective_start_date' => $arr,
						'effective_end_date'   => $dep,
					),
					array( 'id' => (int) $r['id'] ),
					array( '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
				$updated++;
			}
		}
	}

	update_option( $flag, time() );
	return array( 'updated' => $updated );
}
