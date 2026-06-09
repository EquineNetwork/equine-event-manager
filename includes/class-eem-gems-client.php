<?php
/**
 * GEMS Web Data API client — backs the "Feed" event source.
 *
 * Fetches the event schedule from the GEMS Web Data API
 * (GET /api/Schedule/{associationId}, Bearer JWT) and normalizes each event into
 * the same canonical shape the generic feed adapter produces, so the reservation
 * editor's event picker and the linked-event display surfaces consume GEMS events
 * with no source-specific branching downstream.
 *
 * Credentials resolve from EEM's own integration settings first, falling back to
 * the `gems_key` / `gems_assn` options the standalone "GEMS Wordpress Integration"
 * plugin stores — so when that plugin is installed the connection works with no
 * re-entry.
 *
 * Results are cached in a short-lived transient (live fetch, no cron).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GEMS Web Data API client.
 */
class EEM_Gems_Client {

	/**
	 * Default GEMS Web Data API base URL (override per-site in Settings).
	 *
	 * @var string
	 */
	const DEFAULT_BASE_URL = 'https://webdataapi-ehbahmadepazg8e3.centralus-01.azurewebsites.net';

	/**
	 * Transient TTL for a fetched schedule (seconds).
	 *
	 * @var int
	 */
	const CACHE_TTL = 900; // 15 minutes.

