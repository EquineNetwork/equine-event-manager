<?php
/**
 * Events repository — pure-read data layer for normalized event data.
 *
 * Extracts all event data reads from EEM_Events into a single repo class.
 * Every public method is a pure read (no postmeta writes, no CPT registration,
 * no WP hook side-effects). The one tolerated side-effect is the feed-index
 * transient cache write, which is a cache, not a data mutation.
 *
 * The EEM_Events class (WordPress integration layer) delegates to this repo
 * for all data reads. The REST controller calls this repo directly.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only repository for normalized event data across all sources.
 *
 * Sources: native (en_event CPT), tec (tribe_events CPT), feed (GEMS/external
 * JSON/XML), external (reservation-backed with manual event details).
 */
class EEM_Events_Repo {

	/**
	 * Feed index transient TTL in seconds (15 minutes).
	 *
	 * @var int
	 */
	const FEED_CACHE_TTL = 900;

	// ──────────────────────────────────────────────────────────────
	// Public API
	// ──────────────────────────────────────────────────────────────

	/**
	 * Get normalized event data for a single event by post ID.
	 *
	 * Dispatches to the source-specific normalizer based on post_type.
	 * Works for native (en_event) and TEC (tribe_events) events.
	 * For feed/external events accessed via a reservation, use get_for_reservation().
	 *
	 * @param int $event_id Event post ID.
	 * @return array<string, mixed> Normalized event data, or empty array if not found.
	 */
	public static function get( int $event_id ): array {
		$event_id = absint( $event_id );

		if ( ! $event_id ) {
			return array();
		}

		$post = get_post( $event_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return array();
		}

		if ( EEM_Events::EVENT_POST_TYPE === $post->post_type ) {
			return self::normalize_native_event( $event_id, $post );
		}

		if ( 'tribe_events' === $post->post_type ) {
			return self::normalize_tec_event( $event_id, $post );
		}

		return array();
	}

	/**
	 * Get normalized event data from a reservation's linked event source.
	 *
	 * Handles all four sources: native and TEC delegate to get(), feed and
	 * external build the normalized shape from reservation postmeta + feed data.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array<string, mixed> Normalized event data, or empty array.
	 */
	public static function get_for_reservation( int $reservation_id ): array {
		$reservation_id = absint( $reservation_id );

		if ( ! $reservation_id || 'en_reservation' !== get_post_type( $reservation_id ) ) {
			return array();
		}

		$reservation = get_post( $reservation_id );

		if ( ! $reservation || 'publish' !== $reservation->post_status ) {
			return array();
		}

		$event_source = self::get_effective_source( $reservation_id );

		if ( in_array( $event_source, array( 'native', 'tec' ), true ) ) {
			$event_id = absint( get_post_meta( $reservation_id, '_en_event_id', true ) );

			return $event_id ? self::get( $event_id ) : array();
		}

		return self::normalize_reservation_event( $reservation_id, $reservation, $event_source );
	}

	/**
	 * Get a paginated, filtered, sorted collection of events across all sources.
	 *
	 * Aggregates events from whichever sources are enabled, deduplicates,
	 * filters by timeframe/venue/producer/search, sorts by date, then
	 * paginates. Returns the same envelope shape as EEM_Reservations_List_Repo.
	 *
	 * @param array $args {
	 *     @type int    $page           Page number (default 1).
	 *     @type int    $per_page       Items per page (default 20).
	 *     @type string $source         Source filter: 'all', 'native', 'tec', 'feed', 'external', or comma-separated (default 'all').
	 *     @type string $timeframe      'current_upcoming', 'past', 'ongoing', 'all' (default 'current_upcoming').
	 *     @type string $search         Search term matched against title, venue, location (default '').
	 *     @type string $venue_name     Filter by venue name slug (default '').
	 *     @type string $producer_name  Filter by producer name slug (default '').
	 *     @type string $category       Category term ID or slug (default '').
	 *     @type int    $venue          Venue post ID filter (default 0).
	 *     @type int    $producer       Producer post ID filter (default 0).
	 *     @type bool   $featured       Featured-only filter (default false).
	 *     @type string $orderby        Sort field: 'date', 'title' (default 'date').
	 *     @type string $order          Sort direction: 'asc', 'desc' (default 'asc').
	 * }
	 * @return array{items: array[], total: int, total_pages: int, page: int, per_page: int}
	 */
	public static function get_collection( array $args = array() ): array {
		$defaults = array(
			'page'          => 1,
			'per_page'      => 20,
			'source'        => 'all',
			'timeframe'     => 'current_upcoming',
			'search'        => '',
			'venue_name'    => '',
			'producer_name' => '',
			'category'      => '',
			'venue'         => 0,
			'producer'      => 0,
			'featured'      => false,
			'orderby'       => 'date',
			'order'         => 'asc',
		);

		$args     = wp_parse_args( $args, $defaults );
		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );

		$shortcode_atts = array(
			'limit'         => 0,
			'source'        => $args['source'],
			'timeframe'     => $args['timeframe'],
			'category'      => $args['category'],
			'venue'         => $args['venue'],
			'producer'      => $args['producer'],
			'featured'      => $args['featured'],
			'venue_name'    => $args['venue_name'],
			'producer_name' => $args['producer_name'],
		);

		$events = self::collect_events( $shortcode_atts );

		$search = sanitize_text_field( (string) $args['search'] );
		if ( '' !== $search ) {
			$search_lower = mb_strtolower( $search );
			$events       = array_values(
				array_filter(
					$events,
					static function ( $event ) use ( $search_lower ) {
						$haystack = mb_strtolower(
							implode(
								' ',
								array(
									isset( $event['title'] ) ? (string) $event['title'] : '',
									isset( $event['venue_name'] ) ? (string) $event['venue_name'] : '',
									isset( $event['location'] ) ? (string) $event['location'] : '',
									! empty( $event['producer']['name'] ) ? (string) $event['producer']['name'] : '',
								)
							)
						);

						return false !== mb_strpos( $haystack, $search_lower );
					}
				)
			);
		}

		if ( 'title' === $args['orderby'] ) {
			usort(
				$events,
				static function ( $a, $b ) use ( $args ) {
					$cmp = strcasecmp( (string) $a['title'], (string) $b['title'] );

					return 'desc' === $args['order'] ? -$cmp : $cmp;
				}
			);
		}

