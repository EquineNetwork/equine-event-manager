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
$rv_addons_on = ! empty( $data['rv_addons_enabled'] );

$rv_addons = isset( $data['rv_addons'] ) ? (array) $data['rv_addons'] : array();

$rv_pricing_mode = isset( $data['rv_pricing_mode'] ) ? (string) $data['rv_pricing_mode'] : 'nightly';
$rv_packages     = EEM_Stay_Packages_Repo::get_packages( (int) ( $data['_reservation_id'] ?? get_the_ID() ), 'rv' );
$is_rv_packages  = ( 'packages' === $rv_pricing_mode );

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

// 3a. Pricing Mode radio
ob_start();
?>
<div class="eem-mode-btns">
	<button type="button"
		class="eem-mode-btn<?php echo ! $is_rv_packages ? ' active' : ''; ?>"
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
</div>
<input type="hidden" name="en_reservation[rv_pricing_mode]" id="eem-rv-pricing-mode-input" value="<?php echo esc_attr( $rv_pricing_mode ); ?>">
<?php
$pricing_mode_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Pricing Mode', 'equine-event-manager' ),
	'label_sub'    => __( 'How customers pay for RV lots', 'equine-event-manager' ),
	'control_html' => $pricing_mode_html,
	'hint'         => __( 'Nightly Rate: single price per night with date picker. Stay Packages: named packages with fixed dates and prices.', 'equine-event-manager' ),
) );

// ── Nightly-mode content (hidden when pricing mode = packages) ──
echo '<div class="eem-rv-nightly-content" id="eem-rv-nightly-content"' . ( $is_rv_packages ? ' style="display:none"' : '' ) . '>';
// Hidden mirror — always-on nightly when in nightly mode (backend still checks this key)
echo '<input type="hidden" name="en_reservation[rv_nightly_enabled]" value="1">';

// 9. RV Nightly Rate
eem_render_editor_field_row( array(
	'label'        => __( 'RV Nightly Rate', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[rv_nightly_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['rv_nightly_rate'] ) )
	),
	'hint'         => __( 'Base nightly rate. Lot zones below may add tier-specific pricing.', 'equine-event-manager' ),
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

// 11. Early Bird toggle
ob_start();
eem_render_editor_toggle_label_row( array(
	'name'       => 'rv_early_bird_enabled',
	'subsection' => 'rv-eb',
	'label'      => __( 'Enable RV early bird pricing', 'equine-event-manager' ),
	'is_enabled' => $eb_on,
	'controls'   => array( 'row-rv-eb-cutoff', 'row-rv-eb-nightly' ),
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
	'is_hidden'    => ! $eb_on,
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" type="number" step="0.01" min="0" name="en_reservation[rv_early_bird_nightly_rate]" value="%s" /></div>',
		esc_attr( $fmt_money( $data['rv_early_bird_nightly_rate'] ) )
	),
) );

echo '</div>'; // .eem-rv-nightly-content

