<?php
/**
 * Reservation Editor — "RV Reservations" section body
 * (C7.X.4 mockup-canonical rewrite).
 *
 * Mockup lines 649–887. Replaces the legacy table-form chrome with
 * mockup-canonical .eem-field-row + .eem-toggle-label-row +
 * .eem-stay-type-btn pair + .eem-zone-row repeating-row builder
 * (NEW _eem_rv_lot_zones meta) + .eem-repeat-table RV add-ons +
 * .eem-layout-summary widget.
 *
 * Field row IDs match mockup:
 *   row-rv-weekend-dates / row-rv-rate-nightly / row-rv-rate-weekend
 *   row-rv-open / row-rv-close
 *   row-rv-eb-cutoff / row-rv-eb-nightly / row-rv-eb-weekend
 *   rv-addons-table-wrap (controlled by RV Add-Ons master toggle)
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed>  $data */

require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-field-row.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-toggle-label-row.php';
require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-stay-type-pair.php';

$fmt_dt    = function ( $v ) { if ( '' === (string) $v ) return ''; if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', (string) $v ) ) return substr( (string) $v, 0, 16 ); $ts = strtotime( (string) $v ); return $ts ? gmdate( 'Y-m-d\TH:i', $ts ) : ''; };
$fmt_money = function ( $v ) { return number_format( (float) $v, 2, '.', '' ); };

$nightly_on   = ! empty( $data['rv_nightly_enabled'] );
$weekend_on   = ! empty( $data['rv_weekend_enabled'] );
$schedule_on  = ! empty( $data['rv_schedule_enabled'] );
$eb_on        = ! empty( $data['rv_early_bird_enabled'] );
$rv_addons_on = ! empty( $data['rv_addons_enabled'] );

$rv_addons = isset( $data['rv_addons'] ) ? (array) $data['rv_addons'] : array();

?>
<input type="hidden" name="en_reservation[rv_enabled]" data-eem-section-enabled="rv" value="<?php echo ! empty( $data['rv_enabled'] ) ? '1' : '0'; ?>" />

<?php
// 1. RV Description
eem_render_editor_field_row( array(
	'label'        => __( 'RV Description', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<textarea class="eem-field-textarea" name="en_reservation[rv_description]" id="en_rv_description" rows="4">%s</textarea>',
		esc_textarea( (string) $data['rv_description'] )
	),
	'hint'         => __( 'Shown in the RV Reservations section on the customer form.', 'equine-event-manager' ),
) );

// 2. Available Reservation Dates
eem_render_editor_field_row( array(
	'label'        => __( 'Available Reservation Dates', 'equine-event-manager' ),
	'label_sub'    => __( 'Bookable date window for RV lots', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<div class="eem-date-range"><input class="eem-field-input" type="date" name="en_reservation[available_start_date]" value="%s" style="width:170px" /><span class="eem-date-sep">–</span><input class="eem-field-input" type="date" name="en_reservation[available_end_date]" value="%s" style="width:170px" /></div>',
		esc_attr( (string) $data['available_start_date'] ),
		esc_attr( (string) $data['available_end_date'] )
	),
) );

// 3. Stay Types
ob_start();
eem_render_editor_stay_type_pair( array(
	'group_label'      => __( 'RV stay types', 'equine-event-manager' ),
	'group_slug'       => 'rv-stay',
	'nightly_name'     => 'rv_nightly_enabled',
	'nightly_on'       => $nightly_on,
	'nightly_controls' => array( 'row-rv-rate-nightly', 'row-rv-eb-nightly' ),
	'weekend_name'     => 'rv_weekend_enabled',
	'weekend_on'       => $weekend_on,
	'weekend_controls' => array( 'row-rv-weekend-dates', 'row-rv-rate-weekend', 'row-rv-eb-weekend' ),
) );
$stay = ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Stay Types', 'equine-event-manager' ),
	'label_sub'    => __( 'Enable one or both', 'equine-event-manager' ),
	'control_html' => $stay,
	'hint'         => __( 'Weekend Rate uses the RV weekend package dates configured below.', 'equine-event-manager' ),
) );

