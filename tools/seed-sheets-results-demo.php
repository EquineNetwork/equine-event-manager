<?php
/**
 * Seed a few fully-tied-together demo events (Whitney 2026-06-13).
 *
 * Builds three native events spanning the lifecycle (ongoing / upcoming / past),
 * each wired end-to-end so the whole system demonstrates its relationships:
 *
 *   Producer (en_producer)
 *      └─ Event (en_event) ── Venue (en_venue, + saved EEM_Venue facility layout)
 *           ├─ Reservation (en_reservation, stalls + RV) ── Entries (en_entry divisions)
 *           └─ Sheets & Results (en_discipline + wp_eem_sheet_entries + PDF attachments)
 *
 * Idempotent: every seeded record carries `_eem_sr_demo = 1`; re-running deletes
 * the prior demo set (posts, sheet entries, PDF attachments, saved layouts) and
 * rebuilds fresh.
 *
 * Run: wp eval-file tools/seed-sheets-results-demo.php
 *
 * @package EEM_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via: wp eval-file tools/seed-sheets-results-demo.php\n" );
	exit( 1 );
}

if ( ! class_exists( 'EEM_Sheet_Entries' ) ) {
	fwrite( STDERR, "EEM not loaded.\n" );
	exit( 1 );
}

global $wpdb;
$DEMO_FLAG = '_eem_sr_demo';

/* --------------------------------------------------------------------------
 * 0. Tear down any previous demo set.
 * ------------------------------------------------------------------------ */
$prior = get_posts( array(
	'post_type'   => array( 'en_event', 'en_venue', 'en_producer', 'en_reservation', 'en_entry', 'attachment' ),
	'post_status' => 'any',
	'numberposts' => -1,
	'fields'      => 'ids',
	'meta_key'    => $DEMO_FLAG,
	'meta_value'  => '1',
) );
foreach ( $prior as $pid ) {
	// Remove any sheet entries that referenced this event.
	if ( 'en_event' === get_post_type( $pid ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EEM_Sheet_Entries::table_name() . ' WHERE event_id = %d', $pid ) ); // phpcs:ignore WordPress.DB
	}
	wp_delete_post( $pid, true );
}
echo 'Removed ' . count( $prior ) . " prior demo record(s).\n";

// Disciplines created by the demo are tagged via option so we can clean them.
$prev_terms = (array) get_option( 'eem_sr_demo_terms', array() );
foreach ( $prev_terms as $tid ) {
	wp_delete_term( (int) $tid, EEM_Sheet_Entries::TAXONOMY );
}
$demo_terms = array();

/* --------------------------------------------------------------------------
 * Helpers.
 * ------------------------------------------------------------------------ */

/** Build a tiny but valid single-page PDF carrying the given title text. */
$make_pdf = static function ( string $title ): string {
	$text    = str_replace( array( '(', ')' ), array( '\(', '\)' ), $title );
	$content = "BT /F1 20 Tf 64 720 Td ($text) Tj ET";
	$objects = array(
		1 => '<</Type/Catalog/Pages 2 0 R>>',
		2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
		3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>',
		4 => '<</Length ' . strlen( $content ) . ">>\nstream\n" . $content . "\nendstream",
		5 => '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
	);
	$pdf     = "%PDF-1.4\n";
	$offsets = array();
	foreach ( $objects as $n => $body ) {
		$offsets[ $n ] = strlen( $pdf );
		$pdf          .= $n . " 0 obj\n" . $body . "\nendobj\n";
	}
	$xref  = strlen( $pdf );
	$count = count( $objects ) + 1;
	$pdf  .= "xref\n0 $count\n0000000000 65535 f \n";
	for ( $i = 1; $i < $count; $i++ ) {
		$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
	}
	$pdf .= "trailer\n<</Size $count/Root 1 0 R>>\nstartxref\n$xref\n%%EOF";
	return $pdf;
};

