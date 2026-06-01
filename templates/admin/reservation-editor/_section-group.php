<?php
/**
 * Reservation Editor — "Group Reservations" section body
 * (C7.C.1.4.A rewrite — mockup-canonical chrome).
 *
 * Mockup lines 938–1002. Description textarea + Riders Per Group
 * input + 2× toggle-label-row sub-toggles (Grounds Fee + Deposit)
 * + 2 conditional price-wrap amount rows. Collapsed by default.
 * `.eem-section-disabled-note` callout renders when section disabled.
 *
 * Walkthrough enumeration:
 *   3.3 disabled-note → "This section is disabled. Enable it to let
 *                       customers register groups of riders." (via skeleton)
 *   3.4 Group Description → <textarea class="eem-field-textarea">
 *                         _en_group_description (NEW meta key — Decision N1)
 *   3.5 Riders Per Group → <input class="eem-field-input" type="number"
 *                         style="max-width:120px"
 *                         _en_group_riders_per_group (NEW — Decision N1)
 *   3.6 Grounds Fee toggle-label-row → controls eem-ctrl--grounds-amt
 *                         (_en_group_rider_grounds_fee_enabled)
 *   3.7 Grounds Fee Amount → .eem-price-wrap, hidden when 3.6 off
 *                         (_en_group_rider_grounds_fee_amount)
 *   3.9 Deposit toggle-label-row → controls eem-ctrl--deposit-amt
 *                         (_en_group_rider_deposit_enabled)
 *   3.10 Deposit Amount → .eem-price-wrap, hidden when 3.9 off
 *                         (_en_group_rider_deposit_amount)
 *
 * Hidden section-enable mirror per C7.C.1.1.
 *
 * Locals contract:
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
require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-toggle-label-row.php';

$grounds_on = ! empty( $data['group_rider_grounds_fee_enabled'] );
$deposit_on = ! empty( $data['group_rider_deposit_enabled'] );
$grounds_amt = isset( $data['group_rider_grounds_fee_amount'] ) ? (float) $data['group_rider_grounds_fee_amount'] : 0.0;
$deposit_amt = isset( $data['group_rider_deposit_amount'] ) ? (float) $data['group_rider_deposit_amount'] : 0.0;
?>
<input type="hidden" name="en_reservation[group_reservations_enabled]" data-eem-section-enabled="group" value="<?php echo ! empty( $data['group_reservations_enabled'] ) ? '1' : '0'; ?>" />
<?php
// 3.4 Group Description (NEW meta key)
eem_render_editor_field_row( array(
	'label'        => __( 'Group Description', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<textarea class="eem-field-textarea" name="en_reservation[group_description]" id="en_group_description" rows="3" placeholder="%s">%s</textarea>',
		esc_attr__( 'Describe what a group reservation includes...', 'equine-event-manager' ),
		esc_textarea( (string) ( $data['group_description'] ?? '' ) )
	),
) );

// 3.5 Riders Per Group (NEW meta key). 2.3.82: defaults to blank = unlimited.
eem_render_editor_field_row( array(
	'label'        => __( 'Riders Per Group', 'equine-event-manager' ),
	'label_sub'    => __( 'Maximum riders one customer can register. Blank = unlimited.', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<input class="eem-field-input" name="en_reservation[group_riders_per_group]" id="en_group_riders_per_group" type="number" min="1" step="1" style="max-width:120px" value="%s" placeholder="%s" />',
		esc_attr( (string) ( $data['group_riders_per_group'] ?? '' ) ),
		esc_attr__( 'Unlimited', 'equine-event-manager' )
	),
) );

// 3.6 Grounds Fee toggle-label-row.
// C7.X.10 — converted from class-token controls (`eem-ctrl--grounds-amt`)
// to ID-based controls (`row-group-grounds-amt`). The C7.X.9 toggle
// handler routes through `eemApplyControlsById` which does
// `document.getElementById(id)` for each token in data-controls — class
// tokens were silently no-op'd, so Grounds Fee + Deposit toggles never
// hid their dependent rows. Group was the last partial on the retired
// class-token system; stall + rv + cancellation + fees converted in
// C7.X.4–C7.X.6.
ob_start();
eem_render_editor_toggle_label_row( array(
	'name'       => 'group_rider_grounds_fee_enabled',
	'subsection' => 'grounds-fee',
	'label'      => __( 'Charge a grounds fee for each rider', 'equine-event-manager' ),
	'is_enabled' => $grounds_on,
	'controls'   => array( 'row-group-grounds-amt' ),
) );
$grounds_toggle_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Grounds Fee', 'equine-event-manager' ),
	'control_html' => $grounds_toggle_html,
) );

// 3.7 Grounds Fee Amount (conditional)
eem_render_editor_field_row( array(
	'label'        => __( 'Grounds Fee Amount', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" name="en_reservation[group_rider_grounds_fee_amount]" id="en_group_rider_grounds_fee_amount" type="number" step="0.01" min="0" value="%s" /></div>',
		esc_attr( number_format( $grounds_amt, 2, '.', '' ) )
	),
	'row_id'       => 'row-group-grounds-amt',
	'is_hidden'    => ! $grounds_on,
) );

// 3.9 Deposit toggle-label-row (same C7.X.10 conversion as 3.6).
ob_start();
eem_render_editor_toggle_label_row( array(
	'name'       => 'group_rider_deposit_enabled',
	'subsection' => 'deposit',
	'label'      => __( 'Require a deposit for each rider', 'equine-event-manager' ),
	'is_enabled' => $deposit_on,
	'controls'   => array( 'row-group-deposit-amt' ),
) );
$deposit_toggle_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Deposit', 'equine-event-manager' ),
	'control_html' => $deposit_toggle_html,
) );

// 3.10 Deposit Amount (conditional)
eem_render_editor_field_row( array(
	'label'        => __( 'Deposit Amount', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" name="en_reservation[group_rider_deposit_amount]" id="en_group_rider_deposit_amount" type="number" step="0.01" min="0" value="%s" /></div>',
		esc_attr( number_format( $deposit_amt, 2, '.', '' ) )
	),
	'row_id'       => 'row-group-deposit-amt',
	'is_hidden'    => ! $deposit_on,
) );
