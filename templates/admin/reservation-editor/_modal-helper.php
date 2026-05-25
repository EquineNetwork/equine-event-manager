<?php
/**
 * Reservation editor modal-helper partial (C7.B.2).
 *
 * Small reusable wrapper that emits a hidden modal scaffold (overlay +
 * card + header + body slot + footer slot) built on the C1.4
 * `.eem-modal` chrome primitive. Used by:
 *   - Linked Event modal (C7.B.2)
 *   - Event Defaults modal (C7.E — cancellation + venue map tabs)
 *
 * Args:
 *   id            string  Modal element id (e.g. 'eem-modal-linked-event')
 *   title         string  Modal title
 *   body_html     string  Pre-rendered body content
 *   footer_html   string  Pre-rendered footer content (Save/Cancel buttons)
 *   classes       string  Extra classes for .eem-modal-card (e.g. '--wide')
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_render_reservation_editor_modal' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return void
	 */
	function eem_render_reservation_editor_modal( array $args ) {
		$defaults = array(
			'id'          => '',
			'title'       => '',
			'body_html'   => '',
			'footer_html' => '',
			'classes'     => '',
		);
		$args = array_merge( $defaults, $args );
		if ( '' === $args['id'] || '' === $args['title'] ) {
			return;
		}
		?>
		<div class="eem-modal" id="<?php echo esc_attr( $args['id'] ); ?>" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $args['id'] ); ?>-title">
			<div class="eem-modal-card <?php echo esc_attr( $args['classes'] ); ?>">
				<div class="eem-modal-head">
					<h2 class="eem-modal-title" id="<?php echo esc_attr( $args['id'] ); ?>-title"><?php echo esc_html( $args['title'] ); ?></h2>
					<button type="button" class="eem-modal-close" data-eem-action="reservation-editor-modal-close" data-eem-modal="<?php echo esc_attr( $args['id'] ); ?>" aria-label="<?php esc_attr_e( 'Close', 'equine-event-manager' ); ?>">&times;</button>
				</div>
				<div class="eem-modal-body">
					<?php
					// body_html and footer_html are author-controlled (we
					// own the modal-body partials). wp_kses_post strips
					// <input class> attributes among other things, so we
					// echo directly here — same boundary as the page
					// shell partial.
					echo $args['body_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
				<?php if ( '' !== $args['footer_html'] ) : ?>
					<div class="eem-modal-foot">
						<?php echo $args['footer_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
