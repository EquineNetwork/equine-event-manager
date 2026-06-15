<?php
/**
 * Migration 017: Drop config postmeta rows for reservations migrated to the
 * relational wp_eem_reservation_config table.
 *
 * Final step of the postmeta → relational de-coupling. Deletes _en_* and
 * _eem_section_enabled_* config keys from wp_postmeta for every reservation
 * that has a row in the relational table. Non-config keys (_en_reservation_shortcode,
 * _en_reservation_linked_*, _en_tec_event_linked_*, _en_source_event_start_date)
 * are preserved.
 *
 * Idempotent: safe to re-run (DELETE only hits rows that exist).
 *
 * @package EEM_Plugin
 * @since   2.7.318
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run migration 017.
 *
 * @return void
 */
function eem_mig_017_drop_config_postmeta(): void {
	global $wpdb;

	$config_table = $wpdb->prefix . 'eem_reservation_config';

	// Only run if the relational table exists and has rows.
	$table_exists = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $config_table )
	);
	if ( ! $table_exists ) {
		update_option( 'eem_mig_017_drop_config_postmeta_complete', 1 );
		return;
	}

	// Non-config keys that must NOT be deleted (they live outside the config manifest).
	$preserve = array(
		'_en_reservation_shortcode',
		'_en_reservation_linked_tec_event',
		'_en_reservation_linked_native_event',
		'_en_tec_event_linked_reservation',
		'_en_native_event_linked_reservation',
		'_en_source_event_start_date',
	);

	$preserve_clause = implode(
		',',
		array_map( function ( $k ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', $k );
		}, $preserve )
	);

	// Delete config postmeta for migrated reservations in batches.
	$migrated_ids = $wpdb->get_col( "SELECT reservation_id FROM {$config_table}" );

	if ( empty( $migrated_ids ) ) {
		update_option( 'eem_mig_017_drop_config_postmeta_complete', 1 );
		return;
	}

	// Process in batches of 50 to avoid very large DELETE statements.
	$batches = array_chunk( $migrated_ids, 50 );

	foreach ( $batches as $batch ) {
		$id_placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta}
			 WHERE post_id IN ({$id_placeholders})
			 AND (meta_key LIKE %s OR meta_key LIKE %s)
			 AND meta_key NOT IN ({$preserve_clause})",
			...array_merge( $batch, array( '_en_%', '_eem_section_enabled_%' ) )
		) );
	}

	update_option( 'eem_mig_017_drop_config_postmeta_complete', 1 );
}
