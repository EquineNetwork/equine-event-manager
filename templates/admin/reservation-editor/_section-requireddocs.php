<?php
/**
 * Reservation Editor — "Required Documents" section body.
 *
 * Admin-defined list of documents the customer must upload for their order
 * (e.g. Coggins, health certificate). Each row: a name + a "required at
 * checkout" flag. A section-level description explains the requirement.
 * Customer uploads (front-end + admin order-side) attach per-order and wire
 * in a follow-up phase; this section defines the requirement list only.
 *
 * Meta: _eem_section_enabled_requireddocs (toggle),
 *       _en_required_documents_description (string),
 *       _en_required_documents (array of {name, required}).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed> $data */

$rd_desc = isset( $data['required_documents_description'] ) ? (string) $data['required_documents_description'] : '';
$rd_docs = isset( $data['required_documents'] ) && is_array( $data['required_documents'] ) ? $data['required_documents'] : array();
?>
<input type="hidden" name="en_reservation[required_documents_enabled]" data-eem-section-enabled="requireddocs" value="<?php echo ! empty( $data['required_documents_enabled'] ) ? '1' : '0'; ?>" />

<div class="eem-field-row">
	<label class="eem-field-label" for="en_required_documents_description"><?php esc_html_e( 'Description', 'equine-event-manager' ); ?></label>
	<textarea class="eem-field-input" name="en_reservation[required_documents_description]" id="en_required_documents_description" rows="3" placeholder="<?php esc_attr_e( 'Explain what customers need to upload (e.g. "All horses require a current Coggins test and health certificate").', 'equine-event-manager' ); ?>"><?php echo esc_textarea( $rd_desc ); ?></textarea>
</div>

<div class="eem-addon-block">
	<h4 class="eem-addon-block__title"><?php esc_html_e( 'Documents', 'equine-event-manager' ); ?></h4>
	<p class="eem-addon-block__help"><?php esc_html_e( 'Each item gets an upload button for the customer. Check "Required" to block checkout until it is uploaded.', 'equine-event-manager' ); ?></p>
	<table class="eem-repeat-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Document Name', 'equine-event-manager' ); ?></th>
				<th style="width:160px"><?php esc_html_e( 'Required at checkout', 'equine-event-manager' ); ?></th>
				<th style="width:40px"></th>
			</tr>
		</thead>
		<tbody id="eem-required-docs-rows">
			<?php
			// Always show at least one editable row so the line-item table is
			// visible (not just the Add button) on a fresh section.
			$rd_rows = ! empty( $rd_docs ) ? $rd_docs : array( array( 'name' => '', 'required' => 0 ) );
			foreach ( (array) $rd_rows as $idx => $doc ) :
				$d_name = isset( $doc['name'] ) ? (string) $doc['name'] : '';
				$d_req  = ! empty( $doc['required'] );
				?>
				<tr>
					<td><input class="eem-repeat-input" type="text" name="en_reservation[required_documents][<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr( $d_name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Coggins, Health Certificate', 'equine-event-manager' ); ?>" /></td>
					<td style="text-align:center">
						<label class="eem-switch">
							<input type="checkbox" name="en_reservation[required_documents][<?php echo (int) $idx; ?>][required]" value="1" <?php checked( $d_req ); ?> />
							<span class="eem-switch-track" aria-hidden="true"></span>
						</label>
					</td>
					<td><button class="eem-btn-delete" type="button" aria-label="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-remove-repeating-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<button class="eem-btn-add" type="button" data-eem-action="reservation-editor-add-repeating-row" data-eem-repeating-template="eem-required-docs-row-template" data-eem-repeating-tbody="eem-required-docs-rows">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
		<?php esc_html_e( 'Add Document', 'equine-event-manager' ); ?>
	</button>
	<template id="eem-required-docs-row-template"><tr>
		<td><input class="eem-repeat-input" type="text" name="en_reservation[required_documents][__index__][name]" value="" placeholder="<?php esc_attr_e( 'e.g. Coggins, Health Certificate', 'equine-event-manager' ); ?>" /></td>
		<td style="text-align:center">
			<label class="eem-switch">
				<input type="checkbox" name="en_reservation[required_documents][__index__][required]" value="1" />
				<span class="eem-switch-track" aria-hidden="true"></span>
			</label>
		</td>
		<td><button class="eem-btn-delete" type="button" aria-label="<?php esc_attr_e( 'Delete', 'equine-event-manager' ); ?>" data-eem-action="reservation-editor-remove-repeating-row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></td>
	</tr></template>
</div>