		$total       = count( $events );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$offset      = ( $page - 1 ) * $per_page;
		$items       = array_slice( $events, $offset, $per_page );

		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $total_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Get a paginated event listing with the minimal shape needed for event cards.
	 *
	 * Returns only the fields the public events list needs: title, dates,
	 * location, status pill, flyer URL, and draw sheet / results flags.
	 * Much lighter than get_collection() which returns the full normalized shape.
	 *
	 * @param array $args Same args as get_collection().
	 * @return array{items: array[], total: int, total_pages: int, page: int, per_page: int}
	 */
	public static function get_for_event_listing( array $args = array() ): array {
		$result = self::get_collection( $args );
		$today  = wp_date( 'Y-m-d', current_time( 'timestamp' ) );

		$result['items'] = array_map(
			static function ( $event ) use ( $today ) {
				$start    = isset( $event['start_date'] ) ? (string) $event['start_date'] : '';
				$end      = isset( $event['end_date'] ) ? (string) $event['end_date'] : $start;
				$event_id = isset( $event['event_id'] ) ? (int) $event['event_id'] : 0;

				$status = 'upcoming';
				if ( $start && $end ) {
					if ( $end < $today ) {
						$status = 'past';
					} elseif ( $start <= $today && $end >= $today ) {
						$status = 'ongoing';
					}
				}

				$has_draw_sheets = false;
				$has_results     = false;
				if ( $event_id > 0 && class_exists( 'EEM_Sheet_Entries' ) ) {
					$has_draw_sheets = EEM_Sheet_Entries::has_drawsheets( $event_id );
					$has_results     = EEM_Sheet_Entries::has_results( $event_id );
				}

				return array(
					'event_id'        => $event_id,
					'source'          => isset( $event['source'] ) ? (string) $event['source'] : '',
					'title'           => isset( $event['title'] ) ? (string) $event['title'] : '',
					'start_date'      => $start,
					'end_date'        => $end,
					'location'        => self::get_city_state_label( $event ),
					'flyer_url'       => isset( $event['flyer_url'] ) ? (string) $event['flyer_url'] : '',
					'status'          => $status,
					'has_draw_sheets' => $has_draw_sheets,
					'has_results'     => $has_results,
					'reservation_id'  => isset( $event['reservation_id'] ) ? (int) $event['reservation_id'] : 0,
					'cta_label'       => isset( $event['cta_label'] ) ? (string) $event['cta_label'] : '',
				);
			},
			$result['items']
		);

		return $result;
	}

	/**
	 * Search feed events by term.
	 *
	 * Delegates to GEMS when configured, otherwise searches the cached feed index.
	 *
	 * @param string $term     Search term.
	 * @param string $feed_url Optional feed URL override.
	 * @param int    $limit    Maximum results (default 20).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function search_feed( string $term = '', string $feed_url = '', int $limit = 20 ) {
		if ( class_exists( 'EEM_Gems_Client' ) && EEM_Gems_Client::is_configured() ) {
			return EEM_Gems_Client::search( $term, $limit, true );
		}

		$feed_index = self::get_feed_event_index( $feed_url );

		if ( is_wp_error( $feed_index ) ) {
			return $feed_index;
		}

		$term    = sanitize_text_field( (string) $term );
		$results = array();

		if ( empty( $feed_index['events'] ) ) {
			return $results;
		}

		foreach ( $feed_index['events'] as $event_data ) {
			$title = isset( $event_data['title'] ) ? (string) $event_data['title'] : '';

			if ( '' !== $term && false === stripos( $title, $term ) ) {
				$venue = isset( $event_data['venue_name'] ) ? (string) $event_data['venue_name'] : '';
				$loc   = isset( $event_data['location'] ) ? (string) $event_data['location'] : '';

				if ( false === stripos( $venue, $term ) && false === stripos( $loc, $term ) ) {
					continue;
				}
			}

			$results[] = $event_data;

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Test a feed URL and return a summary of the result.
	 *
	 * @param string $feed_url      Feed URL to test.
	 * @param bool   $force_refresh Bypass transient cache.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function test_feed( string $feed_url = '', bool $force_refresh = false ) {
		$feed_index = self::get_feed_event_index( $feed_url, $force_refresh );

		if ( is_wp_error( $feed_index ) ) {
			return $feed_index;
		}

		$sample = array();

		if ( ! empty( $feed_index['events'][0] ) ) {
			$sample_event = $feed_index['events'][0];
			$sample       = array(
				'title'      => isset( $sample_event['title'] ) ? (string) $sample_event['title'] : '',
				'start_date' => isset( $sample_event['start_date'] ) ? (string) $sample_event['start_date'] : '',
				'end_date'   => isset( $sample_event['end_date'] ) ? (string) $sample_event['end_date'] : '',
				'venue_name' => isset( $sample_event['venue_name'] ) ? (string) $sample_event['venue_name'] : '',
				'location'   => isset( $sample_event['location'] ) ? (string) $sample_event['location'] : '',
				'producer'   => ! empty( $sample_event['producer']['name'] ) ? (string) $sample_event['producer']['name'] : '',
			);
		}

		return array(
			'feed_url' => $feed_index['feed_url'],
			'format'   => $feed_index['format'],
			'count'    => count( $feed_index['events'] ),
			'sample'   => $sample,
		);
	}

	// ──────────────────────────────────────────────────────────────
	// Source detection (ported exactly from EEM_Events)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Get the default event source based on settings and active capabilities.
	 *
	 * @return string One of 'native', 'tec', 'feed', 'external'.
	 */
	public static function get_default_source(): string {
		return EEM_Events::get_default_event_source();
	}

	/**
	 * Resolve the effective event source for a reservation.
	 *
	 * Reads _en_event_source postmeta with the full fallback cascade.
	 * Ported as-is — no behavior change.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return string One of 'native', 'tec', 'feed', 'external'.
	 */
	public static function get_effective_source( int $reservation_id ): string {
		return EEM_Events::get_effective_reservation_event_source( $reservation_id );
	}

	/**
	 * Get a human-readable label for an event source key.
	 *
	 * @param string $source Source key.
	 * @return string
	 */
	public static function get_source_label( string $source ): string {
		return EEM_Events::get_event_source_label( $source );
	}

	// ──────────────────────────────────────────────────────────────
	// URL helpers
	// ──────────────────────────────────────────────────────────────

	/**
	 * Get the public customer-facing URL for a reservation's event page.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return string Absolute URL, or '' when invalid.
	 */
	public static function get_reservation_public_url( int $reservation_id ): string {
		return EEM_Events::get_reservation_public_url( $reservation_id );
	}

