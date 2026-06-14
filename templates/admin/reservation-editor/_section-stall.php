<?php
/**
 * Reservation Editor — "Stall Reservations" section body
 * (C7.X.4 mockup-canonical rewrite).
 *
 * Mockup lines 475–647. Replaces the legacy table-form chrome with
 * mockup-canonical .eem-field-row grid + .eem-toggle-label-row +
 * .eem-stay-type-btn pair + .eem-price-wrap + ID-based data-controls.
 *
 * Field row IDs match the mockup so JS applyControls() shows/hides:
 *   row-stall-weekend-dates  (controlled by Weekend stay-type)
 *   row-stall-rate-nightly   (Nightly stay-type)
 *   row-stall-rate-weekend   (Weekend stay-type)
 *   row-stall-open           (Schedule toggle)
 *   row-stall-close          (Schedule toggle)
 *   row-stall-eb-cutoff      (Early Bird toggle)
 *   row-stall-eb-nightly     (Early Bird AND Nightly stay-type)
 *   row-stall-eb-weekend     (Early Bird AND Weekend stay-type)
 *   row-stall-shavings-qty   (Required Shavings toggle)
 *   row-stall-shavings-price (Required Shavings toggle)
 *
 * Closes with the .eem-layout-summary read-only widget (Stall Layout,
 * mockup line 624). "Manage Stall Layout" button stubs to C8.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed>  $data */
/** @var EEM_Reservations_CPT  $reservations_cpt */

require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-field-row.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-toggle-label-row.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-stay-type-pair.php';

// Format helpers
$fmt_dt = function ( $v ) {
	if ( '' === (string) $v ) return '';
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', (string) $v ) ) return substr( (string) $v, 0, 16 );
	$ts = strtotime( (string) $v );
	return $ts ? gmdate( 'Y-m-d\TH:i', $ts ) : '';
};
$fmt_money = function ( $v ) { return number_format( (float) $v, 2, '.', '' ); };

// Initial state
$nightly_on   = ! empty( $data['stall_nightly_enabled'] );
$weekend_on   = ! empty( $data['stall_weekend_enabled'] );
$weekly_on    = ! empty( $data['stall_weekly_enabled'] );
$schedule_on  = ! empty( $data['stall_schedule_enabled'] );
$eb_on        = ! empty( $data['stall_early_bird_enabled'] );
$shavings_on  = ! empty( $data['required_shavings_enabled'] );
// Tack Stall mode — 'customer' (on: buyers flag a tack stall at checkout, for
// the shavings exclusion) or 'off'. The actual tack assignment is done by the
// admin on the Stall Chart ("Mark as Tack Stall"). The legacy 'admin' value is
// treated as on. Control renders under Blocked Stall Numbers below.
$tack_mode = isset( $data['stall_tack_mode'] ) ? (string) $data['stall_tack_mode'] : 'customer';

?>
<input type="hidden" name="en_reservation[stalls_enabled]" data-eem-section-enabled="stall" value="<?php echo ! empty( $data['stalls_enabled'] ) ? '1' : '0'; ?>" />

<?php
// 1. Stall Description
eem_render_editor_field_row( array(
	'label'        => __( 'Stall Description', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<textarea class="eem-field-textarea" name="en_reservation[stall_description]" id="en_stall_description" rows="4">%s</textarea>',
		esc_textarea( (string) $data['stall_description'] )
	),
	'hint'         => __( 'Shown in the Stall Reservations section on the customer form.', 'equine-event-manager' ),
) );

// 2. Available Reservation Dates
eem_render_editor_field_row( array(
	'label'        => __( 'Available Reservation Dates', 'equine-event-manager' ),
	'label_sub'    => __( 'Bookable date window for stalls', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<div class="eem-date-range"><input class="eem-field-input" type="date" name="en_reservation[available_start_date]" value="%s" style="width:170px" /><span class="eem-date-sep">–</span><input class="eem-field-input" type="date" name="en_reservation[available_end_date]" value="%s" style="width:170px" /></div>',
		esc_attr( (string) $data['available_start_date'] ),
		esc_attr( (string) $data['available_end_date'] )
	),
) );