/** Create a PDF Media Library attachment, return its id. */
$make_attachment = static function ( string $label ) use ( $make_pdf, $DEMO_FLAG ): int {
	$upload = wp_upload_dir();
	if ( ! empty( $upload['error'] ) ) {
		return 0;
	}
	$name = wp_unique_filename( $upload['path'], sanitize_file_name( $label ) . '.pdf' );
	$path = trailingslashit( $upload['path'] ) . $name;
	file_put_contents( $path, $make_pdf( $label ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$att_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'application/pdf',
			'post_title'     => $label,
			'post_status'    => 'inherit',
		),
		$path,
		0
	);
	if ( is_wp_error( $att_id ) || ! $att_id ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $path ) );
	update_post_meta( $att_id, $DEMO_FLAG, '1' );
	return (int) $att_id;
};

/** Create/assign an en_discipline term to an event; return term id. */
$discipline = static function ( int $event_id, string $name ) use ( &$demo_terms ): int {
	$term = term_exists( $name, EEM_Sheet_Entries::TAXONOMY );
	if ( ! $term ) {
		$term         = wp_insert_term( $name, EEM_Sheet_Entries::TAXONOMY );
		$demo_terms[] = is_array( $term ) ? (int) $term['term_id'] : 0;
	}
	$tid = (int) ( is_array( $term ) ? $term['term_id'] : $term );
	wp_set_object_terms( $event_id, $tid, EEM_Sheet_Entries::TAXONOMY, true );
	return $tid;
};

