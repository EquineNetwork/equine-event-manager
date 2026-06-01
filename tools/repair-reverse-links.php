<?php
/**
 * One-time repair: repoint stale event->reservation reverse links to the
 * active (non-trashed) reservation that forward-links the event, then
 * re-mirror each reservation's title from its linked event.
 *
 * Run: wp eval-file tools/repair-reverse-links.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$reservations = get_posts( array(
	'post_type'      => 'en_reservation',
	'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
	'posts_per_page' => -1,
	'fields'         => 'ids',
) );

$fixed_links  = 0;
$fixed_titles = 0;

foreach ( $reservations as $rid ) {
	$event_id = absint( get_post_meta( $rid, '_en_event_id', true ) );
	if ( ! $event_id ) {
		continue;
	}

	// Repoint the event's reverse link if it points to a trashed / missing reservation.
	$current_rev = absint( get_post_meta( $event_id, '_equine_event_manager_reservation_id', true ) );
	$rev_status  = $current_rev ? get_post_status( $current_rev ) : false;
	if ( $current_rev !== $rid && ( ! $current_rev || ! $rev_status || 'trash' === $rev_status ) ) {
		update_post_meta( $event_id, '_equine_event_manager_reservation_id', $rid );
		if ( 'tribe_events' === get_post_type( $event_id ) ) {
			update_post_meta( $event_id, '_equine_event_manager_reservations', '[en_reservation id="' . $rid . '"]' );
		}
		$fixed_links++;
		WP_CLI::log( "Repointed event {$event_id} reverse link: {$current_rev} -> {$rid}" );
	}

	// Re-mirror title from resolved event fields.
	if ( class_exists( 'EEM_Reservation_Source_Resolver' ) ) {
		$fields = EEM_Reservation_Source_Resolver::resolve_event_fields( $rid );
		$title  = isset( $fields['title'] ) ? trim( (string) $fields['title'] ) : '';
		if ( '' !== $title && get_post_field( 'post_title', $rid ) !== $title ) {
			wp_update_post( array(
				'ID'         => $rid,
				'post_title' => $title,
				'post_name'  => sanitize_title( $title ),
			) );
			$fixed_titles++;
			WP_CLI::log( "Re-titled reservation {$rid} -> {$title}" );
		}
	}
}

WP_CLI::success( "Done. Reverse links fixed: {$fixed_links}. Titles fixed: {$fixed_titles}." );