// 4. Weekend Package Dates
eem_render_editor_field_row( array(
	'label'        => __( 'Weekend Package Dates', 'equine-event-manager' ),
	'row_id'       => 'row-rv-weekend-dates',
	'is_hidden'    => ! $weekend_on,
	'control_html' => sprintf(
		'<div class="eem-date-range"><span style="font-size:12px;color:#6B7A99;padding-top:9px">%s</span><input class="eem-field-input" type="date" name="en_reservation[rv_weekend_package_start_date]" value="%s" style="width:160px" /><span style="font-size:12px;color:#6B7A99;padding-top:9px">%s</span><input class="eem-field-input" type="date" name="en_reservation[rv_weekend_package_end_date]" value="%s" style="width:160px" /></div>',
		esc_html__( 'Start', 'equine-event-manager' ),
		esc_attr( (string) $data['rv_weekend_package_start_date'] ),
		esc_html__( 'End', 'equine-event-manager' ),
		esc_attr( (string) $data['rv_weekend_package_end_date'] )
	),
) );

// 5. Reservation Schedule toggle
ob_start();
eem_render_editor_toggle_label_row( array(
	'name'       => 'rv_schedule_enabled',
	'subsection' => 'rv-schedule',
	'label'      => __( 'Schedule RV Reservations', 'equine-event-manager' ),
	'is_enabled' => $schedule_on,
	'controls'   => array( 'row-rv-open', 'row-rv-close' ),
) );
$sched = ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Reservation Schedule', 'equine-event-manager' ),
	'control_html' => $sched,
	'hint'         => __( 'Open and close RV reservations on specific dates and times.', 'equine-event-manager' ),
) );

// 6 + 7. Open / Close
eem_render_editor_field_row( array(
	'label'        => __( 'RV Open Date/Time', 'equine-event-manager' ),
	'row_id'       => 'row-rv-open',
	'is_hidden'    => ! $schedule_on,
	'control_html' => sprintf(
		'<input class="eem-field-input" type="datetime-local" name="en_reservation[rv_open_at]" value="%s" style="max-width:260px" />',
		esc_attr( $fmt_dt( $data['rv_open_at'] ) )
	),
) );
eem_render_editor_field_row( array(
	'label'        => __( 'RV Close Date/Time', 'equine-event-manager' ),
	'row_id'       => 'row-rv-close',
	'is_hidden'    => ! $schedule_on,
	'control_html' => sprintf(
		'<input class="eem-field-input" type="datetime-local" name="en_reservation[rv_close_at]" value="%s" style="max-width:260px" />',
		esc_attr( $fmt_dt( $data['rv_close_at'] ) )
	),
) );

// Inventory Mode (C8) — inserted before Available RV Inventory
$rv_selection_mode = isset( $data['rv_selection_mode'] ) ? (string) $data['rv_selection_mode'] : 'quantity';
$rv_is_mapped      = ( 'exact_map' === $rv_selection_mode );
ob_start();
?>
<div class="eem-mode-btns">
	<button type="button"
		class="eem-mode-btn<?php echo $rv_is_mapped ? '' : ' active'; ?>"
		data-mode="bulk"
		data-section="rv"
		data-eem-action="toggle-inventory-mode">
		<?php esc_html_e( 'Bulk', 'equine-event-manager' ); ?>
	</button>
	<button type="button"
		class="eem-mode-btn<?php echo $rv_is_mapped ? ' active' : ''; ?>"
		data-mode="mapped"
		data-section="rv"
		data-eem-action="toggle-inventory-mode">
		<?php esc_html_e( 'Mapped', 'equine-event-manager' ); ?>
	</button>
</div>
<input type="hidden"
	name="rv_selection_mode"
	id="eem-rv-selection-mode-input"
	value="<?php echo esc_attr( $rv_is_mapped ? 'exact_map' : 'quantity' ); ?>">
<?php
$rv_mode_html = (string) ob_get_clean();
$rv_mode_hint_text = $rv_is_mapped
	? __( 'Customers select specific lots from your layout at checkout', 'equine-event-manager' )
	: __( 'Customers pick how many lots they need at checkout; admin assigns specific lots on the Stall & RV Charts page', 'equine-event-manager' );
