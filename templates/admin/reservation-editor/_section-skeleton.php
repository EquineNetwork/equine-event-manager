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
			// C7.C.1.1 — header-toggle initial state. Was hardcoded
			// `--on` for every section even when the underlying meta
			// said the section was disabled (Desync C). Now driven by
			// the per-section enabled flag computed in
			// EEM_Reservation_Editor_Page::section_definitions().
			'is_enabled'    => true,
			// C7.C.1.4.A — mockup line 83/403/950: enable-label flips
			// between "Enabled" and "Disabled" per toggle state. Was
			// hardcoded "Enabled" regardless of state.
			'disabled_note' => '',
			// C7.C.1.4.A — mockup pattern (line 409/956/1110): some
			// sections render a body-level callout when disabled
			// (e.g. "This section is disabled. Enable it to ...").
			// Empty string = no note.
			'intro_hint_html' => '',
			// C7.C.1.4.A — mockup pattern (line 443/1110): some sections
			// render an intro hint above the field rows (Event Day Info,
			// Cancellation Policy). Empty string = no intro.
		);
		$args = array_merge( $defaults, $args );
		if ( '' === $args['key'] || '' === $args['title'] ) {
			return;
		}

		// C7.C.1.2 — disabled sections collapse to header-only at first
		// render. effective_collapsed = !is_enabled || design_collapsed
		// preserves Decision C: design-collapsed sections still default
		// collapsed when enabled; disabling never overrides design
		// intent in the more-open direction. Disabled-state striped
		// overlay must also be applied at render time so the body
		// renders correctly when the user expands a disabled section
		// via chevron click (the JS click-handler used to be the sole
		// source of --disabled, leaving first-render incorrect).
		$is_disabled         = $args['enable_toggle'] && ! $args['is_enabled'];
		$effective_collapsed = $is_disabled || $args['collapsed'];

		$card_classes = 'eem-card eem-reservation-editor-section';
		if ( $effective_collapsed ) {
			$card_classes .= ' eem-section-collapsed';
		}
		$header_classes = 'eem-section-header';
		if ( ! $effective_collapsed ) {
			$header_classes .= ' is-open';
		}
		$body_classes = 'eem-section-body';
		if ( $effective_collapsed ) {
			$body_classes .= ' eem-section-body--hidden';
		}
		if ( $is_disabled ) {
			$body_classes .= ' eem-section-body--disabled';
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
						<?php
						$toggle_state_class = $args['is_enabled'] ? 'eem-toggle--on' : 'eem-toggle--off';
						// C7.C.1.4.A — mockup-canonical: text flips with state.
						$enable_label_text  = $args['is_enabled']
							? __( 'Enabled', 'equine-event-manager' )
							: __( 'Disabled', 'equine-event-manager' );
						?>
						<div class="eem-enable-toggle" data-eem-action="reservation-editor-toggle-enabled" data-eem-section="<?php echo esc_attr( $args['key'] ); ?>">
							<div class="eem-toggle <?php echo esc_attr( $toggle_state_class ); ?>" data-eem-section="<?php echo esc_attr( $args['key'] ); ?>"></div>
							<span class="eem-enable-toggle__label" data-eem-enable-label="<?php echo esc_attr( $args['key'] ); ?>"><?php echo esc_html( $enable_label_text ); ?></span>
						</div>
					<?php endif; ?>
					<div class="eem-section-chevron" aria-hidden="true"><?php
						// C7.C.1.3 — emit the chevron-down glyph. Prior to
						// this, the container was empty and rendered as a
						// 0×0 invisible div — the click target lived on
						// the wrapping .eem-section-header so collapse
						// still worked, but users had no visual cue. Per
						// Decision B, click target stays on the header
						// bar; the chevron is visual feedback only.
						if ( class_exists( 'EEM_Dashboard_Icons' ) ) {
							echo EEM_Dashboard_Icons::svg( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped helper output.
						}
					?></div>
				</div>
			</div>
			<div class="<?php echo esc_attr( $body_classes ); ?>" id="body-<?php echo esc_attr( $args['key'] ); ?>">
				<?php
				// C7.C.1.4.A — disabled-note callout. Renders only when
				// the section's enable toggle is off (mockup line 98 +
				// 409 + 956). Always emitted in markup so JS toggle-OFF
				// can reveal it via the body's --disabled class without
				// a re-render; CSS rule .eem-section-body--disabled
				// .eem-section-disabled-note keeps it visible (the
				// striped overlay is below the note).
				if ( '' !== $args['disabled_note'] ) {
					echo '<div class="eem-section-disabled-note" data-eem-section-disabled-note="' . esc_attr( $args['key'] ) . '">' . esc_html( $args['disabled_note'] ) . '</div>';
				}
				// C7.C.1.4.A — section-level intro hint above field rows
				// (mockup line 443/1110). Pre-escaped author-controlled
				// HTML; small links allowed (mockup line 1127 has an
				// "edit the event default ↗" link).
				if ( '' !== $args['intro_hint_html'] ) {
					echo '<p class="eem-field-hint eem-section-intro-hint">' . $args['intro_hint_html'] . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped author HTML.
				}
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
