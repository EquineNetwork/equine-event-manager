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
	<?php // C7.C.1.1 — header-toggle is the only visible enable control; body carries a hidden mirror for persistence. ?>
	<input type="hidden" name="en_reservation[venue_agreement_enabled]" data-eem-section-enabled="agreement" value="<?php echo ! empty( $data['venue_agreement_enabled'] ) ? '1' : '0'; ?>" />
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
