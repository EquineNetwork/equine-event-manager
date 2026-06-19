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
$fmt_dt    = function ( $v ) { if ( '' === (string) $v ) return ''; if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', (string) $v ) ) return substr( (string) $v, 0, 16 ); $ts = strtotime( (string) $v ); return $ts ? gmdate( 'Y-m-d\TH:i', $ts ) : ''; };
$fmt_money = function ( $v ) { return number_format( (float) $v, 2, '.', '' ); };
$schedule_on  = ! empty( $data['rv_schedule_enabled'] );
$eb_on        = ! empty( $data['rv_early_bird_enabled'] );

$rv_pricing_mode = isset( $data['rv_pricing_mode'] ) ? (string) $data['rv_pricing_mode'] : 'nightly';
$rv_packages     = EEM_Stay_Packages_Repo::get_packages( (int) ( $data['_reservation_id'] ?? get_the_ID() ), 'rv' );
$is_rv_packages  = ( 'packages' === $rv_pricing_mode );
$is_rv_both      = ( 'both' === $rv_pricing_mode );
$show_rv_nightly  = ( ! $is_rv_packages || $is_rv_both );
$show_rv_packages = ( $is_rv_packages || $is_rv_both );

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

// 2. Available Reservation Dates — default to event dates when empty
$_avail_start = (string) $data['available_start_date'];
$_avail_end   = (string) $data['available_end_date'];
if ( '' === $_avail_start && ! empty( $data['_event_start_date'] ) ) {
	$_avail_start = gmdate( 'Y-m-d', strtotime( (string) $data['_event_start_date'] ) );
}
if ( '' === $_avail_end && ! empty( $data['_event_end_date'] ) ) {
	$_avail_end = gmdate( 'Y-m-d', strtotime( (string) $data['_event_end_date'] ) );
}
eem_render_editor_field_row( array(
	'label'        => __( 'Available Reservation Dates', 'equine-event-manager' ),
	'label_sub'    => __( 'Bookable date window for RV lots', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<div class="eem-date-range"><input class="eem-field-input" type="date" name="en_reservation[available_start_date]" value="%s" style="width:170px" /><span class="eem-date-sep">–</span><input class="eem-field-input" type="date" name="en_reservation[available_end_date]" value="%s" style="width:170px" /></div>',
		esc_attr( $_avail_start ),
		esc_attr( $_avail_end )
	),
) );

// 3a. Pricing Mode + rates live inside a single grouped panel.
?>
<div class="eem-rv-packages-content" id="eem-rv-packages-content">
<?php
ob_start();
?>
<div class="eem-mode-btns">
	<button type="button"
		class="eem-mode-btn<?php echo ( 'nightly' === $rv_pricing_mode || ( ! $is_rv_packages && ! $is_rv_both ) ) ? ' active' : ''; ?>"
		data-pricing-mode="nightly"
		data-eem-action="toggle-rv-pricing-mode">
		<?php esc_html_e( 'Nightly Rate', 'equine-event-manager' ); ?>
	</button>
	<button type="button"
		class="eem-mode-btn<?php echo $is_rv_packages ? ' active' : ''; ?>"
		data-pricing-mode="packages"
		data-eem-action="toggle-rv-pricing-mode">
		<?php esc_html_e( 'Stay Packages', 'equine-event-manager' ); ?>
	</button>
	<button type="button"
		class="eem-mode-btn<?php echo $is_rv_both ? ' active' : ''; ?>"
		data-pricing-mode="both"
		data-eem-action="toggle-rv-pricing-mode">
		<?php esc_html_e( 'Both', 'equine-event-manager' ); ?>
	</button>
</div>
<input type="hidden" name="en_reservation[rv_pricing_mode]" id="eem-rv-pricing-mode-input" value="<?php echo esc_attr( $rv_pricing_mode ); ?>">
<?php
$pricing_mode_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Pricing Mode', 'equine-event-manager' ),
	'label_sub'    => __( 'How customers pay for RV lots', 'equine-event-manager' ),
	'control_html' => $pricing_mode_html,
) );

