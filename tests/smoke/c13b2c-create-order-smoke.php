<?php
/**
 * C13.B.2.c — Admin order creation via embedded-form field collection.
 *
 * What landed:
 *   1. render_embedded_sections() now uses admin_invoice="1" — sets hidden
 *      en_invoice_type='manual' + en_invoice_action_mode='send_payment_link'
 *      control fields so no charge is dispatched.
 *   2. ajax_create_order() AJAX handler — hooks eem_order_created, calls
 *      do_shortcode('[en_reservation id=N admin_invoice="1"]') which internally
 *      runs handle_reservation_submission() when $_POST has the form data,
 *      returns order_key + redirect URL.
 *   3. wp_ajax_eem_admin_create_order hook registered in admin class.
 *   4. window.eemCreateOrder.createOrderNonce + ordersUrl localized.
 *   5. coSubmitOrder() in admin.js — FormData collect, contact override,
 *      fetch to eem_admin_create_order, redirect on success.
 *   6. DOMContentLoaded enables Send Payment Link button when embed present.
 *
 * Source-presence smoke only. Mandatory browser self-verify:
 *   Fill contact + add 1 stall, click Send Payment Link → redirected to Order Detail.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c13b2c_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C13.B.2.c — ADMIN ORDER CREATION SMOKE ===\n";

$co_page    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-create-order-page.php' );
$admin_main = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$js_src     = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );

$strip = function( $s ) {
	$s = preg_replace( '~/\*.*?\*/~s', '', $s );
	$s = preg_replace( '~//[^\n]*~', '', $s );
	return $s;
};
$co_clean    = $strip( $co_page );
$admin_clean = $strip( $admin_main );
$js_clean    = $strip( $js_src );


// ── [1] Version bump ─────────────────────────────────────────────────────────
echo "\n[1] Version bump to 2.7.5\n";
c13b2c_ok(
	'EQUINE_EVENT_MANAGER_VERSION >= 2.7.5',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.7.5', '>=' ),
	$pass, $fail, $log
);