	/**
	 * Resolve the GEMS connection settings.
	 *
	 * EEM's own integration settings win; the standalone GEMS plugin's
	 * `gems_key` / `gems_assn` options are the fallback.
	 *
	 * @return array{base_url:string, token:string, assn:string, configured:bool}
	 */
	public static function get_credentials() {
		$settings = get_option( 'equine_event_manager_integration_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$base_url = isset( $settings['feed_gems_base_url'] ) ? trim( (string) $settings['feed_gems_base_url'] ) : '';
		$token    = isset( $settings['feed_gems_token'] ) ? trim( (string) $settings['feed_gems_token'] ) : '';
		$assn     = isset( $settings['feed_gems_assn'] ) ? trim( (string) $settings['feed_gems_assn'] ) : '';

		// Fall back to the standalone GEMS plugin's options.
		if ( '' === $token ) {
			$token = trim( (string) get_option( 'gems_key', '' ) );
		}
		if ( '' === $assn ) {
			$assn = trim( (string) get_option( 'gems_assn', '' ) );
		}
		if ( '' === $base_url ) {
			$base_url = self::DEFAULT_BASE_URL;
		}

		return array(
			'base_url'   => untrailingslashit( $base_url ),
			'token'      => $token,
			'assn'       => $assn,
			'configured' => '' !== $token && '' !== $assn,
		);
	}

	/**
	 * True when a usable GEMS connection is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$creds = self::get_credentials();
		return ! empty( $creds['configured'] );
	}

	/**
	 * Low-level: GET the GEMS schedule and return the decoded raw array.
	 *
	 * @param string $base_url Base URL (no trailing slash).
	 * @param string $token    JWT bearer token.
	 * @param string $assn     Association ID.
	 * @return array<int, array<string,mixed>>|WP_Error Raw event records, or error.
	 */
	public static function request_schedule( $base_url, $token, $assn ) {
		if ( '' === trim( (string) $token ) || '' === trim( (string) $assn ) ) {
			return new WP_Error( 'gems_not_configured', __( 'Add the GEMS JWT Token and Association ID in Settings → Integrations.', 'equine-event-manager' ) );
		}

		$url = untrailingslashit( (string) $base_url ) . '/api/Schedule/' . rawurlencode( (string) $assn );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . trim( (string) $token ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: transport error message. */
			return new WP_Error( 'gems_request_failed', sprintf( __( 'Could not reach the GEMS API: %s', 'equine-event-manager' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'gems_unauthorized', __( 'GEMS rejected the credentials (HTTP 401/403). Check the JWT Token and Association ID.', 'equine-event-manager' ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'gems_http_error', sprintf( __( 'GEMS API returned HTTP %d.', 'equine-event-manager' ), $code ) );
		}

		// Strip a leading BOM defensively before decoding (some APIs prepend one).
		$raw     = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$decoded = json_decode( trim( (string) $raw ), true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'gems_bad_json', __( 'The GEMS API returned an unreadable response.', 'equine-event-manager' ) );
		}

		return $decoded;
	}

	/**
	 * Normalize one raw GEMS schedule record into the canonical feed-event shape.
	 *
	 * @param array<string,mixed> $r Raw GEMS event record.
	 * @return array<string,mixed> Normalized event (empty array when unusable).
	 */
	public static function normalize_event( array $r ) {
		$uid = isset( $r['eventUID'] ) ? (string) $r['eventUID'] : '';
		if ( '' === $uid ) {
			return array();
		}

		$start = ! empty( $r['startDate'] ) ? gmdate( 'Y-m-d', strtotime( (string) $r['startDate'] ) ) : '';
		$end   = ! empty( $r['endDate'] ) ? gmdate( 'Y-m-d', strtotime( (string) $r['endDate'] ) ) : $start;

		$city  = isset( $r['arenaCity'] ) ? trim( (string) $r['arenaCity'] ) : '';
		$state = isset( $r['arenaState'] ) ? trim( (string) $r['arenaState'] ) : '';
		$loc   = trim( $city . ( '' !== $city && '' !== $state ? ', ' : '' ) . $state );

		return array(
			'source'            => 'feed',
			'external_event_id' => $uid,
			'title'             => isset( $r['eventName'] ) ? (string) $r['eventName'] : '',
			'start_date'        => $start,
			'end_date'          => $end,
			'venue_name'        => isset( $r['arenaName'] ) ? (string) $r['arenaName'] : '',
			'venue_address'     => isset( $r['arenaAddress'] ) ? (string) $r['arenaAddress'] : '',
			'venue_city'        => $city,
			'venue_state'       => $state,
			'venue_zip'         => isset( $r['arenaZip'] ) ? (string) $r['arenaZip'] : '',
			'location'          => $loc,
			'producer'          => array(
				'name'  => isset( $r['producerName'] ) ? (string) $r['producerName'] : '',
				'phone' => isset( $r['producerPhone'] ) ? (string) $r['producerPhone'] : '',
				'email' => isset( $r['producerEmail'] ) ? (string) $r['producerEmail'] : '',
			),
			'event_type'        => isset( $r['eventType'] ) ? (string) $r['eventType'] : '',
			'ref_id'            => isset( $r['refId'] ) ? (string) $r['refId'] : '',
			'logo'              => isset( $r['imgLogo'] ) ? (string) $r['imgLogo'] : '',
			'content_raw'       => isset( $r['eventType'] ) ? (string) $r['eventType'] : '',
		);
	}

	/**
	 * Get the normalized GEMS schedule, transient-cached.
	 *
	 * @param bool $force_refresh Bypass the cache.
	 * @return array<int, array<string,mixed>>|WP_Error Normalized events, or error.
	 */
	public static function get_events( $force_refresh = false ) {
		$creds = self::get_credentials();
		if ( empty( $creds['configured'] ) ) {
			return new WP_Error( 'gems_not_configured', __( 'GEMS is not connected. Add the JWT Token and Association ID in Settings → Integrations.', 'equine-event-manager' ) );
		}

		$cache_key = 'eem_gems_sched_' . md5( $creds['base_url'] . '|' . $creds['assn'] );

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$raw = self::request_schedule( $creds['base_url'], $creds['token'], $creds['assn'] );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$events = array();
		foreach ( $raw as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$event = self::normalize_event( $record );
			if ( ! empty( $event ) ) {
				$events[] = $event;
			}
		}

		set_transient( $cache_key, $events, self::CACHE_TTL );

		return $events;
	}

	/**
	 * Clear the cached schedule (used by a manual refresh / after saving creds).
	 *
	 * @return void
	 */
	public static function clear_cache() {
		$creds = self::get_credentials();
		delete_transient( 'eem_gems_sched_' . md5( $creds['base_url'] . '|' . $creds['assn'] ) );
	}

	/**
	 * Search the schedule for the editor event picker.
	 *
	 * @param string $term          Keyword (matches title / venue / location / id).
	 * @param int    $limit         Max results.
	 * @param bool   $upcoming_only Hide events whose end date is in the past.
	 * @return array<int, array<string,mixed>>|WP_Error Picker-ready rows, or error.
	 */
	public static function search( $term = '', $limit = 20, $upcoming_only = true ) {
		$events = self::get_events();
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$term_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $term ) : strtolower( (string) $term );
		$today   = current_time( 'Y-m-d' );
		$rows    = array();

		foreach ( $events as $e ) {
			if ( $upcoming_only && '' !== $e['end_date'] && $e['end_date'] < $today ) {
				continue;
			}

			$blob = implode( ' ', array_filter( array( $e['title'], $e['venue_name'], $e['location'], $e['external_event_id'] ) ) );
			$blob = function_exists( 'mb_strtolower' ) ? mb_strtolower( $blob ) : strtolower( $blob );

			if ( '' !== $term_lc && false === strpos( $blob, $term_lc ) ) {
				continue;
			}

			$rows[] = array(
				'id'                => $e['external_event_id'],
				'text'              => $e['title'],
				'start_date'        => $e['start_date'],
				'end_date'          => $e['end_date'],
				'venue_name'        => $e['venue_name'],
				'location'          => $e['location'],
				'producer_name'     => isset( $e['producer']['name'] ) ? (string) $e['producer']['name'] : '',
				'external_event_id' => $e['external_event_id'],
			);

			if ( count( $rows ) >= absint( $limit ) ) {
				break;
			}
		}

		// Soonest-first so the picker leads with the next events.
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( (string) $a['start_date'], (string) $b['start_date'] );
			}
		);

		return $rows;
	}

