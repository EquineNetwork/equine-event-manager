<?php
/**
 * Reservation Editor — "Stall Reservations" section body (C7.C.2.1).
 *
 * Stall-RULES surface: 9 field rows (description, available dates,
 * stay types, weekend package dates, reservation schedule + open/close
 * datetimes, inventory, nightly + weekend rates, early bird + cutoff +
 * rates, required shavings + qty + price).
 *
 * EXCLUDED — wires in C7.C.2.2:
 *   - Read-only Stall Layout summary widget (mockup lines 624–644)
 *   - "Manage Stall Layout" button → C8 stall chart page
 *
 * EXCLUDED — C8 scope:
 *   - Stall Row Builder (add/remove/reorder, range labels, overlap
 *     detection, Preview Full Chart modal). Mockup line 624 calls out
 *     "READ-ONLY STALL LAYOUT SUMMARY (full editor lives in C8)".
 *
 * Locals contract (provided by EEM_Reservation_Editor_Page::render_section_body):
 *   $data              array  reservation meta values
 *   $reservations_cpt  EEM_Reservations_CPT  for the field-row helpers
 *
 * Available Reservation Dates row renders here AND in the Check-In
 * section (C7.C.1) — same persisted meta keys (`_en_available_*`),
 * edit-one-update-both UX is mockup-canonical (mockup lines 502–511
 * stall, 676–685 rv, plus the checkin section). Smoke regression-
 * guards both bindings.
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
	<input type="hidden" name="en_reservation[stalls_enabled]" data-eem-section-enabled="stall" value="<?php echo ! empty( $data['stalls_enabled'] ) ? '1' : '0'; ?>" />
	<table class="form-table" role="presentation">
		<tbody>
			<?php
			$reservations_cpt->render_editor_textarea_row(
				'stall_description',
				__( 'Reservation Description', 'equine-event-manager' ),
				$data['stall_description'],
				__( 'Describe stall amenities, bedding details, or other reservation notes shown on the front end.', 'equine-event-manager' )
			);
			$reservations_cpt->render_editor_date_range_row(
				'available_start_date',
				'available_end_date',
				__( 'Available Reservation Dates', 'equine-event-manager' ),
				$data['available_start_date'],
				$data['available_end_date'],
				__( 'Bookable date window for stalls.', 'equine-event-manager' )
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Stay Types', 'equine-event-manager' ); ?></th>
				<td>
					<div class="eem-stay-type-toggle-group">
						<label class="eem-inline-toggle-control">
							<input name="en_reservation[stall_nightly_enabled]" id="en_stall_nightly_enabled" type="checkbox" value="1" <?php checked( $data['stall_nightly_enabled'], 1 ); ?> />
							<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
							<span class="eem-inline-toggle-control__label"><?php esc_html_e( 'Nightly', 'equine-event-manager' ); ?></span>
						</label>
						<label class="eem-inline-toggle-control">
							<input name="en_reservation[stall_weekend_enabled]" id="en_stall_weekend_enabled" type="checkbox" value="1" <?php checked( $data['stall_weekend_enabled'], 1 ); ?> />
							<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
							<span class="eem-inline-toggle-control__label"><?php esc_html_e( 'Weekend Rate', 'equine-event-manager' ); ?></span>
						</label>
					</div>
					<p class="description"><?php esc_html_e( 'Enable one or both stall stay types. Weekend Rate uses the stall weekend package dates below.', 'equine-event-manager' ); ?></p>
				</td>
			</tr>
			<?php
			$reservations_cpt->render_editor_date_range_row(
				'stall_weekend_package_start_date',
				'stall_weekend_package_end_date',
				__( 'Weekend Package Dates', 'equine-event-manager' ),
				$data['stall_weekend_package_start_date'],
				$data['stall_weekend_package_end_date'],
				__( 'Customers choosing Stall Weekend Rate will automatically use this package date range.', 'equine-event-manager' ),
				'eem-weekend-package-row eem-rate-mode-row eem-rate-mode-group--stall'
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Reservation Schedule', 'equine-event-manager' ); ?></th>
				<td>
					<label class="eem-inline-toggle-control">
						<input name="en_reservation[stall_schedule_enabled]" id="en_stall_schedule_enabled" type="checkbox" value="1" <?php checked( $data['stall_schedule_enabled'], 1 ); ?> />
						<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
						<span class="eem-inline-toggle-control__label"><?php esc_html_e( 'Schedule Stall Reservations', 'equine-event-manager' ); ?></span>
					</label>
					<p class="description"><?php esc_html_e( 'Turn this on to open and close stall reservations on specific dates and times.', 'equine-event-manager' ); ?></p>
				</td>
			</tr>
			<?php
			$reservations_cpt->render_editor_datetime_row( 'stalls_open_at',  __( 'Stalls Open Date/Time',  'equine-event-manager' ), $data['stalls_open_at'],  '', 'eem-schedule-row eem-schedule-group--stall' );
			$reservations_cpt->render_editor_datetime_row( 'stalls_close_at', __( 'Stalls Close Date/Time', 'equine-event-manager' ), $data['stalls_close_at'], '', 'eem-schedule-row eem-schedule-group--stall' );
			$reservations_cpt->render_editor_number_row(
				'stall_inventory',
				__( 'Available Stall Inventory', 'equine-event-manager' ),
				$data['stall_inventory'],
				__( 'Leave blank for unlimited inventory. Once inventory reaches zero, customers will see a sold out message.', 'equine-event-manager' )
			);
			$reservations_cpt->render_editor_currency_row(
				'stall_nightly_rate',
				__( 'Stall Nightly Rate', 'equine-event-manager' ),
				$data['stall_nightly_rate'],
				array( 'mode' => 'nightly', 'group' => 'stall' )
			);
			$reservations_cpt->render_editor_currency_row(
				'stall_weekend_rate',
				__( 'Stall Weekend Rate', 'equine-event-manager' ),
				$data['stall_weekend_rate'],
				array( 'mode' => 'weekend', 'group' => 'stall' )
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Stall Early Bird', 'equine-event-manager' ); ?></th>
				<td>
					<label class="eem-inline-toggle-control">
						<input name="en_reservation[stall_early_bird_enabled]" id="en_stall_early_bird_enabled" type="checkbox" value="1" <?php checked( $data['stall_early_bird_enabled'], 1 ); ?> />
						<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
						<span class="eem-inline-toggle-control__label"><?php esc_html_e( 'Enable stall early bird pricing', 'equine-event-manager' ); ?></span>
					</label>
				</td>
			</tr>
			<?php
			$reservations_cpt->render_editor_datetime_row(
				'stall_early_bird_cutoff',
				__( 'Stall Early Bird Cutoff', 'equine-event-manager' ),
				$data['stall_early_bird_cutoff'],
				'',
				'eem-early-bird-row eem-early-bird-group--stall'
			);
			$reservations_cpt->render_editor_currency_row(
				'stall_early_bird_nightly_rate',
				__( 'Stall Early Bird Nightly Rate', 'equine-event-manager' ),
				$data['stall_early_bird_nightly_rate'],
				array( 'mode' => 'nightly', 'group' => 'stall', 'row_classes' => 'eem-early-bird-row eem-early-bird-group--stall' )
			);
			$reservations_cpt->render_editor_currency_row(
				'stall_early_bird_weekend_rate',
				__( 'Stall Early Bird Weekend Rate', 'equine-event-manager' ),
				$data['stall_early_bird_weekend_rate'],
				array( 'mode' => 'weekend', 'group' => 'stall', 'row_classes' => 'eem-early-bird-row eem-early-bird-group--stall' )
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Required Shavings', 'equine-event-manager' ); ?></th>
				<td>
					<label class="eem-inline-toggle-control">
						<input name="en_reservation[required_shavings_enabled]" id="en_required_shavings_enabled" type="checkbox" value="1" <?php checked( $data['required_shavings_enabled'], 1 ); ?> />
						<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
						<span class="eem-inline-toggle-control__label"><?php esc_html_e( 'Require shavings with each stall', 'equine-event-manager' ); ?></span>
					</label>
				</td>
			</tr>
			<tr class="eem-required-shavings-row">
				<th scope="row"><label for="en_required_shavings_per_stall"><?php esc_html_e( 'Required Shavings Per Stall', 'equine-event-manager' ); ?></label></th>
				<td><input name="en_reservation[required_shavings_per_stall]" id="en_required_shavings_per_stall" type="number" min="0" step="1" value="<?php echo esc_attr( (string) $data['required_shavings_per_stall'] ); ?>" /></td>
			</tr>
			<?php
			$reservations_cpt->render_editor_currency_row(
				'required_shavings_price',
				__( 'Required Shavings Price Per Bag', 'equine-event-manager' ),
				$data['required_shavings_price'],
				array( 'row_classes' => 'eem-required-shavings-row' )
			);
			?>
		</tbody>
	</table>
</div>
