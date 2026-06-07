<?php
/**
 * Tack Stalls — On/Off control + shavings exclusion.
 *
 * `_en_stall_tack_mode` is 'off' or 'customer' (on). When on, the buyer flags a
 * single tack stall at checkout (drives the required-shavings exclusion — tack
 * stalls still pay the normal stall rate). The admin assigns/overrides the actual
 * tack stall via the "Mark as Tack Stall" chip on the Stall Chart — there is no
 * per-reservation admin tag-select. The legacy 'admin' mode normalizes to on.
 * Migration #006 converts the old boolean toggle.
 */

$pass = 0; $fail = 0; $log = array();
function tk_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

/* ── CPT data layer ─────────────────────────────────────────────── */
tk_ok( 'sanitize_stall_tack_mode keeps off',          'off'      === EEM_Reservations_CPT::sanitize_stall_tack_mode( 'off' ),      $pass, $fail, $log );
tk_ok( 'sanitize_stall_tack_mode keeps customer',     'customer' === EEM_Reservations_CPT::sanitize_stall_tack_mode( 'customer' ), $pass, $fail, $log );
tk_ok( 'sanitize_stall_tack_mode maps legacy admin → customer', 'customer' === EEM_Reservations_CPT::sanitize_stall_tack_mode( 'admin' ), $pass, $fail, $log );
tk_ok( 'sanitize_stall_tack_mode garbage → customer', 'customer' === EEM_Reservations_CPT::sanitize_stall_tack_mode( 'wat' ),   $pass, $fail, $log );

$cpt      = new EEM_Reservations_CPT();
$defaults = ( new ReflectionMethod( 'EEM_Reservations_CPT', 'get_default_meta_values' ) );
$defaults->setAccessible( true );
$dmap = $defaults->invoke( $cpt );
tk_ok( 'defaults expose stall_tack_mode=customer',  isset( $dmap['stall_tack_mode'] ) && 'customer' === $dmap['stall_tack_mode'], $pass, $fail, $log );
tk_ok( 'admin-stalls default removed',              ! array_key_exists( 'stall_tack_admin_stalls', $dmap ), $pass, $fail, $log );
tk_ok( 'old boolean key absent from defaults',      ! array_key_exists( 'stall_tack_designation_enabled', $dmap ), $pass, $fail, $log );

/* ── Editor partial: On/Off control under Blocked Stall Numbers ──── */
$section = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'templates/admin/reservation-editor/_section-stall.php' );
$pos_blocked = strpos( $section, 'Blocked Stall Numbers' );
$pos_tack    = strpos( $section, 'eem-stall-tack-mode-input' );
tk_ok( 'tack-mode hidden input present',                 false !== $pos_tack, $pass, $fail, $log );
tk_ok( 'tack control renders AFTER Blocked Stall Numbers', false !== $pos_blocked && false !== $pos_tack && $pos_tack > $pos_blocked, $pass, $fail, $log );
tk_ok( 'tack control is a 2-button On/Off',              2 === substr_count( $section, 'data-eem-action="toggle-tack-mode"' ), $pass, $fail, $log );
tk_ok( 'admin tack tag-select removed',                  false === strpos( $section, 'eem-tack-admin-select' ) && false === strpos( $section, 'eem_tack_admin_stalls' ), $pass, $fail, $log );
tk_ok( 'old boolean toggle removed from partial',        false === strpos( $section, "'stall_tack_designation_enabled'" ), $pass, $fail, $log );

/* ── JS wiring ──────────────────────────────────────────────────── */
$js = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
tk_ok( 'JS registers toggle-tack-mode action', false !== strpos( $js, "'toggle-tack-mode'" ) && false !== strpos( $js, 'function toggleTackMode' ), $pass, $fail, $log );
tk_ok( 'JS no longer references tack-admin tag-select', false === strpos( $js, 'eem-tack-admin-select' ), $pass, $fail, $log );

/* ── Save handler no longer persists admin tack stalls ──────────── */
$editor = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );
tk_ok( 'save handler dropped _en_stall_tack_admin_stalls', false === strpos( $editor, '_en_stall_tack_admin_stalls' ), $pass, $fail, $log );

/* ── Customer selector gated to "on" (off !== mode) ─────────────── */
$sc_src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
tk_ok( 'customer tack selector shown when tack is on', false !== strpos( $sc_src, "'off' !== ( \$data['stall_tack_mode'] ?? 'customer' )" ), $pass, $fail, $log );

/* ── get_tack_stall_count (private) — off vs on ─────────────────── */
$sc  = new EEM_Shortcodes();
$gtc = new ReflectionMethod( 'EEM_Shortcodes', 'get_tack_stall_count' );
$gtc->setAccessible( true );

$off_data     = array( 'stall_tack_mode' => 'off' );
$on_data      = array( 'stall_tack_mode' => 'customer' );
$sub_one_tack = array( 'preferred_tack_stall' => '7', 'preferred_stall_units' => array( '5', '6', '7' ) );
$sub_no_tack  = array( 'preferred_tack_stall' => '', 'preferred_stall_units' => array( '5', '6', '7' ) );

// Signature is get_tack_stall_count( $submission, $data ).
tk_ok( 'tack count: off = 0',                  0 === $gtc->invoke( $sc, $sub_one_tack, $off_data ), $pass, $fail, $log );
tk_ok( 'tack count: on + designated = 1',      1 === $gtc->invoke( $sc, $sub_one_tack, $on_data ),  $pass, $fail, $log );
tk_ok( 'tack count: on, none designated = 0',  0 === $gtc->invoke( $sc, $sub_no_tack,  $on_data ),  $pass, $fail, $log );

/* ── Required-shavings bills on the post-tack quantity ──────────── */
tk_ok( 'server shavings uses shavings_stall_qty (tack-excluded)', false !== strpos( $sc_src, '$shavings_stall_qty * absint( $data[\'required_shavings_per_stall\'] )' ), $pass, $fail, $log );
tk_ok( 'shavings_stall_qty = stall_qty_total - tack count',        false !== strpos( $sc_src, '$shavings_stall_qty            = max( 0, $stall_qty_total - $tack_stall_count )' ), $pass, $fail, $log );
tk_ok( 'JS live total excludes tack from shavings', false !== strpos( $sc_src, 'countTackStalls(form, stallQty)' ) && false !== strpos( $sc_src, 'shavingsStallQty' ), $pass, $fail, $log );

/* ── Migration #006 ─────────────────────────────────────────────── */
tk_ok( 'migration 006 file exists', is_readable( EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-006-tack-mode.php' ), $pass, $fail, $log );
$act = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-activator.php' );
tk_ok( 'migration 006 registered in activator', false !== strpos( $act, 'eem_mig_006_tack_mode_complete' ) && false !== strpos( $act, 'eem_mig_006_tack_mode()' ), $pass, $fail, $log );
require_once EQUINE_EVENT_MANAGER_PATH . 'includes/migrations/eem-mig-006-tack-mode.php';
tk_ok( 'migration resolver function defined', function_exists( 'eem_mig_006_resolve_mode' ), $pass, $fail, $log );

echo "\n=== Tack on/off + shavings smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