	// ──────────────────────────────────────────────────────────────
	// Date / label helpers (public for REST shaping)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Format a start/end date pair into a readable range label.
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 * @return string
	 */
	public static function format_date_range_label( string $start_date, string $end_date = '' ): string {
		if ( ! $start_date ) {
			return '';
		}

		if ( ! $end_date || $start_date === $end_date ) {
			return (string) wp_date( get_option( 'date_format' ), strtotime( $start_date ) );
		}

		return sprintf(
			/* translators: 1: start date, 2: end date. */
			__( '%1$s - %2$s', 'equine-event-manager' ),
			wp_date( get_option( 'date_format' ), strtotime( $start_date ) ),
			wp_date( get_option( 'date_format' ), strtotime( $end_date ) )
		);
	}

	/**
	 * Get a readable "X days" / "Today" / "In progress" label.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return string
	 */
	public static function get_days_until_label( string $start_date, string $end_date = '' ): string {
		$start_date = self::normalize_date( $start_date );
		$end_date   = self::normalize_date( $end_date );

		if ( '' === $start_date ) {
			return '';
		}

		$today_date    = wp_date( 'Y-m-d', current_time( 'timestamp' ) );
		$today         = strtotime( $today_date . ' 00:00:00' );
		$event_day     = strtotime( $start_date . ' 00:00:00' );
		$event_end_day = strtotime( ( $end_date ? $end_date : $start_date ) . ' 00:00:00' );

		if ( ! $today || ! $event_day || ! $event_end_day ) {
			return '';
		}

		$days_until = (int) floor( ( $event_day - $today ) / DAY_IN_SECONDS );

		if ( $days_until < 0 && $event_end_day >= $today ) {
			return __( 'In progress', 'equine-event-manager' );
		}

		if ( 0 === $days_until ) {
			return __( 'Today', 'equine-event-manager' );
		}

		if ( 1 === $days_until ) {
			return __( '1 day', 'equine-event-manager' );
		}

		if ( $days_until > 1 ) {
			return sprintf(
				/* translators: %d: number of days until the event starts. */
				__( '%d days', 'equine-event-manager' ),
				$days_until
			);
		}

		return '';
	}

	/**
	 * Get the best city/state label for an event.
	 *
	 * @param array<string, mixed> $event_data Normalized event data.
	 * @return string
	 */
	public static function get_city_state_label( array $event_data ): string {
		$location = trim( isset( $event_data['location'] ) ? (string) $event_data['location'] : '' );

		if ( '' !== $location ) {
			return $location;
		}

		if ( ! empty( $event_data['venue']['address_display'] ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $event_data['venue']['address_display'] );
			$last  = is_array( $lines ) ? trim( (string) end( $lines ) ) : '';

			if ( '' !== $last ) {
				return $last;
			}
		}

		return '';
	}

	// ──────────────────────────────────────────────────────────────
	// Feed index (transient-cached HTTP fetch)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Fetch and cache the normalized feed event index.
	 *
	 * Cache key includes the feed URL hash so different feed URLs (or tenants)
	 * never share a transient.
	 *
	 * @param string $feed_url      Feed URL (defaults to saved setting).
	 * @param bool   $force_refresh Bypass cache.
	 * @return array{feed_url: string, format: string, events: array[]}|WP_Error
	 */
	public static function get_feed_event_index( string $feed_url = '', bool $force_refresh = false ) {
		return EEM_Events::get_feed_event_index( $feed_url, $force_refresh );
	}

	/**
	 * Look up a single feed event by its external ID.
	 *
	 * @param string $external_event_id External event identifier.
	 * @param string $feed_url          Optional feed URL override.
	 * @return array<string, mixed>
	 */
	public static function get_feed_event_by_external_id( string $external_event_id, string $feed_url = '' ): array {
		$result = EEM_Events::get_feed_event_by_external_id( $external_event_id, $feed_url );

		return is_array( $result ) ? $result : array();
	}

	// ──────────────────────────────────────────────────────────────
	// Internal — normalizers
	// ──────────────────────────────────────────────────────────────

	/**
	 * Normalize a native event (en_event CPT) into the shared shape.
	 *
	 * @param int     $event_id Event post ID.
	 * @param WP_Post $post     Event post object.
	 * @return array<string, mixed>
	 */
	private static function normalize_native_event( int $event_id, WP_Post $post ): array {
		$evt            = EEM_Native_Event_Repo::get( $event_id );
		$reservation_id = absint( get_post_meta( $event_id, '_equine_event_manager_reservation_id', true ) );
		$flyer_file_id  = $evt['flyer_file_id'];
		$flyer_file_url = $evt['flyer_url'];
		$event_dates    = self::get_native_event_dates( $event_id );
		$event_details  = self::get_native_event_card_details( $event_id );
		$categories     = wp_get_post_terms( $event_id, EEM_Events::EVENT_CATEGORY_TAXONOMY, array( 'fields' => 'names' ) );
		$tags           = wp_get_post_terms( $event_id, EEM_Events::EVENT_TAG_TAXONOMY, array( 'fields' => 'names' ) );

		return array(
			'event_id'       => $event_id,
			'source'         => 'native',
			'title'          => get_the_title( $event_id ),
			'content_raw'    => (string) $post->post_content,
			'excerpt'        => has_excerpt( $event_id ) ? get_the_excerpt( $event_id ) : '',
			'start_date'     => $event_dates['start_date'],
			'end_date'       => $event_dates['end_date'],
			'venue_name'     => $event_details['venue_name'],
			'location'       => $event_details['location'],
			'venue'          => self::get_venue_details( $evt['venue_id'] ),
			'producer'       => self::get_producer_details( $evt['producer_id'] ),
			'featured'       => (bool) $evt['featured'],
			'featured_image' => get_the_post_thumbnail_url( $event_id, 'large' ),
			'hero_image'     => self::resolve_hero_image_url( $flyer_file_id, (string) get_the_post_thumbnail_url( $event_id, 'large' ) ),
			'flyer_url'      => $flyer_file_url ? $flyer_file_url : ( $flyer_file_id ? wp_get_attachment_url( $flyer_file_id ) : '' ),
			'reservation_id' => $reservation_id,
			'cta_label'      => $evt['cta_label'],
			'social'         => array(
				'facebook'  => $evt['facebook'],
				'instagram' => $evt['instagram'],
			),
			'categories'     => is_wp_error( $categories ) ? array() : array_values( array_filter( array_map( 'strval', $categories ) ) ),
			'tags'           => is_wp_error( $tags ) ? array() : array_values( array_filter( array_map( 'strval', $tags ) ) ),
		);
	}

