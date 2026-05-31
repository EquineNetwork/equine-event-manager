<?php
/**
 * C10.C (2.3.53) smoke — customer form section restyle + Settings Integrations.
 *
 * Source-presence assertions only for the CSS restyle (computed/visual fidelity
 * needs a browser pass — see header note). The functional guarantee under test
 * is: the markup port did NOT change the submission payload (identical name=
 * field inventory) and the Settings Integrations Part-2 behavior renders as
 * specified.
 */

$pass = 0; $fail = 0; $log = array();
function ok( $label, $cond, &$pass, &$fail, &$log, $extra = '' ) {
	if ( $cond ) { $pass++; $log[] = "  ok  - {$label}"; }
	else { $fail++; $log[] = "FAIL  - {$label}" . ( $extra ? " ({$extra})" : '' ); }
}

/* ── Part 1: form restyle is CSS-only → field inventory unchanged ── */
$css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/public.css' );
ok( 'public.css carries the C10.C scoped restyle block', str_contains( $css, 'C10.C (2.3.53) — CUSTOMER FORM SECTION SHELLS' ), $pass, $fail, $log );
ok( 'section card chrome scoped under .eem-event-page', str_contains( $css, '.eem-event-page .eem-reservation-section {' ), $pass, $fail, $log );
ok( 'header band uppercases the section title', (bool) preg_match( '/\.eem-event-page \.eem-reservation-section__title \{[^}]*text-transform: uppercase/s', $css ), $pass, $fail, $log );
ok( 'toggle restyled to mockup blue', (bool) preg_match( '/checked \+ \.eem-reservation-section-toggle__track \{\s*background: #1668F2/s', $css ), $pass, $fail, $log );
ok( 'no !important added in the restyle block', ! preg_match( '/C10\.C.*?!important/s', substr( $css, strpos( $css, 'C10.C (2.3.53)' ) ) ), $pass, $fail, $log );

/* Field inventory: render res 43 and assert the canonical payload set. */
$rid = 43;
$sc  = new EEM_Shortcodes();
$html = $sc->render_reservation( array( 'id' => $rid ) );
preg_match_all( '/\sname="([^"]+)"/', $html, $names );
$set = array_values( array_unique( $names[1] ) );
$required = array( 'first_name', 'last_name', 'email', 'phone', 'stall_qty', 'rv_qty', 'stripe_payment_intent_id', 'en_reservation_nonce', 'billing_first_name', 'billing_postal_code' );
foreach ( $required as $f ) {
	ok( "form still submits field name={$f}", in_array( $f, $set, true ), $pass, $fail, $log );
}
ok( 'form preserves the Stripe intent hidden input', str_contains( $html, 'name="stripe_payment_intent_id"' ), $pass, $fail, $log );
ok( 'JS-critical class .eem-reservation-section preserved', str_contains( $html, 'eem-reservation-section' ), $pass, $fail, $log );
ok( 'JS-critical class .eem-reservation-form preserved', str_contains( $html, 'eem-reservation-form' ), $pass, $fail, $log );

/* ── Part 2: Settings Integrations ── */
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admin ) { wp_set_current_user( $admin[0]->ID ); }
$sp = new EEM_Settings_Page();
$m  = new ReflectionMethod( 'EEM_Settings_Page', 'render_integrations_panel' );
$m->setAccessible( true );
ob_start(); $m->invoke( $sp ); $shtml = ob_get_clean();

preg_match_all( '/data-eem-source-value="([a-z]+)"/', $shtml, $order );
ok( 'event source order is TEC, Native, Feed', array( 'tec', 'native', 'feed' ) === $order[1], $pass, $fail, $log, implode( ',', $order[1] ) );
ok( 'two Coming Soon pills rendered', 2 === substr_count( $shtml, 'is-soon">Coming Soon' ), $pass, $fail, $log );
ok( 'two disabled source radios', 2 === preg_match_all( '/<input type="radio"[^>]*disabled/', $shtml, $d ), $pass, $fail, $log );
ok( 'native detail panel hidden', (bool) preg_match( '/data-eem-source-detail="native"[^>]*hidden/', $shtml ), $pass, $fail, $log );
ok( 'feed detail panel hidden', (bool) preg_match( '/data-eem-source-detail="feed"[^>]*hidden/', $shtml ), $pass, $fail, $log );
ok( 'tec detail panel visible', ! preg_match( '/data-eem-source-detail="tec"[^>]*hidden/', $shtml ), $pass, $fail, $log );
ok( 'Coming Soon pill style exists in admin.css', str_contains( file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' ), '.eem-source-status.is-soon' ), $pass, $fail, $log );

/* Version bump */
ok( 'plugin version is >= 2.3.53', version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.53', '>=' ), $pass, $fail, $log, EQUINE_EVENT_MANAGER_VERSION );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
