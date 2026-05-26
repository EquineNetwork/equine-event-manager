<?php
/**
 * Reservation Editor — "Check-In / Check-Out" section body (C7.C.1).
 *
 * Combines two legacy meta-boxes into the single mockup-canon section:
 *   - "Available Reservation Dates"  → date range pair
 *   - "Check-In/Check-Out"           → enable toggle + checkin / checkout
 *                                       datetimes (via render_editor_datetime_row)
 *
 * Locals contract (provided by EEM_Reservation_Editor_Page::render_checkin_body):
 *   $data              array  reservation meta values
 *   $reservations_cpt  EEM_Reservations_CPT  for the datetime-row helper
 *
 * The inline `[checkin_checkout_enabled]` checkbox is the persisted source
 * of truth for the section's enabled state — the header-card toggle is
 * a separate visual-only surface (C7.B.2.1) until C7.G polish unifies the
 * two. Both stay rendered for now; users can click either to toggle.
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

$checkin_fallback  = ! empty( $data['stalls_open_at'] ) ? $data['stalls_open_at'] : $data['available_start_date'];
$checkout_fallback = ! empty( $data['stalls_close_at'] ) ? $data['stalls_close_at'] : $data['available_end_date'];
?>
<div class="eem-editor-fields">
	<input type="hidden" name="en_reservation[available_dates_manually_edited]" id="en_available_dates_manually_edited" value="<?php echo esc_attr( (string) $data['available_dates_manually_edited'] ); ?>" />
	<p class="description"><?php esc_html_e( 'Select the dates for which you would like customers to be able to make reservations.', 'equine-event-manager' ); ?></p>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><label for="en_available_start_date"><?php esc_html_e( 'Date Range', 'equine-event-manager' ); ?></label></th>
				<td>
					<div>
						<input name="en_reservation[available_start_date]" id="en_available_start_date" type="date" value="<?php echo esc_attr( (string) $data['available_start_date'] ); ?>" />
						<span aria-hidden="true">-</span>
						<input name="en_reservation[available_end_date]" id="en_available_end_date" type="date" value="<?php echo esc_attr( (string) $data['available_end_date'] ); ?>" />
					</div>
				</td>
			</tr>
		</tbody>
	</table>

	<div class="eem-section-toggle-row">
		<label class="eem-section-toggle-control">
			<input name="en_reservation[checkin_checkout_enabled]" id="en_checkin_checkout_enabled" type="checkbox" value="1" data-eem-section-toggle="checkin-checkout" <?php checked( $data['checkin_checkout_enabled'], 1 ); ?> />
			<span class="eem-section-toggle-control__label"><?php esc_html_e( 'Enable check-in/check-out', 'equine-event-manager' ); ?></span>
			<span class="eem-section-toggle-control__track" aria-hidden="true"><span class="eem-section-toggle-control__thumb"></span></span>
		</label>
	</div>
	<p class="description"><?php esc_html_e( 'Set the customer check-in and check-out time for all reservations.', 'equine-event-manager' ); ?></p>
	<table class="form-table" role="presentation">
		<tbody>
			<?php $reservations_cpt->render_editor_datetime_row( 'checkin_time',  __( 'Check-In Time',  'equine-event-manager' ), $data['checkin_time'],  '', '', $checkin_fallback ); ?>
			<?php $reservations_cpt->render_editor_datetime_row( 'checkout_time', __( 'Check-Out Time', 'equine-event-manager' ), $data['checkout_time'], '', '', $checkout_fallback ); ?>
		</tbody>
	</table>
</div>