	/**
	 * Normalize a TEC event (tribe_events) into the shared shape.
	 *
	 * @param int     $event_id Event post ID.
	 * @param WP_Post $post     Event post object.
	 * @return array<string, mixed>
	 */
	private static function normalize_tec_event( int $event_id, WP_Post $post ): array {
		$flyer_file_id    = absint( get_post_meta( $event_id, '_equine_event_manager_event_flyer_file_id', true ) );
		$flyer_file_url   = (string) get_post_meta( $event_id, '_equine_event_manager_event_flyer_url', true );
		$event_dates      = self::get_tec_event_dates( $event_id );
		$event_details    = self::get_tec_event_card_details( $event_id );
		$venue_details    = self::get_tec_venue_details( $event_id );
		$producer_details = self::get_tec_producer_details( $event_id );
		$categories       = wp_get_post_terms( $event_id, 'tribe_events_cat', array( 'fields' => 'names' ) );
		$tags             = wp_get_post_terms( $event_id, 'post_tag', array( 'fields' => 'names' ) );

		return array(
			'event_id'       => $event_id,
			'source'         => 'tec',
			'title'          => get_the_title( $event_id ),
			'content_raw'    => (string) $post->post_content,
			'excerpt'        => has_excerpt( $event_id ) ? get_the_excerpt( $event_id ) : '',
			'start_date'     => $event_dates['start_date'],
			'end_date'       => $event_dates['end_date'],
			'venue_name'     => $event_details['venue_name'],
			'location'       => $event_details['location'],
			'venue'          => $venue_details,
			'producer'       => $producer_details,
			'featured'       => (bool) get_post_meta( $event_id, '_equine_event_manager_event_featured', true ),
			'featured_image' => get_the_post_thumbnail_url( $event_id, 'large' ),
			'hero_image'     => self::resolve_hero_image_url( $flyer_file_id, (string) get_the_post_thumbnail_url( $event_id, 'large' ) ),
			'flyer_url'      => $flyer_file_url ? $flyer_file_url : ( $flyer_file_id ? wp_get_attachment_url( $flyer_file_id ) : '' ),
			'reservation_id' => self::get_linked_reservation_id( $event_id ),
			'cta_label'      => '',
			'categories'     => is_wp_error( $categories ) ? array() : array_values( array_filter( array_map( 'strval', $categories ) ) ),
			'tags'           => is_wp_error( $tags ) ? array() : array_values( array_filter( array_map( 'strval', $tags ) ) ),
		);
	}

	/**
	 * Build normalized event data from a reservation-backed source (feed/external).
	 *
	 * @param int     $reservation_id Reservation post ID.
	 * @param WP_Post $reservation    Reservation post object.
	 * @param string  $event_source   Resolved source key.
	 * @return array<string, mixed>
	 */
	private static function normalize_reservation_event( int $reservation_id, WP_Post $reservation, string $event_source ): array {
		$feed_url    = (string) get_post_meta( $reservation_id, '_en_event_feed_url', true );
		$external_id = (string) get_post_meta( $reservation_id, '_en_external_event_id', true );
		$feed_event  = 'feed' === $event_source ? self::get_feed_event_by_external_id( $external_id, $feed_url ) : array();
		$start_date  = self::normalize_date( (string) get_post_meta( $reservation_id, '_en_available_start_date', true ) );
		$end_date    = self::normalize_date( (string) get_post_meta( $reservation_id, '_en_available_end_date', true ) );
		$title       = (string) get_post_meta( $reservation_id, '_en_external_event_name', true );
		$location    = (string) get_post_meta( $reservation_id, '_en_event_location', true );
		$venue_name  = (string) get_post_meta( $reservation_id, '_en_venue_name', true );
		$address     = trim( (string) get_post_meta( $reservation_id, '_en_venue_address', true ) );
		$summary     = trim( (string) get_post_meta( $reservation_id, '_en_event_details_summary', true ) );
		$map_url     = '';
		$map_image   = absint( get_post_meta( $reservation_id, '_en_venue_map_image_id', true ) );

		if ( ! empty( get_post_meta( $reservation_id, '_en_venue_map_enabled', true ) ) ) {
			$map_url = (string) get_post_meta( $reservation_id, '_en_venue_map_download_url', true );

			if ( '' === $map_url && $map_image ) {
				$map_url = (string) wp_get_attachment_image_url( $map_image, 'large' );
			}
		}

		$feed_dates_authoritative = ( 'feed' === $event_source );

		if ( ( $feed_dates_authoritative || empty( $start_date ) ) && ! empty( $feed_event['start_date'] ) ) {
			$start_date = (string) $feed_event['start_date'];
		}

		if ( ( $feed_dates_authoritative || empty( $end_date ) ) && ! empty( $feed_event['end_date'] ) ) {
			$end_date = (string) $feed_event['end_date'];
		}

		if ( '' === $title && ! empty( $feed_event['title'] ) ) {
			$title = (string) $feed_event['title'];
		}

		if ( '' === $location && ! empty( $feed_event['location'] ) ) {
			$location = (string) $feed_event['location'];
		}

		if ( '' === $venue_name && ! empty( $feed_event['venue_name'] ) ) {
			$venue_name = (string) $feed_event['venue_name'];
		}

		if ( '' === $summary && ! empty( $feed_event['content_raw'] ) ) {
			$summary = trim( (string) $feed_event['content_raw'] );
		}

		if ( '' === $title ) {
			$title = $reservation ? $reservation->post_title : __( 'Event Reservations', 'equine-event-manager' );
		}

		return array(
			'event_id'          => 0,
			'source'            => $event_source ? $event_source : 'external',
			'external_event_id' => $external_id,
			'title'             => $title,
			'content_raw'       => $summary,
			'excerpt'           => '',
			'start_date'        => $start_date,
			'end_date'          => $end_date ? $end_date : $start_date,
			'venue_name'        => $venue_name,
			'location'          => $location,
			'venue'             => array(
				'address_display' => $address,
				'map_query'       => trim( implode( ', ', array_filter( array( $venue_name, str_replace( array( "\r\n", "\r", "\n" ), ', ', $address ), $location ) ) ) ),
				'filter_url'      => '',
			),
			'producer'          => array(
				'name'       => '',
				'email'      => '',
				'phone'      => '',
				'website'    => '',
				'filter_url' => '',
			),
			'featured_image'    => ! empty( $feed_event['featured_image'] ) ? (string) $feed_event['featured_image'] : '',
			'hero_image'        => ! empty( $feed_event['featured_image'] ) ? (string) $feed_event['featured_image'] : '',
			'flyer_url'         => ! empty( $feed_event['flyer_url'] ) ? (string) $feed_event['flyer_url'] : '',
			'external_url'      => ! empty( $feed_event['external_url'] ) ? (string) $feed_event['external_url'] : '',
			'reservation_id'    => $reservation_id,
			'cta_label'         => __( 'Reserve Now', 'equine-event-manager' ),
			'categories'        => array(),
			'tags'              => array(),
			'map_url'           => $map_url,
			'event_feed_url'    => $feed_url,
		);
	}

