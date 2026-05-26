<?php
/**
 * Reservation Editor — shared field-row helper (C7.C.1.4.A).
 *
 * Mockup-canonical layout primitive replacing the WordPress
 * `<table class="form-table">` chrome the legacy meta-box helpers
 * emit. CSS at admin.css `.eem-field-row` (shipped C1.2): grid with
 * 220px label column + 1fr control column, 12px vertical padding,
 * 1px bottom border between rows (first/last special-cased).
 *
 * Replaces the per-row `<table><tr><th><td>` pattern from
 * `EEM_Reservations_CPT::render_editor_*_row()` (now `@deprecated`
 * per C7.C.1.4.A Decision C).
 *
 * Args:
 *   label         string  Plain label text (translated upstream)
 *   label_sub     string  Optional sub-label below the main label
 *   control_html  string  Pre-rendered control markup (input/select/textarea/
 *                         toggle-label-row/etc.) — author-controlled, must
 *                         be pre-escaped per-attribute by the caller
 *   hint          string  Optional `.eem-field-hint` text below the control
 *   row_id        string  Optional id="row-X" — used by data-eem-controls
 *                         targeting from sub-section toggles (mockup
 *                         line 539 / 547 / 552 pattern)
 *   row_classes   string  Optional space-separated additional class tokens
 *                         (e.g. `eem-ctrl--grounds-amt` for conditional
 *                         visibility, `eem-row--hidden` if initial-hidden)
 *   is_hidden     bool    Adds `eem-row--hidden` (display:none) — used when
 *                         a parent toggle is currently OFF at first render.
 *                         JS applyControls() updates this on user clicks.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_render_editor_field_row' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return void
	 */
	function eem_render_editor_field_row( array $args ) {
		$defaults = array(
			'label'        => '',
			'label_sub'    => '',
			'control_html' => '',
			'hint'         => '',
			'row_id'       => '',
			'row_classes'  => '',
			'is_hidden'    => false,
		);
		$args = array_merge( $defaults, $args );

		$classes = 'eem-field-row';
		if ( '' !== $args['row_classes'] ) {
			$classes .= ' ' . $args['row_classes'];
		}
		if ( $args['is_hidden'] ) {
			$classes .= ' eem-row--hidden';
		}
		$id_attr = '' !== $args['row_id'] ? ' id="' . esc_attr( $args['row_id'] ) . '"' : '';
		?>
		<div class="<?php echo esc_attr( $classes ); ?>"<?php echo $id_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>>
			<div class="eem-field-label"><?php
				echo esc_html( $args['label'] );
				if ( '' !== $args['label_sub'] ) {
					echo '<div class="eem-field-label-sub">' . esc_html( $args['label_sub'] ) . '</div>';
				}
			?></div>
			<div class="eem-field-control">
				<?php echo $args['control_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- author-controlled, pre-escaped by caller. ?>
				<?php if ( '' !== $args['hint'] ) : ?>
					<span class="eem-field-hint"><?php echo esc_html( $args['hint'] ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
