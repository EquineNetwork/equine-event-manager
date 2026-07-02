<?php
/**
 * Group-name dropdown smoke.
 *
 * The strict admin-defined group-name list lives in the config table, but the
 * customer form read it from stale post-meta — so the Group Name dropdown never
 * rendered even when names were defined. get_reservation_meta() now reads
 * group_names (+ group fee fields) from config. Also pins the "don't see your
 * group — call us at {support phone}" helper.
 *
 * Run: wp eval-file tests/smoke/group-name-dropdown-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// Seed a published reservation with group reservations enabled + a strict group
// list in the config table only (mirroring the v4 editor / an import).
$rid = (int) wp_insert_post( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'post_title' => 'Group dropdown smoke' ) );
EEM_Reservation_Config::for( $rid )->set_many( array(
	'group_reservations_enabled'      => 1,
	'group_names'                     => array( 'Team Alpha', 'Bravo Barn', 'Charlie Crew' ),
	'group_rider_grounds_fee_enabled' => 1,
	'group_rider_grounds_fee_amount'  => 100,
	'group_rider_deposit_enabled'     => 1,
	'group_rider_deposit_amount'      => 100,
) )->save();
EEM_Reservation_Config::flush_cache( $rid );

// 1. get_reservation_meta resolves group_names from config.
$sc = new EEM_Shortcodes();
$m  = new ReflectionMethod( 'EEM_Shortcodes', 'get_reservation_meta' );
$m->setAccessible( true );
$data = $m->invoke( $sc, $rid );
$check( 'get_reservation_meta returns group_names from config', isset( $data['group_names'] ) && is_array( $data['group_names'] ) && in_array( 'Team Alpha', $data['group_names'], true ) );

// 2. Rendered form has the group_name select + each option.
$html = do_shortcode( '[en_reservation id="' . $rid . '"]' );
$check( 'form renders the group_name select', false !== strpos( $html, 'name="group_name"' ) );
$check( 'option Team Alpha present', false !== strpos( $html, '>Team Alpha</option>' ) );
$check( 'option Bravo Barn present', false !== strpos( $html, '>Bravo Barn</option>' ) );
$check( 'option Charlie Crew present', false !== strpos( $html, '>Charlie Crew</option>' ) );

// 3. The "don't see your group — call us" helper renders. When a support phone is
//    set in branding, it is included; otherwise the no-phone variant shows.
$company = get_option( 'equine_event_manager_company_settings', array() );
$phone   = is_array( $company ) && ! empty( $company['support_phone'] ) ? (string) $company['support_phone'] : '';
$check( 'call-to-be-added helper renders', false !== strpos( $html, 'to be added' ) || false !== strpos( $html, 'contact us to be added' ) );
if ( '' !== $phone ) {
	$digits = preg_replace( '/\D+/', '', $phone );
	$check( 'helper includes the branding support phone', '' !== $digits && false !== strpos( preg_replace( '/\D+/', '', $html ), $digits ) );
}

// 4. Opt-in default (Whitney 2026-07-02): the group toggle is OFF by default so a
//    non-group customer checks out without hitting the rider-count requirement.
//    Rider count still defaults to 0 for when a group booker turns the section on.
$check( 'rider count input defaults to 0', false !== strpos( $html, 'name="group_rider_count" min="0" step="1" value="0"' ) );
$check( 'group toggle renders', false !== strpos( $html, 'data-eem-group-toggle' ) );
$check( 'group toggle is OFF by default (opt-in)', false === strpos( $html, 'data-eem-group-toggle checked' ) );

// 5. The group subtotal placeholder renders at $0.00 (no pre-charge on load).
$check( 'group subtotal renders $0.00 on load', false !== strpos( $html, 'data-eem-total="group_subtotal">$0.00' ) );

// 6. Regression guard: NEITHER JS clamp may force the rider count to a minimum of
//    1. Both the rider-row renderer and the price recalc must allow 0 — a leftover
//    Math.max(1, ...) on group_rider_count is what pre-charged $200 on load.
$src = (string) file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
$check( 'no Math.max(1) clamp on group rider count in recalc', false === strpos( $src, "Math.max(1, getNumberFieldValue(form, 'group_rider_count')" ) );
$check( 'recalc allows 0 riders', false !== strpos( $src, "Math.max(0, getNumberFieldValue(form, 'group_rider_count')" ) );
$check( 'rider-row renderer no longer forces count to 1', false === strpos( $src, 'count = Math.max(1, count || 1)' ) );

// 7. "I'm one of the riders" self-rider opt-in (Whitney 2026-06-29).
$check( 'form renders the self-rider checkbox', false !== strpos( $html, 'name="group_self_is_rider"' ) );
$check( 'self-rider helper text present', false !== strpos( $html, 'we’ll add you as Rider 1 using your contact name' ) );
$check( 'JS defines the contact→Rider 1 auto-fill', false !== strpos( $src, 'function fillRider1FromContact' ) );
$check( 'JS stops auto-sync on a manual Rider 1 edit', false !== strpos( $src, 'rider1Manual = true' ) );
$check( 'self-rider defaults UNCHECKED (keeps $0 on load)', false === strpos( $html, 'name="group_self_is_rider" value="1" data-eem-group-self checked' ) );

wp_delete_post( $rid, true );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