	// ──────────────────────────────────────────────────────────────
	// Internal — collection helpers
	// ──────────────────────────────────────────────────────────────

	/**
	 * Collect and process events from all enabled sources.
	 *
	 * Mirrors the logic of EEM_Events::get_normalized_event_collection() exactly.
	 *
	 * @param array $atts Shortcode-style attributes.
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_events( array $atts ): array {
		$limit      = isset( $atts['limit'] ) ? (int) $atts['limit'] : 20;
		$limit      = $limit < 0 ? 0 : $limit;
		$pull_count = max( 24, $limit > 0 ? $limit * 3 : 500 );
		$sources    = self::parse_source_filter( isset( $atts['source'] ) ? $atts['source'] : 'all' );
		$timeframe  = self::parse_timeframe_filter( isset( $atts['timeframe'] ) ? $atts['timeframe'] : 'current_upcoming' );
		$events     = array();

		if ( in_array( 'native', $sources, true ) && EEM_Events::is_native_events_enabled() ) {
			foreach ( self::query_native_event_ids( $atts, $pull_count ) as $event_id ) {
				$event_data = self::get( $event_id );

				if ( ! empty( $event_data ) ) {
					$events[] = $event_data;
				}
			}
		}

		if ( in_array( 'tec', $sources, true ) && EEM_Events::is_tec_integration_enabled() ) {
			foreach ( self::query_tec_event_ids( $atts, $pull_count ) as $event_id ) {
				$event_data = self::get( $event_id );

				if ( ! empty( $event_data ) ) {
					$events[] = $event_data;
				}
			}
		}

		if ( in_array( 'feed', $sources, true ) ) {
			$feed_index = self::get_feed_event_index();

			if ( ! is_wp_error( $feed_index ) && ! empty( $feed_index['events'] ) ) {
				foreach ( array_slice( $feed_index['events'], 0, $pull_count ) as $event_data ) {
					$events[] = $event_data;
				}
			}
		}

		$reservation_sources = array_values( array_intersect( $sources, array( 'feed', 'external' ) ) );

		if ( ! empty( $reservation_sources ) ) {
			foreach ( self::query_reservation_event_ids( $reservation_sources, $pull_count ) as $reservation_id ) {
				$event_data = self::get_for_reservation( $reservation_id );

				if ( ! empty( $event_data ) ) {
					$events[] = $event_data;
				}
			}
		}

		$events = self::deduplicate( $events );
		$events = self::filter_by_timeframe( $events, $timeframe );

		usort(
			$events,
			static function ( $left, $right ) use ( $timeframe ) {
				$left_date  = self::get_sort_date( $left );
				$right_date = self::get_sort_date( $right );

				if ( $left_date === $right_date ) {
					return strcasecmp( (string) $left['title'], (string) $right['title'] );
				}

				if ( 'past' === $timeframe ) {
					return strcmp( $right_date, $left_date );
				}

				return strcmp( $left_date, $right_date );
			}
		);

		$venue_name_filter    = sanitize_title( isset( $atts['venue_name'] ) ? (string) $atts['venue_name'] : '' );
		$producer_name_filter = sanitize_title( isset( $atts['producer_name'] ) ? (string) $atts['producer_name'] : '' );

		if ( $venue_name_filter ) {
			$events = array_values(
				array_filter(
					$events,
					static function ( $event ) use ( $venue_name_filter ) {
						return ! empty( $event['venue_name'] ) && sanitize_title( (string) $event['venue_name'] ) === $venue_name_filter;
					}
				)
			);
		}

		if ( $producer_name_filter ) {
			$events = array_values(
				array_filter(
					$events,
					static function ( $event ) use ( $producer_name_filter ) {
						return ! empty( $event['producer']['name'] ) && sanitize_title( (string) $event['producer']['name'] ) === $producer_name_filter;
					}
				)
			);
		}

		return $limit > 0 ? array_slice( $events, 0, $limit ) : $events;
	}

	// ──────────────────────────────────────────────────────────────
	// Internal — source-specific ID queries
	// ──────────────────────────────────────────────────────────────

	/**
	 * Query native event IDs for collection.
	 *
	 * @param array $atts  Filter attributes.
	 * @param int   $limit Query limit.
	 * @return array<int, int>
	 */
	private static function query_native_event_ids( array $atts, int $limit ): array {
		if ( EEM_Native_Event_Repo::table_exists() ) {
			return self::query_native_event_ids_sql( $atts, $limit );
		}

		$query_args = array(
			'post_type'      => EEM_Events::EVENT_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => absint( $limit ),
			'fields'         => 'ids',
			'meta_key'       => '_equine_event_manager_event_start_date',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$meta_query = array();

		if ( ! empty( $atts['featured'] ) ) {
			$meta_query[] = array(
				'key'   => '_equine_event_manager_event_featured',
				'value' => '1',
			);
		}

		if ( ! empty( $atts['venue'] ) ) {
			$meta_query[] = array(
				'key'   => '_equine_event_manager_event_venue_id',
				'value' => absint( $atts['venue'] ),
			);
		}

		if ( ! empty( $atts['producer'] ) ) {
			$meta_query[] = array(
				'key'   => '_equine_event_manager_event_producer_id',
				'value' => absint( $atts['producer'] ),
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		if ( ! empty( $atts['category'] ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => EEM_Events::EVENT_CATEGORY_TAXONOMY,
					'field'    => is_numeric( $atts['category'] ) ? 'term_id' : 'slug',
					'terms'    => is_numeric( $atts['category'] ) ? absint( $atts['category'] ) : sanitize_title( $atts['category'] ),
				),
			);
		}

		return array_map( 'absint', get_posts( $query_args ) );
	}

	/**
	 * SQL-based native events query using the relational table.
	 *
	 * @param array $atts  Filter attributes.
	 * @param int   $limit Query limit.
	 * @return array<int, int>
	 */
	private static function query_native_event_ids_sql( array $atts, int $limit ): array {
		global $wpdb;
		$ne = EEM_Native_Event_Repo::table_name();

		$where = array( "p.post_type = '" . esc_sql( EEM_Events::EVENT_POST_TYPE ) . "'", "p.post_status = 'publish'" );
		$join  = "LEFT JOIN {$ne} ne ON ne.event_id = p.ID";

		if ( ! empty( $atts['featured'] ) ) {
			$where[] = 'ne.featured = 1';
		}
		if ( ! empty( $atts['venue'] ) ) {
			$where[] = $wpdb->prepare( 'ne.venue_id = %d', absint( $atts['venue'] ) );
		}
		if ( ! empty( $atts['producer'] ) ) {
			$where[] = $wpdb->prepare( 'ne.producer_id = %d', absint( $atts['producer'] ) );
		}
		if ( ! empty( $atts['category'] ) ) {
			$tax   = esc_sql( EEM_Events::EVENT_CATEGORY_TAXONOMY );
			$field = is_numeric( $atts['category'] ) ? 'tt.term_id' : 't.slug';
			$val   = is_numeric( $atts['category'] ) ? absint( $atts['category'] ) : sanitize_title( $atts['category'] );
			$join .= " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID";
			$join .= " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = '{$tax}'";
			if ( ! is_numeric( $atts['category'] ) ) {
				$join .= " INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id";
			}
			$where[] = $wpdb->prepare( "{$field} = %s", $val );
		}

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB
		return array_map( 'absint', $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p {$join} WHERE {$where_sql} ORDER BY ne.start_date ASC LIMIT " . absint( $limit )
		) );
	}

	/**
	 * Query TEC event IDs for collection.
	 *
	 * @param array $atts  Filter attributes.
	 * @param int   $limit Query limit.
	 * @return array<int, int>
	 */
	private static function query_tec_event_ids( array $atts, int $limit ): array {
		$query_args = array(
			'post_type'      => 'tribe_events',
			'post_status'    => 'publish',
			'posts_per_page' => absint( $limit ),
			'fields'         => 'ids',
			'meta_key'       => '_EventStartDate',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		if ( ! empty( $atts['category'] ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'tribe_events_cat',
					'field'    => is_numeric( $atts['category'] ) ? 'term_id' : 'slug',
					'terms'    => is_numeric( $atts['category'] ) ? absint( $atts['category'] ) : sanitize_title( $atts['category'] ),
				),
			);
		}

		return array_map( 'absint', get_posts( $query_args ) );
	}

	/**
	 * Query reservation IDs for reservation-backed event sources.
	 *
	 * @param array<int, string> $sources Allowed source keys.
	 * @param int                $limit   Query limit.
	 * @return array<int, int>
	 */
	private static function query_reservation_event_ids( array $sources, int $limit ): array {
		$meta_query = array(
			'relation' => 'OR',
			array(
				'key'     => '_en_event_source',
				'value'   => $sources,
				'compare' => 'IN',
			),
		);

		if ( in_array( self::get_default_source(), $sources, true ) ) {
			$meta_query[] = array(
				'key'   => '_en_use_global_event_source',
				'value' => 1,
			);
		}

		return array_map(
			'absint',
			get_posts(
				array(
					'post_type'      => 'en_reservation',
					'post_status'    => 'publish',
					'posts_per_page' => absint( $limit ),
					'fields'         => 'ids',
					'meta_key'       => '_en_available_start_date',
					'orderby'        => 'meta_value',
					'order'          => 'ASC',
					'meta_query'     => $meta_query,
					'no_found_rows'  => true,
				)
			)
		);
	}

	// ──────────────────────────────────────────────────────────────
	// Internal — data helpers
	// ──────────────────────────────────────────────────────────────

	/**
	 * Get native event date values.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{start_date: string, end_date: string}
	 */
	private static function get_native_event_dates( int $event_id ): array {
		$start = (string) EEM_Native_Event_Repo::get_field( $event_id, 'start_date' );
		$end   = (string) EEM_Native_Event_Repo::get_field( $event_id, 'end_date' );

		return array(
			'start_date' => self::normalize_date( $start ),
			'end_date'   => self::normalize_date( $end ? $end : $start ),
		);
	}

	/**
	 * Get venue and location details for a native event.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{venue_name: string, location: string}
	 */
	private static function get_native_event_card_details( int $event_id ): array {
		$venue_id       = (int) EEM_Native_Event_Repo::get_field( $event_id, 'venue_id' );
		$location_label = (string) EEM_Native_Event_Repo::get_field( $event_id, 'location_label' );
		$venue_name     = '';
		$location       = $location_label;

		if ( $venue_id && EEM_Events::VENUE_POST_TYPE === get_post_type( $venue_id ) ) {
			$venue_name = get_the_title( $venue_id );

			if ( '' === $location ) {
				$detail   = EEM_Venue::get_detail( (int) $venue_id, true );
				$location = trim( implode( ', ', array_filter( array( $detail['city'], $detail['state'] ) ) ) );
			}
		}

		return array(
			'venue_name' => $venue_name,
			'location'   => $location,
		);
	}

	/**
	 * Get TEC event date values.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{start_date: string, end_date: string}
	 */
	private static function get_tec_event_dates( int $event_id ): array {
		$start = get_post_meta( $event_id, '_EventStartDate', true );
		$end   = get_post_meta( $event_id, '_EventEndDate', true );

		return array(
			'start_date' => self::normalize_date( $start ),
			'end_date'   => self::normalize_date( $end ? $end : $start ),
		);
	}

	/**
	 * Get venue and location for a TEC event card display.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{venue_name: string, location: string}
	 */
	private static function get_tec_event_card_details( int $event_id ): array {
		$venue_id   = absint( get_post_meta( $event_id, '_EventVenueID', true ) );
		$venue_name = '';
		$location   = '';

		if ( $venue_id ) {
			$venue_name = get_the_title( $venue_id );
			$city       = (string) get_post_meta( $venue_id, '_VenueCity', true );
			$state      = (string) get_post_meta( $venue_id, '_VenueStateProvince', true );

			if ( '' === $state ) {
				$state = (string) get_post_meta( $venue_id, '_VenueState', true );
			}

			$location = trim( implode( ', ', array_filter( array( $city, $state ) ) ) );
		}

		if ( '' === $venue_name ) {
			$venue_name = (string) get_post_meta( $event_id, '_EventVenue', true );
		}

		return array(
			'venue_name' => $venue_name,
			'location'   => $location,
		);
	}

	/**
	 * Get detailed venue data for a TEC event.
	 *
	 * @param int $event_id Event post ID.
	 * @return array
	 */
	private static function get_tec_venue_details( int $event_id ): array {
		$venue_id = absint( get_post_meta( $event_id, '_EventVenueID', true ) );

		if ( ! $venue_id ) {
			return array(
				'address_display' => '',
				'map_query'       => '',
				'filter_url'      => '',
				'link'            => '',
			);
		}

		$name = get_the_title( $venue_id );

		$lines = array_filter(
			array(
				(string) get_post_meta( $venue_id, '_VenueAddress', true ),
				(string) get_post_meta( $venue_id, '_VenueAddress2', true ),
				trim(
					implode(
						', ',
						array_filter(
							array(
								(string) get_post_meta( $venue_id, '_VenueCity', true ),
								(string) get_post_meta( $venue_id, '_VenueStateProvince', true ) ? (string) get_post_meta( $venue_id, '_VenueStateProvince', true ) : (string) get_post_meta( $venue_id, '_VenueState', true ),
							)
						)
					)
				),
				(string) get_post_meta( $venue_id, '_VenueZip', true ),
			)
		);

		return array(
			'address_display' => implode( "\n", $lines ),
			'map_query'       => implode( ', ', $lines ),
			'filter_url'      => self::get_directory_url( EEM_Events::DIRECTORY_LOCATION_ROUTE_BASE, $name ),
			'link'            => (string) get_permalink( $venue_id ),
		);
	}

	/**
	 * Get producer details for a TEC event.
	 *
	 * @param int $event_id Event post ID.
	 * @return array
	 */
	private static function get_tec_producer_details( int $event_id ): array {
		$organizer_id = self::get_tec_organizer_id( $event_id );

		if ( ! $organizer_id ) {
			return array(
				'name'       => '',
				'email'      => '',
				'phone'      => '',
				'website'    => '',
				'filter_url' => '',
				'link'       => '',
			);
		}

		$name = get_the_title( $organizer_id );

		return array(
			'name'       => $name,
			'email'      => (string) get_post_meta( $organizer_id, '_OrganizerEmail', true ),
			'phone'      => (string) get_post_meta( $organizer_id, '_OrganizerPhone', true ),
			'website'    => (string) get_post_meta( $organizer_id, '_OrganizerWebsite', true ),
			'filter_url' => self::get_directory_url( EEM_Events::DIRECTORY_PRODUCER_ROUTE_BASE, $name ),
			'link'       => (string) get_permalink( $organizer_id ),
		);
	}

	/**
	 * Get venue details for a native event.
	 *
	 * @param int $venue_id Venue post ID.
	 * @return array
	 */
	private static function get_venue_details( int $venue_id ): array {
		if ( ! $venue_id || EEM_Events::VENUE_POST_TYPE !== get_post_type( $venue_id ) ) {
			return array(
				'address_display' => '',
				'map_query'       => '',
				'filter_url'      => '',
				'lat'             => '',
				'lng'             => '',
			);
		}

		$name   = get_the_title( $venue_id );
		$detail = EEM_Venue::get_detail( $venue_id, true );

		$city        = trim( $detail['city'] );
		$state       = trim( $detail['state'] );
		$postal_code = trim( $detail['postal_code'] );
		$city_state  = trim( implode( ', ', array_filter( array( $city, $state ) ) ) );

		if ( '' !== $postal_code ) {
			$city_state = trim( $city_state . ' ' . $postal_code );
		}

		$lines = array_filter( array( $detail['address_1'], $detail['address_2'], $city_state ) );

		return array(
			'address_display' => implode( "\n", $lines ),
			'map_query'       => implode( ', ', $lines ),
			'filter_url'      => self::get_directory_url( EEM_Events::DIRECTORY_LOCATION_ROUTE_BASE, $name ),
			'lat'             => $detail['lat'],
			'lng'             => $detail['lng'],
		);
	}

	/**
	 * Get producer details for a native event.
	 *
	 * @param int $producer_id Producer post ID.
	 * @return array
	 */
	private static function get_producer_details( int $producer_id ): array {
		if ( ! $producer_id || EEM_Events::PRODUCER_POST_TYPE !== get_post_type( $producer_id ) ) {
			return array(
				'name'       => '',
				'email'      => '',
				'phone'      => '',
				'website'    => '',
				'filter_url' => '',
			);
		}

		$name          = get_the_title( $producer_id );
		$producer_data = EEM_Producer_Repo::get( (int) $producer_id );

		return array(
			'name'       => $name,
			'email'      => $producer_data['email'],
			'phone'      => $producer_data['phone'],
			'website'    => $producer_data['website'],
			'filter_url' => self::get_directory_url( EEM_Events::DIRECTORY_PRODUCER_ROUTE_BASE, $name ),
		);
	}

	/**
	 * Get the linked reservation ID for an event post.
	 *
	 * @param int $event_id Event post ID.
	 * @return int
	 */
	private static function get_linked_reservation_id( int $event_id ): int {
		$reservation_id = absint( get_post_meta( $event_id, '_equine_event_manager_reservation_id', true ) );

		if ( $reservation_id ) {
			return $reservation_id;
		}

		if ( 'tribe_events' === get_post_type( $event_id ) ) {
			$stored_shortcode = (string) get_post_meta( $event_id, 'reservations', true );

			if ( preg_match( '/id="(\d+)"/', $stored_shortcode, $matches ) ) {
				return absint( $matches[1] );
			}
		}

		return 0;
	}

	/**
	 * Get the first TEC organizer ID for an event.
	 *
	 * @param int $event_id TEC event post ID.
	 * @return int
	 */
	private static function get_tec_organizer_id( int $event_id ): int {
		$organizer_id = get_post_meta( $event_id, '_EventOrganizerID', true );

		if ( is_array( $organizer_id ) ) {
			return ! empty( $organizer_id[0] ) ? absint( $organizer_id[0] ) : 0;
		}

		if ( is_string( $organizer_id ) && false !== strpos( $organizer_id, ':' ) ) {
			$maybe = maybe_unserialize( $organizer_id );
			if ( is_array( $maybe ) ) {
				return ! empty( $maybe[0] ) ? absint( $maybe[0] ) : 0;
			}
		}

		return absint( $organizer_id );
	}

	/**
	 * Resolve the hero image URL from a flyer attachment or thumbnail.
	 *
	 * @param int    $flyer_file_id Flyer attachment ID.
	 * @param string $thumbnail_url Post thumbnail URL.
	 * @return string
	 */
	private static function resolve_hero_image_url( int $flyer_file_id, string $thumbnail_url ): string {
		$flyer_file_id = absint( $flyer_file_id );
		if ( $flyer_file_id ) {
			$mime = get_post_mime_type( $flyer_file_id );
			if ( is_string( $mime ) && 0 === strpos( $mime, 'image/' ) ) {
				$url = wp_get_attachment_image_url( $flyer_file_id, 'large' );
				if ( $url ) {
					return (string) $url;
				}
			}
		}

		return $thumbnail_url ? (string) $thumbnail_url : '';
	}

	/**
	 * Build a directory URL for venue/producer filtered listings.
	 *
	 * @param string $directory_type Route base.
	 * @param string $label          Display label.
	 * @return string
	 */
	private static function get_directory_url( string $directory_type, string $label ): string {
		$directory_type = sanitize_key( $directory_type );
		$label_slug     = sanitize_title( $label );

		if ( '' === $label_slug || ! in_array( $directory_type, array( EEM_Events::DIRECTORY_LOCATION_ROUTE_BASE, EEM_Events::DIRECTORY_PRODUCER_ROUTE_BASE ), true ) ) {
			return '';
		}

		return home_url( user_trailingslashit( EEM_Events::get_event_rewrite_slug() . '/' . $directory_type . '/' . $label_slug ) );
	}

	// ──────────────────────────────────────────────────────────────
	// Internal — filter/sort/dedup
	// ──────────────────────────────────────────────────────────────

	/**
	 * Parse a source filter string into an array of valid source keys.
	 *
	 * @param string $source Raw source filter.
	 * @return array<int, string>
	 */
	private static function parse_source_filter( string $source ): array {
		$parts = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $source ) ) ) );

		if ( empty( $parts ) || in_array( 'all', $parts, true ) ) {
			return array( 'native', 'tec', 'feed', 'external' );
		}

		return array_values( array_intersect( $parts, array( 'native', 'tec', 'feed', 'external' ) ) );
	}

	/**
	 * Parse a timeframe filter string into a canonical value.
	 *
	 * @param string $timeframe Raw timeframe.
	 * @return string
	 */
	private static function parse_timeframe_filter( string $timeframe ): string {
		$timeframe = sanitize_key( $timeframe );
		$aliases   = array(
			'upcoming' => 'current_upcoming',
			'current'  => 'current_upcoming',
		);

		if ( isset( $aliases[ $timeframe ] ) ) {
			$timeframe = $aliases[ $timeframe ];
		}

		return in_array( $timeframe, array( 'current_upcoming', 'past', 'ongoing', 'all' ), true ) ? $timeframe : 'current_upcoming';
	}

	/**
	 * Filter events by timeframe.
	 *
	 * @param array<int, array<string, mixed>> $events    Events.
	 * @param string                           $timeframe Timeframe key.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_by_timeframe( array $events, string $timeframe ): array {
		if ( 'all' === $timeframe ) {
			return $events;
		}

		$today = wp_date( 'Y-m-d', current_time( 'timestamp' ) );

		return array_values(
			array_filter(
				$events,
				static function ( $event ) use ( $timeframe, $today ) {
					$start = self::normalize_date( isset( $event['start_date'] ) ? (string) $event['start_date'] : '' );
					$end   = self::normalize_date( isset( $event['end_date'] ) ? (string) $event['end_date'] : '' );

					if ( '' === $start ) {
						return 'current_upcoming' === $timeframe;
					}

					if ( '' === $end ) {
						$end = $start;
					}

					if ( 'past' === $timeframe ) {
						return $end < $today;
					}

					if ( 'ongoing' === $timeframe ) {
						return $start <= $today && $end >= $today;
					}

					return $end >= $today;
				}
			)
		);
	}

	/**
	 * Deduplicate events, preferring reservation-backed over raw feed.
	 *
	 * @param array<int, array<string, mixed>> $events Events.
	 * @return array<int, array<string, mixed>>
	 */
	private static function deduplicate( array $events ): array {
		$deduped = array();

		foreach ( $events as $event ) {
			$key = '';

			if ( 'feed' === ( isset( $event['source'] ) ? $event['source'] : '' ) && ! empty( $event['external_event_id'] ) ) {
				$key = 'feed:' . (string) $event['external_event_id'];
			} elseif ( ! empty( $event['event_id'] ) ) {
				$key = 'post:' . absint( $event['event_id'] );
			} elseif ( ! empty( $event['reservation_id'] ) ) {
				$key = 'reservation:' . absint( $event['reservation_id'] );
			}

			if ( '' === $key ) {
				$deduped[] = $event;
				continue;
			}

			if ( ! isset( $deduped[ $key ] ) ) {
				$deduped[ $key ] = $event;
				continue;
			}

			$current_has_reservation = ! empty( $deduped[ $key ]['reservation_id'] );
			$new_has_reservation     = ! empty( $event['reservation_id'] );

			if ( $new_has_reservation && ! $current_has_reservation ) {
				$deduped[ $key ] = $event;
			}
		}

		return array_values( $deduped );
	}

	/**
	 * Get the sort date for an event (start_date preferred, then end_date, then far-future).
	 *
	 * @param array<string, mixed> $event_data Event data.
	 * @return string
	 */
	private static function get_sort_date( array $event_data ): string {
		$start = self::normalize_date( isset( $event_data['start_date'] ) ? (string) $event_data['start_date'] : '' );
		$end   = self::normalize_date( isset( $event_data['end_date'] ) ? (string) $event_data['end_date'] : '' );

		if ( $start ) {
			return $start;
		}

		if ( $end ) {
			return $end;
		}

		return '9999-12-31';
	}

	/**
	 * Normalize a date string to Y-m-d format.
	 *
	 * @param string $value Raw date string.
	 * @return string Y-m-d or ''.
	 */
	private static function normalize_date( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );

		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
	}
}
