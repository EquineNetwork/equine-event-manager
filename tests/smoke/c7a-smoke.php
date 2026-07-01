<?php
/**
 * C7.A smoke — Event Defaults data layer + Cancellation Policy resolver + migrations.
 *
 *   [1]  Schema — wp_eem_event_defaults table exists with expected columns + PK + KEY
 *   [2]  Class + function loading
 *   [3]  EEM_Event_Defaults_Repo::normalize_event_source — 'external'→'feed', unknown→'native'
 *   [4]  Repo CRUD — cancellation_policy round-trip + composite-PK isolation
 *   [5]  Repo CRUD — venue_map round-trip + implicit set⇒show semantic
 *   [6]  Resolver — override precedence
 *   [7]  Resolver — event-default fallback
 *   [8]  Resolver — null return when both empty
 *   [9]  Resolver — toggle OFF suppresses
 *   [10] Resolver — empty toggle meta = enabled (Q7 contract)
 *   [11] Resolver — request-scoped memo cache hit
 *   [12] Resolver — cache cleared on set_cancellation_policy write
 *   [13] Activator integration — create_event_defaults_table + migration baseline guard wired
 *
 * NOTE: the #001/#002 migration-behavior sections were removed when the 42 one-time
 * migrations were collapsed into the dbDelta baseline (#41). The ongoing resolver +
 * source-normalization behavior they fed is still covered by sections [3]–[12].
 *
 * @package EEM_Plugin
 */
if ( ! function_exists( 'get_option' ) ) { echo "FAIL: WP not loaded\n"; exit( 1 ); }
$pass = 0; $fail = 0; $log = array();
function c7a_ok( $l, $c, &$p, &$f, &$lg, $d = '' ) {
	if ( $c ) { $p++; $lg[] = "  ✓ {$l}"; }
	else      { $f++; $lg[] = "  ✗ {$l}" . ( $d ? " — {$d}" : '' ); }
}

echo "\n=== C7.A SMOKE ===\n";

// Defensive pre-cleanup: delete any leftover test reservations from a
// prior crashed run (would otherwise corrupt c4d's sort order smoke).
$leftover_reservations = get_posts( array(
	'post_type'      => 'en_reservation',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	's'              => 'C7.A Smoke',
) );
foreach ( $leftover_reservations as $stale ) {
	wp_delete_post( $stale->ID, true );
}

global $wpdb;
$table = $wpdb->prefix . 'eem_event_defaults';

// ── [1] Schema ─────────────────────────────────────────────────────
echo "\n[1] Schema — wp_eem_event_defaults table\n";
$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
c7a_ok( "table {$table} exists", $table_exists, $pass, $fail, $log );

if ( $table_exists ) {
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
	foreach ( array( 'event_id', 'event_source', 'cancellation_policy', 'venue_map_image_id', 'venue_map_download_url', 'venue_map_caption', 'created_at', 'updated_at' ) as $col ) {
		c7a_ok( "column `{$col}` present", in_array( $col, $columns, true ), $pass, $fail, $log );
	}
	// Verify the NO enabled column (Q3.5 lock).
	c7a_ok( 'NO `venue_map_enabled` column (Q3.5: implicit set⇒show)',
		! in_array( 'venue_map_enabled', $columns, true ),
		$pass, $fail, $log );
	// PK shape — composite (event_id, event_source)
	$pk = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'PRIMARY'", ARRAY_A );
	$pk_cols = array_map( function( $r ) { return $r['Column_name']; }, $pk );
	c7a_ok( 'composite PRIMARY KEY = (event_id, event_source)',
		array( 'event_id', 'event_source' ) === $pk_cols,
		$pass, $fail, $log,
		'got: ' . implode( ',', $pk_cols ) );
	// event_id column type — varchar(191) per Q4
	$type_row = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'event_id'", ARRAY_A );
	c7a_ok( 'event_id column is varchar(191) (handles native+tec post IDs + feed string IDs)',
		isset( $type_row['Type'] ) && false !== stripos( (string) $type_row['Type'], 'varchar(191)' ),
		$pass, $fail, $log,
		'got: ' . ( $type_row['Type'] ?? '?' ) );
}

