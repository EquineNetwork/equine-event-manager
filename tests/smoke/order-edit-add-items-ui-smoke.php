<?php
/**
 * Smoke: Order Edit Phase 3 — Add-Items UI wiring.
 *
 * Asserts the server-side pieces the Add-Items modal depends on:
 *   - EEM_Shortcodes::get_addable_inventory() returns enabled sections with
 *     stay-type options + per-stay-type rates for a configured reservation.
 *   - The modal render emits the form, nonce, type select, and per-section
 *     data-rates / data-staytypes attributes the JS reads.
 *   - The AJAX action is registered and the handler method exists.
 *   - The custom-line-item persistence path round-trips through the adjustments
 *     repo (the 'custom' branch of ajax_add_items).
 *
 * Run:
 *   php wp-cli.phar eval-file tests/smoke/order-edit-add-items-ui-smoke.php
 */

if ( ! class_exists( 'EEM_Shortcodes' ) || ! class_exists( 'EEM_Order_Detail_Page' ) || ! class_exists( 'EEM_Orders_Repository' ) ) {
	echo "FAIL: required classes not loaded\n";
	return;
}

$pass = 0;
$fail = 0;
$check = function ( $label, $cond ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
};

/* ---- get_addable_inventory ---- */
$inv = ( new EEM_Shortcodes() )->get_addable_inventory( 5990 );
$check( 'inventory has a stall section', ! empty( $inv['stall'] ) );
$check( 'stall section has stay_types', ! empty( $inv['stall']['stay_types'] ) );
$check( 'stall section has a nightly rate > 0', isset( $inv['stall']['rates']['nightly'] ) && (float) $inv['stall']['rates']['nightly'] > 0 );

/* ---- Modal render wiring ---- */
$repo  = new EEM_Orders_Repository();
$order = null;
foreach ( (array) $repo->get_orders( '', 'date', 'desc' ) as $o ) {
	if ( 5990 === (int) $o['reservation_id'] && 'cancelled' !== $o['status_slug'] ) { $order = $o; break; }
}
if ( ! $order ) {
	echo "  skip - no non-cancelled 5990 order found for render test\n";
} else {
	$page = new EEM_Order_Detail_Page();
	$ref  = new ReflectionMethod( 'EEM_Order_Detail_Page', 'render_add_items_modal' );
	$ref->setAccessible( true );
	ob_start();
	$ref->invoke( $page, $order );
	$html = (string) ob_get_clean();

	$check( 'modal emits the add-items form', false !== strpos( $html, 'data-eem-add-items-form' ) );
	$check( 'modal emits the nonce field', false !== strpos( $html, '_eem_add_items_nonce' ) );
	$check( 'modal emits the type select', false !== strpos( $html, 'data-eem-add-items-type' ) );
	$check( 'modal emits per-section data-rates', false !== strpos( $html, 'data-rates' ) );
	$check( 'modal emits per-section data-staytypes', false !== strpos( $html, 'data-staytypes' ) );
	$check( 'modal emits the custom-item fields', false !== strpos( $html, 'data-eem-add-items-custom' ) );
	$check( 'modal carries the eem_order_add_items action', false !== strpos( $html, 'eem_order_add_items' ) );
}

/* ---- AJAX registration ---- */
$check( 'wp_ajax_eem_order_add_items action registered', (bool) has_action( 'wp_ajax_eem_order_add_items' ) );
$check( 'ajax_add_items handler exists', method_exists( 'EEM_Order_Detail_Page', 'ajax_add_items' ) );

/* ---- Custom-item persistence round-trip ---- */
if ( $order && class_exists( 'EEM_Order_Adjustments_Repo' ) ) {
	$key    = $order['order_key'];
	$before = count( EEM_Order_Adjustments_Repo::get_custom_items( $key ) );
	$ins    = EEM_Order_Adjustments_Repo::insert_custom_item( $key, 'Smoke late fee', 12.5 );
	$after  = EEM_Order_Adjustments_Repo::get_custom_items( $key );
	$check( 'insert_custom_item succeeds', false !== $ins );
	$check( 'custom item count increased by 1', count( $after ) === $before + 1 );
	$found = false;
	foreach ( $after as $it ) {
		if ( 'Smoke late fee' === $it['description'] && abs( (float) $it['amount'] - 12.5 ) < 0.001 ) { $found = true; break; }
	}
	$check( 'inserted custom item round-trips with description + amount', $found );
}

echo "\n" . $pass . ' passed, ' . $fail . " failed\n";
