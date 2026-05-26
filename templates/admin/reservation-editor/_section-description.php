<?php
/**
 * Reservation Editor — "Reservation Description" section body (C7.C.1).
 *
 * Locals contract (provided by EEM_Reservation_Editor_Page::render_description_body):
 *   $data  array  reservation meta values from EEM_Reservations_CPT::get_editor_meta_values()
 *
 * Always-on section per Decision E (no enable toggle in the header). Renders
 * a single textarea bound to `_en_reservation_description`, persisted through
 * the legacy `EEM_Reservations_CPT::save_meta()` 93-field handler via the
 * `en_reservation[reservation_description]` form key.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed> $data */
?>
<table class="form-table eem-editor-fields" role="presentation">
	<tbody>
		<tr>
			<th scope="row"><label for="en_reservation_description"><?php esc_html_e( 'Description', 'equine-event-manager' ); ?></label></th>
			<td>
				<textarea name="en_reservation[reservation_description]" id="en_reservation_description" rows="5" class="large-text"><?php echo esc_textarea( (string) $data['reservation_description'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Shown on the front-end Stay Details card above the default reservation date and rate instructions.', 'equine-event-manager' ); ?></p>
			</td>
		</tr>
	</tbody>
</table>
