<?php
/**
 * Reservation Editor — mobile-only sticky save bar (C7.X.3).
 *
 * Mockup lines 275 (display:none default) + 299 (display:flex at
 * @media max-width:767px). CSS-controlled visibility — the markup
 * always renders, the `.eem-sticky-save` class flips display based
 * on viewport.
 *
 * Mockup canon: 2 buttons only (Save Draft + Update Reservation).
 * No Cancel / Preview / Trash on mobile sticky.
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

$is_published = 'publish' === get_post_status( $post );
?>
<div class="eem-sticky-save" data-eem-reservation-id="<?php echo esc_attr( (string) $reservation_id ); ?>">
	<?php if ( $is_published ) : ?>
		<button type="button" class="eem-btn-save-draft" data-eem-action="reservation-editor-save-draft"><?php esc_html_e( 'Switch to Draft', 'equine-event-manager' ); ?></button>
		<button type="button" class="eem-btn-update"     data-eem-action="reservation-editor-update"><?php esc_html_e( 'Update', 'equine-event-manager' ); ?></button>
	<?php else : ?>
		<button type="button" class="eem-btn-save-draft" data-eem-action="reservation-editor-save-draft"><?php esc_html_e( 'Save Draft', 'equine-event-manager' ); ?></button>
		<button type="button" class="eem-btn-update"     data-eem-action="reservation-editor-publish"><?php esc_html_e( 'Publish', 'equine-event-manager' ); ?></button>
	<?php endif; ?>
</div>
