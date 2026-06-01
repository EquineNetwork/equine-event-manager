<?php
/**
 * Reservation Editor — "Event Pre-Entries" section body (C8).
 *
 * Mockup lines 1357–1410. Enable-toggled section with a repeating
 * table of class/competition entries customers can purchase.
 *
 * Meta keys:
 *   _en_event_pre_entries_enabled  bool
 *   _en_event_pre_entries          array of {title, inventory, price, max_per_customer}
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

$fmt_money = function ( $v ) { return number_format( (float) $v, 2, '.', '' ); };

// Read saved meta only. New reservations render a blank table (no seeded sample
// rows) — admins add their own pre-entries. (2.3.82: seed rows removed so a new
// reservation form starts completely empty.)
// NOTE: use $data, NOT a direct get_post_meta() call with get_the_ID() — this template
// runs on a custom admin page where the global $post is not set, so get_the_ID()
// returns 0. All section templates must read from $data (pre-loaded by get_meta_values()).
$pre_entries_meta = isset( $data['event_pre_entries'] ) ? $data['event_pre_entries'] : array();
$pre_entries      = ( is_array( $pre_entries_meta ) && ! empty( $pre_entries_meta ) )
	? $pre_entries_meta
	: array();
?>
<input type="hidden" name="eem_event_pre_entries_enabled" data-eem-section-enabled="event_pre_entries" value="<?php echo ! empty( $data['event_pre_entries_enabled'] ) ? '1' : '0'; ?>" />

<?php
ob_start();
?>
<table class="eem-repeat-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Event Title', 'equine-event-manager' ); ?></th>
			<th style="width:110px"><?php esc_html_e( 'Inventory', 'equine-event-manager' ); ?></th>
			<th style="width:120px"><?php esc_html_e( 'Max Per Customer', 'equine-event-manager' ); ?></th>
			<th style="width:140px"><?php esc_html_e( 'Price', 'equine-event-manager' ); ?></th>
			<th style="width:40px"></th>
		</tr>
	</thead>
	<tbody id="eem-pre-entries-list">
		<?php foreach ( $pre_entries as $pi => $entry ) :
			$e_title = isset( $entry['title'] )           ? (string) $entry['title']              : '';
			$e_inv   = isset( $entry['inventory'] )       ? (int) $entry['inventory']             : 0;
			$e_max   = isset( $entry['max_per_customer'] ) ? (string) $entry['max_per_customer']  : '';
			$e_price = isset( $entry['price'] )           ? $fmt_money( $entry['price'] )         : '0.00';
			?>
			<tr>
				<td><input class="eem-repeat-input" type="text" name="eem_event_pre_entries[<?php echo (int) $pi; ?>][title]" value="<?php echo esc_attr( $e_title ); ?>" data-eem-input-action="pre-entry-input"></td>
				<td><input class="eem-repeat-input" type="number" min="0" style="width:90px" name="eem_event_pre_entries[<?php echo (int) $pi; ?>][inventory]" value="<?php echo esc_attr( (string) $e_inv ); ?>" data-eem-input-action="pre-entry-input"></td>
				<td><input class="eem-repeat-input" type="number" min="1" step="1" style="width:90px" name="eem_event_pre_entries[<?php echo (int) $pi; ?>][max_per_customer]" value="<?php echo esc_attr( $e_max ); ?>" placeholder="<?php esc_attr_e( 'Unlimited', 'equine-event-manager' ); ?>" data-eem-input-action="pre-entry-input"></td>
				<td><div class="eem-repeat-price-wrap"><span class="eem-repeat-price-sym">$</span><input class="eem-repeat-price-in" type="number" min="0" step="0.01" name="eem_event_pre_entries[<?php echo (int) $pi; ?>][price]" value="<?php echo esc_attr( $e_price ); ?>" data-eem-input-action="pre-entry-input"></div></td>
				<td><button class="eem-btn-delete" type="button" aria-label="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>" data-eem-action="pre-entry-delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<button class="eem-btn-add" type="button" data-eem-action="pre-entry-add">
	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
	<?php esc_html_e( 'Add Pre-Entry', 'equine-event-manager' ); ?>
</button>
<template id="eem-pre-entry-row-template"><tr>
	<td><input class="eem-repeat-input" type="text" name="eem_event_pre_entries[__index__][title]" value="" placeholder="<?php esc_attr_e( 'Entry title', 'equine-event-manager' ); ?>" data-eem-input-action="pre-entry-input"></td>
	<td><input class="eem-repeat-input" type="number" min="0" style="width:90px" name="eem_event_pre_entries[__index__][inventory]" value="0" data-eem-input-action="pre-entry-input"></td>
	<td><input class="eem-repeat-input" type="number" min="1" step="1" style="width:90px" name="eem_event_pre_entries[__index__][max_per_customer]" value="" placeholder="<?php esc_attr_e( 'Unlimited', 'equine-event-manager' ); ?>" data-eem-input-action="pre-entry-input"></td>
	<td><div class="eem-repeat-price-wrap"><span class="eem-repeat-price-sym">$</span><input class="eem-repeat-price-in" type="number" min="0" step="0.01" name="eem_event_pre_entries[__index__][price]" value="0.00" data-eem-input-action="pre-entry-input"></div></td>
	<td><button class="eem-btn-delete" type="button" aria-label="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>" data-eem-action="pre-entry-delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></td>
</tr></template>
<?php
$entries_html = (string) ob_get_clean();
eem_render_editor_field_row( array(
	'label'        => __( 'Pre-Entries', 'equine-event-manager' ),
	'label_sub'    => __( 'Class or competition entries customers can purchase', 'equine-event-manager' ),
	'control_html' => $entries_html,
) );
