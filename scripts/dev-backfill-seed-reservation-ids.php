<?php
/**
 * Dev-only one-shot: backfill `Reservation setup ID: N` tags on SEED-*
 * orders so EEM_Orders_Repository::extract_reservation_id_from_notes()
 * can resolve a real reservation post per order — unblocks visual
 * verify of reservation-dependent UI (Edit Reservation button on Order
 * Detail action bar, Special Instructions card, etc.).
 *
 * Background — CLEANUP #36:
 *   The seed-data shipper creates SEED-* orders with a short notes
 *   string ("Seed order spec=stall") that omits the `Reservation setup
 *   ID: N` tag the orders-repo regex matches. Result: 25 of 26 seeded
 *   orders surface as `reservation_id = 0` and any reservation-gated
 *   UI hides silently. CLEANUP #36 prescribes a real seeder fix at C7
 *   kickoff; this script is the dev-side stopgap until then.
 *
 * What it does:
 *   For every SEED-* row in wp_en_stall_reservations + wp_en_rv_reservations
 *   whose `notes` column does NOT already contain "Reservation setup ID:",
 *   APPEND "\nReservation setup ID: <id>" using a round-robin pick from
 *   the existing en_reservation post IDs. Idempotent — re-runs are safe
 *   (rows that already carry the tag are skipped).
 *
 * Usage (from the WP install root):
 *   wp eval-file /path/to/equine-event-manager/scripts/dev-backfill-seed-reservation-ids.php
 *
 * NOT a production script. Do not wire to any hook. Do not ship.
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$reservation_ids = get_posts( array(
	'post_type'      => 'en_reservation',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'orderby'        => 'ID',
	'order'          => 'ASC',
) );

if ( empty( $reservation_ids ) ) {
	echo "ABORT: no en_reservation posts exist; nothing to backfill against.\n";
	return;
}

echo 'Using reservation post IDs: ' . implode( ',', $reservation_ids ) . "\n";

$tables  = array( 'wp_en_stall_reservations', 'wp_en_rv_reservations' );
$updated = 0;
$skipped = 0;
$cursor  = 0;

foreach ( $tables as $table ) {
	$rows = $wpdb->get_results(
		"SELECT id, order_number, notes FROM {$table} WHERE order_number LIKE 'SEED-%' ORDER BY id ASC",
		ARRAY_A
	);
	foreach ( $rows as $row ) {
		if ( preg_match( '/Reservation setup ID:\s*\d+/', (string) $row['notes'] ) ) {
			$skipped++;
			continue;
		}
		$res_id   = $reservation_ids[ $cursor % count( $reservation_ids ) ];
		$cursor++;
		$new_note = rtrim( (string) $row['notes'] ) . "\nReservation setup ID: " . (int) $res_id;
		$result   = $wpdb->update(
			$table,
			array( 'notes' => $new_note ),
			array( 'id' => (int) $row['id'] ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false === $result ) {
			echo "  ✗ FAILED {$table}#{$row['id']} ({$row['order_number']}): " . $wpdb->last_error . "\n";
			continue;
		}
		$updated++;
		echo "  ✓ {$table}#{$row['id']} ({$row['order_number']}) → res_id={$res_id}\n";
	}
}

echo "\nDONE: updated={$updated}, already-tagged={$skipped}\n";