// 3. Stay Types pair
ob_start();
eem_render_editor_stay_type_pair( array(
	'group_label'      => __( 'Stall stay types', 'equine-event-manager' ),
	'group_slug'       => 'stall-stay',
	'nightly_name'     => 'stall_nightly_enabled',
	'nightly_label'    => __( 'Nightly', 'equine-event-manager' ),
	'nightly_on'       => $nightly_on,
	'nightly_controls' => array( 'row-stall-rate-nightly', 'row-stall-eb-nightly' ),
	'weekend_name'     => 'stall_weekend_enabled',
	'weekend_label'    => __( 'Weekend Rate', 'equine-event-manager' ),
	'weekend_on'       => $weekend_on,
	'weekend_controls' => array( 'row-stall-weekend-dates', 'row-stall-rate-weekend', 'row-stall-eb-weekend' ),
	'weekly_name'      => 'stall_weekly_enabled',
	'weekly_label'     => __( 'Weekly Rate', 'equine-event-manager' ),
	'weekly_on'        => $weekly_on,
	'weekly_controls'  => array( 'row-stall-weekly-dates', 'row-stall-rate-weekly', 'row-stall-eb-weekly' ),
) );
$stay_html = ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Stay Types', 'equine-event-manager' ),
	'label_sub'    => __( 'Enable one or both', 'equine-event-manager' ),
	'control_html' => $stay_html,
	'hint'         => __( 'Weekend Rate and Weekly Rate each use their own package dates configured below.', 'equine-event-manager' ),
) );

// 4. Weekend Package Dates (conditional)
eem_render_editor_field_row( array(
	'label'        => __( 'Weekend Package Dates', 'equine-event-manager' ),
	'row_id'       => 'row-stall-weekend-dates',
	'is_hidden'    => ! $weekend_on,
	'control_html' => sprintf(
		'<div class="eem-date-range"><span style="font-size:12px;color:#6B7A99;padding-top:9px">%s</span><input class="eem-field-input" type="date" name="en_reservation[stall_weekend_package_start_date]" value="%s" style="width:160px" /><span style="font-size:12px;color:#6B7A99;padding-top:9px">%s</span><input class="eem-field-input" type="date" name="en_reservation[stall_weekend_package_end_date]" value="%s" style="width:160px" /></div>',
		esc_html__( 'Start', 'equine-event-manager' ),
		esc_attr( (string) $data['stall_weekend_package_start_date'] ),
		esc_html__( 'End', 'equine-event-manager' ),
		esc_attr( (string) $data['stall_weekend_package_end_date'] )
	),
) );

// 4b. Weekly Package Dates (conditional)
eem_render_editor_field_row( array(
	'label'        => __( 'Weekly Package Dates', 'equine-event-manager' ),
	'row_id'       => 'row-stall-weekly-dates',
	'is_hidden'    => ! $weekly_on,
	'control_html' => sprintf(
		'<div class="eem-date-range"><span style="font-size:12px;color:#6B7A99;padding-top:9px">%s</span><input class="eem-field-input" type="date" name="en_reservation[stall_weekly_package_start_date]" value="%s" style="width:160px" /><span style="font-size:12px;color:#6B7A99;padding-top:9px">%s</span><input class="eem-field-input" type="date" name="en_reservation[stall_weekly_package_end_date]" value="%s" style="width:160px" /></div>',
		esc_html__( 'Start', 'equine-event-manager' ),
		esc_attr( (string) ( $data['stall_weekly_package_start_date'] ?? '' ) ),
		esc_html__( 'End', 'equine-event-manager' ),
		esc_attr( (string) ( $data['stall_weekly_package_end_date'] ?? '' ) )
	),
) );

// 5. Reservation Schedule toggle
ob_start();
eem_render_editor_toggle_label_row( array(
	'name'       => 'stall_schedule_enabled',
	'subsection' => 'stall-schedule',
	'label'      => __( 'Schedule Stall Reservations', 'equine-event-manager' ),
	'is_enabled' => $schedule_on,
	'controls'   => array( 'row-stall-open', 'row-stall-close' ),
) );
$sched_html = ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Reservation Schedule', 'equine-event-manager' ),
	'control_html' => $sched_html,
	'hint'         => __( 'Open and close stall reservations on specific dates and times.', 'equine-event-manager' ),
) );

// 6 + 7. Stalls Open / Close datetimes
eem_render_editor_field_row( array(
	'label'        => __( 'Stalls Open Date/Time', 'equine-event-manager' ),
	'row_id'       => 'row-stall-open',
	'is_hidden'    => ! $schedule_on,
	'control_html' => sprintf(
		'<input class="eem-field-input" type="datetime-local" name="en_reservation[stalls_open_at]" value="%s" style="max-width:260px" />',
		esc_attr( $fmt_dt( $data['stalls_open_at'] ) )
	),
) );
eem_render_editor_field_row( array(
	'label'        => __( 'Stalls Close Date/Time', 'equine-event-manager' ),
	'row_id'       => 'row-stall-close',
	'is_hidden'    => ! $schedule_on,
	'control_html' => sprintf(
		'<input class="eem-field-input" type="datetime-local" name="en_reservation[stalls_close_at]" value="%s" style="max-width:260px" />',
		esc_attr( $fmt_dt( $data['stalls_close_at'] ) )
	),
) );

