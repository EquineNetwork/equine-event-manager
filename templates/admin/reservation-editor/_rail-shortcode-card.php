<?php
/* No longer rendered on Edit Reservation page as of C8 port. Retained for possible reuse on other pages. */
/**
 * Reservation Editor — right-rail Shortcode card (C7.X.3).
 *
 * Mockup-canonical Shortcode display (mockup lines 1198–1207).
 * Renders the [en_reservation id="N"] snippet the admin can paste
 * on any page to surface the reservation form.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 *
 * Expects $reservation_id (int) in scope.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $reservation_id ) ) {
	return;
}

$reservation_id = (int) $reservation_id;
$shortcode      = sprintf( '[en_reservation id="%d"]', $reservation_id );
?>
<div class="eem-rail-card">
	<div class="eem-rail-header">
		<span class="eem-rail-title"><?php esc_html_e( 'Shortcode', 'equine-event-manager' ); ?></span>
	</div>
	<div class="eem-rail-body">
		<p class="eem-rail-hint"><?php esc_html_e( 'Paste this shortcode on any page to display the reservation form.', 'equine-event-manager' ); ?></p>
		<div class="eem-code-box"><?php echo esc_html( $shortcode ); ?></div>
	</div>
</div>
