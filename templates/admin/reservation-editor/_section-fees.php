<?php
/**
 * Reservation Editor — "Convenience Fee" section body (C7.C.1).
 *
 * The fee-value row's `$ vs. %` chrome is provided by
 * EEM_Reservations_CPT::render_editor_fee_value_row(), which renders both
 * input flavors and the JS-driven swap is wired through C7.C.1's
 * admin.js fee-type visibility handler keyed on the
 * `[convenience_fee_type]` select change.
 *
 * Locals contract (provided by EEM_Reservation_Editor_Page::render_fees_body):
 *   $data              array  reservation meta values
 *   $reservations_cpt  EEM_Reservations_CPT  for fee-value + text-row helpers
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
	<input type="hidden" name="en_reservation[convenience_fee_enabled]" data-eem-section-enabled="fees" value="<?php echo ! empty( $data['convenience_fee_enabled'] ) ? '1' : '0'; ?>" />
	<table class="form-table" role="presentation">
		<tbody>
			<?php
			$reservations_cpt->render_editor_text_row(
				'convenience_fee_label',
				__( 'Fee Label', 'equine-event-manager' ),
				$data['convenience_fee_label'],
				__( 'This label appears on the front-end payment summary.', 'equine-event-manager' )
			);
			?>
			<tr>
				<th scope="row"><label for="en_convenience_fee_type"><?php esc_html_e( 'Convenience Fee Type', 'equine-event-manager' ); ?></label></th>
				<td>
					<select name="en_reservation[convenience_fee_type]" id="en_convenience_fee_type" data-eem-action="reservation-editor-fee-type-change">
						<option value="none"       <?php selected( $data['convenience_fee_type'], 'none' ); ?>><?php esc_html_e( 'None',       'equine-event-manager' ); ?></option>
						<option value="flat"       <?php selected( $data['convenience_fee_type'], 'flat' ); ?>><?php esc_html_e( 'Flat',       'equine-event-manager' ); ?></option>
						<option value="percentage" <?php selected( $data['convenience_fee_type'], 'percentage' ); ?>><?php esc_html_e( 'Percentage', 'equine-event-manager' ); ?></option>
					</select>
				</td>
			</tr>
			<?php
			$reservations_cpt->render_editor_fee_value_row(
				'convenience_fee_value',
				__( 'Convenience Fee Value', 'equine-event-manager' ),
				$data['convenience_fee_value'],
				$data['convenience_fee_type']
			);
			?>
		</tbody>
	</table>
</div>
