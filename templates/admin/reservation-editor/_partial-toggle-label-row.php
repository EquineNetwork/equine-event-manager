<?php
/**
 * Reservation Editor — shared toggle-label-row helper (C7.C.1.4.A).
 *
 * Bordered-chip toggle pattern from mockup line 147 — used for
 * sub-section enable controls (Grounds Fee toggle, Deposit toggle,
 * Schedule toggle, Early Bird toggle, etc.). Replaces the unstyled
 * `<input type="checkbox">` markup that shipped through C7.C.1 /
 * C7.C.2.1.
 *
 * Structure:
 *   <div class="eem-toggle-label-row"
 *        data-eem-action="reservation-editor-toggle-subsection"
 *        data-eem-controls="ctrl-token-1 ctrl-token-2">
 *     <div class="eem-toggle eem-toggle--on|--off"></div>
 *     <input type="hidden" name="en_reservation[X_enabled]"
 *            data-eem-subsection-enabled="X" value="0|1">
 *     <span>Label text</span>
 *   </div>
 *
 * JS handler at `reservation-editor-toggle-subsection`:
 *   1. Flips `.eem-toggle--on/--off` on the indicator
 *   2. Flips the hidden input's value between '1' and '0'
 *   3. Calls global applyControls() — re-evaluates ALL sub-section
 *      toggles and applies `eem-row--hidden` to rows where ANY
 *      covering controller is off (union semantics — Decision D).
 *
 * Initial render:
 *   - PHP reads `$is_enabled` (caller computes from meta)
 *   - Toggle indicator class + hidden input value reflect state
 *   - DEPENDENT ROWS need `eem-row--hidden` applied at render time
 *     by the caller via `_partial-field-row.php` `is_hidden` arg;
 *     this helper only renders the TOGGLE, not its dependents.
 *
 * Args:
 *   name        string  Meta-mirror field name without prefix
 *                       (e.g. 'group_rider_grounds_fee_enabled' →
 *                       hidden input becomes
 *                       en_reservation[group_rider_grounds_fee_enabled])
 *   subsection  string  Slug for data-eem-subsection-enabled attr
 *                       (e.g. 'grounds-fee')
 *   label       string  Visible label text (translated upstream)
 *   is_enabled  bool    Initial state
 *   controls    array   List of `eem-ctrl--*` class tokens this toggle
 *                       controls. JS reads `data-eem-controls` (space-
 *                       separated) and targets matching rows.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_render_editor_toggle_label_row' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return void
	 */
	function eem_render_editor_toggle_label_row( array $args ) {
		$defaults = array(
			'name'       => '',
			'subsection' => '',
			'label'      => '',
			'is_enabled' => false,
			'controls'   => array(),
		);
		$args = array_merge( $defaults, $args );
		if ( '' === $args['name'] || '' === $args['subsection'] || '' === $args['label'] ) {
			return;
		}

		$toggle_state_class = $args['is_enabled'] ? 'eem-toggle--on' : 'eem-toggle--off';
		$hidden_value       = $args['is_enabled'] ? '1' : '0';
		// C7.X.4 — Build-to-Mockup: data-controls is ID-based per
		// mockup (space-separated row ids). Legacy class-token system
		// retired; .controls now accepts a list of HTML ids (or eem-
		// ctrl-- class tokens for backward-compat callers — both work
		// with the same applyControls() JS handler).
		$controls_attr      = implode( ' ', array_map( 'sanitize_html_class', (array) $args['controls'] ) );
		// Mockup emits `class="toggle on"` or `class="toggle off"` on
		// the inner indicator AND the wrapper carries the state classes
		// too so applyControls() can detect on/off from the wrapper.
		$wrapper_state      = $args['is_enabled'] ? 'on' : 'off';
		?>
		<div class="eem-toggle-label-row <?php echo esc_attr( $wrapper_state ); ?>" data-eem-action="reservation-editor-toggle-switch-row" data-controls="<?php echo esc_attr( $controls_attr ); ?>" data-eem-subsection="<?php echo esc_attr( $args['subsection'] ); ?>">
			<div class="eem-toggle <?php echo esc_attr( $toggle_state_class ); ?> <?php echo esc_attr( $wrapper_state ); ?>" aria-hidden="true"></div>
			<input type="hidden" name="en_reservation[<?php echo esc_attr( $args['name'] ); ?>]" data-eem-subsection-enabled="<?php echo esc_attr( $args['subsection'] ); ?>" value="<?php echo esc_attr( $hidden_value ); ?>" />
			<span><?php echo esc_html( $args['label'] ); ?></span>
		</div>
		<?php
	}
}