// ── Nightly-mode rate (hidden when pricing mode = packages only) ──
echo '<div class="eem-rv-nightly-content" id="eem-rv-nightly-content"' . ( ! $show_rv_nightly ? ' style="display:none"' : '' ) . '>';
echo '<input type="hidden" name="en_reservation[rv_nightly_enabled]" value="1">';

eem_render_editor_field_row( array(
	'label'        => __( 'RV Nightly Rate', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[rv_nightly_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['rv_nightly_rate'] ) )
	),
	'hint'         => __( 'Base nightly rate. Row surcharges below are added on top of this rate.', 'equine-event-manager' ),
) );
echo '</div>'; // .eem-rv-nightly-content
?>
<div id="eem-rv-packages-list-wrap"<?php echo $show_rv_packages ? '' : ' style="display:none"'; ?>>
	<div class="eem-packages-list" id="eem-rv-packages-tbody">
		<?php foreach ( $rv_packages as $pkg ) :
			$_price_fmt = number_format( (float) $pkg['price'], 2, '.', '' );
		?>
		<div class="eem-pkg-row" data-package-id="<?php echo (int) $pkg['id']; ?>">
			<input type="text" class="eem-pkg-name-input" value="<?php echo esc_attr( $pkg['name'] ); ?>" data-field="name" placeholder="<?php esc_attr_e( 'Package name', 'equine-event-manager' ); ?>">
			<input type="date" class="eem-pkg-date-input" value="<?php echo esc_attr( $pkg['start_date'] ); ?>" data-field="start_date">
			<span class="eem-pkg-sep">&ndash;</span>
			<input type="date" class="eem-pkg-date-input" value="<?php echo esc_attr( $pkg['end_date'] ); ?>" data-field="end_date">
			<div class="eem-pkg-price-wrap"><span class="eem-pkg-price-sym">$</span><input type="number" step="0.01" min="0" class="eem-pkg-price-input" value="<?php echo esc_attr( $_price_fmt ); ?>" data-field="price"></div>
			<button type="button" class="eem-row-card-delete" data-eem-action="rv-package-delete" data-package-id="<?php echo (int) $pkg['id']; ?>" title="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button>
		</div>
		<?php endforeach; ?>
	</div>
	<div class="eem-empty-cta eem-packages-empty" id="eem-rv-packages-empty"<?php echo empty( $rv_packages ) ? '' : ' style="display:none"'; ?>>
		<div class="eem-empty-cta__icon" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
		</div>
		<h3 class="eem-empty-cta__title"><?php esc_html_e( 'No packages yet', 'equine-event-manager' ); ?></h3>
		<p class="eem-empty-cta__text"><?php esc_html_e( 'Click + Add Package below to create your first stay package.', 'equine-event-manager' ); ?></p>
	</div>

	<div class="eem-package-inline-form" id="eem-rv-package-form" style="display:none">
		<div class="eem-pkg-row">
			<input type="text" class="eem-pkg-name-input" id="eem-rv-pkg-name" placeholder="<?php esc_attr_e( 'e.g. Week 1, Full Event', 'equine-event-manager' ); ?>">
			<input type="date" class="eem-pkg-date-input" id="eem-rv-pkg-start">
			<span class="eem-pkg-sep">&ndash;</span>
			<input type="date" class="eem-pkg-date-input" id="eem-rv-pkg-end">
			<div class="eem-pkg-price-wrap"><span class="eem-pkg-price-sym">$</span><input type="number" step="0.01" min="0" class="eem-pkg-price-input" id="eem-rv-pkg-price"></div>
			<input type="number" min="0" step="1" class="eem-pkg-name-input" id="eem-rv-pkg-max-qty" placeholder="<?php esc_attr_e( 'Unlimited', 'equine-event-manager' ); ?>" style="max-width:100px">
		</div>
		<div class="eem-package-form-actions">
			<button type="button" class="eem-btn eem-btn-primary eem-btn-sm" id="eem-rv-pkg-save"><?php esc_html_e( 'Save', 'equine-event-manager' ); ?></button>
			<button type="button" class="eem-btn eem-btn-ghost eem-btn-sm" id="eem-rv-pkg-cancel"><?php esc_html_e( 'Cancel', 'equine-event-manager' ); ?></button>
		</div>
		<input type="hidden" id="eem-rv-pkg-editing-id" value="">
	</div>

	<button type="button" class="eem-btn-add" id="eem-rv-pkg-add-btn" data-eem-action="rv-package-add">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
		<?php esc_html_e( 'Add Package', 'equine-event-manager' ); ?>
	</button>
