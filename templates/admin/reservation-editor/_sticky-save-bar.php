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

/*
 * #47 — Form visibility chip. `_eem_form_admin_only` non-empty means the
 * booking form is hidden from visitors (admins still see it); empty means
 * the form is live to the public. The chip below is clickable and persists
 * instantly via the eem_toggle_form_visibility AJAX endpoint — no full save
 * required. Replaces the old top-of-page "Form Visibility" card.
 */
$form_admin_only = '' !== (string) get_post_meta( $reservation_id, '_eem_form_admin_only', true );
$fv_nonce        = wp_create_nonce( 'eem_toggle_form_visibility' );

$pub_date_ts    = strtotime( (string) $post->post_date );
$pub_date_label = $pub_date_ts ? date_i18n( get_option( 'date_format' ), $pub_date_ts ) : __( "\xe2\x80\x94", 'equine-event-manager' );

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

	<!-- #47 Form visibility — clickable Public/Hidden chip; persists instantly -->
	<button type="button"
		class="eem-sticky-save-visibility<?php echo $form_admin_only ? ' eem-sticky-save-visibility--hidden' : ''; ?>"
		data-eem-action="reservation-editor-toggle-form-visibility"
		data-eem-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>"
		data-eem-nonce="<?php echo esc_attr( $fv_nonce ); ?>"
		aria-pressed="<?php echo $form_admin_only ? 'true' : 'false'; ?>"
		data-label-public="<?php esc_attr_e( 'Public', 'equine-event-manager' ); ?>"
		data-label-hidden="<?php esc_attr_e( 'Hidden', 'equine-event-manager' ); ?>"
		title="<?php echo esc_attr( $form_admin_only
			? __( 'The booking form is hidden from visitors (admins can still see it). Click to make it public.', 'equine-event-manager' )
			: __( 'The booking form is live to the public. Click to hide it from visitors (admin preview only).', 'equine-event-manager' ) ); ?>">
		<span class="eem-sticky-save-visibility-icon" data-eem-fv-icon aria-hidden="true">
			<?php if ( $form_admin_only ) : ?>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" focusable="false" style="width:14px;height:14px"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
			<?php else : ?>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" focusable="false" style="width:14px;height:14px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
			<?php endif; ?>
		</span>
		<span class="eem-sticky-save-visibility-label" data-eem-fv-label><?php
			echo esc_html( $form_admin_only ? __( 'Hidden', 'equine-event-manager' ) : __( 'Public', 'equine-event-manager' ) );
		?></span>
	</button>

	<!-- Published date -->
	<div class="eem-sticky-save-meta">
		<span class="eem-sticky-save-meta-item">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false" style="width:14px;height:14px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
			<?php echo esc_html( $pub_date_label ); ?>
		</span>
	</div>

	<!-- Spacer -->
	<div class="eem-sticky-save-spacer"></div>

	<!-- Action buttons — order matches mockup line 1591–1601 -->
	<div class="eem-sticky-save-actions">

		<?php
		$preview_url = class_exists( 'EEM_Events' ) ? EEM_Events::get_reservation_public_url( $reservation_id ) : '';
		if ( $preview_url ) : ?>
		<a href="<?php echo esc_url( $preview_url ); ?>" class="eem-btn-preview" target="_blank" rel="noopener noreferrer">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
			<span><?php esc_html_e( 'Preview', 'equine-event-manager' ); ?></span>
		</a>
		<?php else : ?>
		<button type="button" class="eem-btn-preview" disabled aria-disabled="true"
			title="<?php esc_attr_e( 'Preview available when published.', 'equine-event-manager' ); ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
			<span><?php esc_html_e( 'Preview', 'equine-event-manager' ); ?></span>
		</button>
		<?php endif; ?>

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
