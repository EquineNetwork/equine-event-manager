<?php
/**
 * DS-1.B live-wire smoke — Dashboard placeholders wired to live data.
 *
 * Asserts the C8/C11 "Pending …" em-dash placeholders are gone and replaced
 * with real EEM_Admin stall-metric queries, and that the aggregation math is
 * internally consistent.
 *
 * Run: wp eval-file tests/smoke/ds1b-dashboard-livewire-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail = 0; $pass = 0;
$check = function ( $label, $cond ) use ( &$fail, &$pass ) {
	if ( $cond ) { $pass++; WP_CLI::log( "  ok  — {$label}" ); }
	else { $fail++; WP_CLI::warning( "FAIL — {$label}" ); }
};

$is_num_str = static function ( $v ) {
	// number_format_i18n output: digits with optional grouping separators.
	return is_string( $v ) && '' !== $v && preg_match( '/^[0-9][0-9,. ]*$/', $v );
};
$no_placeholder = static function ( $s ) {
	$s = (string) $s;
	// A placeholder is a LEADING em-dash ("— customers haven't signed…") or a
	// "Pending …" marker — not a mid-string em-dash separator, which wired titles
	// legitimately use (e.g. "29 stalls unassigned — Event Name").
	return ! preg_match( '/^\s*—/u', $s )
		&& false === stripos( $s, 'Pending C8' )
		&& false === stripos( $s, 'Pending C11' );
};

// ── for_compute() caches a single hook-free instance ──
$a1 = EEM_Admin::for_compute();
$a2 = EEM_Admin::for_compute();
$check( 'for_compute() returns an EEM_Admin', $a1 instanceof EEM_Admin );
$check( 'for_compute() is memoised (same instance)', $a1 === $a2 );

// ── Metrics shape + aggregation consistency ──
$m = $a1->get_dashboard_stall_metrics();
foreach ( array( 'stalls_unassigned_total', 'stalls_assigned_total', 'rv_unassigned_total', 'per_reservation', 'unconfigured', 'assigned_by_order_key' ) as $key ) {
	$check( "metrics has key '{$key}'", array_key_exists( $key, $m ) );
}
$sum_un = 0; $sum_as = 0;
foreach ( $m['per_reservation'] as $pr ) {
	$sum_un += (int) $pr['unassigned'];
	$sum_as += (int) $pr['assigned'];
	$check( 'per_reservation total = assigned + unassigned', (int) $pr['total'] === (int) $pr['assigned'] + (int) $pr['unassigned'] );
}
$check( 'sum(per_reservation unassigned) == stalls_unassigned_total', $sum_un === (int) $m['stalls_unassigned_total'] );
$check( 'sum(per_reservation assigned) == stalls_assigned_total', $sum_as === (int) $m['stalls_assigned_total'] );

$repo = new EEM_Dashboard_Repo();

// ── KPI card 4 (Unassigned Stalls) — wired, no em-dash ──
$kpi = $repo->kpi_cards( 'last-30' );
$check( 'kpi has 4 cards', is_array( $kpi ) && 4 === count( $kpi ) );
$card4 = $kpi[3];
$check( 'KPI card4 label is Unassigned Stalls', __( 'Unassigned Stalls', 'equine-event-manager' ) === $card4['label'] );
$check( 'KPI card4 has no em_dash flag', empty( $card4['em_dash'] ) );
$check( 'KPI card4 value is numeric', $is_num_str( $card4['value'] ) );
$check( 'KPI card4 value matches metrics', (string) number_format_i18n( (int) $m['stalls_unassigned_total'] ) === (string) $card4['value'] );
$check( 'KPI card4 sub is not a placeholder', $no_placeholder( $card4['sub'] ?? '' ) );

// ── Upcoming reservations stall progress — wired ──
$up = $repo->upcoming_reservations();
foreach ( $up as $r ) {
	$sp = $r['stall_progress'];
	$check( 'upcoming stall_progress has no em_dash', empty( $sp['em_dash'] ) );
	$check( 'upcoming stall_progress assigned numeric', $is_num_str( $sp['assigned'] ) );
	$check( 'upcoming stall_progress total numeric', $is_num_str( $sp['total'] ) );
	$check( 'upcoming stall_progress tone valid', in_array( $sp['tone'], array( 'green', 'amber', 'red' ), true ) );
	$check( 'upcoming stall_progress pct in 0..100', is_int( $sp['pct'] ) && $sp['pct'] >= 0 && $sp['pct'] <= 100 );
}

// ── Needs Attention — no placeholders, no agreement row, conditional ──
$att = $repo->attention_items();
$check( 'attention_items returns an array', is_array( $att ) );
$has_agreement = false;
foreach ( $att as $a ) {
	$check( 'attention row has no em_dash', empty( $a['em_dash'] ) );
	$check( 'attention title has no placeholder', $no_placeholder( $a['title'] ) );
	$check( 'attention desc has no placeholder', $no_placeholder( $a['desc'] ) );
	if ( false !== stripos( $a['title'], 'agreement' ) ) { $has_agreement = true; }
}
$check( 'agreement row is omitted (no live signature data in V1)', ! $has_agreement );

// ── This Week — stalls assigned wired ──
$tw = $repo->this_week();
$check( 'this_week has 5 rows', is_array( $tw ) && 5 === count( $tw ) );
$check( 'this_week stalls-assigned row has no em_dash', empty( $tw[4]['em_dash'] ) );
$check( 'this_week stalls-assigned value is numeric', $is_num_str( $tw[4]['value'] ) );

WP_CLI::log( "\n=== DS-1.B dashboard live-wire smoke: {$pass} passed, {$fail} failed ===" );
if ( $fail > 0 ) { WP_CLI::error( "{$fail} assertion(s) failed." ); }
WP_CLI::success( 'DS-1.B dashboard live-wire smoke passed.' );
