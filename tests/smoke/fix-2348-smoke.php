<?php
/**
 * 2.3.48 — Edit-Reservation section toggles persist their OFF state.
 *
 * Reported bug: after saving an Edit Reservation with all toggles OFF, three
 * sections flipped back ENABLED on reload — Check-In/Check-Out, General
 * Add-Ons, and Agreement. The other seven toggles persisted OFF correctly
 * (Event Pre-Entries was already fixed in 2.3.47).
 *
 * ROOT CAUSE (read-path, not write-path): the SAVE path already stored 0
 * correctly. get_meta_values() carried legacy auto-enable backfills that
 * re-derived the flag = 1 from the section's own data (which legitimately
 * persists — check-in times, add-on rows, the uploaded agreement file). On
 * every reload the backfill overrode the stored 0. The other seven sections
 * had no such data-presence backfill, so they were unaffected.
 *
 * FIX:
 *   1. READ path — guard the three backfills with metadata_exists(): only
 *      infer the flag for pre-toggle-era reservations that NEVER stored it.
 *      Once a save has written the flag (incl. an explicit 0), respect it.
 *      Mirrors the existing _en_use_global_event_source guard.
 *   2. WRITE path (defense in depth) — the three sanitize keys are value-aware
 *      (isset && '1' === value) so a literal "0" in the POST also yields 0,
 *      not just an absent field.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function fix2348_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== 2.3.48 — TOGGLE OFF-PERSISTENCE SMOKE ===\n";

wp_set_current_user( 1 );
$cpt = new EEM_Reservations_CPT();

// Strip comments before any source-pattern scan — the audit-trail comments
// mention isset(), metadata_exists(), the backfill rationale, etc.
$cpt_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-reservations-cpt.php' );
$strip_php = function ( $s ) {
	$s = preg_replace( '~/\*.*?\*/~s', '', $s );
	$s = preg_replace( '~//[^\n]*~', '', $s );
	return $s;
};
$cpt_nocom = $strip_php( $cpt_src );

$three = array( 'checkin_checkout', 'general_addons', 'venue_agreement' );

// ── WRITE PATH — sanitizer is value-aware for the three keys ──────
echo "\n[Write] sanitize_meta_submission: absent → 0, \"1\" → 1, \"0\" → 0\n";

$absent = $cpt->sanitize_meta_submission( array(), array() );
foreach ( $three as $k ) {
	fix2348_ok( "sanitizer: {$k}_enabled ABSENT → 0",
		isset( $absent[ "{$k}_enabled" ] ) && 0 === (int) $absent[ "{$k}_enabled" ],
		$pass, $fail, $log, var_export( $absent[ "{$k}_enabled" ] ?? null, true ) );
}

$on = $cpt->sanitize_meta_submission( array(
	'checkin_checkout_enabled' => '1',
	'general_addons_enabled'   => '1',
	'venue_agreement_enabled'  => '1',
), array() );
foreach ( $three as $k ) {
	fix2348_ok( "sanitizer: {$k}_enabled = \"1\" → 1",
		1 === (int) $on[ "{$k}_enabled" ],
		$pass, $fail, $log, var_export( $on[ "{$k}_enabled" ], true ) );
}

$zero = $cpt->sanitize_meta_submission( array(
	'checkin_checkout_enabled' => '0',
	'general_addons_enabled'   => '0',
	'venue_agreement_enabled'  => '0',
), array() );
foreach ( $three as $k ) {
	fix2348_ok( "sanitizer: {$k}_enabled = \"0\" → 0 (presence is not 'on')",
		0 === (int) $zero[ "{$k}_enabled" ],
		$pass, $fail, $log, var_export( $zero[ "{$k}_enabled" ], true ) );
}

// ── READ PATH — the real bug-catch: explicit 0 survives reload ────
echo "\n[Read] get_meta_values: stored 0 + section data present → stays 0\n";

$rid = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => '2.3.48 ToggleOff ' . wp_generate_password( 6, false, false ),
) );

// Toggles explicitly OFF, but the section data legitimately persists — this is
// exactly the state that used to flip the flags back ON.
update_post_meta( $rid, '_en_checkin_checkout_enabled', 0 );
update_post_meta( $rid, '_en_checkin_time', '08:00' );
update_post_meta( $rid, '_en_checkout_time', '17:00' );
update_post_meta( $rid, '_en_general_addons_enabled', 0 );
update_post_meta( $rid, '_en_general_addons', array( array( 'name' => 'Shavings', 'price' => '10.00' ) ) );
update_post_meta( $rid, '_en_venue_agreement_enabled', 0 );
update_post_meta( $rid, '_en_venue_agreement_file_id', 4242 );

$v = $cpt->get_meta_values( $rid );
foreach ( $three as $k ) {
	fix2348_ok( "read: {$k}_enabled stored 0 + data present → 0 on reload",
		0 === (int) $v[ "{$k}_enabled" ],
		$pass, $fail, $log, var_export( $v[ "{$k}_enabled" ], true ) );
}

// ── READ PATH — legacy inference still fires when flag NEVER stored ─
echo "\n[Read] legacy backfill: flag NEVER stored + data present → 1\n";

