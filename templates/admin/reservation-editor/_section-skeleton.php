<?php
/**
 * Reusable section-card skeleton partial (C7.B.1).
 *
 * Renders the section-card chrome — header (icon chip + title + enable
 * toggle + chevron) wrapping a body slot. Called 10× from
 * EEM_Reservation_Editor_Page::render() during the editor render,
 * once per section in mockup order.
 *
 * Skeleton scope (C7.B.1): chrome only. Each section's `body_html`
 * arg is a placeholder string in C7.B.1; the real per-section bodies
 * wire in C7.C (existing-section fields), C7.D (Event Day Info),
 * C7.E (Cancellation Policy).
 *
 * Per Decision E (verified live against the mockup): icon tones map
 *   description     → blue           (always-on, no enable toggle)
 *   checkin         → teal
 *   eventday        → orange
 *   stall           → green
 *   rv              → purple
 *   addons          → orange
 *   group           → green
 *   fees            → orange
 *   agreement       → navy
 *   cancellation    → red
 *
 * Per Decision D (locked): args shape =
 *   key            string  Section key, used for `id="card-<key>"`
 *                          + JS toggle dispatch
 *   title          string  Section title (translated upstream)
 *   icon_tone      string  blue/teal/orange/green/purple/navy/red
 *   enable_toggle  bool    Render the inline "Enabled" toggle (default true)
 *   collapsed      bool    Open in collapsed state (default false)
 *   body_html      string  Pre-rendered body HTML to inject in section body
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_render_reservation_editor_section' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return void
	 */
	function eem_render_reservation_editor_section( array $args ) {
		$defaults = array(
			'key'           => '',
			'title'         => '',
			'icon_tone'     => 'blue',
			'icon_key'      => '', // C7.B.3 — Feather glyph name per EEM_Dashboard_Icons registry
			'enable_toggle' => true,
			'collapsed'     => false,
			'body_html'     => '',
		);
		$args = array_merge( $defaults, $args );
		if ( '' === $args['key'] || '' === $args['title'] ) {
			return;
		}

		$card_classes = 'eem-card eem-reservation-editor-section';
		if ( $args['collapsed'] ) {
			$card_classes .= ' eem-section-collapsed';
		}
		$header_classes = 'eem-section-header';
		if ( ! $args['collapsed'] ) {
			$header_classes .= ' is-open';
		}
		$body_classes = 'eem-section-body';
		if ( $args['collapsed'] ) {
			$body_classes .= ' eem-section-body--hidden';
		}
		?>
		<section class="<?php echo esc_attr( $card_classes ); ?>" id="card-<?php echo esc_attr( $args['key'] ); ?>">
			<div class="<?php echo esc_attr( $header_classes ); ?>" data-eem-action="reservation-editor-toggle-collapse" data-eem-section="<?php echo esc_attr( $args['key'] ); ?>">
				<div class="eem-section-header-left">
					<div class="eem-section-icon eem-section-icon--<?php echo esc_attr( $args['icon_tone'] ); ?>" aria-hidden="true"><?php
						// C7.B.3 — inline SVG glyph per mockup chip pattern.
						if ( '' !== $args['icon_key'] && class_exists( 'EEM_Dashboard_Icons' ) ) {
							echo EEM_Dashboard_Icons::svg( $args['icon_key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped helper output.
						}
					?></div>
					<span class="eem-section-title"><?php echo esc_html( $args['title'] ); ?></span>
				</div>
				<div class="eem-section-header-right">
					<?php if ( $args['enable_toggle'] ) : ?>
						<div class="eem-enable-toggle" data-eem-action="reservation-editor-toggle-enabled" data-eem-section="<?php echo esc_attr( $args['key'] ); ?>">
							<div class="eem-toggle eem-toggle--on" data-eem-section="<?php echo esc_attr( $args['key'] ); ?>"></div>
							<span class="eem-enable-toggle__label"><?php esc_html_e( 'Enabled', 'equine-event-manager' ); ?></span>
						</div>
					<?php endif; ?>
					<div class="eem-section-chevron" aria-hidden="true"></div>
				</div>
			</div>
			<div class="<?php echo esc_attr( $body_classes ); ?>" id="body-<?php echo esc_attr( $args['key'] ); ?>">
				<?php
				// C7.C.1 — body_html is author-controlled output from the
				// per-section partials under templates/admin/reservation-
				// editor/_section-*.php (pre-escaped per-field via esc_attr
				// / esc_html inside each partial). wp_kses_post strips
				// <template>, structured form chrome, and select option
				// trees we need; direct echo is correct here.
				echo $args['body_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped per-field by the caller partial.
				?>
			</div>
		</section>
		<?php
	}
}