	/**
	 * Get one normalized GEMS event by its eventUID.
	 *
	 * @param string $external_event_id GEMS eventUID.
	 * @return array<string,mixed> Normalized event (empty array when not found).
	 */
	public static function get_event_by_id( $external_event_id ) {
		$external_event_id = (string) $external_event_id;
		if ( '' === $external_event_id ) {
			return array();
		}

		$events = self::get_events();
		if ( is_wp_error( $events ) ) {
			return array();
		}

		foreach ( $events as $e ) {
			if ( (string) $e['external_event_id'] === $external_event_id ) {
				return $e;
			}
		}

		return array();
	}

	/**
	 * Test a connection with explicit (possibly unsaved) credentials.
	 *
	 * @param string $base_url Base URL.
	 * @param string $token    JWT token.
	 * @param string $assn     Association ID.
	 * @return array{ok:bool, count:int, message:string} Result.
	 */
	public static function test_connection( $base_url, $token, $assn ) {
		$base_url = '' !== trim( (string) $base_url ) ? $base_url : self::DEFAULT_BASE_URL;
		$raw      = self::request_schedule( $base_url, $token, $assn );

		if ( is_wp_error( $raw ) ) {
			return array(
				'ok'      => false,
				'count'   => 0,
				'message' => $raw->get_error_message(),
			);
		}

		$count = 0;
		foreach ( $raw as $record ) {
			if ( is_array( $record ) && ! empty( $record['eventUID'] ) ) {
				$count++;
			}
		}

		return array(
			'ok'      => true,
			'count'   => $count,
			/* translators: %d: number of scheduled events returned by GEMS. */
			'message' => sprintf( _n( '✓ Connected — %d scheduled event found.', '✓ Connected — %d scheduled events found.', $count, 'equine-event-manager' ), $count ),
		);
	}
}