/** Build one of the three demo events end-to-end. Returns the event id. */
$build_event = static function ( array $cfg ) use ( $DEMO_FLAG, $make_attachment, $discipline ): int {
	// --- Producer ---------------------------------------------------------
	$producer = wp_insert_post( array(
		'post_type'   => 'en_producer',
		'post_status' => 'publish',
		'post_title'  => $cfg['producer']['name'],
	) );
	update_post_meta( $producer, $DEMO_FLAG, '1' );
	update_post_meta( $producer, '_equine_event_manager_producer_contact_name', $cfg['producer']['contact'] );
	update_post_meta( $producer, '_equine_event_manager_producer_email', $cfg['producer']['email'] );
	update_post_meta( $producer, '_equine_event_manager_producer_phone', $cfg['producer']['phone'] );

	// --- Venue (native en_venue) -----------------------------------------
	$venue = wp_insert_post( array(
		'post_type'   => 'en_venue',
		'post_status' => 'publish',
		'post_title'  => $cfg['venue']['name'],
	) );
	update_post_meta( $venue, $DEMO_FLAG, '1' );
	update_post_meta( $venue, '_equine_event_manager_venue_address_1', $cfg['venue']['address'] );
	update_post_meta( $venue, '_equine_event_manager_venue_city', $cfg['venue']['city'] );
	update_post_meta( $venue, '_equine_event_manager_venue_state', $cfg['venue']['state'] );
	update_post_meta( $venue, '_equine_event_manager_venue_postal_code', $cfg['venue']['zip'] );

	// --- Event ------------------------------------------------------------
	$event = wp_insert_post( array(
		'post_type'    => 'en_event',
		'post_status'  => 'publish',
		'post_title'   => $cfg['title'],
		'post_content' => $cfg['desc'],
	) );
	update_post_meta( $event, $DEMO_FLAG, '1' );
	update_post_meta( $event, '_equine_event_manager_event_start_date', $cfg['start'] );
	update_post_meta( $event, '_equine_event_manager_event_end_date', $cfg['end'] );
	update_post_meta( $event, '_equine_event_manager_event_venue_id', $venue );
	update_post_meta( $event, '_equine_event_manager_event_producer_id', $producer );
	update_post_meta( $event, '_equine_event_manager_event_location_label', $cfg['venue']['city'] . ', ' . $cfg['venue']['state'] );
	update_post_meta( $event, '_equine_event_manager_event_cta_label', 'Reserve Now' );
	update_post_meta( $event, '_equine_event_manager_event_featured', ! empty( $cfg['featured'] ) ? 1 : 0 );
	if ( ! empty( $cfg['category'] ) ) {
		wp_set_object_terms( $event, $cfg['category'], 'en_event_category' );
	}

	// --- Reservation (stalls + optional RV) ------------------------------
	$reservation = wp_insert_post( array(
		'post_type'   => 'en_reservation',
		'post_status' => 'publish',
		'post_title'  => $cfg['title'],
	) );
	update_post_meta( $reservation, $DEMO_FLAG, '1' );
	update_post_meta( $reservation, '_en_event_source', 'native' );
	update_post_meta( $reservation, '_en_event_id', $event );
	update_post_meta( $reservation, '_en_use_global_event_source', 0 );
	// Stalls.
	update_post_meta( $reservation, '_eem_section_enabled_stalls', 1 );
	update_post_meta( $reservation, '_en_stalls_enabled', 1 );
	update_post_meta( $reservation, '_en_stall_selection_mode', 'exact_map' );
	update_post_meta( $reservation, '_en_stall_nightly_enabled', 1 );
	update_post_meta( $reservation, '_en_stall_nightly_rate', '35.00' );
	update_post_meta( $reservation, '_en_available_start_date', $cfg['start'] );
	update_post_meta( $reservation, '_en_available_end_date', $cfg['end'] );
	update_post_meta( $reservation, '_en_stall_rows', array(
		array( 'name' => 'Barn A', 'layout' => 'one-sided', 'first' => '1', 'last' => '30', 'top_first' => '', 'top_last' => '', 'bot_first' => '', 'bot_last' => '' ),
		array( 'name' => 'Barn B', 'layout' => 'one-sided', 'first' => '31', 'last' => '60', 'top_first' => '', 'top_last' => '', 'bot_first' => '', 'bot_last' => '' ),
	) );
	update_post_meta( $reservation, '_en_blocked_stalls', array( '5', '18' ) );
	// RV (only on events flagged for it).
	if ( ! empty( $cfg['rv'] ) ) {
		update_post_meta( $reservation, '_eem_section_enabled_rv', 1 );
		update_post_meta( $reservation, '_en_rv_enabled', 1 );
		update_post_meta( $reservation, '_en_rv_nightly_enabled', 1 );
		update_post_meta( $reservation, '_en_rv_nightly_rate', '45.00' );
		update_post_meta( $reservation, '_en_rv_zones', array(
			array( 'name' => 'Full Hookup', 'color' => '#1668F2', 'nightly' => '45.00', 'weekend' => '55.00', 'available_qty' => 20 ),
		) );
		update_post_meta( $reservation, '_en_rv_rows', array(
			array( 'name' => 'RV Row 1', 'layout' => 'one-sided', 'first' => '1', 'last' => '20', 'top_first' => '', 'top_last' => '', 'bot_first' => '', 'bot_last' => '', 'zone_id' => 0 ),
		) );
	}

	// --- Saved facility layout on the relational venue -------------------
	if ( class_exists( 'EEM_Venue' ) ) {
		$canonical = EEM_Venue::resolve( 'native', (string) $venue, $cfg['venue']['name'] );
		if ( $canonical > 0 ) {
			EEM_Venue::save_layout( $canonical, $reservation, $cfg['venue']['name'] . ' — Standard' );
		}
	}

	// --- Entries / Divisions ---------------------------------------------
	foreach ( $cfg['divisions'] as $d ) {
		$division = wp_insert_post( array(
			'post_type'   => 'en_entry',
			'post_status' => 'publish',
			'post_title'  => $d['name'],
		) );
		update_post_meta( $division, $DEMO_FLAG, '1' );
		update_post_meta( $division, '_en_entry_reservation_id', $reservation );
		update_post_meta( $division, '_en_division_name', $d['name'] );
		update_post_meta( $division, '_en_division_price', $d['price'] );
		update_post_meta( $division, '_en_division_spots', $d['spots'] );
		update_post_meta( $division, '_en_division_max', 2 );
		update_post_meta( $division, '_en_entry_description', $d['desc'] );
	}

	// --- Sheets & Results -------------------------------------------------
	foreach ( $cfg['sheets'] as $s ) {
		$disc_id = $discipline( $event, $s['discipline'] );
		$draw    = ! empty( $s['draw'] ) ? $make_attachment( $cfg['title'] . ' — ' . $s['label'] . ' Draw' ) : 0;
		$entry   = EEM_Sheet_Entries::add_entry( array(
			'event_id'      => $event,
			'discipline_id' => $disc_id,
			'label'         => $s['label'],
			'round'         => $s['round'],
			'entry_date'    => $s['date'],
			'drawsheet_pdf' => $draw,
		) );
		if ( $entry > 0 && ! empty( $s['result'] ) ) {
			$res_pdf = $make_attachment( $cfg['title'] . ' — ' . $s['label'] . ' Results' );
			if ( $res_pdf > 0 ) {
				EEM_Sheet_Entries::set_pdf( $entry, 'result', $res_pdf );
			}
		}
	}

	return (int) $event;
};