</div><!-- #eem-rv-packages-list-wrap -->
</div><!-- .eem-rv-packages-content -->
<?php

// ── Nightly-mode options (schedule + early bird — hidden when pricing mode = packages only) ──
echo '<div class="eem-rv-nightly-options" id="eem-rv-nightly-options"' . ( ! $show_rv_nightly ? ' style="display:none"' : '' ) . '>';

// 5–7. Reservation Schedule — grouped (mirrors Stall).
echo '<div class="eem-sched-group">';
eem_render_editor_toggle_label_row( array(
	'name'       => 'rv_schedule_enabled',
	'subsection' => 'rv-schedule',
	'label'      => __( 'Schedule RV Reservations', 'equine-event-manager' ),
	'is_enabled' => $schedule_on,
	'controls'   => array( 'row-rv-schedule-fields' ),
) );
echo '<p class="eem-field-hint eem-sched-group__hint">' . esc_html__( 'Open and close RV reservations on specific dates and times.', 'equine-event-manager' ) . '</p>';
echo '<div class="eem-sched-fields' . ( $schedule_on ? '' : ' eem-row--hidden' ) . '" id="row-rv-schedule-fields">';
printf(
	'<div class="eem-sched-field"><span class="eem-sched-field__label">%s</span><input class="eem-field-input" type="datetime-local" name="en_reservation[rv_open_at]" value="%s" /></div>',
	esc_html__( 'RV Open Date/Time', 'equine-event-manager' ),
	esc_attr( $fmt_dt( $data['rv_open_at'] ) )
);
printf(
	'<div class="eem-sched-field"><span class="eem-sched-field__label">%s</span><input class="eem-field-input" type="datetime-local" name="en_reservation[rv_close_at]" value="%s" /></div>',
	esc_html__( 'RV Close Date/Time', 'equine-event-manager' ),
	esc_attr( $fmt_dt( $data['rv_close_at'] ) )
);
echo '</div></div>';

// 11–13. Early Bird — grouped (mirrors Stall).
echo '<div class="eem-sched-group">';
eem_render_editor_toggle_label_row( array(
	'name'       => 'rv_early_bird_enabled',
	'subsection' => 'rv-eb',
	'label'      => __( 'Enable Early Bird Pricing', 'equine-event-manager' ),
	'is_enabled' => $eb_on,
	'controls'   => array( 'row-rv-eb-fields' ),
) );
echo '<p class="eem-field-hint eem-sched-group__hint">' . esc_html__( 'Offer a discounted nightly rate before a cutoff date.', 'equine-event-manager' ) . '</p>';
echo '<div class="eem-sched-fields' . ( $eb_on ? '' : ' eem-row--hidden' ) . '" id="row-rv-eb-fields">';
printf(
	'<div class="eem-sched-field"><span class="eem-sched-field__label">%s</span><input class="eem-field-input" type="datetime-local" name="en_reservation[rv_early_bird_cutoff]" value="%s" /></div>',
	esc_html__( 'Early Bird Cutoff', 'equine-event-manager' ),
	esc_attr( $fmt_dt( $data['rv_early_bird_cutoff'] ) )
);
printf(
	'<div class="eem-sched-field"><span class="eem-sched-field__label">%s</span><div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[rv_early_bird_nightly_rate]" value="%s" /></div></div>',
	esc_html__( 'Early Bird Nightly Rate', 'equine-event-manager' ),
	esc_attr( $fmt_money( $data['rv_early_bird_nightly_rate'] ) )
);
echo '</div></div>';

echo '</div>'; // .eem-rv-nightly-options