$rv_mode_html .= '<span class="eem-field-hint eem-inventory-mode-hint">' . esc_html( $rv_mode_hint_text ) . '</span>';
eem_render_editor_field_row( array(
	'label'        => __( 'Inventory Mode', 'equine-event-manager' ),
	'label_sub'    => __( 'How is RV inventory defined for this reservation?', 'equine-event-manager' ),
	'row_id'       => 'eem-row-rv-inventory-mode',
	'control_html' => $rv_mode_html,
) );

// 8. Inventory (dual-state: editable in Bulk mode, computed in Mapped mode)
ob_start();
?>
<input type="number"
	name="en_reservation[rv_inventory]"
	id="eem-rv-inventory-input"
	class="eem-field-input"
	value="<?php echo esc_attr( (string) ( $data['rv_inventory'] ?? '' ) ); ?>"
	placeholder="<?php esc_attr_e( 'Unlimited', 'equine-event-manager' ); ?>"
	min="0"
	style="<?php echo $rv_is_mapped ? 'display:none;' : ''; echo 'max-width:140px;'; ?>">
<div class="eem-inventory-computed-wrap"
	id="eem-rv-inventory-computed"
	style="<?php echo $rv_is_mapped ? '' : 'display:none;'; ?>">
	<span class="eem-inventory-computed-number" id="eem-rv-inventory-number">0</span>
	<span class="eem-inventory-computed-label" id="eem-rv-inventory-label">
		<?php esc_html_e( '(computed from row quantities)', 'equine-event-manager' ); ?>
	</span>
</div>
<?php
$rv_inv_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Available RV Inventory', 'equine-event-manager' ),
	'label_sub'    => __( 'Blank = unlimited', 'equine-event-manager' ),
	'control_html' => $rv_inv_html,
	'hint'         => __( 'Once inventory reaches zero, customers see a sold-out message.', 'equine-event-manager' ),
) );

// 8b. Max RV Lots Per Customer (per-customer purchase limit)
eem_render_editor_field_row( array(
	'label'        => __( 'Max RV Lots Per Customer', 'equine-event-manager' ),
	'label_sub'    => __( 'Blank = unlimited', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<input class="eem-field-input" type="number" min="1" step="1" name="eem_rv_max_per_customer" id="eem-rv-max-per-customer" value="%s" placeholder="%s" style="max-width:140px;" />',
		esc_attr( (string) ( $data['rv_max_per_customer'] ?? '' ) ),
		esc_attr__( 'Unlimited', 'equine-event-manager' )
	),
	'hint'         => __( 'Limits how many RV lots a single customer can reserve. Enforced at checkout.', 'equine-event-manager' ),
) );

// 9 + 10. Nightly + Weekend rates
eem_render_editor_field_row( array(
	'label'        => __( 'RV Nightly Rate', 'equine-event-manager' ),
	'row_id'       => 'row-rv-rate-nightly',
	'is_hidden'    => ! $nightly_on,
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[rv_nightly_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['rv_nightly_rate'] ) )
	),
	'hint'         => __( 'Base nightly rate. Lot zones below may add tier-specific pricing.', 'equine-event-manager' ),
) );
eem_render_editor_field_row( array(
	'label'        => __( 'RV Weekend Rate', 'equine-event-manager' ),
	'row_id'       => 'row-rv-rate-weekend',
	'is_hidden'    => ! $weekend_on,
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[rv_weekend_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['rv_weekend_rate'] ) )
	),
) );

// 11. Early Bird toggle
ob_start();
eem_render_editor_toggle_label_row( array(
	'name'       => 'rv_early_bird_enabled',
	'subsection' => 'rv-eb',
	'label'      => __( 'Enable RV early bird pricing', 'equine-event-manager' ),
	'is_enabled' => $eb_on,
	'controls'   => array( 'row-rv-eb-cutoff', 'row-rv-eb-nightly', 'row-rv-eb-weekend' ),
) );
$eb = ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'RV Early Bird Pricing', 'equine-event-manager' ),
	'control_html' => $eb,
) );

