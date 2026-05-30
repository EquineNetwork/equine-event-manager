<?php
/**
 * WP-CLI command: seed demo data for admin page visual verification.
 *
 * Populates reservation #43 (2026 Southeast Region Super Sort) with a
 * realistic stall/RV/pre-entry configuration and 10 fake customer orders,
 * so every admin page can be visually verified without manual setup.
 *
 * Usage:
 *   wp eem seed_demo              # Seed if not already seeded (idempotent)
 *   wp eem seed_demo --reset      # Clear existing demo data, then re-seed
 *   wp eem seed_demo --status     # Report whether demo data exists
 *
 * Idempotency marker: wp_options key `equine_event_manager_demo_seeded`.
 * All demo order rows use emails ending in `@eemdemo.test`.
 *
 * @package EEM_Plugin
 * @since   2.3.25
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * EEM_Seed_Demo_Command — WP-CLI `wp eem seed_demo` handler.
 *
 * Seeds reservation #43 with full stall/RV/pre-entry config and
 * 10 realistic fake customer orders for visual verification of
 * all admin pages without manual data entry.
 *
 * @since 2.3.25
 */
class EEM_Seed_Demo_Command extends WP_CLI_Command {

	/** Idempotency marker option key. */
	const SEEDED_OPTION = 'equine_event_manager_demo_seeded';

	/** Email domain for all seeded orders (easy teardown). */
	const DEMO_EMAIL_DOMAIN = '@eemdemo.test';

	/** Target reservation post ID. */
	const RESERVATION_ID = 43;

	/** Event dates. */
	const ARRIVAL_DATE   = '2026-06-15';
	const DEPARTURE_DATE = '2026-06-18';
	const NIGHTS         = 3;

	/** Base nightly rates. */
	const STALL_NIGHTLY = 35.00;
	const RV_NIGHTLY    = 45.00;

	/** Convenience fee: 3% per spec. */
	const FEE_PCT = 0.03;

	// ------------------------------------------------------------------ //
	// Entry point                                                          //
	// ------------------------------------------------------------------ //

