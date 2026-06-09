<?php
/**
 * Smoke: parse_authorize_net_response_body() must decode an Authorize.net JSON
 * body that begins with a UTF-8 BOM.
 *
 * Regression guard for the live bug where every Authorize.net charge failed with
 * "Authorize.net returned an unreadable response" even on a successful (200 OK)
 * transaction — because the gateway prepends a UTF-8 BOM (EF BB BF) to every
 * JSON response and trim() does not strip it, so json_decode() returned null.
 *
 * Run: wp eval-file tests/smoke/authnet-bom-parse-smoke.php
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

$pass = 0;
$fail = 0;
$log  = array();
$ok   = function ( $name, $cond ) use ( &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; $log[] = "PASS  $name"; }
	else         { $fail++; $log[] = "FAIL  $name"; }
};

$sc  = new EEM_Shortcodes();
$ref = new ReflectionMethod( $sc, 'parse_authorize_net_response_body' );
$ref->setAccessible( true );

$bom = "\xEF\xBB\xBF";

// [1] BOM + auth-only response (the exact shape from the live probe).
$auth = $ref->invoke( $sc, $bom . '{"messages":{"resultCode":"Ok","message":[{"code":"I00001","text":"Successful."}]}}' );
$ok( '[1] BOM-prefixed auth response decodes', is_array( $auth ) );
$ok( '[1] resultCode preserved', is_array( $auth ) && ( $auth['messages']['resultCode'] ?? '' ) === 'Ok' );

// [2] BOM + transactionResponse (the charge path).
$txn = $ref->invoke( $sc, $bom . '{"transactionResponse":{"responseCode":"1","transId":"40001234567"},"messages":{"resultCode":"Ok","message":[{"code":"I00001","text":"Successful."}]}}' );
$ok( '[2] BOM-prefixed transaction response decodes', is_array( $txn ) );
$ok( '[2] transId preserved', is_array( $txn ) && ( $txn['transactionResponse']['transId'] ?? '' ) === '40001234567' );
$ok( '[2] responseCode preserved', is_array( $txn ) && ( $txn['transactionResponse']['responseCode'] ?? '' ) === '1' );

// [3] Plain (no BOM) still works — no regression.
$plain = $ref->invoke( $sc, '{"messages":{"resultCode":"Ok"}}' );
$ok( '[3] non-BOM JSON still decodes', is_array( $plain ) );

// [4] Empty body still returns null.
$ok( '[4] empty body returns null', null === $ref->invoke( $sc, '' ) );

echo implode( "\n", $log ) . "\n";
echo "authnet-bom-parse-smoke: {$pass} passed, {$fail} failed\n";
