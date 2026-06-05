<?php
/**
 * C13.A.1 smoke — Create Order page renders the real two-column workspace.
 *
 * Replaces the DS-1 iframe-preview stub with eem-* markup. Verifies the page
 * structure (customer lookup, reservation picker, contact, section cards, custom
 * items, summary rail, payment hand-off) and that the payment rail does NOT wire a
 * real charge (Charge Card links to Collect Payment per the C13 decision).
 *
 * Run: wp eval-file tests/smoke/c13a-create-order-render-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

wp_set_current_user( 1 );
set_current_screen( 'dashboard' );
ob_start();
EEM_Create_Order_Page::render();
$html = (string) ob_get_clean();

$check( 'no longer renders the iframe mockup preview', false === strpos( $html, 'eem-mockup-preview' ) && false === strpos( $html, 'Coming in C13' ) );
$check( 'two-column workspace', false !== strpos( $html, 'eem-co-workspace' ) && false !== strpos( $html, 'eem-co-main' ) && false !== strpos( $html, 'eem-co-rail' ) );
$check( 'breadcrumb back to Orders', false !== strpos( $html, 'page=equine-event-manager-orders' ) && false !== strpos( $html, 'eem-breadcrumb' ) );
$check( 'customer lookup card + search input', false !== strpos( $html, 'data-eem-input-action="create-order-customer-search"' ) );
$check( 'skip-new-customer affordance', false !== strpos( $html, 'data-eem-action="create-order-skip-customer"' ) );
$check( 'reservation picker present', false !== strpos( $html, 'data-eem-input-action="create-order-reservation"' ) || false !== strpos( $html, 'No published reservations' ) );
$check( 'contact fields (first/last/email/phone)', false !== strpos( $html, 'data-eem-co-contact="first_name"' ) && false !== strpos( $html, 'data-eem-co-contact="email"' ) );
$check( 'four reservation-driven section cards', 4 === substr_count( $html, 'data-eem-co-section=' ) );
$check( 'custom line items card', false !== strpos( $html, 'data-eem-action="create-order-add-custom-item"' ) );
$check( 'special requests textarea', false !== strpos( $html, 'name="notes"' ) );
$check( 'order summary rail + total', false !== strpos( $html, 'data-eem-co-summary-total' ) );
$check( 'apply-discount affordance', false !== strpos( $html, 'data-eem-action="create-order-add-discount"' ) );
$check( 'payment tabs (Send Link / Charge Card)', 2 === substr_count( $html, 'data-eem-action="create-order-payment-tab"' ) );

// Payment-gating guard: no real charge UI; Charge Card hands off to Collect Payment.
// 2.7.25 — Charge Card now creates the order first, then redirects to Collect
// Payment (the old bare link landed on "No Order Specified"). Assert the button
// carries the create-order-charge action rather than a dead collect-payment link.
$check( 'Charge Card creates order then collects (create-order-charge action)', false !== strpos( $html, 'create-order-charge' ) );
$check( 'no card-number / CVC entry on this page', false === stripos( $html, 'name="card_number"' ) && false === stripos( $html, 'placeholder="CVC"' ) && false === stripos( $html, 'Charge $' ) );

// Hygiene on the new CSS block.
$css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$co_block = strstr( $css, '/* ════════════════════════════════════════════════════════════════════' . "\n" . '   C13 — Create Order' );
$check( 'create-order CSS block present', false !== $co_block );
// One documented exception (2.7.27): the embedded qty-stepper padding override
// uses !important to beat a grandfathered admin-legacy rule for the embedded
// customer form (see the admin.css comment). Remove that known line, then assert
// no OTHER !important appears in the create-order CSS.
$co_no_qty = str_replace( 'padding: 0 !important;', 'padding: 0;', (string) $co_block );
$check( 'no undocumented !important in the create-order CSS', false === $co_block || false === strpos( $co_no_qty, '!important' ) );
$check( 'no text-decoration: underline in the block', false === $co_block || false === strpos( $co_block, 'text-decoration: underline' ) );

// Page class hygiene.
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-create-order-page.php' );
$check( 'no error_log in the page class', false === strpos( $src, 'error_log(' ) );

WP_CLI::log( "\n=== C13.A.1 Create Order render smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'C13.A.1 Create Order render smoke passed.' );
