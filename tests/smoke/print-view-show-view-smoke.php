<?php
/**
 * Stall-chart print-view SHOW/VIEW smoke (#234 backfill — 2.7.467).
 *
 * The print-view rework (2.7.441–466) added two toggles alongside the existing
 * `rows` one, mirroring the live screen:
 *   - VIEW: 'location' (By Location) | 'customer' (By Customer)
 *   - SHOW: 'all' | 'stalls' | 'rv'
 * The existing print-view-assigned-only-smoke covers the `rows` toggle; this
 * backfills the SHOW + VIEW parameter contracts and the `all_rv` rows branch,
 * plus a fixture-gated functional render in each mode.
 *
 * Run: wp eval-file tests/smoke/print-view-show-view-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};
$skip = static function ( string $label ): void { echo "  ..  - SKIP: {$label}\n"; };

// --- source-presence: the SHOW/VIEW/rows parameter contracts ---------------
$ref = new ReflectionClass( 'EEM_Admin' );
$src = (string) file_get_contents( $ref->getFileName() );

$check( 'VIEW param parsed from $_GET[view]', false !== strpos( $src, "\$pv_view = isset( \$_GET['view'] )" ) );
$check( 'VIEW defaults to location', false !== strpos( $src, "? sanitize_key( wp_unslash( \$_GET['view'] ) ) : 'location'" ) );
$check( 'VIEW whitelist is location|customer', false !== strpos( $src, "in_array( \$pv_view, array( 'location', 'customer' ), true )" ) );

$check( 'SHOW param parsed from $_GET[show]', false !== strpos( $src, "\$pv_show = isset( \$_GET['show'] )" ) );
$check( 'SHOW defaults to all', false !== strpos( $src, "? sanitize_key( wp_unslash( \$_GET['show'] ) ) : 'all'" ) );
$check( 'SHOW whitelist is all|stalls|rv', false !== strpos( $src, "in_array( \$pv_show, array( 'all', 'stalls', 'rv' ), true )" ) );

$check( 'rows whitelist includes all_rv', false !== strpos( $src, "in_array( \$pv_rows, array( 'assigned', 'all', 'all_rv' ), true )" ) );
$check( 'per-inventory stall_assigned_only derived', false !== strpos( $src, "\$stall_assigned_only = ( 'all' !== \$pv_rows )" ) );
$check( 'per-inventory rv_assigned_only derived', false !== strpos( $src, "\$rv_assigned_only    = ( 'all_rv' !== \$pv_rows )" ) );
$check( 'nav-url builder preserves view/show/rows', false !== strpos( $src, "'view' => \$pv_view, 'show' => \$pv_show, 'rows' => \$pv_rows" ) );

// --- functional render (fixture-gated) -------------------------------------
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admins ) { wp_set_current_user( $admins[0]->ID ); }

// Any published reservation with stalls OR RV enabled is enough to render.
$target = 0;
$reservations = get_posts( array( 'post_type' => 'en_reservation', 'post_status' => 'publish', 'numberposts' => 50, 'fields' => 'ids' ) );
foreach ( $reservations as $cand_id ) {
	if ( '1' === (string) get_post_meta( $cand_id, '_en_stalls_enabled', true )
		|| '1' === (string) get_post_meta( $cand_id, '_en_rv_enabled', true ) ) {
		$target = (int) $cand_id; break;
	}
}

if ( $target < 1 ) {
	$skip( 'no published reservation with stalls/RV enabled — functional render assertions skipped' );
} else {
	$admin  = new EEM_Admin();
	$render = static function ( $admin, $rid, $view, $show ) {
		$_GET = array(
			'page' => 'equine-event-manager-stall-chart-print',
			'reservation_id' => (string) $rid, 'view' => $view, 'show' => $show, 'rows' => 'assigned',
		);
		ob_start(); $admin->render_stall_chart_print_page(); $html = (string) ob_get_clean(); $_GET = array();
		return $html;
	};

	$loc = $render( $admin, $target, 'location', 'all' );
	$cust = $render( $admin, $target, 'customer', 'all' );
	echo "  ..  - using reservation #{$target}\n";
	$check( 'VIEW=location renders a non-trivial page', strlen( $loc ) > 500 );
	$check( 'VIEW=customer renders a non-trivial page', strlen( $cust ) > 500 );
	$check( 'location and customer views differ', $loc !== $cust );

	$show_all    = $render( $admin, $target, 'location', 'all' );
	$show_stalls = $render( $admin, $target, 'location', 'stalls' );
	$show_rv     = $render( $admin, $target, 'location', 'rv' );
	$check( 'SHOW=all renders', strlen( $show_all ) > 500 );
	$check( 'SHOW=stalls renders', strlen( $show_stalls ) > 500 );
	$check( 'SHOW=rv renders', strlen( $show_rv ) > 500 );

	// Invalid params fall back to defaults rather than fataling.
	$bad = $render( $admin, $target, 'bogus', 'bogus' );
	$check( 'invalid view/show fall back without error', strlen( $bad ) > 500 );
}

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
