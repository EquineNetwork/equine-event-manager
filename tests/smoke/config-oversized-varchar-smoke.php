<?php
/**
 * Reservation-config oversized-varchar smoke (0d fix — 2.7.468).
 *
 * Regression guard for the migration-016 backfill data-loss bug: a legacy
 * `_en_checkin_time` postmeta holding an unsanitized 'YYYY-MM-DDTHH:MM'
 * datetime (16 chars) overflowed the `checkin_time varchar(10)` column, which
 * made $wpdb->replace() reject the ENTIRE row — silently dropping `stall_rows`
 * (the stall map) and every other column with it. cast_for_db() now truncates
 * varchar/char values to their declared width so one oversized field can never
 * fail the whole row. This asserts the row survives AND the map is intact.
 *
 * Run: wp eval-file tests/smoke/config-oversized-varchar-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

global $wpdb;

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$res_id = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'draft',
	'post_title'  => '0d oversized-varchar smoke',
) );
$check( 'seed: throwaway reservation created', $res_id > 0 );

// Hydrate a values array as the backfill reader would, but with an oversized
// legacy checkin_time (16 chars into a varchar(10) column) alongside a real map.
$cpt    = new EEM_Reservations_CPT();
$values = $cpt->get_meta_values( $res_id, true );
$values['checkin_time']  = '2026-06-15T08:00';   // 16 chars — overflows varchar(10)
$values['checkout_time'] = '2026-06-18T18:00';   // 16 chars — overflows varchar(10)
$values['stall_rows']    = array(
	array( 'name' => 'Red Barn', 'layout' => 'one-sided', 'first' => '100', 'last' => '115' ),
	array( 'name' => 'Y Section', 'layout' => 'one-sided', 'first' => 'Y1', 'last' => 'Y12' ),
);

$wpdb->show_errors(); $wpdb->suppress_errors( false );
EEM_Reservation_Config::flush_cache( $res_id );
$ok = EEM_Reservation_Config::insert_from_values( $res_id, $values );

$check( 'insert_from_values succeeds despite oversized checkin/checkout', true === $ok );
$check( 'no swallowed DB error', '' === (string) $wpdb->last_error );

EEM_Reservation_Config::flush_cache( $res_id );
$cfg  = EEM_Reservation_Config::for( $res_id );
$rows = $cfg->get( 'stall_rows' );
$check( 'stall_rows (the map) survived the insert', is_array( $rows ) && 2 === count( $rows ) );
$check( 'stall_rows content intact (Red Barn + Y Section)',
	is_array( $rows ) && 'Red Barn' === ( $rows[0]['name'] ?? '' ) && 'Y Section' === ( $rows[1]['name'] ?? '' ) );
$check( 'oversized checkin_time truncated to column width (<=10 chars)', mb_strlen( (string) $cfg->get( 'checkin_time' ) ) <= 10 );
$check( 'oversized checkout_time truncated to column width (<=10 chars)', mb_strlen( (string) $cfg->get( 'checkout_time' ) ) <= 10 );

// A normal-width value passes through untouched.
EEM_Reservation_Config::flush_cache( $res_id );
$values['checkin_time'] = '08:00';
EEM_Reservation_Config::insert_from_values( $res_id, $values );
EEM_Reservation_Config::flush_cache( $res_id );
$check( 'in-range value passes through unchanged', '08:00' === (string) EEM_Reservation_Config::for( $res_id )->get( 'checkin_time' ) );

// --- teardown -------------------------------------------------------------
$wpdb->delete( $wpdb->prefix . 'eem_reservation_config', array( 'reservation_id' => $res_id ), array( '%d' ) );
wp_delete_post( $res_id, true );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
