<?php
/**
 * Change-after-orders acknowledgment smoke (2.7.197).
 *
 * Changing Stall Inventory Type or Customer Selection on a reservation that
 * already has orders now requires an explicit acknowledgment: the editor save
 * returns code `structural_change_requires_ack` (409); the client confirms and
 * resubmits with eem_structural_change_ack=1. Source-structure assertions; the
 * full server->confirm->resubmit round-trip was browser-verified on a
 * reservation with 1 order at ship time.
 *
 * Run: wp eval-file tests/smoke/structural-change-ack-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$root = dirname( __DIR__, 2 );
$php  = (string) file_get_contents( $root . '/admin/class-eem-reservation-editor-page.php' );
$js   = (string) file_get_contents( $root . '/assets/js/admin.js' );

// --- server guardrail ------------------------------------------------------
$check( 'save bypasses the gate when ack is present', str_contains( $php, "empty( \$_POST['eem_structural_change_ack'] )" ) );
$check( 'compares NEW stall_inventory_type vs existing', str_contains( $php, '$eem_new_inv !== $eem_cur_inv' ) );
$check( 'compares NEW stall_customer_selection vs existing', str_contains( $php, '$eem_new_sel !== $eem_cur_sel' ) );
$check( 'only gates when orders already exist', str_contains( $php, 'if ( $eem_order_count > 0 )' ) );
$check( 'returns the structural_change_requires_ack code (409)', str_contains( $php, "'structural_change_requires_ack'" ) && str_contains( $php, '), 409 );' ) );
$check( 'message names the changed setting(s) + order count', str_contains( $php, 'already has %2$d order' ) );
$check( 'gate runs BEFORE the publish gate', strpos( $php, 'eem_structural_change_ack' ) < strpos( $php, 'per-section publish-gate validation' ) );

// --- client ack flow -------------------------------------------------------
$check( 'JS has the ack flag', str_contains( $js, 'var _eemStructuralAck = false;' ) );
$check( 'JS includes the ack flag in the POST when set', str_contains( $js, "if (_eemStructuralAck) { body.set('eem_structural_change_ack', '1'); }" ) );
$check( 'JS confirms + resubmits on the ack code', str_contains( $js, "'structural_change_requires_ack' === resp.data.code" ) && str_contains( $js, 'window.confirm(msg)' ) && str_contains( $js, '_eemStructuralAck = true;' ) );
$check( 'JS re-dispatches the same save kind', str_contains( $js, 'eemDispatchSave(kind);' ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
