<?php
/**
 * Accept.js payment-node smoke (ship-readiness 4.2 / task #46).
 *
 * Verifies the shared EEM_Shortcodes::build_authorize_net_payment() helper that
 * all three Auth.net charge paths use:
 *   - given Accept.js opaque data → emits a `payment.opaqueData` node (raw PAN
 *     never reaches the server — the PCI SAQ-A driver),
 *   - given no opaque data + a valid card → emits the legacy `payment.creditCard`
 *     node (backward compatible),
 *   - opaque data takes priority when both are somehow present,
 *   - invalid raw-card input returns the right WP_Error.
 *
 * Run via: wp eval-file tests/smoke/authnet-acceptjs-payment-node-smoke.php
 *
 * @package EEM_Plugin
 */

$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

if ( ! class_exists( 'EEM_Shortcodes' ) ) {
	echo "  FAIL — EEM_Shortcodes missing\n0 passed, 1 failed\n";
	return;
}
$sc = new EEM_Shortcodes();
$m  = new ReflectionMethod( 'EEM_Shortcodes', 'build_authorize_net_payment' );
$m->setAccessible( true );
$nexty = ( (int) gmdate( 'Y' ) + 1 );

// 1. Accept.js opaque data → opaqueData node, no raw card anywhere.
$op = $m->invoke( $sc, 'COMMON.ACCEPT.INAPP.PAYMENT', 'eyJ0b2tlbiI6ImFiYzEyMyJ9', '', '', '', '' );
$chk( is_array( $op ) && isset( $op['opaqueData'] ), 'opaque data → payment.opaqueData node' );
$chk( isset( $op['opaqueData']['dataDescriptor'] ) && 'COMMON.ACCEPT.INAPP.PAYMENT' === $op['opaqueData']['dataDescriptor'], 'dataDescriptor passed through' );
$chk( isset( $op['opaqueData']['dataValue'] ) && 'eyJ0b2tlbiI6ImFiYzEyMyJ9' === $op['opaqueData']['dataValue'], 'dataValue (nonce) passed through' );
$chk( ! isset( $op['creditCard'] ), 'opaque node carries NO creditCard (PAN never sent)' );

// 2. No opaque + valid card → creditCard node (legacy path still works).
$cc = $m->invoke( $sc, '', '', '4111111111111111', '12', (string) $nexty, '123' );
$chk( is_array( $cc ) && isset( $cc['creditCard'] ), 'valid card, no opaque → payment.creditCard node' );
$chk( '4111111111111111' === $cc['creditCard']['cardNumber'], 'card number normalized' );
$chk( ( $nexty . '-12' ) === $cc['creditCard']['expirationDate'], 'expiration formatted YYYY-MM' );

// 3. Opaque takes priority even if card fields are also present.
$both = $m->invoke( $sc, 'COMMON.ACCEPT.INAPP.PAYMENT', 'tok', '4111111111111111', '12', (string) $nexty, '123' );
$chk( isset( $both['opaqueData'] ) && ! isset( $both['creditCard'] ), 'opaque data wins over raw card when both present' );

// 4. Invalid raw card → WP_Error.
$bad = $m->invoke( $sc, '', '', '411', '12', (string) $nexty, '1' );
$chk( is_wp_error( $bad ) && 'authorize_missing_card' === $bad->get_error_code(), 'short card → authorize_missing_card error' );
$exp = $m->invoke( $sc, '', '', '4111111111111111', '13', '2000', '123' );
$chk( is_wp_error( $exp ) && 'authorize_invalid_expiry' === $exp->get_error_code(), 'bad expiry → authorize_invalid_expiry error' );

echo "\n$pass passed, $fail failed\n";
