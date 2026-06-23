<?php
/**
 * Smoke — Add-On Report (ROADMAP v1 #4).
 *
 * Verifies the new 'add_ons' report is registered + routed + catalogued +
 * exportable (CSV/PDF), and that its summary / per-day worksheet aggregation is
 * correct. Orders are injected via a EEM_Reports_Repo subclass so the smoke is
 * DB-free (no fixture dependency); the export layer runs against the real
 * exporter.
 *
 * Run: wp eval-file tests/smoke/add-ons-report-smoke.php
 */

$passed = 0;
$failed = 0;
$check  = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

// DB-free repo: inject synthetic orders.
$repo = new class() extends EEM_Reports_Repo {
	public array $orders = array();
	public function get_filtered_orders( array $filters ): array { return $this->orders; }
};

// --- registration + routing -------------------------------------------------
$check( "'add_ons' registered in REPORTS", in_array( 'add_ons', EEM_Reports_Repo::REPORTS, true ) );
$check( 'get_report routes add_ons', 'add_ons' === ( $repo->get_report( 'add_ons', array( 'reservation_id' => 0 ) )['slug'] ?? '' ) );

// catalog entry present on the admin page
$cat_ref = new ReflectionMethod( 'EEM_Reports_Page', 'report_catalog' );
$cat_ref->setAccessible( true );
$catalog = $cat_ref->invoke( new EEM_Reports_Page() );
$slugs   = array_map( static function ( $c ) { return $c['slug']; }, $catalog );
$check( 'catalog lists add_ons', in_array( 'add_ons', $slugs, true ) );

// --- summary (all reservations) ---------------------------------------------
$repo->orders = array(
	array( 'notes' => "Add-On: Hay | Qty: 3 | Per: bale | Subtotal: 30.00\nAdd-On: Fan | Qty: 1 | Per: each | Subtotal: 20.00" ),
	array( 'notes' => "Add-On: Hay | Qty: 2 | Per: bale | Subtotal: 20.00" ),
	array( 'notes' => 'No add-ons' ),
);
$summary = $repo->get_report( 'add_ons', array( 'reservation_id' => 0 ) );
$check( 'summary: Hay first, qty 5 across 2 orders', isset( $summary['rows'][0] ) && 'Hay' === $summary['rows'][0][0] && '2' === $summary['rows'][0][1] && '5' === $summary['rows'][0][2] );
$check( 'summary: only add-on orders counted', count( $summary['rows'] ) === 2 );

// --- daily (single reservation) ---------------------------------------------
$repo->orders = array(
	array( 'notes' => 'Add-On: Hay | Qty: 2 | Per: bale | Subtotal: 20.00', 'stall_arrival_date' => '2026-06-01', 'stall_departure_date' => '2026-06-03' ),
	array( 'notes' => "Add-On: Hay | Qty: 1 | Per: bale | Subtotal: 10.00\nAdd-On: Fan | Qty: 1 | Per: each | Subtotal: 20.00", 'stall_arrival_date' => '2026-06-02', 'stall_departure_date' => '2026-06-02' ),
	array( 'notes' => 'Add-On: Parking | Qty: 4 | Per: each | Subtotal: 40.00', 'stall_arrival_date' => '', 'stall_departure_date' => '' ),
);
$daily = $repo->get_report( 'add_ons', array( 'reservation_id' => 7 ) );
$check( 'daily: dynamic columns Date,Fan,Hay,Total', $daily['headers'] === array( 'Date', 'Fan', 'Hay', 'Total' ) );
$check( 'daily: pinned TOTALS row', ( $daily['summary_row_count'] ?? 0 ) === 1 );
$check( 'daily: Hay total = 7 (2×3 days + 1)', isset( $daily['rows'][0][2] ) && '7' === $daily['rows'][0][2] );
$check( 'daily: grand total = 8', isset( $daily['rows'][0][3] ) && '8' === $daily['rows'][0][3] );
$check( 'daily: Jun 2 has Fan 1 + Hay 3', isset( $daily['rows'][2] ) && '1' === $daily['rows'][2][1] && '3' === $daily['rows'][2][2] );
$check( 'daily: undated Parking in note section', ! empty( $daily['note_sections'] ) && 'Parking' === $daily['note_sections'][0]['rows'][0][0] && '4' === $daily['note_sections'][0]['rows'][0][1] );

// --- export layer (real exporter consumes the shape) ------------------------
$exporter = new EEM_Report_Exporter();
$csv = $exporter->build_csv( $daily );
$check( 'CSV export includes headers', str_contains( $csv, 'Hay' ) && str_contains( $csv, 'Total' ) );
$check( 'CSV export includes a TOTALS row', str_contains( $csv, 'TOTALS' ) );
if ( method_exists( $exporter, 'build_pdf' ) ) {
	$pdf = $exporter->build_pdf( $daily, array( 'title' => 'Add-Ons' ) );
	$check( 'PDF export produces a non-empty document', is_string( $pdf ) && strlen( $pdf ) > 100 );
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
