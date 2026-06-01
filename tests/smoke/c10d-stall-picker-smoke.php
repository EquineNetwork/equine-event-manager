<?php
/**
 * C10.D (2.3.76) smoke — "Pick Your Stalls" interactive picker.
 *
 * Renders the canonical seed reservation (43) and asserts the picker structure
 * from .mockups/event_page.html: box + header + legend + row sections (one-sided
 * and back-to-back with an aisle) + selectable cells posting preferred_stall_units[]
 * + blocked/reserved inert cells + the selection summary. Plus unit tests on the
 * label-range expander (numeric / prefixed / padded). Read-only — no mutation.
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c10d_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ok  - {$l}"; }
	else      { $f++; $lg[] = "FAIL  - {$l}" . ( $d ? " ({$d})" : '' ); }
}

echo "\n=== C10.D — Pick Your Stalls smoke ===\n";

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admin ) { wp_set_current_user( $admin[0]->ID ); }

$sc = new EEM_Shortcodes();

/* ── 1. Label-range expander ── */
$em = new ReflectionMethod( 'EEM_Shortcodes', 'expand_stall_label_range' );
$em->setAccessible( true );
c10d_ok( 'expands numeric 100..103', array( '100', '101', '102', '103' ) === $em->invoke( $sc, '100', '103' ), $pass, $fail, $log );
c10d_ok( 'expands prefixed Y1..Y4', array( 'Y1', 'Y2', 'Y3', 'Y4' ) === $em->invoke( $sc, 'Y1', 'Y4' ), $pass, $fail, $log );
c10d_ok( 'expands padded A-01..A-03', array( 'A-01', 'A-02', 'A-03' ) === $em->invoke( $sc, 'A-01', 'A-03' ), $pass, $fail, $log, implode( ',', $em->invoke( $sc, 'A-01', 'A-03' ) ) );

/* ── 2. Render res 43 ── */
$html = $sc->render_reservation( array( 'id' => 43 ) );

c10d_ok( 'picker box rendered', str_contains( $html, 'data-eem-stall-picker' ), $pass, $fail, $log );
c10d_ok( 'title "Pick Your Stalls"', str_contains( $html, 'Pick Your Stalls' ), $pass, $fail, $log );
c10d_ok( 'legend present (4 dots)', 4 === substr_count( $html, 'class="legend-dot' ), $pass, $fail, $log, (string) substr_count( $html, 'class="legend-dot' ) );
c10d_ok( 'back-to-back row + aisle', str_contains( $html, 'picker-stall-row back-to-back' ) && str_contains( $html, 'picker-stall-row-aisle' ), $pass, $fail, $log );
c10d_ok( 'one-sided row present', str_contains( $html, 'class="picker-stall-row">' ), $pass, $fail, $log );
c10d_ok( 'row meta shows stall count + layout', str_contains( $html, 'stalls · Back-to-back' ) && str_contains( $html, 'stalls · One-sided' ), $pass, $fail, $log );

// Selectable cells post preferred_stall_units[] (server already validates this field).
preg_match_all( '/name="preferred_stall_units\[\]" value="([^"]+)"/', $html, $sel );
c10d_ok( 'selectable cells post preferred_stall_units[]', count( $sel[1] ) > 0, $pass, $fail, $log, (string) count( $sel[1] ) );

// Blocked label 105 renders as an inert blocked cell (res 43 _en_blocked_stalls = 105,107,Y3).
c10d_ok( 'blocked stall 105 is inert', (bool) preg_match( '/picker-stall blocked"[^>]*>105</', $html ), $pass, $fail, $log );
c10d_ok( 'blocked cells are NOT selectable', ! in_array( '105', $sel[1], true ), $pass, $fail, $log );

// Selection summary shell.
c10d_ok( 'count element present', str_contains( $html, 'data-eem-stall-count' ), $pass, $fail, $log );
c10d_ok( 'list element present', str_contains( $html, 'data-eem-stall-list' ), $pass, $fail, $log );
c10d_ok( 'max-warning element present', str_contains( $html, 'data-eem-stall-warn' ), $pass, $fail, $log );

/* ── 3. JS + CSS presence ── */
$src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'public/class-equine-event-manager-shortcodes.php' );
c10d_ok( 'syncStallPicker JS present', str_contains( $src, 'function syncStallPicker(form)' ), $pass, $fail, $log );
c10d_ok( 'max-cap enforcement present', str_contains( $src, 'eem-stall-picker-input' ) && str_contains( $src, 'inp.checked = false' ), $pass, $fail, $log );
$css = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'assets/css/public.css' );
c10d_ok( 'picker CSS scoped to .eem-event-page', str_contains( $css, '.eem-event-page .picker-stall {' ), $pass, $fail, $log );

/* ── Version ── */
c10d_ok( 'version >= 2.3.76', version_compare( EQUINE_EVENT_MANAGER_VERSION, '2.3.76', '>=' ), $pass, $fail, $log, EQUINE_EVENT_MANAGER_VERSION );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
