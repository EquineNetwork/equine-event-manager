<?php
/**
 * T1 + T2 — Tack Stalls 3-mode control + shavings exclusion.
 *
 * T1: `_en_stall_tack_mode` ('off'|'admin'|'customer') replaces the v2 #4
 * boolean; admin-assigned numbers live in `_en_stall_tack_admin_stalls`. The
 * control renders under Blocked Stall Numbers; customer selector shows only in
 * 'customer' mode. T2: tack stalls are excluded from required shavings (they
 * still pay the normal stall rate). Migration #006 converts old bool → mode.
 */

$pass = 0; $fail = 0; $log = array();
function tk_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

/* ── T1: CPT data layer ─────────────────────────────────────────── */
tk_ok( 'sanitize_stall_tack_mode accepts off',      'off'      === EEM_Reservations_CPT::sanitize_stall_tack_mode( 'off' ),      $pass, $fail, $log );
tk_ok( 'sanitize_stall_tack_mode accepts admin',    'admin'    === EEM_Reservations_CPT::sanitize_stall_tack_mode( 'admin' ),    $pass, $fail, $log );
tk_ok( 'sanitize_stall_tack_mode accepts customer', 'customer' === EEM_Reservations_CPT::sanitize_stall_tack_mode( 'customer' ), $pass, $fail, $log );
tk_ok( 'sanitize_stall_tack_mode falls back to customer on garbage', 'customer' === EEM_Reservations_CPT::sanitize_stall_tack_mode( 'wat' ), $pass, $fail, $log );

$cpt      = new EEM_Reservations_CPT();
$defaults = ( new ReflectionMethod( 'EEM_Reservations_CPT', 'get_default_meta_values' ) );
$defaults->setAccessible( true );
$dmap = $defaults->invoke( $cpt );
tk_ok( 'defaults expose stall_tack_mode=customer',        isset( $dmap['stall_tack_mode'] ) && 'customer' === $dmap['stall_tack_mode'], $pass, $fail, $log );
tk_ok( 'defaults expose stall_tack_admin_stalls=array()', isset( $dmap['stall_tack_admin_stalls'] ) && array() === $dmap['stall_tack_admin_stalls'], $pass, $fail, $log );
tk_ok( 'old boolean key removed from defaults',           ! array_key_exists( 'stall_tack_designation_enabled', $dmap ), $pass, $fail, $log );

/* ── T1: editor partial renders the 3-mode control under Blocked Stalls ── */
$section = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-stall.php' );
$pos_blocked = strpos( $section, 'Blocked Stall Numbers' );
$pos_tack    = strpos( $section, 'eem-stall-tack-mode-input' );
tk_ok( 'tack-mode hidden input present',          false !== $pos_tack, $pass, $fail, $log );
tk_ok( 'tack control renders AFTER Blocked Stall Numbers', false !== $pos_blocked && false !== $pos_tack && $pos_tack > $pos_blocked, $pass, $fail, $log );
tk_ok( 'tack control has all three mode buttons',  3 === substr_count( $section, 'data-eem-action="toggle-tack-mode"' ), $pass, $fail, $log );
tk_ok( 'admin tack tag-select present',            false !== strpos( $section, 'eem-tack-admin-select' ) && false !== strpos( $section, 'eem_tack_admin_stalls[]' ), $pass, $fail, $log );
tk_ok( 'old boolean toggle removed from partial',  false === strpos( $section, "'stall_tack_designation_enabled'" ), $pass, $fail, $log );

/* ── T1: JS wiring ──────────────────────────────────────────────── */
$js = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
tk_ok( 'JS registers toggle-tack-mode action', false !== strpos( $js, "'toggle-tack-mode'" ) && false !== strpos( $js, 'function toggleTackMode' ), $pass, $fail, $log );
tk_ok( 'JS populates tack tag-select from stall labels', false !== strpos( $js, "'eem-tack-admin-select'" ), $pass, $fail, $log );

/* ── T1: save handler persists admin tack stalls ────────────────── */
$editor = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
tk_ok( 'save handler writes _en_stall_tack_admin_stalls', false !== strpos( $editor, "_en_stall_tack_admin_stalls" ), $pass, $fail, $log );

