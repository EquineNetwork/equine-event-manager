<?php
/**
 * Move-stall-assignment smoke (#234 — restored move-customer flow, 2.7.466).
 *
 * The 06-18 session restored "move customer to another stall" on the By-Location
 * readiness grid: an occupied cell re-opens the cell-action menu → destination
 * mode → scope modal → POST eem_move_stall_assignment. The handler is heavily
 * order-data dependent, so this smoke asserts the handler's STRUCTURE rather than
 * driving a full DB move: it's registered, capability + nonce gated, validates
 * required params, serializes behind the shared per-reservation assignment lock,
 * handles both stall + RV kinds and the this-night scope, and the JS posts the
 * matching action with the expected fields.
 *
 * Run: wp eval-file tests/smoke/move-stall-assignment-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// --- handler exists + is hooked to the AJAX action --------------------------
$ref = new ReflectionClass( 'EEM_Admin' );
$check( 'ajax_move_stall_assignment() defined', $ref->hasMethod( 'ajax_move_stall_assignment' ) );

$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$check( 'hooked to wp_ajax_eem_move_stall_assignment', false !== strpos( $src, "add_action( 'wp_ajax_eem_move_stall_assignment', array( \$this, 'ajax_move_stall_assignment' ) )" ) );

// --- security: nonce + capability -------------------------------------------
$check( 'verifies the eem_stall_chart_move nonce', false !== strpos( $src, "check_ajax_referer( 'eem_stall_chart_move', '_wpnonce' )" ) );
// Capability gate sits immediately after the nonce check in the move handler.
$move_pos = strpos( $src, 'function ajax_move_stall_assignment' );
$move_seg = false !== $move_pos ? substr( $src, $move_pos, 1200 ) : '';
$check( 'requires manage_options capability', false !== strpos( $move_seg, "current_user_can( 'manage_options' )" ) && false !== strpos( $move_seg, '403' ) );

// --- required-param validation ----------------------------------------------
$check( 'rejects missing order/source/destination params', false !== strpos( $src, "if ( '' === \$order_key || '' === \$src_stall || '' === \$dest_stall ) {" ) );
$check( '404s when the order is not found', false !== strpos( $src, "\$order = \$this->orders_repository->get_order( \$order_key );" ) );

// --- concurrency: shares the per-reservation assignment lock ----------------
$check( 'acquires the per-reservation assignment lock', false !== strpos( $src, 'if ( ! $this->acquire_assignment_lock( $reservation_id ) ) {' ) );
$check( 'returns busy when the lock cannot be taken', false !== strpos( $src, '$this->send_assignment_lock_busy();' ) );

// --- stall + RV kinds, this-night scope -------------------------------------
$check( 'handles both stall and RV kinds', false !== strpos( $src, "\$kind        = ( isset( \$_POST['kind'] ) && 'rv' === sanitize_key( wp_unslash( \$_POST['kind'] ) ) ) ? 'rv' : 'stall';" ) );
$check( 'scopes a single-night move (this-night)', false !== strpos( $src, "\$single_night = ( 'this-night' === \$scope" ) );

// --- destination validation: blocked + in-chart + per-night conflict --------
$check( 'rejects a blocked destination unit', false !== strpos( $src, 'Destination %s is blocked.' ) );
$check( 'rejects a destination not part of the chart', false !== strpos( $src, 'Destination %s is not part of this chart.' ) );
$check( 'rejects a destination already reserved on a target night', false !== strpos( $src, 'Destination %s is already reserved on one of those nights.' ) );

// --- helper methods the move relies on exist --------------------------------
foreach ( array( 'get_stall_chart_occupied_dates', 'get_stall_chart_config', 'parse_assigned_units_string', 'get_order_component_note_value', 'get_order_night_assignments' ) as $m ) {
	$check( "helper {$m}() defined", $ref->hasMethod( $m ) );
}

// --- JS side posts the matching action + fields -----------------------------
$js = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$check( 'JS posts action=eem_move_stall_assignment', false !== strpos( $js, "body.set('action', 'eem_move_stall_assignment')" ) );
$check( 'JS sends source_stall + destination_stall', false !== strpos( $js, "body.set('source_stall'" ) && false !== strpos( $js, "body.set('destination_stall'" ) );
$check( 'JS uses destination-mode for the move target pick', false !== strpos( $js, "classList.add('destination-mode')" ) );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