// ── Packages-mode content (hidden when pricing mode = nightly) ──
?>
<div class="eem-rv-packages-content" id="eem-rv-packages-content"<?php echo $is_rv_packages ? '' : ' style="display:none"'; ?>>
	<div class="eem-packages-table-wrap">
		<table class="eem-packages-table" id="eem-rv-packages-table">
			<thead>
				<tr>
					<th class="eem-packages-col-drag"></th>
					<th><?php esc_html_e( 'Name', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Start Date', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'End Date', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Price', 'equine-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Max Qty', 'equine-event-manager' ); ?></th>
					<th class="eem-packages-col-actions"></th>
				</tr>
			</thead>
			<tbody id="eem-rv-packages-tbody">
				<?php foreach ( $rv_packages as $pkg ) : ?>
				<tr data-package-id="<?php echo (int) $pkg['id']; ?>">
					<td class="eem-packages-col-drag"><span class="eem-drag-handle">&#x2630;</span></td>
					<td><?php echo esc_html( $pkg['name'] ); ?></td>
					<td><?php echo esc_html( $pkg['start_date'] ); ?></td>
					<td><?php echo esc_html( $pkg['end_date'] ); ?></td>
					<td>$<?php echo esc_html( number_format( (float) $pkg['price'], 2 ) ); ?></td>
					<td><?php echo (int) $pkg['max_quantity'] > 0 ? (int) $pkg['max_quantity'] : '&mdash;'; ?></td>
					<td class="eem-packages-col-actions">
						<button type="button" class="eem-btn-sm" data-eem-action="rv-package-edit" data-package-id="<?php echo (int) $pkg['id']; ?>"><?php esc_html_e( 'Edit', 'equine-event-manager' ); ?></button>
						<button type="button" class="eem-btn-sm eem-btn-sm--danger" data-eem-action="rv-package-delete" data-package-id="<?php echo (int) $pkg['id']; ?>"><?php esc_html_e( 'Delete', 'equine-event-manager' ); ?></button>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( empty( $rv_packages ) ) : ?>
		<p class="eem-packages-empty" id="eem-rv-packages-empty"><?php esc_html_e( 'No packages yet. Click "+ Add Package" to create one.', 'equine-event-manager' ); ?></p>
		<?php endif; ?>
	</div>

	<div class="eem-package-inline-form" id="eem-rv-package-form" style="display:none">
		<div class="eem-package-form-fields">
			<div class="eem-package-form-field">
				<label><?php esc_html_e( 'Name', 'equine-event-manager' ); ?></label>
				<input type="text" class="eem-field-input" id="eem-rv-pkg-name" placeholder="<?php esc_attr_e( 'e.g. Week 1, Full Event', 'equine-event-manager' ); ?>">
			</div>
			<div class="eem-package-form-field">
				<label><?php esc_html_e( 'Start Date', 'equine-event-manager' ); ?></label>
				<input type="date" class="eem-field-input" id="eem-rv-pkg-start">
			</div>
			<div class="eem-package-form-field">
				<label><?php esc_html_e( 'End Date', 'equine-event-manager' ); ?></label>
				<input type="date" class="eem-field-input" id="eem-rv-pkg-end">
			</div>
			<div class="eem-package-form-field">
				<label><?php esc_html_e( 'Price', 'equine-event-manager' ); ?></label>
				<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input type="number" step="0.01" min="0" class="eem-price-input" id="eem-rv-pkg-price"></div>
			</div>
			<div class="eem-package-form-field">
				<label><?php esc_html_e( 'Max Qty', 'equine-event-manager' ); ?></label>
				<input type="number" min="0" step="1" class="eem-field-input" id="eem-rv-pkg-max-qty" placeholder="<?php esc_attr_e( 'Unlimited', 'equine-event-manager' ); ?>" style="max-width:100px">
			</div>
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
</div>
<?php

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
// Load meta from $data (pre-populated by get_meta_values()) or fall back to seeded zones / rows.
// NOTE: use $data, NOT a direct post-meta call with get_the_ID() — on custom admin pages
// (admin.php?page=...) the global $post is not set by WordPress, so that function returns 0.
// 2.3.82: seed zones/rows removed — a new reservation starts with empty RV lot
// pricing + an empty RV Row Builder. Admins add their own.
$rv_zones_meta = isset( $data['rv_zones'] ) ? $data['rv_zones'] : array();
$rv_zones      = ( is_array( $rv_zones_meta ) && ! empty( $rv_zones_meta ) )
	? $rv_zones_meta
	: array();

