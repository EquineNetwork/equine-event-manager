<?php
/**
 * Reservation Editor — "General Add-Ons" section body
 * (C7.X.4 mockup-canonical rewrite).
 *
 * Mockup lines 889–936. Single field-row containing the
 * .eem-repeat-table with columns: Add-On Name / Price (120px) /
 * Unit (120px) / Action (40px). Replaces the legacy
 * .eem-admin-table-field chrome with mockup-canonical .eem-repeat-*
 * primitives.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed>            $data */
/** @var array<int, array<string, mixed>> $addons */

require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-field-row.php';

$fmt_money = function ( $v ) { return number_format( (float) $v, 2, '.', '' ); };
?>
<input type="hidden" name="en_reservation[general_addons_enabled]" data-eem-section-enabled="addons" value="<?php echo ! empty( $data['general_addons_enabled'] ) ? '1' : '0'; ?>" />

<div class="eem-addon-block">
	<h4 class="eem-addon-block__title"><?php esc_html_e( 'Add-Ons', 'equine-event-manager' ); ?></h4>
	<p class="eem-addon-block__help"><?php esc_html_e( 'Optional items customers can purchase', 'equine-event-manager' ); ?></p>
	<table class="eem-repeat-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Add-On Name', 'equine-event-manager' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Price', 'equine-event-manager' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Unit', 'equine-event-manager' ); ?></th>
				<th style="width:40px"></th>
			</tr>
		</thead>
		<tbody id="eem-general-addons-rows">
			<?php foreach ( (array) $addons as $idx => $addon ) :
				$a_name = isset( $addon['name'] ) ? (string) $addon['name'] : '';
				$a_price = isset( $addon['price'] ) ? (float) $addon['price'] : 0.0;
				$a_unit  = isset( $addon['per_label'] ) ? (string) $addon['per_label'] : '';
				?>
				<tr>
					<td><input class="eem-repeat-input" type="text" name="en_reservation[general_addons][<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr( $a_name ); ?>" /></td>
					<td><div class="eem-repeat-price-wrap"><span class="eem-repeat-price-sym">$</span><input class="eem-repeat-price-in" type="number" step="0.01" min="0" name="en_reservation[general_addons][<?php echo (int) $idx; ?>][price]" value="<?php echo esc_attr( $fmt_money( $a_price ) ); ?>" /></div></td>
					<td><input class="eem-repeat-input" type="text" name="en_reservation[general_addons][<?php echo (int) $idx; ?>][per_label]" value="<?php echo esc_attr( $a_unit ); ?>" placeholder="<?php esc_attr_e( 'bag, bale, each', 'equine-event-manager' ); ?>" /></td>
					<td><button class="eem-btn-delete" type="button" aria-label="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-remove-repeating-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></td>
					<input type="hidden" name="en_reservation[general_addons][<?php echo (int) $idx; ?>][applies_to]" value="any" />
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<button class="eem-btn-add" type="button" data-eem-action="reservation-editor-add-repeating-row" data-eem-repeating-template="eem-general-addons-row-template" data-eem-repeating-tbody="eem-general-addons-rows">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
		<?php esc_html_e( 'Add Add-On', 'equine-event-manager' ); ?>
	</button>
	<template id="eem-general-addons-row-template"><tr>
		<td><input class="eem-repeat-input" type="text" name="en_reservation[general_addons][__index__][name]" value="" placeholder="<?php esc_attr_e( 'Add-on name', 'equine-event-manager' ); ?>" /></td>
		<td><div class="eem-repeat-price-wrap"><span class="eem-repeat-price-sym">$</span><input class="eem-repeat-price-in" type="number" step="0.01" min="0" name="en_reservation[general_addons][__index__][price]" value="0.00" /></div></td>
		<td><input class="eem-repeat-input" type="text" name="en_reservation[general_addons][__index__][per_label]" value="" placeholder="<?php esc_attr_e( 'bag, bale, each', 'equine-event-manager' ); ?>" /></td>
		<td><button class="eem-btn-delete" type="button" aria-label="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-remove-repeating-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></td>
		<input type="hidden" name="en_reservation[general_addons][__index__][applies_to]" value="any" />
	</tr></template>
</div>
