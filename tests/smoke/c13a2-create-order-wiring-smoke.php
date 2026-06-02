<?php
/**
 * C13.A.2 smoke — Create Order interactivity wiring.
 *
 * The customer-search AJAX returns matching customers; the localize script + JS
 * handlers (typeahead, pick, skip/change, section toggle, payment tab, custom
 * items) are present. Runtime behaviors are source-asserted here — browser
 * self-verify still required for the live typeahead/toggle interactions.
 *
 * Run: wp eval-file tests/smoke/c13a2-create-order-wiring-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond, $extra = '' ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) ); }
};

wp_set_current_user( 1 );
add_filter( 'wp_die_ajax_handler', function () { return function () { throw new Exception( 'die' ); }; } );
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }

// ── AJAX handler registered + returns results for a known seed customer ──
$check( 'AJAX action registered', has_action( 'wp_ajax_eem_create_order_customer_search' ) !== false );

$_POST = $_REQUEST = array(
	'_wpnonce' => wp_create_nonce( 'eem_create_order_customer_search' ),
	's'        => 'amelia',
);
try { ob_start(); EEM_Create_Order_Page::ajax_customer_search(); $raw = ob_get_clean(); }
catch ( Exception $e ) { $raw = ob_get_clean(); }
$resp = json_decode( (string) $raw, true );
$check( 'AJAX returns success', is_array( $resp ) && ! empty( $resp['success'] ), 'raw: ' . substr( (string) $raw, 0, 160 ) );
$results = ( is_array( $resp ) && isset( $resp['data']['results'] ) ) ? $resp['data']['results'] : array();
$check( 'AJAX matched a seeded customer (Amelia)', ! empty( $results ) && false !== stripos( wp_json_encode( $results ), 'amelia' ) );
$check( 'result rows carry name + email + orders', ! empty( $results ) && isset( $results[0]['name'], $results[0]['email'], $results[0]['orders'] ) );

// Short term → no results (guards against dumping the whole list).
$_POST['s'] = 'a';
try { ob_start(); EEM_Create_Order_Page::ajax_customer_search(); $raw2 = ob_get_clean(); } catch ( Exception $e ) { $raw2 = ob_get_clean(); }
$resp2 = json_decode( (string) $raw2, true );
$check( '1-char term returns no results', is_array( $resp2 ) && empty( $resp2['data']['results'] ) );
$_POST = array();

// ── Localize script on the page render ──
ob_start(); EEM_Create_Order_Page::render(); $html = ob_get_clean();
$check( 'page localizes window.eemCreateOrder.ajaxUrl', false !== strpos( $html, 'window.eemCreateOrder.ajaxUrl' ) );
$check( 'page localizes the search nonce', false !== strpos( $html, 'window.eemCreateOrder.searchNonce' ) );

// ── JS handlers present ──
$js = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
foreach ( array(
	'customer search dispatch' => "body.set('action', 'eem_create_order_customer_search')",
	'pick customer'            => "'create-order-pick-customer'",
	'skip customer'            => "'create-order-skip-customer'",
	'change customer'          => "'create-order-change-customer'",
	'section toggle'           => "'create-order-toggle-section'",
	'payment tab switch'       => "'create-order-payment-tab'",
	'add custom item'          => "'create-order-add-custom-item'",
	'remove custom item'       => "'create-order-remove-custom-item'",
	'autofill contact'         => 'data-eem-co-contact="first_name"',
) as $label => $needle ) {
	$check( "JS handles {$label}", false !== strpos( $js, $needle ) );
}

WP_CLI::log( "\n=== C13.A.2 Create Order wiring smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C13.A.2 Create Order wiring smoke passed.' );
