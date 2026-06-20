<?php
/**
 * Migration 026: Stay Packages data model.
 *
 * 1. Creates wp_eem_stay_packages table.
 * 2. Adds stall_pricing_mode / rv_pricing_mode columns to wp_eem_reservation_config.
 * 3. Adds selected_package_ids, effective_start_date, effective_end_date columns
 *    to wp_en_stall_reservations and wp_en_rv_reservations.
 *
 * Idempotent: uses dbDelta for the new table; ALTER IGNORE for columns.
 *
 * @package EEM_Plugin
 * @since   2.7.334
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run migration 026.
 *
 * @return void
 */
function eem_mig_026_stay_packages(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();

	// 1. Create wp_eem_stay_packages table.
	$packages_table = $wpdb->prefix . 'eem_stay_packages';

	$sql = "CREATE TABLE {$packages_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		reservation_id bigint(20) unsigned NOT NULL,
		type varchar(10) NOT NULL DEFAULT 'stall',
		name varchar(191) NOT NULL,
		start_date date NOT NULL,
		end_date date NOT NULL,
		price decimal(10,2) NOT NULL DEFAULT 0.00,
		early_bird_price decimal(10,2) NULL DEFAULT NULL,
		sort_order int NOT NULL DEFAULT 0,
		max_quantity int NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY reservation_id (reservation_id),
		KEY reservation_type (reservation_id, type)
	) {$charset_collate};";

	dbDelta( $sql );

	// 2. Add pricing mode columns to reservation_config.
	$config_table = $wpdb->prefix . 'eem_reservation_config';

	$existing_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$config_table}", 0 );

	if ( ! in_array( 'stall_pricing_mode', $existing_cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$config_table} ADD COLUMN stall_pricing_mode varchar(20) NOT NULL DEFAULT 'nightly'" );
	}

	if ( ! in_array( 'rv_pricing_mode', $existing_cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$config_table} ADD COLUMN rv_pricing_mode varchar(20) NOT NULL DEFAULT 'nightly'" );
	}

	// 3. Add package columns to stall reservations table.
	$stall_table = $wpdb->prefix . 'en_stall_reservations';
	$stall_cols  = $wpdb->get_col( "SHOW COLUMNS FROM {$stall_table}", 0 );

	if ( ! in_array( 'selected_package_ids', $stall_cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$stall_table} ADD COLUMN selected_package_ids JSON NULL" );
	}
	if ( ! in_array( 'effective_start_date', $stall_cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$stall_table} ADD COLUMN effective_start_date date NULL" );
	}
	if ( ! in_array( 'effective_end_date', $stall_cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$stall_table} ADD COLUMN effective_end_date date NULL" );
	}

	// 4. Add package columns to RV reservations table.
	$rv_table = $wpdb->prefix . 'en_rv_reservations';
	$rv_cols  = $wpdb->get_col( "SHOW COLUMNS FROM {$rv_table}", 0 );

	if ( ! in_array( 'selected_package_ids', $rv_cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$rv_table} ADD COLUMN selected_package_ids JSON NULL" );
	}
	if ( ! in_array( 'effective_start_date', $rv_cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$rv_table} ADD COLUMN effective_start_date date NULL" );
	}
	if ( ! in_array( 'effective_end_date', $rv_cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$rv_table} ADD COLUMN effective_end_date date NULL" );
	}

	update_option( 'eem_mig_026_stay_packages_complete', 1 );
}
