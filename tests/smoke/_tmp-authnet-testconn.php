<?php
/**
 * Throwaway: exercise ajax_test_authorize_net_connection() against the SAVED
 * live creds (blank POST creds → falls back to saved). Captures the wp_send_json
 * output instead of letting wp_die() kill the run. Self-deletes. No charge.
 */
wp_set_current_user( 1 );
$_POST = array(
	'_wpnonce'        => wp_create_nonce( 'eem_test_authorize_net' ),
	'mode'            => 'live',
	'api_login'       => '',
	'transaction_key' => '',
);
$_REQUEST = $_POST;

add_filter( 'wp_die_ajax_handler', function () {
	return function () { echo "\n[wp_die reached]\n"; throw new Exception( 'wp_die' ); };
} );

$sc = new EEM_Shortcodes();
ob_start();
try {
	$sc->ajax_test_authorize_net_connection();
} catch ( Exception $e ) {
	// expected — wp_send_json_* calls wp_die()
}
$out = ob_get_clean();
echo "captured response:\n" . $out . "\n";
@unlink( __FILE__ );