// ── [2] Class + function loading ────────────────────────────────────
echo "\n[2] Class + function loading\n";
c7a_ok( 'EEM_Event_Defaults_Repo class loaded', class_exists( 'EEM_Event_Defaults_Repo' ), $pass, $fail, $log );
c7a_ok( 'eem_resolve_cancellation_policy() function exists', function_exists( 'eem_resolve_cancellation_policy' ), $pass, $fail, $log );
c7a_ok( 'eem_is_cancellation_policy_enabled() helper exists', function_exists( 'eem_is_cancellation_policy_enabled' ), $pass, $fail, $log );
c7a_ok( 'eem_clear_cancellation_policy_resolver_cache() exists', function_exists( 'eem_clear_cancellation_policy_resolver_cache' ), $pass, $fail, $log );

// ── [3] Source normalization ─────────────────────────────────────────
echo "\n[3] EEM_Event_Defaults_Repo::normalize_event_source\n";
c7a_ok( "'external' → 'feed' (Q4.5)",        'feed'   === EEM_Event_Defaults_Repo::normalize_event_source( 'external' ), $pass, $fail, $log );
c7a_ok( "'feed' → 'feed' (canonical pass-through)", 'feed'   === EEM_Event_Defaults_Repo::normalize_event_source( 'feed' ),     $pass, $fail, $log );
c7a_ok( "'native' → 'native'",               'native' === EEM_Event_Defaults_Repo::normalize_event_source( 'native' ),   $pass, $fail, $log );
c7a_ok( "'tec' → 'tec'",                     'tec'    === EEM_Event_Defaults_Repo::normalize_event_source( 'tec' ),      $pass, $fail, $log );
c7a_ok( "'garbage' → 'native' (unknown fallback)", 'native' === EEM_Event_Defaults_Repo::normalize_event_source( 'garbage' ), $pass, $fail, $log );
c7a_ok( "'' → 'native' (empty fallback)",    'native' === EEM_Event_Defaults_Repo::normalize_event_source( '' ),         $pass, $fail, $log );

// ── Setup: clean test rows + repo instance ─────────────────────────
$repo = new EEM_Event_Defaults_Repo();
$test_event_id = 'c7a-smoke-test-' . wp_generate_password( 8, false, false );
$wpdb->delete( $table, array( 'event_id' => $test_event_id ) );

// ── [4] Repo cancellation_policy round-trip ────────────────────────
echo "\n[4] Repo — cancellation_policy CRUD + composite-PK isolation\n";
c7a_ok( 'get on non-existent row returns null',
	null === $repo->get_cancellation_policy( $test_event_id, 'native' ),
	$pass, $fail, $log );
$set_ok = $repo->set_cancellation_policy( $test_event_id, 'native', 'Test policy text — native source.' );
c7a_ok( 'set_cancellation_policy returns true on success', $set_ok, $pass, $fail, $log );
c7a_ok( 'get after set returns the stored policy',
	'Test policy text — native source.' === $repo->get_cancellation_policy( $test_event_id, 'native' ),
	$pass, $fail, $log );
// Composite-PK isolation — same event_id under different sources should NOT collide
$repo->set_cancellation_policy( $test_event_id, 'tec', 'Test policy text — TEC source.' );
c7a_ok( 'composite PK isolates native + tec rows (same event_id, different source)',
	'Test policy text — native source.' === $repo->get_cancellation_policy( $test_event_id, 'native' )
	&& 'Test policy text — TEC source.' === $repo->get_cancellation_policy( $test_event_id, 'tec' ),
	$pass, $fail, $log );
// 'external' normalized to 'feed' at the boundary
$repo->set_cancellation_policy( $test_event_id, 'external', 'Test policy text — feed source via legacy alias.' );
c7a_ok( "set with 'external' is read back via 'feed' (boundary normalization)",
	'Test policy text — feed source via legacy alias.' === $repo->get_cancellation_policy( $test_event_id, 'feed' ),
	$pass, $fail, $log );
