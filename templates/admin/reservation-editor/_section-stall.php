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
$schedule_on  = ! empty( $data['stall_schedule_enabled'] );
$eb_on        = ! empty( $data['stall_early_bird_enabled'] );
$shavings_on  = ! empty( $data['required_shavings_enabled'] );

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
) );
$stay_html = ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Stay Types', 'equine-event-manager' ),
	'label_sub'    => __( 'Enable one or both', 'equine-event-manager' ),
	'control_html' => $stay_html,
	'hint'         => __( 'Weekend Rate uses the stall weekend package dates configured below.', 'equine-event-manager' ),
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

// 11. Early Bird Pricing toggle
ob_start();
eem_render_editor_toggle_label_row( array(
	'name'       => 'stall_early_bird_enabled',
	'subsection' => 'stall-eb',
	'label'      => __( 'Enable stall early bird pricing', 'equine-event-manager' ),
	'is_enabled' => $eb_on,
	'controls'   => array( 'row-stall-eb-cutoff', 'row-stall-eb-nightly', 'row-stall-eb-weekend' ),
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
		<?php esc_html_e( '(computed from row quantities)', 'equine-event-manager' ); ?>
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
<div class="eem-row-builder" id="eem-stall-row-builder-list">
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
		<div class="eem-row-card-top">
			<div class="eem-row-card-field">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'Row Name', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_stall_rows[<?php echo (int) $ri; ?>][name]" value="<?php echo esc_attr( $r_name ); ?>" data-eem-input-action="stall-row-input">
			</div>
			<div class="eem-row-card-field eem-row-card-field-layout">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'Layout', 'equine-event-manager' ); ?></span>
				<select name="eem_stall_rows[<?php echo (int) $ri; ?>][layout]" data-eem-input-action="stall-row-layout">
					<option value="one-sided"<?php selected( $r_layout, 'one-sided' ); ?>><?php esc_html_e( 'One-sided', 'equine-event-manager' ); ?></option>
					<option value="back-to-back"<?php selected( $r_layout, 'back-to-back' ); ?>><?php esc_html_e( 'Back-to-back', 'equine-event-manager' ); ?></option>
				</select>
			</div>
			<button class="eem-row-card-delete" type="button" title="<?php esc_attr_e( 'Delete row', 'equine-event-manager' ); ?>" data-eem-action="stall-delete-row">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
			</button>
		</div>
		<div class="eem-row-card-one-sided"<?php echo $is_b2b ? ' style="display:none"' : ''; ?>>
			<div class="eem-row-card-field">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'First Stall Label', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_stall_rows[<?php echo (int) $ri; ?>][first]" value="<?php echo esc_attr( $r_first ); ?>" data-role="first" data-eem-input-action="stall-row-input">
			</div>
			<div class="eem-row-card-field">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'Last Stall Label', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_stall_rows[<?php echo (int) $ri; ?>][last]" value="<?php echo esc_attr( $r_last ); ?>" data-role="last" data-eem-input-action="stall-row-input">
			</div>
		</div>
		<div class="eem-row-card-sides"<?php echo $is_b2b ? '' : ' style="display:none"'; ?>>
			<div class="eem-side-block">
				<div class="eem-side-block-label"><?php esc_html_e( 'Top Side', 'equine-event-manager' ); ?></div>
				<div class="eem-side-block-row">
					<div class="eem-row-card-field"><span class="eem-row-card-field-label"><?php esc_html_e( 'First', 'equine-event-manager' ); ?></span><input type="text" name="eem_stall_rows[<?php echo (int) $ri; ?>][top_first]" value="<?php echo esc_attr( $r_top_first ); ?>" data-role="top-first" data-eem-input-action="stall-row-input"></div>
					<div class="eem-row-card-field"><span class="eem-row-card-field-label"><?php esc_html_e( 'Last', 'equine-event-manager' ); ?></span><input type="text" name="eem_stall_rows[<?php echo (int) $ri; ?>][top_last]" value="<?php echo esc_attr( $r_top_last ); ?>" data-role="top-last" data-eem-input-action="stall-row-input"></div>
				</div>
			</div>
			<div class="eem-side-block">
				<div class="eem-side-block-label"><?php esc_html_e( 'Bottom Side', 'equine-event-manager' ); ?></div>
				<div class="eem-side-block-row">
					<div class="eem-row-card-field"><span class="eem-row-card-field-label"><?php esc_html_e( 'First', 'equine-event-manager' ); ?></span><input type="text" name="eem_stall_rows[<?php echo (int) $ri; ?>][bot_first]" value="<?php echo esc_attr( $r_bot_first ); ?>" data-role="bot-first" data-eem-input-action="stall-row-input"></div>
					<div class="eem-row-card-field"><span class="eem-row-card-field-label"><?php esc_html_e( 'Last', 'equine-event-manager' ); ?></span><input type="text" name="eem_stall_rows[<?php echo (int) $ri; ?>][bot_last]" value="<?php echo esc_attr( $r_bot_last ); ?>" data-role="bot-last" data-eem-input-action="stall-row-input"></div>
				</div>
			</div>
		</div>
		<div>
			<div class="eem-row-card-preview-label"><?php esc_html_e( 'Preview', 'equine-event-manager' ); ?> <span class="eem-row-card-count"></span></div>
			<div class="eem-stall-row-layout<?php echo $is_b2b ? ' eem-back-to-back' : ''; ?>"></div>
		</div>
	</div>
<?php endforeach; ?>
</div>
<button class="eem-row-add-btn" type="button" data-eem-action="stall-add-row">
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
	<?php esc_html_e( 'Add Row', 'equine-event-manager' ); ?>
</button>
<span class="eem-field-hint"><?php echo wp_kses( __( 'Each row appears as a horizontal strip of stall boxes. <strong>One-sided</strong>: single row of stalls. <strong>Back-to-back</strong>: two rows separated by an aisle. Stall labels can be numbers (100–111) or strings (Y1–Y12).', 'equine-event-manager' ), array( 'strong' => array() ) ); ?></span>
<?php
$stall_rows_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Stall Rows', 'equine-event-manager' ),
	'label_sub'    => __( 'Define the physical layout customers will see', 'equine-event-manager' ),
	'row_id'       => 'row-stall-blocks',
	'control_html' => $stall_rows_html,
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
	'label'        => __( 'Stall Map', 'equine-event-manager' ),
	'label_sub'    => __( 'PDF or image customers can open', 'equine-event-manager' ),
	'row_id'       => 'row-stall-map',
	'control_html' => $map_html,
) );
?>
</div>
