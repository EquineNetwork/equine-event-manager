<?php
/**
 * Reservation Editor — "RV Reservations" section body (C7.C.2.1).
 *
 * RV-RULES surface: 8 field rows (description, available dates, stay
 * types, weekend package dates, reservation schedule + open/close
 * datetimes, inventory, nightly + weekend rates, early bird + cutoff +
 * rates).
 *
 * EXCLUDED — wires in C7.C.2.2:
 *   - Lot Zones repeating-row builder (NEW _eem_rv_lot_zones meta key,
 *     8-preset color palette per Decision C-1)
 *   - RV Add-Ons table (master toggle + repeating rows)
 *   - Blocked RV Lots multiselect (alongside the read-only Lot Layout
 *     summary widget)
 *   - "Manage Lot Layout" button → C8 stall chart page
 *
 * Locals contract (provided by EEM_Reservation_Editor_Page::render_section_body):
 *   $data              array  reservation meta values
 *   $reservations_cpt  EEM_Reservations_CPT  for the field-row helpers
 *
 * Available Reservation Dates row renders here AND in stall (and
 * checkin) — same persisted `_en_available_*` keys, edit-one-update-
 * both UX is mockup-canonical (intentional per the C7.C.2.1 kickoff
 * conversation).
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
	<input type="hidden" name="en_reservation[rv_enabled]" data-eem-section-enabled="rv" value="<?php echo ! empty( $data['rv_enabled'] ) ? '1' : '0'; ?>" />
	<table class="form-table" role="presentation">
		<tbody>
			<?php
			$reservations_cpt->render_editor_textarea_row(
				'rv_description',
				__( 'Reservation Description', 'equine-event-manager' ),
				$data['rv_description'],
				__( 'Describe RV amenities, hookups, or other reservation notes shown on the front end.', 'equine-event-manager' )
			);
			$reservations_cpt->render_editor_date_range_row(
				'available_start_date',
				'available_end_date',
				__( 'Available Reservation Dates', 'equine-event-manager' ),
				$data['available_start_date'],
				$data['available_end_date'],
				__( 'Bookable date window for RV lots.', 'equine-event-manager' )
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Stay Types', 'equine-event-manager' ); ?></th>
				<td>
					<div class="eem-stay-type-toggle-group">
						<label class="eem-inline-toggle-control">
							<input name="en_reservation[rv_nightly_enabled]" id="en_rv_nightly_enabled" type="checkbox" value="1" <?php checked( $data['rv_nightly_enabled'], 1 ); ?> />
							<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
							<span class="eem-inline-toggle-control__label"><?php esc_html_e( 'Nightly', 'equine-event-manager' ); ?></span>
						</label>
						<label class="eem-inline-toggle-control">
							<input name="en_reservation[rv_weekend_enabled]" id="en_rv_weekend_enabled" type="checkbox" value="1" <?php checked( $data['rv_weekend_enabled'], 1 ); ?> />
							<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
							<span class="eem-inline-toggle-control__label"><?php esc_html_e( 'Weekend Rate', 'equine-event-manager' ); ?></span>
						</label>
					</div>
					<p class="description"><?php esc_html_e( 'Enable one or both RV stay types. Weekend Rate uses the RV weekend package dates below.', 'equine-event-manager' ); ?></p>
				</td>
			</tr>
			<?php
			$reservations_cpt->render_editor_date_range_row(
				'rv_weekend_package_start_date',
				'rv_weekend_package_end_date',
				__( 'Weekend Package Dates', 'equine-event-manager' ),
				$data['rv_weekend_package_start_date'],
				$data['rv_weekend_package_end_date'],
				__( 'Customers choosing RV Weekend Rate will automatically use this package date range.', 'equine-event-manager' ),
				'eem-weekend-package-row eem-rate-mode-row eem-rate-mode-group--rv'
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Reservation Schedule', 'equine-event-manager' ); ?></th>
				<td>
					<label class="eem-inline-toggle-control">
						<input name="en_reservation[rv_schedule_enabled]" id="en_rv_schedule_enabled" type="checkbox" value="1" <?php checked( $data['rv_schedule_enabled'], 1 ); ?> />
						<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
						<span class="eem-inline-toggle-control__label"><?php esc_html_e( 'Schedule RV Reservations', 'equine-event-manager' ); ?></span>
					</label>
					<p class="description"><?php esc_html_e( 'Turn this on to open and close RV reservations on specific dates and times.', 'equine-event-manager' ); ?></p>
				</td>
			</tr>
			<?php
			$reservations_cpt->render_editor_datetime_row( 'rv_open_at',  __( 'RV Open Date/Time',  'equine-event-manager' ), $data['rv_open_at'],  '', 'eem-schedule-row eem-schedule-group--rv' );
			$reservations_cpt->render_editor_datetime_row( 'rv_close_at', __( 'RV Close Date/Time', 'equine-event-manager' ), $data['rv_close_at'], '', 'eem-schedule-row eem-schedule-group--rv' );
			$reservations_cpt->render_editor_number_row(
				'rv_inventory',
				__( 'Available RV Inventory', 'equine-event-manager' ),
				$data['rv_inventory'],
				__( 'Leave blank for unlimited inventory. Once inventory reaches zero, customers will see a sold out message.', 'equine-event-manager' ),
				'eem-rv-inventory-row'
			);
			$reservations_cpt->render_editor_currency_row(
				'rv_nightly_rate',
				__( 'RV Nightly Rate', 'equine-event-manager' ),
				$data['rv_nightly_rate'],
				array( 'mode' => 'nightly', 'group' => 'rv' )
			);
			$reservations_cpt->render_editor_currency_row(
				'rv_weekend_rate',
				__( 'RV Weekend Rate', 'equine-event-manager' ),
				$data['rv_weekend_rate'],
				array( 'mode' => 'weekend', 'group' => 'rv' )
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'RV Early Bird', 'equine-event-manager' ); ?></th>
				<td>
					<label class="eem-inline-toggle-control">
						<input name="en_reservation[rv_early_bird_enabled]" id="en_rv_early_bird_enabled" type="checkbox" value="1" <?php checked( $data['rv_early_bird_enabled'], 1 ); ?> />
						<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
						<span class="eem-inline-toggle-control__label"><?php esc_html_e( 'Enable RV early bird pricing', 'equine-event-manager' ); ?></span>
					</label>
				</td>
			</tr>
			<?php
			$reservations_cpt->render_editor_datetime_row(
				'rv_early_bird_cutoff',
				__( 'RV Early Bird Cutoff', 'equine-event-manager' ),
				$data['rv_early_bird_cutoff'],
				'',
				'eem-early-bird-row eem-early-bird-group--rv'
			);
			$reservations_cpt->render_editor_currency_row(
				'rv_early_bird_nightly_rate',
				__( 'RV Early Bird Nightly Rate', 'equine-event-manager' ),
				$data['rv_early_bird_nightly_rate'],
				array( 'mode' => 'nightly', 'group' => 'rv', 'row_classes' => 'eem-early-bird-row eem-early-bird-group--rv' )
			);
			$reservations_cpt->render_editor_currency_row(
				'rv_early_bird_weekend_rate',
				__( 'RV Early Bird Weekend Rate', 'equine-event-manager' ),
				$data['rv_early_bird_weekend_rate'],
				array( 'mode' => 'weekend', 'group' => 'rv', 'row_classes' => 'eem-early-bird-row eem-early-bird-group--rv' )
			);
			?>
		</tbody>
	</table>
</div>
