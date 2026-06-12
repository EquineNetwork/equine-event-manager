<?php
/**
 * Stall-chart print-view assigned-only smoke (v1 #3 — By Location print refinement).
 *
 * The print view defaults to a dense "Assigned only" mode that prints just the
 * stalls/lots with an occupied or blocked night, dropping the flood of empty
 * "Available" rows. An "All stalls" toggle restores the full blank check-in sheet.
 *
 * Source-presence assertions always run. When a reservation with at least one
 * assigned stall exists (dev/staging fixture), the smoke ALSO renders the page in
 * both modes and asserts assigned-mode renders strictly fewer stall rows. With no
 * such fixture the render assertions are skipped (reported), not failed.
 *
 * Run: wp eval-file tests/smoke/print-view-assigned-only-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0; $skipped = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};
$skip = static function ( string $label ) use ( &$skipped ): void {
	$skipped++; echo "SKIP  - {$label}\n";
};

// --- source-presence: toggle + filter logic --------------------------------
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$check( 'print page reads a rows param', false !== strpos( $src, "\$pv_rows = isset( \$_GET['rows'] )" ) );
$check( 'rows param defaults to assigned', false !== strpos( $src, "? sanitize_key( wp_unslash( \$_GET['rows'] ) ) : 'assigned'" ) );
$check( 'assigned-only flag computed', false !== strpos( $src, "\$assigned_only = ( 'assigned' === \$pv_rows )" ) );
$check( 'row_has_content helper defined', false !== strpos( $src, '$row_has_content = static function' ) );
$check( 'By Location skips empty barns in assigned mode', false !== strpos( $src, 'if ( $assigned_only && 0 === $content_count )' ) );
$check( 'stall row skip in assigned mode', false !== strpos( $src, 'if ( $assigned_only && ! $row_has_content( $stall_row ) )' ) );
$check( 'rv row skip in assigned mode', false !== strpos( $src, 'if ( $assigned_only && ! $row_has_content( $rv_row ) )' ) );
$check( '"Assigned only" toggle button rendered', false !== strpos( $src, 'Assigned only' ) );
$check( '"All stalls" toggle button rendered', false !== strpos( $src, 'All stalls' ) );
$check( 'assigned-only empty-state note rendered', false !== strpos( $src, 'pv-empty-note' ) );

// --- functional render (fixture-gated) -------------------------------------
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

// Find a published reservation with stalls enabled AND at least one order whose
// stall component carries an "Assigned Stall Units" note.
$repo   = new EEM_Orders_Repository();
$target = 0;
$reservations = get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'numberposts' => 50, 'fields' => 'ids' ) );
foreach ( $reservations as $cand_id ) {
	if ( '1' !== (string) get_post_meta( $cand_id, '_en_stalls_enabled', true ) ) { continue; }
	foreach ( $repo->get_orders() as $o ) {
		if ( (int) ( $o['reservation_id'] ?? 0 ) !== (int) $cand_id ) { continue; }
		foreach ( (array) ( $o['components'] ?? array() ) as $comp ) {
			if ( ( $comp['table'] ?? '' ) === 'stall' && false !== strpos( (string) ( $comp['notes'] ?? '' ), 'Assigned Stall Units:' ) ) {
				$target = (int) $cand_id; break 3;
			}
		}
	}
}

if ( $target < 1 ) {
	$skip( 'no reservation with an assigned stall — functional render assertions skipped' );
} else {
	$admin  = new EEM_Admin();
	$render = static function ( $admin, $rid, $rows ) {
		$_GET = array( 'page' => 'equine-event-manager-stall-chart-print', 'reservation_id' => (string) $rid, 'view' => 'location', 'rows' => $rows );
		ob_start(); $admin->render_stall_chart_print_page(); $html = (string) ob_get_clean(); $_GET = array();
		return $html;
	};
	$assigned = $render( $admin, $target, 'assigned' );
	$all      = $render( $admin, $target, 'all' );
	$a_rows   = substr_count( $assigned, 'class="pv-stall-num"' );
	$all_rows = substr_count( $all, 'class="pv-stall-num"' );

	echo "  ..  - using reservation #{$target}: assigned={$a_rows} rows, all={$all_rows} rows\n";
	$check( 'assigned mode renders at least one stall row', $a_rows >= 1 );
	$check( 'all mode renders at least as many rows as assigned', $all_rows >= $a_rows );
	$check( 'assigned mode renders strictly fewer rows than all (drops empties)', $a_rows < $all_rows );
	// Assigned mode drops purely-available rows but KEEPS blocked rows (which are
	// also customer-empty), so compare counts rather than asserting zero.
	$a_empty   = substr_count( $assigned, 'pv-customer-empty' );
	$all_empty = substr_count( $all, 'pv-customer-empty' );
	$check( 'assigned mode has fewer empty-customer rows than all mode', $a_empty < $all_empty );
	$check( 'all mode keeps empty "—" available rows', $all_empty > 0 );
	$check( 'both row-toggle buttons present in rendered output', false !== strpos( $assigned, 'Assigned only' ) && false !== strpos( $assigned, 'All stalls' ) );

	// Default (no rows param) === assigned mode.
	$_GET = array( 'page' => 'equine-event-manager-stall-chart-print', 'reservation_id' => (string) $target, 'view' => 'location' );
	ob_start(); $admin->render_stall_chart_print_page(); $def = (string) ob_get_clean(); $_GET = array();
	$check( 'omitting rows param defaults to assigned-mode row count', substr_count( $def, 'class="pv-stall-num"' ) === $a_rows );
}

echo "\n{$passed} passed, {$failed} failed, {$skipped} skipped\n";
if ( $failed > 0 ) { exit( 1 ); }
