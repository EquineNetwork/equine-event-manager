<?php
/**
 * 2.3.47 — three-bug-fix smoke.
 *
 * FIX 1 — [en_reservation] auto-resolves the reservation from the current
 *         TEC event context when no id="" is passed (explicit id wins);
 *         a bare tag with no resolvable context renders an HTML comment
 *         only. Covered by the new inverse resolver
 *         EEM_Reservations_CPT::get_reservation_id_for_tec_event() plus
 *         shortcode source-pattern assertions.
 *
 * FIX 2 — Section enable toggles persist their OFF state. The Event
 *         Pre-Entries flag (routed through the bare eem_ namespace) is now
 *         written UNCONDITIONALLY in ajax_save (absent/not-"1" = 0); the
 *         en_reservation[]-routed toggles already persist OFF via the CPT
 *         sanitizer's isset()?1:0 (proven functionally below).
 *
 * FIX 3 — validate_for_publish() honors the typed cancellation override
 *         unconditionally. Previously it only read the override INSIDE a
 *         class_exists( 'EEM_Cancellation_Policy' ) guard, but that class
 *         is defined nowhere, so the gate errored on every publish even
 *         when the admin had filled in the override.
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function fix2347_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== 2.3.47 — THREE-FIX SMOKE ===\n";

wp_set_current_user( 1 );

$shortcode_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
$page_src      = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-eem-reservation-editor-page.php' );

// Strip block + line comments before any absence/presence token scan — the
// audit-trail comments mention isset(), the missing class name, etc.
$strip_php = function ( $s ) {
	$s = preg_replace( '~/\*.*?\*/~s', '', $s );
	$s = preg_replace( '~//[^\n]*~', '', $s );
	return $s;
};
$shortcode_nocom = $strip_php( $shortcode_src );
$page_nocom      = $strip_php( $page_src );

// ── FIX 1 — TEC-context resolver ─────────────────────────────────
echo "\n[FIX 1] Shortcode resolves reservation from TEC event context\n";

fix2347_ok( 'EEM_Reservations_CPT::get_reservation_id_for_tec_event() exists',
	method_exists( 'EEM_Reservations_CPT', 'get_reservation_id_for_tec_event' ),
	$pass, $fail, $log );

// Clean stale fixtures.
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'any', 'posts_per_page' => -1, 's' => '2.3.47 Resolver' ) ) as $stale ) {
	wp_delete_post( $stale->ID, true );
}
$rid = wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => '2.3.47 Resolver ' . wp_generate_password( 6, false, false ) ) );
// A meta-holder post standing in for the TEC event (resolver reads the link
// meta off the event id regardless of the event's own post type).
$eid_linked = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => '2.3.47 Resolver event' ) );
update_post_meta( $eid_linked, '_equine_event_manager_reservation_id', $rid );

$cpt = new EEM_Reservations_CPT();

fix2347_ok( 'resolver: linked event → reservation id',
	$cpt->get_reservation_id_for_tec_event( $eid_linked ) === $rid,
	$pass, $fail, $log, 'got ' . var_export( $cpt->get_reservation_id_for_tec_event( $eid_linked ), true ) );

fix2347_ok( 'resolver: event id 0 → 0 (no context)',
	$cpt->get_reservation_id_for_tec_event( 0 ) === 0,
	$pass, $fail, $log );

$eid_unlinked = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => '2.3.47 Resolver unlinked' ) );
fix2347_ok( 'resolver: event with no link meta → 0',
	$cpt->get_reservation_id_for_tec_event( $eid_unlinked ) === 0,
	$pass, $fail, $log );

// Stale link pointing at a non-reservation post → 0 (link no longer valid).
$eid_stale = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => '2.3.47 Resolver stale' ) );
update_post_meta( $eid_stale, '_equine_event_manager_reservation_id', $eid_unlinked );
fix2347_ok( 'resolver: link pointing at non-en_reservation post → 0',
	$cpt->get_reservation_id_for_tec_event( $eid_stale ) === 0,
	$pass, $fail, $log );

// Shortcode source contract.
fix2347_ok( 'render_reservation gates auto-resolve on is_singular( tribe_events )',
	false !== strpos( $shortcode_nocom, "is_singular( 'tribe_events' )" ),
	$pass, $fail, $log );
fix2347_ok( 'render_reservation calls get_reservation_id_for_tec_event()',
	false !== strpos( $shortcode_nocom, 'get_reservation_id_for_tec_event(' ),
	$pass, $fail, $log );
fix2347_ok( 'render_reservation emits silent "no reservation context" comment',
	false !== strpos( $shortcode_src, '<!-- eem: no reservation context -->' ),
	$pass, $fail, $log );
fix2347_ok( 'render_reservation emits silent "no reservation linked" comment',
	false !== strpos( $shortcode_src, '<!-- eem: no reservation linked to this event -->' ),
	$pass, $fail, $log );
