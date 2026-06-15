<?php
/**
 * Migration 022: Backfill wp_eem_division_config from en_entry postmeta.
 *
 * Reads all en_entry posts and copies their 6 config meta keys into the
 * relational wp_eem_division_config table via EEM_Division_Config_Repo::save().
 *
 * @package EEM_Plugin
 * @since   2.7.321
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eem_mig_022_division_config_backfill(): void {
	global $wpdb;

	if ( ! class_exists( 'EEM_Division_Config_Repo' ) ) {
		return;
	}

	EEM_Division_Config_Repo::create_table();

	$post_ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'en_entry' AND post_status IN ('publish','draft','trash','private')"
	); // phpcs:ignore WordPress.DB

	foreach ( $post_ids as $pid ) {
		$pid = (int) $pid;

		EEM_Division_Config_Repo::save( $pid, array(
			'reservation_id'   => absint( get_post_meta( $pid, '_en_entry_reservation_id', true ) ),
			'description'      => (string) get_post_meta( $pid, '_en_entry_description', true ),
			'division_name'    => (string) get_post_meta( $pid, '_en_division_name', true ),
			'price'            => (float) get_post_meta( $pid, '_en_division_price', true ),
			'spots'            => absint( get_post_meta( $pid, '_en_division_spots', true ) ),
			'max_per_customer' => absint( get_post_meta( $pid, '_en_division_max', true ) ),
		) );
	}

	update_option( 'eem_mig_022_division_config_backfill_complete', 1 );
}
