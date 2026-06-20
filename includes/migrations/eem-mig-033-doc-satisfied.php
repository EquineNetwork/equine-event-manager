<?php
/**
 * Migration 033: "marked satisfied" state for required documents.
 *
 * Adds satisfied / satisfied_by / satisfied_at columns to
 * wp_eem_order_documents so an admin can mark a required document as
 * fulfilled in person ("I laid eyes on the papers") without uploading a
 * file (Whitney 2026-06-20). A requirement is fulfilled when it has an
 * uploaded file OR satisfied = 1.
 *
 * Idempotent: each column added only when absent.
 *
 * @package EEM_Plugin
 * @since   2.7.511
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run migration 033.
 *
 * @return void
 */
function eem_mig_033_doc_satisfied(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'eem_order_documents';
	$cols  = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB

	if ( ! in_array( 'satisfied', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN satisfied tinyint(1) NOT NULL DEFAULT 0 AFTER file_size" ); // phpcs:ignore WordPress.DB
	}
	if ( ! in_array( 'satisfied_by', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN satisfied_by bigint(20) unsigned NOT NULL DEFAULT 0 AFTER satisfied" ); // phpcs:ignore WordPress.DB
	}
	if ( ! in_array( 'satisfied_at', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN satisfied_at datetime NULL DEFAULT NULL AFTER satisfied_by" ); // phpcs:ignore WordPress.DB
	}

	update_option( 'eem_mig_033_doc_satisfied_complete', 1, false );
}
