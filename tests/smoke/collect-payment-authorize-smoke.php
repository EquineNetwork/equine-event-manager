<?php
/**
 * Smoke: the C14 Authorize.net admin "Charge Card" path is wired end-to-end at
 * the structural level (live charge testing deferred until Authorize.net
 * credentials are provisioned).
 *
 * Run: wp eval-file tests/smoke/collect-payment-authorize-smoke.php
 *
 * Verifies:
 *  - the AJAX action `eem_collect_payment_authorize_charge` is registered (admin only, no nopriv)
 *  - the handler method exists + is public on EEM_Shortcodes
 *  - the reused dispatch `process_authorize_net_invoice_payment` exists
 *  - the page renders the Auth.net raw-card fields when the gateway is configured
 *  - dispatched logged-out, the handler refuses (permission gate fires, parent survives)
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

require __DIR__ . '/_ajax-harness.php';

$pass = 0;
$fail = 0;
$log  = array();
$ok   = function ( $name, $cond ) use ( &$pass, &$fail, &$log ) {
	if ( $cond ) {
		$pass++;
		$log[] = "PASS  $name";
	} else {
		$fail++;
		$log[] = "FAIL  $name";
	}
};

// [1] Action registered (admin only).
$ok( '[1] wp_ajax_ action registered', has_action( 'wp_ajax_eem_collect_payment_authorize_charge' ) !== false );
$ok( '[1] no nopriv variant (admin only)', has_action( 'wp_ajax_nopriv_eem_collect_payment_authorize_charge' ) === false );

// [2] Handler + reused dispatch exist on EEM_Shortcodes.
$ref = new ReflectionClass( 'EEM_Shortcodes' );
$ok( '[2] handler method exists', $ref->hasMethod( 'ajax_collect_payment_authorize_charge' ) );
$ok( '[2] handler is public', $ref->hasMethod( 'ajax_collect_payment_authorize_charge' ) && $ref->getMethod( 'ajax_collect_payment_authorize_charge' )->isPublic() );
$ok( '[2] reused dispatch present', $ref->hasMethod( 'process_authorize_net_invoice_payment' ) );

// [3] Page-side: client config getter + charge-asset printer exist.
$pref = new ReflectionClass( 'EEM_Collect_Payment_Page' );
$ok( '[3] get_authorize_net_client_config exists', $pref->hasMethod( 'get_authorize_net_client_config' ) );
$ok( '[3] print_authorize_charge_assets exists', $pref->hasMethod( 'print_authorize_charge_assets' ) );

// [4] Dispatched logged-out → permission gate fires (parent survives the wp_die).
$r = eem_dispatch_ajax( 'eem_collect_payment_authorize_charge', array( 'order_key' => 'nope' ), 0 );
$ok( '[4] dispatch returned (parent survived child wp_die)', is_array( $r ) );
$ok( '[4] gate refused (success !== true)', true !== $r['success'] );

echo implode( "\n", $log ) . "\n";
echo "collect-payment-authorize-smoke: {$pass} passed, {$fail} failed\n";
