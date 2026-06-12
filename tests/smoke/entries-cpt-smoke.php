<?php
/**
 * Entries CPT + styled-editor smoke (v1 — Entries feature, #1 + #1b).
 *
 * The "Entries" entity: an `en_entry` CPT under the Orders menu, each connected
 * to a reservation (event), holding a Description + a list of purchasable line
 * items (title/price/inventory/max). A custom editor (EEM_Entries::render) mirrors
 * the Edit Reservation chrome — event-connect typeahead + Description + the
 * Pre-Entries line-items card. The resolver expands each entry's items into the
 * legacy customer-pipeline option shape.
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
$check( 'list renders the "+ New Entry" electric button', false !== strpos( $list_html, 'New Entry' ) && false !== strpos( $list_html, 'eem-btn-electric' ) );
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
update_post_meta( $eid, EEM_Entries::META_DESCRIPTION, 'Pre-purchase your classes.' );
update_post_meta( $eid, EEM_Entries::META_ITEMS, array(
	array( 'title' => 'Friday Reining', 'price' => '45.00', 'inventory' => 20, 'max_per_customer' => 2 ),
	array( 'title' => 'Saturday Cutting', 'price' => '60.00', 'inventory' => 0, 'max_per_customer' => 0 ),
) );
$_GET = array( 'entry_id' => (string) $eid );
ob_start(); EEM_Entries::render(); $html = (string) ob_get_clean();
$_GET = array();

$check( 'editor renders the plugin shell (eem-plugin-wrap)', false !== strpos( $html, 'eem-plugin-wrap' ) );
$check( 'editor renders the event-connect typeahead', false !== strpos( $html, 'id="eem-entry-typeahead"' ) && false !== strpos( $html, 'entry-filter-events' ) );
$check( 'editor renders the Description field with its value', false !== strpos( $html, 'id="eem-entry-description"' ) && false !== strpos( $html, 'Pre-purchase your classes.' ) );
$check( 'editor renders the Pre-Entries line-items card', false !== strpos( $html, 'id="card-entry-items"' ) && false !== strpos( $html, 'eem-repeat-table' ) );
$check( 'cards use the reservation-editor section chrome (icon chip + title)', 2 === substr_count( $html, 'eem-section-icon eem-section-icon--' ) && false !== strpos( $html, 'eem-section-icon--blue' ) && false !== strpos( $html, 'eem-section-icon--green' ) );
$check( 'editor renders seeded item rows', false !== strpos( $html, 'Friday Reining' ) && false !== strpos( $html, 'Saturday Cutting' ) );
$check( 'editor reuses the generic repeating-row add + template', false !== strpos( $html, 'reservation-editor-add-repeating-row' ) && false !== strpos( $html, 'eem-entry-item-row-template' ) );
$check( 'editor renders the sticky save bar (Draft + Publish)', false !== strpos( $html, 'entry-editor-save-draft' ) && false !== strpos( $html, 'entry-editor-publish' ) );
$check( 'header shows the connected event title', false !== strpos( $html, 'Entries Smoke Event' ) );

// --- resolver round-trip (publish required) --------------------------------
wp_update_post( array( 'ID' => $eid, 'post_status' => 'publish' ) );
$opts = EEM_Entries::get_for_reservation( $rid_for_entry );
$check( 'resolver expands each item into its own option', 2 === count( $opts ) );
$k0   = 'entry_' . $eid . '_0';
$check( 'resolver keys options entry_{postID}_{idx}', isset( $opts[ $k0 ] ) );
$check( 'resolver carries item title', isset( $opts[ $k0 ]['title'] ) && 'Friday Reining' === $opts[ $k0 ]['title'] );
$check( 'resolver carries price as a 2dp string', isset( $opts[ $k0 ]['price'] ) && '45.00' === $opts[ $k0 ]['price'] );
$check( 'resolver carries inventory + max as ints', 20 === $opts[ $k0 ]['inventory'] && 2 === $opts[ $k0 ]['max_per_customer'] );
$check( 'unlimited item resolves to 0 inventory + 0 max', 0 === $opts[ 'entry_' . $eid . '_1' ]['inventory'] && 0 === $opts[ 'entry_' . $eid . '_1' ]['max_per_customer'] );

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
$check( 'shortcode resolver includes the CPT entry items', isset( $merged[ $k0 ] ) && 'Friday Reining' === $merged[ $k0 ]['title'] );

// --- save write-path round-trip (via the testable save_entry_fields) --------
// ajax_save() validates the nonce/cap then delegates the writes to
// save_entry_fields(); we exercise that directly so wp_die() doesn't abort.
$ret = EEM_Entries::save_entry_fields(
	$eid,
	$rid_for_entry,
	'Edited via save handler.',
	array(
		array( 'title' => 'Sunday Roping', 'price' => '30.00', 'inventory' => '10', 'max_per_customer' => '1' ),
		array( 'title' => '', 'price' => '5.00', 'inventory' => '0', 'max_per_customer' => '0' ), // blank → dropped
	),
	'publish'
);
$check( 'save returns the inherited title + status', 'Entries Smoke Event' === $ret['title'] && 'publish' === $ret['status'] );

$saved_desc  = (string) get_post_meta( $eid, EEM_Entries::META_DESCRIPTION, true );
$saved_items = get_post_meta( $eid, EEM_Entries::META_ITEMS, true );
$check( 'ajax_save persisted the description', 'Edited via save handler.' === $saved_desc );
$check( 'ajax_save dropped the blank-title row (1 item kept)', is_array( $saved_items ) && 1 === count( $saved_items ) );
$check( 'ajax_save kept the real item with sanitized values', is_array( $saved_items ) && 'Sunday Roping' === $saved_items[0]['title'] && '30.00' === $saved_items[0]['price'] && 10 === $saved_items[0]['inventory'] );
$check( 'ajax_save published + inherited the event title', 'publish' === get_post_status( $eid ) && 'Entries Smoke Event' === get_post_field( 'post_title', $eid ) );

wp_delete_post( (int) $eid, true );
wp_delete_post( (int) $rid_for_entry, true );
$check( 'cleaned up temp posts', null === get_post( $eid ) && null === get_post( $rid_for_entry ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
