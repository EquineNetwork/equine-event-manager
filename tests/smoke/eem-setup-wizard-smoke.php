<?php
/**
 * First-run setup wizard (EEM_Setup_Wizard) guard.
 *
 * Verifies the guided modal renders all five steps with the smart-auto-open
 * data attributes + navigation wiring, that "required complete" ignores the
 * optional SendGrid step, and that the JS opener/handlers + every CSS class the
 * modal uses are present (C7.X.20 invisible-modal guard).
 */

$pass = 0; $fail = 0; $log = array();
function wz_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

// --- Render markup ---
ob_start();
EEM_Setup_Wizard::render();
$html = (string) ob_get_clean();

wz_ok( 'wizard modal root present',            false !== strpos( $html, 'id="eem-setup-wizard"' ),               $pass, $fail, $log );
wz_ok( 'uses .eem-modal chrome',                false !== strpos( $html, 'class="eem-modal eem-setup-wizard"' ), $pass, $fail, $log );
wz_ok( 'carries autoopen flag',                 false !== strpos( $html, 'data-eem-wizard-autoopen="1"' ),       $pass, $fail, $log );
wz_ok( 'carries start-step attr',               (bool) preg_match( '/data-eem-wizard-start="\d+"/', $html ),     $pass, $fail, $log );
wz_ok( 'carries done-count attr',               (bool) preg_match( '/data-eem-wizard-done-count="\d+"/', $html ),$pass, $fail, $log );

// Five steps + five dots (matches EEM_Setup_Checklist::items()).
$step_count = preg_match_all( '/class="eem-setup-wizard__step"/', $html );
$dot_count  = preg_match_all( '/class="eem-setup-wizard__dot[ "]/', $html );
wz_ok( 'renders 5 step panels', 5 === $step_count, $pass, $fail, $log );
wz_ok( 'renders 5 progress dots', 5 === $dot_count, $pass, $fail, $log );

// Self-contained: each step maps to a Settings panel + has its real fields inline.
$panel_count = preg_match_all( '/data-eem-wizard-panel="[a-z]+"/', $html );
wz_ok( 'every step maps to a settings panel (5)', 5 === $panel_count, $pass, $fail, $log );
wz_ok( 'each step carries an inline fields container + error slot',
	5 === preg_match_all( '/eem-setup-wizard__fields/', $html )
	&& 5 === preg_match_all( '/data-eem-wizard-error/', $html ),
	$pass, $fail, $log );
// The actual inputs that save to each panel (reusing the settings payload shape).
wz_ok( 'event source: payload[source] radio',         false !== strpos( $html, 'name="payload[source]"' ),                   $pass, $fail, $log );
wz_ok( 'branding: logo picker + support_email',       false !== strpos( $html, 'data-eem-logo-upload' ) && false !== strpos( $html, 'name="payload[support_email]"' ), $pass, $fail, $log );
wz_ok( 'communications: from_name + from_email',      false !== strpos( $html, 'name="payload[sender][from_name]"' ) && false !== strpos( $html, 'name="payload[sender][from_email]"' ), $pass, $fail, $log );
wz_ok( 'payments: stripe gateway + key fields',       false !== strpos( $html, 'value="stripe"' ) && false !== strpos( $html, 'name="payload[stripe][test_secret_key]"' ), $pass, $fail, $log );
wz_ok( 'sendgrid: api key field',                     false !== strpos( $html, 'name="payload[sendgrid_api_key]"' ),          $pass, $fail, $log );
wz_ok( 'carries the settings-save nonce',             (bool) preg_match( '/data-eem-settings-nonce="[a-f0-9]+"/', $html ),     $pass, $fail, $log );
wz_ok( 'Save & Continue + Back + close buttons present',
	false !== strpos( $html, 'data-eem-action="setup-wizard-save"' )
	&& false !== strpos( $html, 'data-eem-action="setup-wizard-back"' )
	&& false !== strpos( $html, 'data-eem-action="setup-wizard-close"' ),
	$pass, $fail, $log );