// Confirm DB only has the 'feed' row, not 'external'
$db_sources = $wpdb->get_col( $wpdb->prepare( "SELECT event_source FROM {$table} WHERE event_id = %s ORDER BY event_source", $test_event_id ) );
c7a_ok( "DB has no 'external' rows (writes always canonical)",
	! in_array( 'external', $db_sources, true ),
	$pass, $fail, $log,
	'got: ' . implode( ',', $db_sources ) );

// ── [5] Repo venue_map round-trip ──────────────────────────────────
echo "\n[5] Repo — venue_map CRUD + implicit set⇒show\n";
c7a_ok( 'venue map: get on row without image_id returns null',
	null === $repo->get_venue_map( $test_event_id . '-vm', 'native' ),
	$pass, $fail, $log );
$vm_set = $repo->set_venue_map( $test_event_id, 'native', array(
	'image_id'     => 999,
	'download_url' => 'https://example.test/map.pdf',
	'caption'      => 'Test venue map caption',
) );
c7a_ok( 'set_venue_map returns true on success', $vm_set, $pass, $fail, $log );
$vm = $repo->get_venue_map( $test_event_id, 'native' );
c7a_ok( 'venue map round-trip preserves all 3 fields',
	is_array( $vm )
		&& 999 === $vm['image_id']
		&& 'https://example.test/map.pdf' === $vm['download_url']
		&& 'Test venue map caption' === $vm['caption'],
	$pass, $fail, $log );
// Implicit set⇒show — clearing image_id to 0 should return null (Q3.5)
$repo->set_venue_map( $test_event_id, 'native', array( 'image_id' => 0 ) );
c7a_ok( 'venue map: image_id=0 returns null (implicit set⇒show per Q3.5)',
	null === $repo->get_venue_map( $test_event_id, 'native' ),
	$pass, $fail, $log );

// ── Setup: a reservation for resolver tests ────────────────────────
$reservation_id = wp_insert_post( array(
	'post_type'   => 'en_reservation',
	'post_status' => 'publish',
	'post_title'  => 'C7.A Smoke Reservation ' . wp_generate_password( 6, false, false ),
) );
update_post_meta( $reservation_id, '_en_event_source', 'native' );
update_post_meta( $reservation_id, '_en_event_id', $test_event_id );
// #55: source detection now reads the relational config table (mig-016 decouple),
// not _en_event_source post-meta — seed it there so the resolver picks 'native'.
if ( class_exists( 'EEM_Reservation_Config' ) ) {
	EEM_Reservation_Config::for( $reservation_id )
		->set_many( array( 'event_source' => 'native', 'use_global_event_source' => 0 ) )
		->save();
	EEM_Reservation_Config::flush_cache( $reservation_id );
}
// Restore the test event default after the venue map test cleared it
$repo->set_cancellation_policy( $test_event_id, 'native', 'Event default policy text.' );

// ── [6] Resolver — override precedence ─────────────────────────────
echo "\n[6] Resolver — override precedence\n";
eem_clear_cancellation_policy_resolver_cache();
update_post_meta( $reservation_id, '_eem_cancellation_policy_override', 'Per-reservation override.' );
c7a_ok( 'override wins over event default',
	'Per-reservation override.' === eem_resolve_cancellation_policy( $reservation_id ),
	$pass, $fail, $log );

// ── [7] Resolver — event-default fallback ──────────────────────────
echo "\n[7] Resolver — event-default fallback\n";
delete_post_meta( $reservation_id, '_eem_cancellation_policy_override' );
eem_clear_cancellation_policy_resolver_cache();
c7a_ok( 'empty override falls back to event default',
	'Event default policy text.' === eem_resolve_cancellation_policy( $reservation_id ),
	$pass, $fail, $log );