// v2 #5 — group the interdependent RV layout cluster (Inventory Mode → Available
// → Max → Zones → Lot Rows → Blocked → Map) in a shaded panel, mirroring the
// front-end "Pick Your Stalls" card.
echo '<div class="eem-layout-group">';

// Inventory Mode (C8) — UX polish 2.3.23: moved below pricing/EB so the
// inventory cluster (Mode → Available qty → Max per customer → row builder) appears
// as one tight group just above the Lot Zones and row builder.
// v4 RV two-control — mirror the stall section: RV Inventory Type (Bulk |
// Mapped) + Customer Selection (Quantity | Pick from layout). The legacy
// rv_selection_mode = exact_map iff mapped + pick_layout.
$rv_inv_type    = isset( $data['rv_inventory_type'] ) ? (string) $data['rv_inventory_type'] : 'bulk';
$rv_cust_sel    = isset( $data['rv_customer_selection'] ) ? (string) $data['rv_customer_selection'] : 'quantity';
$rv_is_mapped   = ( 'mapped' === $rv_inv_type );
$rv_is_pick     = ( $rv_is_mapped && 'pick_layout' === $rv_cust_sel );
$rv_legacy_mode = ( $rv_is_mapped && 'pick_layout' === $rv_cust_sel ) ? 'exact_map' : 'quantity';

// ── Control 1: RV Inventory Type ──
ob_start();
?>
<div class="eem-mode-btns">
	<button type="button" class="eem-mode-btn<?php echo $rv_is_mapped ? '' : ' active'; ?>" data-type="bulk" data-eem-action="toggle-rv-inventory-type"><?php esc_html_e( 'Bulk', 'equine-event-manager' ); ?></button>
	<button type="button" class="eem-mode-btn<?php echo $rv_is_mapped ? ' active' : ''; ?>" data-type="mapped" data-eem-action="toggle-rv-inventory-type"><?php esc_html_e( 'Mapped', 'equine-event-manager' ); ?></button>
</div>
<input type="hidden" name="rv_inventory_type" id="eem-rv-inventory-type-input" value="<?php echo esc_attr( $rv_inv_type ); ?>">
<span class="eem-field-hint eem-rv-inventory-type-hint"><?php
	echo esc_html( $rv_is_mapped
		? __( 'Specific RV lots exist — define them with the layout below.', 'equine-event-manager' )
		: __( 'Sell a total count with no specific lots (first come, first served).', 'equine-event-manager' ) );
?></span>
<?php
$rv_type_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'RV Inventory Type', 'equine-event-manager' ),
	'label_sub'    => __( 'Do specific lots exist, or just a count?', 'equine-event-manager' ),
	'row_id'       => 'eem-row-rv-inventory-type',
	'control_html' => $rv_type_html,
) );

// ── Control 2: Customer Selection ──
ob_start();
?>
<div class="eem-mode-btns">
	<button type="button" class="eem-mode-btn<?php echo $rv_is_pick ? '' : ' active'; ?>" data-selection="quantity" data-eem-action="toggle-rv-customer-selection"><?php esc_html_e( 'Quantity', 'equine-event-manager' ); ?></button>
	<button type="button" class="eem-mode-btn<?php echo $rv_is_pick ? ' active' : ''; ?><?php echo $rv_is_mapped ? '' : ' is-disabled'; ?>" data-selection="pick_layout" data-eem-action="toggle-rv-customer-selection" <?php disabled( ! $rv_is_mapped ); ?>><?php esc_html_e( 'Pick from layout', 'equine-event-manager' ); ?></button>
</div>
<input type="hidden" name="rv_customer_selection" id="eem-rv-customer-selection-input" value="<?php echo esc_attr( $rv_cust_sel ); ?>">
<input type="hidden" name="rv_selection_mode" id="eem-rv-selection-mode-input" value="<?php echo esc_attr( $rv_legacy_mode ); ?>">
<span class="eem-field-hint eem-rv-customer-selection-hint"><?php
	echo esc_html( $rv_is_pick
		? __( 'Customers select specific lots from your layout at checkout.', 'equine-event-manager' )
		: __( 'Customers pick how many lots they need; you assign specific lots on the Stall & RV Charts page.', 'equine-event-manager' ) );
