<?php
/**
 * Reservation Editor — generic repeating-row table helper (C7.C.1).
 *
 * Used by C7.C.1 for the General Add-Ons section. Same helper will absorb
 * C7.C.2's Stall Rows and RV Lot Zones repeating tables once their
 * column shapes are settled. The shape is intentionally generic:
 *
 *   $args = [
 *     'table_id'           => 'en_general_addons_rows',           // <tbody id>
 *     'template_id'        => 'eem-general-addon-row-template',    // <template id>
 *     'add_button_id'      => 'en_add_general_addon',              // add-button id
 *     'add_button_label'   => __( 'Add Add-On', 'equine-event-manager' ),
 *     'row_classes'        => 'eem-general-addon-row',             // tr classes
 *     'remove_button_class'=> 'eem-remove-general-addon',          // delete button class
 *     'name_prefix'        => 'en_reservation[general_addons]',    // field name root
 *     'columns'            => [ 'Add-On Name', 'Description', 'Price', 'Per', 'Action' ],
 *     'rows'               => $addons,                              // existing data
 *     'row_renderer'       => callable( $args, $index, $row ) → emits row <tr> markup,
 *     'template_renderer'  => callable( $args ) → emits template <tr> markup (uses __index__),
 *     'intro_html'         => '<p class="description">…</p>'        // optional table intro
 *   ];
 *
 * JS handlers in `assets/js/admin.js` (C7.C.1 additions) clone the
 * `<template>` row on add-button click and remove-button click handles
 * the corresponding `<tr>` deletion. The data-eem-action attributes on
 * the add + remove buttons are the JS hook points.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_render_repeating_row_table' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return void
	 */
	function eem_render_repeating_row_table( array $args ) {
		$defaults = array(
			'table_id'            => '',
			'template_id'         => '',
			'add_button_id'       => '',
			'add_button_label'    => __( 'Add Row', 'equine-event-manager' ),
			'remove_button_class' => 'eem-remove-row',
			'name_prefix'         => '',
			'columns'             => array(),
			'rows'                => array(),
			'row_renderer'        => null,
			'template_renderer'   => null,
			'intro_html'          => '',
			'extra_table_classes' => '',
		);
		$args = array_merge( $defaults, $args );
		if ( '' === $args['table_id'] || ! is_callable( $args['row_renderer'] ) || ! is_callable( $args['template_renderer'] ) ) {
			return;
		}

		$table_classes = trim( 'widefat striped eem-repeating-table ' . (string) $args['extra_table_classes'] );
		?>
		<div class="eem-admin-structured-table eem-repeating-row-helper" data-eem-repeating-add="<?php echo esc_attr( (string) $args['add_button_id'] ); ?>" data-eem-repeating-template="<?php echo esc_attr( (string) $args['template_id'] ); ?>" data-eem-repeating-tbody="<?php echo esc_attr( (string) $args['table_id'] ); ?>">
			<?php if ( '' !== $args['intro_html'] ) { echo wp_kses_post( (string) $args['intro_html'] ); } ?>
			<table class="<?php echo esc_attr( $table_classes ); ?>">
				<thead>
					<tr>
						<?php foreach ( (array) $args['columns'] as $col_label ) : ?>
							<th><?php echo esc_html( (string) $col_label ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody id="<?php echo esc_attr( (string) $args['table_id'] ); ?>">
					<?php foreach ( (array) $args['rows'] as $index => $row ) {
						call_user_func( $args['row_renderer'], $args, (int) $index, (array) $row );
					} ?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button button-secondary eem-repeating-row-add" id="<?php echo esc_attr( (string) $args['add_button_id'] ); ?>" data-eem-action="reservation-editor-add-repeating-row"><?php echo esc_html( (string) $args['add_button_label'] ); ?></button>
			</p>
			<template id="<?php echo esc_attr( (string) $args['template_id'] ); ?>"><?php
				call_user_func( $args['template_renderer'], $args );
			?></template>
		</div>
		<?php
	}
}