	/**
	 * Seed demo data for visual verification of admin pages.
	 *
	 * ## OPTIONS
	 *
	 * [--reset]
	 * : Wipe existing demo data before seeding.
	 *
	 * [--status]
	 * : Report seeding state and exit.
	 *
	 * ## EXAMPLES
	 *
	 *   wp eem seed_demo
	 *   wp eem seed_demo --reset
	 *   wp eem seed_demo --status
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {

		if ( isset( $assoc_args['status'] ) ) {
			$ts = get_option( self::SEEDED_OPTION );
			if ( $ts ) {
				WP_CLI::success( 'Demo data is seeded (seeded at ' . date( 'Y-m-d H:i:s', (int) $ts ) . ').' );
			} else {
				WP_CLI::line( 'Demo data is NOT seeded.' );
			}
			return;
		}

		if ( isset( $assoc_args['reset'] ) ) {
			WP_CLI::line( 'Resetting demo data…' );
			$this->teardown();
			WP_CLI::line( '  ✓ Cleared.' );
		} elseif ( get_option( self::SEEDED_OPTION ) ) {
			WP_CLI::success( 'Demo data already seeded. Use --reset to re-seed.' );
			return;
		}

		// Guard: target reservation must exist.
		$post = get_post( self::RESERVATION_ID );
		if ( ! $post || 'en_reservation' !== $post->post_type ) {
			WP_CLI::error(
				sprintf( 'Reservation #%d does not exist or is not an en_reservation post.', self::RESERVATION_ID )
			);
			return;
		}

		WP_CLI::line( 'Seeding reservation #' . self::RESERVATION_ID . ' meta…' );
		$this->seed_reservation_meta();
		WP_CLI::line( '  ✓ Reservation meta written.' );

		WP_CLI::line( 'Seeding customer orders…' );
		$n = $this->seed_orders();
		WP_CLI::line( sprintf( '  ✓ %d order rows inserted.', $n ) );

		WP_CLI::line( 'Advancing order-number counter…' );
		$this->advance_order_number_counter();
		WP_CLI::line( '  ✓ Counter advanced past demo range.' );

		// Optional bonus: seed a second reservation that has stall chart DISABLED
		// so the list page shows variety.
		$this->maybe_seed_second_reservation();

		update_option( self::SEEDED_OPTION, time(), false );

		WP_CLI::success(
			'Done. Open admin.php?page=equine-event-manager-stall-charts to verify the list page, ' .
			'then click reservation #' . self::RESERVATION_ID . ' to verify the detail page.'
		);
	}

	// ------------------------------------------------------------------ //
	// Teardown                                                             //
	// ------------------------------------------------------------------ //

	/**
	 * Remove seeded order rows (identified by email domain) and the
	 * idempotency marker. Reservation meta is left untouched.
	 *
	 * @return void
	 */
	private function teardown(): void {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( self::DEMO_EMAIL_DOMAIN );

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}en_stall_reservations WHERE email LIKE %s",
			$like
		) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}en_rv_reservations WHERE email LIKE %s",
			$like
		) );

		delete_option( self::SEEDED_OPTION );
	}

	// ------------------------------------------------------------------ //
	// Reservation #43 meta                                                 //
	// ------------------------------------------------------------------ //

	/**
	 * Write full reservation configuration to post #43.
	 *
	 * Stall rows  : Red Barn (back-to-back 100-131), Blue Barn (one-sided 200-215),
	 *               Y Section (one-sided Y1-Y12).
	 * RV zones    : Red Lot (+$35/night), Blue Lot (+$0/night).
	 * RV rows     : Premium Row (1-10, Red Lot), Standard Row (11-30, Blue Lot).
	 * Pre-entries : Friday Reining, Saturday Cutting, Sunday RCH.
	 * Add-ons     : T-Shirt ($25), Souvenir Hat ($20).
	 *
	 * @return void
	 */
	private function seed_reservation_meta(): void {
		$id = self::RESERVATION_ID;

		// ── Title / description / dates ──────────────────────────────── //
		wp_update_post( array(
			'ID'         => $id,
			'post_title' => '2026 Southeast Region Super Sort',
			'post_status' => 'publish',
		) );
		update_post_meta( $id, '_en_stall_description',
			'Welcome to the 2026 Southeast Region Super Sort! Stall reservations include shavings and daily water service. All stalls are 12×12. Please review the facility map before selecting your preferred area.' );
		update_post_meta( $id, '_en_available_start_date', self::ARRIVAL_DATE );
		update_post_meta( $id, '_en_available_end_date',   self::DEPARTURE_DATE );

		// ── Event source (native, self-referential for demo) ─────────── //
		update_post_meta( $id, '_en_event_source',            'native' );
		update_post_meta( $id, '_en_event_id',                $id );
		update_post_meta( $id, '_en_use_global_event_source', 0 );

		// ── Event Day Info ────────────────────────────────────────────── //
		update_post_meta( $id, '_eem_event_day_enabled',   1 );
		update_post_meta( $id, '_eem_event_day_checkin',   'Monday, June 15 — Stalls available from 8:00 AM' );
		update_post_meta( $id, '_eem_event_day_bring',     'Halter, lead rope, shavings bag (provided), buckets, fans' );
		update_post_meta( $id, '_eem_event_day_parking',   'Horse trailers park in designated overflow lots; passenger vehicles in Lot C' );
		update_post_meta( $id, '_eem_event_day_contact',   'Show Office: (555) 867-5309 · show@supersortevent.com' );

		// ── Stall section enabled + chart enabled ─────────────────────── //
		update_post_meta( $id, '_en_stalls_enabled',       1 );
		update_post_meta( $id, '_en_stall_chart_enabled',  1 );
		update_post_meta( $id, '_en_stall_selection_mode', 'exact_map' );

		// ── Stall rates ──────────────────────────────────────────────── //
		update_post_meta( $id, '_en_stall_nightly_enabled', 1 );
		update_post_meta( $id, '_en_stall_nightly_rate',    number_format( self::STALL_NIGHTLY, 2, '.', '' ) );

		// ── Required Shavings ────────────────────────────────────────── //
		update_post_meta( $id, '_en_required_shavings_enabled',       1 );
		update_post_meta( $id, '_en_required_shavings_per_stall',     2 );
		update_post_meta( $id, '_en_required_shavings_price',         '12.00' );

		// ── Max stalls per customer ───────────────────────────────────── //
		update_post_meta( $id, '_en_stall_max_per_customer', 6 );

		// ── Stall rows ────────────────────────────────────────────────── //
		// Red Barn: back-to-back 100-131 (top 100-115, bottom 116-131)
		// Blue Barn: one-sided 200-215
		// Y Section: one-sided Y1-Y12
		update_post_meta( $id, '_en_stall_rows', array(
			array(
				'name'      => 'Red Barn',
				'layout'    => 'back-to-back',
				'first'     => '',
				'last'      => '',
				'top_first' => '100',
				'top_last'  => '115',
				'bot_first' => '116',
				'bot_last'  => '131',
			),
			array(
				'name'      => 'Blue Barn',
				'layout'    => 'one-sided',
				'first'     => '200',
				'last'      => '215',
				'top_first' => '',
				'top_last'  => '',
				'bot_first' => '',
				'bot_last'  => '',
			),
			array(
				'name'      => 'Y Section',
				'layout'    => 'one-sided',
				'first'     => 'Y1',
				'last'      => 'Y12',
				'top_first' => '',
				'top_last'  => '',
				'bot_first' => '',
				'bot_last'  => '',
			),
		) );

		// ── Blocked stalls ───────────────────────────────────────────── //
		update_post_meta( $id, '_en_blocked_stalls', array( '105', '107', 'Y3' ) );

		// ── RV section enabled ────────────────────────────────────────── //
		update_post_meta( $id, '_en_rv_enabled',           1 );
		update_post_meta( $id, '_en_rv_selection_mode',    'exact_map' );
		update_post_meta( $id, '_en_rv_nightly_enabled',   1 );
		update_post_meta( $id, '_en_rv_nightly_rate',      number_format( self::RV_NIGHTLY, 2, '.', '' ) );
		update_post_meta( $id, '_en_rv_max_per_customer',  3 );

		// ── RV zones ─────────────────────────────────────────────────── //
		update_post_meta( $id, '_en_rv_zones', array(
			array(
				'name'          => 'Red Lot',
				'color'         => '#E53535',
				'nightly'       => '35.00',
				'weekend'       => '50.00',
				'available_qty' => 10,
			),
			array(
				'name'          => 'Blue Lot',
				'color'         => '#1668F2',
				'nightly'       => '0.00',
				'weekend'       => '0.00',
				'available_qty' => 20,
			),
		) );

		// ── RV rows ──────────────────────────────────────────────────── //
		update_post_meta( $id, '_en_rv_rows', array(
			array(
				'name'      => 'Premium Row',
				'layout'    => 'one-sided',
				'first'     => '1',
				'last'      => '10',
				'top_first' => '',
				'top_last'  => '',
				'bot_first' => '',
				'bot_last'  => '',
				'zone_id'   => 'Red Lot',
			),
			array(
				'name'      => 'Standard Row',
				'layout'    => 'one-sided',
				'first'     => '11',
				'last'      => '30',
				'top_first' => '',
				'top_last'  => '',
				'bot_first' => '',
				'bot_last'  => '',
				'zone_id'   => 'Blue Lot',
			),
		) );

		// ── Blocked RV lots ──────────────────────────────────────────── //
		update_post_meta( $id, '_en_blocked_rv_lots', array( '5', '22' ) );

		// ── Pre-entries ──────────────────────────────────────────────── //
		update_post_meta( $id, '_en_event_pre_entries_enabled', 1 );
		update_post_meta( $id, '_en_event_pre_entries', array(
			array(
				'title'            => 'Friday Reining Class',
				'inventory'        => 30,
				'price'            => '75.00',
				'max_per_customer' => 2,
			),
			array(
				'title'            => 'Saturday Cutting Class',
				'inventory'        => 25,
				'price'            => '95.00',
				'max_per_customer' => 2,
			),
			array(
				'title'            => 'Sunday Reined Cow Horse',
				'inventory'        => 20,
				'price'            => '120.00',
				'max_per_customer' => 1,
			),
		) );

		// ── General add-ons ──────────────────────────────────────────── //
		update_post_meta( $id, '_en_general_addons_enabled', 1 );
		update_post_meta( $id, '_en_general_addons', array(
			array(
				'name'        => 'T-Shirt',
				'description' => 'Official 2026 Super Sort event tee',
				'applies_to'  => 'any',
				'price'       => '25.00',
				'per_label'   => 'each',
			),
			array(
				'name'        => 'Souvenir Hat',
				'description' => 'Embroidered event cap',
				'applies_to'  => 'any',
				'price'       => '20.00',
				'per_label'   => 'each',
			),
		) );

		// ── Convenience fee ──────────────────────────────────────────── //
		update_post_meta( $id, '_en_convenience_fee_enabled', 1 );
		update_post_meta( $id, '_en_convenience_fee_type',    'percent' );
		update_post_meta( $id, '_en_convenience_fee_amount',  '3.00' );

		// ── Cancellation policy ──────────────────────────────────────── //
		update_post_meta( $id, '_eem_cancellation_policy_override',
			'Cancellations made 30+ days before the event receive a full refund. Cancellations 15–29 days prior receive a 50% refund. No refunds within 14 days of the event. Stall transfers are permitted at no charge with 48-hour notice.' );

		// ── Agreement ────────────────────────────────────────────────── //
		update_post_meta( $id, '_en_venue_agreement_enabled', 1 );
		update_post_meta( $id, '_en_venue_agreement_text',
			'By completing this reservation I agree to all facility rules, the cancellation policy stated above, and that I am responsible for the care and conduct of my horses and party during the event.' );

		// ── Check-in/Check-out ───────────────────────────────────────── //
		update_post_meta( $id, '_en_checkin_checkout_enabled', 1 );
		update_post_meta( $id, '_en_checkin_time',  '2026-06-15T08:00' );
		update_post_meta( $id, '_en_checkout_time', '2026-06-18T18:00' );
	}

	// ------------------------------------------------------------------ //
	// Order rows                                                           //
	// ------------------------------------------------------------------ //

	/**
	 * Insert 10 fake customer orders (11 DB rows — Amanda has stall + RV).
	 *
	 * Customer roster per spec:
	 *   #0001 John Smith       — Red Barn 101, 102, 103 + Premium 1, Premium 2
	 *   #0002 Jane Doe         — Blue Barn 200, 201 + Standard 11
	 *   #0003 Robert Johnson   — Red Barn 108, 109, 110, 111
	 *   #0004 Emily Davis      — Y Section Y1
	 *   #0005 Michael Brown    — Red Barn 104, 106 + Premium 3
	 *   #0006 Sarah Wilson     — Blue Barn 210, 211, 212
	 *   #0007 David Martinez   — Red Barn top 120-125 (6 stalls — max-per-customer limit)
	 *   #0008 Lisa Anderson    — Y Section Y5, Y6
	 *   #0009 James Taylor     — Standard 15 (RV only)
	 *   #0010 Karen Thomas     — Blue Barn 215 + INTENTIONAL ISSUE: also "Red Barn 199"
	 *                           (stall 199 does not exist in the configured rows →
	 *                           triggers Assignment Issues card on the stall chart)
	 *
	 * @return int DB rows inserted.
	 */
	private function seed_orders(): int {
		global $wpdb;

		$stall  = $wpdb->prefix . 'en_stall_reservations';
		$rv     = $wpdb->prefix . 'en_rv_reservations';
		$event  = self::RESERVATION_ID;
		$arr    = self::ARRIVAL_DATE;
		$dep    = self::DEPARTURE_DATE;
		$nights = self::NIGHTS;
		$n      = 0;

		// Helpers
		$stall_subtotal = function ( int $qty ) use ( $nights ): float {
			return round( $qty * self::STALL_NIGHTLY * $nights, 2 );
		};
		$rv_subtotal = function ( int $qty ) use ( $nights ): float {
			return round( $qty * self::RV_NIGHTLY * $nights, 2 );
		};
		$fee = function ( float $sub ): float {
			return round( $sub * self::FEE_PCT, 2 );
		};

		$do_stall = function (
			string $order_num,
			string $name,
			string $email_prefix,
			string $phone,
			int    $qty,
			string $assigned,
			string $status = 'completed',
			string $txn    = ''
		) use ( $wpdb, $stall, $event, $arr, $dep, $stall_subtotal, $fee, &$n ): void {
			$sub   = $stall_subtotal( $qty );
			$cfee  = $fee( $sub );
			$total = $sub + $cfee;
			$notes = $assigned ? 'Assigned Stall Units: ' . $assigned : '';
			$wpdb->insert( $stall, array(
				'event_source'    => 'native',
				'event_id'        => $event,
				'customer_name'   => $name,
				'email'           => $email_prefix . self::DEMO_EMAIL_DOMAIN,
				'phone'           => $phone,
				'stall_qty'       => $qty,
				'stay_type'       => 'Full Stay',
				'arrival_date'    => $arr,
				'departure_date'  => $dep,
				'unit_price'      => number_format( self::STALL_NIGHTLY, 2, '.', '' ),
				'subtotal'        => number_format( $sub,   2, '.', '' ),
				'convenience_fee' => number_format( $cfee,  2, '.', '' ),
				'total'           => number_format( $total, 2, '.', '' ),
				'payment_status'  => $status,
				'payment_gateway' => 'stripe',
				'order_number'    => $order_num,
				'transaction_id'  => $txn ?: 'ch_demo' . $order_num,
				'notes'           => $notes,
			) );
			++$n;
		};

		$do_rv = function (
			string $order_num,
			string $name,
			string $email_prefix,
			string $phone,
			int    $qty,
			string $assigned,
			string $status = 'completed',
			string $txn    = ''
		) use ( $wpdb, $rv, $event, $arr, $dep, $rv_subtotal, $fee, &$n ): void {
			$sub   = $rv_subtotal( $qty );
			$cfee  = $fee( $sub );
			$total = $sub + $cfee;
			$notes = $assigned ? 'Assigned RV Lots: ' . $assigned : '';
			$wpdb->insert( $rv, array(
				'event_source'    => 'native',
				'event_id'        => $event,
				'customer_name'   => $name,
				'email'           => $email_prefix . self::DEMO_EMAIL_DOMAIN,
				'phone'           => $phone,
				'rv_qty'          => $qty,
				'rv_type'         => 'Standard',
				'stay_type'       => 'Full Stay',
				'arrival_date'    => $arr,
				'departure_date'  => $dep,
				'unit_price'      => number_format( self::RV_NIGHTLY, 2, '.', '' ),
				'subtotal'        => number_format( $sub,   2, '.', '' ),
				'convenience_fee' => number_format( $cfee,  2, '.', '' ),
				'total'           => number_format( $total, 2, '.', '' ),
				'payment_status'  => $status,
				'payment_gateway' => 'stripe',
				'order_number'    => $order_num,
				'transaction_id'  => $txn ?: 'ch_demo' . $order_num,
				'notes'           => $notes,
			) );
			++$n;
		};

		// ── #0001  John Smith — Red Barn 101/102/103 + Premium 1/2 ───── //
		$do_stall( '0001', 'John Smith',       'john.smith',       '555-0101', 3, '101, 102, 103' );
		$do_rv(    '0001', 'John Smith',       'john.smith',       '555-0101', 2, 'Premium Row 1, Premium Row 2' );

		// ── #0002  Jane Doe — Blue Barn 200/201 + Standard 11 ──────────  //
		$do_stall( '0002', 'Jane Doe',         'jane.doe',         '555-0102', 2, '200, 201' );
		$do_rv(    '0002', 'Jane Doe',         'jane.doe',         '555-0102', 1, 'Standard Row 11' );

		// ── #0003  Robert Johnson — Red Barn 108-111 ─────────────────── //
		$do_stall( '0003', 'Robert Johnson',   'robert.johnson',   '555-0103', 4, '108, 109, 110, 111' );

		// ── #0004  Emily Davis — Y Section Y1 ────────────────────────── //
		$do_stall( '0004', 'Emily Davis',      'emily.davis',      '555-0104', 1, 'Y1' );

		// ── #0005  Michael Brown — Red Barn 104/106 + Premium 3 ──────── //
		// (skips blocked 105)
		$do_stall( '0005', 'Michael Brown',    'michael.brown',    '555-0105', 2, '104, 106' );
		$do_rv(    '0005', 'Michael Brown',    'michael.brown',    '555-0105', 1, 'Premium Row 3' );

		// ── #0006  Sarah Wilson — Blue Barn 210/211/212 ───────────────── //
		$do_stall( '0006', 'Sarah Wilson',     'sarah.wilson',     '555-0106', 3, '210, 211, 212' );

		// ── #0007  David Martinez — Red Barn top 120-125 (max-per-customer limit) //
		$do_stall( '0007', 'David Martinez',   'david.martinez',   '555-0107', 6, '120, 121, 122, 123, 124, 125' );

		// ── #0008  Lisa Anderson — Y Section Y5/Y6 ───────────────────── //
		$do_stall( '0008', 'Lisa Anderson',    'lisa.anderson',    '555-0108', 2, 'Y5, Y6' );

		// ── #0009  James Taylor — Standard 15 (RV only) ──────────────── //
		$do_rv(    '0009', 'James Taylor',     'james.taylor',     '555-0109', 1, 'Standard Row 15' );

		// ── #0010  Karen Thomas — Blue Barn 215 + INTENTIONAL ISSUE ──── //
		// "Red Barn 199" is outside the configured range (100-131) →
		// the stall chart assignment-issues card should flag it.
		$do_stall( '0010', 'Karen Thomas',     'karen.thomas',     '555-0110', 2, '215, 199', 'completed', 'ch_demo_issue' );

		return $n;
	}

	// ------------------------------------------------------------------ //
	// Order counter                                                        //
	// ------------------------------------------------------------------ //

	/**
	 * Advance the order-number counter past the seeded range (0001-0010)
	 * so real future orders don't reuse those numbers.
	 *
	 * @return void
	 */
	private function advance_order_number_counter(): void {
		$current = absint( get_option( 'equine_event_manager_next_order_number', 0 ) );
		if ( $current < 11 ) {
			update_option( 'equine_event_manager_next_order_number', 11, false );
		}
	}

	// ------------------------------------------------------------------ //
	// Bonus second reservation (no chart enabled) for list variety         //
	// ------------------------------------------------------------------ //

	/**
	 * Create a second en_reservation post with stall_chart_enabled = 0,
	 * so the list page shows a "Not Configured" row alongside #43.
	 * Idempotent: skips if a post titled "2026 Gulf Coast Classic (Demo)" already exists.
	 *
	 * @return void
	 */
	private function maybe_seed_second_reservation(): void {
		$exists = get_posts( array(
			'post_type'   => 'en_reservation',
			'post_status' => 'any',
			'title'       => '2026 Gulf Coast Classic (Demo)',
			'fields'      => 'ids',
			'numberposts' => 1,
		) );
		if ( $exists ) {
			return;
		}

		$new_id = wp_insert_post( array(
			'post_type'   => 'en_reservation',
			'post_title'  => '2026 Gulf Coast Classic (Demo)',
			'post_status' => 'publish',
		) );

		if ( is_wp_error( $new_id ) ) {
			return;
		}

		// Leave stall_chart_enabled = 0 (default) → shows as "Not Configured".
		update_post_meta( $new_id, '_en_available_start_date', '2026-08-10' );
		update_post_meta( $new_id, '_en_available_end_date',   '2026-08-13' );
		update_post_meta( $new_id, '_en_stalls_enabled',       1 );
		update_post_meta( $new_id, '_en_stall_nightly_enabled', 1 );
		update_post_meta( $new_id, '_en_stall_nightly_rate',    '30.00' );

		WP_CLI::line( '  ✓ Bonus reservation "2026 Gulf Coast Classic (Demo)" created (post #' . $new_id . ').' );
	}
}

// ── Register WP-CLI command ───────────────────────────────────────────── //
WP_CLI::add_command( 'eem seed_demo', 'EEM_Seed_Demo_Command' );
