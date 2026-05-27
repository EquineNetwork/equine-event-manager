<?php
/**
 * Reservation Editor — "Cancellation Policy" section body (C7.X.6).
 *
 * Mockup lines 1092–1142. Per-reservation override of the inherited
 * event-default cancellation policy. Three UX states:
 *   1. No override + event default exists → render .eem-inherited-default-banner
 *      ("Using event default cancellation policy"), then a read-only
 *      textarea showing the event default + a hint with link to edit it,
 *      then an empty override textarea + status hint
 *   2. Override is non-empty → banner hidden via .eem-cancellation-overridden
 *      class; status hint flips to "Using this reservation's custom policy"
 *      + Restore default button shown
 *   3. No event default + no override → just the override textarea
 *
 * JS handlers (window.eemUpdateCancellationOverrideState +
 * window.eemRestoreCancellationDefault, both shipped in admin.js
 * C7.X.2) wire the input listener + restore button.
 *
 * New meta key: _en_cancellation_policy_override (string).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed> $data */

require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_partial-field-row.php';

$override     = isset( $data['cancellation_policy_override'] ) ? (string) $data['cancellation_policy_override'] : '';
$has_override = '' !== trim( $override );

// Resolve event-default cancellation policy. The resolver
// (C7.A) reads the linked event's default from the event_defaults
// table; fall back to the legacy global option if no event default.
$event_default = '';
if ( class_exists( 'EEM_Cancellation_Policy' ) ) {
	$reservation_id = (int) get_the_ID();
	if ( ! $reservation_id && isset( $_GET['reservation_id'] ) ) {
		$reservation_id = absint( wp_unslash( $_GET['reservation_id'] ) );
	}
	if ( $reservation_id ) {
		$resolved = EEM_Cancellation_Policy::resolve_for_reservation( $reservation_id );
		// Strip the override portion if present (we want only the
		// event-level default to show in the read-only textarea).
		if ( is_array( $resolved ) && isset( $resolved['event_default'] ) ) {
			$event_default = (string) $resolved['event_default'];
		} elseif ( is_string( $resolved ) ) {
			$event_default = $resolved;
		}
	}
}
if ( '' === $event_default ) {
	$event_default = (string) get_option( 'equine_event_manager_cancellation_policy', '' );
}
$has_event_default = '' !== trim( $event_default );
?>
<input type="hidden" name="en_reservation[cancellation_enabled]" data-eem-section-enabled="cancellation" value="<?php echo ! empty( $data['cancellation_enabled'] ) ? '1' : '0'; ?>" />

<div<?php if ( $has_override ) { echo ' class="eem-cancellation-overridden"'; } ?> id="card-cancellation-state">

	<p class="eem-field-hint eem-section-intro-hint" style="margin-top:0;margin-bottom:14px"><?php esc_html_e( "Customer-facing cancellation terms shown at checkout (link), in the confirmation email, on the PDF receipt, and on the hosted order page. Defaults to this reservation's event-level policy; override per-reservation only if this booking has different terms (e.g., VIP exceptions, group bookings).", 'equine-event-manager' ); ?></p>

	<?php if ( $has_event_default ) : ?>
		<div class="eem-inherited-default-banner">
			<div class="eem-inherited-default-banner-icon">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
			</div>
			<div class="eem-inherited-default-banner-content">
				<div class="eem-inherited-default-banner-title"><?php esc_html_e( 'Using event default cancellation policy', 'equine-event-manager' ); ?></div>
				<div class="eem-inherited-default-banner-text"><?php esc_html_e( 'This reservation inherits the cancellation policy set at the event level. Edit below to customize for this reservation only — the event default is unchanged.', 'equine-event-manager' ); ?></div>
			</div>
		</div>

		<?php
		eem_render_editor_field_row( array(
			'label'        => __( 'Event default', 'equine-event-manager' ),
			'label_sub'    => __( 'From the linked event · read-only here', 'equine-event-manager' ),
			'control_html' => sprintf(
				'<textarea class="eem-field-input" id="eem-cancellation-event-default" rows="4" readonly style="background:#f3f4f5;color:#50575e;cursor:not-allowed">%s</textarea>',
				esc_textarea( $event_default )
			),
			'hint'         => __( 'To change the default for all reservations linked to this event, edit the event default ↗ (affects future reservations only; existing reservations keep their snapshot).', 'equine-event-manager' ),
		) );
		?>
	<?php endif; ?>

	<?php
	ob_start();
	?>
	<textarea class="eem-field-input" name="en_reservation[cancellation_policy_override]" id="en_cancellation_policy_override" rows="6" placeholder="<?php echo $has_event_default ? esc_attr__( 'Leave blank to use the event default above. Type here to override for this reservation only.', 'equine-event-manager' ) : esc_attr__( 'Type the cancellation policy text customers will see at checkout.', 'equine-event-manager' ); ?>"><?php echo esc_textarea( $override ); ?></textarea>
	<div class="eem-cancellation-override-actions">
		<span class="eem-field-hint" id="eem-cancellation-status-hint">
			<?php if ( $has_override ) : ?>
				<strong style="color:var(--eem-electric)"><?php esc_html_e( "Using this reservation's custom policy", 'equine-event-manager' ); ?></strong>
				<?php esc_html_e( '(event default is overridden)', 'equine-event-manager' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Currently using event default. Type to customize.', 'equine-event-manager' ); ?>
			<?php endif; ?>
		</span>
		<button type="button" class="eem-btn-link-secondary" id="eem-cancellation-restore-btn" onclick="window.eemRestoreCancellationDefault()" style="display:<?php echo $has_override ? 'inline-block' : 'none'; ?>"><?php esc_html_e( 'Restore event default', 'equine-event-manager' ); ?></button>
	</div>
	<?php
	$override_html = (string) ob_get_clean();
	eem_render_editor_field_row( array(
		'label'        => __( "This reservation's policy", 'equine-event-manager' ),
		'label_sub'    => $has_event_default ? __( 'Override the event default', 'equine-event-manager' ) : '',
		'control_html' => $override_html,
	) );
	?>
</div>
