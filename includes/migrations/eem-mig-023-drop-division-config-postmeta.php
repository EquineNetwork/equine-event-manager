<?php
/**
 * Migration 023: Drop division config postmeta rows after backfill.
 *
 * Deletes the 6 meta keys that have been migrated to wp_eem_division_config,
 * only for posts that actually have a row in the config table.
 *
 * @package EEM_Plugin
 * @since   2.7.321
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eem_mig_023_drop_division_config_postmeta(): void {
	global $wpdb;

	$cfg_table = $wpdb->prefix . 'eem_division_config';

	$keys = array(
		'_en_entry_reservation_id',
		'_en_entry_description',
		'_en_division_name',
		'_en_division_price',
		'_en_division_spots',
		'_en_division_max',
	);

	$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

	// phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare(
		"DELETE pm FROM {$wpdb->postmeta} pm
		 INNER JOIN {$cfg_table} dc ON dc.division_id = pm.post_id
		 WHERE pm.meta_key IN ({$placeholders})",
		...$keys
	) );

	update_option( 'eem_mig_023_drop_division_config_postmeta_complete', 1 );
}
