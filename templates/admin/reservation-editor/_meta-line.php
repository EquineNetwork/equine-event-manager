<?php
/**
 * Reservation Editor meta-line (C7.X.12 — Item 7 rail card retirement).
 *
 * Mockup pattern (updated this commit): two-column inline meta strip
 * under the .eem-plugin-title / .eem-plugin-subtitle. Linked-event
 * editing is now inline via small "(change)" + "(unlink)" text links
 * — the right-rail "Linked Event" card retires this commit.
 *
 *   [Linked Event]   <a>Perry, GA – 2026 Southeast Region Super Sort</a>
 *                    <a>(change)</a>  <a>(unlink)</a>
 *   [Event Dates]    May 8, 2026 – May 10, 2026
 *
 * Click handlers (admin.js):
 *   - `reservation-editor-event-change` opens the typeahead modal
 *     (`#eem-modal-linked-event`) — same modal the retired rail card
 *     used internally.
 *   - `reservation-editor-event-unlink` confirms then dispatches the
 *     existing `eem_reservation_editor_unlink_event` AJAX handler.
 *
 * When no event is linked, render just "(link event)" — invites the
 * admin to set one without showing dead unlink affordance.
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
$has_link       = '' !== $title;
$display_title  = '' !== $venue && $has_link ? $venue . ' – ' . $title : ( $has_link ? $title : __( '(no event linked)', 'equine-event-manager' ) );
?>
<div class="eem-plugin-meta-line">
	<span>
		<span class="eem-meta-label"><?php esc_html_e( 'Linked Event', 'equine-event-manager' ); ?></span>
		<?php if ( $has_link ) : ?>
			<a class="eem-meta-value" href="#"><?php echo esc_html( $display_title ); ?></a>
			<a class="eem-meta-action" href="#" data-eem-action="reservation-editor-event-change" data-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>"><?php esc_html_e( '(change)', 'equine-event-manager' ); ?></a>
			<a class="eem-meta-action eem-meta-action--danger" href="#" data-eem-action="reservation-editor-event-unlink" data-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>"><?php esc_html_e( '(unlink)', 'equine-event-manager' ); ?></a>
		<?php else : ?>
			<span class="eem-meta-value"><?php echo esc_html( $display_title ); ?></span>
			<a class="eem-meta-action" href="#" data-eem-action="reservation-editor-event-change" data-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>"><?php esc_html_e( '(link event)', 'equine-event-manager' ); ?></a>
		<?php endif; ?>
	</span>
	<span>
		<span class="eem-meta-label"><?php esc_html_e( 'Event Dates', 'equine-event-manager' ); ?></span>
		<span class="eem-meta-value"><?php echo esc_html( '' !== $date_range ? $date_range : '—' ); ?></span>
	</span>
</div>
