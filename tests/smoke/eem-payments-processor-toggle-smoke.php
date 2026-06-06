<?php
/**
 * Payments: inactive-processor field disabling guard.
 *
 * Verifies the Payments panel tags each connection card with
 * data-eem-processor-section + an "inactive" note, that the JS disables the
 * inactive processor's fields off the selected_gateway radio, and — critically —
 * that the save handler is isset-guarded so a disabled (non-submitted) section
 * never wipes the other processor's saved keys.
 */

$pass = 0; $fail = 0; $log = array();
function pt_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

// --- Render ---
$page = new EEM_Settings_Page();
$m = new ReflectionMethod( 'EEM_Settings_Page', 'render_payments_panel' );
$m->setAccessible( true );
ob_start();
$m->invoke( $page );
$html = (string) ob_get_clean();

pt_ok( 'Stripe section tagged',                false !== strpos( $html, 'data-eem-processor-section="stripe"' ),        $pass, $fail, $log );
pt_ok( 'Authorize.net section tagged',         false !== strpos( $html, 'data-eem-processor-section="authorize_net"' ), $pass, $fail, $log );
pt_ok( 'inactive note rendered on both cards', 2 === substr_count( $html, 'eem-processor-inactive-note' ),              $pass, $fail, $log );
pt_ok( 'selected_gateway radios present',      false !== strpos( $html, 'name="payload[selected_gateway]"' ),          $pass, $fail, $log );

// --- JS behavior ---
$js = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
pt_ok( 'JS defines eemApplyProcessorState',       false !== strpos( $js, 'function eemApplyProcessorState' ),         $pass, $fail, $log );
pt_ok( 'JS keys off the selected_gateway radio',  false !== strpos( $js, 'payload[selected_gateway]' ),               $pass, $fail, $log );
pt_ok( 'JS disables the inactive section fields', false !== strpos( $js, 'el.disabled = !isActive' ),                 $pass, $fail, $log );

// --- Save handler preserves the inactive (non-submitted) processor's keys ---
$src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-settings-page.php' );
pt_ok( "save isset-guards \$payload['stripe'] (preserves keys when disabled)",        false !== strpos( $src, "isset( \$payload['stripe'] )" ),        $pass, $fail, $log );
pt_ok( "save isset-guards \$payload['authorize_net'] (preserves keys when disabled)", false !== strpos( $src, "isset( \$payload['authorize_net'] )" ), $pass, $fail, $log );

// --- CSS dim state ---
$css = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
pt_ok( 'CSS dims the inactive connection card', false !== strpos( $css, '.eem-processor-section--inactive' ), $pass, $fail, $log );

echo "\n=== Payments processor-toggle smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
