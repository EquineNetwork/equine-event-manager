<?php
/**
 * Reservation Editor — "Agreement" section body (C7.C.1).
 *
 * Locals contract (provided by EEM_Reservation_Editor_Page::render_agreement_body):
 *   $data              array  reservation meta values
 *   $reservations_cpt  EEM_Reservations_CPT  for file-field + text-row helpers
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed>      $data */
/** @var EEM_Reservations_CPT      $reservations_cpt */
?>
<div class="eem-editor-fields">
	<div class="eem-section-toggle-row">
		<label class="eem-section-toggle-control">
			<input name="en_reservation[venue_agreement_enabled]" id="en_venue_agreement_enabled" type="checkbox" value="1" data-eem-section-toggle="agreement" <?php checked( $data['venue_agreement_enabled'], 1 ); ?> />
			<span class="eem-section-toggle-control__label"><?php esc_html_e( 'Enable agreement', 'equine-event-manager' ); ?></span>
			<span class="eem-section-toggle-control__track" aria-hidden="true"><span class="eem-section-toggle-control__thumb"></span></span>
		</label>
	</div>
	<table class="form-table" role="presentation">
		<tbody>
			<?php
			$reservations_cpt->render_editor_file_field_row(
				'venue_agreement_file_id',
				__( 'Agreement File', 'equine-event-manager' ),
				$data['venue_agreement_file_id'],
				__( 'Upload the agreement form customers should review before submitting.', 'equine-event-manager' )
			);
			$reservations_cpt->render_editor_text_row(
				'venue_agreement_file_label',
				__( 'Agreement Link Label', 'equine-event-manager' ),
				$data['venue_agreement_file_label'],
				__( 'Shown on the front end for the agreement file link, such as Venue Agreement or Rider Agreement.', 'equine-event-manager' )
			);
			?>
		</tbody>
	</table>
</div>
