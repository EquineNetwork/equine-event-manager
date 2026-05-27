<?php
/**
 * C7.X.9 seed — link reservation 44 to a real event for visual verify.
 *
 * Why this exists: the canonical visual-verify reservation (`id=44`,
 * "2025 Spring Classic") has shipped without `_en_event_source` /
 * `_en_event_id` / `_en_external_event_id` populated, so the editor's
 * meta-line + Linked Event rail card render "(no event linked)" / "—"
 * placeholders instead of real values. That blocks end-to-end verify
 * of items 1–6 from C7.X.9.
 *
 * What this does (idempotent, re-runnable):
 *   1. Find a publishable source event the resolver knows how to read.
 *      Preference order: native (en_event) → TEC (tribe_events).
 *      If neither exists, seed a minimal native en_event so the script
 *      always has something to link.
 *   2. Wire reservation 44's `_en_event_source` + `_en_event_id` meta
 *      to that event (native or TEC both use the same two keys per
 *      EEM_Events::get_normalized_reservation_event_data).
 *   3. Ensure `_en_use_global_event_source = 0` so the per-reservation
 *      source mode wins over the site-wide default.
 *   4. Force `EEM_Reservation_Source_Resolver::cache_source_event_start_date`
 *      so the sort-cache key is rewritten from the now-linked event.
 *   5. Print a before/after summary + the resolver's final output so
 *      Whitney can confirm the meta-line + rail card will render.
 *
 * Invocation (Local site):
 *   wp eval-file tests/seeds/seed-reservation-44-link-event.php
 *
 * If reservation 44 doesn't exist (different Local install), the script
 * skips with a clear message rather than failing — Whitney can rerun
 * after adjusting RID below or after re-seeding the install.
 *
 * @package EEM_Plugin
 */

if ( ! function_exists( 'get_option' ) ) {
	echo "FAIL: WP not loaded (run via wp eval-file)\n";
	return;
}

$RID = 44;

echo "\n=== C7.X.9 seed — link reservation {$RID} to a real event ===\n";

$reservation = get_post( $RID );
if ( ! $reservation || 'en_reservation' !== $reservation->post_type ) {
	echo "SKIP: reservation {$RID} not found (or wrong post_type). Nothing to link.\n";
	return;
}

if ( 'publish' !== $reservation->post_status ) {
	wp_update_post( array( 'ID' => $RID, 'post_status' => 'publish' ) );
	echo "  · forced post_status → publish (resolver requires it)\n";
}

// Snapshot BEFORE.
$before = array(
	'_en_event_source'        => get_post_meta( $RID, '_en_event_source', true ),
	'_en_event_id'            => get_post_meta( $RID, '_en_event_id', true ),
	'_en_external_event_id'   => get_post_meta( $RID, '_en_external_event_id', true ),
	'_en_use_global_event_source' => get_post_meta( $RID, '_en_use_global_event_source', true ),
);
echo "\nBEFORE:\n";
foreach ( $before as $k => $v ) {
	echo "  {$k} = " . var_export( $v, true ) . "\n";
}

// Pick a source event. Native preferred (no plugin dep); TEC as fallback.
$event_id = 0;
$source   = '';

$native = get_posts( array(
	'post_type'      => 'en_event',
	'posts_per_page' => 1,
	'post_status'    => 'publish',
	'fields'         => 'ids',
	'orderby'        => 'date',
	'order'          => 'DESC',
) );
if ( ! empty( $native ) ) {
	$event_id = (int) $native[0];
	$source   = 'native';
}

if ( 0 === $event_id ) {
	$tec = get_posts( array(
		'post_type'      => 'tribe_events',
		'posts_per_page' => 1,
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	if ( ! empty( $tec ) ) {
		$event_id = (int) $tec[0];
		$source   = 'tec';
	}
}

if ( 0 === $event_id ) {
	// Seed a minimal native event so the script ALWAYS lands something.
	$event_id = wp_insert_post( array(
		'post_type'   => 'en_event',
		'post_status' => 'publish',
		'post_title'  => '2025 Spring Classic (seeded by C7.X.9)',
	) );
	if ( is_wp_error( $event_id ) || ! $event_id ) {
		echo "FAIL: could not seed a native en_event fallback.\n";
		return;
	}
	// Minimal date metadata the resolver / date-range label reads.
	update_post_meta( $event_id, '_en_event_start_date', '2025-03-10' );
	update_post_meta( $event_id, '_en_event_end_date',   '2025-03-12' );
	update_post_meta( $event_id, '_en_venue_name',       'Spring Classic Showgrounds' );
	$source = 'native';
	echo "\n  · no existing en_event / tribe_events found; seeded native event id={$event_id}\n";
} else {
	echo "\n  · picked existing {$source} event id={$event_id}\n";
}

// Wire reservation 44 to the picked event.
update_post_meta( $RID, '_en_event_source',            $source );
update_post_meta( $RID, '_en_event_id',                $event_id );
update_post_meta( $RID, '_en_use_global_event_source', 0 );
// Clear stale external/feed keys so resolver picks the native/TEC path.
delete_post_meta( $RID, '_en_external_event_id' );
delete_post_meta( $RID, '_en_event_feed_url' );

// Rewrite the sort-cache key from the resolver.
if ( class_exists( 'EEM_Reservation_Source_Resolver' ) ) {
	EEM_Reservation_Source_Resolver::cache_source_event_start_date( $RID, get_post( $RID ) );
}

// Snapshot AFTER.
$after = array(
	'_en_event_source'            => get_post_meta( $RID, '_en_event_source', true ),
	'_en_event_id'                => get_post_meta( $RID, '_en_event_id', true ),
	'_en_use_global_event_source' => get_post_meta( $RID, '_en_use_global_event_source', true ),
	'_en_source_event_start_date' => get_post_meta( $RID, '_en_source_event_start_date', true ),
);
echo "\nAFTER:\n";
foreach ( $after as $k => $v ) {
	echo "  {$k} = " . var_export( $v, true ) . "\n";
}

// Resolver round-trip.
echo "\nRESOLVER OUTPUT (what the editor meta-line + rail card will display):\n";
if ( class_exists( 'EEM_Reservation_Source_Resolver' ) ) {
	$fields     = EEM_Reservation_Source_Resolver::resolve_event_fields( $RID );
	$date_range = EEM_Reservation_Source_Resolver::get_date_range_label( $RID );
	echo "  title      = " . var_export( $fields['title'],      true ) . "\n";
	echo "  start_date = " . var_export( $fields['start_date'], true ) . "\n";
	echo "  end_date   = " . var_export( $fields['end_date'],   true ) . "\n";
	echo "  venue      = " . var_export( $fields['venue'],      true ) . "\n";
	echo "  date_range = " . var_export( $date_range,           true ) . "\n";
} else {
	echo "  EEM_Reservation_Source_Resolver class not loaded.\n";
}

echo "\n=== DONE ===\n";