// ── [2] PHP — render_embedded_sections() uses admin_invoice="1" ──────────────
echo "\n[2] render_embedded_sections() — admin_invoice=\"1\" shortcode attr\n";
c13b2c_ok(
	'admin_invoice="1" present in do_shortcode call',
	false !== strpos( $co_clean, 'admin_invoice=\\"1\\"' ) || false !== strpos( $co_page, "admin_invoice=\"1\"" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'Docblock notes admin_invoice sets en_invoice_type + en_invoice_action_mode',
	false !== strpos( $co_page, 'en_invoice_type' ) && false !== strpos( $co_page, 'en_invoice_action_mode' ),
	$pass, $fail, $log
);


// ── [3] PHP — ajax_create_order() method ─────────────────────────────────────
echo "\n[3] ajax_create_order() method\n";
c13b2c_ok(
	'public static function ajax_create_order() declared',
	false !== strpos( $co_clean, 'public static function ajax_create_order()' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'Docblock present before ajax_create_order() (has @return void in file)',
	false !== strpos( $co_page, '@return void' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'current_user_can( manage_options ) capability check',
	false !== strpos( $co_clean, "current_user_can( 'manage_options' )" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'check_ajax_referer( eem_admin_create_order ) nonce check',
	false !== strpos( $co_clean, "check_ajax_referer( 'eem_admin_create_order'" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'en_reservation_id read from $_POST with absint',
	false !== strpos( $co_clean, "absint( wp_unslash( \$_POST['en_reservation_id'] ) )" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'Post-type + publish-status guard on embedded reservation',
	false !== strpos( $co_clean, "EEM_Reservations_CPT::POST_TYPE !== \$post->post_type" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'$_POST[en_invoice_type] forced to manual',
	false !== strpos( $co_clean, "\$_POST['en_invoice_type']        = 'manual'" ) ||
	false !== strpos( $co_clean, "\$_POST['en_invoice_type'] = 'manual'" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'$_POST[en_invoice_action_mode] forced to send_payment_link',
	false !== strpos( $co_clean, "'send_payment_link'" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'eem_order_created hook registered to capture order_key',
	false !== strpos( $co_clean, "add_action" ) && false !== strpos( $co_clean, "'eem_order_created'" ) && false !== strpos( $co_clean, 'captured_order_key' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'do_shortcode called with admin_invoice="1" in ajax handler',
	(bool) preg_match( '/ajax_create_order.*?do_shortcode.*?admin_invoice.*?1/s', $co_clean ),
	$pass, $fail, $log
);
c13b2c_ok(
	'ob_start() + ob_end_clean() wrap do_shortcode() to suppress render_form_styles() bleed',
	false !== strpos( $co_clean, 'ob_start()' ) && false !== strpos( $co_clean, 'ob_end_clean()' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'wp_send_json_error on null order_key (create_failed code)',
	false !== strpos( $co_clean, "create_failed" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'EEM_Orders_List_Page::order_detail_url used for redirect URL',
	false !== strpos( $co_clean, 'EEM_Orders_List_Page::order_detail_url' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'wp_send_json_success returns order_key + redirect',
	false !== strpos( $co_clean, "'order_key'" ) && false !== strpos( $co_clean, "'redirect'" ),
	$pass, $fail, $log
);


// ── [4] Admin.php — wp_ajax_eem_admin_create_order hook ──────────────────────
echo "\n[4] admin.php — AJAX hook registration\n";
c13b2c_ok(
	"wp_ajax_eem_admin_create_order hooked to EEM_Create_Order_Page::ajax_create_order",
	false !== strpos( $admin_clean, "wp_ajax_eem_admin_create_order" ) &&
	false !== strpos( $admin_clean, "'EEM_Create_Order_Page', 'ajax_create_order'" ),
	$pass, $fail, $log
);


// ── [5] PHP — JS localization additions ──────────────────────────────────────
echo "\n[5] PHP — JS localization: createOrderNonce + ordersUrl\n";
c13b2c_ok(
	'window.eemCreateOrder.createOrderNonce localized',
	false !== strpos( $co_page, 'window.eemCreateOrder.createOrderNonce' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'wp_create_nonce( eem_admin_create_order ) used',
	false !== strpos( $co_clean, "wp_create_nonce( 'eem_admin_create_order' )" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'createOrderNonce only emitted when rid > 0 (no leak on base page)',
	false !== strpos( $co_clean, '$rid > 0 ? wp_create_nonce' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'window.eemCreateOrder.ordersUrl localized as fallback redirect',
	false !== strpos( $co_page, 'window.eemCreateOrder.ordersUrl' ),
	$pass, $fail, $log
);


// ── [6] JS — coSubmitOrder() function ────────────────────────────────────────
echo "\n[6] admin.js — coSubmitOrder() function\n";
c13b2c_ok(
	'coSubmitOrder() function declared',
	false !== strpos( $js_clean, 'function coSubmitOrder()' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'Finds .eem-reservation-form inside .eem-co-form-embed',
	false !== strpos( $js_clean, ".eem-co-form-embed .eem-reservation-form" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'new FormData(embeddedForm) collects form fields',
	false !== strpos( $js_clean, 'new FormData(embeddedForm)' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'Contact fields overridden from [data-eem-co-contact] inputs',
	false !== strpos( $js_clean, "data-eem-co-contact" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'Notes field overridden from outer Special Requests textarea',
	false !== strpos( $js_clean, "'notes'" ) && false !== strpos( $js_clean, 'notesEl' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'Action set to eem_admin_create_order',
	false !== strpos( $js_clean, "'eem_admin_create_order'" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'createOrderNonce posted as _wpnonce',
	false !== strpos( $js_clean, "cfg.createOrderNonce" ) && false !== strpos( $js_clean, "'_wpnonce'" ),
	$pass, $fail, $log
);
c13b2c_ok(
	'fetch() used (not XMLHttpRequest)',
	false !== strpos( $js_clean, 'fetch(cfg.ajaxUrl' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'Button disabled while request in flight, re-enabled on error',
	false !== strpos( $js_clean, 'btn.disabled = true' ) && false !== strpos( $js_clean, 'btn.disabled = false' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'window.location.href redirect on success',
	false !== strpos( $js_clean, 'window.location.href = j.data.redirect' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'EEM.showSaveToast called on success and error',
	(bool) preg_match( "/EEM\.showSaveToast.*?EEM\.showSaveToast.*?error/s", $js_clean ),
	$pass, $fail, $log
);


// ── [7] JS — button enable + click wiring ────────────────────────────────────
echo "\n[7] admin.js — Send Payment Link button enable + click handler\n";
c13b2c_ok(
	'DOMContentLoaded enables btn when .eem-co-form-embed present',
	false !== strpos( $js_clean, 'btn.disabled = false' ) &&
	false !== strpos( $js_clean, '.eem-co-form-embed' ),
	$pass, $fail, $log
);
c13b2c_ok(
	'Click handler for create-order-send-link calls coSubmitOrder()',
	(bool) preg_match( "/create-order-send-link.*?coSubmitOrder/s", $js_clean ),
	$pass, $fail, $log
);
c13b2c_ok(
	'coSubmitOrder() lives inside IIFE (not polluting global scope)',
	(bool) preg_match( '/\(function\s*\(\s*\)\s*\{[\s\S]*?function coSubmitOrder/s', $js_src ),
	$pass, $fail, $log
);


// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n";
foreach ( $log as $line ) { echo $line . "\n"; }
echo "\n" . ( $fail === 0 ? 'ALL PASS' : "FAILURES: {$fail}" ) . " — {$pass} passed, {$fail} failed\n\n";
exit( $fail > 0 ? 1 : 0 );
