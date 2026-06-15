<?php
/**
 * Migration 018 — Backfill wp_eem_producers from postmeta.
 *
 * Reads all en_producer posts and copies their postmeta fields into the
 * new wp_eem_producers relational table. Idempotent (REPLACE INTO).
 *
 * @package EEM_Plugin
 * @since   2.7.319
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eem_mig_018_producer_table_backfill(): void {
	global $wpdb;

	if ( ! class_exists( 'EEM_Producer_Repo' ) ) {
		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-producer-repo.php';
	}

	EEM_Producer_Repo::create_table();

	$producer_ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'en_producer' AND post_status IN ('publish','draft','pending','private','trash')"
	);

	$count = 0;
	foreach ( $producer_ids as $pid ) {
		$pid = (int) $pid;

		$contact_name = (string) get_post_meta( $pid, '_equine_event_manager_producer_contact_name', true );
		$email        = (string) get_post_meta( $pid, '_equine_event_manager_producer_email', true );
		$phone        = (string) get_post_meta( $pid, '_equine_event_manager_producer_phone', true );
		$website      = (string) get_post_meta( $pid, '_equine_event_manager_producer_website', true );
		$tec_id       = (int) get_post_meta( $pid, '_equine_event_manager_imported_tec_organizer_id', true );

		$wpdb->replace(
			EEM_Producer_Repo::table_name(),
			array(
				'producer_id'               => $pid,
				'contact_name'              => $contact_name,
				'email'                     => $email,
				'phone'                     => $phone,
				'website'                   => $website,
				'imported_tec_organizer_id' => $tec_id,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d' )
		);
		++$count;
	}

	update_option( 'eem_mig_018_producer_table_backfill_complete', 1 );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( sprintf( '[EEM] Migration 018: backfilled %d producers into wp_eem_producers.', $count ) );
	}
}
