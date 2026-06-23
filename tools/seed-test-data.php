<?php
/**
 * Reusable TEST-DATA seed for visual verification (Phase 1 / Phase 2).
 *
 * Creates a realistic roster of test customers and a varied set of orders
 * (single/multi-stall, RV-only, combo, add-on; mixed paid/pending/partial-refund
 * statuses; several with Special Requests) across every stall/RV reservation
 * that currently has a configured chart. Stall/RV units are pre-assigned from
 * each reservation's real available pool, with a few orders left unassigned so
 * the chart's "issues" surface has something to show.
 *
 * WHY: the local dev site lacks realistic customer/order data, which every
 * visual-verification step in the V1 build needs. This is a TOOL, not a one-shot
 * — re-run it any time to refresh test data (e.g. after a migration).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW TO RUN (prompt-free; same pattern as the smoke runner):
 *
 *   wp eval-file tools/seed-test-data.php
 *
 *   …or with the Local-bundled binaries:
 *   /Applications/Local.app/Contents/Resources/extraResources/lightning-services/\
 *     php-8.2.29+0/bin/darwin-arm64/bin/php \
 *     /Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar \
 *     eval-file tools/seed-test-data.php --path="/Users/<you>/Local Sites/<site>/app/public"
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IDEMPOTENT: every seeded row uses an email ending in `@eem-test.local`. Each
 * run first DELETES all rows with that domain, then re-creates them — so
 * re-running never duplicates, and it NEVER touches real data or the `@eemdemo.test`
 * demo roster. (This delete is the only destructive op and is self-scoped to the
 * test domain; no confirm flag needed.)
 *
 * FUTURE-EXTENSIBLE: the roster + ORDER_PLAN below are plain data arrays. Add
 * columns/fields as later commits need them:
 *   - commit #2 (Group Name)  → add a 'group' key to ORDER_PLAN rows + a
 *                               `Group Name: …` notes line in build_notes().
 *   - commit #5 (Tack Stalls) → set `tack_stall_qty` + a `Tack Stalls: …` notes
 *                               line (the column already exists on the table).
 *
 * @package EEM_Plugin
 * @since   2.4.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds idempotent test customers + orders for visual verification.
 */
class EEM_Test_Data_Seeder {

	/** Marker domain — every seeded row's email ends with this. */
	const EMAIL_DOMAIN = '@eem-test.local';

	/** Order numbers start here (high range to avoid colliding with real/demo). */
	const ORDER_NUM_BASE = 9001;

	/** Fallback nightly rates when the reservation has none configured. */
	const STALL_NIGHTLY_FALLBACK = 25.00;
	const RV_NIGHTLY_FALLBACK     = 45.00;
	const FEE_PCT                 = 0.03;

	/**
	 * Customer roster — [first, last, phone-suffix]. Sorts sensibly under
	 * "Last, First". 14 customers; several get 2 orders in the plan.
	 *
	 * @return array<int, array{0:string,1:string,2:string}>
	 */
	private static function roster(): array {
		return array(
			array( 'Amelia',  'Brooks',     '0101' ),
			array( 'Wei',     'Chen',       '0102' ),
			array( 'Carlos',  'Delgado',    '0103' ),
			array( 'Hannah',  'Bergstrom',  '0104' ),
			array( 'Tobias',  'Klein',      '0105' ),
			array( 'Grace',   'Mwangi',     '0106' ),
			array( 'Priya',   'Nair',       '0107' ),
			array( 'Liam',    "O'Connor",   '0108' ),
			array( 'Marcus',  'Okafor',     '0109' ),
			array( 'Nathan',  'Park',       '0110' ),
			array( 'Aisha',   'Rahman',     '0111' ),
			array( 'Sofia',   'Russo',      '0112' ),
			array( 'Emma',    'Thompson',   '0113' ),
			array( 'Darnell', 'Washington', '0114' ),
		);
	}

