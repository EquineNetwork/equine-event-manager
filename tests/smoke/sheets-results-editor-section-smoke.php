<?php
/**
 * Sheets & Results — Event-editor embedded section smoke (Slice 3 — Screen 2).
 *
 * Verifies the embedded section that the Event editor renders via
 * EEM_Sheets_Results_Page::render_embedded_section(): the section card on the
 * editor, the right-rail summary card with live counts, the standalone
 * "Manage / Open Sheets & Results" links, the AJAX fragment endpoint that
 * re-renders the body without a full reload, and the JS embedded branch.
 *
 * Run: wp eval-file tests/smoke/sheets-results-editor-section-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

// #55: Sheets & Results is a gated feature (default-on, but OFF in this env). The
// AJAX handlers + editor section card only wire up when it's enabled, and the
// plugin registers them at init — past by now. Enable the feature via the
// settings filter and run the registration so the behavior under test is live.
add_filter( 'option_equine_event_manager_feature_settings', static function ( $v ) {
	if ( ! is_array( $v ) ) { $v = array(); }
	$v['sheets_results_enabled'] = 1;
	return $v;
}, 99 );
if ( class_exists( 'EEM_Sheets_Results_Page' ) && ! has_action( 'wp_ajax_eem_sr_render_section' ) ) {
	EEM_Sheets_Results_Page::register();
}

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
require_once EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-skeleton.php';

// --- wiring ------------------------------------------------------------------
$check( 'render-section AJAX registered', false !== has_action( 'wp_ajax_eem_sr_render_section' ) );
$check( 'render_embedded_section is public+static', method_exists( 'EEM_Sheets_Results_Page', 'render_embedded_section' ) );

// --- seed --------------------------------------------------------------------
$suffix = substr( md5( (string) wp_rand() ), 0, 6 );
$event  = wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'draft', 'post_title' => 'SR3 Event ' . $suffix ) );
update_post_meta( $event, '_equine_event_manager_event_start_date', '2099-08-01' );
$disc   = wp_insert_term( 'SR3 Barrels ' . $suffix, 'en_discipline' );
$disc_id = is_array( $disc ) ? (int) $disc['term_id'] : 0;
wp_set_object_terms( $event, array( $disc_id ), 'en_discipline' );
$entry = EEM_Sheet_Entries::add_entry( array(
	'event_id' => $event, 'discipline_id' => $disc_id, 'label' => 'SR3 Long Go ' . $suffix,
	'round' => 'finals', 'entry_date' => '2099-08-01', 'drawsheet_pdf' => 222333,
) );
$check( 'seed event + discipline + entry', $event > 0 && $disc_id > 0 && $entry > 0 );

// --- embedded section fragment ----------------------------------------------
$frag = EEM_Sheets_Results_Page::render_embedded_section( $event );
$check( 'embedded section returns a string', is_string( $frag ) && '' !== $frag );
$check( 'embedded root carries eem-sr-embedded', false !== strpos( $frag, 'eem-sr-embedded' ) );
$check( 'embedded root carries event id', false !== strpos( $frag, 'data-event-id="' . $event . '"' ) );
$check( 'embedded has the Manage link', false !== strpos( $frag, 'Manage in Sheets &amp; Results' ) || false !== strpos( $frag, 'Manage in Sheets & Results' ) );
$check( 'embedded has the swap target .eem-sr-body', false !== strpos( $frag, 'eem-sr-body' ) );
$check( 'embedded renders the tabs', false !== strpos( $frag, 'data-eem-action="sr-tab"' ) );
$check( 'embedded renders the discipline group', false !== strpos( $frag, 'SR3 Barrels ' . $suffix ) );
$check( 'embedded renders the entry row', false !== strpos( $frag, 'SR3 Long Go ' . $suffix ) );
$check( 'embedded has NO event selector', false === strpos( $frag, 'sr-switch-event' ) );

// --- full editor render includes the section + rail --------------------------
$_GET = array( 'page' => EEM_Event_Editor_Page::MENU_SLUG, 'event_id' => (string) $event );
ob_start();
EEM_Event_Editor_Page::render();
$html = ob_get_clean();
$check( 'editor renders the Sheets & Results section card', false !== strpos( $html, 'id="card-sheets"' ) );
$check( 'editor embeds the manager body', false !== strpos( $html, 'eem-sr-embedded' ) );
$check( 'editor rail shows the summary card', false !== strpos( $html, 'data-sr-rail-drawsheets' ) && false !== strpos( $html, 'data-sr-rail-results' ) );
$check( 'rail draw-sheet count reflects seed (1)', false !== preg_match( '/data-sr-rail-drawsheets[^>]*>\s*1\s*</', $html ) );
$check( 'rail has the Open Sheets & Results link', false !== strpos( $html, 'Open Sheets' ) );

// --- fragment AJAX shape (render_body_inner via Reflection, no wp_die) --------
$ref = new ReflectionMethod( 'EEM_Sheets_Results_Page', 'render_body_inner' );
$ref->setAccessible( true );
ob_start();
$ref->invoke( null, $event, 'results' );
$body = ob_get_clean();
$check( 'fragment (results tab) renders Upload Result PDF', false !== strpos( $body, 'Upload Result PDF' ) );
$check( 'fragment has no selector (body only)', false === strpos( $body, 'sr-switch-event' ) );

// --- JS embedded branch ------------------------------------------------------
$js = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/sheets-results.js' );
$check( 'JS has the embedded fragment-swap branch', false !== strpos( $js, 'eem-sr-embedded' ) && false !== strpos( $js, 'eem_sr_render_section' ) );
$check( 'JS updates rail counts', false !== strpos( $js, 'updateRailCounts' ) );

// --- cleanup -----------------------------------------------------------------
global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Sheet_Entries::table_name() . ' WHERE event_id = %d', $event ) ); // phpcs:ignore WordPress.DB
wp_delete_post( $event, true );
wp_delete_term( $disc_id, 'en_discipline' );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