eem_render_editor_field_row( array(
	'label'        => __( 'Early Bird Cutoff', 'equine-event-manager' ),
	'row_id'       => 'row-rv-eb-cutoff',
	'is_hidden'    => ! $eb_on,
	'control_html' => sprintf(
		'<input class="eem-field-input" type="datetime-local" name="en_reservation[rv_early_bird_cutoff]" value="%s" style="max-width:260px" />',
		esc_attr( $fmt_dt( $data['rv_early_bird_cutoff'] ) )
	),
) );
eem_render_editor_field_row( array(
	'label'        => __( 'Early Bird Nightly Rate', 'equine-event-manager' ),
	'row_id'       => 'row-rv-eb-nightly',
	'is_hidden'    => ! ( $eb_on && $nightly_on ),
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[rv_early_bird_nightly_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['rv_early_bird_nightly_rate'] ) )
	),
) );
eem_render_editor_field_row( array(
	'label'        => __( 'Early Bird Weekend Rate', 'equine-event-manager' ),
	'row_id'       => 'row-rv-eb-weekend',
	'is_hidden'    => ! ( $eb_on && $weekend_on ),
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[rv_early_bird_weekend_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['rv_early_bird_weekend_rate'] ) )
	),
) );

// ── RV Mapped-content wrapper opens here (C8) ──
// Load meta from $data (pre-populated by get_meta_values()) or fall back to seeded zones / rows.
// NOTE: use $data, NOT a direct post-meta call with get_the_ID() — on custom admin pages
// (admin.php?page=...) the global $post is not set by WordPress, so that function returns 0.
$rv_zones_meta = isset( $data['rv_zones'] ) ? $data['rv_zones'] : array();
$rv_zones      = ( is_array( $rv_zones_meta ) && ! empty( $rv_zones_meta ) )
	? $rv_zones_meta
	: array(
		array( 'name' => 'Red Lot',  'color' => '#EF4444', 'nightly' => '35.00', 'weekend' => '90.00', 'available_qty' => '6' ),
		array( 'name' => 'Blue Lot', 'color' => '#1668F2', 'nightly' => '25.00', 'weekend' => '65.00', 'available_qty' => '18' ),
	);

$rv_rows_meta = isset( $data['rv_rows'] ) ? $data['rv_rows'] : array();
$rv_rows      = ( is_array( $rv_rows_meta ) && ! empty( $rv_rows_meta ) )
	? $rv_rows_meta
	: array(
		array( 'name' => 'RV Row A', 'layout' => 'one-sided',    'first' => '1',  'last' => '12', 'top_first' => '', 'top_last' => '', 'bot_first' => '', 'bot_last' => '' ),
		array( 'name' => 'RV Row B', 'layout' => 'back-to-back', 'first' => '',   'last' => '',   'top_first' => '13', 'top_last' => '18', 'bot_first' => '19', 'bot_last' => '24' ),
	);

$blocked_rv_lots_meta = isset( $data['blocked_rv_lots'] ) ? $data['blocked_rv_lots'] : array();
$blocked_rv_lots      = is_array( $blocked_rv_lots_meta ) ? $blocked_rv_lots_meta : array();

// Load saved RV lot zone assignments from $data; default each lot to zone index 0
// if no saved assignment exists (JS side reads window._rvLotZoneAssignmentsInit).
$lot_assignments_meta = isset( $data['rv_lot_zone_assignments'] ) ? $data['rv_lot_zone_assignments'] : array();
$lot_assignments      = ( is_array( $lot_assignments_meta ) && ! empty( $lot_assignments_meta ) )
	? $lot_assignments_meta
	: array();
$lot_assignments_json = wp_json_encode( $lot_assignments );
?>
<script>
/* Equine Event Manager — RV lot zone assignments init (server-rendered). */
if ( typeof window._rvLotZoneAssignmentsInit === 'undefined' ) {
	window._rvLotZoneAssignmentsInit = <?php echo $lot_assignments_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode is safe ?>;
}
</script>
<input type="hidden" id="eem-rv-lot-zone-assignments-input" name="eem_rv_lot_zone_assignments" value="">
<div id="eem-rv-mapped-content"
	style="<?php echo $rv_is_mapped ? '' : 'display:none;'; ?>">
<?php

