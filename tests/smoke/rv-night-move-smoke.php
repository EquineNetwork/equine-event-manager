<?php
/**
 * RV per-night move smoke (v1 #4).
 *
 * Per-night moves (this-night vs all-nights) were stall-only; this extends them
 * to RV lots. The same move flow drives both kinds — the `kind` (stall|rv) picks
 * which component / assignment note / night-map / chart config the swap reads and
 * writes. An `RV Lot Night Map` note mirrors the stall night map.
 *
 * Helper round-trips run against synthetic order arrays (no DB, no wp_die). The
 * end-to-end AJAX path is verified separately by a runtime diagnostic; here we
 * assert the night-map read/resolve helpers honor the RV component + the handler,
 * matrix render, grid overlay, repo writer, and JS all route by kind.
 *
 * Run: wp eval-file tests/smoke/rv-night-move-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$ref = new ReflectionClass( 'EEM_Admin' );
$admin = $ref->newInstanceWithoutConstructor();
$call = static function ( $admin, $ref, $method, array $args ) {
	$m = $ref->getMethod( $method ); $m->setAccessible( true );
	return $m->invokeArgs( $admin, $args );
};

// --- helper round-trip: RV night-map read + resolve --------------------------
// Synthetic order with an rv component carrying a per-night override note.
$rv_order = array(
	'components' => array(
		array(
			'table' => 'rv',
			'notes' => "Assigned RV Lots: Red Lot 7, Red Lot 8\nRV Lot Night Map: 2026-06-26=Red Lot 9;2026-06-27=Red Lot 8\n",
		),
	),
);

$overrides = $call( $admin, $ref, 'parse_stall_night_overrides', array( $rv_order, 'rv', 'RV Lot Night Map' ) );
$check( 'parse reads the RV Lot Night Map (not the stall map)', isset( $overrides['2026-06-26'] ) && in_array( 'Red Lot 9', $overrides['2026-06-26'], true ) );
$check( 'parse returns each overridden date', isset( $overrides['2026-06-27'] ) && in_array( 'Red Lot 8', $overrides['2026-06-27'], true ) );

// With the default (stall) label the RV map must NOT leak in.
$stall_view = $call( $admin, $ref, 'parse_stall_night_overrides', array( $rv_order ) );
$check( 'stall-label parse ignores the RV night map', array() === $stall_view );

// Resolve per-night assignment: override night uses Red Lot 9, other nights fall
// back to the flat whole-stay set.
$dates = array( '2026-06-26', '2026-06-27' );
$flat  = array( 'Red Lot 7', 'Red Lot 8' );
$nights = $call( $admin, $ref, 'get_order_night_assignments', array( $rv_order, $dates, $flat, 'rv', 'RV Lot Night Map' ) );
$check( 'override night resolves to the moved lot', in_array( 'Red Lot 9', (array) $nights['2026-06-26'], true ) );
$check( 'non-override night falls back to the flat set', in_array( 'Red Lot 8', (array) $nights['2026-06-27'], true ) );

// Serialize: a uniform night set collapses to '' (no map needed).
$uniform = array( '2026-06-26' => array( 'Red Lot 7' ), '2026-06-27' => array( 'Red Lot 7' ) );
$check( 'serialize collapses a uniform stay to empty', '' === $call( $admin, $ref, 'serialize_stall_night_map', array( $uniform ) ) );
$mixed = array( '2026-06-26' => array( 'Red Lot 9' ), '2026-06-27' => array( 'Red Lot 8' ) );
$ser = $call( $admin, $ref, 'serialize_stall_night_map', array( $mixed ) );
$check( 'serialize writes every date for a mixed stay', false !== strpos( $ser, '2026-06-26=Red Lot 9' ) && false !== strpos( $ser, '2026-06-27=Red Lot 8' ) );

// --- repo writer exists ------------------------------------------------------
$check( 'EEM_Orders_Repository::update_order_rv_night_map() defined', method_exists( 'EEM_Orders_Repository', 'update_order_rv_night_map' ) );

// --- source presence: handler + render + grid + JS route by kind -------------
$admin_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'admin/class-equine-event-manager-admin.php' );
$js_src    = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/js/admin.js' );

$check( 'handler reads a kind param', false !== strpos( $admin_src, "'rv' === sanitize_key( wp_unslash( \$_POST['kind'] ) )" ) );
$check( 'handler picks RV component + labels', false !== strpos( $admin_src, "\$assign_lbl  = \$is_rv ? 'Assigned RV Lots'" ) && false !== strpos( $admin_src, "\$night_lbl   = \$is_rv ? 'RV Lot Night Map'" ) );
$check( 'handler writes the RV night map for rv moves', false !== strpos( $admin_src, 'update_order_rv_night_map( $order_key, $serialized )' ) );
$check( 'handler validates against the RV lot pools', false !== strpos( $admin_src, "\$config['blocked_rv_lots']" ) && false !== strpos( $admin_src, "\$config['rv_lot_names']" ) );
$check( 'matrix render emits data-kind on pills + available cells', 2 <= substr_count( $admin_src, 'data-kind="<?php echo esc_attr( $kind ); ?>"' ) );
// Whitespace-normalized so the call-signature match survives re-alignment, and
// matched only up to the kind + reservation_id args so it tolerates trailing
// params added later (e.g. the $render_secondary_column flag on the stall call).
$admin_src_n = preg_replace( '/[ \t]+/', ' ', $admin_src );
$check( 'matrix callers pass stall + rv kinds', false !== strpos( $admin_src_n, "'stall', (int) \$reservation_id" ) && false !== strpos( $admin_src_n, "'rv', (int) \$reservation_id" ) );
$check( 'grid overlay applies the RV Lot Night Map', false !== strpos( $admin_src, "get_order_component_note_value( \$order, 'rv', 'RV Lot Night Map' )" ) );

$check( 'JS captures the pill kind', false !== strpos( $js_src, "window._scActiveKind      = pill.getAttribute('data-kind') || 'stall'" ) );
$check( 'JS gates same-kind destination drops', false !== strpos( $js_src, "if (destKind !== (window._scActiveKind || 'stall'))" ) );
$check( 'JS forwards kind in the move POST', false !== strpos( $js_src, "body.set('kind', window._scActiveKind || 'stall')" ) );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
