<?php
/**
 * Reservation Editor — always-visible sticky save bar (C8 port).
 *
 * Replaces the right-rail Publish card and the old mobile-only
 * _sticky-save-mobile.php. Fixed to the bottom of the viewport on
 * ALL screen sizes per mockup line 258:
 *   display:flex; position:fixed; bottom:0; left:0; right:0;
 *
 * Structure (left → right):
 *   .eem-sticky-save-status — colored dot + status label
 *   .eem-sticky-save-spacer — flex:1 push
 *   .eem-sticky-save-actions — Preview · Save as Draft · Move to Trash · Update/Publish
 *
 * Buttons wire to the SAME data-eem-action handlers used by the old
 * rail Publish card (_rail-publish-card.php):
 *   reservation-editor-update    — already published, save in-place
 *   reservation-editor-publish   — draft → publish
 *   reservation-editor-save-draft
 *   reservation-editor-trash
 *
 * The nonce hidden input placed here mirrors its location in the
 * retired rail card so the existing JS (eemDispatchSave) can read
 * it via the unchanged `[name="_eem_editor_nonce"]` selector.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 *
 * @var WP_Post $post            Loaded in EEM_Reservation_Editor_Page::render().
 * @var int     $reservation_id  Same scope.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $post, $reservation_id ) ) {
	return;
}

$reservation_id = (int) $reservation_id;
$status         = get_post_status( $post );
$is_published   = 'publish' === $status;

/* Status badge label + modifier class */
$status_labels = array(
	'publish' => __( 'Published', 'equine-event-manager' ),
	'draft'   => __( 'Draft',     'equine-event-manager' ),
	'future'  => __( 'Scheduled', 'equine-event-manager' ),
	'pending' => __( 'Pending',   'equine-event-manager' ),
	'private' => __( 'Private',   'equine-event-manager' ),
	'trash'   => __( 'Trash',     'equine-event-manager' ),
);
$status_label  = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : ucfirst( (string) $status );

$dot_modifier = 'publish' === $status ? 'published' : ( 'trash' === $status ? 'trash' : 'draft' );

/* Nonce — same action as rail card so JS dispatcher works unchanged */
$nonce = wp_create_nonce( 'eem_reservation_editor' );
?>
<div class="eem-sticky-save" id="eem-sticky-save" data-eem-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>">

	<!-- Nonce: read by eemDispatchSave() via [name="_eem_editor_nonce"] -->
	<input type="hidden" name="_eem_editor_nonce" value="<?php echo esc_attr( $nonce ); ?>" />

	<!-- Status badge -->
	<div class="eem-sticky-save-status eem-sticky-save-status--<?php echo esc_attr( $dot_modifier ); ?>">
		<span class="eem-sticky-save-dot" aria-hidden="true"></span>
		<span data-eem-publish-status><?php echo esc_html( $status_label ); ?></span>
	</div>

	<!-- Spacer -->
	<div class="eem-sticky-save-spacer"></div>

	<!-- Action buttons — order matches mockup line 1591–1601 -->
	<div class="eem-sticky-save-actions">

		<?php /* Preview — wired to C10 when it ships; disabled stub until then.
		         Button matches rail card's disabled pattern per C7.X.16 Issue D3. */ ?>
		<button type="button"
			class="eem-btn-preview"
			disabled
			aria-disabled="true"
			title="<?php esc_attr_e( 'Customer preview available after C10 ships.', 'equine-event-manager' ); ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
			<span><?php esc_html_e( 'Preview', 'equine-event-manager' ); ?></span>
		</button>

		<?php /* Save as Draft — always available; switches a published reservation back to draft */ ?>
		<button type="button"
			class="eem-btn-save-draft"
			data-eem-action="reservation-editor-save-draft"
			aria-label="<?php esc_attr_e( 'Save as Draft', 'equine-event-manager' ); ?>">
			<span><?php esc_html_e( 'Save as Draft', 'equine-event-manager' ); ?></span>
		</button>

		<?php /* Move to Trash */ ?>
		<button type="button"
			class="eem-btn-danger-sm"
			data-eem-action="reservation-editor-trash"
			aria-label="<?php esc_attr_e( 'Move to Trash', 'equine-event-manager' ); ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false" style="width:14px;height:14px"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
			<span><?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?></span>
		</button>

		<?php /* Primary CTA — Update if already published, Publish if draft */ ?>
		<?php if ( $is_published ) : ?>
			<button type="button"
				class="eem-btn-update"
				data-eem-action="reservation-editor-update">
				<?php esc_html_e( 'Update Reservation', 'equine-event-manager' ); ?>
			</button>
		<?php else : ?>
			<button type="button"
				class="eem-btn-update"
				data-eem-action="reservation-editor-publish">
				<?php esc_html_e( 'Publish Reservation', 'equine-event-manager' ); ?>
			</button>
		<?php endif; ?>

	</div><!-- /.eem-sticky-save-actions -->
</div><!-- /.eem-sticky-save -->
