<?php
/**
 * Import allowlist smoke (ship-readiness 4.3).
 *
 * The JSON setup import previously did blind update_post_meta() with
 * attacker-controlled keys and raw $wpdb->insert() of whole imported rows. Asserts:
 *   - is_importable_meta_key() accepts only the plugin's own meta namespace
 *     (_en_ / _eem_ / _equine_event_manager_) and rejects arbitrary WP/other keys.
 *   - table_columns() returns the real component-table columns (minus `id`), so the
 *     row filter (array_intersect_key) drops any bogus column from a poisoned file.
 *
 * Run via: wp eval-file tests/smoke/import-allowlist-smoke.php
 *
 * @package EEM_Plugin
 */

global $wpdb;
$pass = 0; $fail = 0;
$chk = static function ( $cond, $label ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ok  — $label\n"; } else { $fail++; echo "  FAIL — $label\n"; }
};

if ( ! class_exists( 'EEM_Import_Handler' ) ) {
	echo "  FAIL — EEM_Import_Handler missing\n0 passed, 1 failed\n";
	return;
}

$key_ok = new ReflectionMethod( 'EEM_Import_Handler', 'is_importable_meta_key' );
$key_ok->setAccessible( true );
$cols   = new ReflectionMethod( 'EEM_Import_Handler', 'table_columns' );
$cols->setAccessible( true );

// --- meta key allowlist ---
foreach ( array( '_en_stalls_enabled', '_eem_foo', '_equine_event_manager_event_start_date' ) as $good ) {
	$chk( true === $key_ok->invoke( null, $good ), "accepts plugin key: $good" );
}
foreach ( array( '_edit_lock', '_wp_capabilities', 'wp_user_level', 'evil', '_edit_last' ) as $bad ) {
	$chk( false === $key_ok->invoke( null, $bad ), "rejects non-plugin key: $bad" );
}

// --- table column allowlist ---
$stall_table = $wpdb->prefix . 'en_stall_reservations';
$stall_cols  = (array) $cols->invoke( null, $stall_table );
$chk( ! empty( $stall_cols ), 'table_columns returns stall columns' );
$chk( ! in_array( 'id', $stall_cols, true ), 'table_columns excludes the PK id' );
$chk( in_array( 'payment_status', $stall_cols, true ), 'table_columns includes a real column (payment_status)' );

// The filter the import applies: a poisoned row with a bogus column is stripped.
$poisoned = array(
	'id'             => 999,        // PK — must be dropped
	'payment_status' => 'paid',     // real column — kept
	'total'          => '10.00',    // real column — kept
	'evil_column'    => 'DROP',     // bogus — must be dropped
	'wp_capabilities'=> 'admin',    // bogus — must be dropped
);
$filtered = array_intersect_key( $poisoned, array_flip( $stall_cols ) );
$chk( ! array_key_exists( 'id', $filtered ), 'row filter drops PK id' );
$chk( ! array_key_exists( 'evil_column', $filtered ), 'row filter drops a bogus column' );
$chk( ! array_key_exists( 'wp_capabilities', $filtered ), 'row filter drops wp_capabilities' );
$chk( array_key_exists( 'payment_status', $filtered ) && array_key_exists( 'total', $filtered ), 'row filter keeps real columns' );

echo "\n$pass passed, $fail failed\n";
