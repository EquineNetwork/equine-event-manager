<?php
/**
 * v2 parity — Edit Reservation is the single source of truth.
 *
 * Covers two fixes from the walkthrough:
 *  1. A reservation offering only purchasable non-stall/RV sections (Group
 *     Reservations / Add-Ons / Pre-Entries) is still bookable — get_reservation_status()
 *     exposes `other_bookable`, and the form-vs-"not available" gate honors it.
 *     (Previously a group-only reservation showed "not available" on the front.)
 *  2. The customer contact-card "Group Name" field is gated behind the
 *     per-reservation Group Reservations toggle.
 *
 * Browser self-verify was performed both directions (group-only renders the form
 * with Group Name shown; stalls+RV-only hides Group Name) — these assertions are
 * the source/behavioral regression guard.
 */

$pass = 0; $fail = 0; $log = array();
function v2p_ok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

$sc  = new EEM_Shortcodes();
$ref = new ReflectionMethod( 'EEM_Shortcodes', 'get_reservation_status' );
$ref->setAccessible( true );

// Behavioral: other_bookable reflects group/addons/pre-entries enablement.
$base_off = array(
	'stalls_enabled' => 0, 'rv_enabled' => 0,
	'group_reservations_enabled' => 0, 'general_addons_enabled' => 0,
	'event_pre_entries_enabled' => 0, 'stall_inventory' => '', 'rv_inventory' => '',
);
try {
	$st = $ref->invoke( $sc, $base_off, 0 );
	v2p_ok( 'all sections off => other_bookable falsy', empty( $st['other_bookable'] ), $pass, $fail, $log );

	$g = $base_off; $g['group_reservations_enabled'] = 1;
	v2p_ok( 'group-only => other_bookable true', ! empty( $ref->invoke( $sc, $g, 0 )['other_bookable'] ), $pass, $fail, $log );

	$a = $base_off; $a['general_addons_enabled'] = 1;
	v2p_ok( 'addons-only => other_bookable true', ! empty( $ref->invoke( $sc, $a, 0 )['other_bookable'] ), $pass, $fail, $log );

	$p = $base_off; $p['event_pre_entries_enabled'] = 1;
	v2p_ok( 'pre-entries-only => other_bookable true', ! empty( $ref->invoke( $sc, $p, 0 )['other_bookable'] ), $pass, $fail, $log );
} catch ( Throwable $e ) {
	$fail++; $log[] = 'FAIL: get_reservation_status threw — ' . $e->getMessage();
}

// Source: the form-vs-notice gate honors other_bookable.
$src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
v2p_ok(
	'form gate includes other_bookable',
	(bool) preg_match( "/!\s*\\\$status\['stalls_bookable'\][\s\S]{0,160}other_bookable/", $src ),
	$pass, $fail, $log
);

// Source: Group Name field wrapped in group_reservations_enabled.
v2p_ok(
	'Group Name field gated behind group_reservations_enabled',
	(bool) preg_match( "/if\s*\(\s*\\\$group_reservations_enabled\s*\)\s*:\s*\?>\s*<label class=\"eem-group-name-field\"/", $src ),
	$pass, $fail, $log
);

// v2 #3 — section open-state persists across save+reload (admin.js).
// Source-presence guard; browser self-verified directly (Check-In/Check-Out
// stayed expanded after Update Reservation reloaded the page).
$js = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
v2p_ok( 'admin.js defines eemPersistAllSectionStates', false !== strpos( $js, 'function eemPersistAllSectionStates' ), $pass, $fail, $log );
v2p_ok(
	'section-state sweep runs before the save reload',
	(bool) preg_match( '/eemPersistAllSectionStates\(\);[\s\S]{0,200}window\.location\.reload/', $js ),
	$pass, $fail, $log
);

echo "\n=== v2 parity smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
