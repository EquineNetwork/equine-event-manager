<?php
/**
 * Sheets & Results manager page smoke (Slice 2 — Screen 1).
 *
 * Verifies EEM_Sheets_Results_Page: class/slug/AJAX wiring, and a render of the
 * page against a seeded event with two disciplines and a draw-sheet entry —
 * asserting representative content per region (selector, tabs + counts,
 * discipline group, add-file panel with round options, the draw-sheet row, and
 * the mirrored Results tab showing the amber "Upload Result PDF" state for an
 * entry that has no result PDF yet).
 *
 * The mutating AJAX handlers call wp_send_json_* (wp_die-exits the CLI runner)
 * and delegate to EEM_Sheet_Entries, which is fully covered by
 * sheet-entries-repo-smoke.php; here we assert their actions are registered and
 * the page JS references them.
 *
 * Run: wp eval-file tests/smoke/sheets-results-page-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }
if ( ! post_type_exists( 'en_event' ) && class_exists( 'EEM_Events' ) ) {
	( new EEM_Events() )->register_content_types();
}
EEM_Sheet_Entries::create_table();

// --- wiring ------------------------------------------------------------------
$check( 'page class loaded', class_exists( 'EEM_Sheets_Results_Page' ) );
$check( 'menu slug', EEM_Sheets_Results_Page::MENU_SLUG === 'equine-event-manager-sheets-results' );
$check( 'url() builds with event + tab', false !== strpos( EEM_Sheets_Results_Page::url( 42, 'results' ), 'event_id=42' ) && false !== strpos( EEM_Sheets_Results_Page::url( 42, 'results' ), 'tab=results' ) );
$check( 'add-discipline AJAX registered', false !== has_action( 'wp_ajax_eem_sr_add_discipline' ) );
$check( 'rename-discipline AJAX registered', false !== has_action( 'wp_ajax_eem_sr_rename_discipline' ) );
$check( 'delete-discipline AJAX registered', false !== has_action( 'wp_ajax_eem_sr_delete_discipline' ) );
$check( 'add-entry AJAX registered', false !== has_action( 'wp_ajax_eem_sr_add_entry' ) );
$check( 'set-pdf AJAX registered', false !== has_action( 'wp_ajax_eem_sr_set_pdf' ) );
$check( 'delete-entry AJAX registered', false !== has_action( 'wp_ajax_eem_sr_delete_entry' ) );

// --- seed --------------------------------------------------------------------
$suffix  = substr( md5( (string) wp_rand() ), 0, 6 );
$event   = wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'SR2 Event ' . $suffix ) );
update_post_meta( $event, '_equine_event_manager_event_start_date', '2099-06-14' );
update_post_meta( $event, '_equine_event_manager_event_end_date', '2099-06-18' );
$barrels   = wp_insert_term( 'SR2 Barrel Racing ' . $suffix, 'en_discipline' );
$breakaway = wp_insert_term( 'SR2 Breakaway ' . $suffix, 'en_discipline' );
$barrels_id   = is_array( $barrels ) ? (int) $barrels['term_id'] : 0;
$breakaway_id = is_array( $breakaway ) ? (int) $breakaway['term_id'] : 0;
wp_set_object_terms( $event, array( $barrels_id, $breakaway_id ), 'en_discipline' );
$entry = EEM_Sheet_Entries::add_entry( array(
	'event_id'      => $event,
	'discipline_id' => $barrels_id,
	'label'         => 'Open 5D Long Go ' . $suffix,
	'round'         => 'finals',
	'entry_date'    => '2099-06-14',
	'drawsheet_pdf' => 987654, // non-zero so the row renders (no real attachment needed)
) );
$check( 'seed event + disciplines + entry', $event > 0 && $barrels_id > 0 && $breakaway_id > 0 && $entry > 0 );

// --- render (Draw Sheets tab) ------------------------------------------------
$_GET = array( 'page' => EEM_Sheets_Results_Page::MENU_SLUG, 'event_id' => (string) $event, 'tab' => 'drawsheets' );
ob_start();
EEM_Sheets_Results_Page::render();
$html = ob_get_clean();

$check( 'renders the page wrapper', false !== strpos( $html, 'eem-sheets-results' ) );
$check( 'renders the event selector', false !== strpos( $html, 'eem-sr-selector' ) && false !== strpos( $html, 'data-eem-action="sr-switch-event"' ) );
$check( 'selector includes the seeded event', false !== strpos( $html, 'SR2 Event ' . $suffix ) );
$check( 'renders the lifecycle pill (Upcoming)', false !== strpos( $html, 'Upcoming' ) );
$check( 'renders the date-range pill', false !== strpos( $html, 'eem-sr-meta-pill' ) );
$check( 'renders the two doc tabs', substr_count( $html, 'data-eem-action="sr-tab"' ) === 2 );
$check( 'renders tab counts', false !== strpos( $html, 'eem-sr-tab-count' ) );
$check( 'renders the add-discipline bar', false !== strpos( $html, 'data-eem-action="sr-add-discipline"' ) );
$check( 'renders the Barrel Racing group', false !== strpos( $html, 'SR2 Barrel Racing ' . $suffix ) );
$check( 'renders the (empty) Breakaway group', false !== strpos( $html, 'SR2 Breakaway ' . $suffix ) );
$check( 'renders an Add File button per group', substr_count( $html, 'data-eem-action="sr-toggle-add"' ) >= 2 );
$check( 'renders a Rename discipline link', false !== strpos( $html, 'data-eem-action="sr-rename-discipline"' ) );
$check( 'renders a Delete discipline link', false !== strpos( $html, 'data-eem-action="sr-delete-discipline"' ) );
$check( 'renders the add-file panel with round options', false !== strpos( $html, 'eem-sr-add-panel' ) && false !== strpos( $html, 'value="finals"' ) );
$check( 'renders the draw-sheet row label', false !== strpos( $html, 'Open 5D Long Go ' . $suffix ) );
$check( 'draw-sheet row shows Live badge', false !== strpos( $html, 'eem-sr-live-badge' ) );
$check( 'draw-sheet row has replace + delete actions', false !== strpos( $html, 'data-eem-action="sr-replace-pdf"' ) && false !== strpos( $html, 'data-eem-action="sr-delete-entry"' ) );
$check( 'row meta shows the round (Finals)', false !== strpos( $html, 'Finals' ) );

// --- render (Results tab) ----------------------------------------------------
$_GET = array( 'page' => EEM_Sheets_Results_Page::MENU_SLUG, 'event_id' => (string) $event, 'tab' => 'results' );
ob_start();
EEM_Sheets_Results_Page::render();
$rhtml = ob_get_clean();
$check( 'results panel active on tab=results', false !== preg_match( '/data-sr-panel="results"[^>]*>/', $rhtml ) );
$check( 'results mirrors the entry (amber Upload Result PDF)', false !== strpos( $rhtml, 'Upload Result PDF' ) );
$check( 'results shows X of Y uploaded', false !== strpos( $rhtml, 'of' ) && false !== strpos( $rhtml, 'uploaded' ) );

// --- page JS references the AJAX actions -------------------------------------
$js = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/sheets-results.js' );
$check( 'JS dispatches eem_sr_add_entry', false !== strpos( $js, 'eem_sr_add_entry' ) );
$check( 'JS dispatches eem_sr_set_pdf', false !== strpos( $js, 'eem_sr_set_pdf' ) );
$check( 'JS dispatches eem_sr_delete_entry', false !== strpos( $js, 'eem_sr_delete_entry' ) );
$check( 'JS dispatches eem_sr_add_discipline', false !== strpos( $js, 'eem_sr_add_discipline' ) );

// --- cleanup -----------------------------------------------------------------
global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Sheet_Entries::table_name() . ' WHERE event_id = %d', $event ) ); // phpcs:ignore WordPress.DB
wp_delete_post( $event, true );
wp_delete_term( $barrels_id, 'en_discipline' );
wp_delete_term( $breakaway_id, 'en_discipline' );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
