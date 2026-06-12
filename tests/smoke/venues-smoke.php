<?php
/**
 * Venues data-layer + resolver smoke (v2 Facility Layout Templates, Slice 1).
 *
 * Proves: source-agnostic resolution (exact source-map hit; normalized-name
 * match across DIFFERENT sources → same canonical venue; distinct name → new
 * venue), and the save → load (copy-on-use) layout round-trip without mutating
 * the saved Venue layout.
 *
 * Run: wp eval-file tests/smoke/venues-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

global $wpdb;
EEM_Venue::create_tables();

// Unique names so reruns don't collide with prior rows.
$suffix = substr( md5( (string) wp_rand() ), 0, 6 );
$ntr    = 'Smoke NTR Arena ' . $suffix;
$burn   = 'Smoke Burnett Complex ' . $suffix;

// --- resolution ------------------------------------------------------------
$a1 = EEM_Venue::resolve( 'tec', 'V100-' . $suffix, $ntr );
$check( 'resolve creates a canonical venue', $a1 > 0 );
$a2 = EEM_Venue::resolve( 'tec', 'V100-' . $suffix, $ntr );
$check( 'exact source-map hit returns the same venue', $a1 === $a2 );
$a3 = EEM_Venue::resolve( 'gems', 'G55-' . $suffix, $ntr ); // different source, same name
$check( 'normalized-name match unifies across sources (TEC + GEMS → one venue)', $a1 === $a3 );
$b1 = EEM_Venue::resolve( 'tec', 'V200-' . $suffix, $burn );
$check( 'a distinct venue name yields a new venue', $b1 > 0 && $b1 !== $a1 );

$maps = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . EEM_Venue::source_map_table() . ' WHERE venue_id = %d', $a1 ) );
$check( 'venue A has two source mappings (tec + gems)', 2 === $maps );

// --- save → load (copy-on-use) round-trip ----------------------------------
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Venue Smoke Res A' ) );
update_post_meta( $rid, '_en_stall_rows', array( array( 'first' => '100', 'last' => '120' ) ) );
update_post_meta( $rid, '_en_rv_lots', array( 'Red Lot', 'Blue Lot' ) );
update_post_meta( $rid, '_en_blocked_stalls', array( '105', '106' ) );

$lid = EEM_Venue::save_layout( $a1, $rid, '2025 Main Barn Layout' );
$check( 'save_layout returns a layout id', $lid > 0 );
$layouts = EEM_Venue::get_layouts( $a1 );
$check( 'get_layouts lists the saved layout by name', ! empty( $layouts ) && '2025 Main Barn Layout' === $layouts[0]['name'] );

// Load into a NEW reservation (copy-on-use).
$rid2 = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Venue Smoke Res B' ) );
$applied = EEM_Venue::apply_layout_to_reservation( $lid, $rid2 );
$check( 'apply_layout_to_reservation succeeds', true === $applied );
$check( 'target got the stall rows', get_post_meta( $rid2, '_en_stall_rows', true ) === array( array( 'first' => '100', 'last' => '120' ) ) );
$check( 'target got the RV lots', get_post_meta( $rid2, '_en_rv_lots', true ) === array( 'Red Lot', 'Blue Lot' ) );
$check( 'target got the blocked stalls', get_post_meta( $rid2, '_en_blocked_stalls', true ) === array( '105', '106' ) );

// Mutate the CLONE — the saved layout must be untouched (copy-on-use).
update_post_meta( $rid2, '_en_stall_rows', array( array( 'first' => '200', 'last' => '210' ) ) );
$saved = EEM_Venue::get_layout( $lid );
$check( 'editing the clone does NOT mutate the saved Venue layout', isset( $saved['layout']['_en_stall_rows'][0]['first'] ) && '100' === $saved['layout']['_en_stall_rows'][0]['first'] );

// --- rename + delete + counts ----------------------------------------------
$check( 'rename_layout works', EEM_Venue::rename_layout( $lid, '2025 Layout (renamed)' ) );
$counts = EEM_Venue::all_with_counts();
$found  = false;
foreach ( $counts as $c ) { if ( (int) $c['id'] === $a1 ) { $found = ( 1 === (int) $c['layout_count'] && 2 === (int) $c['source_count'] ); break; } }
$check( 'all_with_counts reports 1 layout + 2 sources for venue A', $found );
$check( 'delete_layout works', EEM_Venue::delete_layout( $lid ) );
$check( 'layout gone after delete', null === EEM_Venue::get_layout( $lid ) );

// --- cleanup ---------------------------------------------------------------
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::source_map_table() . ' WHERE venue_id IN (%d,%d)', $a1, $b1 ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::layouts_table() . ' WHERE venue_id IN (%d,%d)', $a1, $b1 ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::venues_table() . ' WHERE id IN (%d,%d)', $a1, $b1 ) );
wp_delete_post( (int) $rid, true );
wp_delete_post( (int) $rid2, true );
$check( 'cleaned up', null === EEM_Venue::get( $a1 ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
