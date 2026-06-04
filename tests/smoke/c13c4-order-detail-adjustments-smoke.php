<?php
/**
 * C13.C.4 smoke — Order Detail surfaces custom items + discount.
 *
 * Seeds adjustments for a synthetic order key, renders the Order Summary card
 * with a minimal $order array, and asserts the custom-item lines, the discount
 * navy-chip + reason, and the recomputed grand total (component total + custom
 * items − discount) all appear. Content-density per the render-chunk discipline:
 * asserts NON-EMPTY values, not just container classes.
 *
 * Run: wp eval-file tests/smoke/c13c4-order-detail-adjustments-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run via wp eval-file\n" );
	exit( 1 );
}

$passed = 0;
$failed = 0;
$check  = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) {
		$passed++;
		echo "  ok  - {$label}\n";
	} else {
		$failed++;
		echo "FAIL  - {$label}\n";
	}
};

$check( 'order detail class loaded', class_exists( 'EEM_Order_Detail_Page' ) );

$order_key = wp_generate_password( 32, false ); // realistic 32-char order key

// Seed two custom items + a $10 discount.
EEM_Order_Adjustments_Repo::replace_custom_items( $order_key, array(
	array( 'description' => 'Late arrival fee', 'amount' => 25.00 ),
	array( 'description' => 'Damage charge', 'amount' => 15.00 ),
) );
EEM_Order_Adjustments_Repo::set_discount( $order_key, 'dollar', 10.00, 'First-time customer', 140.00 );

// Minimal order: $100 component total (stall subtotal 90 + fees 10).
$order = array(
	'order_key'      => $order_key,
	'stall_subtotal' => 90.00,
	'rv_subtotal'    => 0.0,
	'fees'           => 10.00,
	'total'          => 100.00,
);

$page = new EEM_Order_Detail_Page();
$ref  = new ReflectionMethod( 'EEM_Order_Detail_Page', 'render_summary_card' );
$ref->setAccessible( true );
ob_start();
$ref->invoke( $page, $order );
$html = (string) ob_get_clean();

// --- Custom line items -----------------------------------------------------
$check( 'Custom Line Items section header rendered', str_contains( $html, 'Custom Line Items' ) );
$check( 'first custom item description shown', str_contains( $html, 'Late arrival fee' ) );
$check( 'second custom item description shown', str_contains( $html, 'Damage charge' ) );
$check( 'custom item amount shown ($25.00)', str_contains( $html, '$25.00' ) );
$check( 'custom items section total ($40.00)', str_contains( $html, '$40.00' ) );

// --- Discount --------------------------------------------------------------
$check( 'discount container rendered', str_contains( $html, 'data-eem-order-discount' ) );
$check( 'discount negative amount shown (−$10.00)', str_contains( $html, '−$10.00' ) );
$check( 'discount reason chip shows reason text', str_contains( $html, 'eem-order-summary__discount-chip' ) && str_contains( $html, 'First-time customer' ) );

// --- Recomputed grand total: 100 + 40 − 10 = 130 ---------------------------
$check( 'grand total recomputed to $130.00', str_contains( $html, 'eem-order-summary__grand-val' ) && str_contains( $html, '$130.00' ) );

// --- No-adjustments order: total unchanged, no discount markup -------------
$plain_key = wp_generate_password( 32, false );
$plain = array( 'order_key' => $plain_key, 'stall_subtotal' => 50.0, 'rv_subtotal' => 0.0, 'fees' => 5.0, 'total' => 55.0 );
ob_start();
$ref->invoke( $page, $plain );
$plain_html = (string) ob_get_clean();
$check( 'no discount markup when none applied', ! str_contains( $plain_html, 'data-eem-order-discount' ) );
$check( 'no custom items section when none', ! str_contains( $plain_html, 'Custom Line Items' ) );
$check( 'plain order total unchanged ($55.00)', str_contains( $plain_html, '$55.00' ) );

// --- cleanup ---------------------------------------------------------------
EEM_Order_Adjustments_Repo::delete_for_order( $order_key );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) {
	exit( 1 );
}
