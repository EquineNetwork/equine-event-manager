<?php
/**
 * Reservation Editor — right-rail Linked Event card (C7.X.3).
 *
 * Mockup-canonical Linked Event UX (mockup lines 1180–1196). Replaces
 * the legacy modal-launched-from-meta-line UX from C7.B.2 (now retired).
 *
 * Structure:
 *   .eem-rail-card > .eem-rail-header "Linked Event"
 *                  > .eem-rail-body
 *                      > rail-hint copy
 *                      > .eem-event-search typeahead input
 *                      > .eem-event-linked display block (if linked):
 *                          - .eem-event-linked-name (linked event title)
 *                          - .eem-event-linked-date (formatted date range)
 *                          - .eem-event-unlink button (✕ Unlink)
 *
 * Search input is a stub for now — typeahead endpoint wires when the
 * dependent backend is in place. Unlink button dispatches AJAX to the
 * eem_reservation_editor_unlink_event handler (page class).
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
$display_title  = '' !== $venue && '' !== $title ? $venue . ' – ' . $title : $title;
$has_link       = '' !== $title;
?>
<div class="eem-rail-card">
	<div class="eem-rail-header">
		<span class="eem-rail-title"><?php esc_html_e( 'Linked Event', 'equine-event-manager' ); ?></span>
	</div>
	<div class="eem-rail-body">
		<p class="eem-rail-hint"><?php esc_html_e( 'Link this reservation to an event from your active event source. The reservation title and dates mirror the linked event.', 'equine-event-manager' ); ?></p>
		<input class="eem-event-search" type="text" placeholder="<?php esc_attr_e( 'Search events…', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-event-search" data-eem-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>" autocomplete="off" />
		<?php if ( $has_link ) : ?>
			<div class="eem-event-linked">
				<div style="min-width:0">
					<div class="eem-event-linked-name"><a href="#"><?php echo esc_html( $display_title ); ?></a></div>
					<?php if ( '' !== $date_range ) : ?>
						<div class="eem-event-linked-date"><?php echo esc_html( $date_range ); ?></div>
					<?php endif; ?>
				</div>
				<button class="eem-event-unlink" type="button" data-eem-action="reservation-editor-event-unlink">✕ <?php esc_html_e( 'Unlink', 'equine-event-manager' ); ?></button>
			</div>
		<?php endif; ?>
	</div>
</div>