?></span>
<?php
$rv_sel_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Customer Selection', 'equine-event-manager' ),
	'label_sub'    => __( 'How do customers choose lots at checkout?', 'equine-event-manager' ),
	'row_id'       => 'eem-row-rv-customer-selection',
	'control_html' => $rv_sel_html,
) );

// Available RV Inventory (dual-state: editable in Bulk mode, computed in Mapped mode)
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

// Max RV Lots Per Customer (per-customer purchase limit)
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

// ── RV Mapped-content wrapper opens here (C8) ──
// Load rows from $data (pre-populated by get_meta_values()).
// NOTE: use $data, NOT a direct post-meta call with get_the_ID() — on custom admin pages
// (admin.php?page=...) the global $post is not set by WordPress, so that function returns 0.

// v4 RV map connection check (for map builder section visibility).
$rv_map_zones_snap = ( isset( $data['rv_map'] ) && is_array( $data['rv_map'] ) ) ? $data['rv_map'] : array();
if ( empty( $rv_map_zones_snap['barns'] ) && class_exists( 'EEM_Stall_Map_Importer' ) ) {
	$rv_pm_rid  = (int) ( $data['_reservation_id'] ?? get_the_ID() );
	$rv_pm_snap = $rv_pm_rid > 0 ? EEM_Stall_Map_Importer::get_for_reservation( $rv_pm_rid, EEM_Stall_Map_Importer::RV_META_KEY ) : array();
	if ( ! empty( $rv_pm_snap['barns'] ) ) {
		$rv_map_zones_snap = $rv_pm_snap;
		$data['rv_map']    = $rv_pm_snap;
	}
}
$rv_map_connected = ! empty( $rv_map_zones_snap['barns'] );

$rv_rows_meta = isset( $data['rv_rows'] ) ? $data['rv_rows'] : array();
$rv_rows      = ( is_array( $rv_rows_meta ) && ! empty( $rv_rows_meta ) )
	? $rv_rows_meta
	: array();

$blocked_rv_lots_meta = isset( $data['blocked_rv_lots'] ) ? $data['blocked_rv_lots'] : array();
$blocked_rv_lots      = is_array( $blocked_rv_lots_meta ) ? $blocked_rv_lots_meta : array();

// RV Lot Map (parallel to stall_map_id; new in 2.3.23)
$rv_lot_map_id   = (int) ( isset( $data['rv_lot_map_id'] ) ? $data['rv_lot_map_id'] : 0 );
$rv_lot_map_url  = $rv_lot_map_id ? wp_get_attachment_url( $rv_lot_map_id ) : '';
$rv_lot_map_name = $rv_lot_map_id ? basename( (string) get_attached_file( $rv_lot_map_id ) ) : '';
?>
<div id="eem-rv-mapped-content"
	style="<?php echo $rv_is_mapped ? '' : 'display:none;'; ?>">
<?php

