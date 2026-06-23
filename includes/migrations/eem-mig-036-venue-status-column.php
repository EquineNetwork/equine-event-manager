<?php
/**
 * Migration 036 — Add status column to wp_eem_venues for soft-delete (trash lifecycle).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eem_mig_036_venue_status_column(): void {
	global $wpdb;
	$table = $wpdb->prefix . 'eem_venues';

	$col = $wpdb->get_var( $wpdb->prepare(
		"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
		DB_NAME,
		$table
	) );

	if ( ! $col ) {
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN status varchar(20) NOT NULL DEFAULT 'active' AFTER geocoded_address" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "ALTER TABLE {$table} ADD KEY status (status)" ); // phpcs:ignore WordPress.DB
	}

	update_option( 'eem_mig_036_venue_status_column_complete', 1 );
}