// ── RV Lot Zones (nightly / weekend / available_qty) ──
ob_start();
?>
<div class="eem-zone-list" id="eem-lot-zones-list">
	<?php foreach ( $rv_zones as $zi => $zone ) :
		$z_name    = isset( $zone['name'] )          ? (string) $zone['name']                        : '';
		$z_color   = isset( $zone['color'] )          ? (string) $zone['color']                       : '#1668F2';
		$z_night   = isset( $zone['nightly'] )        ? $fmt_money( $zone['nightly'] )                : '0.00';
		$z_weekend = isset( $zone['weekend'] )        ? $fmt_money( $zone['weekend'] )                : '0.00';
		$z_qty     = isset( $zone['available_qty'] )  ? (int) $zone['available_qty']                  : 0;
		?>
		<div class="eem-zone-row" data-zone-index="<?php echo (int) $zi; ?>">
			<div class="eem-zone-color-swatch" style="background:<?php echo esc_attr( $z_color ); ?>" data-eem-action="reservation-editor-zone-color-open" data-eem-current-color="<?php echo esc_attr( $z_color ); ?>"></div>
			<input type="hidden" name="eem_rv_zones[<?php echo (int) $zi; ?>][color]" data-eem-zone-color-mirror value="<?php echo esc_attr( $z_color ); ?>">
			<input class="eem-zone-name-input" type="text" name="eem_rv_zones[<?php echo (int) $zi; ?>][name]" value="<?php echo esc_attr( $z_name ); ?>" placeholder="<?php esc_attr_e( 'Zone name', 'equine-event-manager' ); ?>" data-eem-input-action="rv-zone-input">
			<div class="eem-zone-price-group">
				<span class="eem-zone-price-label"><?php esc_html_e( '+ Nightly', 'equine-event-manager' ); ?></span>
				<div class="eem-zone-price-wrap"><span class="eem-zone-price-sym">$</span><input class="eem-zone-price-in" type="number" step="0.01" min="0" name="eem_rv_zones[<?php echo (int) $zi; ?>][nightly]" value="<?php echo esc_attr( $z_night ); ?>" data-eem-input-action="rv-zone-input"></div>
			</div>
			<div class="eem-zone-price-group">
				<span class="eem-zone-price-label"><?php esc_html_e( '+ Weekend', 'equine-event-manager' ); ?></span>
				<div class="eem-zone-price-wrap"><span class="eem-zone-price-sym">$</span><input class="eem-zone-price-in" type="number" step="0.01" min="0" name="eem_rv_zones[<?php echo (int) $zi; ?>][weekend]" value="<?php echo esc_attr( $z_weekend ); ?>" data-eem-input-action="rv-zone-input"></div>
			</div>
			<div class="eem-zone-price-group">
				<span class="eem-zone-price-label"><?php esc_html_e( 'Avail Qty', 'equine-event-manager' ); ?></span>
				<div class="eem-zone-price-wrap"><input class="eem-zone-price-in" type="number" min="0" style="width:56px" name="eem_rv_zones[<?php echo (int) $zi; ?>][available_qty]" value="<?php echo esc_attr( (string) $z_qty ); ?>" data-role="zone-qty" data-eem-input-action="rv-zone-input"></div>
			</div>
			<button class="eem-row-card-delete" type="button" title="<?php esc_attr_e( 'Delete zone', 'equine-event-manager' ); ?>" data-eem-action="rv-delete-zone">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
			</button>
		</div>
	<?php endforeach; ?>
</div>
<button class="eem-zone-add-btn" type="button" data-eem-action="rv-add-zone">
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
	<?php esc_html_e( 'Add Zone', 'equine-event-manager' ); ?>