// ── v4 RV Mapping — native RV Map Builder (its OWN map slot, separate from the
// stall map). Every tab is an RV zone; every numbered cell an RV lot. ──
$rv_map_snap = ( isset( $data['rv_map'] ) && is_array( $data['rv_map'] ) ) ? $data['rv_map'] : array();
$rv_seed     = array();
foreach ( ( $rv_map_snap['barns'] ?? array() ) as $rv_seed_barn ) {
	$rv_seed[] = array(
		'name'      => (string) ( $rv_seed_barn['name'] ?? '' ),
		'grid'      => ( $rv_seed_barn['grid'] ?? array() ),
		// Slice 3: carry the painted-area registry + tab surcharge so the builder
		// re-opens with the surcharge work intact (cell.area travels inside grid).
		'areas'     => ( $rv_seed_barn['areas'] ?? array() ),
		'surcharge' => ( $rv_seed_barn['surcharge'] ?? null ),
	);
}
ob_start();
?>
<div class="eem-stall-map-connect" data-eem-rv-map data-eem-rv-map-total="<?php echo (int) ( ! empty( $rv_map_snap['barns'] ) ? EEM_Stall_Map_Importer::count_stalls( $rv_map_snap ) : 0 ); ?>">
	<?php // The inline RV Map Builder below is always visible in Pick-from-layout
	// mode, so the separate "Edit Map" button is unnecessary. ?>
	<div class="eem-stall-map-status" data-eem-rv-map-status>
		<?php
		if ( ! empty( $rv_map_snap['barns'] ) ) {
			$rvm_counts = EEM_Stall_Map_Importer::barn_stall_counts( $rv_map_snap );
			$rvm_total  = EEM_Stall_Map_Importer::count_stalls( $rv_map_snap );
			$rvm_bits   = array();
			foreach ( $rvm_counts as $rvm_zn => $rvm_zc ) {
				$rvm_bits[] = esc_html( $rvm_zn ) . ' (' . (int) $rvm_zc . ')';
			}
			echo '<span class="eem-stall-map-ok">&#x2713; ' . esc_html( sprintf( /* translators: %d: zone count */ _n( '%d zone', '%d zones', count( $rvm_counts ), 'equine-event-manager' ), count( $rvm_counts ) ) ) . ' &middot; ' . (int) $rvm_total . ' ' . esc_html__( 'lots total', 'equine-event-manager' ) . '</span> ';
			echo '<span class="eem-stall-map-barns">' . implode( ', ', $rvm_bits ) . '</span>'; // phpcs:ignore -- bits pre-escaped
		}
		?>
	</div>
	<script type="application/json" id="eem-map-seed-rv"><?php echo wp_json_encode( $rv_seed ); // phpcs:ignore -- JSON seed for the Map Builder ?></script>
	<div class="eem-mb-inline-host" data-eem-map-host="rv"></div>
</div>
<?php
$rv_map_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Interactive RV Map', 'equine-event-manager' ),
	'label_sub'    => __( 'Draw your RV layout — no spreadsheet', 'equine-event-manager' ),
	'row_id'       => 'row-rv-map-connect',
	'control_html' => $rv_map_html,
	// v4 RV two-control: the map is the layout source for Pick-from-layout only.
	'is_hidden'    => ! $rv_is_pick,
) );

// ── Lot Rows builder ──
ob_start();
?>
<div class="eem-row-builder-summary" style="margin-bottom:10px" id="eem-rv-row-summary"></div>
<div class="eem-row-builder" id="eem-rv-row-builder-list">
<?php foreach ( $rv_rows as $ri => $row ) :
	$r_name       = isset( $row['name'] )              ? (string) $row['name']              : '';
	$r_first      = isset( $row['first'] )             ? (string) $row['first']             : '';
	$r_last       = isset( $row['last'] )              ? (string) $row['last']              : '';
	$r_surcharge  = isset( $row['nightly_surcharge'] ) ? $fmt_money( $row['nightly_surcharge'] ) : '0.00';
	?>
	<div class="eem-row-card eem-row-card--inline" data-row-index="<?php echo (int) $ri; ?>">
		<input type="hidden" name="eem_rv_rows[<?php echo (int) $ri; ?>][layout]" value="one-sided">
		<div class="eem-row-card-top">
			<div class="eem-row-card-field" style="flex:2 1 160px">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'Row Name', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][name]" value="<?php echo esc_attr( $r_name ); ?>" data-eem-input-action="rv-row-input">
			</div>
			<div class="eem-row-card-field" style="flex:1 1 100px">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'First Lot', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][first]" value="<?php echo esc_attr( $r_first ); ?>" data-role="first" data-eem-input-action="rv-row-input">
			</div>
			<div class="eem-row-card-field" style="flex:1 1 100px">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'Last Lot', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][last]" value="<?php echo esc_attr( $r_last ); ?>" data-role="last" data-eem-input-action="rv-row-input">
			</div>
			<div class="eem-row-card-field" style="flex:1 1 120px">
				<span class="eem-row-card-field-label"><?php esc_html_e( '+ Nightly Surcharge', 'equine-event-manager' ); ?></span>
				<div class="eem-zone-price-wrap"><span class="eem-zone-price-sym">$</span><input class="eem-zone-price-in" type="number" step="0.01" min="0" name="eem_rv_rows[<?php echo (int) $ri; ?>][nightly_surcharge]" value="<?php echo esc_attr( $r_surcharge ); ?>" data-eem-input-action="rv-row-input"></div>
			</div>
			<button class="eem-row-card-delete" type="button" title="<?php esc_attr_e( 'Delete row', 'equine-event-manager' ); ?>" data-eem-action="rv-delete-row">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
			</button>
		</div>
		<div>
			<div class="eem-row-card-preview-label"><?php esc_html_e( 'Preview', 'equine-event-manager' ); ?> <span class="eem-row-card-count"></span></div>
			<div class="eem-stall-row-layout"></div>
		</div>
	</div>