	/**
	 * Order plan — each row:
	 *   [cust_idx, stall_qty, rv_qty, shavings_qty, status, assign(bool), special_requests, group_name]
	 * status ∈ completed | pending | partially_refunded | invoice_sent.
	 * group_name (V1 D2) is an optional clustering tag; '' = no group. Several
	 * orders share "Bluegrass Trailer Crew" to demo the Show-by-group filter.
	 *
	 * @return array<int, array{0:int,1:int,2:int,3:int,4:string,5:bool,6:string,7:string}>
	 */
	private static function order_plan(): array {
		return array(
			array( 0,  3, 0, 0, 'completed',          true,  'Near the wash rack please', '' ),
			array( 0,  0, 1, 0, 'completed',          true,  '', '' ),
			array( 1,  1, 0, 2, 'completed',          true,  '', '' ),
			array( 2,  5, 0, 0, 'pending',            true,  'Stalls together if possible — traveling with three horses', 'Delgado Performance Horses' ),
			array( 3,  0, 2, 0, 'completed',          true,  '', '' ),
			array( 4,  8, 0, 0, 'completed',          true,  'End of row preferred', 'Bluegrass Trailer Crew' ),
			array( 5,  2, 1, 0, 'completed',          true,  '', '' ),
			array( 6,  1, 0, 0, 'partially_refunded', true,  '', '' ),
			array( 7,  3, 0, 4, 'completed',          true,  'Quiet area for a nervous mare', '' ),
			array( 8,  2, 0, 0, 'invoice_sent',       false, '', '' ),
			array( 9,  0, 1, 0, 'pending',            false, '', '' ),
			array( 10, 4, 0, 0, 'completed',          true,  '', '' ),
			array( 11, 1, 1, 0, 'completed',          true,  'Shaded side if available', 'Bluegrass Trailer Crew' ),
			array( 12, 2, 0, 0, 'completed',          true,  '', '' ),
			array( 13, 6, 0, 0, 'completed',          true,  '', 'Bluegrass Trailer Crew' ),
			array( 1,  0, 1, 0, 'completed',          true,  '', '' ),
			array( 2,  1, 0, 0, 'completed',          true,  '', 'Delgado Performance Horses' ),
			array( 5,  3, 0, 0, 'pending',            false, '', '' ),
			array( 10, 0, 2, 0, 'completed',          true,  '', '' ),
			array( 12, 1, 0, 0, 'partially_refunded', true,  'Close to parking — limited mobility', '' ),
		);
	}

