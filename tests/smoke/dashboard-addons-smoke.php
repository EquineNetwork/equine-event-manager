<?php
/**
 * Dashboard "Add-Ons" card smoke — Entries + Sheets & Results activity, gated on
 * the per-site feature flags.
 *
 * Covers EEM_Dashboard_Repo::addons_summary() (counts + flag gating) and the
 * EEM_Dashboard_Page Add-Ons card render (rows only for enabled add-ons; both
 * off → nothing rendered).
 *
 * Run: wp eval-file tests/smoke/dashboard-addons-smoke.php
 */

if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }

$pass = 0; $fail = 0;
$ok = static function ( $label, $cond ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  - {$label}\n"; }
	else { $fail++; echo "FAIL  - {$label}\n"; }
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }
if ( ! post_type_exists( 'en_event' ) && class_exists( 'EEM_Events' ) ) {
	( new EEM_Events() )->register_content_types();
}
EEM_Sheet_Entries::create_table();
EEM_Division_Entries::create_table();

$flags_option = EEM_Events::FEATURES_SETTINGS_OPTION;
$saved_flags  = get_option( $flags_option );

$suffix = substr( md5( (string) wp_rand() ), 0, 6 );

// --- seed entries (one division + a ledger entry of qty 3) -------------------
$division = wp_insert_post( array( 'post_type' => EEM_Entries::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'DA Division ' . $suffix ) );
global $wpdb;
$wpdb->insert( EEM_Division_Entries::table_name(), array(
	'division_id'   => $division,
	'order_key'     => 'da-' . $suffix,
	'customer_name' => 'DA Entrant',
	'email'         => 'da@example.com',
	'qty'           => 3,
	'status'        => 'paid',
) );

// --- seed sheets (one event: 2 draw sheets, 1 result, so 1 awaiting) ---------
$event = wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'DA Event ' . $suffix ) );
$disc  = wp_insert_term( 'DA Discipline ' . $suffix, 'en_discipline' );
$did   = is_array( $disc ) ? (int) $disc['term_id'] : 0;
$e1    = EEM_Sheet_Entries::add_entry( array( 'event_id' => $event, 'discipline_id' => $did, 'label' => 'DA1', 'drawsheet_pdf' => 11 ) );
$e2    = EEM_Sheet_Entries::add_entry( array( 'event_id' => $event, 'discipline_id' => $did, 'label' => 'DA2', 'drawsheet_pdf' => 12 ) );
EEM_Sheet_Entries::set_pdf( $e1, 'result', 99 ); // 1 result; e2 awaits

// --- both ON: summary counts -------------------------------------------------
update_option( $flags_option, array( 'entries_enabled' => 1, 'sheets_results_enabled' => 1 ) );
$sum = EEM_Dashboard_Repo::addons_summary();
$ok( 'entries enabled flag true', true === $sum['entries']['enabled'] );
$ok( 'entries counts the seeded division', $sum['entries']['divisions'] >= 1 );
$ok( 'entries counts the entrant qty', $sum['entries']['entrants'] >= 3 );
$ok( 'sheets enabled flag true', true === $sum['sheets']['enabled'] );
$ok( 'sheets counts draw sheets (>=2)', $sum['sheets']['drawsheets'] >= 2 );
$ok( 'sheets counts results (>=1)', $sum['sheets']['results'] >= 1 );
$ok( 'sheets counts awaiting (>=1)', $sum['sheets']['awaiting'] >= 1 );

// --- render the card (both on) ----------------------------------------------
$render = new ReflectionMethod( 'EEM_Dashboard_Page', 'render_addons_card' );
$render->setAccessible( true );
ob_start();
$render->invoke( null, $sum );
$html = ob_get_clean();
$ok( 'card renders the Add-Ons header', false !== strpos( $html, 'Add-Ons' ) );
$ok( 'card renders the Entries row', false !== strpos( $html, 'Entries' ) && false !== strpos( $html, 'entered' ) );
$ok( 'card renders the Sheets row', false !== strpos( $html, 'Sheets &amp; Results' ) && false !== strpos( $html, 'draw sheets' ) );
$ok( 'card renders the awaiting-results alert', false !== strpos( $html, 'awaiting results' ) );

// --- both OFF: card renders nothing -----------------------------------------
update_option( $flags_option, array( 'entries_enabled' => 0, 'sheets_results_enabled' => 0 ) );
$sum_off = EEM_Dashboard_Repo::addons_summary();
$ok( 'both flags off in summary', false === $sum_off['entries']['enabled'] && false === $sum_off['sheets']['enabled'] );
ob_start();
$render->invoke( null, $sum_off );
$html_off = ob_get_clean();
$ok( 'card renders NOTHING when both off', '' === trim( $html_off ) );

// --- only sheets ON ---------------------------------------------------------
update_option( $flags_option, array( 'entries_enabled' => 0, 'sheets_results_enabled' => 1 ) );
$sum_s = EEM_Dashboard_Repo::addons_summary();
ob_start();
$render->invoke( null, $sum_s );
$html_s = ob_get_clean();
$ok( 'sheets-only: renders Sheets row', false !== strpos( $html_s, 'draw sheets' ) );
$ok( 'sheets-only: omits Entries row', false === strpos( $html_s, 'entered' ) );

// --- cleanup -----------------------------------------------------------------
if ( false === $saved_flags ) { delete_option( $flags_option ); } else { update_option( $flags_option, $saved_flags ); }
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Division_Entries::table_name() . ' WHERE division_id = %d', $division ) ); // phpcs:ignore WordPress.DB
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Sheet_Entries::table_name() . ' WHERE event_id = %d', $event ) ); // phpcs:ignore WordPress.DB
wp_delete_post( (int) $division, true );
wp_delete_post( (int) $event, true );
if ( $did ) { wp_delete_term( $did, 'en_discipline' ); }

echo "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
if ( $fail > 0 ) { exit( 1 ); }