<?php endforeach; ?>
</div>
<button class="eem-row-add-btn" type="button" data-eem-action="rv-add-row">
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
	<?php esc_html_e( 'Add Row', 'equine-event-manager' ); ?>
</button>
<span class="eem-field-hint"><?php esc_html_e( 'Each row name becomes a tab on the customer map. The surcharge is added to the base RV rate per night for lots in that row.', 'equine-event-manager' ); ?></span>
<?php
$rv_rows_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Lot Rows', 'equine-event-manager' ),
	'label_sub'    => __( 'Define the physical layout customers will see', 'equine-event-manager' ),
	'row_id'       => 'row-rv-rows-builder',
	'control_html' => $rv_rows_html,
	// v4 RV two-control: under Pick-from-layout the connected map IS the layout,
	// so the manual lot rows hide; they stay for Mapped + Quantity.
	'is_hidden'    => $rv_is_pick,
) );

// v2 Venues Slice 3 — Save/Load Layout buttons now live in the RV Map Builder's
// legend bar (data-eem-action="venue-save-layout"/"venue-load-layout"), handled
// by the same delegated venue-layouts.js. The standalone card below the builder
// was removed for a cleaner editor.

// ── Blocked RV Lots ──
// The standalone tag-select field was removed; blocking now lives in the Map
// Builder's Block tool + search (eem-map-builder.js reads/writes this hidden
// input live, persisted on Update Reservation through the existing meta path).
?>
<input type="hidden" name="eem_blocked_rv_lots" id="eem-blocked-rv-lots-input" value="<?php echo esc_attr( implode( ',', array_map( 'strval', $blocked_rv_lots ) ) ); ?>">
<?php

// ── RV Lot Map file upload (parallel to Stall Map; new in 2.3.23) ──
ob_start();
?>
<div class="eem-file-row">
	<span class="eem-file-name" id="eem-rv-lot-map-name"><?php echo $rv_lot_map_name ? esc_html( $rv_lot_map_name ) : ''; ?></span>
	<button class="eem-btn-upload" type="button" data-eem-action="rv-lot-map-upload"><?php esc_html_e( 'Upload File', 'equine-event-manager' ); ?></button>
	<button class="eem-btn-file-del" type="button" aria-label="<?php esc_attr_e( 'Remove file', 'equine-event-manager' ); ?>" data-eem-action="rv-lot-map-remove"<?php echo $rv_lot_map_id ? '' : ' style="display:none"'; ?>>
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
	</button>
	<?php if ( $rv_lot_map_url ) : ?>
		<a class="eem-view-link" href="<?php echo esc_url( $rv_lot_map_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View file', 'equine-event-manager' ); ?></a>
	<?php endif; ?>
	<input type="hidden" name="eem_rv_lot_map_id" id="eem-rv-lot-map-id" value="<?php echo esc_attr( (string) $rv_lot_map_id ); ?>">
</div>
<span class="eem-field-hint"><?php esc_html_e( 'Optional: upload a visual map of the RV lot layout for customer reference.', 'equine-event-manager' ); ?></span>
<?php
$rv_map_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'View Only RV Lot Map', 'equine-event-manager' ),
	'label_sub'    => __( 'PDF or image customers can open', 'equine-event-manager' ),
	'row_id'       => 'row-rv-lot-map',
	'control_html' => $rv_map_html,
) );
?>
</div>
</div><!-- .eem-layout-group -->


