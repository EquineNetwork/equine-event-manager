<?php
/**
 * Reservation Editor — "Additional Shavings" section body.
 *
 * Admin defines shavings product types (name + price per bag). Any product
 * defined here is semantically "shavings bags" for reporting — the Shavings
 * report aggregates required_shavings_qty + additional_shavings_qty across
 * all types without needing to inspect the product name.
 *
 * Customers see each enabled product as a qty-picker at checkout and may buy
 * any flat quantity of any type regardless of stall count. Quantities are
 * stored per-type in additional_shavings_items JSON on the stall reservation
 * row; additional_shavings_qty holds the running total for backward compat.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed> $data */

$products  = isset( $data['additional_shavings_products'] ) && is_array( $data['additional_shavings_products'] )
	? $data['additional_shavings_products']
	: array();
$fmt_money = function ( $v ) { return number_format( (float) $v, 2, '.', '' ); };
?>
<input type="hidden" name="en_reservation[additional_shavings_enabled]" data-eem-section-enabled="shavings" value="<?php echo ! empty( $data['additional_shavings_enabled'] ) ? '1' : '0'; ?>" />

<div class="eem-addon-block">
	<h4 class="eem-addon-block__title"><?php esc_html_e( 'Shavings Products', 'equine-event-manager' ); ?></h4>
	<p class="eem-addon-block__help"><?php esc_html_e( 'Types of shavings bags customers can purchase at checkout. All items here are counted as shavings in reports.', 'equine-event-manager' ); ?></p>
	<table class="eem-repeat-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Product Name', 'equine-event-manager' ); ?></th>
				<th style="width:140px"><?php esc_html_e( 'Price / Bag', 'equine-event-manager' ); ?></th>
				<th style="width:40px"></th>
			</tr>
		</thead>
		<tbody id="eem-shavings-products-rows">
			<?php foreach ( $products as $idx => $product ) :
				$p_name  = isset( $product['name'] ) ? (string) $product['name'] : '';
				$p_price = isset( $product['price'] ) ? (float) $product['price'] : 0.0;
				?>
				<tr>
					<td><input class="eem-repeat-input" type="text" name="en_reservation[additional_shavings_products][<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr( $p_name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Large Flake, Mini Flake', 'equine-event-manager' ); ?>" /></td>
					<td><div class="eem-repeat-price-wrap"><span class="eem-repeat-price-sym">$</span><input class="eem-repeat-price-in" type="number" step="0.01" min="0" name="en_reservation[additional_shavings_products][<?php echo (int) $idx; ?>][price]" value="<?php echo esc_attr( $fmt_money( $p_price ) ); ?>" /></div></td>
					<td><button class="eem-btn-delete" type="button" aria-label="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-remove-repeating-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<button class="eem-btn-add" type="button" data-eem-action="reservation-editor-add-repeating-row" data-eem-repeating-template="eem-shavings-products-row-template" data-eem-repeating-tbody="eem-shavings-products-rows">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
		<?php esc_html_e( 'Add Shavings Type', 'equine-event-manager' ); ?>
	</button>
	<template id="eem-shavings-products-row-template"><tr>
		<td><input class="eem-repeat-input" type="text" name="en_reservation[additional_shavings_products][__index__][name]" value="" placeholder="<?php esc_attr_e( 'e.g. Large Flake, Mini Flake', 'equine-event-manager' ); ?>" /></td>
		<td><div class="eem-repeat-price-wrap"><span class="eem-repeat-price-sym">$</span><input class="eem-repeat-price-in" type="number" step="0.01" min="0" name="en_reservation[additional_shavings_products][__index__][price]" value="0.00" /></div></td>
		<td><button class="eem-btn-delete" type="button" aria-label="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-remove-repeating-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></td>
	</tr></template>
</div>