// 9 + 10. Nightly + Weekend rates (conditional on stay-type)
// UX polish 2.3.23: rates + EB + shavings now appear before inventory controls
// so the admin's mental flow is: pricing → then "how many / which mode?".
eem_render_editor_field_row( array(
	'label'        => __( 'Stall Nightly Rate', 'equine-event-manager' ),
	'row_id'       => 'row-stall-rate-nightly',
	'is_hidden'    => ! $nightly_on,
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[stall_nightly_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['stall_nightly_rate'] ) )
	),
) );
eem_render_editor_field_row( array(
	'label'        => __( 'Stall Weekend Rate', 'equine-event-manager' ),
	'row_id'       => 'row-stall-rate-weekend',
	'is_hidden'    => ! $weekend_on,
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[stall_weekend_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['stall_weekend_rate'] ) )
	),
) );
eem_render_editor_field_row( array(
	'label'        => __( 'Stall Weekly Rate', 'equine-event-manager' ),
	'row_id'       => 'row-stall-rate-weekly',
	'is_hidden'    => ! $weekly_on,
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[stall_weekly_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['stall_weekly_rate'] ?? '0.00' ) )
	),
) );

// 11. Early Bird Pricing toggle
ob_start();
eem_render_editor_toggle_label_row( array(
	'name'       => 'stall_early_bird_enabled',
	'subsection' => 'stall-eb',
	'label'      => __( 'Enable stall early bird pricing', 'equine-event-manager' ),
	'is_enabled' => $eb_on,
	'controls'   => array( 'row-stall-eb-cutoff', 'row-stall-eb-nightly', 'row-stall-eb-weekend', 'row-stall-eb-weekly' ),
) );
$eb_html = ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Stall Early Bird Pricing', 'equine-event-manager' ),
	'control_html' => $eb_html,
) );

// 12 + 13 + 14. Early Bird cutoff + rates
eem_render_editor_field_row( array(
	'label'        => __( 'Early Bird Cutoff', 'equine-event-manager' ),
	'row_id'       => 'row-stall-eb-cutoff',
	'is_hidden'    => ! $eb_on,
	'control_html' => sprintf(
		'<input class="eem-field-input" type="datetime-local" name="en_reservation[stall_early_bird_cutoff]" value="%s" style="max-width:260px" />',
		esc_attr( $fmt_dt( $data['stall_early_bird_cutoff'] ) )
	),
) );
eem_render_editor_field_row( array(
	'label'        => __( 'Early Bird Nightly Rate', 'equine-event-manager' ),
	'row_id'       => 'row-stall-eb-nightly',
	'is_hidden'    => ! ( $eb_on && $nightly_on ),
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[stall_early_bird_nightly_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['stall_early_bird_nightly_rate'] ) )
	),
) );
eem_render_editor_field_row( array(
	'label'        => __( 'Early Bird Weekend Rate', 'equine-event-manager' ),
	'row_id'       => 'row-stall-eb-weekend',
	'is_hidden'    => ! ( $eb_on && $weekend_on ),
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[stall_early_bird_weekend_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['stall_early_bird_weekend_rate'] ) )
	),
) );
eem_render_editor_field_row( array(
	'label'        => __( 'Early Bird Weekly Rate', 'equine-event-manager' ),
	'row_id'       => 'row-stall-eb-weekly',
	'is_hidden'    => ! ( $eb_on && $weekly_on ),
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[stall_early_bird_weekly_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['stall_early_bird_weekly_rate'] ?? '0.00' ) )
	),
) );

// 15. Required Shavings toggle
ob_start();
eem_render_editor_toggle_label_row( array(
	'name'       => 'required_shavings_enabled',
	'subsection' => 'stall-shavings',
	'label'      => __( 'Require shavings with each stall', 'equine-event-manager' ),
	'is_enabled' => $shavings_on,
	'controls'   => array( 'row-stall-shavings-qty', 'row-stall-shavings-price' ),
) );
$shav_html = ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Required Shavings', 'equine-event-manager' ),
	'control_html' => $shav_html,
) );

// T1 — the Tack Stalls control moved DOWN to render under Blocked Stall Numbers
// (it governs which physical stall numbers are tack), so it is emitted later in
// this file rather than here next to Required Shavings.

// 16 + 17. Shavings qty + price
eem_render_editor_field_row( array(
	'label'        => __( 'Required Shavings Per Stall', 'equine-event-manager' ),
	'row_id'       => 'row-stall-shavings-qty',
	'is_hidden'    => ! $shavings_on,
	'control_html' => sprintf(
		'<input class="eem-field-input" type="number" min="0" step="1" name="en_reservation[required_shavings_per_stall]" value="%s" style="max-width:120px" />',
		esc_attr( (string) ( $data['required_shavings_per_stall'] ?? '0' ) )
	),
) );
eem_render_editor_field_row( array(
	'label'        => __( 'Shavings Price Per Bag', 'equine-event-manager' ),
	'row_id'       => 'row-stall-shavings-price',
	'is_hidden'    => ! $shavings_on,
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[required_shavings_price]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['required_shavings_price'] ) )
	),
) );