</button>
<template id="eem-lot-zone-row-template">
<div class="eem-zone-row" data-zone-index="__index__">
	<div class="eem-zone-color-swatch" style="background:#1668F2" data-eem-action="reservation-editor-zone-color-open" data-eem-current-color="#1668F2"></div>
	<input type="hidden" name="eem_rv_zones[__index__][color]" data-eem-zone-color-mirror value="#1668F2">
	<input class="eem-zone-name-input" type="text" name="eem_rv_zones[__index__][name]" value="" placeholder="<?php esc_attr_e( 'Zone name', 'equine-event-manager' ); ?>" data-eem-input-action="rv-zone-input">
	<div class="eem-zone-price-group">
		<span class="eem-zone-price-label"><?php esc_html_e( '+ Nightly', 'equine-event-manager' ); ?></span>
		<div class="eem-zone-price-wrap"><span class="eem-zone-price-sym">$</span><input class="eem-zone-price-in" type="number" step="0.01" min="0" name="eem_rv_zones[__index__][nightly]" value="0.00" data-eem-input-action="rv-zone-input"></div>
	</div>
	<div class="eem-zone-price-group">
		<span class="eem-zone-price-label"><?php esc_html_e( '+ Weekend', 'equine-event-manager' ); ?></span>
		<div class="eem-zone-price-wrap"><span class="eem-zone-price-sym">$</span><input class="eem-zone-price-in" type="number" step="0.01" min="0" name="eem_rv_zones[__index__][weekend]" value="0.00" data-eem-input-action="rv-zone-input"></div>
	</div>
	<div class="eem-zone-price-group">
		<span class="eem-zone-price-label"><?php esc_html_e( 'Avail Qty', 'equine-event-manager' ); ?></span>
		<div class="eem-zone-price-wrap"><input class="eem-zone-price-in" type="number" min="0" style="width:56px" name="eem_rv_zones[__index__][available_qty]" value="0" data-role="zone-qty" data-eem-input-action="rv-zone-input"></div>
	</div>
	<button class="eem-row-card-delete" type="button" title="<?php esc_attr_e( 'Delete zone', 'equine-event-manager' ); ?>" data-eem-action="rv-delete-zone">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
	</button>
</div>
</template>
<?php
$zones_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'RV Lot Zones', 'equine-event-manager' ),
	'label_sub'    => __( 'Pricing tiers — every lot belongs to a zone', 'equine-event-manager' ),
	'row_id'       => 'row-rv-zones',
	'control_html' => $zones_html . '<span class="eem-field-hint" style="display:block;margin-bottom:12px">' . esc_html__( 'Zone prices are added to the base RV rate. Total = base rate + zone surcharge.', 'equine-event-manager' ) . '</span>',
) );

// ── Lot Rows builder ──
ob_start();
?>
<div class="eem-row-builder-summary" style="margin-bottom:10px" id="eem-rv-row-summary"></div>
<div class="eem-zone-painter">
	<span class="eem-zone-painter-label"><?php esc_html_e( 'Paint Mode', 'equine-event-manager' ); ?></span>
	<select class="eem-zone-painter-select" id="eem-rv-paint-zone" data-eem-input-action="rv-paint-zone">
		<option value=""><?php esc_html_e( 'Off — click to view lot details', 'equine-event-manager' ); ?></option>
		<?php foreach ( $rv_zones as $zi => $zone ) :
			$z_name  = isset( $zone['name'] )  ? (string) $zone['name']  : '';
			// Auto-assign zone color from the JS palette by index (matches getZoneColor() in admin.js)
			$palette  = array( '#DC2626', '#2563EB', '#16A34A', '#CA8A04', '#9333EA', '#EA580C' );
			$z_color  = $palette[ $zi % count( $palette ) ];
			?>
			<option value="<?php echo (int) $zi; ?>" style="color:<?php echo esc_attr( $z_color ); ?>">&#x25cf; <?php echo esc_html( $z_name ); ?></option>
		<?php endforeach; ?>
	</select>
	<span class="eem-zone-painter-hint"><?php esc_html_e( 'Paint Mode lets you assign individual lots to zones. Useful when rows contain lots from multiple zones (e.g., premium corner spots vs. standard interior). Pick a zone, then click any lot to mark it that zone.', 'equine-event-manager' ); ?></span>
