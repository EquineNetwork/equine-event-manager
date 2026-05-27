<?php
/**
 * Reservation Editor — "Event Day Info" section body (C7.X.5).
 *
 * Mockup lines 425–473. Four field rows (Check-in instructions /
 * What to bring / Parking / Event-day contact) + section-level
 * intro hint above the rows.
 *
 * New meta keys per CLAUDE.md scope add #1:
 *   _en_event_day_enabled, _en_event_day_checkin, _en_event_day_bring,
 *   _en_event_day_parking, _en_event_day_contact.
 *
 * Customer-facing consumption: C11 confirmation email + C12 hosted
 * order page (per CLAUDE.md). NOT on the PDF receipt.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed> $data */

require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-field-row.php';
?>
<input type="hidden" name="en_reservation[event_day_enabled]" data-eem-section-enabled="eventday" value="<?php echo ! empty( $data['event_day_enabled'] ) ? '1' : '0'; ?>" />

<p class="eem-field-hint eem-section-intro-hint" style="margin-top:0;margin-bottom:14px"><?php esc_html_e( 'Customer-facing info shown in the confirmation email, on the hosted order page, and on the PDF receipt. Leave any field blank to omit that line from the email. Disable the section to hide it entirely.', 'equine-event-manager' ); ?></p>

<?php
// 1. Check-in instructions (text input)
eem_render_editor_field_row( array(
	'label'        => __( 'Check-in instructions', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<input class="eem-field-input" type="text" name="en_reservation[event_day_checkin]" id="en_event_day_checkin" value="%s" placeholder="%s" />',
		esc_attr( (string) ( $data['event_day_checkin'] ?? '' ) ),
		esc_attr__( 'e.g. Friday, May 8 at 7:00 AM at the main barn office', 'equine-event-manager' )
	),
	'hint'         => __( 'Appears as: Check-in opens: [your text]', 'equine-event-manager' ),
) );

// 2. What to bring (textarea)
eem_render_editor_field_row( array(
	'label'        => __( 'What to bring', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<textarea class="eem-field-input" name="en_reservation[event_day_bring]" id="en_event_day_bring" rows="2" placeholder="%s">%s</textarea>',
		esc_attr__( 'e.g. Coggins certificate (within 12 months), feed and water buckets…', 'equine-event-manager' ),
		esc_textarea( (string) ( $data['event_day_bring'] ?? '' ) )
	),
	'hint'         => __( 'Appears as: What to bring: [your text]', 'equine-event-manager' ),
) );

// 3. Parking (textarea)
eem_render_editor_field_row( array(
	'label'        => __( 'Parking', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<textarea class="eem-field-input" name="en_reservation[event_day_parking]" id="en_event_day_parking" rows="2" placeholder="%s">%s</textarea>',
		esc_attr__( 'e.g. Truck and trailer parking is on the east side of the barns…', 'equine-event-manager' ),
		esc_textarea( (string) ( $data['event_day_parking'] ?? '' ) )
	),
	'hint'         => __( 'Appears as: Parking: [your text]', 'equine-event-manager' ),
) );

// 4. Event-day contact (text)
eem_render_editor_field_row( array(
	'label'        => __( 'Event-day contact', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<input class="eem-field-input" type="text" name="en_reservation[event_day_contact]" id="en_event_day_contact" value="%s" placeholder="%s" />',
		esc_attr( (string) ( $data['event_day_contact'] ?? '' ) ),
		esc_attr__( 'e.g. 555-555-5555', 'equine-event-manager' )
	),
	'hint'         => __( 'Appears as: Questions on event day: Call the event hotline at [your text]', 'equine-event-manager' ),
) );
