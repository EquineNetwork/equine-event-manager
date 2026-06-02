<?php
/**
 * Reservation source-event resolver (RES-ARCH-1 / C6.6).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical entry point for reading reservation title + date metadata.
 *
 * Per docs/decisions.md RES-ARCH-1 (added 2026-05-23, formalised in C6.6),
 * a reservation's user-visible event title and event dates are read-only
 * mirrors of the source event (Native CPT / TEC / External Feed). The
 * reservation's own `post_title` is a free-form admin label — not the
 * source of truth for display. Callers that surface the event name or
 * date range to users MUST go through this class.
 *
 * Implementation: this class is a thin façade over the existing
 * {@see EEM_Events::get_normalized_reservation_event_data()} method,
 * which already dispatches across all three event sources and returns
 * a normalised payload. The façade narrows the contract to the RES-ARCH-1
 * trio so the architectural intent is obvious at every call site.
 *
 * Caller contract:
 *   - Display reads ALWAYS use {@see self::get_title()}, {@see self::get_date_range_label()},
 *     or {@see self::resolve_event_fields()}.
 *   - Sort / SQL paths that need a meta_key to ORDER BY use the
 *     `_en_source_event_start_date` derived cache (written by
 *     {@see self::cache_source_event_start_date()} on every reservation save).
 *   - Direct reads of `_en_nightly_start_date` / `_en_nightly_end_date` /
 *     `_en_weekend_start_date` / `_en_weekend_end_date` are forbidden in
 *     new code — those keys are deprecated and being removed (see
 *     CLEANUP entry #22).
 *
 * Cache strategy (hybrid resolver + narrow cache):
 *   - Display reads: pure resolver, no cache. Resolution cost is bounded
 *     (Native/TEC: 2-3 cached post_meta reads via WP object cache; Feed:
 *     wrapped in transient by the underlying feed fetch).
 *   - Sort path: `_en_source_event_start_date` cache key, written on
 *     `save_post_en_reservation` by {@see self::cache_source_event_start_date()}.
 *     Single writer, single reader (the orderby SQL in
 *     {@see EEM_Reservations_List_Repo::get_paginated()}).
 *
 * Known limitation: the sort cache is reservation-side-written only. A
 * source-event change (e.g. a Native en_event's start_date is edited)
 * does NOT push to linked reservations' caches; the cache only refreshes
 * when the reservation itself is next saved. Acceptable for the typical
 * deployment shape where source events are set up once per season.
 * The other-direction sync handler is tracked as a follow-on cleanup
 * entry (CLEANUP #24) — must land before any production where source
 * events are edited frequently.
 *
 * @since 2.2.0 (introduced in C6.6)
 */
class EEM_Reservation_Source_Resolver {

	/**
	 * Sort-cache meta key — derived `start_date` written by
	 * {@see self::cache_source_event_start_date()}. Single writer, used
	 * by `orderby=event_dates` in {@see EEM_Reservations_List_Repo}.
	 */
	const SORT_CACHE_META_KEY = '_en_source_event_start_date';