// v4 RV two-control: when an RV map is connected, the ZONES are the sheet's tabs
// (Red Lot, Yellow Lot, Blue Lot). Auto-populate the zone list from the tab
// names so the admin only fills in pricing — merging any saved pricing matched
// by zone name. The names become read-only (they come from the map).
$rv_map_zones_snap = ( isset( $data['rv_map'] ) && is_array( $data['rv_map'] ) ) ? $data['rv_map'] : array();
// Fall back to the canonical post-meta (_en_rv_map) the RV chart + Save Map use,
// so a map saved before the config-sync fix still seeds the builder.
if ( empty( $rv_map_zones_snap['barns'] ) && class_exists( 'EEM_Stall_Map_Importer' ) ) {
	$rv_pm_rid  = (int) ( $data['_reservation_id'] ?? get_the_ID() );
	$rv_pm_snap = $rv_pm_rid > 0 ? EEM_Stall_Map_Importer::get_for_reservation( $rv_pm_rid, EEM_Stall_Map_Importer::RV_META_KEY ) : array();
	if ( ! empty( $rv_pm_snap['barns'] ) ) {
		$rv_map_zones_snap = $rv_pm_snap;
		$data['rv_map']    = $rv_pm_snap; // keep the later seed read (line ~509) consistent
	}
}
$rv_map_connected  = ! empty( $rv_map_zones_snap['barns'] );
if ( $rv_map_connected && class_exists( 'EEM_Stall_Map_Importer' ) ) {
	$saved_pricing = array(); // lowercase zone name => [nightly, weekend]
	foreach ( (array) $rv_zones as $sz ) {
		$sz_name = isset( $sz['name'] ) ? strtolower( trim( (string) $sz['name'] ) ) : '';
		if ( '' !== $sz_name ) {
			$saved_pricing[ $sz_name ] = array(
				'nightly' => isset( $sz['nightly'] ) ? $sz['nightly'] : '0.00',
				'weekend' => isset( $sz['weekend'] ) ? $sz['weekend'] : '0.00',
			);
		}
	}
	$rv_zones = array();
	foreach ( EEM_Stall_Map_Importer::barn_names( $rv_map_zones_snap ) as $tab_name ) {
		$tab_name = (string) $tab_name;
		$key      = strtolower( trim( $tab_name ) );
		$rv_zones[] = array(
			'name'    => $tab_name,
			'nightly' => isset( $saved_pricing[ $key ]['nightly'] ) ? $saved_pricing[ $key ]['nightly'] : '0.00',
			'weekend' => isset( $saved_pricing[ $key ]['weekend'] ) ? $saved_pricing[ $key ]['weekend'] : '0.00',
		);
	}
}

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

/**
 * V1 ZONE MODEL (2.3.22, 2026-05-30):
 * Each rv_row has a single zone_id field. All lots in a row belong to that zone.
 * C10 ENFORCEMENT CONTRACT: rows with no zone_id = lots in that row are UNAVAILABLE.
 * See: docs/c10-contracts.md
 *
 * V2 BACKLOG — RV Painting and Advanced Inventory (deferred from V1, 2026-05-30):
 *
 * The following features were considered for V1 but deferred to V2 to ship a
 * coherent, simple inventory model for the June 12, 2026 test-ready milestone:
 *
 * - Per-lot painting: admin clicks individual lots to assign them to zones,
 *   useful when a row contains lots from multiple zones (e.g., premium corner
 *   spots vs. standard interior). V1 assigns zones at row level only.
 *
 * - Per-lot color dots: visual indicator of zone membership at lot granularity.
 *
 * - Per-zone Avail Qty (admin-entered): separate inventory cap independent of
 *   configured lots. V1 uses row count as inventory truth (computed from rows).
 *
 * - Bulk-with-zones mode: quantity per zone without row builder. V1 has only
 *   Bulk (no zones) and Mapped (rows with zone dropdowns).
 *
 * See docs/c10-contracts.md V2 BACKLOG section for full design conversation history.
 */
?>
<div id="eem-rv-mapped-content"
	style="<?php echo $rv_is_mapped ? '' : 'display:none;'; ?>">
<?php