/* ── T1: customer selector gated to 'customer' mode only ────────── */
$sc_src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
tk_ok( 'customer tack selector gated to customer mode', false !== strpos( $sc_src, "'customer' === ( \$data['stall_tack_mode'] ?? 'customer' )" ), $pass, $fail, $log );

/* ── T2: get_tack_stall_count (private) across all three modes ───── */
$sc  = new EEM_Shortcodes();
$gtc = new ReflectionMethod( 'EEM_Shortcodes', 'get_tack_stall_count' );
$gtc->setAccessible( true );

$off_data      = array( 'stall_tack_mode' => 'off' );
$cust_data     = array( 'stall_tack_mode' => 'customer' );
$admin_data    = array( 'stall_tack_mode' => 'admin', 'stall_tack_admin_stalls' => array( '5', '6' ) );
$sub_one_tack  = array( 'preferred_tack_stall' => '7', 'preferred_stall_units' => array( '5', '6', '7' ) );
$sub_no_tack   = array( 'preferred_tack_stall' => '', 'preferred_stall_units' => array( '5', '6', '7' ) );
$sub_admin_hit = array( 'preferred_stall_units' => array( '4', '5', '6' ) ); // 5 & 6 are admin tack → 2
$sub_admin_mis = array( 'preferred_stall_units' => array( '1', '2', '3' ) ); // none → 0

// Signature is get_tack_stall_count( $submission, $data ).
tk_ok( 'tack count: off mode = 0',                 0 === $gtc->invoke( $sc, $sub_one_tack,  $off_data ),   $pass, $fail, $log );
tk_ok( 'tack count: customer + designated = 1',    1 === $gtc->invoke( $sc, $sub_one_tack,  $cust_data ),  $pass, $fail, $log );
tk_ok( 'tack count: customer, none designated = 0',0 === $gtc->invoke( $sc, $sub_no_tack,   $cust_data ),  $pass, $fail, $log );
tk_ok( 'tack count: admin, 2 picked match = 2',    2 === $gtc->invoke( $sc, $sub_admin_hit, $admin_data ), $pass, $fail, $log );
tk_ok( 'tack count: admin, none picked match = 0', 0 === $gtc->invoke( $sc, $sub_admin_mis, $admin_data ), $pass, $fail, $log );

/* ── T2: the required-shavings line bills on the post-tack quantity ── */
tk_ok( 'server shavings uses shavings_stall_qty (tack-excluded)', false !== strpos( $sc_src, '$shavings_stall_qty * absint( $data[\'required_shavings_per_stall\'] )' ), $pass, $fail, $log );
tk_ok( 'shavings_stall_qty = stall_qty_total - tack count',        false !== strpos( $sc_src, '$shavings_stall_qty            = max( 0, $stall_qty_total - $tack_stall_count )' ), $pass, $fail, $log );
tk_ok( 'JS live total excludes tack from shavings', false !== strpos( $sc_src, 'countTackStalls(form, stallQty)' ) && false !== strpos( $sc_src, 'shavingsStallQty' ), $pass, $fail, $log );

/* ── Migration #006 ─────────────────────────────────────────────── */
tk_ok( 'migration 006 file exists', is_readable( EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-006-tack-mode.php' ), $pass, $fail, $log );
$act = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-activator.php' );
tk_ok( 'migration 006 registered in activator', false !== strpos( $act, 'eem_mig_006_tack_mode_complete' ) && false !== strpos( $act, 'eem_mig_006_tack_mode()' ), $pass, $fail, $log );
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-006-tack-mode.php';
tk_ok( 'migration resolver function defined', function_exists( 'eem_mig_006_resolve_mode' ), $pass, $fail, $log );

// Read-only resolver check against the demo reservation (no legacy meta → 'customer').
$demo_id = 6124;
if ( get_post( $demo_id ) ) {
	$resolved = eem_mig_006_resolve_mode( $demo_id );
	tk_ok( 'resolver returns a valid mode for demo reservation', in_array( $resolved, array( 'off', 'customer' ), true ), $pass, $fail, $log );
}

echo "\n=== Tack T1+T2 smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
