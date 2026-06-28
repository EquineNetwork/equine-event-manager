<?php
/**
 * Write-only payment-secret smoke (ship-readiness 4.4a).
 *
 * Payment secret fields (Stripe secret keys, Auth.net transaction keys, webhook
 * signing secret) are write-only: the settings form never emits the stored value
 * into page source, and a BLANK submit keeps the existing secret rather than
 * wiping it. Asserts sanitize_credential_group()'s 'secret' handling:
 *   - blank submit + existing value  → keeps the existing secret
 *   - non-blank submit               → updates to the new value
 *   - 'text' fields                  → always take the submitted value (publishable
 *                                       keys / api_login are public, not secrets)
 *
 * Run via: wp eval-file tests/smoke/write-only-secrets-smoke.php
 *
 * @package EEM_Plugin
 */

$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

if ( ! class_exists( 'EEM_Settings_Page' ) ) {
	echo "  FAIL — EEM_Settings_Page missing\n0 passed, 1 failed\n";
	return;
}

$m = new ReflectionMethod( 'EEM_Settings_Page', 'sanitize_credential_group' );
$m->setAccessible( true );
$page = new EEM_Settings_Page();

$schema = array(
	'mode'             => 'mode',
	'live_publishable' => 'text',
	'live_secret'      => 'secret',
);
$existing = array(
	'mode'             => 'live',
	'live_publishable' => 'pk_live_OLD',
	'live_secret'      => 'sk_live_STORED_SECRET',
);

// Blank secret submit → keep stored secret; publishable (text) takes submitted.
$blank = $m->invoke( $page, array( 'mode' => 'live', 'live_publishable' => 'pk_live_NEW', 'live_secret' => '' ), $schema, $existing );
$chk( 'sk_live_STORED_SECRET' === $blank['live_secret'], 'blank secret submit KEEPS the stored secret' );
$chk( 'pk_live_NEW' === $blank['live_publishable'], 'public publishable key takes the submitted value' );

// Non-blank secret submit → updates.
$update = $m->invoke( $page, array( 'mode' => 'test', 'live_publishable' => 'pk_live_NEW', 'live_secret' => 'sk_live_ROTATED' ), $schema, $existing );
$chk( 'sk_live_ROTATED' === $update['live_secret'], 'non-blank secret submit UPDATES the secret' );
$chk( 'test' === $update['mode'], 'mode field sanitized to a valid enum' );

// No existing value + blank → empty (first-time setup, nothing to keep).
$fresh = $m->invoke( $page, array( 'live_secret' => '' ), $schema, array() );
$chk( '' === $fresh['live_secret'], 'blank secret with no stored value stays empty' );

echo "\n$pass passed, $fail failed\n";
