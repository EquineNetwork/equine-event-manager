<?php
/**
 * Unit Holds repository — short-lived "in someone's cart" holds for stall / RV
 * units on the customer event page (ROADMAP #36).
 *
 * When a customer picks a unit on the customer-facing map/picker, we write a
 * 15-minute hold keyed to their browser session token (customers don't log in).
 * Other shoppers then see that unit greyed as "Taken" until the hold lapses or
 * the holder checks out. This is a UX layer ONLY — hard double-booking is already
 * prevented at submit time by the per-reservation advisory lock in
 * EEM_Shortcodes (recompute-availability-under-lock-before-charge). Holds just
 * stop two shoppers from both filling out the form for the same unit.
 *
 * v1 simplification: one active hold per (reservation, section, unit) — a held
 * unit reads Taken to everyone else for the full window regardless of date
 * overlap. True date-aware holds (hold only the overlapping nights) are deferred;
 * for a 15-minute window where concurrent opening-day picks are almost always the
 * same dates, the simpler model is safe and self-healing. arrival/departure are
 * still stored for forward compatibility + debugging.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for the {prefix}eem_unit_holds table.
 */
class EEM_Unit_Holds_Repo {

	/** Table base name (sans prefix). */
	const TABLE = 'eem_unit_holds';

	/** Hold lifetime in minutes. */
	const HOLD_MINUTES = 15;