</div>
<div class="eem-row-builder" id="eem-rv-row-builder-list">
<?php foreach ( $rv_rows as $ri => $row ) :
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
				<input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][name]" value="<?php echo esc_attr( $r_name ); ?>" data-eem-input-action="rv-row-input">
			</div>
			<div class="eem-row-card-field eem-row-card-field-layout">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'Layout', 'equine-event-manager' ); ?></span>
				<select name="eem_rv_rows[<?php echo (int) $ri; ?>][layout]" data-eem-input-action="rv-row-layout">
					<option value="one-sided"<?php selected( $r_layout, 'one-sided' ); ?>><?php esc_html_e( 'One-sided', 'equine-event-manager' ); ?></option>
					<option value="back-to-back"<?php selected( $r_layout, 'back-to-back' ); ?>><?php esc_html_e( 'Back-to-back', 'equine-event-manager' ); ?></option>
				</select>
			</div>
			<button class="eem-row-card-delete" type="button" title="<?php esc_attr_e( 'Delete row', 'equine-event-manager' ); ?>" data-eem-action="rv-delete-row">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
			</button>
		</div>
		<div class="eem-row-card-one-sided"<?php echo $is_b2b ? ' style="display:none"' : ''; ?>>
			<div class="eem-row-card-field">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'First Lot Label', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][first]" value="<?php echo esc_attr( $r_first ); ?>" data-role="first" data-eem-input-action="rv-row-input">
			</div>
			<div class="eem-row-card-field">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'Last Lot Label', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][last]" value="<?php echo esc_attr( $r_last ); ?>" data-role="last" data-eem-input-action="rv-row-input">
			</div>
		</div>
		<div class="eem-row-card-sides"<?php echo $is_b2b ? '' : ' style="display:none"'; ?>>
			<div class="eem-side-block">
				<div class="eem-side-block-label"><?php esc_html_e( 'Top Side', 'equine-event-manager' ); ?></div>
				<div class="eem-side-block-row">
					<div class="eem-row-card-field"><span class="eem-row-card-field-label"><?php esc_html_e( 'First', 'equine-event-manager' ); ?></span><input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][top_first]" value="<?php echo esc_attr( $r_top_first ); ?>" data-role="top-first" data-eem-input-action="rv-row-input"></div>
					<div class="eem-row-card-field"><span class="eem-row-card-field-label"><?php esc_html_e( 'Last', 'equine-event-manager' ); ?></span><input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][top_last]" value="<?php echo esc_attr( $r_top_last ); ?>" data-role="top-last" data-eem-input-action="rv-row-input"></div>
				</div>
			</div>
			<div class="eem-side-block">
				<div class="eem-side-block-label"><?php esc_html_e( 'Bottom Side', 'equine-event-manager' ); ?></div>
				<div class="eem-side-block-row">
					<div class="eem-row-card-field"><span class="eem-row-card-field-label"><?php esc_html_e( 'First', 'equine-event-manager' ); ?></span><input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][bot_first]" value="<?php echo esc_attr( $r_bot_first ); ?>" data-role="bot-first" data-eem-input-action="rv-row-input"></div>
					<div class="eem-row-card-field"><span class="eem-row-card-field-label"><?php esc_html_e( 'Last', 'equine-event-manager' ); ?></span><input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][bot_last]" value="<?php echo esc_attr( $r_bot_last ); ?>" data-role="bot-last" data-eem-input-action="rv-row-input"></div>
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
<button class="eem-row-add-btn" type="button" data-eem-action="rv-add-row">
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
	<?php esc_html_e( 'Add Row', 'equine-event-manager' ); ?>
</button>
<span class="eem-field-hint"><?php esc_html_e( "Each lot's colored dot shows its current zone. By default, all lots are assigned to the first zone. Use Paint Mode above to reassign individual lots.", 'equine-event-manager' ); ?></span>
<?php
$rv_rows_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Lot Rows', 'equine-event-manager' ),
	'label_sub'    => __( 'Define the physical layout customers will see', 'equine-event-manager' ),
	'row_id'       => 'row-rv-rows-builder',
	'control_html' => $rv_rows_html,
) );

