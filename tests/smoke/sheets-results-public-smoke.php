<?php
/**
 * Sheets & Results — public surfaces smoke (Slices 4 + 5).
 *
 * Slice 4: the conditional Draw Sheets / Results buttons on the event-list row
 * (shown only when the matching document type has an uploaded PDF) + the public
 * URL helper. Slice 5: the public per-event page render (hero + tabs +
 * discipline groups + day labels + PDF rows + "Coming soon" pill), the
 * `[eem_sheets_results]` shortcode, the query var, and the route handler.
 *
 * Run: wp eval-file tests/smoke/sheets-results-public-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }
$events = new EEM_Events();
if ( ! post_type_exists( 'en_event' ) ) { $events->register_content_types(); }
EEM_Sheet_Entries::create_table();

// --- wiring ------------------------------------------------------------------
$check( 'eem_sheets_results shortcode registered', shortcode_exists( 'eem_sheets_results' ) );
$check( 'eem_sheets query var registered', in_array( 'eem_sheets', $events->filter_query_vars( array() ), true ) );
$check( 'sheets request-filter exists', method_exists( 'EEM_Events', 'filter_sheets_request' ) );
$check( 'sheets styles enqueue hook exists', method_exists( 'EEM_Events', 'maybe_enqueue_sheets_styles' ) );
$check( 'render_public_page exists', method_exists( 'EEM_Sheets_Results_Page', 'render_public_page' ) );

// --- URL helper (Slice 4/5) --------------------------------------------------
$suffix = substr( md5( (string) wp_rand() ), 0, 6 );
$venue  = wp_insert_post( array( 'post_type' => 'en_venue', 'post_status' => 'publish', 'post_title' => 'SR45 Arena ' . $suffix ) );
$event  = wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'SR45 Event ' . $suffix ) );
update_post_meta( $event, '_equine_event_manager_event_start_date', '2099-06-14' );
update_post_meta( $event, '_equine_event_manager_event_end_date', '2099-06-16' );
update_post_meta( $event, '_equine_event_manager_event_venue_id', $venue );

$draw_url = EEM_Events::get_event_sheets_results_url( $event, 'drawsheets' );
$res_url  = EEM_Events::get_event_sheets_results_url( $event, 'results' );
$check( 'URL helper builds /sheets-and-results/', false !== strpos( $draw_url, '/sheets-and-results/' ) );
$check( 'URL helper results variant carries tab=results', false !== strpos( $res_url, 'tab=results' ) );

$barrels   = wp_insert_term( 'SR45 Barrels ' . $suffix, 'en_discipline' );
$breakaway = wp_insert_term( 'SR45 Breakaway ' . $suffix, 'en_discipline' );
$barrels_id   = is_array( $barrels ) ? (int) $barrels['term_id'] : 0;
$breakaway_id = is_array( $breakaway ) ? (int) $breakaway['term_id'] : 0;
wp_set_object_terms( $event, array( $barrels_id, $breakaway_id ), 'en_discipline' );
// Entry 1: draw sheet + result (Live on both tabs).
$e1 = EEM_Sheet_Entries::add_entry( array( 'event_id' => $event, 'discipline_id' => $barrels_id, 'label' => 'SR45 Open 5D ' . $suffix, 'round' => 'finals', 'entry_date' => '2099-06-14', 'drawsheet_pdf' => 111, 'result_pdf' => 222 ) );
// Entry 2: draw sheet only (Results tab → "Coming soon").
$e2 = EEM_Sheet_Entries::add_entry( array( 'event_id' => $event, 'discipline_id' => $barrels_id, 'label' => 'SR45 19U ' . $suffix, 'round' => '1st-go', 'entry_date' => '2099-06-15', 'drawsheet_pdf' => 333 ) );
$check( 'seed event/venue/disciplines/entries', $event > 0 && $barrels_id > 0 && $e1 > 0 && $e2 > 0 );
$check( 'has_drawsheets true', EEM_Sheet_Entries::has_drawsheets( $event ) );
$check( 'has_results true', EEM_Sheet_Entries::has_results( $event ) );

// Request-filter path fallback routes the URL to a real singular en_event query.
$slug_for_route          = get_post_field( 'post_name', $event );
$_SERVER['REQUEST_URI']  = '/events/' . $slug_for_route . '/sheets-and-results/';
$routed                  = $events->filter_sheets_request( array() );
$check( 'request filter routes to singular en_event + flag', isset( $routed['post_type'], $routed['name'], $routed['eem_sheets'] ) && 'en_event' === $routed['post_type'] && $slug_for_route === $routed['name'] && '1' === $routed['eem_sheets'] );
$_SERVER['REQUEST_URI']  = '/some/other/page/';
$check( 'request filter ignores non-sheets URLs', array() === $events->filter_sheets_request( array() ) );

// --- Slice 4: conditional buttons on the event-list row ----------------------
$row = new ReflectionMethod( 'EEM_Events', 'render_event_list_row_markup' );
$row->setAccessible( true );
$with = (string) $row->invoke( $events, array( 'event_id' => $event, 'title' => 'SR45 Event ' . $suffix, 'start_date' => '2099-06-14', 'end_date' => '2099-06-16' ) );
$check( 'row renders the actions block', false !== strpos( $with, 'eem-event-list-row__actions' ) );
$check( 'row renders the Draw Sheets button', false !== strpos( $with, 'eem-event-list-row__btn--draw' ) );
$check( 'row renders the Results button', false !== strpos( $with, 'eem-event-list-row__btn--results' ) );
// The buttons themselves carry no icon (no-icons-on-buttons rule + kses strips
// inline SVG in list markup). Isolate the actions block and assert it's svg-free
// (the row's media area legitimately has SVGs).
preg_match( '#eem-event-list-row__actions">(.*?)</div>#s', $with, $am );
$check( 'row buttons have NO inline svg (kses-safe + no-icons rule)', isset( $am[1] ) && false === strpos( $am[1], '<svg' ) );

// Event with no sheets → no buttons.
$bare = wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'SR45 Bare ' . $suffix ) );
$without = (string) $row->invoke( $events, array( 'event_id' => $bare, 'title' => 'SR45 Bare ' . $suffix, 'start_date' => '', 'end_date' => '' ) );
$check( 'event with no sheets renders no actions block', false === strpos( $without, 'eem-event-list-row__actions' ) );

// --- Slice 5: public page render --------------------------------------------
$html = EEM_Sheets_Results_Page::render_public_page( $event );
$check( 'public page renders the header', false !== strpos( $html, 'eem-sr-public-head' ) );
$check( 'public page has NO heavy navy hero', false === strpos( $html, 'eem-sr-public-hero' ) );
$check( 'public page shows the event title', false !== strpos( $html, 'SR45 Event ' . $suffix ) );
$check( 'public page shows the venue', false !== strpos( $html, 'SR45 Arena ' . $suffix ) );
$check( 'public page has both tabs', substr_count( $html, 'data-eem-pub-tab="' ) === 2 );
$check( 'public page draw panel shows the discipline', false !== strpos( $html, 'SR45 Barrels ' . $suffix ) );
$check( 'public page renders a day label', false !== strpos( $html, 'eem-sr-pub-day' ) );
$check( 'public page renders a PDF row', false !== strpos( $html, 'eem-sr-pub-item' ) && false !== strpos( $html, 'SR45 Open 5D ' . $suffix ) );
$check( 'public page Results shows Coming soon for pending', false !== strpos( $html, 'Coming soon' ) );
$check( 'public page empty discipline shows empty state', false !== strpos( $html, 'SR45 Breakaway ' . $suffix ) && false !== strpos( $html, 'eem-sr-pub-empty' ) );

// --- shortcode renders the same page ----------------------------------------
$sc = do_shortcode( '[eem_sheets_results event_id="' . $event . '"]' );
$check( 'shortcode renders the public page', false !== strpos( $sc, 'eem-sr-public' ) && false !== strpos( $sc, 'SR45 Event ' . $suffix ) );

// --- cleanup -----------------------------------------------------------------
global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Sheet_Entries::table_name() . ' WHERE event_id = %d', $event ) ); // phpcs:ignore WordPress.DB
wp_delete_post( $event, true );
wp_delete_post( $bare, true );
wp_delete_post( $venue, true );
wp_delete_term( $barrels_id, 'en_discipline' );
wp_delete_term( $breakaway_id, 'en_discipline' );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