/* --------------------------------------------------------------------------
 * Dates relative to "today" (computed in PHP, not the workflow runtime).
 * ------------------------------------------------------------------------ */
$today = current_time( 'Y-m-d' );
$ongoing_start  = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
$ongoing_end    = gmdate( 'Y-m-d', strtotime( $today . ' +2 days' ) );
$upcoming_start = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );
$upcoming_end   = gmdate( 'Y-m-d', strtotime( $today . ' +33 days' ) );
$past_start     = gmdate( 'Y-m-d', strtotime( $today . ' -40 days' ) );
$past_end       = gmdate( 'Y-m-d', strtotime( $today . ' -37 days' ) );

/* --------------------------------------------------------------------------
 * Event A — Summer Sizzler Barrel Bash (ONGOING) — full draws + some results.
 * ------------------------------------------------------------------------ */
$a = $build_event( array(
	'title'    => 'Summer Sizzler Barrel Bash 2026',
	'desc'     => 'Three days of barrel racing and breakaway roping under the lights.',
	'start'    => $ongoing_start,
	'end'      => $ongoing_end,
	'featured' => true,
	'rv'       => true,
	'category' => array( 'Barrel Racing' ),
	'producer' => array( 'name' => 'Lone Star Rodeo Co.', 'contact' => 'Dale Whitfield', 'email' => 'dale@lonestarrodeo.test', 'phone' => '(817) 555-0142' ),
	'venue'    => array( 'name' => 'Will Rogers Memorial Coliseum', 'address' => '3401 W Lancaster Ave', 'city' => 'Fort Worth', 'state' => 'TX', 'zip' => '76107' ),
	'divisions' => array(
		array( 'name' => 'Open 5D Barrels', 'price' => '50.00', 'spots' => 40, 'desc' => 'Open to all riders, 5D format.' ),
		array( 'name' => 'Youth 3D Barrels', 'price' => '35.00', 'spots' => 30, 'desc' => 'Riders 18 and under.' ),
	),
	'sheets' => array(
		array( 'discipline' => 'Barrel Racing', 'label' => 'Open 5D Long Go', 'round' => '1st-go', 'date' => $ongoing_start, 'draw' => true, 'result' => true ),
		array( 'discipline' => 'Barrel Racing', 'label' => 'Open 5D Short Go', 'round' => 'short-go', 'date' => $ongoing_end, 'draw' => true, 'result' => true ),
		array( 'discipline' => 'Barrel Racing', 'label' => 'Youth 3D Go', 'round' => '1st-go', 'date' => $ongoing_start, 'draw' => true, 'result' => false ),
		array( 'discipline' => 'Breakaway Roping', 'label' => 'Breakaway Average', 'round' => 'average', 'date' => $ongoing_end, 'draw' => true, 'result' => true ),
	),
) );
echo "Built ONGOING event #$a (Summer Sizzler Barrel Bash 2026)\n";

