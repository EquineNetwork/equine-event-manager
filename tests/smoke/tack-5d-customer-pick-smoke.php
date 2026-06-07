<?php
/**
 * V1 #5d smoke — customer tack-stall designation at checkout (MAPPED/pick mode).
 *
 * Operational only (no price change). Verifies: the picker grid renders the
 * optional tack-designate control (select + name="preferred_tack_stall" + amber
 * chrome), the submission payload validates the designation against the picked
 * units (kept when picked, dropped when not), and the JS sync helper + CSS
 * exist. Mirrors the admin-side #5b path so both converge on one Tack Stalls note.
 *
 * Run: wp eval-file tests/smoke/tack-5d-customer-pick-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$sc  = new EEM_Shortcodes();
$ref = new ReflectionClass( $sc );
$priv = function ( $name ) use ( $sc, $ref ) { $m = $ref->getMethod( $name ); $m->setAccessible( true ); return $m; };

// ── Render: the picker grid emits the tack-designate control ──
$rows = array(
	array( 'name' => 'Barn A', 'layout' => 'one-sided', 'first' => '100', 'last' => '105' ),
);
ob_start();
$priv( 'render_stall_picker_grid' )->invoke( $sc, $rows, array(), array(), array(), '' );
$html = ob_get_clean();
$check( 'picker renders the tack-designate wrapper', false !== strpos( $html, 'data-eem-tack-designate' ) );
$check( 'picker renders the preferred_tack_stall select', false !== strpos( $html, 'name="preferred_tack_stall"' ) );
$check( 'tack select is JS-targetable', false !== strpos( $html, 'data-eem-tack-select' ) );
$check( 'tack block carries the amber dot', false !== strpos( $html, 'stall-tack-designate__dot' ) );
$check( 'tack block starts hidden (revealed by JS on pick)', false !== strpos( $html, 'data-eem-tack-designate hidden' ) );
$check( 'tack block has a "no tack stall" default option', false !== stripos( $html, 'No tack stall' ) );

// ── Payload: designation validates against the picked units ──
// $data must define a stall pool so the picked units survive sanitization.
$pool_data = array(
	'stall_selection_mode'     => 'exact_map',
	'stall_chart_stall_blocks' => array( array( 'start' => 100, 'end' => 105 ) ),
);
$_POST = array(
	'preferred_stall_units' => array( '100', '101', '102' ),
	'preferred_tack_stall'  => '101',
	'stall_qty'             => 3,
);
$payload = $priv( 'get_stall_submission_payload' )->invoke( $sc, $pool_data );
$check( 'valid designation (one of the picks) is kept', '101' === ( $payload['preferred_tack_stall'] ?? 'MISSING' ) );

$_POST['preferred_tack_stall'] = '999';
$payload2 = $priv( 'get_stall_submission_payload' )->invoke( $sc, $pool_data );
$check( 'designation not among picks is dropped', '' === ( $payload2['preferred_tack_stall'] ?? 'MISSING' ) );

$_POST['preferred_tack_stall'] = '';
$payload3 = $priv( 'get_stall_submission_payload' )->invoke( $sc, $pool_data );
$check( 'empty designation stays empty (opt-out)', '' === ( $payload3['preferred_tack_stall'] ?? 'MISSING' ) );
$_POST = array();

// ── Wiring: JS sync helper + note write + CSS + no leak ──
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
$css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/public.css' );
$check( 'JS sync helper present', false !== strpos( $src, 'function syncStallTackDesignate' ) );
$check( 'sync helper invoked from syncStallPicker', false !== strpos( $src, 'syncStallTackDesignate(picker, selected)' ) );
// Note writer fires whenever tack is on (off !== mode) with the buyer's single
// designated stall.
$check( 'submission writes the Tack Stalls note', false !== strpos( $src, '"\nTack Stalls: " . sanitize_text_field' ) );
$check( 'Tack Stalls is stripped from Special Requests', false !== strpos( $src, 'Tack Stalls|Add-On' ) );
$check( 'CSS for the tack-designate block exists', false !== strpos( $css, '.stall-tack-designate' ) );

WP_CLI::log( "\n=== Tack #5d customer-pick smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Tack #5d customer-pick smoke passed.' );
