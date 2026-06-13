<?php
/**
 * EEM single-event template (2.3.50).
 *
 * Handed to WordPress by EEM_Events::filter_single_event_template() for
 * supported single event views. Renders the theme header and footer around
 * EEM's own normalized event markup (hero + reservation mount), bypassing the
 * theme's single template and TEC's event chrome entirely.
 *
 * The event body is produced by the [equine_event_manager_event] shortcode,
 * which reuses render_normalized_event_markup() (2.3.49) and mounts the
 * [en_reservation] form when the event has a linked reservation.
 *
 * @package Equine_Event_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$eem_event_id  = (int) get_queried_object_id();
$eem_is_sheets = (bool) get_query_var( 'eem_sheets' ) && class_exists( 'EEM_Sheets_Results_Page' )
	&& class_exists( 'EEM_Events' ) && EEM_Events::is_sheets_results_enabled();

// On the Sheets & Results variant, retitle the document before the header
// prints <title>.
if ( $eem_is_sheets && $eem_event_id ) {
	add_filter(
		'document_title_parts',
		static function ( $parts ) use ( $eem_event_id ) {
			$parts['title'] = get_the_title( $eem_event_id ) . ' — ' . __( 'Sheets & Results', 'equine-event-manager' );
			return $parts;
		}
	);
}

get_header();

if ( $eem_event_id ) {
	if ( $eem_is_sheets ) {
		echo EEM_Sheets_Results_Page::render_public_page( $eem_event_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in render_public_page().
	} else {
		echo do_shortcode( sprintf( '[equine_event_manager_event id="%d" show_content="1" show_reservation="1"]', $eem_event_id ) );
	}
}

get_footer();
