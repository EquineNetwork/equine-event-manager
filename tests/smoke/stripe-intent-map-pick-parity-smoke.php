<?php
/**
 * Regression smoke — Stripe PaymentIntent vs final-submit parity for MAP-picked
 * stalls (Whitney 2026-06-29).
 *
 * THE BUG: ajax_create_stripe_payment_intent() built the submission without first
 * setting $this->active_reservation_id. sanitize_submission() →
 * sanitize_preferred_stall_units() → get_stall_map_unit_labels() reads that
 * property to merge the stall-MAP cell labels into the allowed unit pool. With it
 * unset (0), the map labels were absent, every map-picked stall was intersected
 * away, the designated tack stall was discarded, and the PaymentIntent total was
 * computed WITHOUT the tack-shavings exclusion. The final submit (which DOES set
 * active_reservation_id) computed WITH it — so the charged amount and the order
 * total diverged and Stripe rejected the charge: "The Stripe payment amount did
 * not match this reservation total."
 *
 * This is a BEHAVIORAL guard, not source-presence: it drives the real validator
 * against a real reservation map and asserts map picks survive IFF the active
 * reservation context is set — exactly the property the handler must guarantee.
 *
 * Run: wp eval-file tests/smoke/stripe-intent-map-pick-parity-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

if ( ! class_exists( 'EEM_Stall_Map_Importer' ) ) {
	WP_CLI::warning( 'EEM_Stall_Map_Importer unavailable — skipping map-pick parity smoke.' );
	WP_CLI::success( 'Skipped (no map importer).' );
	return;
}

$sc  = new EEM_Shortcodes();
$ref = new ReflectionClass( $sc );
$priv = function ( $name ) use ( $sc, $ref ) { $m = $ref->getMethod( $name ); $m->setAccessible( true ); return $m; };
$set_active = function ( $id ) use ( $sc, $ref ) {
	$p = $ref->getProperty( 'active_reservation_id' );
	$p->setAccessible( true );
	$p->setValue( $sc, (int) $id );
};

// ── Find a published reservation whose stall map carries at least 2 stall labels ──
$reservation_id = 0;
$map_labels     = array();
$candidates     = get_posts( array(
	'post_type'      => 'en_reservation',
	'post_status'    => 'publish',
	'posts_per_page' => 50,
	'fields'         => 'ids',
) );

foreach ( $candidates as $cand_id ) {
	$set_active( $cand_id );
	$labels = $priv( 'get_stall_map_unit_labels' )->invoke( $sc );
	if ( count( $labels ) >= 2 ) {
		$reservation_id = (int) $cand_id;
		$map_labels     = array_values( $labels );
		break;
	}
}
$set_active( 0 );

if ( $reservation_id <= 0 ) {
	WP_CLI::warning( 'No reservation with a 2+ stall map snapshot found — skipping (seed a mapped reservation to run this guard).' );
	WP_CLI::success( 'Skipped (no mapped reservation fixture).' );
	return;
}

WP_CLI::log( "Using reservation #{$reservation_id} with map labels: " . implode( ', ', array_slice( $map_labels, 0, 6 ) ) . ( count( $map_labels ) > 6 ? '…' : '' ) );

$data       = $priv( 'get_reservation_meta' )->invoke( $sc, $reservation_id );
$pick_a     = $map_labels[0];
$pick_b     = $map_labels[1];
$picked     = array( $pick_a, $pick_b );

// ── BUG condition: active context unset → map picks intersected away ──
$set_active( 0 );
$pool_unset   = $priv( 'get_stall_assignment_unit_pool' )->invoke( $sc, $data );
$kept_unset   = $priv( 'sanitize_preferred_stall_units' )->invoke( $sc, $picked, $data );
$check( 'WITHOUT active context, map labels are absent from the allowed pool (the bug source)', ! in_array( (string) $pick_a, array_map( 'strval', $pool_unset ), true ) );
$check( 'WITHOUT active context, map picks are dropped (reproduces the divergence)', empty( $kept_unset ) );

// ── FIX condition: active context set → map picks survive ──
$set_active( $reservation_id );
$pool_set     = $priv( 'get_stall_assignment_unit_pool' )->invoke( $sc, $data );
$kept_set     = array_map( 'strval', $priv( 'sanitize_preferred_stall_units' )->invoke( $sc, $picked, $data ) );
$check( 'WITH active context, map labels join the allowed pool', in_array( (string) $pick_a, array_map( 'strval', $pool_set ), true ) && in_array( (string) $pick_b, array_map( 'strval', $pool_set ), true ) );
$check( 'WITH active context, both map picks survive validation', in_array( (string) $pick_a, $kept_set, true ) && in_array( (string) $pick_b, $kept_set, true ) );

// ── Tack parity: a designated tack stall that IS one of the surviving picks is kept ──
$_POST = array(
	'preferred_stall_units' => $picked,
	'preferred_tack_stall'  => (string) $pick_a,
	'stall_qty'             => 2,
);
$payload = $priv( 'get_stall_submission_payload' )->invoke( $sc, $data );
$check( 'WITH active context, the designated tack stall is retained on the submission', (string) $pick_a === (string) ( $payload['preferred_tack_stall'] ?? '' ) );
$check( 'WITH active context, the picked units are retained on the submission', in_array( (string) $pick_a, array_map( 'strval', (array) ( $payload['preferred_stall_units'] ?? array() ) ), true ) );
$_POST = array();
$set_active( 0 );

// ── Handler wiring: the intent handler must set the active context before sanitizing ──
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
$handler_pos = strpos( $src, 'public function ajax_create_stripe_payment_intent' );
$sanitize_pos = $handler_pos !== false ? strpos( $src, '$this->sanitize_submission( $data )', $handler_pos ) : false;
$active_pos   = $handler_pos !== false ? strpos( $src, '$this->active_reservation_id = (int) $reservation_id;', $handler_pos ) : false;
$check( 'intent handler sets active_reservation_id', $active_pos !== false );
$check( 'intent handler sets active_reservation_id BEFORE sanitize_submission()', $active_pos !== false && $sanitize_pos !== false && $active_pos < $sanitize_pos );

WP_CLI::log( "\n=== Stripe intent map-pick parity smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Stripe intent map-pick parity smoke passed.' );
