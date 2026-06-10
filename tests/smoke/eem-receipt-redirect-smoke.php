<?php
/**
 * #8 hosted-receipt redirect — script-survival guard.
 *
 * After a successful customer submission the form must client-redirect to the
 * hosted receipt. The redirect <script> previously lived inside the success
 * notice string, which is rendered through wp_kses_post() — that STRIPS
 * <script> tags (leaving the JS as visible page text and never redirecting).
 * The fix carries the URL on $pending_redirect_url and emits the <script> from
 * the shortcode's own (non-kses'd) template output.
 *
 * This guard sets $pending_redirect_url via reflection, renders the real
 * [en_reservation] form, and asserts the intact <script>window.location.replace
 * survives BOTH the kses boundary (it's outside $message) AND wpautop()
 * (the_content's autop pass). Regression guard for the "receipt shows as raw
 * text" bug.
 */

$pass = 0; $fail = 0; $log = array();
function rok( $label, $cond, &$pass, &$fail, &$log ) {
	if ( $cond ) { $pass++; } else { $fail++; $log[] = "FAIL: $label"; }
}

// Seed a published, event-linked reservation so the form renders (not the gate).
$rid = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => 'Receipt Redirect Smoke',
) );
update_post_meta( $rid, '_en_event_source',            'feed' );
update_post_meta( $rid, '_en_use_global_event_source', 0 );
update_post_meta( $rid, '_en_external_event_id',       'ext-receipt-redirect' );
update_post_meta( $rid, '_en_external_event_title',    'Receipt Redirect Event' );
update_post_meta( $rid, '_en_stalls_enabled',          1 );
register_shutdown_function( static function () use ( $rid ) {
	if ( $rid ) { wp_delete_post( (int) $rid, true ); }
} );

$test_url = 'http://en-event-manager.local/?eem_receipt=deadbeefdeadbeefdeadbeefdeadbeef';

$sc  = new EEM_Shortcodes();
$ref = new ReflectionProperty( $sc, 'pending_redirect_url' );
$ref->setAccessible( true );
$ref->setValue( $sc, $test_url );

$html = $sc->render_reservation( array( 'id' => $rid ) );

rok( 'render produced output', is_string( $html ) && strlen( $html ) > 200, $pass, $fail, $log );

// The redirect must live inside an intact <script> tag (NOT kses-stripped to bare
// text). The script carries a sessionStorage.removeItem(...) try/catch prefix
// before the redirect (autosave-state cleanup), so match the call within the same
// <script> tag rather than immediately after the opening tag.
$intact = (bool) preg_match( '#<script>[^<]*window\.location\.replace\(#', $html );
rok( 'intact <script>…window.location.replace( present', $intact, $pass, $fail, $log );
rok( 'redirect URL present in script',                  false !== strpos( $html, 'eem_receipt=deadbeef' ), $pass, $fail, $log );

// Negative: the bug signature was the JS statement appearing WITHOUT its <script>
// wrapper (kses stripped the tag). Strip all <script>…</script> blocks and assert
// no bare window.location.replace survives.
$no_scripts = preg_replace( '#<script>.*?</script>#s', '', $html );
rok( 'no kses-stripped (tagless) redirect statement', false === strpos( (string) $no_scripts, 'window.location.replace' ), $pass, $fail, $log );

// Survives wpautop() (the_content autop pass) — wpautop must not break/strip it.
$after_autop = wpautop( $html );
rok( 'redirect <script> survives wpautop()', (bool) preg_match( '#<script>[^<]*window\.location\.replace\(#', $after_autop ), $pass, $fail, $log );

// Control: confirm wp_kses_post() WOULD strip it (documents why the script can't
// live inside the kses'd $message).
$kses_demo = wp_kses_post( '<script>window.location.replace("x");</script>' );
rok( 'wp_kses_post strips <script> (root-cause control)', false === strpos( $kses_demo, '<script>' ), $pass, $fail, $log );

echo "\n=== #8 receipt-redirect script-survival smoke: $pass passed, $fail failed ===\n";
foreach ( $log as $l ) { echo "  $l\n"; }
if ( $fail > 0 ) { WP_CLI::error( "$fail assertion(s) failed" ); }
