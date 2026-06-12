<?php
/**
 * Entries CPT smoke (v1 — Entries feature, commit 1).
 *
 * The standalone "Entries" entity: an `en_entry` CPT under the Orders menu, each
 * linked to a reservation (event), with price/inventory/max meta, resolved into
 * the legacy pre-entry option shape for the customer/checkout pipeline.
 *
 * Run: wp eval-file tests/smoke/entries-cpt-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- registration ----------------------------------------------------------
$check( 'en_entry CPT is registered', post_type_exists( 'en_entry' ) );
$obj = get_post_type_object( 'en_entry' );
$check( 'Entries CPT lives under the Orders menu', $obj && 'equine-event-manager-orders' === $obj->show_in_menu );
$check( 'Entries CPT is non-public (admin-only)', $obj && ! $obj->public );
$check( 'register() wires the save + meta-box hooks', method_exists( 'EEM_Entries', 'register' ) && method_exists( 'EEM_Entries', 'save' ) && method_exists( 'EEM_Entries', 'render_meta_box' ) );

// --- resolver round-trip ---------------------------------------------------
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Entries Smoke Event' ) );
$eid = wp_insert_post( array( 'post_type' => 'en_entry', 'post_status' => 'publish', 'post_title' => 'Open 4D Barrels' ) );
update_post_meta( $eid, EEM_Entries::META_RESERVATION, $rid );
update_post_meta( $eid, EEM_Entries::META_PRICE, '45.00' );
update_post_meta( $eid, EEM_Entries::META_INVENTORY, 30 );
update_post_meta( $eid, EEM_Entries::META_MAX, 2 );

$opts = EEM_Entries::get_for_reservation( $rid );
$key  = 'entry_' . $eid;
$check( 'resolver returns the linked entry, keyed entry_{id}', isset( $opts[ $key ] ) );
$check( 'resolver carries title', isset( $opts[ $key ]['title'] ) && 'Open 4D Barrels' === $opts[ $key ]['title'] );
$check( 'resolver carries price as a 2dp string', isset( $opts[ $key ]['price'] ) && '45.00' === $opts[ $key ]['price'] );
$check( 'resolver carries inventory + max as ints', 30 === $opts[ $key ]['inventory'] && 2 === $opts[ $key ]['max_per_customer'] );

// Unlinked / other-reservation entries don't leak in.
$other = EEM_Entries::get_for_reservation( $rid + 999999 );
$check( 'unrelated reservation gets no entries', array() === $other );
$check( 'reservation 0 returns empty (guard)', array() === EEM_Entries::get_for_reservation( 0 ) );

// --- customer-pipeline merge: the shortcode resolver pulls CPT entries -------
// get_enabled_pre_entry_options() merges legacy reservation-meta entries with
// the reservation's linked Entries (keyed entry_{id}), using the active
// reservation id set during render/submission.
$sc      = new EEM_Shortcodes();
$ar_prop = new ReflectionProperty( 'EEM_Shortcodes', 'active_reservation_id' );
$ar_prop->setAccessible( true );
$ar_prop->setValue( $sc, $rid );
$resolve = new ReflectionMethod( 'EEM_Shortcodes', 'get_enabled_pre_entry_options' );
$resolve->setAccessible( true );

// Legacy meta entry (numeric key) + the CPT entry (entry_{id}) coexist.
$legacy_data = array( 'event_pre_entries_enabled' => 1, 'event_pre_entries' => array( array( 'title' => 'Legacy Stall Pass', 'price' => '10.00', 'inventory' => 5, 'max_per_customer' => 1 ) ) );
$merged = $resolve->invoke( $sc, $legacy_data );
$check( 'resolver includes the CPT entry (entry_{id})', isset( $merged[ $key ] ) && 'Open 4D Barrels' === $merged[ $key ]['title'] );
$check( 'resolver still includes the legacy numeric entry', isset( $merged['0'] ) && 'Legacy Stall Pass' === $merged['0']['title'] );
$check( 'legacy + CPT keys do not collide', count( $merged ) >= 2 );

// With no active reservation, only legacy entries resolve (no CPT leak).
$ar_prop->setValue( $sc, 0 );
$legacy_only = $resolve->invoke( $sc, $legacy_data );
$check( 'no active reservation → CPT entries excluded', ! isset( $legacy_only[ $key ] ) && isset( $legacy_only['0'] ) );

wp_delete_post( (int) $eid, true );
wp_delete_post( (int) $rid, true );
$check( 'cleaned up temp posts', null === get_post( $eid ) && null === get_post( $rid ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
