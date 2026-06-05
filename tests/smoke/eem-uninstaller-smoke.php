<?php
/**
 * EEM_Uninstaller safety + selection guard (NON-destructive).
 *
 * The uninstaller wipes ALL plugin data, so this smoke must NOT call
 * purge_all_data() — it would erase the dev site. Instead it verifies the
 * selection logic that decides WHAT gets deleted:
 *   - plugin_option_names() matches plugin options (namespaces + explicit)…
 *   - …and CRITICALLY never matches WordPress core options (admin_email,
 *     date_format, siteurl, blogname, home) — the safety invariant.
 *   - count_data() reports sane, reflective counts.
 *   - The table + CPT constants are the expected sets.
 *   - The uninstall opt-in defaults to OFF (deleting the plugin keeps data
 *     unless the site explicitly opted in).
 */

require_once EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-uninstaller.php';

$pass = 0; $fail = 0; $log = array();
function uok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

// Seed two throwaway plugin-namespaced options (cleaned up at the end).
add_option( 'eem_zzz_smoke_marker', 'x' );
add_option( 'equine_event_manager_zzz_smoke', 'y' );
set_transient( 'eem_zzz_smoke_transient', 'z', 300 );

$names = EEM_Uninstaller::plugin_option_names();

// --- Selection: plugin options ARE matched ---
uok( 'matches eem_ namespaced option',                 in_array( 'eem_zzz_smoke_marker', $names, true ),                $pass, $fail, $log );
uok( 'matches equine_event_manager_ namespaced option', in_array( 'equine_event_manager_zzz_smoke', $names, true ),     $pass, $fail, $log );
uok( 'matches plugin transient',                        in_array( '_transient_eem_zzz_smoke_transient', $names, true ),  $pass, $fail, $log );

// --- SAFETY: WordPress core options are NEVER matched ---
foreach ( array( 'admin_email', 'date_format', 'siteurl', 'blogname', 'home', 'template', 'users_can_register' ) as $core_opt ) {
	uok( "SAFETY: core option '$core_opt' is NOT matched", ! in_array( $core_opt, $names, true ), $pass, $fail, $log );
}

// --- count_data(): shape + sanity ---
$counts = EEM_Uninstaller::count_data();
$expected_keys = array( 'reservations', 'events', 'venues', 'producers', 'orders', 'activity_log', 'options' );
$has_all_keys = true;
foreach ( $expected_keys as $k ) {
	if ( ! array_key_exists( $k, $counts ) || ! is_int( $counts[ $k ] ) ) { $has_all_keys = false; }
}
uok( 'count_data returns all expected integer keys', $has_all_keys, $pass, $fail, $log );
uok( 'count_data options count includes our 2 markers + transient (>=3)', $counts['options'] >= 3, $pass, $fail, $log );

// count_data reflects a freshly-created reservation, then is back to baseline.
$baseline = $counts['reservations'];
$tmp_res  = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Uninstaller Smoke Res' ) );
$after    = EEM_Uninstaller::count_data();
uok( 'count_data reservations increments with a new reservation', $after['reservations'] === $baseline + 1, $pass, $fail, $log );
wp_delete_post( (int) $tmp_res, true );

// --- Constants are the expected canonical sets ---
uok( 'TABLES is the 6 known custom tables',
	count( EEM_Uninstaller::TABLES ) === 6
	&& in_array( 'en_stall_reservations', EEM_Uninstaller::TABLES, true )
	&& in_array( 'eem_event_defaults', EEM_Uninstaller::TABLES, true )
	&& in_array( 'en_order_adjustments', EEM_Uninstaller::TABLES, true ),
	$pass, $fail, $log );
uok( 'POST_TYPES is the 4 plugin CPTs (no TEC types)',
	EEM_Uninstaller::POST_TYPES === array( 'en_reservation', 'en_event', 'en_venue', 'en_producer' ),
	$pass, $fail, $log );

// --- Uninstall opt-in defaults OFF (delete-plugin keeps data unless opted in) ---
uok( 'delete-on-uninstall opt-in defaults to falsy', ! get_option( 'equine_event_manager_delete_data_on_uninstall' ), $pass, $fail, $log );

// Cleanup throwaways.
delete_option( 'eem_zzz_smoke_marker' );
delete_option( 'equine_event_manager_zzz_smoke' );
delete_transient( 'eem_zzz_smoke_transient' );

echo "\n=== EEM_Uninstaller safety smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
