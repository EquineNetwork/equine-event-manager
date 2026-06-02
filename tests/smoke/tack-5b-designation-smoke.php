<?php
/**
 * V1 #5b smoke — admin tack-stall designation on the Stall & RV Charts page.
 *
 * Operational only (no price change). Verifies: the Tack Stalls notes-line
 * writer, the chart grid marking is_tack on the right cell, the amber pill +
 * tack-dot render, the no-leak guard (tack line doesn't reach Special Requests),
 * the toggle round-trip, and the AJAX/popup wiring.
 *
 * Run: wp eval-file tests/smoke/tack-5b-designation-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$admin = EEM_Admin::for_compute();
$ref   = new ReflectionClass( $admin );
$priv  = function ( $name ) use ( $admin, $ref ) { $m = $ref->getMethod( $name ); $m->setAccessible( true ); return $m; };
$repo  = new EEM_Orders_Repository();

// Find a #3499 order with >= 2 assigned stall units.
$target = null;
foreach ( $repo->get_orders( '', 'date', 'asc' ) as $o ) {
	if ( (int) ( $o['reservation_id'] ?? 0 ) !== 3499 ) { continue; }
	$units = (array) $priv( 'parse_assigned_units_string' )->invoke(
		$admin, $priv( 'get_order_component_note_value' )->invoke( $admin, $o, 'stall', 'Assigned Stall Units' )
	);
	if ( count( $units ) >= 2 ) { $target = array( 'order' => $o, 'units' => array_values( $units ) ); break; }
}
if ( null === $target ) {
	WP_CLI::warning( 'No seeded #3499 order with >=2 assigned stalls — run tools/seed-test-data.php first.' );
	WP_CLI::error( 'precondition failed' );
	return;
}
$order_key = (string) $target['order']['order_key'];
$tack_unit = (string) $target['units'][0];
$other     = (string) $target['units'][1];
WP_CLI::log( "Test order {$order_key}; marking stall {$tack_unit} as tack (other: {$other})" );

// Preserve + restore original tack state.
$orig_tack = trim( (string) $priv( 'get_order_component_note_value' )->invoke(
	$admin, $repo->get_order( $order_key ), 'stall', 'Tack Stalls'
) );

// ── Writer: mark one stall as tack ──
$check( 'update_order_tack_stalls succeeds', (bool) $repo->update_order_tack_stalls( $order_key, $tack_unit ) );
$after = $repo->get_order( $order_key );
$tack_after = (array) $priv( 'parse_assigned_units_string' )->invoke(
	$admin, $priv( 'get_order_component_note_value' )->invoke( $admin, $after, 'stall', 'Tack Stalls' )
);
$check( 'Tack Stalls note now lists the unit', in_array( $tack_unit, $tack_after, true ) );
$check( 'only the one stall is tack', 1 === count( $tack_after ) );

// ── No leak: Tack Stalls must not appear in Special Requests ──
$sr = trim( (string) $priv( 'get_special_requests_from_order_notes' )->invoke( $admin, $after['notes'] ) );
$check( 'Tack Stalls does not leak into Special Requests', false === stripos( $sr, 'Tack Stalls' ) && false === strpos( $sr, $tack_unit . ',' ) );

// ── Grid marks the right cell is_tack ──
$cfg  = $priv( 'get_stall_chart_config' )->invoke( $admin, 3499 );
$grid = $priv( 'build_stall_chart_grid' )->invoke( $admin, 3499, $cfg );
$tack_cell_ok = false; $other_cell_not_tack = true;
foreach ( $grid['stall_rows'] as $row ) {
	foreach ( (array) $row['cells'] as $cell ) {
		if ( ( $cell['type'] ?? '' ) !== 'occupied' || (string) $cell['order_key'] !== $order_key ) { continue; }
		if ( (string) $row['unit'] === $tack_unit && ! empty( $cell['is_tack'] ) ) { $tack_cell_ok = true; }
		if ( (string) $row['unit'] === $other && ! empty( $cell['is_tack'] ) ) { $other_cell_not_tack = false; }
	}
}
$check( 'grid marks the tack stall cell is_tack=true', $tack_cell_ok );
$check( 'grid leaves the other stall is_tack=false', $other_cell_not_tack );

// ── Pill render: amber class + tack dot + data-is-tack ──
ob_start();
$priv( 'render_stall_chart_matrix_table' )->invoke( $admin, $grid['stall_rows'], $grid['date_columns'] );
$html = ob_get_clean();
$check( 'render outputs a tack pill (eem-occ-pill--tack)', false !== strpos( $html, 'eem-occ-pill--tack' ) );
$check( 'render outputs the tack dot', false !== strpos( $html, 'eem-occ-pill__tack-dot' ) );
$check( 'render outputs data-is-tack="1" somewhere', false !== strpos( $html, 'data-is-tack="1"' ) );

// ── #5b.2: by-customer view shows the amber Tack note + data-has-tack + filter ──
$brows = $priv( 'build_stall_chart_rows' )->invoke( $admin, 3499, $cfg );
$row_tack_ok = false;
foreach ( (array) $brows as $r ) {
	if ( (string) $r['order_key'] === $order_key ) {
		$row_tack_ok = isset( $r['tack_units'] ) && in_array( $tack_unit, array_map( 'strval', (array) $r['tack_units'] ), true );
	}
}
$check( 'build_stall_chart_rows carries tack_units for the order', $row_tack_ok );

ob_start();
$priv( 'render_stall_chart_order_count_table' )->invoke( $admin, $brows, $grid['date_columns'] );
$cust_html = ob_get_clean();
$check( 'by-customer renders the amber Tack note', false !== strpos( $cust_html, 'eem-chart-tack-note' ) );
$check( 'by-customer Tack note shows the unit', false !== strpos( $cust_html, 'Tack: ' . $tack_unit ) || false !== strpos( $cust_html, 'Tack: ' ) );
$check( 'tack row carries data-has-tack="1"', false !== strpos( $cust_html, 'data-has-tack="1"' ) );

// ── Toggle off ──
$check( 'unmark (empty) succeeds', (bool) $repo->update_order_tack_stalls( $order_key, '' ) );
$cleared = (array) $priv( 'parse_assigned_units_string' )->invoke(
	$admin, $priv( 'get_order_component_note_value' )->invoke( $admin, $repo->get_order( $order_key ), 'stall', 'Tack Stalls' )
);
$check( 'Tack Stalls note cleared after unmark', empty( $cleared ) );

// Restore original.
$repo->update_order_tack_stalls( $order_key, $orig_tack );

// ── Wiring ──
$adm_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$js_src  = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$check( 'ajax_toggle_tack_stall hook registered', has_action( 'wp_ajax_eem_toggle_tack_stall' ) !== false );
$check( 'popup has the Mark/Unmark Tack button', false !== strpos( $adm_src, 'data-eem-action="toggle-tack-stall"' ) );
$check( 'JS handles the tack toggle action', false !== strpos( $js_src, "'[data-eem-action=\"toggle-tack-stall\"]'" ) );
$check( 'JS posts to eem_toggle_tack_stall', false !== strpos( $js_src, 'eem_toggle_tack_stall' ) );
$check( 'by-customer has the Tack Stalls filter chip', false !== strpos( $adm_src, 'stall-chart-toggle-tack' ) );
$check( 'JS has the tack filter toggle handler', false !== strpos( $js_src, "'stall-chart-toggle-tack'" ) );
$check( 'filter accounts for data-has-tack', false !== strpos( $js_src, "getAttribute('data-has-tack')" ) );

WP_CLI::log( "\n=== Tack #5b designation smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'Tack #5b designation smoke passed.' );
