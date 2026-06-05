<?php
/**
 * Settings → Danger Zone wiring guard (NON-destructive).
 *
 * Verifies the "Erase all data & start fresh" UI is wired without ever calling
 * the destructive reset:
 *   - render_danger_panel() emits the reset button (+ action/nonce/dashboard
 *     attrs), the count summary, and the delete-on-uninstall opt-in checkbox.
 *   - save_danger_panel() persists the opt-in both ways.
 *   - The JS opener + action-map entry exist AND the JS-created modal uses only
 *     CSS classes present in admin.css (C7.X.20 invisible-modal guard).
 *   - The eem_reset_all_data AJAX action is registered.
 */

$pass = 0; $fail = 0; $log = array();
function dz_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

$page = new EEM_Settings_Page();
$ref  = new ReflectionClass( $page );

// --- Panel render ---
$render = $ref->getMethod( 'render_danger_panel' );
$render->setAccessible( true );
ob_start();
$render->invoke( $page );
$html = (string) ob_get_clean();

dz_ok( 'reset button present with action',     false !== strpos( $html, 'data-eem-action="settings-reset-all-data"' ), $pass, $fail, $log );
dz_ok( 'reset button carries a reset nonce',    (bool) preg_match( '/data-eem-reset-nonce="[a-f0-9]+"/', $html ),        $pass, $fail, $log );
dz_ok( 'reset button carries dashboard url',    false !== strpos( $html, 'data-eem-dashboard-url=' ),                    $pass, $fail, $log );
dz_ok( 'count summary list present',            false !== strpos( $html, 'eem-danger-summary' ),                         $pass, $fail, $log );
dz_ok( 'opt-in checkbox present',               false !== strpos( $html, 'name="payload[delete_data_on_uninstall]"' ),  $pass, $fail, $log );
dz_ok( 'opt-in form posts to danger panel',     false !== strpos( $html, 'data-eem-panel="danger"' ),                    $pass, $fail, $log );
dz_ok( 'TEC-untouched reassurance shown',        false !== stripos( $html, 'never touched' ),                            $pass, $fail, $log );

// --- save_danger_panel persists the opt-in both ways ---
$save = $ref->getMethod( 'save_danger_panel' );
$save->setAccessible( true );
$save->invoke( $page, array( 'delete_data_on_uninstall' => '1' ) );
dz_ok( 'opt-in saves ON', 1 === (int) get_option( 'equine_event_manager_delete_data_on_uninstall' ), $pass, $fail, $log );
$save->invoke( $page, array() );
dz_ok( 'opt-in saves OFF (unchecked omits the key)', 0 === (int) get_option( 'equine_event_manager_delete_data_on_uninstall' ), $pass, $fail, $log );
delete_option( 'equine_event_manager_delete_data_on_uninstall' );

// --- JS: opener + action-map entry + modal classes exist in CSS ---
$js  = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$css = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );

dz_ok( 'JS defines openResetAllDataModal',      false !== strpos( $js, 'function openResetAllDataModal' ),               $pass, $fail, $log );
dz_ok( 'JS action-map wires settings-reset-all-data', false !== strpos( $js, "'settings-reset-all-data':" ),             $pass, $fail, $log );
dz_ok( 'JS posts action=eem_reset_all_data',    false !== strpos( $js, "'eem_reset_all_data'" ),                         $pass, $fail, $log );
dz_ok( 'JS typed-confirm word is ERASE',         false !== strpos( $js, "CONFIRM_WORD = 'ERASE'" ),                       $pass, $fail, $log );

// C7.X.20: every CSS class the JS modal uses must exist in admin.css, or the
// modal renders invisibly. Extract the modal's class list and cross-check.
$modal_classes = array( 'eem-modal', 'eem-modal-card', 'eem-modal-head', 'eem-modal-head--danger', 'eem-modal-title', 'eem-modal-title--danger', 'eem-modal-body', 'eem-modal-foot', 'eem-field-input', 'eem-btn', 'eem-btn-secondary', 'eem-btn-danger' );
foreach ( $modal_classes as $cls ) {
	dz_ok( "modal class .$cls exists in admin.css", false !== strpos( $css, '.' . $cls ), $pass, $fail, $log );
}

// --- AJAX action registered ---
$loader = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager.php' );
dz_ok( 'eem_reset_all_data AJAX action registered', false !== strpos( $loader, "wp_ajax_eem_reset_all_data" ), $pass, $fail, $log );
dz_ok( 'handler method exists', method_exists( 'EEM_Settings_Page', 'handle_ajax_reset_all_data' ), $pass, $fail, $log );

// --- Danger panel is in the nav ---
dz_ok( "'danger' panel registered in nav", isset( EEM_Settings_Page::panels()['danger'] ), $pass, $fail, $log );

echo "\n=== Danger Zone wiring smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
