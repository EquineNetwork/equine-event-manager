<?php
/**
 * Security smoke — the LOW hardening items (security audit).
 *
 * 1. Percentage discount cap: set_discount() must reject a percent discount > 100
 *    (defensive backstop; the Create Order handler rejects it earlier with a
 *    user-facing 422). Dollar discounts of any size still resolve (they clamp to
 *    the subtotal at resolve time).
 * 2. CSV formula-injection neutralization: build_csv() must prefix a single quote
 *    to any cell starting with = + - @ | %, while leaving genuine numbers intact.
 *
 * Run: wp eval-file tests/smoke/security-low-hardening-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// === FIX A — percentage discount cap ======================================
$pct    = EEM_Order_Adjustments_Repo::DISCOUNT_PERCENT;
$dollar = EEM_Order_Adjustments_Repo::DISCOUNT_DOLLAR;
$okey   = 'eem-disc-smoke-' . wp_generate_password( 8, false );

$check( 'set_discount rejects a 150% discount (returns false)',
	false === EEM_Order_Adjustments_Repo::set_discount( $okey, $pct, 150.0, 'smoke', 100.0 ) );
$check( 'set_discount rejects a 101% discount (boundary)',
	false === EEM_Order_Adjustments_Repo::set_discount( $okey, $pct, 101.0, 'smoke', 100.0 ) );

$id_100 = EEM_Order_Adjustments_Repo::set_discount( $okey, $pct, 100.0, 'smoke', 100.0 );
$check( 'set_discount accepts exactly 100%', is_int( $id_100 ) && $id_100 > 0 );

$id_dollar = EEM_Order_Adjustments_Repo::set_discount( $okey, $dollar, 5000.0, 'smoke', 100.0 );
$check( 'large DOLLAR discount still accepted (clamps at resolve)', is_int( $id_dollar ) && $id_dollar > 0 );
EEM_Order_Adjustments_Repo::remove_discount( $okey );

// Source: the Create Order handler also rejects >100% with a user-facing code.
$co_src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/class-eem-create-order-page.php' );
$check( 'Create Order handler rejects >100% with user-facing code',
	str_contains( $co_src, "'discount_percent_too_large'" ) );

// === FIX B — CSV formula-injection neutralization =========================
$exp = new EEM_Report_Exporter();
$ref = new ReflectionMethod( 'EEM_Report_Exporter', 'neutralize_csv_cell' );
$ref->setAccessible( true );

$check( 'formula "=cmd|..." is quoted',  "'=cmd|/c calc" === $ref->invoke( $exp, '=cmd|/c calc' ) );
$check( 'leading + is quoted',           "'+1+1" === $ref->invoke( $exp, '+1+1' ) );
$check( 'leading @ is quoted',           "'@SUM(A1)" === $ref->invoke( $exp, '@SUM(A1)' ) );
$check( 'leading | is quoted',           "'|pipe" === $ref->invoke( $exp, '|pipe' ) );
$check( 'text starting with - (e.g. -SUM) is quoted', "'-SUM(A1)" === $ref->invoke( $exp, '-SUM(A1)' ) );
$check( 'genuine negative number is NOT quoted (stays numeric)', '-5.00' === $ref->invoke( $exp, '-5.00' ) );
$check( 'genuine positive number is NOT quoted', '42' === $ref->invoke( $exp, '42' ) );
$check( 'decimal number is NOT quoted', '19.99' === $ref->invoke( $exp, '19.99' ) );
$check( 'ordinary name is untouched', 'Jane Smith' === $ref->invoke( $exp, 'Jane Smith' ) );
$check( 'empty stays empty', '' === $ref->invoke( $exp, '' ) );

// End-to-end: a malicious customer name in a report dataset is neutralized.
$csv = $exp->build_csv( array( 'headers' => array( 'Name', 'Total' ), 'rows' => array( array( '=WEBSERVICE("http://evil")', '19.99' ) ) ) );
$check( 'build_csv neutralizes the formula but keeps the number',
	str_contains( $csv, "'=WEBSERVICE" ) && str_contains( $csv, '19.99' ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