// ── Blocked RV Lots tag-select ──
ob_start();
?>
<div class="eem-tag-select" id="eem-blocked-rv-lots-select">
	<div class="eem-tag-select-input" data-eem-action="tag-open">
		<?php foreach ( $blocked_rv_lots as $bl_val ) : ?>
		<span class="eem-tag-chip" data-value="<?php echo esc_attr( (string) $bl_val ); ?>">
			<?php echo esc_html( (string) $bl_val ); ?>
			<button type="button" class="eem-tag-chip-remove" data-eem-action="tag-remove" aria-label="<?php esc_attr_e( 'Remove', 'equine-event-manager' ); ?>">&#xd7;</button>
			<input type="hidden" name="eem_blocked_rv_lots[]" value="<?php echo esc_attr( (string) $bl_val ); ?>">
		</span>
		<?php endforeach; ?>
		<input class="eem-tag-search" type="text" placeholder="<?php esc_attr_e( 'Type a lot label…', 'equine-event-manager' ); ?>" data-eem-input-action="tag-search" data-eem-tag-target="eem-blocked-rv-lots-select">
	</div>
	<div class="eem-tag-dropdown" id="eem-blocked-rv-lots-dropdown">
		<div class="eem-tag-dropdown-empty" style="display:none"><?php esc_html_e( 'No matching lots.', 'equine-event-manager' ); ?></div>
	</div>
</div>
<span class="eem-field-hint"><?php esc_html_e( 'Type a lot label to filter, then click to block it. Click × on a chip to unblock.', 'equine-event-manager' ); ?></span>
<?php
$blocked_rv_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Blocked RV Lots', 'equine-event-manager' ),
	'label_sub'    => __( 'Hold back from reservation', 'equine-event-manager' ),
	'row_id'       => 'row-rv-blocked-lots',
	'control_html' => $blocked_rv_html,
) );
?>
</div>
<?php
// ── RV Add-Ons with master enable toggle (last field-row, after mapped-content) ──
ob_start();
eem_render_editor_toggle_label_row( array(
	'name'       => 'rv_addons_enabled',
	'subsection' => 'rv-addons',
	'label'      => __( 'Enable RV add-ons', 'equine-event-manager' ),
	'is_enabled' => $rv_addons_on,
	'controls'   => array( 'rv-addons-table-wrap' ),
) );
?>
<div id="rv-addons-table-wrap" <?php echo $rv_addons_on ? '' : 'style="display:none"'; ?>>
	<table class="eem-repeat-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Add-On', 'equine-event-manager' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Price', 'equine-event-manager' ); ?></th>
				<th style="width:40px"></th>
			</tr>
		</thead>
		<tbody id="eem-rv-addons-rows">
			<?php foreach ( $rv_addons as $idx => $addon ) :
				$a_name  = isset( $addon['name'] ) ? (string) $addon['name'] : '';
				$a_price = isset( $addon['price'] ) ? (float) $addon['price'] : 0.0;
				?>
				<tr>
					<td><input class="eem-repeat-input" type="text" name="en_reservation[rv_addons][<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr( $a_name ); ?>" /></td>
					<td><div class="eem-repeat-price-wrap"><span class="eem-repeat-price-sym">$</span><input class="eem-repeat-price-in" type="number" step="0.01" min="0" name="en_reservation[rv_addons][<?php echo (int) $idx; ?>][price]" value="<?php echo esc_attr( $fmt_money( $a_price ) ); ?>" /></div></td>
					<td><button class="eem-btn-delete" type="button" aria-label="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-remove-repeating-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<button class="eem-btn-add" type="button" data-eem-action="reservation-editor-add-repeating-row" data-eem-repeating-template="eem-rv-addons-row-template" data-eem-repeating-tbody="eem-rv-addons-rows">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
		<?php esc_html_e( 'Add RV Add-On', 'equine-event-manager' ); ?>
	</button>
	<template id="eem-rv-addons-row-template"><tr>
		<td><input class="eem-repeat-input" type="text" name="en_reservation[rv_addons][__index__][name]" value="" placeholder="<?php esc_attr_e( 'Add-on name', 'equine-event-manager' ); ?>" /></td>
		<td><div class="eem-repeat-price-wrap"><span class="eem-repeat-price-sym">$</span><input class="eem-repeat-price-in" type="number" step="0.01" min="0" name="en_reservation[rv_addons][__index__][price]" value="0.00" /></div></td>
		<td><button class="eem-btn-delete" type="button" aria-label="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-remove-repeating-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></td>
	</tr></template>
</div>
<?php
$rv_addons_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'RV Add-Ons', 'equine-event-manager' ),
	'label_sub'    => __( 'Optional hookups customers can add', 'equine-event-manager' ),
	'control_html' => $rv_addons_html,
) );


