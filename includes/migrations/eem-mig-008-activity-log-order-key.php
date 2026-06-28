<?php
/**
 * Migration #008 — backfill the denormalized `order_key` column on the activity
 * log (CLEANUP #32).
 *
 * The dedicated `order_key` column + `KEY order_key_created (order_key, created_at)`
 * index were added to `{prefix}eem_activity_log` via dbDelta in
 * `create_activity_log_table()`. Existing rows wrote the order key only inside the
 * JSON `payload`; this migration extracts it into the new column so
 * `EEM_Activity_Log::get_for_order_key()` (now an indexed `WHERE order_key = %s`
 * lookup) returns historical entries too.
 *
 * Idempotent / flag-gated: only touches rows where `order_key = ''` AND the payload
 * actually carries an order_key. Uses MySQL JSON extraction when available, with a
 * regex fallback for older MySQL/MariaDB builds without JSON_EXTRACT.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill `order_key` from the JSON payload for every legacy activity-log row.
 *
 * @return array{updated:int} Count of rows updated (for telemetry/verification).
 */
function eem_mig_008_activity_log_order_key() {
	global $wpdb;

	$flag = 'eem_mig_008_activity_log_order_key_complete';
	if ( get_option( $flag ) ) {
		return array( 'updated' => 0 );
	}

	$table = $wpdb->prefix . 'eem_activity_log';

	// Guard: the table must exist and carry the new column (dbDelta runs before
	// run_one_time_migrations in activate(), so this should always hold).
	$column_exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'order_key'",
			$table
		)
	);
	if ( ! $column_exists ) {
		update_option( $flag, time() );
		return array( 'updated' => 0 );
	}

	$updated = 0;

	// Preferred path: native JSON extraction (MySQL 5.7+/MariaDB 10.2+).
	$json_supported = (bool) $wpdb->get_var( "SELECT JSON_EXTRACT('{\"k\":1}', '$.k')" );

	if ( $json_supported ) {
		$updated = (int) $wpdb->query(
			"UPDATE {$table}
			 SET order_key = JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_key'))
			 WHERE order_key = ''
			   AND payload LIKE '%\"order_key\":%'
			   AND JSON_EXTRACT(payload, '$.order_key') IS NOT NULL"
		);
	} else {
		// Fallback: walk matching rows in PHP and parse the key out of the JSON.
		$rows = $wpdb->get_results(
			"SELECT id, payload FROM {$table} WHERE order_key = '' AND payload LIKE '%\"order_key\":%'",
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$decoded = json_decode( (string) $row['payload'], true );
			$key     = is_array( $decoded ) && isset( $decoded['order_key'] ) ? sanitize_text_field( (string) $decoded['order_key'] ) : '';
			if ( '' === $key ) {
				continue;
			}
			$wpdb->update( $table, array( 'order_key' => $key ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
			$updated++;
		}
	}

	update_option( $flag, time() );
	return array( 'updated' => $updated );
}