	/**
	 * Run the seed. Reports progress via WP_CLI.
	 *
	 * @return void
	 */
	public static function run(): void {
		global $wpdb;
		$stall_tbl = $wpdb->prefix . 'en_stall_reservations';
		$rv_tbl    = $wpdb->prefix . 'en_rv_reservations';

		// 1. Discover configured stall/RV reservations + their available pools.
		$targets = self::discover_targets();
		if ( empty( $targets ) ) {
			self::log( 'ERROR: no stall/RV reservation with a configured chart was found. Configure stall rows / RV zones on a reservation first.' );
			return;
		}
		self::log( sprintf( 'Found %d configured reservation(s): %s', count( $targets ), implode( ', ', array_map( static function ( $t ) {
			return sprintf( '#%d "%s"', $t['id'], $t['title'] );
		}, $targets ) ) ) );

		// 2. Purge prior test rows (idempotency — scoped to the test domain only).
		$like      = '%' . $wpdb->esc_like( self::EMAIL_DOMAIN );
		$del_stall = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$stall_tbl} WHERE email LIKE %s", $like ) ); // phpcs:ignore WordPress.DB
		$del_rv    = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$rv_tbl} WHERE email LIKE %s", $like ) ); // phpcs:ignore WordPress.DB
		self::log( sprintf( 'Purged prior test rows: %d stall + %d RV (email LIKE %s).', $del_stall, $del_rv, self::EMAIL_DOMAIN ) );

		$roster = self::roster();
		$plan   = self::order_plan();
		$report = array();
		$order_n = self::ORDER_NUM_BASE;
		$t_count = count( $targets );

		foreach ( $plan as $i => $row ) {
			list( $cust_idx, $stall_qty, $rv_qty, $shavings, $status, $assign, $special, $group ) = $row;

			// Round-robin across discovered reservations (1 today → all on #3499).
			$tk     = array_keys( $targets )[ $i % $t_count ];
			$target =& $targets[ $tk ];

			$cust        = $roster[ $cust_idx ];
			$name        = trim( $cust[0] . ' ' . $cust[1] );
			$email       = self::email_for( $cust );
			$phone       = '(559) 555-' . $cust[2];
			$order_num   = (string) $order_n;
			$token       = md5( $order_num . $email );
			$created_at  = self::staggered_date( $i );

			$stall_units = array();
			$rv_units    = array();
			if ( $assign && $stall_qty > 0 ) {
				$stall_units = self::take( $target['stall_pool'], $stall_qty );
			}
			if ( $assign && $rv_qty > 0 ) {
				$rv_units = self::take( $target['rv_pool'], $rv_qty );
			}

			$nights = $target['nights'];

			if ( $stall_qty > 0 ) {
				$sub   = round( $stall_qty * $target['stall_rate'] * $nights, 2 );
				$cfee  = round( $sub * self::FEE_PCT, 2 );
				$notes = self::build_notes( $target['id'], $token, 'stall', $stall_units, $shavings, $special, $group );
				$wpdb->insert( $stall_tbl, array( // phpcs:ignore WordPress.DB
					'event_source'          => 'native',
					'event_id'              => $target['id'],
					// #23 — denormalized reservation post id, matching the production
					// checkout path (shortcodes writes reservation_id at order time).
					'reservation_id'        => $target['id'],
					'customer_name'         => $name,
					'email'                 => $email,
					'phone'                 => $phone,
					'stall_qty'             => $stall_qty,
					'required_shavings_qty' => $shavings,
					'stay_type'             => 'Full Stay',
					'arrival_date'          => $target['arrival'],
					'departure_date'        => $target['departure'],
					'unit_price'            => number_format( $target['stall_rate'], 2, '.', '' ),
					'subtotal'              => number_format( $sub, 2, '.', '' ),
					'convenience_fee'       => number_format( $cfee, 2, '.', '' ),
					'total'                 => number_format( $sub + $cfee, 2, '.', '' ),
					'payment_status'        => $status,
					'payment_gateway'       => 'stripe',
					'order_number'          => $order_num,
					'transaction_id'        => 'ch_test_' . $order_num,
					'notes'                 => $notes,
					'created_at'            => $created_at,
				) );
			}

			if ( $rv_qty > 0 ) {
				$sub   = round( $rv_qty * $target['rv_rate'] * $nights, 2 );
				$cfee  = round( $sub * self::FEE_PCT, 2 );
				// Put the special request + group on BOTH rows of a combo order so
				// they survive whichever row seeds the grouped order's notes.
				$notes = self::build_notes( $target['id'], $token, 'rv', $rv_units, 0, $special, $group );
				$wpdb->insert( $rv_tbl, array( // phpcs:ignore WordPress.DB
					'event_source'    => 'native',
					'event_id'        => $target['id'],
					// #23 — denormalized reservation post id (see stall insert above).
					'reservation_id'  => $target['id'],
					'customer_name'   => $name,
					'email'           => $email,
					'phone'           => $phone,
					'rv_qty'          => $rv_qty,
					'rv_type'         => 'Standard',
					'stay_type'       => 'Full Stay',
					'arrival_date'    => $target['arrival'],
					'departure_date'  => $target['departure'],
					'unit_price'      => number_format( $target['rv_rate'], 2, '.', '' ),
					'subtotal'        => number_format( $sub, 2, '.', '' ),
					'convenience_fee' => number_format( $cfee, 2, '.', '' ),
					'total'           => number_format( $sub + $cfee, 2, '.', '' ),
					'payment_status'  => $status,
					'payment_gateway' => 'stripe',
					'order_number'    => $order_num,
					'transaction_id'  => 'ch_test_' . $order_num,
					'notes'           => $notes,
					'created_at'      => $created_at,
				) );
			}

			$report[] = sprintf(
				'#%05d  %-22s  %-9s  stall:%d%s rv:%d%s  %s%s',
				(int) $order_num,
				$name,
				$status,
				$stall_qty,
				$stall_units ? '(' . implode( ',', $stall_units ) . ')' : ( $stall_qty ? '(UNASSIGNED)' : '' ),
				$rv_qty,
				$rv_units ? '(' . implode( ',', $rv_units ) . ')' : ( $rv_qty ? '(UNASSIGNED)' : '' ),
				$target['id'] ? 'res#' . $target['id'] : '',
				( '' !== $group ? '  ‹' . $group . '›' : '' ) . ( '' !== $special ? '  ✦ "' . $special . '"' : '' )
			);
			$order_n++;
			unset( $target );
		}

		self::log( "\nSeeded orders:" );
		foreach ( $report as $line ) {
			self::log( '  ' . $line );
		}
		self::log( sprintf( "\nDONE — %d orders for %d customers across %d reservation(s). Re-run any time; rows with %s are replaced, never duplicated.", count( $plan ), count( self::roster() ), count( $targets ), self::EMAIL_DOMAIN ) );
	}

	/**
	 * Discover stall/RV reservations with a configured chart + their pools.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function discover_targets(): array {
		$q = new WP_Query( array(
			'post_type'      => 'en_reservation',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_en_stalls_enabled', 'value' => '1' ),
				array( 'key' => '_en_rv_enabled', 'value' => '1' ),
			),
		) );
		if ( empty( $q->posts ) ) {
			return array();
		}

		$admin = EEM_Admin::for_compute();
		$cfg_m = new ReflectionMethod( $admin, 'get_stall_chart_config' );
		$cfg_m->setAccessible( true );

		$targets = array();
		foreach ( $q->posts as $rid ) {
			$cfg     = $cfg_m->invoke( $admin, $rid );
			$stalls  = array_values( (array) ( $cfg['available_stall_units'] ?? array() ) );
			$rvs     = array_values( (array) ( $cfg['available_rv_lot_names'] ?? array() ) );
			if ( empty( $stalls ) && empty( $rvs ) ) {
				continue; // No configured chart — skip.
			}
			$start = (string) ( get_post_meta( $rid, '_en_source_event_start_date', true ) ?: get_post_meta( $rid, '_en_event_start_date', true ) );
			$end   = (string) ( get_post_meta( $rid, '_en_nightly_end_date', true ) ?: get_post_meta( $rid, '_en_event_end_date', true ) );
			$start = $start ?: gmdate( 'Y-m-d', strtotime( '+30 days' ) );
			$end   = $end ?: gmdate( 'Y-m-d', strtotime( $start . ' +2 days' ) );
			$nights = max( 1, (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) );

			$targets[ $rid ] = array(
				'id'         => $rid,
				'title'      => get_the_title( $rid ),
				'stall_pool' => $stalls,
				'rv_pool'    => $rvs,
				'arrival'    => $start,
				'departure'  => $end,
				'nights'     => $nights,
				'stall_rate' => (float) ( get_post_meta( $rid, '_en_stall_nightly_rate', true ) ?: self::STALL_NIGHTLY_FALLBACK ),
				'rv_rate'    => (float) ( get_post_meta( $rid, '_en_rv_nightly_rate', true ) ?: self::RV_NIGHTLY_FALLBACK ),
			);
		}
		return $targets;
	}

	/**
	 * Pop N units off the front of a pool (by reference).
	 *
	 * @param array<int, string> $pool Pool, mutated.
	 * @param int                $n    Count to take.
	 * @return array<int, string>
	 */
	private static function take( array &$pool, int $n ): array {
		$out = array();
		for ( $i = 0; $i < $n && ! empty( $pool ); $i++ ) {
			$out[] = (string) array_shift( $pool );
		}
		return $out;
	}

	/**
	 * Build an order-notes string. Special requests are a bare freeform line
	 * (the consumer's parser strips known-label lines and returns the remainder).
	 *
	 * @param int                $rid     Reservation id.
	 * @param string             $token   Submission token (groups stall+rv rows).
	 * @param string             $kind    'stall' | 'rv'.
	 * @param array<int, string> $units   Assigned units (may be empty).
	 * @param int                $shav    Required shavings qty (stall add-on).
	 * @param string             $special Special requests freeform text.
	 * @param string             $group   Group Name tag (V1 D2); '' = none.
	 * @return string
	 */
	private static function build_notes( int $rid, string $token, string $kind, array $units, int $shav, string $special, string $group = '' ): string {
		$lines   = array();
		$lines[] = 'Reservation setup ID: ' . $rid;
		$lines[] = 'Submission token: ' . $token;
		if ( ! empty( $units ) ) {
			$lines[] = ( 'stall' === $kind ? 'Assigned Stall Units: ' : 'Assigned RV Lots: ' ) . implode( ', ', $units );
		}
		// V1 D2: Group Name is a labelled metadata line (the parser strips it, so
		// it won't leak into the displayed special requests).
		if ( '' !== $group ) {
			$lines[] = 'Group Name: ' . $group;
		}
		// NB: shavings live in the `required_shavings_qty` COLUMN (which drives the
		// "Add-On" type badge). Do NOT write a "Required Shavings:" notes line —
		// the special-requests parser doesn't recognize that label and would leak
		// it into the displayed special requests. $shav is intentionally unused here.
		unset( $shav );
		if ( '' !== $special ) {
			$lines[] = $special;
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Deterministic test email for a roster entry.
	 *
	 * @param array{0:string,1:string,2:string} $cust
	 * @return string
	 */
	private static function email_for( array $cust ): string {
		$slug = strtolower( $cust[0] . '.' . $cust[1] );
		$slug = preg_replace( '/[^a-z0-9.]+/', '', $slug );
		return $slug . self::EMAIL_DOMAIN;
	}

	/**
	 * Staggered created_at so "Last Activity" varies across customers.
	 *
	 * @param int $i Order index.
	 * @return string Y-m-d H:i:s
	 */
	private static function staggered_date( int $i ): string {
		$days = ( count( self::order_plan() ) - $i ) * 2; // newest orders = smallest offset
		return gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	}

	/**
	 * Log a line via WP-CLI when available, else echo.
	 *
	 * @param string $msg
	 * @return void
	 */
	private static function log( string $msg ): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::log( $msg );
		} else {
			echo esc_html( $msg ) . "\n";
		}
	}
}

// Auto-run when invoked via `wp eval-file` (CLI context only — never web).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	EEM_Test_Data_Seeder::run();
}
