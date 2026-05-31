<?php
/**
 * 2.3.54 smoke — event page renders event info (hero) only when no reservation
 * is linked. The previous `.eem-event-body` fallback (which re-printed the event
 * description below the hero, duplicating the hero copy) must be gone.
 */
$pass = 0; $fail = 0; $log = array();
function ok( $label, $cond, &$pass, &$fail, &$log, $extra = '' ) {
	if ( $cond ) { $pass++; $log[] = "  ok  - {$label}"; }
	else { $fail++; $log[] = "FAIL  - {$label}" . ( $extra ? " ({$extra})" : '' ); }
}

/* Source guard: the elseif body-fallback branch is removed. */
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-events.php' );
ok( 'event markup no longer emits the .eem-event-body fallback', ! preg_match( '/elseif \( \$show_content && ! empty\( \$event_data\[\'content_raw\'\] \) \)/', $src ), $pass, $fail, $log );

/* Render guard: reservation-less event_data → hero yes, body no, form no. */
$ev = new EEM_Events();
$r  = new ReflectionMethod( 'EEM_Events', 'render_normalized_event_markup' );
$r->setAccessible( true );
$event_data = array(
	'event_id'       => 999,
	'reservation_id' => 0,
	'title'          => 'Unlinked Demo Event',
	'start_date'     => '2026-07-01',
	'end_date'       => '2026-07-03',
	'content_raw'    => '<p>Body paragraph that must not be re-printed in a .eem-event-body block.</p>',
	'venue'          => array(), 'producer' => array(), 'categories' => array(),
	'venue_name'     => 'Demo Venue', 'location' => 'Demo City',
);
$h = $r->invoke( $ev, $event_data, true, true );
ok( 'unlinked event renders the hero (event info)', str_contains( $h, 'class="hero"' ), $pass, $fail, $log );
ok( 'unlinked event renders NO .eem-event-body block', ! str_contains( $h, 'class="eem-event-body"' ), $pass, $fail, $log );
ok( 'unlinked event mounts NO reservation form', ! str_contains( $h, 'eem-reservation-form-wrap' ), $pass, $fail, $log );

/* Linked event (#9 ↔ res 43) still mounts the form, no body. */
$h9 = do_shortcode( '[equine_event_manager_event id="9" show_content="1" show_reservation="1"]' );
ok( 'linked event #9 mounts the reservation form', str_contains( $h9, 'eem-reservation-form-wrap' ), $pass, $fail, $log );
ok( 'linked event #9 renders NO .eem-event-body block', ! str_contains( $h9, 'class="eem-event-body"' ), $pass, $fail, $log );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
