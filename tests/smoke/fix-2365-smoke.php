<?php
/**
 * 2.3.65 — C10.E batch smoke.
 *
 * Covers the two runtime/save-path changes in the 2.3.65 batch that source
 * presence cannot prove:
 *   1. Gate-robustness — a reservation save that submits event_id=0 (the value
 *      the editor's hidden field carries whenever the link gate is showing) must
 *      NOT orphan an existing TEC event link. Unlinking is only ever the explicit
 *      ajax_unlink_event action.
 *   2. format_us_phone_display() normalizes 10/11-digit numbers to XXX-XXX-XXXX.
 *
 * The remaining 2.3.65 items are CSS/markup (verified in-browser) and are not
 * asserted here.
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function f2365_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ok  - {$l}"; }
	else      { $f++; $lg[] = "FAIL  - {$l}" . ( $d ? " ({$d})" : '' ); }
}

echo "\n=== 2.3.65 — gate-robustness + phone format smoke ===\n";

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admin ) { wp_set_current_user( $admin[0]->ID ); }

/* ── 1. Phone formatter (Reflection on the private static helper) ── */
$pm = new ReflectionMethod( 'EEM_Events', 'format_us_phone_display' );
$pm->setAccessible( true );
f2365_ok( '10-digit → dashes', '559-393-5352' === $pm->invoke( null, '5593935352' ), $pass, $fail, $log, $pm->invoke( null, '5593935352' ) );
f2365_ok( '11-digit 1-prefixed → dashes', '559-393-5352' === $pm->invoke( null, '15593935352' ), $pass, $fail, $log, $pm->invoke( null, '15593935352' ) );
f2365_ok( 'already-formatted passes through', '559-393-5352' === $pm->invoke( null, '559-393-5352' ), $pass, $fail, $log, $pm->invoke( null, '559-393-5352' ) );
f2365_ok( 'non-standard length left intact', '+44 20 7946' === $pm->invoke( null, '+44 20 7946' ), $pass, $fail, $log, $pm->invoke( null, '+44 20 7946' ) );

/* ── 2. Gate robustness: a save with event_id=0 must not orphan the link ── */
$rid       = 43;
$event_id  = absint( get_post_meta( $rid, '_en_event_id', true ) );
$rev_event = $event_id > 0 ? absint( get_post_meta( $event_id, '_equine_event_manager_reservation_id', true ) ) : 0;
f2365_ok( 'precondition: res 43 linked to event ' . $event_id, $event_id > 0, $pass, $fail, $log );
f2365_ok( 'precondition: event reverse-link points back to 43', $rev_event === $rid, $pass, $fail, $log, (string) $rev_event );

if ( $event_id > 0 ) {
	$cpt  = new EEM_Reservations_CPT();
	$post = get_post( $rid );

	// Simulate the editor posting a gated save: valid meta nonce + event_id=0.
	$_POST['equine_event_manager_reservation_meta_nonce'] = wp_create_nonce( 'equine_event_manager_save_reservation_meta' );
	$_POST['en_reservation'] = array( 'event_id' => '0' );

	$cpt->save_meta( $rid, $post );

	$after_event = absint( get_post_meta( $rid, '_en_event_id', true ) );
	$after_rev   = absint( get_post_meta( $event_id, '_equine_event_manager_reservation_id', true ) );
	f2365_ok( 'after event_id=0 save: _en_event_id preserved', $after_event === $event_id, $pass, $fail, $log, (string) $after_event );
	f2365_ok( 'after event_id=0 save: reverse link preserved', $after_rev === $rid, $pass, $fail, $log, (string) $after_rev );

	unset( $_POST['equine_event_manager_reservation_meta_nonce'], $_POST['en_reservation'] );
}

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
