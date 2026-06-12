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
// Bound the no-!important check to the ORIGINAL C10.C restyle block (from its
// marker to the first later-version addition). The 2.3.60 quantity-stepper
// restyle legitimately uses !important to beat the legacy .eem-quantity-*
// !important rules + the host theme (documented inline in public.css).
$rb_start = strpos( $css, 'C10.C (2.3.53)' );
$rb_end   = strpos( $css, '2.3.60', $rb_start );
$rb_block = false !== $rb_end ? substr( $css, $rb_start, $rb_end - $rb_start ) : substr( $css, $rb_start );
ok( 'no !important in the C10.C restyle block', ! str_contains( $rb_block, '!important' ), $pass, $fail, $log );

/* Field inventory: render a published reservation that emits the full customer
 * form and assert the canonical payload set. The old hardcoded id=43 fixture was
 * deleted by later seed churn; resolve a live published reservation that actually
 * renders the form (first_name present) so the assertion tests real output rather
 * than a stale id. */
$sc  = new EEM_Shortcodes();
$rid = 0;
$candidates = get_posts( array(
	'post_type'      => 'en_reservation',
	'post_status'    => 'publish',
	'posts_per_page' => 40,
	'fields'         => 'ids',
) );
foreach ( $candidates as $cand ) {
	$probe = $sc->render_reservation( array( 'id' => $cand ) );
	if ( str_contains( $probe, 'name="first_name"' ) && str_contains( $probe, 'name="stall_qty"' ) ) {
		$rid = $cand;
		break;
	}
}
ok( 'found a published reservation that renders the full form', $rid > 0, $pass, $fail, $log );
$html = $rid ? $sc->render_reservation( array( 'id' => $rid ) ) : '';
preg_match_all( '/\sname="([^"]+)"/', $html, $names );
$set = array_values( array_unique( $names[1] ) );
// rv_qty is section-conditional (only present when an RV section is enabled in
// quantity mode), so it is NOT a universal required field — the picked
// reservation may be stall-only. Keep stall_qty as the representative qty field.
$required = array( 'first_name', 'last_name', 'email', 'phone', 'stall_qty', 'stripe_payment_intent_id', 'en_reservation_nonce', 'billing_first_name', 'billing_postal_code' );
foreach ( $required as $f ) {
	ok( "form still submits field name={$f}", in_array( $f, $set, true ), $pass, $fail, $log );
}
ok( 'form preserves the Stripe intent hidden input', str_contains( $html, 'name="stripe_payment_intent_id"' ), $pass, $fail, $log );
ok( 'JS-critical class .eem-reservation-section preserved', str_contains( $html, 'eem-reservation-section' ), $pass, $fail, $log );
ok( 'JS-critical class .eem-reservation-form preserved', str_contains( $html, 'eem-reservation-form' ), $pass, $fail, $log );

/* ── Part 2: Settings Integrations ── */
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admin ) { wp_set_current_user( $admin[0]->ID ); }

// Native Events shipped (2.7.234) — no longer "Coming Soon". GEMS (the 'feed'
// source) is un-gated when the GEMS for WordPress connection is configured
// (2.7.156). So the only remaining coming-soon source is Feed, and only when
// GEMS is NOT configured. Source ORDER is always TEC, GEMS(feed), Native.
$gems_ready = class_exists( 'EEM_Gems_Client' ) && EEM_Gems_Client::is_configured();
$soon_count = $gems_ready ? 0 : 1;

// Render with a known active source (tec) so the per-source detail-panel
// visibility assertions below are deterministic regardless of the box's saved
// source. Capture the TRUE original first; restored at the end.
$integ_orig = get_option( 'equine_event_manager_integration_settings' );
update_option( 'equine_event_manager_integration_settings', array_merge( (array) $integ_orig, array( 'default_event_source' => 'tec' ) ) );

$sp = new EEM_Settings_Page();
$m  = new ReflectionMethod( 'EEM_Settings_Page', 'render_integrations_panel' );
$m->setAccessible( true );
ob_start(); $m->invoke( $sp ); $shtml = ob_get_clean();

preg_match_all( '/data-eem-source-value="([a-z]+)"/', $shtml, $order );
ok( 'event source order is TEC, GEMS(feed), Native', array( 'tec', 'feed', 'native' ) === $order[1], $pass, $fail, $log, implode( ',', $order[1] ) );
// Coming Soon pills: Feed only when GEMS is NOT configured (Native shipped).
ok( "{$soon_count} Coming Soon pill(s)", $soon_count === substr_count( $shtml, 'is-soon">Coming Soon' ), $pass, $fail, $log, substr_count( $shtml, 'is-soon">Coming Soon' ) );
ok( "{$soon_count} disabled source radio(s)", $soon_count === preg_match_all( '/<input type="radio"[^>]*disabled/', $shtml, $d ), $pass, $fail, $log );
// SendGrid is now an enabled, POSTing Email Delivery field — assert the current
// shipped behavior (not the old coming-soon/disabled state).
ok( 'SendGrid field is enabled (no disabled attr)', ! preg_match( '/id="eem-sendgrid"[^>]*disabled/', $shtml ), $pass, $fail, $log );
ok( 'SendGrid field POSTs under payload[sendgrid_api_key]', str_contains( $shtml, 'name="payload[sendgrid_api_key]"' ), $pass, $fail, $log );
ok( 'Email Delivery card is NOT flagged coming-soon', ! str_contains( $shtml, 'eem-card eem-card--coming-soon' ), $pass, $fail, $log );

/* Save-preserve: a save that omits sendgrid_api_key must NOT wipe an existing key. */
update_option( 'equine_event_manager_integration_settings', array( 'default_event_source' => 'tec', 'sendgrid_api_key' => 'SG.SMOKE_PRESERVE' ) );
$sv = new ReflectionMethod( 'EEM_Settings_Page', 'save_integrations_panel' );
$sv->setAccessible( true );
$sv->invoke( $sp, array( 'source' => 'tec' ) );
$saved = get_option( 'equine_event_manager_integration_settings' );
ok( 'disabled SendGrid key preserved across save', 'SG.SMOKE_PRESERVE' === $saved['sendgrid_api_key'], $pass, $fail, $log, $saved['sendgrid_api_key'] );
update_option( 'equine_event_manager_integration_settings', $integ_orig ); // restore the TRUE original source/settings
ok( 'native detail panel hidden', (bool) preg_match( '/data-eem-source-detail="native"[^>]*hidden/', $shtml ), $pass, $fail, $log );
ok( 'feed detail panel hidden', (bool) preg_match( '/data-eem-source-detail="feed"[^>]*hidden/', $shtml ), $pass, $fail, $log );
ok( 'tec detail panel visible', ! preg_match( '/data-eem-source-detail="tec"[^>]*hidden/', $shtml ), $pass, $fail, $log );
ok( 'Coming Soon pill style exists in admin.css', str_contains( file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' ), '.eem-source-status.is-soon' ), $pass, $fail, $log );

/* Version bump */
ok( 'plugin version is >= 2.3.53', version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.53', '>=' ), $pass, $fail, $log, EQUINE_EVENT_MANAGER_VERSION );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
