<?php
/**
 * V1 D2 smoke — Group Name field + Stall Charts display + Show-by-group filter.
 *
 * Covers: the group chip + pill data attr render; the Group-Name line does NOT
 * leak into displayed special requests; live build_stall_chart_rows carries
 * group names; and the checkout form / capture / notes-assembly / JS toggle are
 * wired (source presence).
 *
 * Run: wp eval-file tests/smoke/d2-group-name-smoke.php
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
$priv  = function ( $name ) use ( $admin, $ref ) {
	$m = $ref->getMethod( $name );
	$m->setAccessible( true );
	return $m;
};
$render = function ( $name, array $args ) use ( $admin, $priv ) {
	ob_start();
	$priv( $name )->invokeArgs( $admin, $args );
	return ob_get_clean();
};

$GROUP = 'Bluegrass Trailer Crew';
$NOTE  = 'Shaded side if available';
$date_columns = array( '2026-05-08' => 'May 8' );

// ── A) By-customer row renders the group chip + data-group, search includes it ──
$rows = array(
	array(
		'order_key' => 'k1', 'order_number' => '9006', 'customer_name' => 'Tobias Klein',
		'daily_counts' => array( '2026-05-08' => 1 ), 'stall_units' => array( '109' ), 'rv_units' => array(),
		'unassigned' => '', 'special_requests' => '', 'group_name' => $GROUP,
	),
	array(
		'order_key' => 'k2', 'order_number' => '9012', 'customer_name' => 'Aisha Rahman',
		'daily_counts' => array( '2026-05-08' => 1 ), 'stall_units' => array( '123' ), 'rv_units' => array(),
		'unassigned' => '', 'special_requests' => '', 'group_name' => '',
	),
);
$cust_html = $render( 'render_stall_chart_order_count_table', array( $rows, $date_columns ) );
$check( 'by-customer renders the group chip', false !== strpos( $cust_html, 'eem-chart-cust-icon--group' ) );
$check( 'group chip shows the group name', false !== strpos( $cust_html, $GROUP ) );
$check( 'grouped row carries data-group', false !== strpos( $cust_html, 'data-group="' . esc_attr( $GROUP ) . '"' ) );
$check( 'ungrouped row has empty data-group', false !== strpos( $cust_html, 'data-group=""' ) );
$check( 'group name folded into row search index', false !== strpos( strtolower( $cust_html ), strtolower( $GROUP ) ) );
$check( 'exactly one group chip (ungrouped row has none)', 1 === substr_count( $cust_html, 'eem-chart-cust-icon--group' ) );

// ── B) Matrix pill carries data-group-name ──
$pill_rows = array( array(
	'unit' => '109', 'block' => 'Red Barn',
	'cells' => array( '2026-05-08' => array(
		'type' => 'occupied', 'label' => 'Tobias Klein', 'order_key' => 'k1',
		'order_number' => '9006', 'special_requests' => '', 'group_name' => $GROUP,
	) ),
) );
$pill_html = $render( 'render_stall_chart_matrix_table', array( $pill_rows, $date_columns ) );
$check( 'pill carries data-group-name', false !== strpos( $pill_html, 'data-group-name="' . esc_attr( $GROUP ) . '"' ) );

// ── C) Group Name does NOT leak into displayed special requests (admin parser) ──
$sr = $priv( 'get_special_requests_from_order_notes' );
$notes = "Reservation setup ID: 3499\nSubmission token: abc\nGroup Name: {$GROUP}\n{$NOTE}";
$parsed = trim( (string) $sr->invoke( $admin, $notes ) );
$check( 'special requests = the freeform line only', $parsed === $NOTE );
$check( 'special requests excludes the Group Name line', false === strpos( $parsed, 'Group Name' ) && false === strpos( $parsed, $GROUP ) );

// group-name extractor returns the value
$gn = $priv( 'get_group_name_from_order_notes' );
$check( 'get_group_name_from_order_notes returns the tag', $GROUP === $gn->invoke( $admin, $notes ) );
$check( 'get_group_name_from_order_notes empty when absent', '' === $gn->invoke( $admin, "Reservation setup ID: 3499\nJust a note" ) );

// ── D) Live: build_stall_chart_rows carries group names; no leak into requests ──
// Discover the reservation that carries the seeded group orders (tools/seed-test-data.php
// targets whichever reservation has a configured chart — #5990 on the dev box), so
// this doesn't depend on a hardcoded id.
$seed_rid = 0;
foreach ( get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $cand ) {
	$ccfg = $priv( 'get_stall_chart_config' )->invoke( $admin, (int) $cand );
	$crow = $priv( 'build_stall_chart_rows' )->invoke( $admin, (int) $cand, $ccfg );
	foreach ( (array) $crow as $rr ) {
		if ( $GROUP === trim( (string) ( $rr['group_name'] ?? '' ) ) ) { $seed_rid = (int) $cand; break 2; }
	}
}
$check( 'found a seeded reservation with group orders (run tools/seed-test-data.php first)', $seed_rid > 0 );
$cfg  = $priv( 'get_stall_chart_config' )->invoke( $admin, $seed_rid );
$brow = $priv( 'build_stall_chart_rows' )->invoke( $admin, $seed_rid, $cfg );
$groups = array(); $leak = false;
foreach ( (array) $brow as $r ) {
	$g = trim( (string) ( $r['group_name'] ?? '' ) );
	if ( '' !== $g ) { $groups[ $g ] = ( $groups[ $g ] ?? 0 ) + 1; }
	if ( false !== stripos( (string) ( $r['special_requests'] ?? '' ), 'Group Name' ) ) { $leak = true; }
}
WP_CLI::log( '  (live groups: ' . wp_json_encode( $groups ) . ')' );
$check( 'live chart rows carry "Bluegrass Trailer Crew" (seeded x3)', ( $groups[ $GROUP ] ?? 0 ) >= 3 );
$check( 'live chart rows carry "Delgado Performance Horses"', ( $groups['Delgado Performance Horses'] ?? 0 ) >= 1 );
$check( 'no Group Name leak into any special_requests', ! $leak );

// ── E) Source wiring ──
$short = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
$adm   = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$js    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );
$check( 'checkout form has the group_name input', false !== strpos( $short, 'name="group_name"' ) );
$check( 'submission captures group_name', false !== strpos( $short, "'group_name'" ) );
$check( 'notes assembly includes Group Name line', false !== strpos( $short, 'Group Name: ' ) );
$check( 'shortcodes parser strips Group Name', false !== strpos( $short, 'Group Name|' ) );
$check( 'chart has Show-by-group toggle', false !== strpos( $adm, 'stall-chart-toggle-groups' ) );
$check( 'JS has the group toggle handler', false !== strpos( $js, "'stall-chart-toggle-groups'" ) );
$check( 'JS filter accounts for data-group', false !== strpos( $js, "getAttribute('data-group')" ) );

WP_CLI::log( "\n=== D2 group-name smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'D2 group-name smoke passed.' );
