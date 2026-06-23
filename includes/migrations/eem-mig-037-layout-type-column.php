<?php
/**
 * Migration 037: Add layout_type column to wp_eem_venue_layouts.
 *
 * Allows saving stall and RV layouts separately (stall / rv / combined).
 * Existing rows default to 'combined' (the pre-split behavior).
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eem_mig_037_layout_type_column(): void {
	global $wpdb;
	$table = $wpdb->prefix . 'eem_venue_layouts';

	$col = $wpdb->get_var( $wpdb->prepare(
		"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'layout_type'",
		DB_NAME,
		$table
	) );

	if ( $col ) {
		return;
	}

	$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `layout_type` VARCHAR(20) NOT NULL DEFAULT 'combined' AFTER `layout_json`" ); // phpcs:ignore WordPress.DB
}

eem_mig_037_layout_type_column();
