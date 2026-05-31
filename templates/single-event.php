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

get_header();

$eem_event_id = (int) get_queried_object_id();

if ( $eem_event_id ) {
	echo do_shortcode( sprintf( '[equine_event_manager_event id="%d" show_content="1" show_reservation="1"]', $eem_event_id ) );
}

get_footer();