/* --------------------------------------------------------------------------
 * Event B — Prairie Fall Classic Roping (UPCOMING) — draws only, results pending.
 * ------------------------------------------------------------------------ */
$b = $build_event( array(
	'title'    => 'Prairie Fall Classic Roping 2026',
	'desc'     => 'Premier tie-down and team roping on the prairie.',
	'start'    => $upcoming_start,
	'end'      => $upcoming_end,
	'featured' => false,
	'rv'       => false,
	'category' => array( 'Roping' ),
	'producer' => array( 'name' => 'Prairie Events LLC', 'contact' => 'Megan Cole', 'email' => 'megan@prairieevents.test', 'phone' => '(402) 555-0188' ),
	'venue'    => array( 'name' => 'Lancaster Event Center', 'address' => '4100 N 84th St', 'city' => 'Lincoln', 'state' => 'NE', 'zip' => '68507' ),
	'divisions' => array(
		array( 'name' => 'Tie-Down Open', 'price' => '60.00', 'spots' => 25, 'desc' => 'Open tie-down roping.' ),
		array( 'name' => 'Team Roping #9.5', 'price' => '80.00', 'spots' => 40, 'desc' => '#9.5 handicap team roping.' ),
	),
	'sheets' => array(
		array( 'discipline' => 'Tie-Down Roping', 'label' => 'Tie-Down 1st Go', 'round' => '1st-go', 'date' => $upcoming_start, 'draw' => true, 'result' => false ),
		array( 'discipline' => 'Team Roping', 'label' => 'Team Roping 1st Go', 'round' => '1st-go', 'date' => $upcoming_start, 'draw' => true, 'result' => false ),
	),
) );
echo "Built UPCOMING event #$b (Prairie Fall Classic Roping 2026)\n";

/* --------------------------------------------------------------------------
 * Event C — Black Hills Winter Reining (PAST) — full draws + results.
 * ------------------------------------------------------------------------ */
$c = $build_event( array(
	'title'    => 'Black Hills Winter Reining 2026',
	'desc'     => 'Season-closing reining championship.',
	'start'    => $past_start,
	'end'      => $past_end,
	'featured' => false,
	'rv'       => false,
	'category' => array( 'Reining' ),
	'producer' => array( 'name' => 'Black Hills Equestrian Assoc.', 'contact' => 'Sarah Johnson', 'email' => 'sarah@bhea.test', 'phone' => '(605) 555-0100' ),
	'venue'    => array( 'name' => 'Rushmore Plaza Civic Center', 'address' => '444 Mt Rushmore Rd N', 'city' => 'Rapid City', 'state' => 'SD', 'zip' => '57701' ),
	'divisions' => array(
		array( 'name' => 'Open Reining', 'price' => '75.00', 'spots' => 30, 'desc' => 'NRHA-approved open reining.' ),
	),
	'sheets' => array(
		array( 'discipline' => 'Reining', 'label' => 'Open Reining Go 1', 'round' => '1st-go', 'date' => $past_start, 'draw' => true, 'result' => true ),
		array( 'discipline' => 'Reining', 'label' => 'Open Reining Finals', 'round' => 'finals', 'date' => $past_end, 'draw' => true, 'result' => true ),
	),
) );
echo "Built PAST event #$c (Black Hills Winter Reining 2026)\n";

update_option( 'eem_sr_demo_terms', $demo_terms );

echo "\nDone. 3 events seeded with producers, venues (+ saved layouts), reservations (stalls/RV), divisions, and Sheets & Results.\n";
