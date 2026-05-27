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

// Pick a source event whose start_date aligns with reservation 44's
// title ("2025 Spring Classic" → 2025-03-10). C7.X.10 update: the
// previous version of this seed picked the first available event
// regardless of date, which broke c4d-smoke's sort + date-filter
// assertions that depend on reservation 44 sorting to its 2025-03 slot.
// Pick policy:
//   1. Prefer a native en_event with start_date='2025-03-10'.
//   2. Else prefer a TEC tribe_events whose post_date matches.
//   3. Else seed a minimal native en_event with that exact date.
$TARGET_START = '2025-03-10';
$TARGET_END   = '2025-03-12';
$event_id     = 0;
$source       = '';

// Step 1 — native en_event matching target date.
$native = get_posts( array(
	'post_type'      => 'en_event',
	'posts_per_page' => -1,
	'post_status'    => 'publish',
	'fields'         => 'ids',
) );
foreach ( (array) $native as $candidate ) {
	// Native event resolver stores dates under _equine_event_manager_*.
	$candidate_start = (string) get_post_meta( $candidate, '_equine_event_manager_event_start_date', true );
	if ( '' === $candidate_start ) {
		$candidate_start = (string) get_post_meta( $candidate, '_en_event_start_date', true );
	}
	if ( $TARGET_START === $candidate_start ) {
		$event_id = (int) $candidate;
		$source   = 'native';
		break;
	}
}

// Step 2 — seed a native en_event with the target date so c4d-smoke's
// sort + date-filter assertions remain stable. We deliberately skip
// the "pick any native" + "fall back to TEC" branches here because
// either path would yield an event with a different start_date,
// which would break res 44's 2025-03 sort slot. Seeding a fresh
// native event with the target date is the only deterministic path.
if ( 0 === $event_id ) {
	// First, look for a previously-seeded "Spring Classic" native event
	// (so re-running the script is idempotent and doesn't pile up
	// duplicate seed events on every run).
	$prior_seeded = get_posts( array(
		'post_type'      => 'en_event',
		'posts_per_page' => 1,
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'title'          => '2025 Spring Classic (seeded by C7.X.9)',
	) );
	if ( ! empty( $prior_seeded ) ) {
		$event_id = (int) $prior_seeded[0];
		$source   = 'native';
		// Ensure dates are still set (someone could've cleared them).
		// Native event resolver reads _equine_event_manager_event_*_date
		// (not _en_event_*_date). The _en_* mirror is written too as a
		// belt-and-braces in case any legacy code path reads it.
		update_post_meta( $event_id, '_equine_event_manager_event_start_date', $TARGET_START );
		update_post_meta( $event_id, '_equine_event_manager_event_end_date',   $TARGET_END );
		update_post_meta( $event_id, '_en_event_start_date', $TARGET_START );
		update_post_meta( $event_id, '_en_event_end_date',   $TARGET_END );
		echo "\n  · reused prior-seeded native event id={$event_id} (start={$TARGET_START})\n";
	} else {
		$event_id = wp_insert_post( array(
			'post_type'   => 'en_event',
			'post_status' => 'publish',
			'post_title'  => '2025 Spring Classic (seeded by C7.X.9)',
		) );
		if ( is_wp_error( $event_id ) || ! $event_id ) {
			echo "FAIL: could not seed a native en_event fallback.\n";
			return;
		}
		// Native event resolver reads _equine_event_manager_event_*_date
		// (not _en_event_*_date). The _en_* mirror is written too as a
		// belt-and-braces in case any legacy code path reads it.
		update_post_meta( $event_id, '_equine_event_manager_event_start_date', $TARGET_START );
		update_post_meta( $event_id, '_equine_event_manager_event_end_date',   $TARGET_END );
		update_post_meta( $event_id, '_en_event_start_date', $TARGET_START );
		update_post_meta( $event_id, '_en_event_end_date',   $TARGET_END );
		update_post_meta( $event_id, '_en_venue_name',       'Spring Classic Showgrounds' );
		$source = 'native';
		echo "\n  · seeded new native event id={$event_id} (title='2025 Spring Classic', start={$TARGET_START})\n";
	}
} else {
	echo "\n  · picked existing {$source} event id={$event_id}\n";
}

// Belt-and-braces backfill: if we picked a native event via the
// `_en_event_start_date` legacy fallback in Step 1, the canonical
// `_equine_event_manager_event_*_date` keys may be missing. Backfill
// them now so the resolver returns proper date_range. Idempotent —
// re-writes the same values if already present.
if ( 'native' === $source ) {
	$canon_start = (string) get_post_meta( $event_id, '_equine_event_manager_event_start_date', true );
	if ( '' === $canon_start ) {
		$legacy_start = (string) get_post_meta( $event_id, '_en_event_start_date', true );
		$legacy_end   = (string) get_post_meta( $event_id, '_en_event_end_date',   true );
		if ( '' !== $legacy_start ) {
			update_post_meta( $event_id, '_equine_event_manager_event_start_date', $legacy_start );
			update_post_meta( $event_id, '_equine_event_manager_event_end_date',   '' !== $legacy_end ? $legacy_end : $legacy_start );
			echo "  · backfilled canonical date keys on event {$event_id} from legacy _en_event_*_date\n";
		}
	}
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
