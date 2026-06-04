<?php
/**
 * Launch fix smoke — saving an UNCHANGED settings panel is success, not failure.
 *
 * WordPress update_option() returns false both on failure AND when the value is
 * unchanged, so re-saving the Communications panel after only editing one
 * section made the untouched sections report "Some settings could not be saved."
 * The repo update_* methods now treat "already stored" as success. This asserts
 * the second (no-op) save returns true.
 *
 * Run: wp eval-file tests/smoke/c-settings-noop-save-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$sender = array( 'send_customer_emails' => 1, 'from_name' => 'Noop Co', 'from_email' => 'noop@example.com', 'reply_to' => 'noop@example.com', 'admin_copy_email' => '' );
EEM_Settings_Repo::update_email_sender( $sender );
$check( 'email sender: re-save unchanged returns true', true === EEM_Settings_Repo::update_email_sender( $sender ) );

$policies = EEM_Settings_Repo::get_policies();
EEM_Settings_Repo::update_policies( $policies );
$check( 'policies: re-save unchanged returns true', true === EEM_Settings_Repo::update_policies( $policies ) );

$tax = EEM_Settings_Repo::get_tax();
EEM_Settings_Repo::update_tax( $tax );
$check( 'tax: re-save unchanged returns true', true === EEM_Settings_Repo::update_tax( $tax ) );

// update_all() sanitizes to the full template set, so calling it twice with the
// same input makes the second call a no-op.
EEM_Email_Templates_Repo::update_all( array() );
$check( 'templates: re-save unchanged returns true', true === EEM_Email_Templates_Repo::update_all( array() ) );

// And a real change still returns true.
$sender2 = array_merge( $sender, array( 'from_name' => 'Changed Co ' . wp_generate_password( 4, false ) ) );
$check( 'email sender: real change returns true', true === EEM_Settings_Repo::update_email_sender( $sender2 ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
