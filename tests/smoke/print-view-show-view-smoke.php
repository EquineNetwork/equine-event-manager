<?php
/**
 * Stall-chart print-view SHOW/VIEW smoke (#234 — 2.7.466 print rework).
 *
 * The 06-18 session reworked the print view so its filter bar mirrors the live
 * screen: a SHOW toggle (All / Stalls / RV) and a VIEW toggle (By Location /
 * By Customer), on top of the pre-existing rows toggle (Assigned only / All
 * Stalls / All RV). This smoke covers the SHOW + VIEW axes (the rows axis is
 * covered by print-view-assigned-only-smoke.php).
 *
 * Source-presence assertions always run: param parse + clamp, the three SHOW
 * buttons, the two VIEW buttons, and the section-gating conditionals. When a
 * fixture reservation with an assigned stall exists, the smoke ALSO renders the
 * page across the SHOW/VIEW matrix and asserts the right sections appear/vanish.
 *
 * Run: wp eval-file tests/smoke/print-view-show-view-smoke.php
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

// --- source-presence: SHOW + VIEW param parse, clamp, buttons, gating --------
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );

$check( 'VIEW param read from $_GET[\'view\']', false !== strpos( $src, "\$pv_view = isset( \$_GET['view'] ) ? sanitize_key( wp_unslash( \$_GET['view'] ) ) : 'location'" ) );
$check( 'VIEW param clamps to location|customer', false !== strpos( $src, "if ( ! in_array( \$pv_view, array( 'location', 'customer' ), true ) )" ) );
$check( 'SHOW param read from $_GET[\'show\']', false !== strpos( $src, "\$pv_show = isset( \$_GET['show'] ) ? sanitize_key( wp_unslash( \$_GET['show'] ) ) : 'all'" ) );
$check( 'SHOW param clamps to all|stalls|rv', false !== strpos( $src, "if ( ! in_array( \$pv_show, array( 'all', 'stalls', 'rv' ), true ) )" ) );

$check( 'SHOW=All button rendered', false !== strpos( $src, "'all' === \$pv_show ? ' is-active' : ''" ) );
$check( 'SHOW=Stalls button rendered', false !== strpos( $src, "'stalls' === \$pv_show ? ' is-active' : ''" ) );
$check( 'SHOW=RV button rendered', false !== strpos( $src, "'rv' === \$pv_show ? ' is-active' : ''" ) );
$check( 'VIEW=By Location button rendered', false !== strpos( $src, "'location' === \$pv_view ? ' is-active' : ''" ) );
$check( 'VIEW=By Customer button rendered', false !== strpos( $src, "'customer' === \$pv_view ? ' is-active' : ''" ) );

// Section gating: stall section hidden when SHOW=rv or VIEW=customer; RV section
// hidden when SHOW=stalls or VIEW=customer; By Customer band only on VIEW=customer.
$check( 'stall section gated off for SHOW=rv / VIEW=customer', false !== strpos( $src, "if ( 'customer' !== \$pv_view && 'rv' !== \$pv_show && ! empty( \$grid['stall_rows'] ) )" ) );
$check( 'RV section gated off for SHOW=stalls / VIEW=customer', false !== strpos( $src, "if ( 'customer' !== \$pv_view && 'stalls' !== \$pv_show && ! empty( \$grid['rv_rows'] ) )" ) );
$check( 'By Customer band rendered (pv-section-by-customer)', false !== strpos( $src, 'pv-section-by-customer' ) );
$check( 'By Customer RV columns gated by SHOW != stalls', false !== strpos( $src, "if ( 'stalls' !== \$pv_show ) :" ) );

// --- functional render (fixture-gated) -------------------------------------
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

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
	$render = static function ( $admin, $rid, $view, $show ) {
		$_GET = array( 'page' => 'equine-event-manager-stall-chart-print', 'reservation_id' => (string) $rid, 'view' => $view, 'show' => $show );
		ob_start(); $admin->render_stall_chart_print_page(); $html = (string) ob_get_clean(); $_GET = array();
		return $html;
	};

	$stall_band = '>Stall Units<';
	$rv_band    = '>RV Lots<';
	$cust_band  = 'pv-section-by-customer';

	// SHOW=stalls (By Location): stall band present, RV section band absent.
	$h = $render( $admin, $target, 'location', 'stalls' );
	$check( 'SHOW=stalls renders the Stall Units section band', false !== strpos( $h, $stall_band ) );
	$check( 'SHOW=stalls hides the RV Lots section band', false === strpos( $h, '<div class="pv-section-band"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 3h15v13H1z"' ) );

	// SHOW=rv (By Location): RV band present, stall section band absent.
	$h = $render( $admin, $target, 'location', 'rv' );
	$check( 'SHOW=rv hides the Stall Units section band', false === strpos( $h, $stall_band ) );

	// VIEW=customer: By Customer band present regardless of SHOW.
	$h = $render( $admin, $target, 'customer', 'all' );
	$check( 'VIEW=customer renders the By Customer band', false !== strpos( $h, $cust_band ) );

	// Invalid params clamp to defaults (location/all) → behaves like the default render.
	$_GET = array( 'page' => 'equine-event-manager-stall-chart-print', 'reservation_id' => (string) $target, 'view' => 'bogus', 'show' => 'bogus' );
	ob_start(); $admin->render_stall_chart_print_page(); $clamped = (string) ob_get_clean(); $_GET = array();
	$default = $render( $admin, $target, 'location', 'all' );
	$check( 'invalid view/show clamps to By Location + All (matches default render length)', strlen( $clamped ) === strlen( $default ) );
}

echo "\n{$passed} passed, {$failed} failed, {$skipped} skipped\n";
if ( $failed > 0 ) { exit( 1 ); }
