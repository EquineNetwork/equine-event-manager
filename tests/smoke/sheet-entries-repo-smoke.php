<?php
/**
 * Sheets & Results data-layer smoke (Slice 1).
 *
 * Exercises EEM_Sheet_Entries end-to-end: table creation, the en_discipline
 * taxonomy registration, the add/get/update/set_pdf/delete CRUD, the
 * discipline grouping (including assigned-but-empty disciplines), and the
 * counts / has_drawsheets / has_results helpers that drive the conditional
 * event-card buttons.
 *
 * Run: wp eval-file tests/smoke/sheet-entries-repo-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

global $wpdb;

// --- 0. Class + table --------------------------------------------------------
$check( 'EEM_Sheet_Entries class loaded', class_exists( 'EEM_Sheet_Entries' ) );

EEM_Sheet_Entries::create_table();
$table  = EEM_Sheet_Entries::table_name();
$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
$check( 'wp_eem_sheet_entries table exists', $exists === $table );

// --- 1. Taxonomy -------------------------------------------------------------
// The taxonomy is registered on init when native events are enabled. Ensure the
// content types are registered for this run regardless of the stored source so
// the smoke is deterministic.
if ( ! taxonomy_exists( EEM_Sheet_Entries::TAXONOMY ) ) {
	$events = new EEM_Events();
	$events->register_content_types();
}
$check( 'en_discipline taxonomy registered', taxonomy_exists( 'en_discipline' ) );

$tax = get_taxonomy( 'en_discipline' );
$check( 'en_discipline is flat (non-hierarchical)', $tax && false === $tax->hierarchical );
$check( 'en_discipline attached to en_event', $tax && in_array( 'en_event', (array) $tax->object_type, true ) );

// --- 2. Round helpers --------------------------------------------------------
$rounds = EEM_Sheet_Entries::rounds();
$check( 'rounds() returns 8 options', count( $rounds ) === 8 );
$check( 'rounds() includes finals + average', isset( $rounds['finals'], $rounds['average'] ) );
$check( 'round_label(finals) non-empty', '' !== EEM_Sheet_Entries::round_label( 'finals' ) );
$check( 'round_label("") empty', '' === EEM_Sheet_Entries::round_label( '' ) );

// --- 3. Seed an event + two disciplines --------------------------------------
$event_id = wp_insert_post(
	array(
		'post_type'   => 'en_event',
		'post_status' => 'publish',
		'post_title'  => 'S&R Smoke Event',
	)
);
$check( 'seed event created', $event_id > 0 );

$barrels   = wp_insert_term( 'SR Smoke Barrel Racing', 'en_discipline' );
$breakaway = wp_insert_term( 'SR Smoke Breakaway', 'en_discipline' );
$tiedown   = wp_insert_term( 'SR Smoke Tie-Down', 'en_discipline' );
$barrels_id   = is_array( $barrels ) ? (int) $barrels['term_id'] : 0;
$breakaway_id = is_array( $breakaway ) ? (int) $breakaway['term_id'] : 0;
$tiedown_id   = is_array( $tiedown ) ? (int) $tiedown['term_id'] : 0;
$check( 'three discipline terms created', $barrels_id && $breakaway_id && $tiedown_id );

// Assign all three to the event (Tie-Down will stay empty to test that path).
wp_set_object_terms( $event_id, array( $barrels_id, $breakaway_id, $tiedown_id ), 'en_discipline' );

// --- 4. add_entry (draw sheet only) ------------------------------------------
$entry_id = EEM_Sheet_Entries::add_entry(
	array(
		'event_id'      => $event_id,
		'discipline_id' => $barrels_id,
		'label'         => 'Open 5D Long Go',
		'round'         => '1st-go',
		'entry_date'    => '2026-06-14',
		'drawsheet_pdf' => 4242,
	)
);
$check( 'add_entry returns row id', $entry_id > 0 );

$row = EEM_Sheet_Entries::get( $entry_id );
$check( 'get() shapes row', is_array( $row ) && $row['id'] === $entry_id );
$check( 'label persisted', $row && 'Open 5D Long Go' === $row['label'] );
$check( 'round slug persisted', $row && '1st-go' === $row['round'] );
$check( 'date persisted Y-m-d', $row && '2026-06-14' === $row['entry_date'] );
$check( 'drawsheet_pdf persisted', $row && 4242 === $row['drawsheet_pdf'] );
$check( 'result_pdf starts empty (mirror)', $row && 0 === $row['result_pdf'] );

// add_entry guards
$check( 'add_entry without event_id returns 0', 0 === EEM_Sheet_Entries::add_entry( array( 'label' => 'x' ) ) );

// --- 5. round + date sanitization --------------------------------------------
$bad = EEM_Sheet_Entries::add_entry(
	array(
		'event_id'      => $event_id,
		'discipline_id' => $barrels_id,
		'label'         => 'Bad Round',
		'round'         => 'not-a-real-round',
		'entry_date'    => 'not-a-date',
		'drawsheet_pdf' => 7,
	)
);
$bad_row = EEM_Sheet_Entries::get( $bad );
$check( 'unknown round sanitized to ""', $bad_row && '' === $bad_row['round'] );
$check( 'invalid date sanitized to ""', $bad_row && '' === $bad_row['entry_date'] );

// --- 6. counts + has_* (draw sheets only so far) -----------------------------
$counts = EEM_Sheet_Entries::counts( $event_id );
$check( 'counts drawsheets = 2', 2 === $counts['drawsheets'] );
$check( 'counts results = 0', 0 === $counts['results'] );
$check( 'has_drawsheets true', true === EEM_Sheet_Entries::has_drawsheets( $event_id ) );
$check( 'has_results false', false === EEM_Sheet_Entries::has_results( $event_id ) );

// --- 7. set_pdf (upload the result) ------------------------------------------
$check( 'set_pdf result ok', true === EEM_Sheet_Entries::set_pdf( $entry_id, 'result', 9090 ) );
$row = EEM_Sheet_Entries::get( $entry_id );
$check( 'result_pdf now set', $row && 9090 === $row['result_pdf'] );
$check( 'has_results now true', true === EEM_Sheet_Entries::has_results( $event_id ) );
$check( 'counts results = 1', 1 === EEM_Sheet_Entries::counts( $event_id )['results'] );

// --- 8. update_entry ---------------------------------------------------------
$check( 'update_entry label ok', true === EEM_Sheet_Entries::update_entry( $entry_id, array( 'label' => 'Open 5D Long Go (rev)' ) ) );
$row = EEM_Sheet_Entries::get( $entry_id );
$check( 'updated label persisted', $row && 'Open 5D Long Go (rev)' === $row['label'] );

// --- 9. grouping (assigned-but-empty discipline appears) ---------------------
$groups = EEM_Sheet_Entries::get_for_event_grouped_by_discipline( $event_id );
$by_id  = array();
foreach ( $groups as $g ) { $by_id[ $g['discipline_id'] ] = $g; }
$check( 'grouped: all 3 disciplines present', isset( $by_id[ $barrels_id ], $by_id[ $breakaway_id ], $by_id[ $tiedown_id ] ) );
$check( 'grouped: barrels has 2 entries', isset( $by_id[ $barrels_id ] ) && 2 === count( $by_id[ $barrels_id ]['entries'] ) );
$check( 'grouped: breakaway empty group', isset( $by_id[ $breakaway_id ] ) && 0 === count( $by_id[ $breakaway_id ]['entries'] ) );
$check( 'grouped: tie-down empty group', isset( $by_id[ $tiedown_id ] ) && 0 === count( $by_id[ $tiedown_id ]['entries'] ) );
$check( 'grouped: barrels name carried', isset( $by_id[ $barrels_id ] ) && 'SR Smoke Barrel Racing' === $by_id[ $barrels_id ]['discipline_name'] );

// --- 10. delete_entry --------------------------------------------------------
$check( 'delete_entry ok', true === EEM_Sheet_Entries::delete_entry( $bad ) );
$check( 'deleted row gone', null === EEM_Sheet_Entries::get( $bad ) );
$check( 'counts drawsheets back to 1', 1 === EEM_Sheet_Entries::counts( $event_id )['drawsheets'] );

// --- cleanup -----------------------------------------------------------------
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Sheet_Entries::table_name() . ' WHERE event_id = %d', $event_id ) ); // phpcs:ignore WordPress.DB
wp_delete_post( $event_id, true );
wp_delete_term( $barrels_id, 'en_discipline' );
wp_delete_term( $breakaway_id, 'en_discipline' );
wp_delete_term( $tiedown_id, 'en_discipline' );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
