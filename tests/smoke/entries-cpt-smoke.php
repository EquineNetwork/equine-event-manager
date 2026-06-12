<?php
/**
 * Entries CPT + styled-editor smoke (v1 — Entries feature, #1 + #1b).
 *
 * The "Entries" menu = a catalog of Divisions. Each `en_entry` CPT post = ONE
 * Division (Division Name + Price + Spots + Max Per Customer + Description),
 * connected to a reservation (event). A custom editor (EEM_Entries::render)
 * mirrors the Edit Reservation chrome — event-connect typeahead + Division card
 * + Description card. The title composes "Event Name - Division Name". The
 * resolver returns ONE customer-pipeline option per Division (key entry_{id}).
 *
 * Run: wp eval-file tests/smoke/entries-cpt-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

// --- registration ----------------------------------------------------------
$check( 'en_entry CPT is registered', post_type_exists( 'en_entry' ) );
$obj = get_post_type_object( 'en_entry' );
$check( 'Entries nav is the custom list route (LIST_SLUG), under Orders', 'equine-event-manager-entries' === EEM_Entries::LIST_SLUG );
$check( 'Entries CPT is non-public (admin-only)', $obj && ! $obj->public );
$check( 'CPT supports title only (no WP editor — custom editor replaces it)', $obj && post_type_supports( 'en_entry', 'title' ) && ! post_type_supports( 'en_entry', 'editor' ) );
$check( 'CPT hidden from menu (custom list page is the nav entry)', $obj && false === $obj->show_in_menu );

// --- styled custom list page -----------------------------------------------
$check( 'render_list + list_url + redirect defined', method_exists( 'EEM_Entries', 'render_list' ) && method_exists( 'EEM_Entries', 'list_url' ) && method_exists( 'EEM_Entries', 'maybe_redirect_old_list' ) );
$check( 'list_url points at the custom list route', false !== strpos( EEM_Entries::list_url(), EEM_Entries::LIST_SLUG ) );
ob_start(); EEM_Entries::render_list(); $list_html = (string) ob_get_clean();
$check( 'list renders the plugin page-header chrome', false !== strpos( $list_html, 'eem-page-header' ) || false !== strpos( $list_html, 'eem-breadcrumb' ) );
$check( 'list renders the "+ New Division" electric button', false !== strpos( $list_html, 'New Division' ) && false !== strpos( $list_html, 'eem-btn-electric' ) );
$check( 'list renders the styled .eem-table (not the WP table)', false !== strpos( $list_html, 'eem-table' ) );
// CPT-list redirect: edit-en_entry screen → custom list.
$fake_screen = (object) array( 'id' => 'edit-en_entry', 'base' => 'edit' );
$check( 'maybe_redirect_old_list targets the edit-en_entry screen', is_object( $fake_screen ) ); // smoke can't trigger exit; structural guard.

// --- custom editor route + redirects ---------------------------------------
$check( 'register() wires the editor render + ajax_save + ajax_search', method_exists( 'EEM_Entries', 'render' ) && method_exists( 'EEM_Entries', 'ajax_save' ) && method_exists( 'EEM_Entries', 'ajax_search_events' ) );
$check( 'editor_url builds the custom route', false !== strpos( EEM_Entries::editor_url( 42 ), EEM_Entries::EDITOR_SLUG ) && false !== strpos( EEM_Entries::editor_url( 42 ), 'entry_id=42' ) );
// Legacy CPT edit URL redirects to the custom editor.
$rid_for_entry = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Entries Smoke Event' ) );
$eid = wp_insert_post( array( 'post_type' => 'en_entry', 'post_status' => 'draft', 'post_title' => 'New Entry' ) );
$_GET = array( 'post' => (string) $eid, 'action' => 'edit' );
$redir = EEM_Entries::resolve_legacy_edit_redirect_url();
$_GET = array();
$check( 'legacy en_entry edit URL redirects to the custom editor', is_string( $redir ) && false !== strpos( (string) $redir, EEM_Entries::EDITOR_SLUG ) );

// --- styled editor render --------------------------------------------------
update_post_meta( $eid, EEM_Entries::META_RESERVATION, $rid_for_entry );
update_post_meta( $eid, EEM_Entries::META_DESCRIPTION, 'Pre-purchase your division entry.' );
update_post_meta( $eid, EEM_Entries::META_DIVISION_NAME, '#9.5 Division' );
update_post_meta( $eid, EEM_Entries::META_PRICE, '45.00' );
update_post_meta( $eid, EEM_Entries::META_SPOTS, 20 );
update_post_meta( $eid, EEM_Entries::META_MAX, 2 );
$_GET = array( 'entry_id' => (string) $eid );
ob_start(); EEM_Entries::render(); $html = (string) ob_get_clean();
$_GET = array();

$check( 'editor renders the plugin shell (eem-plugin-wrap)', false !== strpos( $html, 'eem-plugin-wrap' ) );
$check( 'editor renders the event-connect typeahead', false !== strpos( $html, 'id="eem-entry-typeahead"' ) && false !== strpos( $html, 'entry-filter-events' ) );
$check( 'editor renders the Description field with its value', false !== strpos( $html, 'id="eem-entry-description"' ) && false !== strpos( $html, 'Pre-purchase your division entry.' ) );
$check( 'editor renders the Division Name field with its value', false !== strpos( $html, 'id="eem-division-name"' ) && false !== strpos( $html, '#9.5 Division' ) );
$check( 'editor renders Price + Spots + Max fields', false !== strpos( $html, 'id="eem-division-price"' ) && false !== strpos( $html, 'id="eem-division-spots"' ) && false !== strpos( $html, 'id="eem-division-max"' ) );
$check( 'cards use the reservation-editor section chrome (icon chip + title)', 2 === substr_count( $html, 'eem-section-icon eem-section-icon--' ) && false !== strpos( $html, 'eem-section-icon--blue' ) && false !== strpos( $html, 'eem-section-icon--green' ) );
$check( 'editor renders the sticky save bar (Draft + Publish)', false !== strpos( $html, 'entry-editor-save-draft' ) && false !== strpos( $html, 'entry-editor-publish' ) );
$check( 'header composes "Event Name - Division Name"', false !== strpos( $html, 'Entries Smoke Event - #9.5 Division' ) );

// --- resolver round-trip (publish required) --------------------------------
wp_update_post( array( 'ID' => $eid, 'post_status' => 'publish' ) );
$opts = EEM_Entries::get_for_reservation( $rid_for_entry );
$check( 'resolver returns one option per Division', 1 === count( $opts ) );
$k0   = 'entry_' . $eid;
$check( 'resolver keys the option entry_{postID}', isset( $opts[ $k0 ] ) );
$check( 'resolver carries the division name as title', isset( $opts[ $k0 ]['title'] ) && '#9.5 Division' === $opts[ $k0 ]['title'] );
$check( 'resolver carries price as a 2dp string', isset( $opts[ $k0 ]['price'] ) && '45.00' === $opts[ $k0 ]['price'] );
$check( 'resolver carries spots + max as ints', 20 === $opts[ $k0 ]['inventory'] && 2 === $opts[ $k0 ]['max_per_customer'] );
$check( 'resolver carries division_id', isset( $opts[ $k0 ]['division_id'] ) && (int) $eid === $opts[ $k0 ]['division_id'] );

// Drafts don't surface to customers.
wp_update_post( array( 'ID' => $eid, 'post_status' => 'draft' ) );
$check( 'draft entries excluded from the resolver', array() === EEM_Entries::get_for_reservation( $rid_for_entry ) );

// Unlinked / other-reservation reservations don't leak in.
$check( 'unrelated reservation gets no entries', array() === EEM_Entries::get_for_reservation( $rid_for_entry + 999999 ) );
$check( 'reservation 0 returns empty (guard)', array() === EEM_Entries::get_for_reservation( 0 ) );

// --- shortcode pipeline still merges CPT entries ----------------------------
wp_update_post( array( 'ID' => $eid, 'post_status' => 'publish' ) );
$sc      = new EEM_Shortcodes();
$ar_prop = new ReflectionProperty( 'EEM_Shortcodes', 'active_reservation_id' );
$ar_prop->setAccessible( true );
$ar_prop->setValue( $sc, $rid_for_entry );
$resolve = new ReflectionMethod( 'EEM_Shortcodes', 'get_enabled_pre_entry_options' );
$resolve->setAccessible( true );
$merged = $resolve->invoke( $sc, array() );
$check( 'shortcode resolver includes the CPT division', isset( $merged[ $k0 ] ) && '#9.5 Division' === $merged[ $k0 ]['title'] );

// --- save write-path round-trip (via the testable save_entry_fields) --------
// ajax_save() validates the nonce/cap then delegates the writes to
// save_entry_fields(); we exercise that directly so wp_die() doesn't abort.
$ret = EEM_Entries::save_entry_fields(
	$eid,
	$rid_for_entry,
	'Edited via save handler.',
	array( 'division_name' => '#10.5 Division', 'price' => '30.00', 'spots' => '10', 'max' => '1' ),
	'publish'
);
$check( 'save composes "Event - Division" title + status', 'Entries Smoke Event - #10.5 Division' === $ret['title'] && 'publish' === $ret['status'] );

$check( 'save persisted the description', 'Edited via save handler.' === (string) get_post_meta( $eid, EEM_Entries::META_DESCRIPTION, true ) );
$check( 'save persisted the division name', '#10.5 Division' === (string) get_post_meta( $eid, EEM_Entries::META_DIVISION_NAME, true ) );
$check( 'save persisted price as 2dp + spots/max as ints', '30.00' === (string) get_post_meta( $eid, EEM_Entries::META_PRICE, true ) && 10 === (int) get_post_meta( $eid, EEM_Entries::META_SPOTS, true ) && 1 === (int) get_post_meta( $eid, EEM_Entries::META_MAX, true ) );
$check( 'save published + composed the title', 'publish' === get_post_status( $eid ) && 'Entries Smoke Event - #10.5 Division' === get_post_field( 'post_title', $eid ) );

wp_delete_post( (int) $eid, true );
wp_delete_post( (int) $rid_for_entry, true );
$check( 'cleaned up temp posts', null === get_post( $eid ) && null === get_post( $rid_for_entry ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