// Inventory Mode (C8) — UX polish 2.3.23: moved below pricing/shavings so the
// inventory cluster (Mode → Available qty → Max per customer → Row builder) appears
// as one tight group at the bottom of the section.
// Scenario B (V1 #4): two independent controls replace the single Bulk/Mapped
// toggle — Stall Inventory Type (quantity-only / numbered) + Customer Selection
// (quantity / pick-from-layout). The legacy hidden input is kept (JS-synced) as
// a backstop. $stall_is_numbered drives the inventory-input + Stall Row Builder
// visibility below (it replaces the old $stall_is_mapped).
$stall_inventory_type     = isset( $data['stall_inventory_type'] ) ? (string) $data['stall_inventory_type'] : 'quantity_only';
$stall_customer_selection = isset( $data['stall_customer_selection'] ) ? (string) $data['stall_customer_selection'] : 'quantity';
$stall_is_numbered        = ( 'numbered' === $stall_inventory_type );
$stall_is_pick            = ( 'pick_layout' === $stall_customer_selection );
$stall_is_mapped          = $stall_is_numbered; // alias used by the inventory-input + row-builder blocks below.
$stall_legacy_mode        = ( $stall_is_numbered && $stall_is_pick ) ? 'exact_map' : 'quantity';
// "Simple range" mode (Numbered + Quantity): customers never see the physical
// map, so the row builder collapses to plain First/Last ranges — no Layout
// dropdown, no back-to-back sides, no live preview. The full Stall Row Builder
// (layout + back-to-back + preview) only earns its complexity in pick-from-
// layout mode, where customers tap the actual map.
$stall_is_simple_range    = ( $stall_is_numbered && ! $stall_is_pick );

// v2 #5 — wrap the interdependent layout cluster (Inventory Type → Customer
// Selection → Available → Max → Rows → Blocked → Map) in a shaded panel so it
// reads as one group, mirroring the front-end "Pick Your Stalls" card.
echo '<div class="eem-layout-group">';

// ── Control 1: Stall Inventory Type ──
ob_start();
?>
<div class="eem-mode-btns">
	<button type="button"
		class="eem-mode-btn<?php echo $stall_is_numbered ? '' : ' active'; ?>"
		data-type="quantity_only"
		data-eem-action="toggle-stall-inventory-type">
		<?php esc_html_e( 'Quantity-only', 'equine-event-manager' ); ?>
	</button>
	<button type="button"
		class="eem-mode-btn<?php echo $stall_is_numbered ? ' active' : ''; ?>"
		data-type="numbered"
		data-eem-action="toggle-stall-inventory-type">
		<?php esc_html_e( 'Numbered', 'equine-event-manager' ); ?>
	</button>
</div>
<input type="hidden" name="stall_inventory_type" id="eem-stall-inventory-type-input" value="<?php echo esc_attr( $stall_inventory_type ); ?>">
<span class="eem-field-hint eem-stall-inventory-type-hint"><?php
	echo esc_html( $stall_is_numbered
		? __( 'Specific stall numbers exist — define them in the Stall Row Builder below.', 'equine-event-manager' )
		: __( 'Sell a total count with no specific stall identities.', 'equine-event-manager' ) );
?></span>
<?php
$type_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Stall Inventory Type', 'equine-event-manager' ),
	'label_sub'    => __( 'Do specific numbered stalls exist, or just a count?', 'equine-event-manager' ),
	'row_id'       => 'eem-row-stall-inventory-type',
	'control_html' => $type_html,
) );

// ── Control 2: Customer Selection ──
ob_start();
?>
<div class="eem-mode-btns">
	<button type="button"
		class="eem-mode-btn<?php echo $stall_is_pick ? '' : ' active'; ?>"
		data-selection="quantity"
		data-eem-action="toggle-stall-customer-selection">
		<?php esc_html_e( 'Quantity', 'equine-event-manager' ); ?>
	</button>
	<button type="button"
		class="eem-mode-btn<?php echo $stall_is_pick ? ' active' : ''; ?><?php echo $stall_is_numbered ? '' : ' is-disabled'; ?>"
		data-selection="pick_layout"
		data-eem-action="toggle-stall-customer-selection"
		<?php disabled( ! $stall_is_numbered ); ?>>
		<?php esc_html_e( 'Pick from layout', 'equine-event-manager' ); ?>
	</button>
</div>
<input type="hidden" name="stall_customer_selection" id="eem-stall-customer-selection-input" value="<?php echo esc_attr( $stall_customer_selection ); ?>">
<input type="hidden" name="stall_selection_mode" id="eem-stall-selection-mode-input" value="<?php echo esc_attr( $stall_legacy_mode ); ?>">
<span class="eem-field-hint eem-stall-customer-selection-hint"><?php
	echo esc_html( $stall_is_pick
		? __( 'Customers select specific stalls from your layout at checkout.', 'equine-event-manager' )
		: __( 'Customers pick how many stalls they need; you assign specific stalls on the Stall & RV Charts page.', 'equine-event-manager' ) );
