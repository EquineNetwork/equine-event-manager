<?php
/**
 * C14.B smoke — admin Collect Payment Stripe charge wiring.
 *
 * Source-shape + logic assertions (the live charge round-trip is browser-verified
 * with the Stripe test card, per the runtime-claims discipline). Covers the
 * amount-due math, the two gated AJAX handlers' registration + guard order, the
 * Charge Card form render when Stripe is ready, and the server-side verification
 * checks (status succeeded + order_key match + amount match).
 *
 * Run: wp eval-file tests/smoke/c14b-collect-payment-charge-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- amount-due math (component total + custom items − discount) ------------
$order_key = wp_generate_password( 32, false );
EEM_Order_Adjustments_Repo::replace_custom_items( $order_key, array( array( 'description' => 'Late fee', 'amount' => 50.00 ) ) );
EEM_Order_Adjustments_Repo::set_discount( $order_key, 'dollar', 10.00, 'Test', 140.00 );

$sc  = new ReflectionClass( 'EEM_Shortcodes' );
$obj = $sc->newInstanceWithoutConstructor();
$amt = $sc->getMethod( 'get_order_amount_due' );
$amt->setAccessible( true );
$due = $amt->invoke( $obj, array( 'total' => 100.00 ), $order_key );
$check( 'amount due = 100 + 50 − 10 = 140', abs( $due - 140.00 ) < 0.001 );
$due0 = $amt->invoke( $obj, array( 'total' => 5.00 ), $order_key ); // 5 + 50 − 10 = 45
$check( 'amount due recomputes per order', abs( $due0 - 45.00 ) < 0.001 );

EEM_Order_Adjustments_Repo::delete_for_order( $order_key );

// --- AJAX registration -----------------------------------------------------
$check( 'create-intent action registered', false !== has_action( 'wp_ajax_eem_collect_payment_create_intent' ) );
$check( 'confirm action registered', false !== has_action( 'wp_ajax_eem_collect_payment_confirm' ) );
$check( 'create-intent has NO nopriv (admin-only)', false === has_action( 'wp_ajax_nopriv_eem_collect_payment_create_intent' ) );
$check( 'confirm has NO nopriv (admin-only)', false === has_action( 'wp_ajax_nopriv_eem_collect_payment_confirm' ) );
$check( 'create-intent handler exists', method_exists( 'EEM_Shortcodes', 'ajax_collect_payment_create_intent' ) );
$check( 'confirm handler exists', method_exists( 'EEM_Shortcodes', 'ajax_collect_payment_confirm' ) );

// --- handler guard order (source) ------------------------------------------
$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/public/class-equine-event-manager-shortcodes.php' );
$create = substr( $src, (int) strpos( $src, 'function ajax_collect_payment_create_intent' ), 2600 );
$check( 'create-intent: cap check before nonce', strpos( $create, "current_user_can( 'manage_options' )" ) < strpos( $create, 'check_ajax_referer' ) );
$check( 'create-intent: nonce scoped to order_key', str_contains( $create, "'eem_collect_payment_' . \$order_key" ) );
$check( 'create-intent: rejects already-paid', str_contains( $create, "'already_paid'" ) );
$check( 'create-intent: uses request_stripe_api payment_intents', str_contains( $create, "request_stripe_api( 'POST', 'payment_intents'" ) );

$check( 'confirm: verifies status succeeded', str_contains( $src, "'succeeded' !== \$intent['status']" ) );
$check( 'confirm: verifies order_key metadata match', str_contains( $src, "\$intent['metadata']['order_key'] !== \$order_key" ) );
$check( 'confirm: verifies amount_received', str_contains( $src, 'amount_received' ) );
$check( 'confirm: marks order paid via repo', str_contains( $src, "update_order_payment_details( \$order_key, 'paid', \$intent_id, 'stripe' )" ) );
$check( 'confirm: captures card brand/last4 (CLEANUP #34)', str_contains( $src, "'Card Brand'" ) && str_contains( $src, "'Card Last4'" ) );
$check( 'confirm: expands latest_charge for card details', str_contains( $src, 'expand[]=latest_charge' ) && str_contains( $src, "latest_charge']['payment_method_details']" ) );

// --- Order Detail Payment Details renders captured card --------------------
$odref = new ReflectionMethod( 'EEM_Order_Detail_Page', 'render_payment_details_card' );
$odref->setAccessible( true );
$page  = new EEM_Order_Detail_Page();
ob_start();
$odref->invoke( $page, array(
	'customer_name' => 'Test', 'email' => 't@x.com', 'payment_gateway' => 'stripe', 'status_slug' => 'paid',
	'components'    => array( array( 'transaction_id' => 'pi_abc', 'notes' => "Card Brand: visa\nCard Last4: 4242" ) ),
) );
$od = (string) ob_get_clean();
$check( 'Order Detail shows the Card row', str_contains( $od, 'Card' ) && str_contains( $od, '4242' ) );
$check( 'Order Detail formats brand + last4', str_contains( $od, 'Visa' ) && str_contains( $od, '•••• 4242' ) );
$check( 'confirm: logs order_payment_collected', str_contains( $src, 'order_payment_collected' ) );

// --- Charge Card form renders when Stripe ready ----------------------------
$pref = new ReflectionMethod( 'EEM_Collect_Payment_Page', 'render_payment_card' );
$pref->setAccessible( true );
ob_start(); $pref->invoke( null, admin_url( 'admin.php' ), wp_generate_password( 32, false ), 'pending', 140.00 ); $card = (string) ob_get_clean();
// Stripe is configured in this dev env, so the live form should render.
$check( 'card element mount point rendered', str_contains( $card, 'id="eem-cp-card-element"' ) );
$check( 'charge button shows amount', str_contains( $card, 'Charge $140.00' ) );
$check( 'Stripe.js loaded', str_contains( $card, 'js.stripe.com/v3' ) );
$check( 'inline client calls create-intent', str_contains( $card, 'eem_collect_payment_create_intent' ) );
$check( 'inline client calls confirm', str_contains( $card, 'eem_collect_payment_confirm' ) );
$check( 'no raw card <input> in the form', ! preg_match( '/<input[^>]*name="(card|cvc|exp)/i', $card ) );

// Paid order → no charge form.
ob_start(); $pref->invoke( null, admin_url( 'admin.php' ), wp_generate_password( 32, false ), 'paid', 140.00 ); $paid = (string) ob_get_clean();
$check( 'paid order shows no card element', ! str_contains( $paid, 'id="eem-cp-card-element"' ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
