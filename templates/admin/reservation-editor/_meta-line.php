<?php
/**
 * Reservation editor meta-line partial (C7.B.1).
 *
 * Renders the inline meta strip beneath the page title — event title +
 * date range + venue label + a placeholder "edit linked event" affordance.
 *
 * C7.B.1 scope: READ-ONLY meta readout. The "Change linked event"
 * affordance is a non-functional disabled label here (Decision F).
 * C7.B.2 wires it into a modal launcher (per Q14.b).
 *
 * Data source: EEM_Reservation_Source_Resolver::resolve_event_fields()
 * — returns {title, start_date, end_date, venue} for the reservation's
 * linked event (handles all 3 sources: native/tec/feed transparently).
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
$fields = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
$date_range = EEM_Dashboard_Repo::format_date_range(
	isset( $fields['start_date'] ) ? (string) $fields['start_date'] : '',
	isset( $fields['end_date'] ) ? (string) $fields['end_date'] : ''
);
$title = isset( $fields['title'] ) ? (string) $fields['title'] : '';
$venue = isset( $fields['venue'] ) ? (string) $fields['venue'] : '';
?>
<div class="eem-reservation-editor-meta-line">
	<span class="eem-reservation-editor-meta-label"><?php esc_html_e( 'Linked Event', 'equine-event-manager' ); ?></span>
	<?php if ( '' !== $title ) : ?>
		<span class="eem-reservation-editor-meta-value"><?php echo esc_html( $title ); ?></span>
	<?php else : ?>
		<span class="eem-reservation-editor-meta-value eem-reservation-editor-meta-value--missing">
			<?php esc_html_e( '(no event linked)', 'equine-event-manager' ); ?>
		</span>
	<?php endif; ?>
	<?php if ( '' !== $date_range ) : ?>
		<span class="eem-reservation-editor-meta-sep" aria-hidden="true">·</span>
		<span class="eem-reservation-editor-meta-value"><?php echo esc_html( $date_range ); ?></span>
	<?php endif; ?>
	<?php if ( '' !== $venue ) : ?>
		<span class="eem-reservation-editor-meta-sep" aria-hidden="true">·</span>
		<span class="eem-reservation-editor-meta-value"><?php echo esc_html( $venue ); ?></span>
	<?php endif; ?>
	<span class="eem-reservation-editor-meta-sep" aria-hidden="true">·</span>
	<?php /* C7.B.2: promoted from disabled placeholder to real modal launcher (Q14.b). */ ?>
	<a class="eem-reservation-editor-meta-change-link"
	   href="#"
	   data-eem-action="reservation-editor-launch-linked-event-modal"
	   data-eem-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>"
	   data-eem-current-source="<?php echo esc_attr( EEM_Events::get_effective_reservation_event_source( $reservation_id ) ); ?>">
		<?php esc_html_e( '(change linked event)', 'equine-event-manager' ); ?>
	</a>
</div>
