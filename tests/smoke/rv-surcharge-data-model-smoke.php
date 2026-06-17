<?php
/**
 * RV Surcharge Slice 2 smoke — per-rate-type surcharge value type + row backfill.
 *
 * Covers the EEM_Surcharge canonical value type (sanitize / readers / stacking /
 * is_zero / legacy bridge) and the migration's per-row transform + idempotency on
 * a throwaway reservation. The real eem-mig-028 global pass is exercised on
 * activation (version-gated); here we assert the unit behavior the migration and
 * the editor row-save both rely on.
 *
 * Run: wp eval-file tests/smoke/rv-surcharge-data-model-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

// ── EEM_Surcharge value type ──
$check( 'class exists', class_exists( 'EEM_Surcharge' ) );
$check( 'zero() = nightly 0 + empty packages', 0.0 === EEM_Surcharge::zero()['nightly'] && array() === EEM_Surcharge::zero()['packages'] );
$check( 'sanitize(10) legacy numeric → nightly 10', 10.0 === EEM_Surcharge::sanitize( 10 )['nightly'] );

$s = EEM_Surcharge::sanitize( array( 'nightly' => '5.5', 'packages' => array( 'pkg_a' => '100', 'pkg_b' => -3, '' => 9 ) ) );
$check( 'sanitize nightly 5.5', 5.5 === $s['nightly'] );
$check( 'sanitize package pkg_a 100', 100.0 === $s['packages']['pkg_a'] );
$check( 'sanitize clamps negative package to 0', 0.0 === $s['packages']['pkg_b'] );
$check( 'sanitize drops empty package id', ! array_key_exists( '', $s['packages'] ) );
$check( 'sanitize(junk) → zero', EEM_Surcharge::is_zero( EEM_Surcharge::sanitize( 'garbage' ) ) );
$check( 'rounds to 2 decimals', 5.56 === EEM_Surcharge::sanitize( array( 'nightly' => 5.555 ) )['nightly'] );

$check( 'nightly() reader', 5.5 === EEM_Surcharge::nightly( $s ) );
$check( 'for_package() reader', 100.0 === EEM_Surcharge::for_package( $s, 'pkg_a' ) );
$check( 'for_package() missing → 0', 0.0 === EEM_Surcharge::for_package( $s, 'nope' ) );

$sum = EEM_Surcharge::add(
	array( 'nightly' => 5, 'packages' => array( 'wk' => 100 ) ),
	array( 'nightly' => 10, 'packages' => array( 'wk' => 40, 'we' => 20 ) )
);
$check( 'add() stacks nightly 5+10=15', 15.0 === $sum['nightly'] );
$check( 'add() stacks shared package 100+40=140', 140.0 === $sum['packages']['wk'] );
$check( 'add() unions disjoint package =20', 20.0 === $sum['packages']['we'] );

$check( 'is_zero(zero) true', EEM_Surcharge::is_zero( EEM_Surcharge::zero() ) );
$check( 'is_zero(nightly>0) false', ! EEM_Surcharge::is_zero( EEM_Surcharge::sanitize( 3 ) ) );
$check( 'is_zero(package>0) false', ! EEM_Surcharge::is_zero( EEM_Surcharge::sanitize( array( 'packages' => array( 'x' => 1 ) ) ) ) );
$check( 'from_legacy_nightly(7) → nightly 7', 7.0 === EEM_Surcharge::from_legacy_nightly( 7 )['nightly'] );

// ── migration per-row transform + idempotency (throwaway reservation) ──
$rid = (int) wp_insert_post( array( 'post_type' => EEM_Reservations_CPT::POST_TYPE, 'post_status' => 'draft', 'post_title' => 'rv-surcharge-smoke tmp' ) );
$cfg = EEM_Reservation_Config::for( $rid );
$cfg->set( 'rv_rows', array(
	array( 'name' => 'Standard', 'first' => '1', 'last' => '10', 'nightly_surcharge' => '0.00' ),
	array( 'name' => 'Premium',  'first' => '11', 'last' => '20', 'nightly_surcharge' => '15.00' ),
) );
$cfg->save();
EEM_Reservation_Config::flush_cache( $rid );

$transform = function ( $rid ) {
	$cfg  = EEM_Reservation_Config::for( $rid );
	$rows = $cfg->get( 'rv_rows' );
	if ( ! is_array( $rows ) ) { return; }
	$changed = false;
	foreach ( $rows as $i => $row ) {
		if ( ! is_array( $row ) ) { continue; }
		if ( isset( $row['surcharge'] ) && is_array( $row['surcharge'] ) ) { continue; }
		$rows[ $i ]['surcharge'] = EEM_Surcharge::from_legacy_nightly( $row['nightly_surcharge'] ?? 0 );
		$changed = true;
	}
	if ( $changed ) { $cfg->set( 'rv_rows', $rows ); $cfg->save(); }
};

$transform( $rid );
EEM_Reservation_Config::flush_cache( $rid );
$rows = EEM_Reservation_Config::for( $rid )->get( 'rv_rows' );
$check( 'backfill: row 0 has surcharge object', isset( $rows[0]['surcharge'] ) && is_array( $rows[0]['surcharge'] ) );
$check( 'backfill: Premium surcharge.nightly = 15', 15.0 === EEM_Surcharge::nightly( $rows[1]['surcharge'] ) );
$check( 'backfill: legacy nightly_surcharge preserved', '15.00' === (string) $rows[1]['nightly_surcharge'] );

$before = wp_json_encode( $rows );
$transform( $rid );
EEM_Reservation_Config::flush_cache( $rid );
$check( 'backfill idempotent', $before === wp_json_encode( EEM_Reservation_Config::for( $rid )->get( 'rv_rows' ) ) );

wp_delete_post( $rid, true );

WP_CLI::log( "" );
if ( $fail ) { WP_CLI::error( "{$fail} failed, {$pass} passed" ); }
WP_CLI::success( "{$pass} passed, 0 failed" );
