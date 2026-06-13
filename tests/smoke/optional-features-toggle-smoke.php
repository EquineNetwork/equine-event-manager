<?php
/**
 * Optional-feature toggles smoke (Entries + Sheets & Results).
 *
 * Verifies the Settings → Add-Ons on/off switches: the flag helpers + their
 * default semantics, the Add-Ons save handler, the existing-on/new-off
 * migration (eem-mig-014), and the actual gates — when a feature is OFF its
 * public surfaces disappear (Sheets routing + shortcode + event-card buttons;
 * the en_entry merge on the customer checkout).
 *
 * Run: wp eval-file tests/smoke/optional-features-toggle-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$OPT = EEM_Events::FEATURES_SETTINGS_OPTION;
$before = get_option( $OPT, array() );

$set = static function ( $entries, $sheets ) use ( $OPT ) {
	$f = get_option( $OPT, array() );
	$f = is_array( $f ) ? $f : array();
	if ( null === $entries ) { unset( $f['entries_enabled'] ); } else { $f['entries_enabled'] = $entries; }
	if ( null === $sheets ) { unset( $f['sheets_results_enabled'] ); } else { $f['sheets_results_enabled'] = $sheets; }
	update_option( $OPT, $f );
};

// --- 1. Flag helpers + default-on semantics ---------------------------------
$set( null, null );
$check( 'entries defaults ON when unset', true === EEM_Events::is_entries_enabled() );
$check( 'sheets defaults ON when unset', true === EEM_Events::is_sheets_results_enabled() );
$set( 0, 0 );
$check( 'entries reads OFF', false === EEM_Events::is_entries_enabled() );
$check( 'sheets reads OFF', false === EEM_Events::is_sheets_results_enabled() );
$set( 1, 1 );
$check( 'entries reads ON', true === EEM_Events::is_entries_enabled() );
$check( 'sheets reads ON', true === EEM_Events::is_sheets_results_enabled() );

// --- 2. Add-Ons save handler -------------------------------------------------
$sp  = new EEM_Settings_Page();
$ref = new ReflectionMethod( 'EEM_Settings_Page', 'save_addons_panel' );
$ref->setAccessible( true );
$ref->invoke( $sp, array( 'entries_enabled' => '1' ) ); // sheets box unchecked → not posted
$after = get_option( $OPT, array() );
$check( 'save: checked entries → 1', 1 === (int) $after['entries_enabled'] );
$check( 'save: unchecked sheets → 0', 0 === (int) $after['sheets_results_enabled'] );

// --- 3. Migration eem-mig-014 (existing→1, new→0) ---------------------------
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-014-optional-feature-defaults.php';
$dbv_before = get_option( EEM_Activator::DB_VERSION_OPTION, '' );
// Existing install: a DB version is present.
update_option( EEM_Activator::DB_VERSION_OPTION, '2.7.000' );
$set( null, null );
eem_mig_014_optional_feature_defaults();
$mexist = get_option( $OPT, array() );
$check( 'migration: existing install → both ON', 1 === (int) $mexist['entries_enabled'] && 1 === (int) $mexist['sheets_results_enabled'] );
// New install: no DB version yet.
delete_option( EEM_Activator::DB_VERSION_OPTION );
$set( null, null );
eem_mig_014_optional_feature_defaults();
$mnew = get_option( $OPT, array() );
$check( 'migration: new install → both OFF', 0 === (int) $mnew['entries_enabled'] && 0 === (int) $mnew['sheets_results_enabled'] );
// restore db version + clear the mig-complete flag set by the calls above.
if ( '' !== (string) $dbv_before ) { update_option( EEM_Activator::DB_VERSION_OPTION, $dbv_before ); }
delete_option( 'eem_mig_014_optional_feature_defaults_complete' );

// --- seed an event with sheets + a reservation with a division --------------
$suffix = substr( md5( (string) wp_rand() ), 0, 6 );
if ( ! post_type_exists( 'en_event' ) ) { ( new EEM_Events() )->register_content_types(); }
EEM_Sheet_Entries::create_table();
$event = wp_insert_post( array( 'post_type' => 'en_event', 'post_status' => 'publish', 'post_title' => 'OFT Event ' . $suffix ) );
$disc  = wp_insert_term( 'OFT Barrels ' . $suffix, 'en_discipline' );
$disc_id = is_array( $disc ) ? (int) $disc['term_id'] : 0;
wp_set_object_terms( $event, array( $disc_id ), 'en_discipline' );
EEM_Sheet_Entries::add_entry( array( 'event_id' => $event, 'discipline_id' => $disc_id, 'label' => 'OFT Draw', 'round' => '1st-go', 'entry_date' => '2099-01-01', 'drawsheet_pdf' => 555, 'result_pdf' => 556 ) );

$reservation = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'OFT Res ' . $suffix ) );
$division    = wp_insert_post( array( 'post_type' => 'en_entry', 'post_status' => 'publish', 'post_title' => 'OFT Division ' . $suffix ) );
update_post_meta( $division, '_en_entry_reservation_id', $reservation );
update_post_meta( $division, '_en_division_name', 'OFT Open' );
update_post_meta( $division, '_en_division_price', '40.00' );

$events = new EEM_Events();
$row    = new ReflectionMethod( 'EEM_Events', 'render_event_list_row_markup' );
$row->setAccessible( true );

// --- 4. Sheets & Results gates (OFF) ----------------------------------------
$set( 1, 0 ); // entries on, sheets off
$_SERVER['REQUEST_URI'] = '/events/' . get_post_field( 'post_name', $event ) . '/sheets-and-results/';
$check( 'sheets OFF: request filter does NOT route', array() === $events->filter_sheets_request( array() ) );
$check( 'sheets OFF: shortcode returns empty', '' === do_shortcode( '[eem_sheets_results event_id="' . $event . '"]' ) );
$rmarkup_off = (string) $row->invoke( $events, array( 'event_id' => $event, 'title' => 'OFT Event ' . $suffix, 'start_date' => '', 'end_date' => '' ) );
$check( 'sheets OFF: no event-card buttons', false === strpos( $rmarkup_off, 'eem-event-list-row__actions' ) );

// --- 5. Sheets & Results gates (ON) -----------------------------------------
$set( 1, 1 );
$routed = $events->filter_sheets_request( array() );
$check( 'sheets ON: request filter routes', isset( $routed['eem_sheets'] ) && '1' === $routed['eem_sheets'] );
$check( 'sheets ON: shortcode renders', false !== strpos( do_shortcode( '[eem_sheets_results event_id="' . $event . '"]' ), 'eem-sr-public' ) );
$rmarkup_on = (string) $row->invoke( $events, array( 'event_id' => $event, 'title' => 'OFT Event ' . $suffix, 'start_date' => '', 'end_date' => '' ) );
$check( 'sheets ON: event-card buttons present', false !== strpos( $rmarkup_on, 'eem-event-list-row__actions' ) );

// --- 6. Entries customer-merge gate -----------------------------------------
$sc      = new EEM_Shortcodes();
$pidProp = new ReflectionProperty( 'EEM_Shortcodes', 'active_reservation_id' );
$pidProp->setAccessible( true );
$pidProp->setValue( $sc, $reservation );
$optMethod = new ReflectionMethod( 'EEM_Shortcodes', 'get_enabled_pre_entry_options' );
$optMethod->setAccessible( true );
$set( 1, 1 );
$opts_on  = $optMethod->invoke( $sc, array() );
$check( 'entries ON: division merged into checkout options', array_key_exists( 'entry_' . $division, $opts_on ) );
$set( 0, 1 );
$opts_off = $optMethod->invoke( $sc, array() );
$check( 'entries OFF: division NOT in checkout options', ! array_key_exists( 'entry_' . $division, $opts_off ) );

// --- cleanup -----------------------------------------------------------------
global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Sheet_Entries::table_name() . ' WHERE event_id = %d', $event ) ); // phpcs:ignore WordPress.DB
wp_delete_post( $event, true );
wp_delete_post( $reservation, true );
wp_delete_post( $division, true );
wp_delete_term( $disc_id, 'en_discipline' );
update_option( $OPT, is_array( $before ) ? $before : array() );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
