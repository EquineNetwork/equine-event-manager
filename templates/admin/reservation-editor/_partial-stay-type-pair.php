<?php
/**
 * Reservation Editor — shared stay-type pair partial (C7.X.4).
 *
 * Mockup-canonical pair of clickable pill buttons for Nightly +
 * Weekend Rate stay-type selection (mockup lines 152, 516-519). Each
 * pill carries:
 *   - .eem-stay-type-btn (with .eem-stay-type-btn--active when on)
 *   - inner .eem-toggle indicator
 *   - data-controls (space-separated row IDs of dependent fields)
 *   - hidden mirror input for persistence
 *
 * JS at-least-one validation (eemFlashStayHint) blocks deactivating
 * the LAST active pill in a group.
 *
 * Args:
 *   group_label     string  Aria label for the pair group
 *   nightly_name    string  Hidden mirror input name (e.g. 'stall_nightly_enabled')
 *   nightly_label   string  Visible button label (e.g. 'Nightly')
 *   nightly_on      bool    Initial state
 *   nightly_controls array  Row IDs controlled by the Nightly button
 *   weekend_name    string  Hidden mirror input name
 *   weekend_label   string  Visible button label (e.g. 'Weekend Rate')
 *   weekend_on      bool    Initial state
 *   weekend_controls array  Row IDs controlled by the Weekend button
 *   group_slug      string  Optional identifier on the wrapper for JS scoping
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eem_render_editor_stay_type_pair' ) ) {
	function eem_render_editor_stay_type_pair( array $args ) {
		$d = array_merge( array(
			'group_label'      => '',
			'nightly_name'     => '',
			'nightly_label'    => __( 'Nightly', 'equine-event-manager' ),
			'nightly_on'       => false,
			'nightly_controls' => array(),
			'weekend_name'     => '',
			'weekend_label'    => __( 'Weekend Rate', 'equine-event-manager' ),
			'weekend_on'       => false,
			'weekend_controls' => array(),
			'weekly_name'      => '',
			'weekly_label'     => __( 'Weekly Rate', 'equine-event-manager' ),
			'weekly_on'        => false,
			'weekly_controls'  => array(),
			'group_slug'       => '',
		), $args );

		$render_btn = function ( $name, $label, $is_on, $controls ) {
			// C7.X.9 — stale legacy duplicate tokens (`active`, `on`,
			// `off`) stripped. The click handlers in admin.js only
			// toggle the canonical mockup classes
			// (`eem-stay-type-btn--active`, `eem-toggle--on/--off`);
			// the bare tokens were never flipped off, so
			// `eemApplyControlsById` read `on=true` forever and
			// conditional rows never hid.
			$active_cls = $is_on ? ' eem-stay-type-btn--active' : '';
			$tog_cls    = $is_on ? 'eem-toggle--on' : 'eem-toggle--off';
			$ctrl_attr  = implode( ' ', array_map( 'sanitize_html_class', (array) $controls ) );
			$val        = $is_on ? '1' : '0';
			?>
			<div class="eem-stay-type-btn<?php echo esc_attr( $active_cls ); ?>" data-eem-action="reservation-editor-toggle-stay-type" data-controls="<?php echo esc_attr( $ctrl_attr ); ?>">
				<div class="eem-toggle <?php echo esc_attr( $tog_cls ); ?>" style="pointer-events:none" aria-hidden="true"></div>
				<input type="hidden" name="en_reservation[<?php echo esc_attr( $name ); ?>]" data-eem-stay-type-mirror value="<?php echo esc_attr( $val ); ?>" />
				<span><?php echo esc_html( $label ); ?></span>
			</div>
			<?php
		};
		?>
		<div class="eem-stay-types" data-eem-stay-group="<?php echo esc_attr( $d['group_slug'] ); ?>" role="group" aria-label="<?php echo esc_attr( $d['group_label'] ); ?>">
			<?php $render_btn( $d['nightly_name'], $d['nightly_label'], (bool) $d['nightly_on'], $d['nightly_controls'] ); ?>
			<?php $render_btn( $d['weekend_name'], $d['weekend_label'], (bool) $d['weekend_on'], $d['weekend_controls'] ); ?>
			<?php if ( $d['weekly_name'] ) : ?>
				<?php $render_btn( $d['weekly_name'], $d['weekly_label'], (bool) $d['weekly_on'], $d['weekly_controls'] ); ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
