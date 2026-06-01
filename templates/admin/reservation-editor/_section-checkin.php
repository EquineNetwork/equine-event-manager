<?php
/**
 * Reservation Editor — "Check-In / Check-Out" section body
 * (C7.C.1.4.A rewrite — mockup-canonical chrome).
 *
 * Mockup lines 391–423. Two datetime field rows. Collapsed by
 * default. Body renders a `.eem-section-disabled-note` callout
 * when section is disabled (via section-skeleton's `disabled_note`
 * arg).
 *
 * Walkthrough enumeration:
 *   2.4 row → .eem-field-row "Check-In Time"
 *   2.5 control → <input type="datetime-local" class="eem-field-input">
 *               style="max-width:260px"
 *               (populated from `_en_checkin_time`)
 *   2.6 row → .eem-field-row "Check-Out Time"
 *   2.7 control → <input type="datetime-local" class="eem-field-input">
 *               (populated from `_en_checkout_time`)
 *
 * Hidden enabled-mirror per C7.C.1.1 Desync A/B fix.
 *
 * Locals contract:
 *   $data  array  reservation meta values
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

// 2.3.70 — Check-in / check-out are time-of-day only. Format any stored value
// (bare HH:MM, or a legacy datetime) to the 24-hour H:i an <input type="time">
// expects.
$fmt_time = function ( $value ) {
	$value = (string) $value;
	if ( '' === $value ) {
		return '';
	}
	if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m ) ) {
		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}
	$ts = strtotime( $value );
	return false === $ts ? '' : gmdate( 'H:i', $ts );
};
?>
<input type="hidden" name="en_reservation[checkin_checkout_enabled]" data-eem-section-enabled="checkin" value="<?php echo ! empty( $data['checkin_checkout_enabled'] ) ? '1' : '0'; ?>" />
<?php
eem_render_editor_field_row( array(
	'label'        => __( 'Check-In Time', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<input class="eem-field-input" name="en_reservation[checkin_time]" id="en_checkin_time" type="time" style="max-width:180px" value="%s" />',
		esc_attr( $fmt_time( $data['checkin_time'] ) )
	),
) );
eem_render_editor_field_row( array(
	'label'        => __( 'Check-Out Time', 'equine-event-manager' ),
	'control_html' => sprintf(
		'<input class="eem-field-input" name="en_reservation[checkout_time]" id="en_checkout_time" type="time" style="max-width:180px" value="%s" />',
		esc_attr( $fmt_time( $data['checkout_time'] ) )
	),
) );
