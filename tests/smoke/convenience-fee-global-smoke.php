<?php
/**
 * Global Convenience Fee smoke (ROADMAP v1 #8).
 *
 * The convenience fee moved from per-reservation config to a single global
 * Settings → Payments setting. This verifies the settings repo's compute helper
 * (the single source of truth all surfaces derive the fee from): disabled = $0,
 * percentage = % × subtotal, flat = fixed amount, clamping, and round-trip
 * persistence.
 *
 * Run via: wp eval-file tests/smoke/convenience-fee-global-smoke.php
 *
 * @package EEM_Plugin
 */

$pass = 0; $fail = 0;
$approx = static function ( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.005; };
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

// Disabled → $0 regardless of subtotal.
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => 0, 'type' => 'percentage', 'value' => 5.0 ) );
$chk( $approx( EEM_Settings_Repo::get_convenience_fee_amount( 1000 ), 0.0 ), 'disabled fee = $0 even with a configured value' );

// Percentage.
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => 1, 'type' => 'percentage', 'value' => 4.0 ) );
$cfg = EEM_Settings_Repo::get_convenience_fee();
$chk( $cfg['apply'] === true && 'percentage' === $cfg['type'] && $approx( $cfg['value'], 4.0 ), 'percentage config round-trips' );
$chk( $approx( EEM_Settings_Repo::get_convenience_fee_amount( 1050 ), 42.0 ), 'percentage fee = 4% × $1050 = $42.00' );

// Flat.
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => 1, 'type' => 'flat', 'value' => 25.0 ) );
$chk( $approx( EEM_Settings_Repo::get_convenience_fee_amount( 1050 ), 25.0 ), 'flat fee = $25.00 regardless of subtotal' );
$chk( $approx( EEM_Settings_Repo::get_convenience_fee_amount( 50 ), 25.0 ), 'flat fee constant across subtotals' );

// Percentage clamps to 100; flat has no upper clamp; negatives floor to 0.
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => 1, 'type' => 'percentage', 'value' => 250.0 ) );
$chk( $approx( EEM_Settings_Repo::get_convenience_fee()['value'], 100.0 ), 'percentage value clamps to 100' );
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => 1, 'type' => 'flat', 'value' => 500.0 ) );
$chk( $approx( EEM_Settings_Repo::get_convenience_fee()['value'], 500.0 ), 'flat value is NOT clamped to 100' );
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => 1, 'type' => 'flat', 'value' => -5.0 ) );
$chk( $approx( EEM_Settings_Repo::get_convenience_fee()['value'], 0.0 ), 'negative value floors to 0' );

// Default label present.
$chk( '' !== EEM_Settings_Repo::get_convenience_fee()['label'], 'fee has a non-empty default label' );

// The checkout calculator delegates to the global helper.
$sc  = new EEM_Shortcodes();
$ref = new ReflectionMethod( 'EEM_Shortcodes', 'calculate_convenience_fee' );
$ref->setAccessible( true );
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => 1, 'type' => 'percentage', 'value' => 10.0 ) );
$chk( $approx( $ref->invoke( $sc, 200.0, array() ), 20.0 ), 'calculate_convenience_fee() reads the global setting (10% × $200 = $20)' );

// Reset to disabled so other smokes aren't affected.
EEM_Settings_Repo::update_convenience_fee( array( 'apply' => 0, 'type' => 'percentage', 'value' => 0.0 ) );

echo "\nDone. PASS=$pass FAIL=$fail\n";
