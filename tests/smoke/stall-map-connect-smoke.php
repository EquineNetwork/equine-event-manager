<?php
/**
 * v4 Slice 2 — stall-map connect AJAX handler (backend wiring).
 *
 * Source-presence + registration smoke (the handler calls wp_send_json* which
 * exits, so it is not invoked directly here). The full request→save→preview
 * flow gets browser-verified when the editor connection UI lands.
 */

$pass = 0; $fail = 0; $log = array();
function cn_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

cn_ok( 'handler method exists', method_exists( 'EEM_Reservation_Editor_Page', 'ajax_stall_map_connect' ), $pass, $fail, $log );
cn_ok( 'ajax action registered', has_action( 'wp_ajax_eem_stall_map_connect' ) !== false, $pass, $fail, $log );

$src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
cn_ok( 'handler checks capability', (bool) preg_match( '/ajax_stall_map_connect.*?current_user_can\(\s*\'manage_options\'/s', $src ), $pass, $fail, $log );
cn_ok( 'handler verifies nonce', (bool) preg_match( "/ajax_stall_map_connect.*?check_ajax_referer\(\s*'eem_reservation_editor'/s", $src ), $pass, $fail, $log );
cn_ok( 'handler calls importer import()', false !== strpos( $src, 'EEM_Stall_Map_Importer::import(' ), $pass, $fail, $log );
cn_ok( 'handler rejects cross-barn dupes', false !== strpos( $src, 'EEM_Stall_Map_Importer::find_duplicate_labels(' ), $pass, $fail, $log );
cn_ok( 'handler snapshots to reservation', false !== strpos( $src, 'EEM_Stall_Map_Importer::save_to_reservation(' ), $pass, $fail, $log );
cn_ok( 'handler returns barns + total_stalls', false !== strpos( $src, "'total_stalls'" ) && false !== strpos( $src, "'barns'" ), $pass, $fail, $log );

$loader = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );
cn_ok( 'loader registers the action', false !== strpos( $loader, "wp_ajax_eem_stall_map_connect" ), $pass, $fail, $log );

echo "\n=== Stall Map connect (Slice 2 backend) smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
