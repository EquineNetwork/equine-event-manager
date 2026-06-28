<?php
/**
 * Migration #038 — one-time normalization of accidental ALL-CAPS customer names
 * on existing order rows.
 *
 * CSV-imported orders (and any hand-entered ones) sometimes carry names typed in
 * full caps ("EVANS, HARPER"). This walks the two order component tables and
 * Title-cases any customer_name whose letters are entirely uppercase, leaving
 * intentionally mixed-case names (McCann, Lopez Dugas) untouched. The same
 * eem_normalize_caps_name() helper the importer uses going forward is applied
 * here so existing data and future imports converge on one rule.
 *
 * Idempotent / flag-gated. Bounded: only rows whose name is all-caps (server-side
 * BINARY filter) are fetched, so it never loads the full orders table on large
 * sites.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The normalization helper lives in the importer file (guarded global). Pull it
// in if migrations run before that admin file is loaded.
if ( ! function_exists( 'eem_normalize_caps_name' ) ) {
	require_once EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-import-handler.php';
}

/**
 * Title-case all-caps customer names across the stall + RV order tables.
 *
 * @return array{updated:int} Count of rows rewritten (for verification).
 */
function eem_mig_038_normalize_caps_names() {
	global $wpdb;

	$flag = 'eem_mig_038_normalize_caps_names_complete';
	if ( get_option( $flag ) ) {
		return array( 'updated' => 0 );
	}

	$tables  = array(
		$wpdb->prefix . 'eem_stall_reservations',
		$wpdb->prefix . 'eem_rv_reservations',
	);
	$updated = 0;

	foreach ( $tables as $table ) {
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			continue;
		}

		// BINARY forces a case-sensitive comparison so only names with NO
		// lowercase letters (i.e. all-caps) are fetched — mixed-case rows are
		// excluded server-side, keeping the working set small.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix; no user input.
		$rows = $wpdb->get_results(
			"SELECT id, customer_name FROM {$table}
			 WHERE customer_name <> '' AND customer_name = BINARY UPPER(customer_name)",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$norm = eem_normalize_caps_name( (string) $row['customer_name'] );
			if ( $norm !== (string) $row['customer_name'] ) {
				$wpdb->update(
					$table,
					array( 'customer_name' => $norm ),
					array( 'id' => (int) $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);
				$updated++;
			}
		}
	}

	update_option( $flag, time() );
	return array( 'updated' => $updated );
}
