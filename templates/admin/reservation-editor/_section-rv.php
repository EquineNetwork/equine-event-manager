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
require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-layout-summary.php';

$fmt_dt    = function ( $v ) { if ( '' === (string) $v ) return ''; if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', (string) $v ) ) return substr( (string) $v, 0, 16 ); $ts = strtotime( (string) $v ); return $ts ? gmdate( 'Y-m-d\TH:i', $ts ) : ''; };
$fmt_money = function ( $v ) { return number_format( (float) $v, 2, '.', '' ); };

$nightly_on   = ! empty( $data['rv_nightly_enabled'] );
$weekend_on   = ! empty( $data['rv_weekend_enabled'] );
$schedule_on  = ! empty( $data['rv_schedule_enabled'] );
$eb_on        = ! empty( $data['rv_early_bird_enabled'] );
$rv_addons_on = ! empty( $data['rv_addons_enabled'] );

$lot_zones = isset( $data['rv_lot_zones'] ) ? (array) $data['rv_lot_zones'] : array();
$rv_addons = isset( $data['rv_addons'] ) ? (array) $data['rv_addons'] : array();

// Color palette for Lot Zones — 8 presets per Decision C-1.
$zone_palette = array(
	'red'    => '#dc2626',
	'blue'   => '#1668F2',
	'green'  => '#15803d',
	'orange' => '#ea580c',
	'purple' => '#7c3aed',
	'navy'   => '#031B4E',
	'teal'   => '#0d9488',
	'pink'   => '#db2777',
);

// Lot Layout summary computation (read-only from RV chart meta + zones)
$rv_lot_rows   = 0;
$rv_lot_total  = 0;
$rv_breakdown  = array();
foreach ( $lot_zones as $z ) {
	if ( ! is_array( $z ) || empty( $z['name'] ) ) continue;
	$rv_breakdown[] = array(
		'label'   => $z['name'],
		'count'   => 0,    // Lot-per-zone count comes from C8 chart data
		'blocked' => 0,
	);
}
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
	name="rv_inventory"
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
?>
<div id="eem-rv-mapped-content"
	style="<?php echo $rv_is_mapped ? '' : 'display:none;'; ?>">
<?php

// ── Lot Zones (NEW _eem_rv_lot_zones meta, mockup lines 779–823) ──
ob_start();
?>
<div class="eem-zone-list" id="eem-lot-zones-list">
	<?php foreach ( $lot_zones as $idx => $zone ) :
		$z_name  = isset( $zone['name'] ) ? (string) $zone['name'] : '';
		$z_color = isset( $zone['color'] ) && isset( $zone_palette[ $zone['color'] ] ) ? (string) $zone['color'] : 'blue';
		$z_surch = isset( $zone['surcharge'] ) ? (float) $zone['surcharge'] : 0.0;
		$z_hex   = $zone_palette[ $z_color ];
		?>
		<div class="eem-zone-row">
			<div class="eem-zone-color-swatch" style="background:<?php echo esc_attr( $z_hex ); ?>" data-eem-action="reservation-editor-zone-color-open" data-eem-current-slug="<?php echo esc_attr( $z_color ); ?>"></div>
			<input type="hidden" name="en_reservation[rv_lot_zones][<?php echo (int) $idx; ?>][color]" data-eem-zone-color-mirror value="<?php echo esc_attr( $z_color ); ?>" />
			<input class="eem-zone-name-input" type="text" name="en_reservation[rv_lot_zones][<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr( $z_name ); ?>" />
			<div class="eem-zone-price-group">
				<span class="eem-zone-price-label"><?php esc_html_e( 'Surcharge', 'equine-event-manager' ); ?></span>
				<div class="eem-zone-price-wrap"><span class="eem-zone-price-sym">$</span><input class="eem-zone-price-in" type="number" step="0.01" min="0" name="en_reservation[rv_lot_zones][<?php echo (int) $idx; ?>][surcharge]" value="<?php echo esc_attr( $fmt_money( $z_surch ) ); ?>" /></div>
			</div>
			<button class="eem-zone-delete-btn" type="button" aria-label="<?php esc_attr_e( 'Delete zone', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-zone-delete">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
			</button>
		</div>
	<?php endforeach; ?>
</div>
<button class="eem-zone-add-btn" type="button" data-eem-action="reservation-editor-zone-add">
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
	<?php esc_html_e( 'Add Lot Zone', 'equine-event-manager' ); ?>
</button>
<template id="eem-lot-zone-row-template"><div class="eem-zone-row">
	<div class="eem-zone-color-swatch" style="background:<?php echo esc_attr( $zone_palette['blue'] ); ?>" data-eem-action="reservation-editor-zone-color-open" data-eem-current-slug="blue"></div>
	<input type="hidden" name="en_reservation[rv_lot_zones][__index__][color]" data-eem-zone-color-mirror value="blue" />
	<input class="eem-zone-name-input" type="text" name="en_reservation[rv_lot_zones][__index__][name]" value="" placeholder="<?php esc_attr_e( 'Zone name', 'equine-event-manager' ); ?>" />
	<div class="eem-zone-price-group">
		<span class="eem-zone-price-label"><?php esc_html_e( 'Surcharge', 'equine-event-manager' ); ?></span>
		<div class="eem-zone-price-wrap"><span class="eem-zone-price-sym">$</span><input class="eem-zone-price-in" type="number" step="0.01" min="0" name="en_reservation[rv_lot_zones][__index__][surcharge]" value="0.00" /></div>
	</div>
	<button class="eem-zone-delete-btn" type="button" aria-label="<?php esc_attr_e( 'Delete zone', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-zone-delete">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
	</button>
</div></template>
<?php
$zones_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Lot Zones', 'equine-event-manager' ),
	'label_sub'    => __( 'Pricing tiers customers choose between', 'equine-event-manager' ),
	'control_html' => $zones_html,
) );

// ── Lot Layout summary widget (read-only, mockup lines 864–884) ── inside mapped-content
ob_start();
eem_render_editor_layout_summary( array(
	'kind'          => 'lot',
	'row_count'     => count( $rv_breakdown ),
	'total_count'   => $rv_lot_total,
	'blocked_count' => 0,
	'row_breakdown' => $rv_breakdown,
	'manage_label'  => __( 'Manage Lot Layout', 'equine-event-manager' ),
	'manage_url'    => admin_url( 'admin.php?page=equine-event-manager-stall-charts' ),
	'hint'          => __( 'Edit the physical lot chart from the Stall Charts page. Coming in C8.', 'equine-event-manager' ),
) );
$lot_summary = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Lot Layout', 'equine-event-manager' ),
	'label_sub'    => __( 'Physical lot rows, lot numbers, zone assignments', 'equine-event-manager' ),
	'control_html' => $lot_summary,
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