wz_ok( 'first step shows the welcome intro', false !== strpos( $html, 'eem-setup-wizard__intro' ), $pass, $fail, $log );

// --- Required-vs-optional logic: SendGrid is NOT counted as required ---
$by_key = array();
foreach ( EEM_Setup_Checklist::items() as $item ) {
	$by_key[ $item['key'] ] = ! empty( $item['done'] );
}
$manual_required = 0;
foreach ( array( 'event_source', 'branding', 'communications', 'payments' ) as $k ) {
	if ( ! empty( $by_key[ $k ] ) ) { $manual_required++; }
}
wz_ok( 'required_done_count counts only the 4 required keys', EEM_Setup_Wizard::required_done_count() === $manual_required, $pass, $fail, $log );
wz_ok( 'required_complete === (count >= 4)', EEM_Setup_Wizard::required_complete() === ( $manual_required >= 4 ), $pass, $fail, $log );
wz_ok( 'REQUIRED_KEYS excludes sendgrid', ! in_array( 'sendgrid', EEM_Setup_Wizard::REQUIRED_KEYS, true ), $pass, $fail, $log );
wz_ok( 'REQUIRED_KEYS is the 4 areas', EEM_Setup_Wizard::REQUIRED_KEYS === array( 'event_source', 'branding', 'communications', 'payments' ), $pass, $fail, $log );

// --- JS opener + action handlers + CSS class parity ---
$js  = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$css = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );

wz_ok( 'JS defines eemWizardMaybeAutoOpen', false !== strpos( $js, 'function eemWizardMaybeAutoOpen' ), $pass, $fail, $log );
wz_ok( 'JS smart-reopen keys on done-count', false !== strpos( $js, 'eemWizardClosedAtCount' ), $pass, $fail, $log );
foreach ( array( 'setup-wizard-save', 'setup-wizard-back', 'setup-wizard-goto', 'setup-wizard-close' ) as $act ) {
	wz_ok( "JS action-map wires $act", false !== strpos( $js, "'$act':" ), $pass, $fail, $log );
}
wz_ok( 'JS saves a step via eem_save_settings', false !== strpos( $js, 'function eemWizardSaveStep' ) && false !== strpos( $js, "'eem_save_settings'" ), $pass, $fail, $log );
wz_ok( 'JS marks a step done + reloads on finish', false !== strpos( $js, 'function eemWizardMarkStepDone' ) && false !== strpos( $js, 'window.location.reload' ), $pass, $fail, $log );
foreach ( array( 'eem-setup-wizard__card', 'eem-setup-wizard__dot', 'eem-setup-wizard__step-num', 'eem-setup-wizard__badge--done', 'eem-setup-wizard__hint', 'eem-modal', 'eem-modal-card', 'eem-modal-head', 'eem-modal-body', 'eem-modal-foot' ) as $cls ) {
	wz_ok( "CSS class .$cls exists", false !== strpos( $css, '.' . $cls ), $pass, $fail, $log );
}

// --- Page gating: print view excluded, normal EEM pages included ---
$is_eem = new ReflectionMethod( 'EEM_Setup_Wizard', 'is_eem_admin_page' );
$is_eem->setAccessible( true );
$saved_get = $_GET;
$_GET['page'] = 'equine-event-manager-stall-chart-print';
wz_ok( 'wizard NOT shown on the stall-chart print view', false === $is_eem->invoke( null ), $pass, $fail, $log );
$_GET['page'] = 'equine-event-manager-dashboard';
wz_ok( 'wizard IS eligible on a normal EEM page', true === $is_eem->invoke( null ), $pass, $fail, $log );
$_GET = $saved_get;

// --- Loaded + registered ---
wz_ok( 'wizard registers on admin_footer', false !== strpos( (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-eem-setup-wizard.php' ), "add_action( 'admin_footer'" ), $pass, $fail, $log );

echo "\n=== Setup wizard smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
