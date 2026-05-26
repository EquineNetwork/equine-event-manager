<?php
/**
 * Reservation Editor — "Reservation Description" section body
 * (C7.C.1.4.A rewrite — mockup-canonical chrome).
 *
 * Mockup lines 367–389. Single field row containing a textarea.
 * Always-on section (no enable toggle); body never disabled.
 *
 * Walkthrough enumeration (per CLAUDE.md "Mockup Walkthrough Pre-
 * Audit" rule):
 *   1.3 row     → .eem-field-row (grid 220px+1fr)
 *   1.4 label   → .eem-field-label + .eem-field-label-sub
 *                 ("Description" / "Shown on the customer-facing
 *                 reservation form")
 *   1.5 control → <textarea class="eem-field-textarea">
 *                 (populated from `_en_reservation_description`)
 *   1.6 hint    → "Shown above the reservation date and rate
 *                 instructions on the front end."
 *
 * Locals contract (provided by EEM_Reservation_Editor_Page::render_section_body):
 *   $data  array  reservation meta values
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

$control_html = sprintf(
	'<textarea class="eem-field-textarea" name="en_reservation[reservation_description]" id="en_reservation_description" rows="4">%s</textarea>',
	esc_textarea( (string) $data['reservation_description'] )
);

eem_render_editor_field_row( array(
	'label'        => __( 'Description', 'equine-event-manager' ),
	'label_sub'    => __( 'Shown on the customer-facing reservation form', 'equine-event-manager' ),
	'control_html' => $control_html,
	'hint'         => __( 'Shown above the reservation date and rate instructions on the front end.', 'equine-event-manager' ),
) );
