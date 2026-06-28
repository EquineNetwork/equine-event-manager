<?php
/**
 * Secret-store at-rest encryption smoke (ship-readiness P5 / task #20).
 *
 * Proves the EEM_Secret_Store transparent layer: secret fields encrypt on the
 * way into the payment-settings option and decrypt on the way out, while
 * publishable keys / login IDs stay clear, empties pass through, legacy
 * plaintext keeps working, and a corrupt envelope fails closed. The end-to-end
 * section drives real update_option/get_option and inspects the RAW DB row to
 * confirm the plaintext secret never lands in the database.
 *
 * The real payment-settings option is backed up (raw) and restored byte-for-byte
 * so live credentials are never disturbed.
 *
 * Run via: wp eval-file tests/smoke/secret-store-encrypt-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;

$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

if ( ! class_exists( 'EEM_Secret_Store' ) ) {
	echo "  FAIL — EEM_Secret_Store missing\n0 passed, 1 failed\n";
	return;
}

$opt    = 'equine_event_manager_payment_settings';
$prefix = 'eemenc:v1:';

// --- Back up the real option (raw, pre-filter) ------------------------------
$orig_raw    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt ) );
$orig_exists = null !== $orig_raw;

$chk( EEM_Secret_Store::available(), 'libsodium is available on this host' );

// --- Unit: single-value round-trip -----------------------------------------
$secret = 'sk_live_' . str_repeat( 'A1b2', 8 );
$enc    = EEM_Secret_Store::encrypt( $secret );
$chk( EEM_Secret_Store::is_encrypted( $enc ), 'encrypt() produces a ciphertext envelope' );
$chk( false === strpos( $enc, $secret ), 'plaintext does not appear inside the envelope' );
$chk( $secret === EEM_Secret_Store::decrypt( $enc ), 'decrypt() recovers the original secret' );

$enc2 = EEM_Secret_Store::encrypt( $secret );
$chk( $enc2 !== $enc, 'same plaintext encrypts to a different envelope (random nonce)' );
$chk( $secret === EEM_Secret_Store::decrypt( $enc2 ), 'second envelope also decrypts correctly' );

// --- Unit: passthrough + fail-closed ---------------------------------------
$chk( '' === EEM_Secret_Store::encrypt( '' ), 'empty string is not encrypted (write-only sentinel)' );
$chk( 'legacy_plain_key' === EEM_Secret_Store::decrypt( 'legacy_plain_key' ), 'legacy plaintext passes through decrypt unchanged' );
$chk( $enc === EEM_Secret_Store::encrypt( $enc ), 'already-encrypted value is not double-encrypted (idempotent)' );
$chk( '' === EEM_Secret_Store::decrypt( $prefix . '!!!not-valid-base64!!!' ), 'corrupt envelope fails closed (empty)' );
$chk( '' === EEM_Secret_Store::decrypt( $prefix . base64_encode( 'too-short' ) ), 'truncated ciphertext fails closed (empty)' );

// --- Unit: structural field selection (on_update / on_read) ------------------
$plain = array(
	'selected_gateway' => 'stripe',
	'stripe'           => array(
		'mode'                   => 'live',
		'live_publishable_key'   => 'pk_live_PUBLIC',
		'live_secret_key'        => $secret,
		'test_secret_key'        => '',
		'webhook_signing_secret' => 'whsec_HOOK',
	),
	'authorize_net'    => array(
		'live_api_login'       => 'apilogin123',
		'live_transaction_key' => 'txnkey_SECRET',
	),
);
$enc_arr = EEM_Secret_Store::on_update( $plain );
$chk( EEM_Secret_Store::is_encrypted( $enc_arr['stripe']['live_secret_key'] ), 'on_update encrypts stripe live_secret_key' );
$chk( EEM_Secret_Store::is_encrypted( $enc_arr['stripe']['webhook_signing_secret'] ), 'on_update encrypts stripe webhook_signing_secret' );
$chk( EEM_Secret_Store::is_encrypted( $enc_arr['authorize_net']['live_transaction_key'] ), 'on_update encrypts authnet live_transaction_key' );
$chk( 'pk_live_PUBLIC' === $enc_arr['stripe']['live_publishable_key'], 'publishable key is left clear' );
$chk( 'apilogin123' === $enc_arr['authorize_net']['live_api_login'], 'api login id is left clear' );
$chk( '' === $enc_arr['stripe']['test_secret_key'], 'empty secret stays empty (no envelope)' );

$dec_arr = EEM_Secret_Store::on_read( $enc_arr );
$chk( $secret === $dec_arr['stripe']['live_secret_key'], 'on_read decrypts stripe live_secret_key' );
$chk( 'txnkey_SECRET' === $dec_arr['authorize_net']['live_transaction_key'], 'on_read decrypts authnet live_transaction_key' );

// --- End-to-end: through real update_option/get_option ----------------------
update_option( $opt, $plain, false );
$raw_after = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt ) );
$chk( false === strpos( $raw_after, $secret ), 'plaintext secret is NOT present in the raw DB row' );
$chk( false === strpos( $raw_after, 'txnkey_SECRET' ), 'authnet plaintext secret is NOT present in the raw DB row' );
$chk( false !== strpos( $raw_after, $prefix ), 'raw DB row carries the ciphertext envelope' );
$chk( false !== strpos( $raw_after, 'pk_live_PUBLIC' ), 'raw DB row still carries the clear publishable key' );

$read_back = get_option( $opt );
$chk( is_array( $read_back ) && $secret === $read_back['stripe']['live_secret_key'], 'get_option transparently returns the decrypted stripe secret' );
$chk( is_array( $read_back ) && 'txnkey_SECRET' === $read_back['authorize_net']['live_transaction_key'], 'get_option transparently returns the decrypted authnet secret' );

// --- Restore the real option byte-for-byte ----------------------------------
if ( $orig_exists ) {
	$wpdb->update( $wpdb->options, array( 'option_value' => $orig_raw ), array( 'option_name' => $opt ) );
} else {
	$wpdb->delete( $wpdb->options, array( 'option_name' => $opt ) );
}
wp_cache_delete( $opt, 'options' );
wp_cache_delete( 'alloptions', 'options' );
$restored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt ) );
$chk( $orig_exists ? ( $orig_raw === $restored ) : ( null === $restored ), 'original payment-settings option restored byte-for-byte' );

echo "\n$pass passed, $fail failed\n";
