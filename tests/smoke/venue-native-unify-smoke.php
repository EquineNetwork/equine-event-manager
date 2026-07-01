<?php
/**
 * Native en_venue ↔ canonical EEM_Venue unification smoke (v2 venue Slice 1).
 *
 * Covers sync_native_venue() (eager resolve + name-sync + durable back-reference),
 * update_name(), the find_for_native_venue() fast path via the back-ref meta, and
 * the backfill migration eem-mig-015.
 *
 * Run: wp eval-file tests/smoke/venue-native-unify-smoke.php
 */

if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }

$pass = 0; $fail = 0;
$ok = static function ( $label, $cond ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  - {$label}\n"; }
	else { $fail++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }
if ( ! post_type_exists( 'en_venue' ) && class_exists( 'EEM_Events' ) ) {
	( new EEM_Events() )->register_content_types();
}
// #55: the save_post_en_venue → sync_native_venue_on_save wiring is registered by
// the plugin ONLY when Native Events is enabled (a v2 feature, gated off by
// default). Mirror that wiring here so the eager-sync behavior under test runs
// regardless of the env toggle (matches the plugin's native-events-on path).
if ( class_exists( 'EEM_Events' ) && ! has_action( 'save_post_en_venue', array( $GLOBALS['eem_smoke_events'] ?? null, 'sync_native_venue_on_save' ) ) ) {
	$GLOBALS['eem_smoke_events'] = new EEM_Events();
	add_action( 'save_post_en_venue', array( $GLOBALS['eem_smoke_events'], 'sync_native_venue_on_save' ), 20, 1 );
}
EEM_Venue::create_tables();

$suffix = substr( md5( (string) wp_rand() ), 0, 6 );
$meta   = EEM_Venue::CANONICAL_VENUE_META;

// --- sync creates the canonical venue + back-reference ----------------------
$venue = wp_insert_post( array( 'post_type' => 'en_venue', 'post_status' => 'publish', 'post_title' => 'NU Venue ' . $suffix ) );
$ok( 'seed en_venue', $venue > 0 );
// The save_post_en_venue hook (sync_native_venue_on_save) unifies eagerly on
// insert, so the back-ref already exists here — that proves the hook is wired.
$ok( 'save hook linked the venue on insert', (int) get_post_meta( $venue, $meta, true ) > 0 );

$vid = EEM_Venue::sync_native_venue( (int) $venue );
$ok( 'sync returns a canonical venue id', $vid > 0 );
$ok( 'back-ref post-meta matches the synced id', (int) get_post_meta( $venue, $meta, true ) === $vid );
$row = EEM_Venue::get( $vid );
$ok( 'canonical name matches the post title', $row && 'NU Venue ' . $suffix === (string) $row['name'] );

// --- sync is idempotent ------------------------------------------------------
$ok( 'second sync returns the same id', EEM_Venue::sync_native_venue( (int) $venue ) === $vid );

// --- name change propagates --------------------------------------------------
wp_update_post( array( 'ID' => $venue, 'post_title' => 'NU Venue Renamed ' . $suffix ) );
$vid2 = EEM_Venue::sync_native_venue( (int) $venue );
$ok( 'rename keeps the same canonical id', $vid2 === $vid );
$row2 = EEM_Venue::get( $vid );
$ok( 'canonical name follows the post title', $row2 && 'NU Venue Renamed ' . $suffix === (string) $row2['name'] );
$ok( 'normalized_key updated on rename', $row2 && EEM_Venue::normalize_key( 'NU Venue Renamed ' . $suffix ) === (string) $row2['normalized_key'] );

// --- find_for_native_venue fast path uses the back-ref ----------------------
$ok( 'find_for_native_venue returns the linked venue', EEM_Venue::find_for_native_venue( (int) $venue ) === $vid );
// Even if the title no longer matches the canonical name, the back-ref still resolves.
wp_update_post( array( 'ID' => $venue, 'post_title' => 'Totally Different ' . $suffix ) );
$ok( 'find still resolves via back-ref after title drift', EEM_Venue::find_for_native_venue( (int) $venue ) === $vid );

// --- update_name guards ------------------------------------------------------
$ok( 'update_name rejects empty name', false === EEM_Venue::update_name( $vid, '   ' ) );
$ok( 'update_name rejects id 0', false === EEM_Venue::update_name( 0, 'x' ) );

// --- sync guards -------------------------------------------------------------
$ok( 'sync rejects non-venue post', 0 === EEM_Venue::sync_native_venue( (int) $admins[0]->ID ) );
$ok( 'sync rejects id 0', 0 === EEM_Venue::sync_native_venue( 0 ) );

// --- runtime unify links a venue that has no back-ref -----------------------
// The one-time backfill (eem-mig-015) was collapsed into the #41 baseline; the
// ongoing sync_native_venue() path performs the same unification on demand.
$bare = wp_insert_post( array( 'post_type' => 'en_venue', 'post_status' => 'publish', 'post_title' => 'NU Bare ' . $suffix ) );
delete_post_meta( $bare, $meta ); // ensure unlinked
EEM_Venue::sync_native_venue( (int) $bare );
$ok( 'runtime sync linked the bare venue', (int) get_post_meta( $bare, $meta, true ) > 0 );

// --- cleanup -----------------------------------------------------------------
global $wpdb;
foreach ( array( $vid ) as $cv ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::venues_table() . ' WHERE id = %d', $cv ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::source_map_table() . ' WHERE venue_id = %d', $cv ) ); // phpcs:ignore WordPress.DB
}
$bare_vid = (int) get_post_meta( $bare, $meta, true );
if ( $bare_vid > 0 ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::venues_table() . ' WHERE id = %d', $bare_vid ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Venue::source_map_table() . ' WHERE venue_id = %d', $bare_vid ) ); // phpcs:ignore WordPress.DB
}
wp_delete_post( (int) $venue, true );
wp_delete_post( (int) $bare, true );

echo "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
if ( $fail > 0 ) { exit( 1 ); }