?></span>
<?php
$sel_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Customer Selection', 'equine-event-manager' ),
	'label_sub'    => __( 'How do customers choose stalls at checkout?', 'equine-event-manager' ),
	'row_id'       => 'eem-row-stall-customer-selection',
	'control_html' => $sel_html,
) );

// Available Stall Inventory (dual-state: editable in Bulk mode, computed in Mapped mode)
ob_start();
?>
<input type="number"
	name="en_reservation[stall_inventory]"
	id="eem-stall-inventory-input"
	class="eem-field-input"
	value="<?php echo esc_attr( (string) ( $data['stall_inventory'] ?? '' ) ); ?>"
	placeholder="<?php esc_attr_e( 'Unlimited', 'equine-event-manager' ); ?>"
	min="0"
	style="<?php echo $stall_is_mapped ? 'display:none;' : ''; echo 'max-width:140px;'; ?>">
<div class="eem-inventory-computed-wrap"
	id="eem-stall-inventory-computed"
	style="<?php echo $stall_is_mapped ? '' : 'display:none;'; ?>">
	<span class="eem-inventory-computed-number" id="eem-stall-inventory-number">0</span>
	<span class="eem-inventory-computed-label" id="eem-stall-inventory-label">
		<?php esc_html_e( '(computed from barn/row quantities)', 'equine-event-manager' ); ?>
	</span>
</div>
<?php
$inv_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Available Stall Inventory', 'equine-event-manager' ),
	'label_sub'    => __( 'Blank = unlimited', 'equine-event-manager' ),
	'control_html' => $inv_html,
	'hint'         => __( 'Once inventory reaches zero, customers see a sold-out message.', 'equine-event-manager' ),
) );

// Max Stalls Per Customer (per-customer purchase limit)
eem_render_editor_field_row( array(
	'label'        => __( 'Max Stalls Per Customer', 'equine-event-manager' ),
	'label_sub'    => __( 'Blank = unlimited', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<input class="eem-field-input" type="number" min="1" step="1" name="eem_stall_max_per_customer" id="eem-stall-max-per-customer" value="%s" placeholder="%s" style="max-width:140px;" />',
		esc_attr( (string) ( $data['stall_max_per_customer'] ?? '' ) ),
		esc_attr__( 'Unlimited', 'equine-event-manager' )
	),
	'hint'         => __( 'Limits how many stalls a single customer can reserve. Enforced at checkout.', 'equine-event-manager' ),
) );

// ── Tack Stalls (On/Off) — order: after Max Stalls Per Customer, before the map ──
$tack_on  = ( 'off' !== $tack_mode );
$tack_who = ( 'admin' === $tack_mode ) ? 'admin' : 'customer';
$tack_val = $tack_on ? $tack_who : 'off';
ob_start();
?>
<div class="eem-tack-control" data-eem-tack-control>
	<div class="eem-mode-btns" data-eem-tack-onoff>
		<button type="button" class="eem-mode-btn<?php echo $tack_on ? '' : ' active'; ?>" data-tack-onoff="off" data-eem-action="tack-onoff"><?php esc_html_e( 'Off', 'equine-event-manager' ); ?></button>
		<button type="button" class="eem-mode-btn<?php echo $tack_on ? ' active' : ''; ?>" data-tack-onoff="on" data-eem-action="tack-onoff"><?php esc_html_e( 'On', 'equine-event-manager' ); ?></button>
	</div>
	<div class="eem-tack-who-row" data-eem-tack-who-row<?php echo $tack_on ? '' : ' hidden'; ?>>
		<span class="eem-field-sublabel"><?php esc_html_e( 'Who designates the tack stall?', 'equine-event-manager' ); ?></span>
		<div class="eem-mode-btns" data-eem-tack-who>
			<button type="button" class="eem-mode-btn<?php echo 'customer' === $tack_who ? ' active' : ''; ?>" data-tack-who="customer" data-eem-action="tack-who"><?php esc_html_e( 'Customer', 'equine-event-manager' ); ?></button>
			<button type="button" class="eem-mode-btn<?php echo 'admin' === $tack_who ? ' active' : ''; ?>" data-tack-who="admin" data-eem-action="tack-who"><?php esc_html_e( 'Admin only', 'equine-event-manager' ); ?></button>
		</div>
	</div>
