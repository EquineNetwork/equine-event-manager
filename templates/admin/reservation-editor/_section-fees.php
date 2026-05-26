<?php
/**
 * Reservation Editor — "Convenience Fee" section body
 * (C7.C.1.4.A rewrite — mockup-canonical chrome).
 *
 * Mockup lines 1004–1052. Three-button fee-mode pill triplet
 * (None / Flat Amount / Percentage) replacing the legacy <select>
 * dropdown. Conditional Flat row vs Percentage row based on selection.
 * Percentage input has the % symbol on the RIGHT side (inverted from
 * .eem-price-wrap which puts $ on the left).
 *
 * Walkthrough enumeration:
 *   4.4 fee-modes pill triplet → .eem-fee-modes / .eem-fee-mode-btn
 *                                .active on selected; persisted as
 *                                en_reservation[convenience_fee_type]
 *                                via hidden input mirror
 *   4.5 hint → "Non-refundable. Displayed to customers at checkout
 *              as 'Non-Refundable Convenience Fee.' Sales tax is
 *              configured globally in Settings → Payments."
 *   4.7 Flat row → .eem-price-wrap, hidden when type !== 'flat'
 *                  en_reservation[convenience_fee_value]
 *                  + hint: "Charged once per order, regardless of
 *                  order total."
 *   4.9 Percentage row → .eem-pct-wrap (% symbol on RIGHT)
 *                        hidden when type !== 'percentage'
 *                        same persisted field as Flat
 *                        + hint: "Calculated as a percentage of the
 *                        order subtotal."
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

$current_type = isset( $data['convenience_fee_type'] ) ? (string) $data['convenience_fee_type'] : 'none';
if ( ! in_array( $current_type, array( 'none', 'flat', 'percentage' ), true ) ) {
	$current_type = 'none';
}
$current_value = isset( $data['convenience_fee_value'] ) ? (float) $data['convenience_fee_value'] : 0.0;

// Fee-mode pill buttons: build .eem-fee-mode-btn triplet
$modes = array(
	'none'       => __( 'None', 'equine-event-manager' ),
	'flat'       => __( 'Flat Amount', 'equine-event-manager' ),
	'percentage' => __( 'Percentage', 'equine-event-manager' ),
);
$buttons_html = '';
foreach ( $modes as $slug => $label ) {
	$active = $slug === $current_type ? ' eem-fee-mode-btn--active' : '';
	$buttons_html .= sprintf(
		'<button type="button" class="eem-fee-mode-btn%s" data-eem-action="reservation-editor-fee-mode" data-eem-fee-mode="%s">%s</button>',
		esc_attr( $active ),
		esc_attr( $slug ),
		esc_html( $label )
	);
}
$fee_modes_html = sprintf(
	'<div class="eem-fee-modes">%s</div><input type="hidden" name="en_reservation[convenience_fee_type]" id="en_convenience_fee_type" data-eem-fee-mode-mirror value="%s" />',
	$buttons_html,
	esc_attr( $current_type )
);
?>
<input type="hidden" name="en_reservation[convenience_fee_enabled]" data-eem-section-enabled="fees" value="<?php echo ! empty( $data['convenience_fee_enabled'] ) ? '1' : '0'; ?>" />
<?php
// 4.2 Fee Type row + 4.4 pill triplet
eem_render_editor_field_row( array(
	'label'        => __( 'Fee Type', 'equine-event-manager' ),
	'label_sub'    => __( 'How the convenience fee is calculated', 'equine-event-manager' ),
	'control_html' => $fee_modes_html,
	'hint'         => __( 'Non-refundable. Displayed to customers at checkout as "Non-Refundable Convenience Fee." Sales tax is configured globally in Settings → Payments.', 'equine-event-manager' ),
) );

// 4.6 Flat Fee Amount row (conditional — visible only when type=flat)
eem_render_editor_field_row( array(
	'label'        => __( 'Flat Fee Amount', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<div class="eem-price-wrap"><span class="eem-price-symbol">$</span><input class="eem-price-input" name="en_reservation[convenience_fee_value]" id="en_convenience_fee_value_flat" type="number" step="0.01" min="0" value="%s" data-eem-fee-value="flat" /></div>',
		esc_attr( number_format( 'flat' === $current_type ? $current_value : 0.0, 2, '.', '' ) )
	),
	'hint'         => __( 'Charged once per order, regardless of order total.', 'equine-event-manager' ),
	'row_id'       => 'row-fee-flat',
	'row_classes'  => 'eem-ctrl--fee-flat',
	'is_hidden'    => 'flat' !== $current_type,
) );

// 4.8 Percentage Fee row (conditional — visible only when type=percentage)
eem_render_editor_field_row( array(
	'label'        => __( 'Percentage Fee', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<div class="eem-pct-wrap"><input class="eem-pct-input" name="en_reservation[convenience_fee_value]" id="en_convenience_fee_value_pct" type="number" step="0.1" min="0" max="100" value="%s" data-eem-fee-value="percentage" /><span class="eem-pct-symbol">%%</span></div>',
		esc_attr( 'percentage' === $current_type ? rtrim( rtrim( number_format( $current_value, 2, '.', '' ), '0' ), '.' ) : '0' )
	),
	'hint'         => __( 'Calculated as a percentage of the order subtotal.', 'equine-event-manager' ),
	'row_id'       => 'row-fee-pct',
	'row_classes'  => 'eem-ctrl--fee-pct',
	'is_hidden'    => 'percentage' !== $current_type,
) );
