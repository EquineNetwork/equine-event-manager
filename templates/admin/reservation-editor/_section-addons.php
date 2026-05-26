<?php
/**
 * Reservation Editor — "General Add-Ons" section body (C7.C.1).
 *
 * Uses the shared `eem_render_repeating_row_table()` helper to render
 * the addons table. Row + template renderers are local closures so the
 * column shape stays co-located with the section, while the JS hook
 * points (`data-eem-action="reservation-editor-add-repeating-row"` +
 * `eem-remove-general-addon`) match the handlers landed in admin.js.
 *
 * Locals contract (provided by EEM_Reservation_Editor_Page::render_addons_body):
 *   $data    array  reservation meta values
 *   $addons  array  normalized addon rows from get_editor_general_addons_context()
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed>           $data */
/** @var array<int, array<string, mixed>> $addons */

require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_repeating-row-helper.php';

$row_renderer = function ( $args, $index, $row ) {
	$prefix = $args['name_prefix'];
	?>
	<tr class="<?php echo esc_attr( (string) ( ! empty( $args['row_classes'] ) ? $args['row_classes'] : 'eem-general-addon-row' ) ); ?>">
		<td><div class="eem-admin-table-field"><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[<?php echo esc_attr( (string) $index ); ?>][name]" value="<?php echo esc_attr( (string) ( isset( $row['name'] ) ? $row['name'] : '' ) ); ?>" /></div></td>
		<td><div class="eem-admin-table-field"><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[<?php echo esc_attr( (string) $index ); ?>][description]" value="<?php echo esc_attr( (string) ( isset( $row['description'] ) ? $row['description'] : '' ) ); ?>" /></div></td>
		<td>
			<div class="eem-admin-table-field">
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[<?php echo esc_attr( (string) $index ); ?>][applies_to]" value="any" />
				<div class="eem-currency-field eem-rv-addon-price-field">
					<span class="eem-currency-symbol">$</span>
					<input name="<?php echo esc_attr( $prefix ); ?>[<?php echo esc_attr( (string) $index ); ?>][price]" type="text" class="eem-currency-input" inputmode="decimal" value="<?php echo esc_attr( number_format( (float) ( isset( $row['price'] ) ? $row['price'] : 0 ), 2, '.', '' ) ); ?>" />
				</div>
			</div>
		</td>
		<td><div class="eem-admin-table-field"><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[<?php echo esc_attr( (string) $index ); ?>][per_label]" value="<?php echo esc_attr( (string) ( isset( $row['per_label'] ) ? $row['per_label'] : '' ) ); ?>" placeholder="<?php esc_attr_e( 'bale', 'equine-event-manager' ); ?>" /></div></td>
		<td>
			<div class="eem-admin-table-field eem-admin-table-field--action">
				<button type="button" class="eem-icon-delete-button <?php echo esc_attr( (string) $args['remove_button_class'] ); ?>" data-eem-action="reservation-editor-remove-repeating-row" aria-label="<?php esc_attr_e( 'Remove add-on', 'equine-event-manager' ); ?>" title="<?php esc_attr_e( 'Remove add-on', 'equine-event-manager' ); ?>">
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				</button>
			</div>
		</td>
	</tr>
	<?php
};

$template_renderer = function ( $args ) {
	$prefix = $args['name_prefix'];
	?>
	<tr class="<?php echo esc_attr( (string) ( ! empty( $args['row_classes'] ) ? $args['row_classes'] : 'eem-general-addon-row' ) ); ?>">
		<td><div class="eem-admin-table-field"><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[__index__][name]" value="" /></div></td>
		<td><div class="eem-admin-table-field"><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[__index__][description]" value="" /></div></td>
		<td>
			<div class="eem-admin-table-field">
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[__index__][applies_to]" value="any" />
				<div class="eem-currency-field eem-rv-addon-price-field">
					<span class="eem-currency-symbol">$</span>
					<input name="<?php echo esc_attr( $prefix ); ?>[__index__][price]" type="text" class="eem-currency-input" inputmode="decimal" value="0.00" />
				</div>
			</div>
		</td>
		<td><div class="eem-admin-table-field"><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[__index__][per_label]" value="" placeholder="<?php esc_attr_e( 'bale', 'equine-event-manager' ); ?>" /></div></td>
		<td>
			<div class="eem-admin-table-field eem-admin-table-field--action">
				<button type="button" class="eem-icon-delete-button <?php echo esc_attr( (string) $args['remove_button_class'] ); ?>" data-eem-action="reservation-editor-remove-repeating-row" aria-label="<?php esc_attr_e( 'Remove add-on', 'equine-event-manager' ); ?>" title="<?php esc_attr_e( 'Remove add-on', 'equine-event-manager' ); ?>">
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				</button>
			</div>
		</td>
	</tr>
	<?php
};
?>
<div class="eem-editor-fields">
	<?php // C7.C.1.1 — header-toggle is the only visible enable control; body carries a hidden mirror for persistence. ?>
	<input type="hidden" name="en_reservation[general_addons_enabled]" data-eem-section-enabled="addons" value="<?php echo ! empty( $data['general_addons_enabled'] ) ? '1' : '0'; ?>" />
	<?php
	eem_render_repeating_row_table( array(
		'table_id'            => 'en_general_addons_rows',
		'template_id'         => 'eem-general-addon-row-template',
		'add_button_id'       => 'en_add_general_addon',
		'add_button_label'    => __( 'Add Add-On', 'equine-event-manager' ),
		'row_classes'         => 'eem-general-addon-row',
		'remove_button_class' => 'eem-remove-general-addon',
		'name_prefix'         => 'en_reservation[general_addons]',
		'columns'             => array(
			__( 'Add-On Name',  'equine-event-manager' ),
			__( 'Description',  'equine-event-manager' ),
			__( 'Price',        'equine-event-manager' ),
			__( 'Per',          'equine-event-manager' ),
			__( 'Action',       'equine-event-manager' ),
		),
		'rows'                => $addons,
		'row_renderer'        => $row_renderer,
		'template_renderer'   => $template_renderer,
		'extra_table_classes' => 'eem-general-addon-table',
		'intro_html'          => '<p class="description">' . esc_html__( 'Use general add-ons for items like hay, extra bedding, or other optional products that can be sold alongside stalls or RV reservations.', 'equine-event-manager' ) . '</p>',
	) );
	?>
</div>