</div>
<input type="hidden" name="en_reservation[stall_tack_mode]" id="eem-stall-tack-mode-input" value="<?php echo esc_attr( $tack_val ); ?>">
<span class="eem-field-hint"><?php esc_html_e( 'When on, a tack stall is excluded from required shavings. "Customer" lets buyers flag their own tack stall at checkout; "Admin only" hides that from checkout and you mark the tack stall on the Stall Chart. Either way you can assign or override it on the Stall Chart ("Mark as Tack Stall").', 'equine-event-manager' ); ?></span>
<?php
$tack_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Tack Stalls', 'equine-event-manager' ),
	'label_sub'    => __( 'Excluded from required shavings', 'equine-event-manager' ),
	'row_id'       => 'row-stall-tack',
	'control_html' => $tack_html,
) );

// 18. Stall Row Builder — inside mapped-content wrapper (C8)
// Load meta from $data (pre-populated by get_meta_values()) or fall back to 3 seeded rows.
// NOTE: use $data, NOT a direct post-meta call with get_the_ID() — on custom admin pages
// (admin.php?page=...) the global $post is not set by WordPress, so that function returns 0.
// 2.3.82: seed rows removed — a new reservation starts with an empty Stall Row
// Builder. Admins add their own rows.
$stall_rows_meta = isset( $data['stall_rows'] ) ? $data['stall_rows'] : array();
$stall_rows      = ( is_array( $stall_rows_meta ) && ! empty( $stall_rows_meta ) )
	? $stall_rows_meta
	: array();

$blocked_stalls_meta = isset( $data['blocked_stalls'] ) ? $data['blocked_stalls'] : array();
$blocked_stalls      = is_array( $blocked_stalls_meta ) ? $blocked_stalls_meta : array();

$stall_map_id = (int) ( isset( $data['stall_map_id'] ) ? $data['stall_map_id'] : 0 );
$stall_map_url  = $stall_map_id ? wp_get_attachment_url( $stall_map_id ) : '';
$stall_map_name = $stall_map_id ? basename( get_attached_file( $stall_map_id ) ) : '';
?>
<div id="eem-stall-mapped-content"
	style="<?php echo $stall_is_mapped ? '' : 'display:none;'; ?>">
<?php

// ── Stall Rows field-row ──
ob_start();
?>
<div class="eem-row-builder-summary" style="margin-bottom:10px" id="eem-stall-row-summary"></div>
<?php // Sentinel: always posted when the row builder is on the page, so the save
// handler can tell "all rows deleted" (clear to empty) apart from "section not
// rendered" (leave rows untouched). Without it, deleting every row no-ops. ?>
<input type="hidden" name="eem_stall_rows_present" value="1">
<div class="eem-row-builder<?php echo $stall_is_simple_range ? ' eem-stall-rows--simple' : ''; ?>" id="eem-stall-row-builder-list">
<?php foreach ( $stall_rows as $ri => $row ) :
	$r_name      = isset( $row['name'] )      ? (string) $row['name']      : '';
	$r_layout    = isset( $row['layout'] )     ? (string) $row['layout']    : 'one-sided';
	$r_first     = isset( $row['first'] )      ? (string) $row['first']     : '';
	$r_last      = isset( $row['last'] )       ? (string) $row['last']      : '';
	$r_top_first = isset( $row['top_first'] )  ? (string) $row['top_first'] : '';
	$r_top_last  = isset( $row['top_last'] )   ? (string) $row['top_last']  : '';
	$r_bot_first = isset( $row['bot_first'] )  ? (string) $row['bot_first'] : '';
	$r_bot_last  = isset( $row['bot_last'] )   ? (string) $row['bot_last']  : '';
	$is_b2b      = ( 'back-to-back' === $r_layout );
	?>
	<div class="eem-row-card" data-row-index="<?php echo (int) $ri; ?>">
		<input type="hidden" name="eem_stall_rows[<?php echo (int) $ri; ?>][layout]" value="one-sided">
		<div class="eem-row-card-line">
			<div class="eem-row-card-field eem-row-card-field--name">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'Barn/Row Name', 'equine-event-manager' ); ?> <span class="eem-row-card-optional"><?php esc_html_e( '(optional)', 'equine-event-manager' ); ?></span></span>
				<input type="text" name="eem_stall_rows[<?php echo (int) $ri; ?>][name]" value="<?php echo esc_attr( $r_name ); ?>" placeholder="<?php esc_attr_e( 'Leave blank to number stalls only', 'equine-event-manager' ); ?>" data-eem-input-action="stall-row-input">
			</div>
			<div class="eem-row-card-field">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'First Stall', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_stall_rows[<?php echo (int) $ri; ?>][first]" value="<?php echo esc_attr( $r_first ); ?>" data-role="first" data-eem-input-action="stall-row-input">
			</div>
			<div class="eem-row-card-field">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'Last Stall', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_stall_rows[<?php echo (int) $ri; ?>][last]" value="<?php echo esc_attr( $r_last ); ?>" data-role="last" data-eem-input-action="stall-row-input">
			</div>
			<button class="eem-row-card-delete" type="button" title="<?php esc_attr_e( 'Delete row', 'equine-event-manager' ); ?>" data-eem-action="stall-delete-row">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
			</button>
		</div>
		<div class="eem-row-card-preview">
			<div class="eem-row-card-preview-label"><?php esc_html_e( 'Preview', 'equine-event-manager' ); ?> <span class="eem-row-card-count"></span></div>
			<div class="eem-stall-row-layout"></div>
		</div>
	</div>
