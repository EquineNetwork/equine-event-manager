<?php
/**
 * Native-events source-gating regression smoke.
 *
 * Guards the fix for the "radio says Native Events but the Events/Venues/
 * Producers sidebar items vanish" drift: EEM_Events::is_native_events_enabled()
 * must track the canonical Settings → Integrations event-source picker
 * (`default_event_source`) so menu/taxonomy visibility can never diverge from
 * what the radio shows — even if the legacy `native_events_enabled` mirror flag
 * has drifted to 0.
 *
 * Run: wp eval-file tests/smoke/native-events-source-gating-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }

$passed = 0; $failed = 0;
$check = static function ( string $label, bool $ok ) use ( &$passed, &$failed ): void {
	if ( $ok ) { $passed++; echo "  ok  - {$label}\n"; }
	else { $failed++; echo "FAIL  - {$label}\n"; }
};

$int_opt  = 'equine_event_manager_integration_settings';
$feat_opt = 'equine_event_manager_feature_settings';

// Snapshot to restore at the end (don't disturb the dev site's real config).
$int_before  = get_option( $int_opt, array() );
$feat_before = get_option( $feat_opt, array() );

$set = static function ( $source, $flag ) use ( $int_opt, $feat_opt ) {
	$i = get_option( $int_opt, array() );
	$i = is_array( $i ) ? $i : array();
	$i['default_event_source'] = $source;
	update_option( $int_opt, $i );
	$f = get_option( $feat_opt, array() );
	$f = is_array( $f ) ? $f : array();
	$f['native_events_enabled'] = $flag;
	update_option( $feat_opt, $f );
};

// 1. Canonical case: source=native + flag=1 → enabled.
$set( 'native', 1 );
$check( 'source=native, flag=1 → enabled', true === EEM_Events::is_native_events_enabled() );

// 2. THE DRIFT CASE (the bug): source=native but the mirror flag drifted to 0 →
//    must STILL be enabled, because the source radio is canonical.
$set( 'native', 0 );
$check( 'source=native, flag=0 (drift) → STILL enabled', true === EEM_Events::is_native_events_enabled() );

// 3. Legacy fallback: source=tec but the legacy flag is still 1 → enabled via the
//    backward-compatible fallback (pre-unification installs).
$set( 'tec', 1 );
$check( 'source=tec, flag=1 (legacy fallback) → enabled', true === EEM_Events::is_native_events_enabled() );

// 4. Fully off: source=tec + flag=0 → disabled.
$set( 'tec', 0 );
$check( 'source=tec, flag=0 → disabled', false === EEM_Events::is_native_events_enabled() );

// 5. GEMS source + flag=0 → disabled.
$set( 'feed', 0 );
$check( 'source=feed, flag=0 → disabled', false === EEM_Events::is_native_events_enabled() );

// --- restore -----------------------------------------------------------------
update_option( $int_opt, $int_before );
update_option( $feat_opt, $feat_before );

echo "\n{$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) { exit( 1 ); }
