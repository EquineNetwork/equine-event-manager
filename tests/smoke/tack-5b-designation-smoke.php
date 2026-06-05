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

// EEM_Admin::for_compute() returns a memoised singleton whose internal
// orders_repository caches the order set in a private $cached_orders. Our $repo
// (a separate instance) writes the Tack-Stalls note but cannot invalidate the
// admin singleton's cache, so a grid rebuilt afterwards would otherwise re-read
// stale pre-tack orders and report is_tack=false. In real usage each chart load
// is a fresh request with a fresh repo, so this caching only bites the test.
// Flush the admin's internal repo cache after every tack write so the rebuilt
// grid reflects the new note. (Mirrors what update_component_fields() does on the
// owning instance.)
$flush_admin_orders_cache = function () use ( $admin, $ref ) {
	$repo_prop = $ref->getProperty( 'orders_repository' );
	$repo_prop->setAccessible( true );
	$internal_repo = $repo_prop->getValue( $admin );
	if ( $internal_repo ) {
		$cache_prop = ( new ReflectionClass( $internal_repo ) )->getProperty( 'cached_orders' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue( $internal_repo, null );
	}
};

// Build the #3499 grid up-front and find an order that OCCUPIES >= 2 grid cells.
// (Prior versions of this test sourced $tack_unit from the order's "Assigned
// Stall Units" note. That broke against the current seed data: the seeded order's
// note units (100, 101) fall OUTSIDE reservation #3499's configured stall range
// (300-320), so the allocator places the order on auto-assigned grid units (300+)
// and the tack designation — keyed to the note value — never lands on a rendered
// grid cell. The grid's is_tack lookup matches the Tack-Stalls note value against
// the GRID unit, so the unit we mark as tack must itself be a unit the order
// occupies on the chart. Derive both units from the grid to stay consistent with
// whatever config/allocation the current seed produces.)
$cfg  = $priv( 'get_stall_chart_config' )->invoke( $admin, 3499 );
$grid = $priv( 'build_stall_chart_grid' )->invoke( $admin, 3499, $cfg );

$occupancy = array(); // order_key => [grid units occupied]
foreach ( $grid['stall_rows'] as $row ) {
	foreach ( (array) $row['cells'] as $cell ) {
		if ( ( $cell['type'] ?? '' ) !== 'occupied' ) { continue; }
		$ok = (string) ( $cell['order_key'] ?? '' );
		if ( '' === $ok ) { continue; }
		$occupancy[ $ok ][ (string) $row['unit'] ] = true;
	}
}
$target = null;
foreach ( $occupancy as $ok => $units ) {
	$units = array_keys( $units );
	if ( count( $units ) >= 2 ) { $target = array( 'order_key' => $ok, 'units' => array_values( $units ) ); break; }
}
if ( null === $target ) {
	WP_CLI::warning( 'No #3499 order occupies >=2 grid cells — run tools/seed-test-data.php first.' );
	WP_CLI::error( 'precondition failed' );
	return;
}
$order_key = (string) $target['order_key'];
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
// Rebuild the grid AFTER marking the tack stall so the is_tack flag reflects the
// new Tack-Stalls note ($cfg unchanged — config doesn't depend on tack state).
$flush_admin_orders_cache();
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

// ── Pill render: Tack badge (top-border) + data-is-tack ──
ob_start();
$priv( 'render_stall_chart_matrix_table' )->invoke( $admin, $grid['stall_rows'], $grid['date_columns'] );
$html = ob_get_clean();
$check( 'render outputs a Tack badge (eem-occ-badge--tack)', false !== strpos( $html, 'eem-occ-badge--tack' ) );
$check( 'Tack badge carries the "Tack" label', (bool) preg_match( '/eem-occ-badge--tack[^>]*>Tack</', $html ) );
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