<?php endforeach; ?>
</div>
<button class="eem-row-add-btn" type="button" data-eem-action="stall-add-row">
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
	<?php echo esc_html( $stall_is_simple_range ? __( 'Add Range', 'equine-event-manager' ) : __( 'Add Row', 'equine-event-manager' ) ); ?>
</button>
<span class="eem-field-hint" id="eem-stall-rows-hint"
	data-hint-simple="<?php echo esc_attr__( 'Each range is a block of consecutive stall numbers (e.g. 100–111 or Y1–Y12). Add one range per barn or block. You assign specific stalls to customers on the Stall & RV Charts page.', 'equine-event-manager' ); ?>"
	data-hint-full="<?php echo esc_attr( __( 'Add one barn block per group of stalls — a Barn Name and its first/last stall numbers (e.g. 100–111 or Y1–Y12). These show customers which stall numbers are available; they are not a map of the facility.', 'equine-event-manager' ) ); ?>"><?php
	if ( $stall_is_simple_range ) {
		echo esc_html__( 'Each range is a block of consecutive stall numbers (e.g. 100–111 or Y1–Y12). Add one range per barn or block. You assign specific stalls to customers on the Stall & RV Charts page.', 'equine-event-manager' );
	} else {
		echo esc_html__( 'Add one barn block per group of stalls — a Barn Name and its first/last stall numbers (e.g. 100–111 or Y1–Y12). These show customers which stall numbers are available; they are not a map of the facility.', 'equine-event-manager' );
	}
?></span>
<?php
$stall_rows_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => $stall_is_simple_range ? __( 'Stall Number Ranges', 'equine-event-manager' ) : __( 'Stall Rows', 'equine-event-manager' ),
	'label_sub'    => $stall_is_simple_range ? __( 'Which stall numbers exist', 'equine-event-manager' ) : __( 'Which stall numbers are available', 'equine-event-manager' ),
	'row_id'       => 'row-stall-blocks',
	'control_html' => $stall_rows_html,
	// v4 Slice 5: under Pick-from-layout the connected map IS the layout, so the
	// row builder hides; it stays for Numbered + Quantity (admin still numbers
	// stalls for the chart). JS applyStallLayoutSource() keeps this in sync.
	'is_hidden'    => $stall_is_pick,
) );

// v2 Venues Slice 3 — Save Layout / Load Layout to the reservation's venue.
$context = 'stall';
require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_layout-template-bar.php';

// ── v4 Stall Mapping — native Map Builder (drives "Pick from layout") ──
$stall_map_snap  = ( isset( $data['stall_map'] ) && is_array( $data['stall_map'] ) ) ? $data['stall_map'] : array();
$stall_map_kind  = EEM_Stall_Map_Importer::snapshot_of_kind( $stall_map_snap, 'stall' );
$stall_seed      = array();
foreach ( ( $stall_map_kind['barns'] ?? array() ) as $smk_barn ) {
	$stall_seed[] = array( 'name' => (string) ( $smk_barn['name'] ?? '' ), 'grid' => ( $smk_barn['grid'] ?? array() ) );
}
ob_start();
?>
<div class="eem-stall-map-connect" data-eem-stall-map data-eem-stall-map-total="<?php echo (int) ( ! empty( $stall_map_kind['barns'] ) ? EEM_Stall_Map_Importer::count_stalls( $stall_map_kind ) : 0 ); ?>">
	<div class="eem-stall-map-row">
		<button type="button" class="eem-btn-add" data-eem-action="open-map-builder" data-target="stall"><?php echo ! empty( $stall_map_kind['barns'] ) ? esc_html__( 'Edit Map', 'equine-event-manager' ) : esc_html__( 'Build Map', 'equine-event-manager' ); ?></button>
	</div>
	<div class="eem-stall-map-status" data-eem-stall-map-status>
		<?php
		if ( ! empty( $stall_map_kind['barns'] ) ) {
			$smc_counts = EEM_Stall_Map_Importer::barn_stall_counts( $stall_map_kind );
			$smc_total  = EEM_Stall_Map_Importer::count_stalls( $stall_map_kind );
			$smc_bits   = array();
			foreach ( $smc_counts as $smc_bn => $smc_bc ) {
				$smc_bits[] = esc_html( $smc_bn ) . ' (' . (int) $smc_bc . ')';
			}
			echo '<span class="eem-stall-map-ok">&#x2713; ' . esc_html( sprintf( /* translators: %d: barn count */ _n( '%d barn', '%d barns', count( $smc_counts ), 'equine-event-manager' ), count( $smc_counts ) ) ) . ' &middot; ' . (int) $smc_total . ' ' . esc_html__( 'stalls total', 'equine-event-manager' ) . '</span> ';
			echo '<span class="eem-stall-map-barns">' . implode( ', ', $smc_bits ) . '</span>'; // phpcs:ignore -- bits pre-escaped
		}
		?>
	</div>
	<script type="application/json" id="eem-map-seed-stall"><?php echo wp_json_encode( $stall_seed ); // phpcs:ignore -- JSON seed for the Map Builder ?></script>
	<div class="eem-mb-inline-host" data-eem-map-host="stall"></div>
