<?php
/**
 * #41 SAFETY HARNESS — dbDelta baseline vs. live schema drift check.
 *
 * The one-time-migration collapse (#41) can only be safe if the activator's
 * dbDelta CREATE TABLE definitions ALONE (no migrations) reproduce the exact
 * live schema — otherwise a fresh install would be missing the columns that
 * migrations ALTER in, and deleting the migrations would break it.
 *
 * This tool proves that WITHOUT touching the live tables: it temporarily points
 * $wpdb->prefix at a throwaway `<prefix>sdrift_` namespace, runs ONLY the
 * activator's create_* methods (the dbDelta schema, NOT run_one_time_migrations)
 * against it, then diffs every scratch table column-by-column against its live
 * twin. Scratch tables are dropped and the prefix restored on the way out.
 *
 * - Columns in LIVE but not in the dbDelta scratch = drift that MUST be folded
 *   into the CREATE TABLE definition before the migrations can be removed.
 * - Columns in the scratch but not live = the dbDelta defines something the live
 *   table lacks (a different kind of drift — investigate).
 *
 * Exit 0 = no drift (dbDelta baseline == live → collapse is safe to proceed).
 * Exit 1 = drift found (folding work still required) — prints the exact columns.
 *
 * Run: wp eval-file tests/schema-baseline-drift-check.php
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Must run via wp eval-file\n" ); exit( 1 ); }
if ( ! class_exists( 'EEM_Activator' ) ) { fwrite( STDERR, "EEM_Activator not loaded\n" ); exit( 1 ); }

global $wpdb;

/** Column signature (name + type + null + default) so a TYPE drift is caught too, not just a missing column. */
$sig = static function ( $table ) use ( $wpdb ) {
	$out = array();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
	foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ) as $c ) {
		$out[ $c['Field'] ] = strtolower( $c['Type'] ) . '|' . $c['Null'] . '|' . (string) $c['Default'];
	}
	return $out;
};

$real_prefix    = $wpdb->prefix;
$scratch_prefix = $real_prefix . 'sdrift_';

// The activator's create_* methods (the dbDelta schema, WITHOUT migrations).
$create_methods = array(
	'create_reservation_tables',
	'create_reports_log_table',
	'create_activity_log_table',
	'create_event_defaults_table',
	'create_order_adjustments_table',
	'create_order_payments_table',
);

$drift = array();
$checked = 0;

try {
	// Point the whole plugin's table naming at the scratch namespace, then run the
	// create methods so dbDelta builds throwaway tables from the definitions alone.
	$wpdb->prefix = $scratch_prefix;
	foreach ( $create_methods as $m ) {
		$ref = new ReflectionMethod( 'EEM_Activator', $m );
		$ref->setAccessible( true );
		$ref->invoke( null );
	}

	// Enumerate the scratch tables that were created.
	$like = $wpdb->esc_like( $scratch_prefix ) . '%';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$scratch_tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

	foreach ( $scratch_tables as $scratch_table ) {
		$suffix     = substr( $scratch_table, strlen( $scratch_prefix ) ); // e.g. "eem_stall_reservations"
		$live_table = $real_prefix . $suffix;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $live_table ) ) ) === 0 && ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $live_table ) ) ) {
			$drift[ $suffix ] = array( 'note' => 'live table does not exist' );
			$checked++;
			continue;
		}
		$scratch_sig = $sig( $scratch_table );
		$live_sig    = $sig( $live_table );

		$missing_in_dbdelta = array_diff_key( $live_sig, $scratch_sig ); // live has, dbDelta lacks
		$extra_in_dbdelta   = array_diff_key( $scratch_sig, $live_sig ); // dbDelta has, live lacks
		$type_mismatch      = array();
		foreach ( $scratch_sig as $col => $s ) {
			if ( isset( $live_sig[ $col ] ) && $live_sig[ $col ] !== $s ) {
				$type_mismatch[ $col ] = $s . '  (dbDelta)  vs  ' . $live_sig[ $col ] . '  (live)';
			}
		}
		$checked++;
		if ( $missing_in_dbdelta || $extra_in_dbdelta || $type_mismatch ) {
			$drift[ $suffix ] = array(
				'missing_in_dbdelta' => array_keys( $missing_in_dbdelta ),
				'extra_in_dbdelta'   => array_keys( $extra_in_dbdelta ),
				'type_mismatch'      => $type_mismatch,
			);
		}
	}

	// Drop every scratch table.
	foreach ( $scratch_tables as $scratch_table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal, self-created.
		$wpdb->query( "DROP TABLE IF EXISTS `{$scratch_table}`" );
	}
} finally {
	$wpdb->prefix = $real_prefix; // ALWAYS restore, even on error.
}

echo "=== #41 dbDelta-baseline vs live-schema drift ===\n";
echo "tables checked: {$checked}\n\n";

if ( empty( $drift ) ) {
	echo "OK — no drift. The dbDelta CREATE TABLE definitions alone reproduce the\n";
	echo "live schema exactly, so the one-time migrations are safe to remove.\n";
	exit( 0 );
}

echo "DRIFT FOUND — these must be folded into the dbDelta definitions BEFORE the\n";
echo "migrations can be removed (a fresh install would otherwise be missing them):\n\n";
foreach ( $drift as $table => $d ) {
	echo "  {$table}:\n";
	if ( ! empty( $d['note'] ) ) { echo "    - {$d['note']}\n"; continue; }
	if ( ! empty( $d['missing_in_dbdelta'] ) ) { echo '    - columns in LIVE but not in dbDelta: ' . implode( ', ', $d['missing_in_dbdelta'] ) . "\n"; }
	if ( ! empty( $d['extra_in_dbdelta'] ) )   { echo '    - columns in dbDelta but not LIVE: ' . implode( ', ', $d['extra_in_dbdelta'] ) . "\n"; }
	foreach ( (array) $d['type_mismatch'] as $col => $msg ) { echo "    - TYPE differs on {$col}: {$msg}\n"; }
}
echo "\n";
exit( 1 );
