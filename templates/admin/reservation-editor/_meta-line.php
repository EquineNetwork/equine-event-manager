<?php
/**
 * Reservation Editor meta-line (C7.X.3 mockup-canonical port).
 *
 * Mockup lines 349–358: two-column inline meta strip under the
 * .eem-plugin-title / .eem-plugin-subtitle.
 *   [Linked Event]   <a>Perry, GA – 2026 Southeast Region Super Sort</a>
 *   [Event Dates]    May 8, 2026 – May 10, 2026
 *
 * Per the new Build-to-Mockup canon: NO inline "(change linked event)"
 * launcher anchor — the Linked Event rail card replaces the modal UX.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 *
 * Expects $reservation_id (int) in scope.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $reservation_id ) ) {
	return;
}

$reservation_id = (int) $reservation_id;
$fields         = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
$title          = isset( $fields['title'] ) ? (string) $fields['title'] : '';
$venue          = isset( $fields['venue'] ) ? (string) $fields['venue'] : '';
$date_range     = EEM_Dashboard_Repo::format_date_range(
	isset( $fields['start_date'] ) ? (string) $fields['start_date'] : '',
	isset( $fields['end_date'] ) ? (string) $fields['end_date'] : ''
);
$display_title  = '' !== $venue && '' !== $title ? $venue . ' – ' . $title : ( '' !== $title ? $title : __( '(no event linked)', 'equine-event-manager' ) );
?>
<div class="eem-plugin-meta-line">
	<span>
		<span class="eem-meta-label"><?php esc_html_e( 'Linked Event', 'equine-event-manager' ); ?></span>
		<?php if ( '' !== $title ) : ?>
			<a class="eem-meta-value" href="#"><?php echo esc_html( $display_title ); ?></a>
		<?php else : ?>
			<span class="eem-meta-value"><?php echo esc_html( $display_title ); ?></span>
		<?php endif; ?>
	</span>
	<?php if ( '' !== $date_range ) : ?>
		<span>
			<span class="eem-meta-label"><?php esc_html_e( 'Event Dates', 'equine-event-manager' ); ?></span>
			<span class="eem-meta-value"><?php echo esc_html( $date_range ); ?></span>
		</span>
	<?php endif; ?>
</div>