</div>
<span class="eem-field-hint"><?php esc_html_e( 'Used when Customer Selection is “Pick from layout”. Click Build Map to draw your facility right here in this card — add a tab per barn, then drag to number the stalls. No spreadsheet required.', 'equine-event-manager' ); ?></span>
<?php
$stall_map_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Interactive Stall Map', 'equine-event-manager' ),
	'label_sub'    => __( 'Draw your facility — no spreadsheet', 'equine-event-manager' ),
	'row_id'       => 'row-stall-map-connect',
	'control_html' => $stall_map_html,
	// v4 Slice 5: the map connection is the layout source for Pick-from-layout,
	// so it shows only in that mode (and is required to publish).
	'is_hidden'    => ! $stall_is_pick,
) );

// ── Blocked Stall Numbers tag-select ──
ob_start();
?>
<div class="eem-tag-select" id="eem-blocked-stalls-select">
	<div class="eem-tag-select-input" data-eem-action="tag-open">
		<?php foreach ( $blocked_stalls as $bs_val ) : ?>
		<span class="eem-tag-chip" data-value="<?php echo esc_attr( (string) $bs_val ); ?>">
			<?php echo esc_html( (string) $bs_val ); ?>
			<button type="button" class="eem-tag-chip-remove" data-eem-action="tag-remove" aria-label="<?php esc_attr_e( 'Remove', 'equine-event-manager' ); ?>">&#xd7;</button>
			<input type="hidden" name="eem_blocked_stalls[]" value="<?php echo esc_attr( (string) $bs_val ); ?>">
		</span>
		<?php endforeach; ?>
		<input class="eem-tag-search" type="text" placeholder="<?php esc_attr_e( 'Type a stall number…', 'equine-event-manager' ); ?>" data-eem-input-action="tag-search" data-eem-tag-target="eem-blocked-stalls-select">
	</div>
	<div class="eem-tag-dropdown" id="eem-blocked-stalls-dropdown">
		<div class="eem-tag-dropdown-empty" style="display:none"><?php esc_html_e( 'No matching stall numbers.', 'equine-event-manager' ); ?></div>
	</div>
</div>
<span class="eem-field-hint"><?php esc_html_e( 'Type a stall number to filter, then click to block it. Click × on a chip to unblock.', 'equine-event-manager' ); ?></span>
<?php
$blocked_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Blocked Stall Numbers', 'equine-event-manager' ),
	'label_sub'    => __( 'Hold back from reservation', 'equine-event-manager' ),
	'row_id'       => 'row-stall-blocked',
	'control_html' => $blocked_html,
) );

// ── Stall Map file upload ──
ob_start();
?>
<div class="eem-file-row">
	<span class="eem-file-name" id="eem-stall-map-name"><?php echo $stall_map_name ? esc_html( $stall_map_name ) : ''; ?></span>
	<button class="eem-btn-upload" type="button" data-eem-action="stall-map-upload"><?php esc_html_e( 'Upload File', 'equine-event-manager' ); ?></button>
	<button class="eem-btn-file-del" type="button" aria-label="<?php esc_attr_e( 'Remove file', 'equine-event-manager' ); ?>" data-eem-action="stall-map-remove"<?php echo $stall_map_id ? '' : ' style="display:none"'; ?>>
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
	</button>
	<?php if ( $stall_map_url ) : ?>
		<a class="eem-view-link" href="<?php echo esc_url( $stall_map_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View file', 'equine-event-manager' ); ?></a>
	<?php endif; ?>
	<input type="hidden" name="eem_stall_map_id" id="eem-stall-map-id" value="<?php echo esc_attr( (string) $stall_map_id ); ?>">
</div>
<span class="eem-field-hint"><?php esc_html_e( 'Upload a stall map customers can open in a new tab while choosing a stall.', 'equine-event-manager' ); ?></span>
<?php
$map_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'View Only Stall Map', 'equine-event-manager' ),
	'label_sub'    => __( 'PDF or image customers can open', 'equine-event-manager' ),
	'row_id'       => 'row-stall-map',
	'control_html' => $map_html,
) );
echo '</div>'; // .eem-layout-group
?>
</div>
