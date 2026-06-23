<?php
/**
 * Smoke — customer page consumes the group fields (ROADMAP v1 #20).
 *
 * Two layers:
 *  [A] Behavioural — invokes the real EEM_Shortcodes::validate_submission()
 *      with synthetic data to prove the admin-configured "riders per group" max
 *      is enforced server-side (over max errors; at/under max + unlimited do
 *      not; singular wording at max 1). DB-free, so no fixture needed.
 *  [B] Source-presence — asserts the customer template now renders the
 *      admin-authored group description, the max attribute + max note on the
 *      rider-count input, the JS clamp, and the description CSS. The visual
 *      rendering itself is confirmed in Whitney's visual verify.
 *
 * Run: wp eval-file tests/smoke/group-riders-max-smoke.php
 */

$pass = 0;
$fail = 0;
$ok   = static function ( $name, $cond ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  - {$name}\n"; }
	else         { $fail++; echo "FAIL  - {$name}\n"; }
};

// ── [A] validate_submission max enforcement ────────────────────────────────
$sc  = new EEM_Shortcodes();
$ref = new ReflectionMethod( 'EEM_Shortcodes', 'validate_submission' );
$ref->setAccessible( true );

$mk_riders = static function ( $n ) {
	$r = array();
	for ( $i = 0; $i < $n; $i++ ) { $r[] = array( 'first_name' => 'A' . $i, 'last_name' => 'B' . $i ); }
	return $r;
};

$base_submission = array(
	'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com', 'phone' => '+15555550100',
	'billing_first_name' => 'Jane', 'billing_last_name' => 'Doe', 'billing_address_1' => '1 St',
	'billing_city' => 'Town', 'billing_state' => 'TX', 'billing_postal_code' => '70000', 'billing_country' => 'US',
	'submission_token' => 'tok', 'invoice_type' => '', 'invoice_action_mode' => '',
	'group_reservation_enabled' => 1, 'rv_qty' => 0, 'stall_qty' => 0, 'tack_stall_qty' => 0, 'additional_shavings_qty' => 0,
);
$status = array(
	'stalls_open' => true, 'rv_open' => true, 'shavings_open' => true, 'stalls_sold_out' => false,
	'rv_sold_out' => false, 'stall_inventory_remaining' => null,
);
$has_max_error = static function ( $errors ) {
	foreach ( (array) $errors as $e ) { if ( false !== strpos( (string) $e, 'at most' ) ) { return true; } }
	return false;
};

// count 5 > max 3 → error
$s = array_merge( $base_submission, array( 'group_rider_count' => 5, 'group_riders' => $mk_riders( 5 ) ) );
$errors = $ref->invoke( $sc, $s, $status, array( 'group_reservations_enabled' => 1, 'group_riders_per_group' => 3 ) );
$ok( 'count over max errors', $has_max_error( $errors ) );

// count 3 == max 3 → no max error
$s = array_merge( $base_submission, array( 'group_rider_count' => 3, 'group_riders' => $mk_riders( 3 ) ) );
$errors = $ref->invoke( $sc, $s, $status, array( 'group_reservations_enabled' => 1, 'group_riders_per_group' => 3 ) );
$ok( 'count at max does not error', ! $has_max_error( $errors ) );

// unlimited (0) → no max error
$s = array_merge( $base_submission, array( 'group_rider_count' => 50, 'group_riders' => $mk_riders( 50 ) ) );
$errors = $ref->invoke( $sc, $s, $status, array( 'group_reservations_enabled' => 1, 'group_riders_per_group' => 0 ) );
$ok( 'unlimited does not error', ! $has_max_error( $errors ) );

// singular wording at max 1
$s = array_merge( $base_submission, array( 'group_rider_count' => 2, 'group_riders' => $mk_riders( 2 ) ) );
$errors = $ref->invoke( $sc, $s, $status, array( 'group_reservations_enabled' => 1, 'group_riders_per_group' => 1 ) );
$singular = false;
foreach ( (array) $errors as $e ) { if ( false !== strpos( (string) $e, 'at most 1 rider.' ) ) { $singular = true; } }
$ok( 'singular wording at max 1', $singular );

// ── [B] template / JS / CSS source presence ────────────────────────────────
$base = dirname( __DIR__, 2 );
$src  = (string) file_get_contents( $base . '/public/class-equine-event-manager-shortcodes.php' );
$css  = (string) file_get_contents( $base . '/assets/css/public.css' );

$ok( 'extracts group_description into $data', str_contains( $src, "\$group_description" ) && str_contains( $src, "data['group_description']" ) );
$ok( 'extracts group_riders_per_group into $data', str_contains( $src, "\$group_riders_per_group" ) && str_contains( $src, "data['group_riders_per_group']" ) );
$ok( 'renders the description block', str_contains( $src, 'eem-group-reservation__description' ) );
$ok( 'renders the max attribute on rider count', str_contains( $src, "' max=\"' . esc_attr( (string) \$group_riders_per_group )" ) );
$ok( 'renders the max note', str_contains( $src, 'Maximum %d riders per reservation.' ) );
$ok( 'JS clamps to max', str_contains( $src, "countInput.getAttribute('max')" ) && str_contains( $src, 'count > maxRiders' ) );
$ok( 'CSS styles the description', str_contains( $css, '.eem-group-reservation__description' ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) { exit( 1 ); }
