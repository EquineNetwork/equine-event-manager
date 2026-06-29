<?php
/**
 * #16 — Import/Export carries the event-level dates into the imported reservation.
 *
 * Setup export/import (EEM_Import_Handler::build_export → import_setup) already
 * carries the reservation's available_start/end window via the config table. The
 * gap this guards: TEC/feed-sourced events store NO start/end in en_event
 * post-meta (the dates live in the upstream calendar), so a cloned event imported
 * dateless. import_setup now backfills the cloned event's display dates from the
 * available window when (and only when) the event has none — never overwriting a
 * real event date (native events keep theirs).
 *
 * Run: wp eval-file tests/smoke/import-event-dates-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$pass = 0; $fail = 0;
$check = static function ( string $label, bool $ok ) use ( &$pass, &$fail ): void {
	if ( $ok ) { $pass++; echo "  ok  - {$label}\n"; }
	else { $fail++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

$rc     = new ReflectionClass( 'EEM_Import_Handler' );
$build  = $rc->getMethod( 'build_export' );  $build->setAccessible( true );
$import = $rc->getMethod( 'import_setup' );   $import->setAccessible( true );

$ev_start_key = '_equine_event_manager_event_start_date';
$ev_end_key   = '_equine_event_manager_event_end_date';

$cleanup = array();
$reg     = static function ( $id ) use ( &$cleanup ) { if ( $id ) { $cleanup[] = (int) $id; } return $id; };

/* ── Case A: TEC-like event with NO post-meta dates → backfilled from window ── */
$ev_a  = $reg( wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'Import Dates TEC Event' ) ) );
$res_a = $reg( wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Import Dates TEC Res' ) ) );
EEM_Reservation_Config::for( (int) $res_a )->set_many( array(
	'event_id'             => (int) $ev_a,
	'event_source'         => 'tec',
	'available_start_date' => '2026-08-14',
	'available_end_date'   => '2026-08-17',
) )->save();
EEM_Reservation_Config::flush_cache( (int) $res_a );
$check( 'precondition: TEC source event has no post-meta dates', '' === (string) get_post_meta( $ev_a, $ev_start_key, true ) );

$export_a  = $build->invoke( null, (int) $res_a );
$summary_a = $import->invoke( null, $export_a );
$new_res_a = (int) $summary_a['reservation_id'];
$new_ev_a  = (int) ( $summary_a['event_id'] ?? 0 );
$reg( $new_res_a ); $reg( $new_ev_a ); $reg( $summary_a['venue_id'] ?? 0 );

$cfg_a = EEM_Reservation_Config::for( $new_res_a );
$check( 'imported reservation keeps the available window', '2026-08-14' === (string) $cfg_a->get( 'available_start_date' ) && '2026-08-17' === (string) $cfg_a->get( 'available_end_date' ) );
$check( 'cloned event start backfilled from available_start', '2026-08-14' === (string) get_post_meta( $new_ev_a, $ev_start_key, true ) );
$check( 'cloned event end backfilled from available_end',     '2026-08-17' === (string) get_post_meta( $new_ev_a, $ev_end_key, true ) );

/* ── Case B: native event WITH real dates → preserved, NOT overwritten ── */
$ev_b  = $reg( wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'Import Dates Native Event' ) ) );
update_post_meta( $ev_b, $ev_start_key, '2026-06-25T12:00' );
update_post_meta( $ev_b, $ev_end_key, '2026-06-28T23:59' );
$res_b = $reg( wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Import Dates Native Res' ) ) );
EEM_Reservation_Config::for( (int) $res_b )->set_many( array(
	'event_id'             => (int) $ev_b,
	'event_source'         => 'native',
	'available_start_date' => '2026-06-25',
	'available_end_date'   => '2026-06-28',
) )->save();
EEM_Reservation_Config::flush_cache( (int) $res_b );

$export_b  = $build->invoke( null, (int) $res_b );
$summary_b = $import->invoke( null, $export_b );
$new_ev_b  = (int) ( $summary_b['event_id'] ?? 0 );
$reg( $summary_b['reservation_id'] ?? 0 ); $reg( $new_ev_b ); $reg( $summary_b['venue_id'] ?? 0 );

$check( 'cloned native event keeps its real start (not overwritten by window)', '2026-06-25T12:00' === (string) get_post_meta( $new_ev_b, $ev_start_key, true ) );
$check( 'cloned native event keeps its real end',                               '2026-06-28T23:59' === (string) get_post_meta( $new_ev_b, $ev_end_key, true ) );

foreach ( array_unique( array_filter( $cleanup ) ) as $id ) { wp_delete_post( (int) $id, true ); }

echo "\n=== #16 import event-dates smoke: {$pass} passed, {$fail} failed ===\n";
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'import event-dates smoke passed.' );
