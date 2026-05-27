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
require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-layout-summary.php';

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

// Layout summary computation (read-only from existing chart meta)
$stall_blocks  = isset( $data['stall_chart_stall_blocks'] ) ? (array) $data['stall_chart_stall_blocks'] : array();
$row_count     = count( array_filter( $stall_blocks, function ( $b ) { return is_array( $b ) && ! empty( $b['label'] ); } ) );
$total_stalls  = 0;
$blocked_units = isset( $data['stall_chart_blocked_stall_units'] ) ? (array) $data['stall_chart_blocked_stall_units'] : array();
$blocked_total = count( $blocked_units );
$breakdown     = array();
foreach ( $stall_blocks as $blk ) {
	if ( ! is_array( $blk ) || empty( $blk['label'] ) ) continue;
	$start = isset( $blk['start'] ) ? (int) $blk['start'] : 0;
	$end   = isset( $blk['end'] ) ? (int) $blk['end'] : 0;
	$count = $start && $end ? abs( $end - $start ) + 1 : 0;
	$total_stalls += $count;
	$row_blocked   = 0;
	foreach ( $blocked_units as $u ) {
		// crude membership: blocked units stored as label-prefixed unit codes
		if ( is_string( $u ) && false !== stripos( $u, (string) $blk['label'] ) ) {
			$row_blocked++;
		}
	}
	$breakdown[] = array(
		'label'   => (string) $blk['label'],
		'count'   => $count,
		'blocked' => $row_blocked,
	);
}
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

// 8. Inventory
eem_render_editor_field_row( array(
	'label'        => __( 'Available Stall Inventory', 'equine-event-manager' ),
	'label_sub'    => __( 'Blank = unlimited', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<input class="eem-field-input" type="number" name="en_reservation[stall_inventory]" value="%s" placeholder="%s" style="max-width:140px" />',
		esc_attr( (string) ( $data['stall_inventory'] ?? '' ) ),
		esc_attr__( 'Unlimited', 'equine-event-manager' )
	),
	'hint'         => __( 'Once inventory reaches zero, customers see a sold-out message.', 'equine-event-manager' ),
) );

// 9 + 10. Nightly + Weekend rates (conditional on stay-type)
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

// 18. Stall Layout summary widget (read-only)
$summary_html = '';
ob_start();
eem_render_editor_layout_summary( array(
	'kind'          => 'stall',
	'row_count'     => $row_count,
	'total_count'   => $total_stalls,
	'blocked_count' => $blocked_total,
	'row_breakdown' => $breakdown,
	'manage_label'  => __( 'Manage Stall Layout', 'equine-event-manager' ),
	'manage_url'    => admin_url( 'admin.php?page=equine-event-manager-stall-charts' ),
	'hint'          => __( 'Edit the physical stall chart from the Stall Charts page. Coming in C8.', 'equine-event-manager' ),
) );
$summary_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Stall Layout', 'equine-event-manager' ),
	'label_sub'    => __( 'Physical rows, stall numbers, blocked stalls', 'equine-event-manager' ),
	'control_html' => $summary_html,
) );