$leg = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => '2.3.48 Legacy ' . wp_generate_password( 6, false, false ),
) );
// No *_enabled meta written at all (pre-toggle-era), only the section data.
update_post_meta( $leg, '_en_general_addons', array( array( 'name' => 'Mats', 'price' => '5.00' ) ) );
update_post_meta( $leg, '_en_venue_agreement_file_id', 99 );

$lv = $cpt->get_meta_values( $leg );
fix2348_ok( 'read: general_addons never stored + rows present → backfill 1',
	1 === (int) $lv['general_addons_enabled'],
	$pass, $fail, $log, var_export( $lv['general_addons_enabled'], true ) );
fix2348_ok( 'read: venue_agreement never stored + file_id present → backfill 1',
	1 === (int) $lv['venue_agreement_enabled'],
	$pass, $fail, $log, var_export( $lv['venue_agreement_enabled'], true ) );

// ── DEFENSIVE — all 10 section toggles stored 0 → all read back 0 ─
echo "\n[Defensive] all 10 toggles stored 0 (+ realistic data) → all 0\n";

$all = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => '2.3.48 All10 ' . wp_generate_password( 6, false, false ),
) );
$ten = array(
	'checkin_checkout_enabled',
	'general_addons_enabled',
	'group_reservations_enabled',
	'convenience_fee_enabled',
	'venue_agreement_enabled',
	'stalls_enabled',
	'rv_enabled',
	'event_pre_entries_enabled',
	'event_day_enabled',
	'cancellation_enabled',
);
foreach ( $ten as $k ) {
	update_post_meta( $all, '_en_' . $k, 0 );
}
// Data that would trip an unguarded backfill, plus the convenience_fee_type
// reset that disarms the (intentionally unguarded) fee self-heal.
update_post_meta( $all, '_en_checkin_time', '09:00' );
update_post_meta( $all, '_en_general_addons', array( array( 'name' => 'X', 'price' => '1.00' ) ) );
update_post_meta( $all, '_en_venue_agreement_file_id', 7 );
update_post_meta( $all, '_en_convenience_fee_type', 'none' );

$av = $cpt->get_meta_values( $all );
foreach ( $ten as $k ) {
	fix2348_ok( "defensive: {$k} stored 0 → reads 0",
		0 === (int) $av[ $k ],
		$pass, $fail, $log, var_export( $av[ $k ], true ) );
}

// ── SOURCE CONTRACT — guards + value-aware writes are in place ────
echo "\n[Source] read-path guards + write-path value-awareness\n";

// Post-refactor (tasks #77-81: section-enabled key resolver + mig-007 rename),
// the three read-path backfills no longer inline `metadata_exists( '_en_<field>' )`
// — the guard routes through the new resolver helper
// `! self::section_enabled_exists( $post_id, '<field>_enabled' )`. Behavior is
// preserved (the runtime read-path assertions above still pass), so this is the
// current shape of the same OFF-persistence guard.
foreach ( array(
	'general_addons_enabled',
	'checkin_checkout_enabled',
	'venue_agreement_enabled',
) as $field ) {
	fix2348_ok( "backfill for {$field} is section_enabled_exists()-guarded (post-#77 resolver)",
		(bool) preg_match(
			"~!\s*self::section_enabled_exists\(\s*\\\$post_id,\s*'" . preg_quote( $field, '~' ) . "'\s*\)~",
			$cpt_nocom
		),
		$pass, $fail, $log );
}

// The resolver helper itself still anchors on metadata_exists() against BOTH the
// canonical `_eem_section_enabled_<shortkey>` key and the legacy `_en_<field>`
// key — this is where the real "only infer when never stored" contract now lives.
fix2348_ok( 'section_enabled_exists() checks canonical + legacy keys via metadata_exists()',
	(bool) preg_match(
		"~function section_enabled_exists\([^)]*\)[^{]*\{\s*return\s*metadata_exists\(\s*'post',\s*\\\$post_id,\s*self::section_enabled_meta_key\(\s*\\\$field\s*\)\s*\)\s*\|\|\s*metadata_exists\(\s*'post',\s*\\\$post_id,\s*'_en_'\s*\.\s*\\\$field\s*\)~",
		$cpt_nocom
	),
	$pass, $fail, $log );

foreach ( $three as $k ) {
	fix2348_ok( "sanitize: {$k}_enabled is value-aware ( isset && '1' === )",
		(bool) preg_match(
			"~'{$k}_enabled'\s*=>\s*\(\s*isset\(\s*\\\$source\['{$k}_enabled'\]\s*\)\s*&&\s*'1'\s*===~",
			$cpt_nocom
		),
		$pass, $fail, $log );
}

// ── Cleanup ──────────────────────────────────────────────────────
wp_delete_post( $rid, true );
wp_delete_post( $leg, true );
wp_delete_post( $all, true );

// ── Cache-bust ───────────────────────────────────────────────────
echo "\n[Cache-bust] EQUINE_EVENT_MANAGER_VERSION >= 2.3.48\n";
fix2348_ok( 'EQUINE_EVENT_MANAGER_VERSION >= 2.3.48',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.48', '>=' ),
	$pass, $fail, $log, EQUINE_EVENT_MANAGER_VERSION );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