// ── RV Lot Zones (nightly / weekend / available_qty) ──
ob_start();
?>
<div class="eem-zone-list" id="eem-lot-zones-list">
	<?php foreach ( $rv_zones as $zi => $zone ) :
		$z_name    = isset( $zone['name'] )          ? (string) $zone['name']                        : '';
		// Zone swatch color is computed from position index using the canonical auto-palette
		// (matches getZoneColor() in admin.js). Stored 'color' field is no longer used — color
		// is never saved to meta. Do NOT use $data['rv_zones'][N]['color'] here.
		$_palette  = array( '#DC2626', '#2563EB', '#16A34A', '#CA8A04', '#9333EA', '#EA580C' );
		$z_color   = $_palette[ $zi % count( $_palette ) ];
		$z_night   = isset( $zone['nightly'] )        ? $fmt_money( $zone['nightly'] )                : '0.00';
		?>
		<div class="eem-zone-row" data-zone-index="<?php echo (int) $zi; ?>">
			<div class="eem-zone-color-swatch" style="background:<?php echo esc_attr( $z_color ); ?>"></div>
			<input class="eem-zone-name-input" type="text" name="eem_rv_zones[<?php echo (int) $zi; ?>][name]" value="<?php echo esc_attr( $z_name ); ?>" placeholder="<?php esc_attr_e( 'Zone name', 'equine-event-manager' ); ?>" data-eem-input-action="rv-zone-input"<?php echo $rv_map_connected ? ' title="' . esc_attr__( 'Renaming this zone renames its tab on the RV map', 'equine-event-manager' ) . '"' : ''; ?>>
			<div class="eem-zone-price-group">
				<span class="eem-zone-price-label"><?php esc_html_e( '+ Nightly', 'equine-event-manager' ); ?></span>
				<div class="eem-zone-price-wrap"><span class="eem-zone-price-sym">$</span><input class="eem-zone-price-in" type="number" step="0.01" min="0" name="eem_rv_zones[<?php echo (int) $zi; ?>][nightly]" value="<?php echo esc_attr( $z_night ); ?>" data-eem-input-action="rv-zone-input"></div>
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
<?php if ( $rv_map_connected ) : ?>
<span class="eem-field-hint" style="display:block;margin-top:8px"><?php esc_html_e( 'Zones and your RV Map tabs stay in sync — add or rename a zone here and its tab appears on the map below to draw. Set the pricing for each.', 'equine-event-manager' ); ?></span>
<?php endif; ?>
<template id="eem-lot-zone-row-template">
<div class="eem-zone-row" data-zone-index="__index__">
	<!-- Swatch color is set by rvAddZone() in admin.js using getZoneColor(newIndex). -->
	<div class="eem-zone-color-swatch" style="background:#9CA3AF"></div>
	<input class="eem-zone-name-input" type="text" name="eem_rv_zones[__index__][name]" value="" placeholder="<?php esc_attr_e( 'Zone name', 'equine-event-manager' ); ?>" data-eem-input-action="rv-zone-input">
	<div class="eem-zone-price-group">
		<span class="eem-zone-price-label"><?php esc_html_e( '+ Nightly', 'equine-event-manager' ); ?></span>
		<div class="eem-zone-price-wrap"><span class="eem-zone-price-sym">$</span><input class="eem-zone-price-in" type="number" step="0.01" min="0" name="eem_rv_zones[__index__][nightly]" value="0.00" data-eem-input-action="rv-zone-input"></div>
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

