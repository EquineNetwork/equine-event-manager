<?php
/**
 * Migration 019 — Drop producer postmeta rows.
 *
 * Deletes `_equine_event_manager_producer_*` and
 * `_equine_event_manager_imported_tec_organizer_id` postmeta rows for
 * en_producer posts that have a corresponding row in wp_eem_producers.
 *
 * @package EEM_Plugin
 * @since   2.7.319
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eem_mig_019_drop_producer_postmeta(): void {
	global $wpdb;

	if ( ! class_exists( 'EEM_Producer_Repo' ) ) {
		require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-producer-repo.php';
	}

	if ( ! EEM_Producer_Repo::table_exists() ) {
		return;
	}

	$table = EEM_Producer_Repo::table_name();

	$deleted = $wpdb->query(
		"DELETE pm FROM {$wpdb->postmeta} pm
		 INNER JOIN {$table} pr ON pr.producer_id = pm.post_id
		 WHERE pm.meta_key IN (
			'_equine_event_manager_producer_contact_name',
			'_equine_event_manager_producer_email',
			'_equine_event_manager_producer_phone',
			'_equine_event_manager_producer_website',
			'_equine_event_manager_imported_tec_organizer_id'
		 )"
	);

	update_option( 'eem_mig_019_drop_producer_postmeta_complete', 1 );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( sprintf( '[EEM] Migration 019: deleted %d producer postmeta rows.', (int) $deleted ) );
	}
}
