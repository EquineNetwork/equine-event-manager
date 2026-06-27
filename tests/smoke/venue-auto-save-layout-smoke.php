<?php
/**
 * Venue auto-save layout smoke (ROADMAP v1 #12 — "never lose a map").
 *
 * Verifies EEM_Venue::auto_save_layout(): a reservation's layout is upserted to
 * a single rolling "Auto-saved (latest)" row per venue (overwritten, never
 * duplicated), the empty-guard skips a contentless reservation, and the auto row
 * coexists with manual named saves + is loadable.
 *
 * Run via: wp eval-file tests/smoke/venue-auto-save-layout-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;
EEM_Venue::create_tables();

$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

// Reservation WITH a stall layout (config stall_rows = the built layout).
$rid = (int) wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'AutoSaveLayout' ) );
EEM_Reservation_Config::for( $rid )->set_many( array(
	'stall_rows' => array( array( 'name' => 'Barn A', 'first' => '100', 'last' => '110' ) ),
) )->save();
EEM_Reservation_Config::flush_cache( $rid );

$vid = EEM_Venue::resolve( 'tec', 'AUTOSAVE-EVT', 'Auto Save Venue' );
$chk( $vid > 0, 'venue resolved' );

// First auto-save creates the rolling row.
$a1 = EEM_Venue::auto_save_layout( $vid, $rid );
$chk( $a1 > 0, 'first auto_save_layout creates a row' );
$auto_rows = array_filter( EEM_Venue::get_layouts( $vid ), static function ( $r ) {
	return EEM_Venue::AUTO_LAYOUT_NAME === $r['name'];
} );
$chk( 1 === count( $auto_rows ), 'exactly one "Auto-saved (latest)" row after first save' );

// Second auto-save OVERWRITES (same id, still one row — not a duplicate).
$a2 = EEM_Venue::auto_save_layout( $vid, $rid );
$chk( $a1 === $a2, 'second auto_save_layout overwrites the same row id (rolling latest)' );
$auto_rows = array_filter( EEM_Venue::get_layouts( $vid ), static function ( $r ) {
	return EEM_Venue::AUTO_LAYOUT_NAME === $r['name'];
} );
$chk( 1 === count( $auto_rows ), 'still exactly one auto row after second save (no duplicate)' );

// The auto row is loadable and carries the layout.
$loaded = EEM_Venue::get_layout( $a1 );
$chk( is_array( $loaded ) && ! empty( $loaded['layout']['_en_stall_rows'] ), 'auto layout round-trips the stall rows' );

// Empty-guard: a reservation with no layout does NOT create/overwrite an auto row.
$empty_rid = (int) wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'EmptyLayout' ) );
$empty_vid = EEM_Venue::resolve( 'tec', 'EMPTY-EVT', 'Empty Layout Venue' );
$e = EEM_Venue::auto_save_layout( $empty_vid, $empty_rid );
$chk( 0 === $e, 'empty reservation → auto_save_layout returns 0 (no clobber)' );
$chk( 0 === count( EEM_Venue::get_layouts( $empty_vid ) ), 'no auto row created for an empty layout' );

// A manual named save coexists with the auto row.
$manual = EEM_Venue::save_layout( $vid, $rid, '2026 Finals Layout' );
$chk( $manual > 0 && $manual !== $a1, 'manual save creates a separate row' );
$names = wp_list_pluck( EEM_Venue::get_layouts( $vid ), 'name' );
$chk( in_array( EEM_Venue::AUTO_LAYOUT_NAME, $names, true ) && in_array( '2026 Finals Layout', $names, true ), 'auto + manual layouts coexist' );

// Cleanup.
$wpdb->delete( EEM_Venue::layouts_table(), array( 'venue_id' => $vid ) );
$wpdb->delete( EEM_Venue::layouts_table(), array( 'venue_id' => $empty_vid ) );
wp_delete_post( $rid, true );
wp_delete_post( $empty_rid, true );

echo "\nDone. PASS=$pass FAIL=$fail\n";