// ── [8] Resolver — null when both empty ─────────────────────────────
echo "\n[8] Resolver — null when both empty\n";
$repo->set_cancellation_policy( $test_event_id, 'native', null );
eem_clear_cancellation_policy_resolver_cache();
c7a_ok( 'both override and event default empty → null',
	null === eem_resolve_cancellation_policy( $reservation_id ),
	$pass, $fail, $log );

// Restore the event default for downstream tests.
$repo->set_cancellation_policy( $test_event_id, 'native', 'Event default policy text.' );

// ── [9] Resolver — toggle OFF suppresses ─────────────────────────────
echo "\n[9] Resolver — toggle OFF suppresses\n";
update_post_meta( $reservation_id, '_eem_cancellation_policy_enabled', '0' );
eem_clear_cancellation_policy_resolver_cache();
c7a_ok( 'toggle OFF returns null even with event default present',
	null === eem_resolve_cancellation_policy( $reservation_id ),
	$pass, $fail, $log );

// ── [10] Empty toggle meta = enabled (Q7 contract) ──────────────────
echo "\n[10] Q7 contract — empty toggle meta resolves to ENABLED\n";
delete_post_meta( $reservation_id, '_eem_cancellation_policy_enabled' );
eem_clear_cancellation_policy_resolver_cache();
c7a_ok( 'empty toggle meta → enabled (Q7 contract)',
	eem_is_cancellation_policy_enabled( $reservation_id ),
	$pass, $fail, $log );
c7a_ok( 'resolver returns event default with empty toggle (Q7 contract)',
	'Event default policy text.' === eem_resolve_cancellation_policy( $reservation_id ),
	$pass, $fail, $log );

// ── [11] Resolver — memo cache hit ──────────────────────────────────
echo "\n[11] Resolver — request-scoped memo cache\n";
eem_clear_cancellation_policy_resolver_cache();
eem_resolve_cancellation_policy( $reservation_id ); // first call, populates cache
$cache = eem_cancellation_policy_resolver_cache();
c7a_ok( 'cache populated after first call',
	array_key_exists( $reservation_id, $cache ) && 'Event default policy text.' === $cache[ $reservation_id ],
	$pass, $fail, $log );

// ── [12] Resolver — cache invalidates on write (in-flight C) ────────
echo "\n[12] Resolver — cache cleared on set_cancellation_policy\n";
$repo->set_cancellation_policy( $test_event_id, 'native', 'New event default after write.' );
$cache_after = eem_cancellation_policy_resolver_cache();
c7a_ok( 'cache cleared after set_cancellation_policy',
	empty( $cache_after ),
	$pass, $fail, $log );
c7a_ok( 'resolver returns NEW value after cache clear',
	'New event default after write.' === eem_resolve_cancellation_policy( $reservation_id ),
	$pass, $fail, $log );

// ── [13] Activator integration + #41 baseline guard ─────────────────
echo "\n[13] Activator integration + migration baseline guard\n";
$activator_src = file_get_contents( EQUINE_EVENT_MANAGER_PATH . 'includes/class-equine-event-manager-activator.php' );
c7a_ok( 'activate() calls create_event_defaults_table()',
	false !== strpos( $activator_src, 'self::create_event_defaults_table()' ),
	$pass, $fail, $log );
c7a_ok( 'activate() calls run_one_time_migrations()',
	false !== strpos( $activator_src, 'self::run_one_time_migrations()' ),
	$pass, $fail, $log );
c7a_ok( 'run_one_time_migrations is now the #41 baseline guard (no per-migration flags)',
	false !== strpos( $activator_src, 'MIGRATION_BASELINE_VERSION' )
	&& false !== strpos( $activator_src, 'eem_migration_baseline_gap' ),
	$pass, $fail, $log );

// ── Final cleanup ───────────────────────────────────────────────────
wp_delete_post( $reservation_id, true );
$wpdb->delete( $table, array( 'event_id' => $test_event_id ) );

echo implode( "\n", $log ) . "\n=== RESULT: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