fix2347_ok( 'render_reservation preserves explicit-id-wins ($explicit_id)',
	false !== strpos( $shortcode_nocom, '$explicit_id' ),
	$pass, $fail, $log );

// ── FIX 2 — toggle OFF persistence ───────────────────────────────
echo "\n[FIX 2] Section toggles persist OFF state\n";

// Event Pre-Entries flag write is now UNCONDITIONAL (no isset() guard wrap).
fix2347_ok( 'ajax_save: event_pre_entries_enabled written unconditionally (absent/not-"1" = 0)',
	(bool) preg_match(
		"~update_post_meta\(\s*\\\$reservation_id,\s*'_en_event_pre_entries_enabled',\s*isset\(\s*\\\$_POST\['eem_event_pre_entries_enabled'\]\s*\)\s*&&\s*'1'\s*===~",
		$page_nocom
	),
	$pass, $fail, $log );
fix2347_ok( 'ajax_save: event_pre_entries_enabled write is NOT isset()-guarded out',
	false === strpos( $page_nocom, "if ( isset( \$_POST['eem_event_pre_entries_enabled'] ) ) {" ),
	$pass, $fail, $log );

// en_reservation[]-routed toggles persist OFF via the CPT sanitizer:
// absent key → 0 (proves checkin / addons / agreement once the save runs).
$absent = $cpt->sanitize_meta_submission( array(), array() );
fix2347_ok( 'sanitizer: checkin_checkout_enabled absent → 0',
	isset( $absent['checkin_checkout_enabled'] ) && 0 === (int) $absent['checkin_checkout_enabled'],
	$pass, $fail, $log );
fix2347_ok( 'sanitizer: general_addons_enabled absent → 0',
	isset( $absent['general_addons_enabled'] ) && 0 === (int) $absent['general_addons_enabled'],
	$pass, $fail, $log );
fix2347_ok( 'sanitizer: venue_agreement_enabled absent → 0',
	isset( $absent['venue_agreement_enabled'] ) && 0 === (int) $absent['venue_agreement_enabled'],
	$pass, $fail, $log );
// Present → 1 (toggle ON round-trips).
$present = $cpt->sanitize_meta_submission( array(
	'checkin_checkout_enabled' => '1',
	'general_addons_enabled'   => '1',
	'venue_agreement_enabled'  => '1',
), array() );
fix2347_ok( 'sanitizer: toggles present → 1 (ON persists)',
	1 === (int) $present['checkin_checkout_enabled']
	&& 1 === (int) $present['general_addons_enabled']
	&& 1 === (int) $present['venue_agreement_enabled'],
	$pass, $fail, $log );

// ── FIX 3 — cancellation override validation ─────────────────────
echo "\n[FIX 3] Cancellation override honored without the phantom class gate\n";

// Typed override → NO cancellation error (the reported bug).
$errs_override = EEM_Reservation_Editor_Page::validate_for_publish( array(
	'cancellation_enabled'         => 1,
	'cancellation_policy_override' => 'Full refund up to 14 days before the event.',
), $rid );
fix2347_ok( 'validator: cancellation enabled + typed override → no cancellation error',
	! isset( $errs_override['cancellation'] ),
	$pass, $fail, $log, 'errs=' . var_export( $errs_override, true ) );

// Empty override + no event default → cancellation error still fires.
$errs_empty = EEM_Reservation_Editor_Page::validate_for_publish( array(
	'cancellation_enabled'         => 1,
	'cancellation_policy_override' => '   ',
), $rid );
fix2347_ok( 'validator: cancellation enabled + empty override → cancellation error',
	isset( $errs_empty['cancellation'] ),
	$pass, $fail, $log );

// Section disabled → never errors.
$errs_off = EEM_Reservation_Editor_Page::validate_for_publish( array(
	'cancellation_enabled' => 0,
), $rid );
fix2347_ok( 'validator: cancellation disabled → no cancellation error',
	! isset( $errs_off['cancellation'] ),
	$pass, $fail, $log );

// The override read must no longer be nested inside the class_exists guard.
fix2347_ok( 'validate_for_publish reads override before the (optional) resolver fallback',
	(bool) preg_match(
		"~\\\$resolved\s*=\s*isset\(\s*\\\$c\['cancellation_policy_override'\]\s*\)~",
		$page_nocom
	),
	$pass, $fail, $log );

// ── Cleanup ──────────────────────────────────────────────────────
wp_delete_post( $rid, true );
wp_delete_post( $eid_linked, true );
wp_delete_post( $eid_unlinked, true );
wp_delete_post( $eid_stale, true );

// ── [Cache-bust] ─────────────────────────────────────────────────
echo "\n[Cache-bust] EQUINE_EVENT_MANAGER_VERSION >= 2.3.47\n";
fix2347_ok( 'EQUINE_EVENT_MANAGER_VERSION >= 2.3.47',
	version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.47', '>=' ),
	$pass, $fail, $log, EQUINE_EVENT_MANAGER_VERSION );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
