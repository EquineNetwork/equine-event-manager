<?php
/**
 * Reseed every reservation's facility map through the native Map Builder path.
 *
 * After the Google-Sheet import was removed, demo/seed maps should carry the
 * builder provenance. This re-runs each stored snapshot through
 * {@see EEM_Stall_Map_Importer::snapshot_from_builder()} — the exact path the
 * Map Builder save uses — converting `source` to 'builder' (and dropping the
 * stale `source_url` / `key` / `gid` Google fields) while preserving the exact
 * grids. Idempotent: re-running on an already-builder map is a no-op in effect.
 *
 * Run: wp eval-file tests/seeds/reseed-maps-builder.php
 */

if ( ! function_exists( 'get_option' ) ) {
	fwrite( STDERR, "run via wp eval-file\n" );
	return;
}

global $wpdb;
$rows      = $wpdb->get_results( "SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key IN ('_en_stall_map','_en_rv_map') ORDER BY post_id" );
$converted = 0;

foreach ( $rows as $r ) {
	$snap = get_post_meta( $r->post_id, $r->meta_key, true );
	if ( ! is_array( $snap ) || empty( $snap['barns'] ) ) {
		continue;
	}
	$kind  = ( '_en_rv_map' === $r->meta_key ) ? 'rv' : 'stall';
	$barns = array();
	foreach ( $snap['barns'] as $b ) {
		$barns[] = array(
			'name' => isset( $b['name'] ) ? (string) $b['name'] : '',
			'grid' => isset( $b['grid'] ) && is_array( $b['grid'] ) ? $b['grid'] : array(),
		);
	}
	$rebuilt = EEM_Stall_Map_Importer::snapshot_from_builder( $barns, $kind );
	EEM_Stall_Map_Importer::save_to_reservation( (int) $r->post_id, $rebuilt, $r->meta_key );
	$converted++;
	printf(
		"reseeded post %d  %s  -> source=%s  barns=%d  units=%d\n",
		(int) $r->post_id,
		$r->meta_key,
		$rebuilt['source'],
		count( $rebuilt['barns'] ),
		EEM_Stall_Map_Importer::count_stalls( $rebuilt )
	);
}

printf( "Done: %d map(s) reseeded through the builder path.\n", $converted );
