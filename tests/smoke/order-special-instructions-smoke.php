<?php
/**
 * Smoke — Order Detail "Special Instructions" inline editor (ROADMAP v1 #19).
 *
 * Covers the editable card render (Edit button + textarea + Save/Cancel bar,
 * per-reservation nonce, data attrs), the non-editable fallback (reservation_id
 * 0), the AJAX handler (capability + nonce gate, persist, empty-clears, bad-id
 * guard, round-trip read-back of `_en_special_instructions`), handler
 * registration, and the JS/CSS wiring.
 *
 * Run: wp eval-file tests/smoke/order-special-instructions-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run via wp eval-file\n" );
	exit( 1 );
}

$passed = 0;
$failed = 0;
$check  = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
add_filter( 'wp_die_ajax_handler', static function () {
	return static function ( $message ) { throw new Exception( is_scalar( $message ) ? (string) $message : 'wp_die' ); };
} );

/** Invoke the static AJAX handler, capturing the wp_send_json_* payload. */
$call = static function ( array $post ) {
	$_POST    = $post;
	$_REQUEST = $post;
	ob_start();
	try {
		EEM_Order_Detail_Page::ajax_save_special_instructions();
	} catch ( Throwable $e ) { /* wp_die handler throws — expected */ }
	$out  = ob_get_clean();
	$json = json_decode( $out, true );
	return is_array( $json ) ? $json : array( 'raw' => $out );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! empty( $admin ) ) { wp_set_current_user( (int) $admin[0] ); }

// Disposable reservation fixture.
$reservation_id = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => 'SI Smoke Reservation',
) );
$check( 'reservation fixture created', $reservation_id > 0 );

$page = new EEM_Order_Detail_Page();
$ref  = new ReflectionMethod( 'EEM_Order_Detail_Page', 'render_special_instructions_card' );
$ref->setAccessible( true );

// --- 1. Editable card render (admin + real reservation) --------------------
ob_start(); $ref->invoke( $page, $reservation_id ); $html = (string) ob_get_clean();
$check( 'card carries the special-instructions hook', str_contains( $html, 'data-eem-special-instructions' ) );
$check( 'card carries the reservation id', str_contains( $html, 'data-reservation-id="' . $reservation_id . '"' ) );
$check( 'card carries a nonce', (bool) preg_match( '/data-nonce="[a-f0-9]{6,}"/', $html ) );
$check( 'Edit button present', str_contains( $html, 'data-eem-action="order-special-instructions-edit"' ) );
$check( 'editor textarea present', str_contains( $html, 'data-eem-instructions-input' ) );
$check( 'Save Changes button present', str_contains( $html, 'data-eem-action="order-special-instructions-save"' ) );
$check( 'Cancel button present', str_contains( $html, 'data-eem-action="order-special-instructions-cancel"' ) );
$check( 'error region present', str_contains( $html, 'data-eem-instructions-error' ) );

// --- 2. Non-editable fallback (no reservation) -----------------------------
ob_start(); $ref->invoke( $page, 0 ); $html0 = (string) ob_get_clean();
$check( 'no Edit button when reservation_id 0', ! str_contains( $html0, 'order-special-instructions-edit' ) );

// --- 3. Handler registration -----------------------------------------------
$check( 'AJAX action registered', false !== has_action( 'wp_ajax_eem_order_save_special_instructions' ) );
$check( 'handler method exists', method_exists( 'EEM_Order_Detail_Page', 'ajax_save_special_instructions' ) );

// --- 4. Save persists + returns rendered html ------------------------------
$nonce = wp_create_nonce( 'eem_save_special_instructions_' . $reservation_id );
$r = $call( array(
	'reservation_id'                  => $reservation_id,
	'_eem_special_instructions_nonce' => $nonce,
	'special_instructions'            => "Gate code 4417.\nCall on arrival.",
) );
$check( 'save success', isset( $r['success'] ) && true === $r['success'] );
$check( 'meta persisted (round-trip)', "Gate code 4417.\nCall on arrival." === get_post_meta( $reservation_id, '_en_special_instructions', true ) );
$check( 'response html preserves newline as <br>', isset( $r['data']['text_html'] ) && str_contains( $r['data']['text_html'], '<br' ) );
$check( 'response not flagged empty', isset( $r['data']['is_empty'] ) && false === $r['data']['is_empty'] );

// --- 5. Empty text clears the meta -----------------------------------------
$nonce = wp_create_nonce( 'eem_save_special_instructions_' . $reservation_id );
$r = $call( array(
	'reservation_id'                  => $reservation_id,
	'_eem_special_instructions_nonce' => $nonce,
	'special_instructions'            => '   ',
) );
$check( 'clear success', isset( $r['success'] ) && true === $r['success'] );
$check( 'meta removed when blank', '' === get_post_meta( $reservation_id, '_en_special_instructions', true ) );
$check( 'response flagged empty with em-dash', isset( $r['data']['is_empty'] ) && true === $r['data']['is_empty'] && str_contains( (string) $r['data']['text_html'], 'mdash' ) );

// --- 6. Bad reservation id is rejected -------------------------------------
$nonce = wp_create_nonce( 'eem_save_special_instructions_999999999' );
$r = $call( array(
	'reservation_id'                  => 999999999,
	'_eem_special_instructions_nonce' => $nonce,
	'special_instructions'            => 'x',
) );
$check( 'bad reservation rejected', isset( $r['success'] ) && false === $r['success'] );

// --- 7. JS + CSS wiring ----------------------------------------------------
$base = dirname( __DIR__, 2 );
$js   = (string) file_get_contents( $base . '/assets/js/admin.js' );
$css  = (string) file_get_contents( $base . '/assets/css/admin.css' );
foreach ( array( 'openSpecialInstructionsEditor', 'closeSpecialInstructionsEditor', 'saveSpecialInstructions' ) as $fn ) {
	$check( "JS defines {$fn}()", (bool) preg_match( '/function\s+' . $fn . '\s*\(/', $js ) );
}
$check( 'JS maps order-special-instructions-edit', str_contains( $js, "'order-special-instructions-edit'" ) );
$check( 'JS maps order-special-instructions-save', str_contains( $js, "'order-special-instructions-save'" ) );
$check( 'JS posts the save action', str_contains( $js, 'eem_order_save_special_instructions' ) );
$check( 'CSS styles the editor', str_contains( $css, '.eem-order-instructions__editor' ) );
$check( 'CSS styles the editor actions', str_contains( $css, '.eem-order-instructions__actions' ) );

wp_delete_post( $reservation_id, true );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
