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

// 4. $0 on load: rider count defaults to 0 (toggle stays ON) so the group section
//    adds nothing until the customer sets a rider count.
$check( 'rider count input defaults to 0', false !== strpos( $html, 'name="group_rider_count" min="0" step="1" value="0"' ) );
$check( 'group toggle is still ON by default', (bool) preg_match( '/data-eem-group-toggle checked/', $html ) );

// 5. The group subtotal placeholder renders at $0.00 (no pre-charge on load).
$check( 'group subtotal renders $0.00 on load', false !== strpos( $html, 'data-eem-total="group_subtotal">$0.00' ) );

wp_delete_post( $rid, true );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
