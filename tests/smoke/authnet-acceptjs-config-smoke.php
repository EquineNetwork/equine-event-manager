<?php
/**
 * Accept.js config-gating smoke (ship-readiness 4.2 / task #46).
 *
 * Verifies get_active_authorize_net_configuration() resolves the new Accept.js
 * fields per mode and gates correctly: `use_acceptjs` is true ONLY when the admin
 * toggle is on AND a public client key exists for the ACTIVE mode — so the legacy
 * raw-card flow stays in force until both are set. Backs up + restores the real
 * payment-settings option.
 *
 * Run via: wp eval-file tests/smoke/authnet-acceptjs-config-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;
$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

if ( ! class_exists( 'EEM_Shortcodes' ) ) {
	echo "  FAIL — EEM_Shortcodes missing\n0 passed, 1 failed\n";
	return;
}
$sc  = new EEM_Shortcodes();
$opt = 'equine_event_manager_payment_settings';
$m   = new ReflectionMethod( 'EEM_Shortcodes', 'get_active_authorize_net_configuration' );
$m->setAccessible( true );

$orig_raw    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt ) );
$orig_exists = null !== $orig_raw;

$cfg = static function ( array $authnet ) use ( $m, $sc ) {
	return $m->invoke( $sc, array( 'authorize_net' => $authnet ) );
};

// Test mode, toggle on, test client key set → Accept.js ON, test endpoints.
$c = $cfg( array( 'mode' => 'test', 'test_client_key' => 'CKTEST', 'live_client_key' => 'CKLIVE', 'use_acceptjs' => '1' ) );
$chk( true === $c['use_acceptjs'], 'test mode + toggle + test client key → Accept.js ON' );
$chk( 'CKTEST' === $c['client_key'], 'active client_key resolves to test key' );
$chk( false !== strpos( $c['acceptjs_url'], 'jstest.authorize.net' ), 'Accept.js URL is the sandbox host in test mode' );

// Live mode picks the live client key + live Accept.js host.
$c = $cfg( array( 'mode' => 'live', 'test_client_key' => 'CKTEST', 'live_client_key' => 'CKLIVE', 'use_acceptjs' => '1' ) );
$chk( 'CKLIVE' === $c['client_key'], 'live mode resolves the live client key' );
$chk( false !== strpos( $c['acceptjs_url'], 'js.authorize.net' ) && false === strpos( $c['acceptjs_url'], 'jstest' ), 'Accept.js URL is the live host in live mode' );

// Toggle on but NO client key for the active mode → Accept.js OFF (gated).
$c = $cfg( array( 'mode' => 'test', 'test_client_key' => '', 'live_client_key' => 'CKLIVE', 'use_acceptjs' => '1' ) );
$chk( false === $c['use_acceptjs'], 'toggle on but no client key for active mode → Accept.js OFF (raw-card flow)' );

// Client key set but toggle off → Accept.js OFF.
$c = $cfg( array( 'mode' => 'test', 'test_client_key' => 'CKTEST', 'use_acceptjs' => '' ) );
$chk( false === $c['use_acceptjs'], 'client key set but toggle off → Accept.js OFF' );

// Default (no Accept.js fields at all) → OFF, legacy flow.
$c = $cfg( array( 'mode' => 'test', 'test_api_login' => 'x', 'test_transaction_key' => 'y' ) );
$chk( false === $c['use_acceptjs'], 'no Accept.js config → OFF (fully backward compatible)' );

// Restore the real option byte-for-byte (we never wrote it, but be safe).
if ( $orig_exists ) {
	$wpdb->update( $wpdb->options, array( 'option_value' => $orig_raw ), array( 'option_name' => $opt ) );
	wp_cache_delete( $opt, 'options' );
}

echo "\n$pass passed, $fail failed\n";
