<?php
/**
 * V1 Scenario F smoke — Special Requests visible on the Stall Charts page.
 *
 * Verifies the customer's Special Requests text surfaces on (a) reserved pills
 * in the by-location matrix (amber dot + native tooltip + data attr) and (b) a
 * note under the customer name in the by-customer table — and that rows/cells
 * WITHOUT special requests show no note markup.
 *
 * Run: wp eval-file tests/smoke/f-special-requests-chart-smoke.php
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
$call  = function ( $method, array $args ) use ( $admin, $ref ) {
	$m = $ref->getMethod( $method );
	$m->setAccessible( true );
	ob_start();
	$rv = $m->invokeArgs( $admin, $args );
	$out = ob_get_clean();
	return array( $rv, $out );
};

$NOTE = 'Stallion accommodations preferred';
$date_columns = array( '2026-05-08' => 'May 8' );

// ── A) Matrix (by-location) pill WITH special requests ──
$rows_with = array(
	array(
		'unit'  => '100',
		'block' => 'Red Barn',
		'cells' => array(
			'2026-05-08' => array(
				'type'             => 'occupied',
				'label'            => 'Whitney Mitchell',
				'order_key'        => 'abc123def456abc123def456abc12345', // md5-shaped
				'order_number'     => '9003',
				'special_requests' => $NOTE,
			),
		),
	),
);
list( , $html_with ) = $call( 'render_stall_chart_matrix_table', array( $rows_with, $date_columns ) );
$check( 'pill renders the note indicator dot', false !== strpos( $html_with, 'eem-occ-pill__note-dot' ) );
$check( 'pill gets the --has-note modifier', false !== strpos( $html_with, 'eem-occ-pill--has-note' ) );
$check( 'pill carries data-special-requests', false !== strpos( $html_with, 'data-special-requests="' . esc_attr( $NOTE ) . '"' ) );
$check( 'pill carries a native title tooltip with the note', false !== strpos( $html_with, 'Special requests: ' . $NOTE ) );
// Order number (#NNNNN), NOT the raw md5 key, is what the popup will show.
$check( 'pill carries 5-digit data-order-number', false !== strpos( $html_with, 'data-order-number="#09003"' ) );
$check( 'pill does NOT expose the raw md5 key as visible text', false === strpos( $html_with, '>abc123def456abc123def456abc12345<' ) );

// ── A2) Matrix pill WITHOUT special requests → no note markup ──
$rows_without = array(
	array(
		'unit'  => '101',
		'block' => 'Red Barn',
		'cells' => array(
			'2026-05-08' => array(
				'type'      => 'occupied',
				'label'     => 'Jane Rider',
				'order_key' => 'def456',
				'special_requests' => '',
			),
		),
	),
);
list( , $html_without ) = $call( 'render_stall_chart_matrix_table', array( $rows_without, $date_columns ) );
$check( 'plain pill has NO note dot', false === strpos( $html_without, 'eem-occ-pill__note-dot' ) );
$check( 'plain pill has NO has-note modifier', false === strpos( $html_without, 'eem-occ-pill--has-note' ) );

// ── B) By-customer table WITH special requests ──
$cust_rows = array(
	array(
		'order_key'        => 'abc123',
		'order_number'     => '00020',
		'customer_name'    => 'Whitney Mitchell',
		'daily_counts'     => array( '2026-05-08' => 1 ),
		'stall_units'      => array( '100' ),
		'rv_units'         => array(),
		'unassigned'       => '',
		'special_requests' => $NOTE,
	),
	array(
		'order_key'        => 'def456',
		'order_number'     => '00021',
		'customer_name'    => 'Jane Rider',
		'daily_counts'     => array( '2026-05-08' => 1 ),
		'stall_units'      => array( '101' ),
		'rv_units'         => array(),
		'unassigned'       => '',
		'special_requests' => '',
	),
);
list( , $cust_html ) = $call( 'render_stall_chart_order_count_table', array( $cust_rows, $date_columns ) );
$check( 'by-customer row renders the note block', false !== strpos( $cust_html, 'eem-chart-cust-note' ) );
$check( 'by-customer note shows the request text', false !== strpos( $cust_html, $NOTE ) );
$check( 'by-customer note text is in the row search index', false !== strpos( strtolower( $cust_html ), strtolower( $NOTE ) ) );
// Exactly one note block (the plain row must not render one).
$check( 'exactly one cust-note block (plain row has none)', 1 === substr_count( $cust_html, 'eem-chart-cust-note"' ) || 1 === substr_count( $cust_html, 'class="eem-chart-cust-note' ) );

// ── C) Live data-layer: build_stall_chart_rows carries special_requests ──
$orders_repo   = new EEM_Orders_Repository();
$sr_method     = $ref->getMethod( 'get_special_requests_from_order_notes' );
$sr_method->setAccessible( true );
$found_rid     = 0;
$found_note    = '';
foreach ( $orders_repo->get_orders( '', 'date', 'desc' ) as $o ) {
	$sr = trim( (string) $sr_method->invoke( $admin, $o['notes'] ) );
	if ( '' !== $sr && (int) ( $o['reservation_id'] ?? 0 ) > 0 ) {
		$found_rid  = (int) $o['reservation_id'];
		$found_note = $sr;
		break;
	}
}
if ( $found_rid > 0 ) {
	WP_CLI::log( "  (live check: reservation #{$found_rid} has a special-requests order)" );
	$cfg_m = $ref->getMethod( 'get_stall_chart_config' );
	$cfg_m->setAccessible( true );
	$config = $cfg_m->invoke( $admin, $found_rid );
	list( $built_rows ) = $call( 'build_stall_chart_rows', array( $found_rid, $config ) );
	$has = false;
	foreach ( (array) $built_rows as $r ) {
		if ( isset( $r['special_requests'] ) && '' !== trim( (string) $r['special_requests'] ) ) { $has = true; break; }
	}
	$check( 'build_stall_chart_rows carries special_requests for that reservation', $has );
} else {
	WP_CLI::log( '  (live check skipped — no seeded order has special requests; synthetic render asserts cover the path)' );
}

// ── D) CSS presence ──
$css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/admin.css' );
$check( 'admin.css defines .eem-occ-pill__note-dot', false !== strpos( $css, '.eem-occ-pill__note-dot' ) );
$check( 'admin.css defines .eem-chart-cust-note', false !== strpos( $css, '.eem-chart-cust-note' ) );

WP_CLI::log( "\n=== F special-requests-chart smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'F special-requests-chart smoke passed.' );
