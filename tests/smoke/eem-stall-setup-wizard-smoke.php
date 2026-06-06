<?php
/**
 * First-run Stall setup wizard (EEM_Stall_Setup_Wizard) guard.
 *
 * Verifies the modal renders the branching question steps + summary, exposes the
 * per-site pending flag, registers the "mark seen" AJAX endpoint, and that the
 * JS apply path targets the REAL editor control selectors (so answers actually
 * flip the stall controls — the bug that browser-verify caught at build time).
 */

$pass = 0; $fail = 0; $log = array();
function ss_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

// --- Render ---
ob_start();
EEM_Stall_Setup_Wizard::render_stall_modal();
$html = (string) ob_get_clean();

ss_ok( 'modal root present',            false !== strpos( $html, 'id="eem-stall-setup-wizard"' ),       $pass, $fail, $log );
ss_ok( 'uses shared .eem-modal chrome',  false !== strpos( $html, 'class="eem-modal eem-stall-setup"' ), $pass, $fail, $log );
ss_ok( 'carries pending flag attr',      (bool) preg_match( '/data-eem-pending="[01]"/', $html ),         $pass, $fail, $log );
ss_ok( 'carries the seen nonce',         (bool) preg_match( '/data-eem-nonce="[a-f0-9]+"/', $html ),      $pass, $fail, $log );

// Five question steps with the canonical keys + a summary step.
foreach ( array( 'inventory', 'selection', 'staytype', 'shavings', 'schedule' ) as $key ) {
	ss_ok( "step '$key' present", false !== strpos( $html, 'data-key="' . $key . '"' ), $pass, $fail, $log );
	ss_ok( "step '$key' has radio options", false !== strpos( $html, 'name="eem_stall_q_' . $key . '"' ), $pass, $fail, $log );
}
ss_ok( 'summary step present',   false !== strpos( $html, 'eem-stall-setup__summary' ),         $pass, $fail, $log );
ss_ok( 'nav + skip controls present',
	false !== strpos( $html, 'data-eem-action="stall-setup-next"' )
	&& false !== strpos( $html, 'data-eem-action="stall-setup-back"' )
	&& false !== strpos( $html, 'data-eem-action="stall-setup-close"' ),
	$pass, $fail, $log );

// --- Flag + AJAX wiring ---
$saved = get_option( EEM_Stall_Setup_Wizard::STALL_FLAG );
delete_option( EEM_Stall_Setup_Wizard::STALL_FLAG );
ss_ok( 'pending true when flag unset', true === EEM_Stall_Setup_Wizard::stall_pending(), $pass, $fail, $log );
update_option( EEM_Stall_Setup_Wizard::STALL_FLAG, 1, false );
ss_ok( 'pending false when flag set', false === EEM_Stall_Setup_Wizard::stall_pending(), $pass, $fail, $log );
if ( false === $saved ) { delete_option( EEM_Stall_Setup_Wizard::STALL_FLAG ); } else { update_option( EEM_Stall_Setup_Wizard::STALL_FLAG, $saved, false ); }
ss_ok( 'STALL_FLAG key is eem_stall_setup_seen', 'eem_stall_setup_seen' === EEM_Stall_Setup_Wizard::STALL_FLAG, $pass, $fail, $log );
ss_ok( 'RV_FLAG key is eem_rv_setup_seen',       'eem_rv_setup_seen' === EEM_Stall_Setup_Wizard::RV_FLAG,       $pass, $fail, $log );
ss_ok( 'mark-seen AJAX registered', false !== has_action( 'wp_ajax_eem_stall_setup_seen' ), $pass, $fail, $log );

// --- JS apply targets the real editor controls ---
$js = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
ss_ok( 'JS defines eemStallApply',            false !== strpos( $js, 'function eemStallApply' ), $pass, $fail, $log );
ss_ok( 'JS branches selection on numbered',   false !== strpos( $js, "'numbered' === inv" ),     $pass, $fail, $log );
ss_ok( 'JS clicks inventory-type control',    false !== strpos( $js, 'toggle-stall-inventory-type' ), $pass, $fail, $log );
ss_ok( 'JS clicks customer-selection control', false !== strpos( $js, 'toggle-stall-customer-selection' ), $pass, $fail, $log );
ss_ok( 'JS flips real stay-type/shavings/schedule toggles via setToggle',
	false !== strpos( $js, 'function setToggle' )
	&& false !== strpos( $js, "setToggle('stall_nightly_enabled'" )
	&& false !== strpos( $js, "setToggle('stall_weekend_enabled'" )
	&& false !== strpos( $js, "setToggle('required_shavings_enabled'" )
	&& false !== strpos( $js, "setToggle('stall_schedule_enabled'" ),
	$pass, $fail, $log );
ss_ok( 'JS auto-opens on stall enable + persists seen',
	false !== strpos( $js, 'function eemStallMaybeOpen' ) && false !== strpos( $js, 'eem_stall_setup_seen' ),
	$pass, $fail, $log );

// --- CSS option-card classes present ---
$css = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
foreach ( array( 'eem-stall-setup__opt', 'eem-stall-setup__options', 'eem-stall-setup__q', 'eem-stall-setup__summary-list' ) as $cls ) {
	ss_ok( "CSS .$cls exists", false !== strpos( $css, '.' . $cls ), $pass, $fail, $log );
}

// --- Loaded + registered in bootstrap ---
ss_ok( 'class file required in bootstrap', false !== strpos( (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' ), 'class-eem-stall-setup-wizard.php' ), $pass, $fail, $log );

echo "\n=== Stall setup wizard smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