	/**
	 * WP-cron hook that runs the hourly expired-holds sweep (A8).
	 *
	 * Cleanup was previously opportunistic only (on form submit / hold attempt),
	 * so a quiet site could let lapsed rows accumulate. This scheduled sweep keeps
	 * the table bounded regardless of traffic. Queries already ignore expired rows,
	 * so this is purely housekeeping.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'eem_unit_holds_cleanup';

	/**
	 * Ensure the recurring cleanup event is scheduled. Idempotent — safe to call
	 * on every activation / version-change upgrade. Runs hourly.
	 *
	 * @return void
	 */
	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the recurring cleanup event. Called on plugin deactivation so we
	 * don't leave an orphaned cron entry behind.
	 *
	 * @return void
	 */
	public static function unschedule_cleanup(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		// Belt-and-suspenders: clear any duplicate occurrences too.
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create / upgrade the holds table (idempotent via dbDelta). Called from the
	 * activator's create-tables pass on activation + version-change upgrade.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reservation_id bigint(20) unsigned NOT NULL,
			section varchar(8) NOT NULL,
			unit_label varchar(64) NOT NULL,
			arrival date DEFAULT NULL,
			departure date DEFAULT NULL,
			session_token varchar(64) NOT NULL,
			held_until datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY res_section_unit (reservation_id, section, unit_label),
			KEY held_until (held_until),
			KEY session_token (session_token)
		) {$charset_collate};";
		dbDelta( $sql );
	}

	/**
	 * Normalize a section value to 'stall' | 'rv'.
	 *
	 * @param string $section Raw section.
	 * @return string
	 */
	private static function section( string $section ): string {
		return ( 'rv' === $section ) ? 'rv' : 'stall';
	}

	/**
	 * Try to claim (or refresh) a hold on a unit for a session.
	 *
	 * Succeeds when the unit is free or already held by THIS session (or the prior
	 * hold has lapsed) — in which case the hold is (re)written for a fresh window.
	 * Fails when another session holds it with an unexpired hold.
	 *
	 * Caller is still responsible for checking the unit isn't in a real order /
	 * admin-blocked before offering it; this only arbitrates between live shoppers.
	 *
	 * @param int    $reservation_id Reservation id.
	 * @param string $section        'stall' | 'rv'.
	 * @param string $unit_label     Unit label.
	 * @param string $session_token  Caller's browser session token.
	 * @param string $arrival        Requested arrival (Y-m-d) or ''.
	 * @param string $departure      Requested departure (Y-m-d) or ''.
	 * @return bool True if held by this session after the call; false if blocked by another session.
	 */
	public static function claim( int $reservation_id, string $section, string $unit_label, string $session_token, string $arrival = '', string $departure = '' ): bool {
		global $wpdb;
		$section = self::section( $section );
		$unit_label = trim( $unit_label );
		$session_token = trim( $session_token );
		if ( $reservation_id < 1 || '' === $unit_label || '' === $session_token ) {
			return false;
		}
		$table = self::table();
		$now   = current_time( 'mysql' );
		$until = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( self::HOLD_MINUTES * MINUTE_IN_SECONDS ) );

		// Existing hold for this unit?
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, session_token, held_until FROM {$table} WHERE reservation_id = %d AND section = %s AND unit_label = %s", $reservation_id, $section, $unit_label ),
			ARRAY_A
		);

		$fields = array(
			'reservation_id' => $reservation_id,
			'section'        => $section,
			'unit_label'     => $unit_label,
			'arrival'        => '' !== $arrival ? $arrival : null,
			'departure'      => '' !== $departure ? $departure : null,
			'session_token'  => $session_token,
			'held_until'     => $until,
			'created_at'     => $now,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $existing ) {
			$active = ( (string) $existing['held_until'] > $now );
			$mine   = ( (string) $existing['session_token'] === $session_token );
			if ( $active && ! $mine ) {
				return false; // Another shopper holds it.
			}
			// Mine, or lapsed → re-take it for a fresh window.
			$wpdb->update( $table, $fields, array( 'id' => (int) $existing['id'] ), $formats, array( '%d' ) );
			return true;
		}

		// No row yet — insert. A UNIQUE-key race (two inserts at once) makes the
		// loser's insert fail; treat that as "blocked" so the loser re-greys.
		$ok = $wpdb->insert( $table, $fields, $formats );
		return (bool) $ok;
	}

	/**
	 * Release a single unit's hold IF it belongs to this session (deselect).
	 *
	 * @param int    $reservation_id Reservation id.
	 * @param string $section        'stall' | 'rv'.
	 * @param string $unit_label     Unit label.
	 * @param string $session_token  Caller's session token.
	 * @return void
	 */
	public static function release( int $reservation_id, string $section, string $unit_label, string $session_token ): void {
		global $wpdb;
		if ( $reservation_id < 1 || '' === trim( $session_token ) ) {
			return;
		}
		$wpdb->delete(
			self::table(),
			array(
				'reservation_id' => $reservation_id,
				'section'        => self::section( $section ),
				'unit_label'     => trim( $unit_label ),
				'session_token'  => trim( $session_token ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Drop every hold owned by a session for a reservation (on successful checkout
	 * the units become real orders; on abandon they'd lapse anyway).
	 *
	 * @param int    $reservation_id Reservation id.
	 * @param string $session_token  Session token.
	 * @return void
	 */
	public static function release_session( int $reservation_id, string $session_token ): void {
		global $wpdb;
		if ( $reservation_id < 1 || '' === trim( $session_token ) ) {
			return;
		}
		$wpdb->delete(
			self::table(),
			array( 'reservation_id' => $reservation_id, 'session_token' => trim( $session_token ) ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Refresh the hold window for all of a session's holds on a reservation
	 * (heartbeat — keeps held units alive while the form is open).
	 *
	 * @param int    $reservation_id Reservation id.
	 * @param string $session_token  Session token.
	 * @return void
	 */
	public static function heartbeat( int $reservation_id, string $session_token ): void {
		global $wpdb;
		if ( $reservation_id < 1 || '' === trim( $session_token ) ) {
			return;
		}
		$table = self::table();
		$until = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( self::HOLD_MINUTES * MINUTE_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET held_until = %s WHERE reservation_id = %d AND session_token = %s", $until, $reservation_id, trim( $session_token ) ) );
	}

	/**
	 * Labels of units currently held (unexpired) for a reservation/section,
	 * EXCLUDING holds owned by the given session (so a shopper never sees their own
	 * picks greyed). This is the set the picker merges into its "Taken" display.
	 *
	 * @param int    $reservation_id  Reservation id.
	 * @param string $section         'stall' | 'rv'.
	 * @param string $exclude_session Session token to exclude (the viewer's own), or ''.
	 * @return string[] Unit labels.
	 */
	public static function held_units( int $reservation_id, string $section, string $exclude_session = '' ): array {
		global $wpdb;
		if ( $reservation_id < 1 ) {
			return array();
		}
		$table   = self::table();
		$now     = current_time( 'mysql' );
		$section = self::section( $section );
		if ( '' !== trim( $exclude_session ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT unit_label FROM {$table} WHERE reservation_id = %d AND section = %s AND held_until > %s AND session_token <> %s", $reservation_id, $section, $now, trim( $exclude_session ) ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT unit_label FROM {$table} WHERE reservation_id = %d AND section = %s AND held_until > %s", $reservation_id, $section, $now ) );
		}
		return array_values( array_unique( array_map( 'strval', (array) $rows ) ) );
	}

	/**
	 * Delete lapsed holds (lightweight housekeeping; queries already ignore
	 * expired rows, this just keeps the table from growing unbounded). Safe to
	 * call opportunistically.
	 *
	 * @return void
	 */
	public static function cleanup_expired(): void {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE held_until < %s", $now ) );
	}
}
