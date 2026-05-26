<?php
/**
 * Reservation Editor — "Group Reservations" section body (C7.C.1).
 *
 * Locals contract (provided by EEM_Reservation_Editor_Page::render_group_body):
 *   $data              array  reservation meta values
 *   $reservations_cpt  EEM_Reservations_CPT  for currency-row helper
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
	<input type="hidden" name="en_reservation[group_reservations_enabled]" data-eem-section-enabled="group" value="<?php echo ! empty( $data['group_reservations_enabled'] ) ? '1' : '0'; ?>" />
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Group Reservation Logic', 'equine-event-manager' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'When enabled, customers can turn on a group reservation on the front end, enter the rider count, and provide a first and last name for each rider.', 'equine-event-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Rider Grounds Fee', 'equine-event-manager' ); ?></th>
				<td>
					<label class="eem-inline-toggle-control">
						<input name="en_reservation[group_rider_grounds_fee_enabled]" id="en_group_rider_grounds_fee_enabled" type="checkbox" value="1" <?php checked( $data['group_rider_grounds_fee_enabled'], 1 ); ?> />
						<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
						<span class="eem-inline-toggle-control__label"><?php esc_html_e( 'Charge a grounds fee for each rider in the group reservation.', 'equine-event-manager' ); ?></span>
					</label>
				</td>
			</tr>
			<?php
			$reservations_cpt->render_editor_currency_row(
				'group_rider_grounds_fee_amount',
				__( 'Rider Grounds Fee Amount', 'equine-event-manager' ),
				$data['group_rider_grounds_fee_amount'],
				array(
					'disabled'    => empty( $data['group_rider_grounds_fee_enabled'] ),
					'row_classes' => 'eem-group-fee-row eem-group-fee-row--grounds',
				)
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Rider Deposit', 'equine-event-manager' ); ?></th>
				<td>
					<label class="eem-inline-toggle-control">
						<input name="en_reservation[group_rider_deposit_enabled]" id="en_group_rider_deposit_enabled" type="checkbox" value="1" <?php checked( $data['group_rider_deposit_enabled'], 1 ); ?> />
						<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
						<span class="eem-inline-toggle-control__label"><?php esc_html_e( 'Require a deposit for each rider in the group reservation.', 'equine-event-manager' ); ?></span>
					</label>
				</td>
			</tr>
			<?php
			$reservations_cpt->render_editor_currency_row(
				'group_rider_deposit_amount',
				__( 'Rider Deposit Amount', 'equine-event-manager' ),
				$data['group_rider_deposit_amount'],
				array(
					'disabled'    => empty( $data['group_rider_deposit_enabled'] ),
					'row_classes' => 'eem-group-fee-row eem-group-fee-row--deposit',
				)
			);
			?>
		</tbody>
	</table>
</div>