	/**
	 * Resolve the RES-ARCH-1 trio (title, start_date, end_date) plus
	 * venue label for a reservation.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array{title:string,start_date:string,end_date:string,venue:string}
	 *         All values default to '' on resolution failure (e.g. reservation
	 *         not found, source event unreachable, feed fetch failed).
	 */
	public static function resolve_event_fields( int $reservation_id ): array {
		$id = absint( $reservation_id );
		if ( $id <= 0 ) {
			return self::empty_fields();
		}

		$payload = EEM_Events::get_normalized_reservation_event_data( $id );

		// 2.3.80 — Draft fallback. get_normalized_reservation_event_data() is
		// publish-gated (returns [] for any non-published reservation), but the
		// editor needs the linked-event title/dates for DRAFT reservations too —
		// otherwise a freshly-created draft never inherits its event name. Resolve
		// directly from the linked event (event-level data, no reservation-status
		// gate) when the reservation-level lookup comes back empty. This reads the
		// EVENT's public data, not draft reservation data, so it leaks nothing.
		if ( ( ! is_array( $payload ) || empty( $payload ) ) && function_exists( 'get_post_meta' ) ) {
			$source   = (string) get_post_meta( $id, '_en_event_source', true );
			$event_id = absint( get_post_meta( $id, '_en_event_id', true ) );

			if ( $event_id > 0 && in_array( $source, array( 'native', 'tec', '' ), true ) ) {
				$fallback = EEM_Events::get_normalized_event_data( $event_id );
				if ( is_array( $fallback ) && ! empty( $fallback ) ) {
					$payload = $fallback;
				}
			}
		}

		if ( ! is_array( $payload ) || empty( $payload ) ) {
			return self::empty_fields();
		}

		return array(
			'title'      => isset( $payload['title'] )      ? (string) $payload['title']      : '',
			'start_date' => isset( $payload['start_date'] ) ? (string) $payload['start_date'] : '',
			'end_date'   => isset( $payload['end_date'] )   ? (string) $payload['end_date']   : '',
			'venue'      => isset( $payload['venue_name'] ) ? (string) $payload['venue_name'] : '',
		);
	}

	/**
	 * Convenience accessor: source event title for display.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return string Source event title, or '' when resolution fails.
	 */
	public static function get_title( int $reservation_id ): string {
		$fields = self::resolve_event_fields( $reservation_id );
		return $fields['title'];
	}

	/**
	 * Convenience accessor: human-readable date range label
	 * ("May 8, 2026 – May 10, 2026") from the source event's start +
	 * end dates. Returns '' when either date is missing.
	 *
	 * Replaces the pre-C6.6 implementation that read the now-deprecated
	 * `_en_nightly_*` / `_en_weekend_*` meta keys from the reservation.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return string Formatted range, or '' on missing data.
	 */
	public static function get_date_range_label( int $reservation_id ): string {
		$fields = self::resolve_event_fields( $reservation_id );
		$start  = $fields['start_date'];
		$end    = $fields['end_date'];

		if ( '' === $start || '' === $end ) {
			return '';
		}

		$start_ts = strtotime( $start );
		$end_ts   = strtotime( $end );

		if ( ! $start_ts || ! $end_ts ) {
			return '';
		}

		$fmt = 'M j, Y';
		return sprintf( '%s – %s', date_i18n( $fmt, $start_ts ), date_i18n( $fmt, $end_ts ) );
	}

	/**
	 * Save-post hook: write the sort-cache key from the resolved
	 * source-event start_date.
	 *
	 * Registered as `save_post_en_reservation` priority 30 (after
	 * EEM_Reservations_CPT::save_meta at 10 + sync_shortcode at 20 so
	 * the linked-event meta keys those handlers may have just written
	 * are already in place when the resolver reads them).
	 *
	 * @param int     $post_id Reservation post ID.
	 * @param WP_Post $post    Reservation post object.
	 * @return void
	 */
	public static function cache_source_event_start_date( int $post_id, $post ): void {
		// Guard against revisions / autosaves; the resolver doesn't care
		// but writing meta on every revision would clutter post_meta.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'en_reservation' !== $post->post_type ) {
			return;
		}

		$fields = self::resolve_event_fields( $post_id );
		$start  = $fields['start_date'];

		if ( '' === $start ) {
			delete_post_meta( $post_id, self::SORT_CACHE_META_KEY );
			return;
		}

		update_post_meta( $post_id, self::SORT_CACHE_META_KEY, $start );
	}

	/**
	 * Default empty-fields shape for resolution failures.
	 *
	 * @return array{title:string,start_date:string,end_date:string,venue:string}
	 */
	private static function empty_fields(): array {
		return array(
			'title'      => '',
			'start_date' => '',
			'end_date'   => '',
			'venue'      => '',
		);
	}
}
