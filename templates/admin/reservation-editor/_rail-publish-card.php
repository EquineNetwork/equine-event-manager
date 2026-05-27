<?php
/**
 * Reservation Editor — right-rail Publish card (C7.X.3).
 *
 * Mockup-canonical save UI for desktop. Replaces the legacy fixed-
 * bottom .eem-save-bar shipped in C7.B.2 (now retired). Per the
 * Build-to-Mockup canon (mockup lines 1149–1178), the desktop save
 * surface lives in the right rail with:
 *   - Status row (Published/Draft/Scheduled)
 *   - Visibility row (Public)
 *   - Published date row
 *   - Preview Frontend Form anchor
 *   - Save as Draft button
 *   - Update Reservation button (Electric Blue per VIS-4)
 *   - Move to Trash danger button
 *
 * AJAX nonce input lives in this card so the existing save-dispatch
 * JS (eemDispatchSave) can read it via its existing selector.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 *
 * Expects $post (WP_Post) + $reservation_id (int) in scope.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $post, $reservation_id ) ) {
	return;
}

$post           = $post;
$reservation_id = (int) $reservation_id;
$status         = get_post_status( $post );
$status_labels  = array(
	'publish' => __( 'Published', 'equine-event-manager' ),
	'draft'   => __( 'Draft',     'equine-event-manager' ),
	'future'  => __( 'Scheduled', 'equine-event-manager' ),
	'pending' => __( 'Pending',   'equine-event-manager' ),
	'private' => __( 'Private',   'equine-event-manager' ),
	'trash'   => __( 'Trash',     'equine-event-manager' ),
);
$status_label   = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : ucfirst( (string) $status );
$visibility     = 'private' === $status ? __( 'Private', 'equine-event-manager' ) : __( 'Public', 'equine-event-manager' );
$pub_date_ts    = strtotime( (string) $post->post_date );
$pub_date_label = $pub_date_ts ? date_i18n( get_option( 'date_format' ), $pub_date_ts ) : __( '—', 'equine-event-manager' );
$preview_url    = home_url( user_trailingslashit( 'event-reservation/' . $reservation_id ) );
$primary_action = 'publish' === $status ? 'update' : 'draft';
$nonce          = wp_create_nonce( 'eem_reservation_editor' );
?>
<div class="eem-rail-card" data-eem-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>">
	<div class="eem-rail-header">
		<span class="eem-rail-title"><?php esc_html_e( 'Publish', 'equine-event-manager' ); ?></span>
	</div>
	<div class="eem-rail-body">
		<input type="hidden" name="_eem_editor_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
		<div class="eem-publish-row">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
			<?php esc_html_e( 'Status:', 'equine-event-manager' ); ?> <strong data-eem-publish-status><?php echo esc_html( $status_label ); ?></strong>
		</div>
		<div class="eem-publish-row">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
			<?php esc_html_e( 'Visibility:', 'equine-event-manager' ); ?> <strong><?php echo esc_html( $visibility ); ?></strong>
		</div>
		<div class="eem-publish-row">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
			<?php esc_html_e( 'Published:', 'equine-event-manager' ); ?> <strong><?php echo esc_html( $pub_date_label ); ?></strong>
		</div>
		<a class="eem-btn-preview" href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
			<?php esc_html_e( 'Preview Frontend Form', 'equine-event-manager' ); ?>
		</a>
		<?php if ( 'publish' === $primary_action ) : ?>
			<button type="button" class="eem-btn-update" data-eem-action="reservation-editor-update"><?php esc_html_e( 'Update Reservation', 'equine-event-manager' ); ?></button>
			<button type="button" class="eem-btn-save-draft" data-eem-action="reservation-editor-save-draft"><?php esc_html_e( 'Switch to Draft', 'equine-event-manager' ); ?></button>
		<?php else : ?>
			<button type="button" class="eem-btn-save-draft" data-eem-action="reservation-editor-save-draft"><?php esc_html_e( 'Save as Draft', 'equine-event-manager' ); ?></button>
			<button type="button" class="eem-btn-update" data-eem-action="reservation-editor-publish"><?php esc_html_e( 'Publish Reservation', 'equine-event-manager' ); ?></button>
		<?php endif; ?>
		<button type="button" class="eem-btn-danger-sm" data-eem-action="reservation-editor-trash"><?php esc_html_e( 'Move to Trash', 'equine-event-manager' ); ?></button>
	</div>
</div>