// ── v4 RV Mapping — native RV Map Builder (its OWN map slot, separate from the
// stall map). Every tab is an RV zone; every numbered cell an RV lot. Ordered
// after RV Lot Zones per the editor field order. ──
$rv_map_snap = ( isset( $data['rv_map'] ) && is_array( $data['rv_map'] ) ) ? $data['rv_map'] : array();
$rv_seed     = array();
foreach ( ( $rv_map_snap['barns'] ?? array() ) as $rv_seed_barn ) {
	$rv_seed[] = array( 'name' => (string) ( $rv_seed_barn['name'] ?? '' ), 'grid' => ( $rv_seed_barn['grid'] ?? array() ) );
}
ob_start();
?>
<div class="eem-stall-map-connect" data-eem-rv-map data-eem-rv-map-total="<?php echo (int) ( ! empty( $rv_map_snap['barns'] ) ? EEM_Stall_Map_Importer::count_stalls( $rv_map_snap ) : 0 ); ?>">
	<div class="eem-stall-map-row">
		<button type="button" class="eem-btn-add" data-eem-action="open-map-builder" data-target="rv"><?php echo ! empty( $rv_map_snap['barns'] ) ? esc_html__( 'Edit Map', 'equine-event-manager' ) : esc_html__( 'Build Map', 'equine-event-manager' ); ?></button>
	</div>
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
<span class="eem-field-hint"><?php esc_html_e( 'Used when RV Selection is "Pick from layout". Click Build Map to draw your RV layout — add a tab per zone, then drag to number the lots. Each tab becomes a zone in RV Lot Zones above, where you set its pricing.', 'equine-event-manager' ); ?></span>
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
<?php // Sentinel: lets the save handler clear zones when all are deleted (same
// "delete-all no-ops" fix as stall rows). ?>
<input type="hidden" name="eem_rv_zones_present" value="1">
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
	$r_zone_id   = isset( $row['zone_id'] )    ? (string) $row['zone_id']   : '';
	$is_b2b      = ( 'back-to-back' === $r_layout );
	?>
	<div class="eem-row-card" data-row-index="<?php echo (int) $ri; ?>">
		<div class="eem-row-card-top">
			<div class="eem-row-card-field">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'Row Name', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][name]" value="<?php echo esc_attr( $r_name ); ?>" data-eem-input-action="rv-row-input">
			</div>
			<input type="hidden" name="eem_rv_rows[<?php echo (int) $ri; ?>][layout]" value="one-sided">
			<div class="eem-row-card-field eem-row-card-field-layout">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'Zone', 'equine-event-manager' ); ?></span>
				<select name="eem_rv_rows[<?php echo (int) $ri; ?>][zone_id]" data-eem-input-action="rv-row-input" data-field="zone_id">
					<option value=""><?php esc_html_e( 'Unassigned', 'equine-event-manager' ); ?></option>
					<?php foreach ( $rv_zones as $zi => $zone ) :
						$z_name_opt = isset( $zone['name'] ) ? (string) $zone['name'] : '';
						?>
						<option value="<?php echo esc_attr( (string) $zi ); ?>"<?php selected( $r_zone_id, (string) $zi ); ?>><?php echo esc_html( $z_name_opt ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<button class="eem-row-card-delete" type="button" title="<?php esc_attr_e( 'Delete row', 'equine-event-manager' ); ?>" data-eem-action="rv-delete-row">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
			</button>
		</div>
		<div class="eem-row-card-one-sided">
			<div class="eem-row-card-field">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'First Lot Label', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][first]" value="<?php echo esc_attr( $r_first ); ?>" data-role="first" data-eem-input-action="rv-row-input">
			</div>
			<div class="eem-row-card-field">
				<span class="eem-row-card-field-label"><?php esc_html_e( 'Last Lot Label', 'equine-event-manager' ); ?></span>
				<input type="text" name="eem_rv_rows[<?php echo (int) $ri; ?>][last]" value="<?php echo esc_attr( $r_last ); ?>" data-role="last" data-eem-input-action="rv-row-input">
			</div>
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
<span class="eem-field-hint"><?php esc_html_e( 'Each row must be assigned to a zone. Rows without a zone assignment are unavailable to customers at checkout.', 'equine-event-manager' ); ?></span>
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

// v2 Venues Slice 3 — Save Layout / Load Layout to the reservation's venue
// (combined layout — same action as the stall builder's bar).
$context = 'rv';
require EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_layout-template-bar.php';

