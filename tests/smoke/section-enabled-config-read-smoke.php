<?php
/**
 * Section-enabled config-read smoke.
 *
 * The v4 editor writes section toggles to the relational config table, but
 * read_section_enabled_raw() used to read stale post-meta — so a section enabled
 * in the editor read as OFF at checkout (admin enables Add-Ons/Groups, customer
 * never sees them). The read now prefers the config value when a config row
 * exists. This smoke pins that behavior + the guards around it.
 *
 * Run: wp eval-file tests/smoke/section-enabled-config-read-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// Seed a reservation, set add-ons ON in the CONFIG TABLE only (mirroring what the
// v4 editor does), and write a STALE post-meta OFF (mirroring the divergence an
// import / legacy edit leaves behind).
$rid = (int) wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'draft', 'post_title' => 'Section-read smoke' ) );
$check( 'seed reservation created', $rid > 0 );

EEM_Reservation_Config::for( $rid )->set_many( array(
	'general_addons_enabled'     => 1,
	'group_reservations_enabled' => 1,
	'stalls_enabled'             => 1,
	'rv_enabled'                 => 0,
) )->save();
EEM_Reservation_Config::flush_cache( $rid );

// Stale post-meta that disagrees with config (the bug condition).
update_post_meta( $rid, '_eem_section_enabled_addons', '0' );
update_post_meta( $rid, '_eem_section_enabled_group', '0' );

$check( 'config row now exists', EEM_Reservation_Config::row_exists( $rid ) );

// The fix: section_enabled reflects the CONFIG value, not the stale post-meta.
$check( 'add-ons reads ON from config (not stale post-meta OFF)', EEM_Reservations_CPT::section_enabled( $rid, 'general_addons_enabled' ) === true );
$check( 'groups reads ON from config (not stale post-meta OFF)', EEM_Reservations_CPT::section_enabled( $rid, 'group_reservations_enabled' ) === true );
$check( 'stalls reads ON from config', EEM_Reservations_CPT::section_enabled( $rid, 'stalls_enabled' ) === true );
$check( 'rv reads OFF from config (config-OFF is honored, no false-ON)', EEM_Reservations_CPT::section_enabled( $rid, 'rv_enabled' ) === false );

// read_section_enabled_raw returns the config scalar for mapped fields.
$raw = EEM_Reservations_CPT::read_section_enabled_raw( $rid, 'general_addons_enabled' );
$check( 'read_section_enabled_raw returns config value for add-ons', ! empty( $raw ) );

// No-row reservation falls back to post-meta (legacy path preserved).
$rid2 = (int) wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'draft', 'post_title' => 'No-config-row smoke' ) );
update_post_meta( $rid2, '_eem_section_enabled_addons', '1' );
// Ensure no config row.
global $wpdb;
$wpdb->delete( $wpdb->prefix . 'eem_reservation_config', array( 'reservation_id' => $rid2 ) );
$check( 'no-row reservation: no config row', ! EEM_Reservation_Config::row_exists( $rid2 ) );
$check( 'no-row reservation: still reads post-meta (ON)', EEM_Reservations_CPT::section_enabled( $rid2, 'general_addons_enabled' ) === true );

// No recursion / no fatal — reaching here means the read path completed.
$check( 'read path completes without recursion/fatal', true );

// Cleanup.
wp_delete_post( $rid, true );
wp_delete_post( $rid2, true );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