// ── Blocked RV Lots tag-select ──
ob_start();
?>
<div class="eem-tag-select" id="eem-blocked-rv-lots-select">
	<div class="eem-tag-select-input" data-eem-action="tag-open">
		<input type="hidden" name="eem_blocked_rv_lots" value="<?php echo esc_attr( implode( ',', array_map( 'strval', $blocked_rv_lots ) ) ); ?>">
		<?php foreach ( $blocked_rv_lots as $bl_val ) : ?>
		<span class="eem-tag-chip" data-value="<?php echo esc_attr( (string) $bl_val ); ?>">
			<?php echo esc_html( (string) $bl_val ); ?>
			<button type="button" class="eem-tag-chip-remove" data-eem-action="tag-remove" aria-label="<?php esc_attr_e( 'Remove', 'equine-event-manager' ); ?>">&#xd7;</button>
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
<?php
// Zone names available to restrict an add-on to (RV map mode). Empty list = the
// Zones column is hidden (no zones defined yet → add-ons apply to everything).
$rv_addon_zone_names = array();
foreach ( (array) $rv_zones as $rvz ) {
	$zn = isset( $rvz['name'] ) ? trim( (string) $rvz['name'] ) : '';
	if ( '' !== $zn ) {
		$rv_addon_zone_names[] = $zn;
	}
}
$rv_addon_has_zones = ! empty( $rv_addon_zone_names );
// Click-to-toggle zone pills (styled checkboxes — no native multi-select / Ctrl-
// click). Each checked pill submits its zone as zones[]; none checked = all zones.
$rv_addon_zone_pills = function ( $field_name, array $selected ) use ( $rv_addon_zone_names ) {
	$sel  = array_map( 'strtolower', array_map( 'trim', $selected ) );
	$html = '<div class="eem-zone-pills">';
	foreach ( $rv_addon_zone_names as $zn ) {
		$is = in_array( strtolower( $zn ), $sel, true );
		$html .= '<label class="eem-zone-pill"><input type="checkbox" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $zn ) . '"' . ( $is ? ' checked' : '' ) . '><span>' . esc_html( $zn ) . '</span></label>';
	}
	$html .= '</div>';
	return $html;
};
?>
<div id="rv-addons-table-wrap" <?php echo $rv_addons_on ? '' : 'style="display:none"'; ?>>
	<p class="eem-field-help eem-rv-addons-help">
		<?php esc_html_e( 'Add-on prices are charged per night in addition to the RV rate the customer selects. Fill in only the rate(s) you offer.', 'equine-event-manager' ); ?>
		<?php if ( $rv_addon_has_zones ) : ?>
			<br><?php esc_html_e( 'Leave Zones empty to offer an add-on for every zone, or pick specific zones to restrict it (e.g. Sewer Hookup only for Red Lot).', 'equine-event-manager' ); ?>
		<?php endif; ?>
	</p>
	<table class="eem-repeat-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Add-On', 'equine-event-manager' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Per Night', 'equine-event-manager' ); ?></th>
				<?php if ( $rv_addon_has_zones ) : ?>
					<th style="width:170px"><?php esc_html_e( 'Zones', 'equine-event-manager' ); ?></th>
				<?php endif; ?>
				<th style="width:40px"></th>
			</tr>
		</thead>
		<tbody id="eem-rv-addons-rows">
			<?php foreach ( $rv_addons as $idx => $addon ) :
				$a_name    = isset( $addon['name'] ) ? (string) $addon['name'] : '';
				$a_price   = isset( $addon['price'] ) ? (float) $addon['price'] : 0.0;
				$a_zones   = ( isset( $addon['zones'] ) && is_array( $addon['zones'] ) ) ? array_map( 'strval', $addon['zones'] ) : array();
				?>
				<tr>
					<td><input class="eem-repeat-input" type="text" name="en_reservation[rv_addons][<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr( $a_name ); ?>" /></td>
					<td><div class="eem-repeat-price-wrap"><span class="eem-repeat-price-sym">$</span><input class="eem-repeat-price-in" type="number" step="0.01" min="0" name="en_reservation[rv_addons][<?php echo (int) $idx; ?>][price]" value="<?php echo esc_attr( $fmt_money( $a_price ) ); ?>" /></div></td>
					<?php if ( $rv_addon_has_zones ) : ?>
						<td><?php echo $rv_addon_zone_pills( 'en_reservation[rv_addons][' . (int) $idx . '][zones][]', $a_zones ); // phpcs:ignore -- pre-escaped ?></td>
					<?php endif; ?>
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
		<?php if ( $rv_addon_has_zones ) : ?>
			<td><?php echo $rv_addon_zone_pills( 'en_reservation[rv_addons][__index__][zones][]', array() ); // phpcs:ignore -- pre-escaped ?></td>
		<?php endif; ?>
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


