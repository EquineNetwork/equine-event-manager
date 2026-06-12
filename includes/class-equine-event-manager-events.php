<?php
/**
 * Native event content types, metadata, and frontend output.
 *
 * @package EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers native events, venues, producers, frontend shortcodes, and widgets.
 */
class EEM_Events {

	const FEATURES_SETTINGS_OPTION = 'equine_event_manager_feature_settings';
	const INTEGRATION_SETTINGS_OPTION = 'equine_event_manager_integration_settings';
	const SAFE_EVENT_ARCHIVE_SLUG = 'equine-events';
	const VIRTUAL_EVENT_ROUTE_BASE = 'equine-event';
	const DIRECTORY_LOCATION_ROUTE_BASE = 'location';
	const DIRECTORY_PRODUCER_ROUTE_BASE = 'producer';
	const EVENT_POST_TYPE = 'en_event';
	const VENUE_POST_TYPE = 'en_venue';
	const PRODUCER_POST_TYPE = 'en_producer';
	const EVENT_CATEGORY_TAXONOMY = 'en_event_category';
	const EVENT_TAG_TAXONOMY = 'en_event_tag';
	const VENUE_CATEGORY_TAXONOMY = 'en_venue_category';
	const PRODUCER_CATEGORY_TAXONOMY = 'en_producer_category';
	const EVENT_META_NONCE = 'equine_event_manager_event_meta_nonce';
	const VENUE_META_NONCE = 'equine_event_manager_venue_meta_nonce';
	const PRODUCER_META_NONCE = 'equine_event_manager_producer_meta_nonce';
	const IMPORT_TEC_ACTION = 'equine_event_manager_import_tec_events';
	const FEED_CACHE_TTL = 900;

	private static $is_rendering_event_template = false;

	/**
	 * True once filter_single_event_template has handed WordPress the EEM
	 * single-event template for the current request. The the_content takeover
	 * (2.3.49) stands down when this is set so the page renders once.
	 *
	 * @var bool
	 */
	private static $is_template_takeover = false;

	/**
	 * Get feature settings with defaults.
	 *
	 * @return array<string, int>
	 */
	public static function get_feature_settings() {
		return wp_parse_args(
			get_option( self::FEATURES_SETTINGS_OPTION, array() ),
			array(
				'native_events_enabled' => 0,
			)
		);
	}

	/**
	 * Determine whether native events are enabled.
	 *
	 * @return bool
	 */
	public static function is_native_events_enabled() {
		$settings = self::get_feature_settings();

		return ! empty( $settings['native_events_enabled'] );
	}

	/**
	 * Get integration settings with defaults.
	 *
	 * @return array<string, string|int>
	 */
	public static function get_integration_settings() {
		return wp_parse_args(
			get_option( self::INTEGRATION_SETTINGS_OPTION, array() ),
			array(
				'tec_integration_enabled' => 1,
				'default_event_source'    => 'external',
				'feed_url'                => '',
			)
		);
	}

	/**
	 * Google Maps API key from integration settings (powers the events map view
	 * + venue address geocoding). Empty string when not configured.
	 *
	 * @return string
	 */
	public static function get_google_maps_api_key() {
		$settings = self::get_integration_settings();

		return isset( $settings['google_maps_api_key'] ) ? trim( (string) $settings['google_maps_api_key'] ) : '';
	}

	/**
	 * Assemble a venue's single-line address from its component meta (for
	 * geocoding + map queries). Empty parts are skipped.
	 *
	 * @param int $venue_id Venue post id.
	 * @return string
	 */
	public static function get_venue_address_string( $venue_id ) {
		$venue_id = absint( $venue_id );
		if ( ! $venue_id ) {
			return '';
		}
		$parts = array();
		foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postal_code' ) as $field ) {
			$val = trim( (string) get_post_meta( $venue_id, '_equine_event_manager_venue_' . $field, true ) );
			if ( '' !== $val ) {
				$parts[] = $val;
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Geocode an address string to lat/lng via the Google Geocoding API.
	 *
	 * @param string $address Single-line address.
	 * @param string $api_key Google Maps API key (Geocoding API enabled).
	 * @return array{lat:float,lng:float}|null  Null on empty input, no key, transport error, or zero results.
	 */
	public static function geocode_address( $address, $api_key ) {
		$address = trim( (string) $address );
		$api_key = trim( (string) $api_key );
		if ( '' === $address || '' === $api_key ) {
			return null;
		}
		$url  = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . rawurlencode( $address ) . '&key=' . rawurlencode( $api_key );
		$resp = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $resp ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body['results'][0]['geometry']['location'] ) ) {
			return null;
		}
		$loc = $body['results'][0]['geometry']['location'];
		if ( ! isset( $loc['lat'], $loc['lng'] ) ) {
			return null;
		}
		return array( 'lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng'] );
	}

	/**
	 * Geocode a venue's address into _en_venue_lat / _en_venue_lng when a Maps
	 * key is configured and the address has changed (or coords are missing).
	 * No-ops gracefully without a key, address, or on geocode failure. Skipped
	 * when the admin has entered manual coordinates (caller's responsibility).
	 *
	 * @param int $venue_id Venue post id.
	 * @return void
	 */
	public static function maybe_geocode_venue( $venue_id ) {
		$venue_id = absint( $venue_id );
		$key      = self::get_google_maps_api_key();
		if ( ! $venue_id || '' === $key ) {
			return;
		}
		$address = self::get_venue_address_string( $venue_id );
		if ( '' === $address ) {
			return;
		}
		$last_geocoded = (string) get_post_meta( $venue_id, '_en_venue_geocoded_address', true );
		$has_coords    = '' !== (string) get_post_meta( $venue_id, '_en_venue_lat', true );
		if ( $has_coords && $last_geocoded === $address ) {
			return; // address unchanged since last successful geocode.
		}
		$coords = self::geocode_address( $address, $key );
		if ( null === $coords ) {
			return;
		}
		update_post_meta( $venue_id, '_en_venue_lat', $coords['lat'] );
		update_post_meta( $venue_id, '_en_venue_lng', $coords['lng'] );
		update_post_meta( $venue_id, '_en_venue_geocoded_address', $address );
	}

	/**
	 * Get the default external feed URL configured in settings.
	 *
	 * @return string
	 */
	public static function get_default_feed_url() {
		$settings = self::get_integration_settings();

		return isset( $settings['feed_url'] ) ? esc_url_raw( (string) $settings['feed_url'] ) : '';
	}

	/**
	 * AJAX test an external feed URL.
	 *
	 * @return void
	 */
	public function ajax_test_feed_url() {
		check_ajax_referer( 'equine_event_manager_test_feed_url', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to test feed URLs.', 'equine-event-manager' ) ), 403 );
		}

		$feed_url = isset( $_POST['feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['feed_url'] ) ) : '';
		$result   = self::test_feed_url( $feed_url, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX search external feed events.
	 *
	 * @return void
	 */
	public function ajax_search_feed_events() {
		check_ajax_referer( 'equine_event_manager_search_feed_events', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to search feed events.', 'equine-event-manager' ) ), 403 );
		}

		$term     = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$feed_url = isset( $_GET['feed_url'] ) ? esc_url_raw( wp_unslash( $_GET['feed_url'] ) ) : self::get_default_feed_url();
		$results  = self::search_feed_events( $term, $feed_url, 20 );

		if ( is_wp_error( $results ) ) {
			wp_send_json_error(
				array(
					'message' => $results->get_error_message(),
				),
				400
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Test an external feed URL and summarize the result.
	 *
	 * @param string $feed_url Feed URL.
	 * @param bool   $force_refresh Whether to bypass cache.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function test_feed_url( $feed_url = '', $force_refresh = false ) {
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

	/**
	 * Search normalized feed events.
	 *
	 * @param string $term Search term.
	 * @param string $feed_url Optional feed URL.
	 * @param int    $limit Result limit.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function search_feed_events( $term = '', $feed_url = '', $limit = 20 ) {
		// The Feed source is backed by the GEMS Web Data API when a GEMS
		// connection is configured — delegate so the editor's event picker
		// searches live GEMS events (mirrors how TEC is searched).
		if ( class_exists( 'EEM_Gems_Client' ) && EEM_Gems_Client::is_configured() ) {
			return EEM_Gems_Client::search( $term, $limit, true );
		}

		$feed_index = self::get_feed_event_index( $feed_url );

		if ( is_wp_error( $feed_index ) ) {
			return $feed_index;
		}

		$term    = sanitize_text_field( (string) $term );
		$term_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term ) : strtolower( $term );
		$results = array();

		foreach ( $feed_index['events'] as $event ) {
			$haystack = array_filter(
				array(
					isset( $event['title'] ) ? (string) $event['title'] : '',
					isset( $event['venue_name'] ) ? (string) $event['venue_name'] : '',
					isset( $event['location'] ) ? (string) $event['location'] : '',
					! empty( $event['producer']['name'] ) ? (string) $event['producer']['name'] : '',
					isset( $event['external_event_id'] ) ? (string) $event['external_event_id'] : '',
				)
			);

			$search_blob = function_exists( 'mb_strtolower' ) ? mb_strtolower( implode( ' ', $haystack ) ) : strtolower( implode( ' ', $haystack ) );

			if ( '' !== $term_lc && false === strpos( $search_blob, $term_lc ) ) {
				continue;
			}

			$results[] = array(
				'id'                 => isset( $event['external_event_id'] ) ? (string) $event['external_event_id'] : '',
				'text'               => isset( $event['title'] ) ? (string) $event['title'] : '',
				'start_date'         => isset( $event['start_date'] ) ? (string) $event['start_date'] : '',
				'end_date'           => isset( $event['end_date'] ) ? (string) $event['end_date'] : '',
				'venue_name'         => isset( $event['venue_name'] ) ? (string) $event['venue_name'] : '',
				'location'           => isset( $event['location'] ) ? (string) $event['location'] : '',
				'producer_name'      => ! empty( $event['producer']['name'] ) ? (string) $event['producer']['name'] : '',
				'content_raw'        => isset( $event['content_raw'] ) ? wp_strip_all_tags( (string) $event['content_raw'] ) : '',
				'event_feed_url'     => isset( $feed_index['feed_url'] ) ? (string) $feed_index['feed_url'] : '',
				'external_event_id'  => isset( $event['external_event_id'] ) ? (string) $event['external_event_id'] : '',
			);

			if ( count( $results ) >= absint( $limit ) ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Get one normalized feed event by its external event ID.
	 *
	 * @param string $external_event_id External event ID.
	 * @param string $feed_url Optional feed URL.
	 * @return array<string, mixed>
	 */
	public static function get_feed_event_by_external_id( $external_event_id, $feed_url = '' ) {
		$external_event_id = sanitize_text_field( (string) $external_event_id );

		if ( '' === $external_event_id ) {
			return array();
		}

		// GEMS-backed feed: resolve the linked event from the GEMS schedule.
		if ( class_exists( 'EEM_Gems_Client' ) && EEM_Gems_Client::is_configured() ) {
			return EEM_Gems_Client::get_event_by_id( $external_event_id );
		}

		$feed_index = self::get_feed_event_index( $feed_url );

		if ( is_wp_error( $feed_index ) ) {
			return array();
		}

		foreach ( $feed_index['events'] as $event ) {
			if ( isset( $event['external_event_id'] ) && (string) $event['external_event_id'] === $external_event_id ) {
				return $event;
			}
		}

		return array();
	}

	/**
	 * Bridge for external integrations (e.g. the GEMS for WordPress plugin):
	 * find the PUBLISHED reservation linked to an external event id and return
	 * its public booking-page details, so the external event listing can render
	 * a "Reservations" button that links to the EN reservation page.
	 *
	 * A reservation is "linked" when its `_en_external_event_id` post-meta equals
	 * the external event id (the GEMS eventUID stored when the admin picks the
	 * event in the reservation editor).
	 *
	 * @param string $external_event_id External event id (GEMS eventUID).
	 * @return array{reservation_id:int, url:string, title:string}|null Null when none.
	 */
	public static function get_reservation_for_external_event( $external_event_id ) {
		$external_event_id = trim( (string) $external_event_id );
		if ( '' === $external_event_id ) {
			return null;
		}

		$ids = get_posts(
			array(
				'post_type'        => 'en_reservation',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => array(
					array(
						'key'   => '_en_external_event_id',
						'value' => $external_event_id,
					),
				),
			)
		);

		if ( empty( $ids ) ) {
			return null;
		}

		$reservation_id = (int) $ids[0];

		// The en_reservation CPT is public => false, so get_permalink() yields a
		// non-routable ?post_type=en_reservation&p=N URL that 404s. Customers
		// reach a reservation through the plugin's virtual event route
		// (/equine-event/{id}/), the same page TEC-linked reservations use —
		// featured image + event info + the booking form below. Build that URL.
		$url = self::get_reservation_public_url( $reservation_id );

		if ( '' === $url ) {
			return null;
		}

		return array(
			'reservation_id' => $reservation_id,
			'url'            => $url,
			'title'          => (string) get_the_title( $reservation_id ),
		);
	}

	/**
	 * Get the normalized feed event index for a URL.
	 *
	 * @param string $feed_url Optional feed URL.
	 * @param bool   $force_refresh Whether to bypass cache.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_feed_event_index( $feed_url = '', $force_refresh = false ) {
		$feed_url = $feed_url ? esc_url_raw( (string) $feed_url ) : self::get_default_feed_url();

		if ( '' === $feed_url ) {
			return new WP_Error( 'feed_url_missing', __( 'Add an External Feed URL in Settings > Integrations before using the feed source.', 'equine-event-manager' ) );
		}

		$cache_key = 'eem_feed_index_' . md5( $feed_url );

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) && isset( $cached['events'], $cached['format'], $cached['feed_url'] ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			$feed_url,
			array(
				'timeout'     => 15,
				'redirection' => 5,
				'user-agent'  => 'Equine Event Manager/' . EQUINE_EVENT_MANAGER_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'feed_request_failed', sprintf( __( 'Feed request failed: %s', 'equine-event-manager' ), $response->get_error_message() ) );
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		$body          = (string) wp_remote_retrieve_body( $response );
		$content_type  = (string) wp_remote_retrieve_header( $response, 'content-type' );

		if ( $response_code < 200 || $response_code >= 300 ) {
			return new WP_Error( 'feed_http_error', sprintf( __( 'Feed request returned HTTP %d.', 'equine-event-manager' ), $response_code ) );
		}

		if ( '' === trim( $body ) ) {
			return new WP_Error( 'feed_empty', __( 'The feed URL returned an empty response.', 'equine-event-manager' ) );
		}

		$format = self::detect_feed_format( $body, $content_type );

		if ( ! in_array( $format, array( 'json', 'xml' ), true ) ) {
			return new WP_Error( 'feed_format_unsupported', __( 'The feed URL did not return supported JSON or XML event data.', 'equine-event-manager' ) );
		}

		$events = 'json' === $format ? self::normalize_json_feed_events( $body, $feed_url ) : self::normalize_xml_feed_events( $body, $feed_url );

		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$feed_index = array(
			'feed_url' => $feed_url,
			'format'   => $format,
			'events'   => $events,
		);

		set_transient( $cache_key, $feed_index, self::FEED_CACHE_TTL );

		return $feed_index;
	}

	/**
	 * Detect the remote feed format.
	 *
	 * @param string $body Response body.
	 * @param string $content_type Response content type.
	 * @return string
	 */
	private static function detect_feed_format( $body, $content_type ) {
		$body         = ltrim( (string) $body );
		$content_type = strtolower( (string) $content_type );

		if ( false !== strpos( $content_type, 'json' ) || ( '' !== $body && in_array( $body[0], array( '{', '[' ), true ) ) ) {
			return 'json';
		}

		if ( false !== strpos( $content_type, 'xml' ) || false === strpos( $body, '<html' ) && '' !== $body && '<' === $body[0] ) {
			return 'xml';
		}

		return '';
	}

	/**
	 * Normalize a JSON feed response into shared event data.
	 *
	 * @param string $body Feed body.
	 * @param string $feed_url Feed URL.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function normalize_json_feed_events( $body, $feed_url ) {
		$payload = json_decode( (string) $body, true );

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'feed_json_invalid', __( 'The JSON feed response could not be parsed.', 'equine-event-manager' ) );
		}

		$records = self::extract_feed_records_from_json( $payload );
		$events  = array();

		foreach ( $records as $index => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$event = self::normalize_feed_event_record( $record, $feed_url, $index );

			if ( ! empty( $event ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Extract the most likely JSON record list from a payload.
	 *
	 * @param array<string, mixed>|array<int, mixed> $payload JSON payload.
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_feed_records_from_json( $payload ) {
		if ( self::is_feed_record_list( $payload ) ) {
			return $payload;
		}

		foreach ( array( 'events', 'data', 'items', 'results', 'EventList', 'EventData' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) && self::is_feed_record_list( $payload[ $key ] ) ) {
				return $payload[ $key ];
			}
		}

		foreach ( $payload as $value ) {
			if ( is_array( $value ) && self::is_feed_record_list( $value ) ) {
				return $value;
			}
		}

		return array();
	}

	/**
	 * Determine whether an array looks like a list of feed records.
	 *
	 * @param mixed $value Possible record list.
	 * @return bool
	 */
	private static function is_feed_record_list( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return false;
		}

		$first = reset( $value );

		return is_array( $first );
	}

	/**
	 * Normalize an XML feed response into shared event data.
	 *
	 * @param string $body Feed body.
	 * @param string $feed_url Feed URL.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function normalize_xml_feed_events( $body, $feed_url ) {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( (string) $body, 'SimpleXMLElement', LIBXML_NOCDATA );

		if ( false === $xml ) {
			libxml_clear_errors();
			return new WP_Error( 'feed_xml_invalid', __( 'The XML feed response could not be parsed.', 'equine-event-manager' ) );
		}

		libxml_clear_errors();

		$records = array();

		foreach ( $xml->xpath( '//item | //event | //entry' ) as $node ) {
			$record = json_decode( wp_json_encode( $node ), true );

			if ( is_array( $record ) ) {
				$records[] = $record;
			}
		}

		$events = array();

		foreach ( $records as $index => $record ) {
			$event = self::normalize_feed_event_record( $record, $feed_url, $index );

			if ( ! empty( $event ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Normalize one feed record into shared event data.
	 *
	 * @param array<string, mixed> $record Feed record.
	 * @param string               $feed_url Feed URL.
	 * @param int                  $index Zero-based record index.
	 * @return array<string, mixed>
	 */
	private static function normalize_feed_event_record( $record, $feed_url, $index ) {
		$external_event_id = self::get_first_feed_value(
			$record,
			array( 'EventUID', 'event_uid', 'eventId', 'event_id', 'id', 'uid', 'ID' )
		);
		$title             = self::get_first_feed_value(
			$record,
			array( 'EventName', 'event_name', 'title', 'name' )
		);
		$start_date_raw    = self::get_first_feed_value(
			$record,
			array( 'StartDate', 'start_date', 'start', 'event_start_date', 'date_start' )
		);
		$end_date_raw      = self::get_first_feed_value(
			$record,
			array( 'EndDate', 'end_date', 'end', 'event_end_date', 'date_end' )
		);
		$venue_name        = self::get_first_feed_value(
			$record,
			array( 'ArenaName', 'VenueName', 'venue_name', 'venue', 'location_name' )
		);
		$city              = self::get_first_feed_value( $record, array( 'ArenaCity', 'city', 'City' ) );
		$state             = self::get_first_feed_value( $record, array( 'ArenaState', 'state', 'State' ) );
		$location          = self::get_first_feed_value( $record, array( 'Location', 'location', 'location_label' ) );
		$producer_name     = self::get_first_feed_value( $record, array( 'ProducerName', 'producer_name', 'producer' ) );
		$content_raw       = self::get_first_feed_value( $record, array( 'Description', 'description', 'Summary', 'summary', 'Content', 'content' ) );
		$flyer_url         = self::get_first_feed_value( $record, array( 'FlyerURL', 'flyer_url', 'PDFURL', 'pdf_url' ) );
		$featured_image    = self::get_first_feed_value( $record, array( 'ImageURL', 'image_url', 'EventLogoUrl', 'logo_url' ) );
		$external_url      = self::get_first_feed_value( $record, array( 'EventURL', 'event_url', 'URL', 'url', 'OnlineEntryURL' ) );

		if ( '' === $location ) {
			$location = trim( implode( ', ', array_filter( array( $city, $state ) ) ) );
		}

		$start_date = self::normalize_date_for_input( (string) $start_date_raw );
		$end_date   = self::normalize_date_for_input( (string) $end_date_raw );

		if ( '' === $title || '' === $start_date ) {
			return array();
		}

		if ( '' === $external_event_id ) {
			$external_event_id = 'feed-' . md5( $feed_url . '|' . $title . '|' . $start_date . '|' . $index );
		}

		return array(
			'event_id'          => 0,
			'source'            => 'feed',
			'external_event_id' => $external_event_id,
			'title'             => $title,
			'content_raw'       => $content_raw,
			'excerpt'           => '',
			'start_date'        => $start_date,
			'end_date'          => $end_date ? $end_date : $start_date,
			'venue_name'        => $venue_name,
			'location'          => $location,
			'venue'             => array(
				'address_display' => '',
				'map_query'       => trim( implode( ', ', array_filter( array( $venue_name, $location ) ) ) ),
				'filter_url'      => '',
			),
			'producer'          => array(
				'name'       => $producer_name,
				'email'      => '',
				'phone'      => '',
				'website'    => '',
				'filter_url' => '',
			),
			'featured_image'    => $featured_image,
			'flyer_url'         => $flyer_url,
			'external_url'      => $external_url,
			'reservation_id'    => 0,
			'cta_label'         => __( 'View Event', 'equine-event-manager' ),
			'categories'        => array(),
			'tags'              => array(),
			'map_url'           => '',
			'event_feed_url'    => $feed_url,
		);
	}

	/**
	 * Get the first non-empty value from a feed record.
	 *
	 * @param array<string, mixed> $record Feed record.
	 * @param array<int, string>   $keys Candidate keys.
	 * @return string
	 */
	private static function get_first_feed_value( $record, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $record[ $key ] ) ) {
				$value = is_scalar( $record[ $key ] ) ? (string) $record[ $key ] : '';

				if ( '' !== trim( $value ) ) {
					return trim( $value );
				}
			}
		}

		return '';
	}

	/**
	 * Determine whether The Events Calendar post type is active on this site.
	 *
	 * @return bool
	 */
	public static function is_tec_plugin_active() {
		return post_type_exists( 'tribe_events' );
	}

	/**
	 * Determine whether TEC integration is configured on.
	 *
	 * @return bool
	 */
	public static function is_tec_integration_configured() {
		$settings = self::get_integration_settings();

		return ! empty( $settings['tec_integration_enabled'] );
	}

	/**
	 * Determine whether TEC integration is available and enabled.
	 *
	 * @return bool
	 */
	public static function is_tec_integration_enabled() {
		return self::is_tec_integration_configured() && self::is_tec_plugin_active();
	}

	/**
	 * Get the default event source based on saved settings and active capabilities.
	 *
	 * @return string
	 */
	public static function get_default_event_source() {
		$settings = self::get_integration_settings();
		$default  = sanitize_key( $settings['default_event_source'] );

		// Sources the admin can actually select in Settings today. Native and
		// External/Feed are "Coming Soon" in the UI, so they are not treated as
		// active defaults. This makes a fresh install — whose stored default is
		// the legacy 'external' — resolve to the available source (TEC) with NO
		// Save required, instead of silently dropping linked TEC events because
		// the source resolved to 'external'.
		$available = array();
		if ( self::is_native_events_enabled() ) {
			$available[] = 'native';
		}
		if ( self::is_tec_integration_enabled() ) {
			$available[] = 'tec';
		}
		// The Feed source is real (selectable) when a GEMS connection is
		// configured — events are read from the GEMS Web Data API.
		if ( class_exists( 'EEM_Gems_Client' ) && EEM_Gems_Client::is_configured() ) {
			$available[] = 'feed';
		}

		// Honour an explicit stored choice only when it points at an available source.
		if ( in_array( $default, $available, true ) ) {
			return $default;
		}

		// Otherwise prefer the first available real source (TEC on a typical install).
		if ( ! empty( $available ) ) {
			return $available[0];
		}

		// Nothing selectable is configured — fall back to the legacy feed/external
		// behaviour so feed-only installs keep working.
		return in_array( $default, array( 'external', 'feed' ), true ) ? $default : 'external';
	}

	/**
	 * Resolve the effective event source for a reservation-backed event.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return string
	 */
	// C7.A: promoted to public so eem_resolve_cancellation_policy() in
	// includes/eem-cancellation-policy.php can dispatch event-default
	// lookups by source. Pure read function; no side effects.
	public static function get_effective_reservation_event_source( $reservation_id ) {
		$use_global_event_source = ! empty( get_post_meta( $reservation_id, '_en_use_global_event_source', true ) );
		$event_source            = sanitize_key( (string) get_post_meta( $reservation_id, '_en_event_source', true ) );
		$allowed_sources         = array( 'native', 'tec', 'feed', 'external' );

		if ( $use_global_event_source || ! in_array( $event_source, $allowed_sources, true ) ) {
			$event_source = self::get_default_event_source();
		}

		return in_array( $event_source, $allowed_sources, true ) ? $event_source : 'external';
	}

	/**
	 * Determine whether the site already contains The Events Calendar events.
	 *
	 * @return bool
	 */
	public static function has_existing_tec_events() {
		if ( ! self::is_tec_plugin_active() ) {
			return false;
		}

		$counts = wp_count_posts( 'tribe_events' );

		if ( ! $counts ) {
			return false;
		}

		$total = 0;

		foreach ( get_object_vars( $counts ) as $status => $count ) {
			if ( in_array( $status, array( 'auto-draft', 'trash' ), true ) ) {
				continue;
			}

			$total += (int) $count;
		}

		return $total > 0;
	}

	/**
	 * Get the frontend archive slug used for native events.
	 *
	 * Sites running The Events Calendar keep `/events` reserved for TEC.
	 *
	 * @return string
	 */
	public static function get_event_rewrite_slug() {
		if ( self::is_tec_plugin_active() ) {
			return self::SAFE_EVENT_ARCHIVE_SLUG;
		}

		return 'events';
	}

	/**
	 * Use the classic editor layout for native event content types.
	 *
	 * @param bool   $use_block_editor Whether the block editor should be used.
	 * @param string $post_type Post type key.
	 * @return bool
	 */
	public function filter_use_block_editor_for_post_type( $use_block_editor, $post_type ) {
		if ( in_array( $post_type, array( self::EVENT_POST_TYPE, self::VENUE_POST_TYPE, self::PRODUCER_POST_TYPE ), true ) ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Register native event content types.
	 *
	 * @return void
	 */
	public function register_content_types() {
		register_post_type(
			self::EVENT_POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Events', 'equine-event-manager' ),
					'singular_name'      => __( 'Event', 'equine-event-manager' ),
					'add_new'            => __( 'Add Event', 'equine-event-manager' ),
					'add_new_item'       => __( 'Add Event', 'equine-event-manager' ),
					'edit_item'          => __( 'Edit Event', 'equine-event-manager' ),
					'new_item'           => __( 'New Event', 'equine-event-manager' ),
					'view_item'          => __( 'View Event', 'equine-event-manager' ),
					'search_items'       => __( 'Search Events', 'equine-event-manager' ),
					'not_found'          => __( 'No events found.', 'equine-event-manager' ),
					'not_found_in_trash' => __( 'No events found in Trash.', 'equine-event-manager' ),
					'menu_name'          => __( 'Events', 'equine-event-manager' ),
				),
				'public'       => true,
				'show_ui'      => true,
				'show_in_menu' => false,
				'show_in_rest' => true,
				'has_archive'  => true,
				'rewrite'      => array(
					'slug' => self::get_event_rewrite_slug(),
				),
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
				'menu_icon'    => 'dashicons-calendar-alt',
			)
		);

		register_taxonomy(
			self::EVENT_CATEGORY_TAXONOMY,
			array( self::EVENT_POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => __( 'Event Categories', 'equine-event-manager' ),
					'singular_name' => __( 'Event Category', 'equine-event-manager' ),
					'search_items'  => __( 'Search Event Categories', 'equine-event-manager' ),
					'all_items'     => __( 'All Event Categories', 'equine-event-manager' ),
					'edit_item'     => __( 'Edit Event Category', 'equine-event-manager' ),
					'update_item'   => __( 'Update Event Category', 'equine-event-manager' ),
					'add_new_item'  => __( 'Add Event Category', 'equine-event-manager' ),
					'new_item_name' => __( 'New Event Category Name', 'equine-event-manager' ),
					'menu_name'     => __( 'Event Categories', 'equine-event-manager' ),
				),
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => array(
					'slug' => 'event-category',
				),
			)
		);

		register_taxonomy(
			self::EVENT_TAG_TAXONOMY,
			array( self::EVENT_POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => __( 'Tags', 'equine-event-manager' ),
					'singular_name' => __( 'Tag', 'equine-event-manager' ),
					'search_items'  => __( 'Search Tags', 'equine-event-manager' ),
					'all_items'     => __( 'All Tags', 'equine-event-manager' ),
					'edit_item'     => __( 'Edit Tag', 'equine-event-manager' ),
					'update_item'   => __( 'Update Tag', 'equine-event-manager' ),
					'add_new_item'  => __( 'Add New Tag', 'equine-event-manager' ),
					'new_item_name' => __( 'New Tag Name', 'equine-event-manager' ),
					'menu_name'     => __( 'Tags', 'equine-event-manager' ),
				),
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => array(
					'slug' => 'event-tag',
				),
			)
		);

		register_post_type(
			self::VENUE_POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Locations', 'equine-event-manager' ),
					'singular_name'      => __( 'Venue', 'equine-event-manager' ),
					'add_new'            => __( 'Add Location', 'equine-event-manager' ),
					'add_new_item'       => __( 'Add Location', 'equine-event-manager' ),
					'edit_item'          => __( 'Edit Venue', 'equine-event-manager' ),
					'new_item'           => __( 'New Venue', 'equine-event-manager' ),
					'view_item'          => __( 'View Venue', 'equine-event-manager' ),
					'search_items'       => __( 'Search Locations', 'equine-event-manager' ),
					'not_found'          => __( 'No locations found.', 'equine-event-manager' ),
					'not_found_in_trash' => __( 'No locations found in Trash.', 'equine-event-manager' ),
					'menu_name'          => __( 'Locations', 'equine-event-manager' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => false,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor' ),
				'menu_icon'    => 'dashicons-location-alt',
			)
		);

		register_post_type(
			self::PRODUCER_POST_TYPE,
			array(
				'labels'       => array(
					'name'               => __( 'Producers', 'equine-event-manager' ),
					'singular_name'      => __( 'Producer', 'equine-event-manager' ),
					'add_new'            => __( 'Add Producer', 'equine-event-manager' ),
					'add_new_item'       => __( 'Add Producer', 'equine-event-manager' ),
					'edit_item'          => __( 'Edit Producer', 'equine-event-manager' ),
					'new_item'           => __( 'New Producer', 'equine-event-manager' ),
					'view_item'          => __( 'View Producer', 'equine-event-manager' ),
					'search_items'       => __( 'Search Producers', 'equine-event-manager' ),
					'not_found'          => __( 'No producers found.', 'equine-event-manager' ),
					'not_found_in_trash' => __( 'No producers found in Trash.', 'equine-event-manager' ),
					'menu_name'          => __( 'Producers', 'equine-event-manager' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => false,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor' ),
				'menu_icon'    => 'dashicons-groups',
			)
		);

		register_taxonomy(
			self::VENUE_CATEGORY_TAXONOMY,
			array( self::VENUE_POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => __( 'Location Categories', 'equine-event-manager' ),
					'singular_name' => __( 'Location Category', 'equine-event-manager' ),
					'search_items'  => __( 'Search Location Categories', 'equine-event-manager' ),
					'all_items'     => __( 'All Location Categories', 'equine-event-manager' ),
					'edit_item'     => __( 'Edit Location Category', 'equine-event-manager' ),
					'update_item'   => __( 'Update Location Category', 'equine-event-manager' ),
					'add_new_item'  => __( 'Add Location Category', 'equine-event-manager' ),
					'new_item_name' => __( 'New Location Category Name', 'equine-event-manager' ),
					'menu_name'     => __( 'Categories', 'equine-event-manager' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
			)
		);

		register_taxonomy(
			self::PRODUCER_CATEGORY_TAXONOMY,
			array( self::PRODUCER_POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => __( 'Producer Categories', 'equine-event-manager' ),
					'singular_name' => __( 'Producer Category', 'equine-event-manager' ),
					'search_items'  => __( 'Search Producer Categories', 'equine-event-manager' ),
					'all_items'     => __( 'All Producer Categories', 'equine-event-manager' ),
					'edit_item'     => __( 'Edit Producer Category', 'equine-event-manager' ),
					'update_item'   => __( 'Update Producer Category', 'equine-event-manager' ),
					'add_new_item'  => __( 'Add Producer Category', 'equine-event-manager' ),
					'new_item_name' => __( 'New Producer Category Name', 'equine-event-manager' ),
					'menu_name'     => __( 'Categories', 'equine-event-manager' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
			)
		);
	}

	/**
	 * Register meta boxes for native content types.
	 *
	 * @return void
	 */
	public function register_meta_boxes( $post_type = '', $post = null ) {
		add_meta_box(
			'equine_event_manager_event_details',
			__( 'Event Details', 'equine-event-manager' ),
			array( $this, 'render_event_details_meta_box' ),
			self::EVENT_POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'equine_event_manager_venue_details',
			__( 'Venue Details', 'equine-event-manager' ),
			array( $this, 'render_venue_details_meta_box' ),
			self::VENUE_POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'equine_event_manager_producer_details',
			__( 'Producer Details', 'equine-event-manager' ),
			array( $this, 'render_producer_details_meta_box' ),
			self::PRODUCER_POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Register frontend shortcodes.
	 *
	 * @return void
	 */
	public function register_shortcodes() {
		add_shortcode( 'equine_event_manager_events', array( $this, 'render_events_shortcode' ) );
		add_shortcode( 'equine_event_manager_event', array( $this, 'render_event_shortcode' ) );
		// Canonical `en_` aliases (README §13 prefix) — the short, memorable
		// shortcodes the docs point admins at. They forward to the same handlers,
		// so [en_events view="list|month|map"] and [en_event id="N"] just work.
		add_shortcode( 'en_events', array( $this, 'render_events_shortcode' ) );
		add_shortcode( 'en_event', array( $this, 'render_event_shortcode' ) );
	}

	/**
	 * Register builder-agnostic virtual event routes for reservation-backed events.
	 *
	 * @return void
	 */
	public function register_event_routes() {
		add_rewrite_tag( '%eem_reservation_event%', '([0-9]+)' );
		add_rewrite_tag( '%eem_event_directory_type%', '([^&]+)' );
		add_rewrite_tag( '%eem_event_directory_slug%', '([^&]+)' );
		// Resolve on the TRAILING reservation id. An optional readable prefix
		// (e.g. "ntr-rapid-city-sd-") may precede it for human-friendly / SEO
		// URLs — the prefix is cosmetic and ignored. The same rule still matches
		// bare "/equine-event/6519/", so every link already sent to a customer
		// keeps working (no migration, no broken links).
		add_rewrite_rule(
			'^' . self::VIRTUAL_EVENT_ROUTE_BASE . '/(?:[^/]+-)?([0-9]+)/?$',
			'index.php?eem_reservation_event=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . self::get_event_rewrite_slug() . '/(' . self::DIRECTORY_LOCATION_ROUTE_BASE . '|' . self::DIRECTORY_PRODUCER_ROUTE_BASE . ')/([^/]+)/?$',
			'index.php?eem_event_directory_type=$matches[1]&eem_event_directory_slug=$matches[2]',
			'top'
		);
	}

	/**
	 * Register custom query vars for virtual event pages.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function filter_query_vars( $vars ) {
		$vars[] = 'eem_reservation_event';
		$vars[] = 'eem_event_directory_type';
		$vars[] = 'eem_event_directory_slug';

		return $vars;
	}

	/**
	 * Render a plugin-managed single event page for reservation-backed sources.
	 *
	 * @return void
	 */
	public function maybe_render_virtual_event_page() {
		$reservation_id = absint( get_query_var( 'eem_reservation_event' ) );
		$directory_type = sanitize_key( (string) get_query_var( 'eem_event_directory_type' ) );
		$directory_slug = sanitize_title( (string) get_query_var( 'eem_event_directory_slug' ) );

		// Hosting-resilient fallback. Some hosts (notably WP Engine) cache the
		// rewrite-rules table and don't honor a programmatic flush_rewrite_rules()
		// after a plugin update, so the pretty `/equine-event/{id}/` rule can be
		// absent and the query var empty — the URL then falls through to the front
		// page. Detect the reservation id straight from the request path so the
		// page still resolves without a permalink re-save. Matches the route base
		// as a path suffix, which also covers subdirectory installs.
		if ( ! $reservation_id && ! $directory_type && isset( $_SERVER['REQUEST_URI'] ) ) {
			$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
			if ( preg_match( '#/' . preg_quote( self::VIRTUAL_EVENT_ROUTE_BASE, '#' ) . '/(?:[^/]+-)?([0-9]+)/?$#', $path, $m ) ) {
				$reservation_id = absint( $m[1] );
			}
		}

		if ( ! $reservation_id && ! $directory_type ) {
			return;
		}

		if ( $directory_type ) {
			$this->render_event_directory_page( $directory_type, $directory_slug );
		}

		$event_data = self::get_normalized_reservation_event_data( $reservation_id );

		if ( empty( $event_data ) ) {
			global $wp_query;

			if ( $wp_query instanceof WP_Query ) {
				$wp_query->set_404();
			}

			status_header( 404 );
			return;
		}

		status_header( 200 );
		nocache_headers();

		// Enqueue the front-end stylesheet BEFORE get_header() fires wp_head, so
		// the <link> lands in <head>. render_event_shortcode() also enqueues it,
		// but that runs after get_header() — relying on WP's late-footer style
		// printing, which some hosts/themes (e.g. WP Engine + optimizer plugins)
		// drop, leaving the booking form unstyled (collapsed section headings).
		self::render_frontend_styles();

		add_filter(
			'document_title_parts',
			static function ( $parts ) use ( $event_data ) {
				$parts['title'] = ! empty( $event_data['title'] ) ? $event_data['title'] : __( 'Event', 'equine-event-manager' );

				return $parts;
			}
		);

		if ( function_exists( 'get_header' ) ) {
			get_header();
		}

		echo '<main class="equine-event-manager-virtual-event-page">';
		echo $this->render_event_shortcode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'reservation_id'   => $reservation_id,
				'show_content'     => 1,
				'show_reservation' => 1,
			)
		);
		echo '</main>';

		if ( function_exists( 'get_footer' ) ) {
			get_footer();
		}

		exit;
	}

	/**
	 * Render a plugin-managed directory page for venue- or producer-filtered event listings.
	 *
	 * @param string $directory_type Route type.
	 * @param string $directory_slug Normalized route slug.
	 * @return void
	 */
	private function render_event_directory_page( $directory_type, $directory_slug ) {
		$directory_type = sanitize_key( (string) $directory_type );
		$directory_slug = sanitize_title( (string) $directory_slug );

		if ( '' === $directory_slug || ! in_array( $directory_type, array( self::DIRECTORY_LOCATION_ROUTE_BASE, self::DIRECTORY_PRODUCER_ROUTE_BASE ), true ) ) {
			global $wp_query;

			if ( $wp_query instanceof WP_Query ) {
				$wp_query->set_404();
			}

			status_header( 404 );
			return;
		}

		$filter_attr   = self::DIRECTORY_LOCATION_ROUTE_BASE === $directory_type ? 'venue_name' : 'producer_name';
		$directory_label = $this->find_directory_label( $directory_type, $directory_slug );
		$page_title    = self::DIRECTORY_LOCATION_ROUTE_BASE === $directory_type
			? sprintf( __( 'Events at %s', 'equine-event-manager' ), $directory_label )
			: sprintf( __( 'Events by %s', 'equine-event-manager' ), $directory_label );

		status_header( 200 );
		nocache_headers();

		add_filter(
			'document_title_parts',
			static function ( $parts ) use ( $page_title ) {
				$parts['title'] = $page_title;

				return $parts;
			}
		);

		if ( function_exists( 'get_header' ) ) {
			get_header();
		}

		echo '<main class="equine-event-manager-virtual-event-page eem-event-directory-page">';
		echo '<div class="eem-event-directory-page__inner">';
		echo '<h1 class="eem-event-directory-page__title">' . esc_html( $page_title ) . '</h1>';
		echo wp_kses_post(
			$this->render_events_shortcode(
				array(
					'limit'         => 60,
					'view'          => 'list',
					'source'        => 'all',
					$filter_attr    => $directory_label,
				)
			)
		);
		echo '</div>';
		echo '</main>';

		if ( function_exists( 'get_footer' ) ) {
			get_footer();
		}

		exit;
	}

	/**
	 * Replace singular supported event content with the shared spotlight template.
	 *
	 * @param string $content Original post content.
	 * @return string
	 */
	public function filter_single_event_content( $content ) {
		if ( is_admin() || ! is_main_query() || ! in_the_loop() || self::$is_rendering_event_template ) {
			return $content;
		}

		// 2.3.50 — when the template_include takeover owns this request, the EEM
		// single-event template renders the page directly via the event
		// shortcode. The_content must stay inert so the body isn't injected twice.
		if ( self::$is_template_takeover ) {
			return $content;
		}

		// Let TEC render its own draft/scheduled preview unmolested. Without this,
		// a logged-in editor previewing an unpublished event would see the EEM
		// takeover instead of TEC's native preview.
		if ( is_preview() ) {
			return $content;
		}

		$post_id   = get_the_ID();
		$post_type = $post_id ? get_post_type( $post_id ) : '';

		if ( self::EVENT_POST_TYPE !== $post_type && 'tribe_events' !== $post_type ) {
			return $content;
		}

		if ( 'tribe_events' === $post_type && ! self::is_tec_integration_enabled() ) {
			return $content;
		}

		if ( ! is_singular( $post_type ) ) {
			return $content;
		}

		// FIX 3 (2.3.46) — Prevent double-render / "not available" collision.
		//
		// Two cases where this filter must stand down:
		//
		// 1. The raw post content already contains [en_reservation] — a non-Elementor
		//    page has the shortcode directly in the editor, so the reservation form is
		//    already rendering through the standard shortcode path. Injecting a second
		//    render via this filter produces a duplicate form OR a "Reservations are
		//    not available" notice (if the event-lookup path inside render_event_shortcode
		//    can't resolve the same reservation the shortcode did).
		//
		// 2. Elementor owns the template (_elementor_edit_mode = 'builder') — Elementor
		//    manages its own shortcode pipeline outside the_content. The [en_reservation]
		//    shortcode lives in Elementor's JSON data, not in $content, so has_shortcode()
		//    above returns false even though the form IS being rendered. This check covers
		//    Whitney's TEC + Elementor Single Event template setup specifically.
		if ( has_shortcode( $content, 'en_reservation' ) ) {
			return $content;
		}

		if ( $post_id && 'builder' === (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return $content;
		}

		self::$is_rendering_event_template = true;
		$markup = $this->render_event_shortcode(
			array(
				'id'               => $post_id,
				'show_content'     => 1,
				'show_reservation' => 1,
			)
		);
		self::$is_rendering_event_template = false;

		return $markup ? $markup : $content;
	}

	/**
	 * Hand WordPress the EEM single-event template for supported event singles.
	 *
	 * Fires on `template_include`. When the current request is a supported
	 * single event that EEM should own, returns the path to
	 * templates/single-event.php instead of the theme's template. Cedes to the
	 * theme / TEC / Elementor for every case the takeover must not handle
	 * (admin, non-main query, preview, password-protected, REST, feed,
	 * Elementor-built, or content that already mounts the shortcode itself).
	 *
	 * @param string $template Template path the theme resolved.
	 * @return string EEM template path when taking over, else the original.
	 */
	public function filter_single_event_template( $template ) {
		if ( ! $this->should_take_over_event_template() ) {
			return $template;
		}

		$eem_template = EQUINE_EVENT_MANAGER_PATH . 'templates/single-event.php';

		if ( ! file_exists( $eem_template ) ) {
			return $template;
		}

		self::$is_template_takeover = true;

		return $eem_template;
	}

	/**
	 * Decide whether the template_include takeover should own this request.
	 *
	 * @return bool
	 */
	private function should_take_over_event_template() {
		if ( is_admin() || ! is_main_query() ) {
			return false;
		}

		// Cede REST and feed requests — these are not browser page views.
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
			return false;
		}

		$queried = get_queried_object();

		if ( ! $queried instanceof WP_Post ) {
			return false;
		}

		$post_id   = (int) $queried->ID;
		$post_type = (string) $queried->post_type;

		if ( self::EVENT_POST_TYPE !== $post_type && 'tribe_events' !== $post_type ) {
			return false;
		}

		if ( ! is_singular( $post_type ) ) {
			return false;
		}

		if ( 'tribe_events' === $post_type && ! self::is_tec_integration_enabled() ) {
			return false;
		}

		// Cede TEC's native draft/scheduled preview to TEC.
		if ( is_preview() ) {
			return false;
		}

		// Cede password-protected posts to the theme's password form.
		if ( post_password_required( $queried ) ) {
			return false;
		}

		// Cede when Elementor owns the template — it runs its own pipeline.
		if ( 'builder' === (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return false;
		}

		// Cede when the content already mounts [en_reservation] itself, so the
		// shortcode path renders the form once without a duplicate.
		if ( has_shortcode( (string) $queried->post_content, 'en_reservation' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Register frontend widgets.
	 *
	 * @return void
	 */
	public function register_widgets() {
		register_widget( 'EEM_Upcoming_Events_Widget' );
		register_widget( 'EEM_Featured_Event_Widget' );
	}

	/**
	 * Save native event metadata.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function save_event_meta( $post_id, $post ) {
		if ( ! $this->can_save_post_meta( $post_id, $post, self::EVENT_POST_TYPE, self::EVENT_META_NONCE, 'equine_event_manager_save_event_meta' ) ) {
			return;
		}

		$start_date = $this->sanitize_date_value( isset( $_POST['equine_event_manager_event_start_date'] ) ? wp_unslash( $_POST['equine_event_manager_event_start_date'] ) : '' );
		$end_date   = $this->sanitize_date_value( isset( $_POST['equine_event_manager_event_end_date'] ) ? wp_unslash( $_POST['equine_event_manager_event_end_date'] ) : '' );

		if ( $start_date && $end_date && $end_date < $start_date ) {
			$end_date = $start_date;
		}

		update_post_meta( $post_id, '_equine_event_manager_event_start_date', $start_date );
		update_post_meta( $post_id, '_equine_event_manager_event_end_date', $end_date ? $end_date : $start_date );
		update_post_meta( $post_id, '_equine_event_manager_event_venue_id', absint( isset( $_POST['equine_event_manager_event_venue_id'] ) ? wp_unslash( $_POST['equine_event_manager_event_venue_id'] ) : 0 ) );
		update_post_meta( $post_id, '_equine_event_manager_event_producer_id', absint( isset( $_POST['equine_event_manager_event_producer_id'] ) ? wp_unslash( $_POST['equine_event_manager_event_producer_id'] ) : 0 ) );
		update_post_meta( $post_id, '_equine_event_manager_event_flyer_file_id', absint( isset( $_POST['equine_event_manager_event_flyer_file_id'] ) ? wp_unslash( $_POST['equine_event_manager_event_flyer_file_id'] ) : 0 ) );
		update_post_meta( $post_id, '_equine_event_manager_event_location_label', sanitize_text_field( isset( $_POST['equine_event_manager_event_location_label'] ) ? wp_unslash( $_POST['equine_event_manager_event_location_label'] ) : '' ) );
		update_post_meta( $post_id, '_equine_event_manager_event_cta_label', sanitize_text_field( isset( $_POST['equine_event_manager_event_cta_label'] ) ? wp_unslash( $_POST['equine_event_manager_event_cta_label'] ) : __( 'Reserve Now', 'equine-event-manager' ) ) );
		update_post_meta( $post_id, '_equine_event_manager_event_featured', empty( $_POST['equine_event_manager_event_featured'] ) ? 0 : 1 );

		// Social links (canonical _en_ keys). Stored as full URLs; blank clears.
		update_post_meta( $post_id, '_en_event_facebook', esc_url_raw( isset( $_POST['en_event_facebook'] ) ? wp_unslash( $_POST['en_event_facebook'] ) : '' ) );
		update_post_meta( $post_id, '_en_event_instagram', esc_url_raw( isset( $_POST['en_event_instagram'] ) ? wp_unslash( $_POST['en_event_instagram'] ) : '' ) );
	}

	/**
	 * Save venue metadata.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function save_venue_meta( $post_id, $post ) {
		if ( ! $this->can_save_post_meta( $post_id, $post, self::VENUE_POST_TYPE, self::VENUE_META_NONCE, 'equine_event_manager_save_venue_meta' ) ) {
			return;
		}

		$fields = array(
			'address_1',
			'address_2',
			'city',
			'state',
			'postal_code',
			'phone',
			'website',
		);

		foreach ( $fields as $field ) {
			$value = isset( $_POST[ 'equine_event_manager_venue_' . $field ] ) ? wp_unslash( $_POST[ 'equine_event_manager_venue_' . $field ] ) : '';
			if ( 'website' === $field ) {
				$value = esc_url_raw( $value );
			} else {
				$value = sanitize_text_field( $value );
			}
			update_post_meta( $post_id, '_equine_event_manager_venue_' . $field, $value );
		}

		// Coordinates (Slice 3). A valid manual lat/lng pair always wins; clearing
		// it falls back to auto-geocoding the address via Google (when a Maps key
		// is configured). The geocoded-address cache marker is reset on manual
		// entry so a later blank re-triggers geocoding.
		$manual_lat = isset( $_POST['en_venue_lat'] ) ? trim( (string) wp_unslash( $_POST['en_venue_lat'] ) ) : '';
		$manual_lng = isset( $_POST['en_venue_lng'] ) ? trim( (string) wp_unslash( $_POST['en_venue_lng'] ) ) : '';
		if ( '' !== $manual_lat && '' !== $manual_lng && is_numeric( $manual_lat ) && is_numeric( $manual_lng ) ) {
			update_post_meta( $post_id, '_en_venue_lat', (float) $manual_lat );
			update_post_meta( $post_id, '_en_venue_lng', (float) $manual_lng );
			update_post_meta( $post_id, '_en_venue_geocoded_address', '' );
		} else {
			self::maybe_geocode_venue( $post_id );
		}
	}

	/**
	 * Save producer metadata.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function save_producer_meta( $post_id, $post ) {
		if ( ! $this->can_save_post_meta( $post_id, $post, self::PRODUCER_POST_TYPE, self::PRODUCER_META_NONCE, 'equine_event_manager_save_producer_meta' ) ) {
			return;
		}

		$fields = array(
			'contact_name',
			'email',
			'phone',
			'website',
		);

		foreach ( $fields as $field ) {
			$value = isset( $_POST[ 'equine_event_manager_producer_' . $field ] ) ? wp_unslash( $_POST[ 'equine_event_manager_producer_' . $field ] ) : '';

			if ( 'website' === $field ) {
				$value = esc_url_raw( $value );
			} elseif ( 'email' === $field ) {
				$value = sanitize_email( $value );
			} else {
				$value = sanitize_text_field( $value );
			}

			update_post_meta( $post_id, '_equine_event_manager_producer_' . $field, $value );
		}
	}

	/**
	 * Count native events linked to a venue or producer record.
	 *
	 * @param string $meta_key Event meta key.
	 * @param int    $post_id Related venue or producer post ID.
	 * @return int
	 */
	private function count_linked_native_events( $meta_key, $post_id ) {
		if ( ! $post_id ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::EVENT_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => $meta_key,
				'meta_value'     => $post_id,
				'no_found_rows'  => false,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Render native event detail fields.
	 *
	 * @param WP_Post $post Current event.
	 * @return void
	 */
	public function render_event_details_meta_box( $post ) {
		wp_nonce_field( 'equine_event_manager_save_event_meta', self::EVENT_META_NONCE );

		$start_date         = (string) get_post_meta( $post->ID, '_equine_event_manager_event_start_date', true );
		$end_date           = (string) get_post_meta( $post->ID, '_equine_event_manager_event_end_date', true );
		$venue_id           = absint( get_post_meta( $post->ID, '_equine_event_manager_event_venue_id', true ) );
		$producer_id        = absint( get_post_meta( $post->ID, '_equine_event_manager_event_producer_id', true ) );
		$flyer_file_id      = absint( get_post_meta( $post->ID, '_equine_event_manager_event_flyer_file_id', true ) );
		$location_label     = (string) get_post_meta( $post->ID, '_equine_event_manager_event_location_label', true );
		$cta_label          = (string) get_post_meta( $post->ID, '_equine_event_manager_event_cta_label', true );
		$facebook_url       = (string) get_post_meta( $post->ID, '_en_event_facebook', true );
		$instagram_url      = (string) get_post_meta( $post->ID, '_en_event_instagram', true );
		$featured           = (int) get_post_meta( $post->ID, '_equine_event_manager_event_featured', true );
		$venues             = $this->get_posts_for_select( self::VENUE_POST_TYPE );
		$producers          = $this->get_posts_for_select( self::PRODUCER_POST_TYPE );
		$flyer_url          = $flyer_file_id ? wp_get_attachment_url( $flyer_file_id ) : '';
		$flyer_label        = $flyer_file_id ? wp_basename( get_attached_file( $flyer_file_id ) ) : '';
		$reservation_id     = absint( get_post_meta( $post->ID, '_equine_event_manager_reservation_id', true ) );
		$reservation        = $reservation_id ? get_post( $reservation_id ) : null;
		$event_url          = get_permalink( $post );
		$reservation_url = $reservation_id ? get_edit_post_link( $reservation_id, 'raw' ) : '';
		$venue_edit_url     = $venue_id ? get_edit_post_link( $venue_id, 'raw' ) : '';
		$producer_edit_url  = $producer_id ? get_edit_post_link( $producer_id, 'raw' ) : '';
		$featured_image_id  = get_post_thumbnail_id( $post->ID );
		$category_terms     = get_the_terms( $post->ID, self::EVENT_CATEGORY_TAXONOMY );
		$tag_terms          = get_the_terms( $post->ID, self::EVENT_TAG_TAXONOMY );
		$category_count     = is_array( $category_terms ) ? count( $category_terms ) : 0;
		$tag_count          = is_array( $tag_terms ) ? count( $tag_terms ) : 0;
		$content_word_count = str_word_count( wp_strip_all_tags( (string) $post->post_content ) );
		$has_description    = $content_word_count > 0;
		$date_snapshot      = __( 'Dates not set yet', 'equine-event-manager' );
		$location_snapshot  = __( 'Select both to finish the event stack', 'equine-event-manager' );

		if ( $start_date || $end_date ) {
			$date_parts = array_filter(
				array(
					$start_date,
					$end_date && $end_date !== $start_date ? $end_date : '',
				)
			);
			$date_snapshot = implode( ' - ', $date_parts );
		}

		if ( $venue_id && $producer_id ) {
			$location_snapshot = __( 'Venue and producer are connected', 'equine-event-manager' );
		} elseif ( $venue_id || $producer_id ) {
			$location_snapshot = __( 'One partner selected', 'equine-event-manager' );
		}
		?>
		<div class="eem-event-editor-card">
			<div class="eem-event-editor-card__intro">
				<div>
					<span class="eem-event-editor-card__eyebrow"><?php esc_html_e( 'Event Setup', 'equine-event-manager' ); ?></span>
					<h3><?php esc_html_e( 'Build The Reservation-Facing Event', 'equine-event-manager' ); ?></h3>
					<p><?php esc_html_e( 'Use this area to set the event dates, venue, producer, action button, and flyer so the event page feels like a polished reservation experience instead of a standard post.', 'equine-event-manager' ); ?></p>
					<div class="eem-event-editor-card__status-row">
						<span class="eem-event-editor-card__status-pill<?php echo $reservation ? ' is-linked' : ' is-unlinked'; ?>">
							<?php echo esc_html( $reservation ? __( 'Linked Reservation Ready', 'equine-event-manager' ) : __( 'Link a Reservation', 'equine-event-manager' ) ); ?>
						</span>
						<?php if ( $featured ) : ?>
							<span class="eem-event-editor-card__status-pill is-featured"><?php esc_html_e( 'Featured Event', 'equine-event-manager' ); ?></span>
						<?php endif; ?>
						<?php if ( $featured_image_id ) : ?>
							<span class="eem-event-editor-card__status-pill is-media"><?php esc_html_e( 'Featured Image Ready', 'equine-event-manager' ); ?></span>
						<?php endif; ?>
						<?php if ( $flyer_file_id ) : ?>
							<span class="eem-event-editor-card__status-pill is-flyer"><?php esc_html_e( 'Flyer Attached', 'equine-event-manager' ); ?></span>
						<?php endif; ?>
						<span class="eem-event-editor-card__status-pill<?php echo $has_description ? ' is-ready' : ' is-unlinked'; ?>">
							<?php echo esc_html( $has_description ? __( 'Description Ready', 'equine-event-manager' ) : __( 'Add Description', 'equine-event-manager' ) ); ?>
						</span>
					</div>
					<div class="eem-event-editor-card__actions">
						<?php if ( $event_url ) : ?>
							<a class="eem-event-editor-card__action eem-event-editor-card__action--primary" href="<?php echo esc_url( $event_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview Event Page', 'equine-event-manager' ); ?></a>
						<?php endif; ?>
						<?php if ( $reservation_url ) : ?>
							<a class="eem-event-editor-card__action" href="<?php echo esc_url( $reservation_url ); ?>"><?php esc_html_e( 'Edit Linked Reservation', 'equine-event-manager' ); ?></a>
						<?php endif; ?>
						<?php if ( $venue_edit_url ) : ?>
							<a class="eem-event-editor-card__action" href="<?php echo esc_url( $venue_edit_url ); ?>"><?php esc_html_e( 'Manage Venue', 'equine-event-manager' ); ?></a>
						<?php endif; ?>
						<?php if ( $producer_edit_url ) : ?>
							<a class="eem-event-editor-card__action" href="<?php echo esc_url( $producer_edit_url ); ?>"><?php esc_html_e( 'Manage Producer', 'equine-event-manager' ); ?></a>
						<?php endif; ?>
						<?php if ( $flyer_url ) : ?>
							<a class="eem-event-editor-card__action" href="<?php echo esc_url( $flyer_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Flyer PDF', 'equine-event-manager' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
				<div class="eem-event-editor-card__meta">
					<span class="eem-event-editor-card__meta-label"><?php esc_html_e( 'Linked Reservation', 'equine-event-manager' ); ?></span>
					<strong><?php echo esc_html( $reservation ? $reservation->post_title : __( 'Not linked yet', 'equine-event-manager' ) ); ?></strong>
					<span class="eem-event-editor-card__meta-label"><?php esc_html_e( 'Event Dates', 'equine-event-manager' ); ?></span>
					<strong><?php echo esc_html( $date_snapshot ); ?></strong>
					<span class="eem-event-editor-card__meta-label"><?php esc_html_e( 'Location Stack', 'equine-event-manager' ); ?></span>
					<strong><?php echo esc_html( $location_snapshot ); ?></strong>
				</div>
			</div>

			<div class="eem-event-editor-card__summary-grid">
				<div class="eem-event-editor-card__summary-card">
					<span class="eem-event-editor-card__summary-label"><?php esc_html_e( 'Content', 'equine-event-manager' ); ?></span>
					<strong class="eem-event-editor-card__summary-value"><?php echo esc_html( $has_description ? __( 'Description ready', 'equine-event-manager' ) : __( 'Needs description', 'equine-event-manager' ) ); ?></strong>
					<span class="eem-event-editor-card__summary-note">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d word count. */
								_n( '%d word in the event description', '%d words in the event description', $content_word_count, 'equine-event-manager' ),
								$content_word_count
							)
						);
						?>
					</span>
				</div>
				<div class="eem-event-editor-card__summary-card">
					<span class="eem-event-editor-card__summary-label"><?php esc_html_e( 'Media', 'equine-event-manager' ); ?></span>
					<strong class="eem-event-editor-card__summary-value"><?php echo esc_html( $featured_image_id ? __( 'Featured image ready', 'equine-event-manager' ) : __( 'Add featured image', 'equine-event-manager' ) ); ?></strong>
					<span class="eem-event-editor-card__summary-note"><?php echo esc_html( $flyer_file_id ? __( 'Flyer PDF is attached', 'equine-event-manager' ) : __( 'Flyer PDF is optional', 'equine-event-manager' ) ); ?></span>
				</div>
				<div class="eem-event-editor-card__summary-card">
					<span class="eem-event-editor-card__summary-label"><?php esc_html_e( 'Taxonomies', 'equine-event-manager' ); ?></span>
					<strong class="eem-event-editor-card__summary-value"><?php echo esc_html( sprintf( _n( '%d category', '%d categories', $category_count, 'equine-event-manager' ), $category_count ) ); ?></strong>
					<span class="eem-event-editor-card__summary-note"><?php echo esc_html( sprintf( _n( '%d tag attached', '%d tags attached', $tag_count, 'equine-event-manager' ), $tag_count ) ); ?></span>
				</div>
				<div class="eem-event-editor-card__summary-card">
					<span class="eem-event-editor-card__summary-label"><?php esc_html_e( 'Connections', 'equine-event-manager' ); ?></span>
					<strong class="eem-event-editor-card__summary-value"><?php echo esc_html( $reservation ? __( 'Reservation linked', 'equine-event-manager' ) : __( 'Reservation optional', 'equine-event-manager' ) ); ?></strong>
					<span class="eem-event-editor-card__summary-note"><?php echo esc_html( $location_snapshot ); ?></span>
				</div>
			</div>

			<div class="eem-event-editor-card__quicklinks">
				<a class="eem-event-editor-card__quicklink" href="#titlediv"><?php esc_html_e( 'Jump To Title', 'equine-event-manager' ); ?></a>
				<a class="eem-event-editor-card__quicklink" href="#postdivrich"><?php esc_html_e( 'Jump To Description', 'equine-event-manager' ); ?></a>
				<a class="eem-event-editor-card__quicklink" href="#postimagediv"><?php esc_html_e( 'Open Featured Image', 'equine-event-manager' ); ?></a>
				<a class="eem-event-editor-card__quicklink" href="#en_event_categorydiv"><?php esc_html_e( 'Open Categories', 'equine-event-manager' ); ?></a>
				<a class="eem-event-editor-card__quicklink" href="#tagsdiv-en_event_tag"><?php esc_html_e( 'Open Tags', 'equine-event-manager' ); ?></a>
				<a class="eem-event-editor-card__quicklink" href="#submitdiv"><?php esc_html_e( 'Open Publish Tools', 'equine-event-manager' ); ?></a>
			</div>

			<div class="eem-event-editor-grid">
				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Start Date', 'equine-event-manager' ); ?></span>
					<input type="date" id="equine_event_manager_event_start_date" name="equine_event_manager_event_start_date" value="<?php echo esc_attr( $start_date ); ?>" />
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'End Date', 'equine-event-manager' ); ?></span>
					<input type="date" id="equine_event_manager_event_end_date" name="equine_event_manager_event_end_date" value="<?php echo esc_attr( $end_date ); ?>" />
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Venue', 'equine-event-manager' ); ?></span>
					<select id="equine_event_manager_event_venue_id" name="equine_event_manager_event_venue_id">
						<option value="0"><?php esc_html_e( 'Select a venue', 'equine-event-manager' ); ?></option>
						<?php foreach ( $venues as $venue ) : ?>
							<option value="<?php echo esc_attr( $venue->ID ); ?>" <?php selected( $venue_id, $venue->ID ); ?>><?php echo esc_html( $venue->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Producer', 'equine-event-manager' ); ?></span>
					<select id="equine_event_manager_event_producer_id" name="equine_event_manager_event_producer_id">
						<option value="0"><?php esc_html_e( 'Select a producer', 'equine-event-manager' ); ?></option>
						<?php foreach ( $producers as $producer ) : ?>
							<option value="<?php echo esc_attr( $producer->ID ); ?>" <?php selected( $producer_id, $producer->ID ); ?>><?php echo esc_html( $producer->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="eem-event-editor-field eem-event-editor-field--full">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Location Override', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_event_location_label" name="equine_event_manager_event_location_label" value="<?php echo esc_attr( $location_label ); ?>" />
					<span class="eem-event-editor-field__description"><?php esc_html_e( 'Optional location label shown on event cards if you want something more specific than the venue city/state.', 'equine-event-manager' ); ?></span>
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Button Label', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_event_cta_label" name="equine_event_manager_event_cta_label" value="<?php echo esc_attr( $cta_label ? $cta_label : __( 'Reserve Now', 'equine-event-manager' ) ); ?>" />
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Facebook URL', 'equine-event-manager' ); ?></span>
					<input type="url" class="regular-text" id="en_event_facebook" name="en_event_facebook" value="<?php echo esc_attr( $facebook_url ); ?>" placeholder="https://facebook.com/…" />
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Instagram URL', 'equine-event-manager' ); ?></span>
					<input type="url" class="regular-text" id="en_event_instagram" name="en_event_instagram" value="<?php echo esc_attr( $instagram_url ); ?>" placeholder="https://instagram.com/…" />
				</label>

				<div class="eem-event-editor-field eem-event-editor-field--full">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Event Flyer PDF', 'equine-event-manager' ); ?></span>
					<div>
						<input type="hidden" id="equine_event_manager_event_flyer_file_id" name="equine_event_manager_event_flyer_file_id" value="<?php echo esc_attr( $flyer_file_id ); ?>" />
						<input type="text" class="regular-text" value="<?php echo esc_attr( $flyer_label ); ?>" readonly="readonly" placeholder="<?php esc_attr_e( 'No file selected', 'equine-event-manager' ); ?>" />
						<button type="button" class="button"><?php esc_html_e( 'Add File', 'equine-event-manager' ); ?></button>
						<button type="button" class="eem-icon-delete-button" aria-label="<?php esc_attr_e( 'Remove flyer file', 'equine-event-manager' ); ?>" title="<?php esc_attr_e( 'Remove flyer file', 'equine-event-manager' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
						<?php if ( $flyer_url ) : ?>
							<a href="<?php echo esc_url( $flyer_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View file', 'equine-event-manager' ); ?></a>
						<?php else : ?>
							<a href="#" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e( 'View file', 'equine-event-manager' ); ?></a>
						<?php endif; ?>
					</div>
					<span class="eem-event-editor-field__description"><?php esc_html_e( 'Upload the event flyer PDF customers should be able to open from the event page.', 'equine-event-manager' ); ?></span>
				</div>

				<div class="eem-event-editor-field eem-event-editor-field--full">
					<label class="eem-event-editor-toggle">
						<span class="eem-event-editor-toggle__copy">
							<span class="eem-event-editor-field__label"><?php esc_html_e( 'Featured Event', 'equine-event-manager' ); ?></span>
							<span class="eem-event-editor-field__description"><?php esc_html_e( 'Use this event in featured widgets and shortcodes.', 'equine-event-manager' ); ?></span>
						</span>
						<span class="eem-inline-toggle-control">
							<input type="checkbox" name="equine_event_manager_event_featured" value="1" <?php checked( $featured, 1 ); ?> />
							<span class="eem-inline-toggle-control__track" aria-hidden="true"></span>
						</span>
					</label>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render venue detail fields.
	 *
	 * @param WP_Post $post Current venue.
	 * @return void
	 */
	public function render_venue_details_meta_box( $post ) {
		wp_nonce_field( 'equine_event_manager_save_venue_meta', self::VENUE_META_NONCE );

		$address_1          = (string) get_post_meta( $post->ID, '_equine_event_manager_venue_address_1', true );
		$address_2          = (string) get_post_meta( $post->ID, '_equine_event_manager_venue_address_2', true );
		$city               = (string) get_post_meta( $post->ID, '_equine_event_manager_venue_city', true );
		$state              = (string) get_post_meta( $post->ID, '_equine_event_manager_venue_state', true );
		$postal_code        = (string) get_post_meta( $post->ID, '_equine_event_manager_venue_postal_code', true );
		$phone              = (string) get_post_meta( $post->ID, '_equine_event_manager_venue_phone', true );
		$website            = (string) get_post_meta( $post->ID, '_equine_event_manager_venue_website', true );
		$venue_lat          = (string) get_post_meta( $post->ID, '_en_venue_lat', true );
		$venue_lng          = (string) get_post_meta( $post->ID, '_en_venue_lng', true );
		$maps_key_set       = '' !== self::get_google_maps_api_key();
		$linked_event_count = $this->count_linked_native_events( '_equine_event_manager_event_venue_id', $post->ID );
		$location_label     = trim( implode( ', ', array_filter( array( $city, $state ) ) ) );
		$has_address        = '' !== $address_1 || '' !== $address_2;
		$website_url        = $website ? esc_url( $website ) : '';
		?>
		<div class="eem-event-editor-card">
			<div class="eem-event-editor-card__intro">
				<div>
					<span class="eem-event-editor-card__eyebrow"><?php esc_html_e( 'Venue Setup', 'equine-event-manager' ); ?></span>
					<h3><?php esc_html_e( 'Shape The Location Experience', 'equine-event-manager' ); ?></h3>
					<p><?php esc_html_e( 'Set the venue address, phone, and website so linked events inherit a complete destination for exhibitors and customers.', 'equine-event-manager' ); ?></p>
					<div class="eem-event-editor-card__status-row">
						<span class="eem-event-editor-card__status-pill<?php echo $has_address ? ' is-ready' : ' is-unlinked'; ?>">
							<?php echo esc_html( $has_address ? __( 'Address Ready', 'equine-event-manager' ) : __( 'Add Venue Address', 'equine-event-manager' ) ); ?>
						</span>
						<span class="eem-event-editor-card__status-pill<?php echo $phone ? ' is-ready' : ' is-unlinked'; ?>">
							<?php echo esc_html( $phone ? __( 'Phone Ready', 'equine-event-manager' ) : __( 'Add Venue Phone', 'equine-event-manager' ) ); ?>
						</span>
						<?php if ( $website ) : ?>
							<span class="eem-event-editor-card__status-pill is-media"><?php esc_html_e( 'Website Linked', 'equine-event-manager' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="eem-event-editor-card__actions">
						<a class="eem-event-editor-card__action" href="#titlediv"><?php esc_html_e( 'Jump To Title', 'equine-event-manager' ); ?></a>
						<a class="eem-event-editor-card__action" href="#postdivrich"><?php esc_html_e( 'Jump To Description', 'equine-event-manager' ); ?></a>
						<?php if ( $website_url ) : ?>
							<a class="eem-event-editor-card__action eem-event-editor-card__action--primary" href="<?php echo esc_url( $website_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Venue Website', 'equine-event-manager' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
				<div class="eem-event-editor-card__meta">
					<span class="eem-event-editor-card__meta-label"><?php esc_html_e( 'Linked Events', 'equine-event-manager' ); ?></span>
					<strong><?php echo esc_html( sprintf( _n( '%d event uses this venue', '%d events use this venue', $linked_event_count, 'equine-event-manager' ), $linked_event_count ) ); ?></strong>
					<span class="eem-event-editor-card__meta-label"><?php esc_html_e( 'Location Snapshot', 'equine-event-manager' ); ?></span>
					<strong><?php echo esc_html( $location_label ? $location_label : __( 'Add city and state', 'equine-event-manager' ) ); ?></strong>
					<span class="eem-event-editor-card__meta-label"><?php esc_html_e( 'Contact Status', 'equine-event-manager' ); ?></span>
					<strong><?php echo esc_html( $phone ? __( 'Venue phone available', 'equine-event-manager' ) : __( 'Add venue phone', 'equine-event-manager' ) ); ?></strong>
				</div>
			</div>

			<div class="eem-event-editor-card__summary-grid">
				<div class="eem-event-editor-card__summary-card">
					<span class="eem-event-editor-card__summary-label"><?php esc_html_e( 'Address', 'equine-event-manager' ); ?></span>
					<strong class="eem-event-editor-card__summary-value"><?php echo esc_html( $has_address ? __( 'Physical address set', 'equine-event-manager' ) : __( 'Needs street address', 'equine-event-manager' ) ); ?></strong>
					<span class="eem-event-editor-card__summary-note"><?php echo esc_html( $address_1 ? $address_1 : __( 'Add address line 1 to complete the venue profile.', 'equine-event-manager' ) ); ?></span>
				</div>
				<div class="eem-event-editor-card__summary-card">
					<span class="eem-event-editor-card__summary-label"><?php esc_html_e( 'Region', 'equine-event-manager' ); ?></span>
					<strong class="eem-event-editor-card__summary-value"><?php echo esc_html( $location_label ? __( 'Location ready', 'equine-event-manager' ) : __( 'Needs city and state', 'equine-event-manager' ) ); ?></strong>
					<span class="eem-event-editor-card__summary-note"><?php echo esc_html( $location_label ? $location_label : __( 'Venue cards use this location for linked events.', 'equine-event-manager' ) ); ?></span>
				</div>
				<div class="eem-event-editor-card__summary-card">
					<span class="eem-event-editor-card__summary-label"><?php esc_html_e( 'Website', 'equine-event-manager' ); ?></span>
					<strong class="eem-event-editor-card__summary-value"><?php echo esc_html( $website ? __( 'External link ready', 'equine-event-manager' ) : __( 'Website optional', 'equine-event-manager' ) ); ?></strong>
					<span class="eem-event-editor-card__summary-note"><?php echo esc_html( $website ? $website : __( 'Useful for maps, facility info, or venue policies.', 'equine-event-manager' ) ); ?></span>
				</div>
				<div class="eem-event-editor-card__summary-card">
					<span class="eem-event-editor-card__summary-label"><?php esc_html_e( 'Connected Events', 'equine-event-manager' ); ?></span>
					<strong class="eem-event-editor-card__summary-value"><?php echo esc_html( number_format_i18n( $linked_event_count ) ); ?></strong>
					<span class="eem-event-editor-card__summary-note"><?php esc_html_e( 'Linked native events will inherit this venue automatically.', 'equine-event-manager' ); ?></span>
				</div>
			</div>

			<div class="eem-event-editor-grid">
				<label class="eem-event-editor-field eem-event-editor-field--full">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Address Line 1', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_venue_address_1" name="equine_event_manager_venue_address_1" value="<?php echo esc_attr( $address_1 ); ?>" />
				</label>

				<label class="eem-event-editor-field eem-event-editor-field--full">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Address Line 2', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_venue_address_2" name="equine_event_manager_venue_address_2" value="<?php echo esc_attr( $address_2 ); ?>" />
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'City', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_venue_city" name="equine_event_manager_venue_city" value="<?php echo esc_attr( $city ); ?>" />
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'State', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_venue_state" name="equine_event_manager_venue_state" value="<?php echo esc_attr( $state ); ?>" />
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Postal Code', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_venue_postal_code" name="equine_event_manager_venue_postal_code" value="<?php echo esc_attr( $postal_code ); ?>" />
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Phone', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_venue_phone" name="equine_event_manager_venue_phone" value="<?php echo esc_attr( $phone ); ?>" />
				</label>

				<label class="eem-event-editor-field eem-event-editor-field--full">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Website', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_venue_website" name="equine_event_manager_venue_website" value="<?php echo esc_attr( $website ); ?>" />
					<span class="eem-event-editor-field__description"><?php esc_html_e( 'Use the full URL if linked events should send visitors to the venue website.', 'equine-event-manager' ); ?></span>
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Latitude', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="en_venue_lat" name="en_venue_lat" value="<?php echo esc_attr( $venue_lat ); ?>" placeholder="<?php esc_attr_e( 'Auto-filled from address', 'equine-event-manager' ); ?>" />
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Longitude', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="en_venue_lng" name="en_venue_lng" value="<?php echo esc_attr( $venue_lng ); ?>" placeholder="<?php esc_attr_e( 'Auto-filled from address', 'equine-event-manager' ); ?>" />
				</label>

				<p class="eem-event-editor-field eem-event-editor-field--full eem-event-editor-field__description" style="margin:0;">
					<?php
					if ( $maps_key_set ) {
						esc_html_e( 'Coordinates power the events map view. Leave Latitude/Longitude blank to auto-fill them from the address above when you save (via Google). Enter values manually to override.', 'equine-event-manager' );
					} else {
						esc_html_e( 'Add a Google Maps API key under Settings → Integrations to auto-fill coordinates from the address, or enter Latitude/Longitude manually to place this venue on the events map.', 'equine-event-manager' );
					}
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render producer detail fields.
	 *
	 * @param WP_Post $post Current producer.
	 * @return void
	 */
	public function render_producer_details_meta_box( $post ) {
		wp_nonce_field( 'equine_event_manager_save_producer_meta', self::PRODUCER_META_NONCE );

		$contact_name       = (string) get_post_meta( $post->ID, '_equine_event_manager_producer_contact_name', true );
		$email              = (string) get_post_meta( $post->ID, '_equine_event_manager_producer_email', true );
		$phone              = (string) get_post_meta( $post->ID, '_equine_event_manager_producer_phone', true );
		$website            = (string) get_post_meta( $post->ID, '_equine_event_manager_producer_website', true );
		$linked_event_count = $this->count_linked_native_events( '_equine_event_manager_event_producer_id', $post->ID );
		$website_url        = $website ? esc_url( $website ) : '';
		$contact_ready      = $contact_name || $email || $phone;
		?>
		<div class="eem-event-editor-card">
			<div class="eem-event-editor-card__intro">
				<div>
					<span class="eem-event-editor-card__eyebrow"><?php esc_html_e( 'Producer Setup', 'equine-event-manager' ); ?></span>
					<h3><?php esc_html_e( 'Define The Organizer Profile', 'equine-event-manager' ); ?></h3>
					<p><?php esc_html_e( 'Use this profile to supply the producer contact details and website that linked events will surface on the frontend experience.', 'equine-event-manager' ); ?></p>
					<div class="eem-event-editor-card__status-row">
						<span class="eem-event-editor-card__status-pill<?php echo $contact_name ? ' is-ready' : ' is-unlinked'; ?>">
							<?php echo esc_html( $contact_name ? __( 'Primary Contact Ready', 'equine-event-manager' ) : __( 'Add Primary Contact', 'equine-event-manager' ) ); ?>
						</span>
						<span class="eem-event-editor-card__status-pill<?php echo $email ? ' is-ready' : ' is-unlinked'; ?>">
							<?php echo esc_html( $email ? __( 'Email Ready', 'equine-event-manager' ) : __( 'Add Producer Email', 'equine-event-manager' ) ); ?>
						</span>
						<?php if ( $website ) : ?>
							<span class="eem-event-editor-card__status-pill is-media"><?php esc_html_e( 'Website Linked', 'equine-event-manager' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="eem-event-editor-card__actions">
						<a class="eem-event-editor-card__action" href="#titlediv"><?php esc_html_e( 'Jump To Title', 'equine-event-manager' ); ?></a>
						<a class="eem-event-editor-card__action" href="#postdivrich"><?php esc_html_e( 'Jump To Description', 'equine-event-manager' ); ?></a>
						<?php if ( $website_url ) : ?>
							<a class="eem-event-editor-card__action eem-event-editor-card__action--primary" href="<?php echo esc_url( $website_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Producer Website', 'equine-event-manager' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
				<div class="eem-event-editor-card__meta">
					<span class="eem-event-editor-card__meta-label"><?php esc_html_e( 'Linked Events', 'equine-event-manager' ); ?></span>
					<strong><?php echo esc_html( sprintf( _n( '%d event uses this producer', '%d events use this producer', $linked_event_count, 'equine-event-manager' ), $linked_event_count ) ); ?></strong>
					<span class="eem-event-editor-card__meta-label"><?php esc_html_e( 'Primary Contact', 'equine-event-manager' ); ?></span>
					<strong><?php echo esc_html( $contact_name ? $contact_name : __( 'Not set yet', 'equine-event-manager' ) ); ?></strong>
					<span class="eem-event-editor-card__meta-label"><?php esc_html_e( 'Contact Status', 'equine-event-manager' ); ?></span>
					<strong><?php echo esc_html( $contact_ready ? __( 'Producer profile is filling out', 'equine-event-manager' ) : __( 'Add producer contact details', 'equine-event-manager' ) ); ?></strong>
				</div>
			</div>

			<div class="eem-event-editor-card__summary-grid">
				<div class="eem-event-editor-card__summary-card">
					<span class="eem-event-editor-card__summary-label"><?php esc_html_e( 'Contact', 'equine-event-manager' ); ?></span>
					<strong class="eem-event-editor-card__summary-value"><?php echo esc_html( $contact_name ? __( 'Lead contact ready', 'equine-event-manager' ) : __( 'Needs contact name', 'equine-event-manager' ) ); ?></strong>
					<span class="eem-event-editor-card__summary-note"><?php echo esc_html( $contact_name ? $contact_name : __( 'Add the organizer or production company contact.', 'equine-event-manager' ) ); ?></span>
				</div>
				<div class="eem-event-editor-card__summary-card">
					<span class="eem-event-editor-card__summary-label"><?php esc_html_e( 'Email', 'equine-event-manager' ); ?></span>
					<strong class="eem-event-editor-card__summary-value"><?php echo esc_html( $email ? __( 'Inbox linked', 'equine-event-manager' ) : __( 'Needs email', 'equine-event-manager' ) ); ?></strong>
					<span class="eem-event-editor-card__summary-note"><?php echo esc_html( $email ? $email : __( 'Add an email customers can use from linked events.', 'equine-event-manager' ) ); ?></span>
				</div>
				<div class="eem-event-editor-card__summary-card">
					<span class="eem-event-editor-card__summary-label"><?php esc_html_e( 'Website', 'equine-event-manager' ); ?></span>
					<strong class="eem-event-editor-card__summary-value"><?php echo esc_html( $website ? __( 'Website ready', 'equine-event-manager' ) : __( 'Website optional', 'equine-event-manager' ) ); ?></strong>
					<span class="eem-event-editor-card__summary-note"><?php echo esc_html( $website ? $website : __( 'Useful for schedules, memberships, or organizer information.', 'equine-event-manager' ) ); ?></span>
				</div>
				<div class="eem-event-editor-card__summary-card">
					<span class="eem-event-editor-card__summary-label"><?php esc_html_e( 'Connected Events', 'equine-event-manager' ); ?></span>
					<strong class="eem-event-editor-card__summary-value"><?php echo esc_html( number_format_i18n( $linked_event_count ) ); ?></strong>
					<span class="eem-event-editor-card__summary-note"><?php esc_html_e( 'Linked native events will show this producer automatically.', 'equine-event-manager' ); ?></span>
				</div>
			</div>

			<div class="eem-event-editor-grid">
				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Primary Contact', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_producer_contact_name" name="equine_event_manager_producer_contact_name" value="<?php echo esc_attr( $contact_name ); ?>" />
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Email', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_producer_email" name="equine_event_manager_producer_email" value="<?php echo esc_attr( $email ); ?>" />
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Phone', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_producer_phone" name="equine_event_manager_producer_phone" value="<?php echo esc_attr( $phone ); ?>" />
				</label>

				<label class="eem-event-editor-field">
					<span class="eem-event-editor-field__label"><?php esc_html_e( 'Website', 'equine-event-manager' ); ?></span>
					<input type="text" class="regular-text" id="equine_event_manager_producer_website" name="equine_event_manager_producer_website" value="<?php echo esc_attr( $website ); ?>" />
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a list of native events.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_events_shortcode( $atts ) {
		$raw_atts = shortcode_atts( array(), $atts, 'equine_event_manager_events' );
		$atts = shortcode_atts(
			array(
				'limit'         => 20,
				'category'      => '',
				'venue'         => 0,
				'producer'      => 0,
				'venue_name'    => '',
				'producer_name' => '',
				'featured'      => 0,
				'view'          => 'list',
				'source'        => 'all',
				'month'         => '',
				'timeframe'     => 'current_upcoming',
				'images'        => 'yes',
			),
			$atts,
			'equine_event_manager_events'
		);

		// `month` is the friendly alias for the calendar grid (README/UX wording);
		// normalize it to the internal `calendar` view. `map` is handled in the
		// map branch below.
		$view = sanitize_key( $atts['view'] );
		if ( 'month' === $view ) {
			$view = 'calendar';
		}
		if ( ! in_array( $view, array( 'list', 'calendar', 'map' ), true ) ) {
			$view = 'list';
		}
		$show_images = ! in_array( strtolower( (string) $atts['images'] ), array( 'no', 'false', '0', 'off' ), true );
		$collection_atts = $atts;
		$per_page        = max( 1, absint( $atts['limit'] ) );
		$current_page    = max( 1, absint( get_query_var( 'eem_events_page' ) ? get_query_var( 'eem_events_page' ) : ( isset( $_GET['eem_events_page'] ) ? wp_unslash( $_GET['eem_events_page'] ) : 1 ) ) );
		$timeframe       = self::parse_timeframe_filter( isset( $atts['timeframe'] ) ? $atts['timeframe'] : 'current_upcoming' );
		$source_label    = '';

		if ( ! empty( $raw_atts['source'] ) ) {
			$source_label = sanitize_text_field( (string) $raw_atts['source'] );
		}

		if ( 'calendar' === $view ) {
			$collection_atts['limit'] = max( 120, absint( $atts['limit'] ) );
		} else {
			$collection_atts['limit'] = 0;
		}

		$events = self::get_normalized_event_collection( $collection_atts );

		self::render_frontend_styles();

		if ( empty( $events ) ) {
			return $this->render_event_list_empty_state( $timeframe, $source_label );
		}

		$total_events = count( $events );
		$total_pages  = 'list' === $view ? max( 1, (int) ceil( $total_events / $per_page ) ) : 1;

		if ( 'list' === $view ) {
			$current_page = min( $current_page, $total_pages );
			$events       = array_slice( $events, ( $current_page - 1 ) * $per_page, $per_page );
		}

		ob_start();

		if ( 'calendar' === $view ) {
			echo wp_kses_post( $this->render_event_calendar_markup( $events, (string) $atts['month'] ) );
		} else {
			?>
			<div class="eem-event-list<?php echo $show_images ? '' : ' eem-event-list--no-images'; ?>" data-eem-events-page="<?php echo esc_attr( $current_page ); ?>">
				<?php foreach ( $events as $event_data ) : ?>
					<?php echo wp_kses_post( $this->render_event_list_row_markup( $event_data ) ); ?>
				<?php endforeach; ?>
			</div>
			<?php if ( $total_pages > 1 ) : ?>
				<?php echo wp_kses_post( $this->render_event_list_pagination( $current_page, $total_pages ) ); ?>
			<?php endif; ?>
			<?php
		}

		return (string) ob_get_clean();
	}

	/**
	 * Render a friendly empty state for event list shortcodes.
	 *
	 * @param string $timeframe Active timeframe.
	 * @param string $source_label Raw source label.
	 * @return string
	 */
	private function render_event_list_empty_state( $timeframe, $source_label = '' ) {
		$message = __( 'No current or upcoming events are available right now.', 'equine-event-manager' );
		$hint    = __( 'Use timeframe="all" to include past events too, or timeframe="past" to show only past events.', 'equine-event-manager' );

		if ( 'past' === $timeframe ) {
			$message = __( 'No past events are available right now.', 'equine-event-manager' );
			$hint    = __( 'Try timeframe="all" if you want to show past, current, and upcoming events in one list.', 'equine-event-manager' );
		} elseif ( 'all' === $timeframe ) {
			$message = __( 'No events are available right now.', 'equine-event-manager' );
			$hint    = __( 'Check your active event source settings and make sure events exist for the selected source.', 'equine-event-manager' );
		}

		if ( '' !== $source_label && 'all' !== sanitize_key( $source_label ) ) {
			$hint = sprintf(
				/* translators: %s source label. */
				__( 'No events were found for the "%s" source right now. Check that source in Event Manager settings or widen the shortcode timeframe.', 'equine-event-manager' ),
				$source_label
			);
		}

		ob_start();
		?>
		<div class="eem-event-list-empty" role="status">
			<strong><?php echo esc_html( $message ); ?></strong>
			<p><?php echo esc_html( $hint ); ?></p>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render a single native event block.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_event_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'               => 0,
				'reservation_id'   => 0,
				'show_content'     => 1,
				'show_reservation' => 1,
			),
			$atts,
			'equine_event_manager_event'
		);

		$event_id       = absint( $atts['id'] );
		$reservation_id = absint( $atts['reservation_id'] );

		if ( ! $event_id && ! $reservation_id ) {
			$current_post_type = get_post_type( get_the_ID() );

			if ( in_array( $current_post_type, array( self::EVENT_POST_TYPE, 'tribe_events' ), true ) ) {
				$event_id = get_the_ID();
			} elseif ( 'en_reservation' === $current_post_type ) {
				$reservation_id = get_the_ID();
			}
		}

		$event_data = $reservation_id ? self::get_normalized_reservation_event_data( $reservation_id ) : self::get_normalized_event_data( $event_id );

		if ( empty( $event_data ) ) {
			return '';
		}

		return $this->render_normalized_event_markup(
			$event_data,
			! empty( $atts['show_content'] ),
			! empty( $atts['show_reservation'] )
		);
	}

	/**
	 * Determine whether a post is a native event.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_native_event( $post_id ) {
		return self::EVENT_POST_TYPE === get_post_type( $post_id );
	}

	/**
	 * Get normalized native event dates.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{start_date:string,end_date:string}
	 */
	public static function get_native_event_date_values( $event_id ) {
		$start = (string) get_post_meta( $event_id, '_equine_event_manager_event_start_date', true );
		$end   = (string) get_post_meta( $event_id, '_equine_event_manager_event_end_date', true );

		return array(
			'start_date' => self::normalize_date_for_input( $start ),
			'end_date'   => self::normalize_date_for_input( $end ? $end : $start ),
		);
	}

	/**
	 * Get venue and location details for a native event.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{venue_name:string,location:string}
	 */
	public static function get_native_event_card_details( $event_id ) {
		$venue_id       = absint( get_post_meta( $event_id, '_equine_event_manager_event_venue_id', true ) );
		$location_label = (string) get_post_meta( $event_id, '_equine_event_manager_event_location_label', true );
		$venue_name     = '';
		$location       = $location_label;

		if ( $venue_id && self::VENUE_POST_TYPE === get_post_type( $venue_id ) ) {
			$venue_name = get_the_title( $venue_id );

			if ( '' === $location ) {
				$location = self::get_venue_location_label( $venue_id );
			}
		}

		return array(
			'venue_name' => $venue_name,
			'location'   => $location,
		);
	}

	/**
	 * Get a readable event source label.
	 *
	 * @param string $event_source Event source key.
	 * @return string
	 */
	public static function get_event_source_label( $event_source ) {
		if ( 'tec' === $event_source ) {
			return __( 'The Events Calendar', 'equine-event-manager' );
		}

		if ( 'native' === $event_source ) {
			return __( 'Equine Event Manager', 'equine-event-manager' );
		}

		if ( 'feed' === $event_source ) {
			return __( 'Event Feed', 'equine-event-manager' );
		}

		return __( 'External Event', 'equine-event-manager' );
	}

	/**
	 * Query upcoming native events for selectors.
	 *
	 * @param int $limit Result limit.
	 * @return array
	 */
	public static function get_upcoming_native_events( $limit = 200 ) {
		return get_posts(
			array(
				'post_type'      => self::EVENT_POST_TYPE,
				'post_status'    => array( 'publish', 'future', 'draft' ),
				'posts_per_page' => absint( $limit ),
				'meta_key'       => '_equine_event_manager_event_start_date',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Determine whether The Events Calendar is available.
	 *
	 * @return bool
	 */
	public static function is_the_events_calendar_available() {
		return post_type_exists( 'tribe_events' );
	}

	/**
	 * Get TEC events available for import.
	 *
	 * @param int $limit Result limit.
	 * @return array
	 */
	public static function get_tec_events_for_import( $limit = 100 ) {
		if ( ! self::is_the_events_calendar_available() ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'      => 'tribe_events',
				'post_status'    => array( 'publish', 'future', 'draft' ),
				'posts_per_page' => absint( $limit ),
				'meta_key'       => '_EventStartDate',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * Import a TEC event into a native event.
	 *
	 * @param int $tec_event_id TEC event post ID.
	 * @return array{status:string,event_id:int,message:string}
	 */
	public static function import_tec_event( $tec_event_id ) {
		$tec_event_id = absint( $tec_event_id );

		if ( ! $tec_event_id || 'tribe_events' !== get_post_type( $tec_event_id ) ) {
			return array(
				'status'  => 'error',
				'event_id' => 0,
				'message' => __( 'Invalid The Events Calendar event.', 'equine-event-manager' ),
			);
		}

		$existing_event = self::find_native_event_by_imported_tec_id( $tec_event_id );

		if ( $existing_event ) {
			return array(
				'status'   => 'existing',
				'event_id' => $existing_event,
				'message'  => __( 'That event was already imported.', 'equine-event-manager' ),
			);
		}

		$title   = get_the_title( $tec_event_id );
		$content = get_post_field( 'post_content', $tec_event_id );
		$excerpt = get_post_field( 'post_excerpt', $tec_event_id );
		$dates   = self::get_tec_event_date_values( $tec_event_id );
		$venue_id = self::import_tec_venue( $tec_event_id );
		$producer_id = self::import_tec_producer( $tec_event_id );

		$event_id = wp_insert_post(
			array(
				'post_type'    => self::EVENT_POST_TYPE,
				'post_status'  => 'publish' === get_post_status( $tec_event_id ) ? 'publish' : 'draft',
				'post_title'   => $title ? $title : __( 'Imported Event', 'equine-event-manager' ),
				'post_content' => $content,
				'post_excerpt' => $excerpt,
			),
			true
		);

		if ( is_wp_error( $event_id ) ) {
			return array(
				'status'   => 'error',
				'event_id' => 0,
				'message'  => $event_id->get_error_message(),
			);
		}

		update_post_meta( $event_id, '_equine_event_manager_imported_tec_event_id', $tec_event_id );
		update_post_meta( $event_id, '_equine_event_manager_event_start_date', $dates['start_date'] );
		update_post_meta( $event_id, '_equine_event_manager_event_end_date', $dates['end_date'] ? $dates['end_date'] : $dates['start_date'] );
		update_post_meta( $event_id, '_equine_event_manager_event_venue_id', $venue_id );
		update_post_meta( $event_id, '_equine_event_manager_event_producer_id', $producer_id );
		update_post_meta( $event_id, '_equine_event_manager_event_location_label', self::get_tec_event_location_label( $tec_event_id, $venue_id ) );
		update_post_meta( $event_id, '_equine_event_manager_event_cta_label', __( 'Reserve Now', 'equine-event-manager' ) );

		$thumbnail_id = get_post_thumbnail_id( $tec_event_id );
		if ( $thumbnail_id ) {
			set_post_thumbnail( $event_id, $thumbnail_id );
		}

		self::import_tec_categories( $tec_event_id, $event_id );
		self::migrate_linked_reservation_to_native_event( $tec_event_id, $event_id );

		return array(
			'status'   => 'imported',
			'event_id' => $event_id,
			'message'  => __( 'Event imported successfully.', 'equine-event-manager' ),
		);
	}

	/**
	 * Get TEC event dates normalized for import.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{start_date:string,end_date:string}
	 */
	public static function get_tec_event_date_values( $event_id ) {
		$start = get_post_meta( $event_id, '_EventStartDate', true );
		$end   = get_post_meta( $event_id, '_EventEndDate', true );

		return array(
			'start_date' => self::normalize_date_for_input( $start ),
			'end_date'   => self::normalize_date_for_input( $end ? $end : $start ),
		);
	}

	/**
	 * Enqueue the frontend stylesheet on demand.
	 *
	 * @return void
	 */
	public static function render_frontend_styles() {
		wp_enqueue_style(
			'eem-google-fonts',
			'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap',
			array(),
			null
		);
		wp_enqueue_style(
			'eem-public',
			EQUINE_EVENT_MANAGER_URL . 'assets/css/public.css',
			array(),
			EQUINE_EVENT_MANAGER_VERSION
		);
	}

	/**
	 * Render the frontend event list row markup.
	 *
	 * @param array<string, mixed> $event_data Normalized event data.
	 * @return string
	 */
	private function render_event_list_row_markup( $event_data ) {
		if ( empty( $event_data ) || ! is_array( $event_data ) ) {
			return '';
		}

		$event_url        = self::get_event_frontend_url( $event_data );
		$date_label       = self::format_date_range_label( $event_data['start_date'], $event_data['end_date'] );
		$days_until_label = self::get_days_until_event_label( $event_data['start_date'], $event_data['end_date'] );
		$primary_category = ! empty( $event_data['categories'][0] ) ? (string) $event_data['categories'][0] : '';
		$venue_name       = ! empty( $event_data['venue_name'] ) ? (string) $event_data['venue_name'] : '';
		$producer_name    = ! empty( $event_data['producer']['name'] ) ? (string) $event_data['producer']['name'] : '';
		$venue_filter_url = ! empty( $event_data['venue']['filter_url'] ) ? (string) $event_data['venue']['filter_url'] : '';
		$producer_filter  = ! empty( $event_data['producer']['filter_url'] ) ? (string) $event_data['producer']['filter_url'] : '';
		$reserve_label    = ! empty( $event_data['cta_label'] ) ? (string) $event_data['cta_label'] : __( 'Reserve Now', 'equine-event-manager' );
		$reserve_url      = ! empty( $event_data['reservation_id'] ) ? ( $event_url ? $event_url . '#reservation' : '#reservation' ) : '';
		$location_label   = self::get_event_city_state_label( $event_data );

		ob_start();
		?>
		<article class="eem-event-list-row">
			<div class="eem-event-list-row__media">
				<?php if ( $days_until_label ) : ?>
					<div class="eem-event-list-row__media-badge">
						<span class="eem-event-list-row__media-badge-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" role="presentation" focusable="false">
								<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"></circle>
								<path d="M12 7.5v5l3.2 1.9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>
							</svg>
						</span>
						<span><?php echo esc_html( $days_until_label ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $event_data['featured_image'] ) ) : ?>
					<img src="<?php echo esc_url( $event_data['featured_image'] ); ?>" alt="<?php echo esc_attr( $event_data['title'] ); ?>" />
				<?php else : ?>
					<div class="eem-event-list-row__media-placeholder" aria-hidden="true">
						<span class="eem-event-list-row__media-placeholder-icon">
							<svg viewBox="0 0 24 24" fill="none" role="presentation" focusable="false">
								<path d="M4.75 6.75A2 2 0 016.75 4.75h10.5a2 2 0 012 2v10.5a2 2 0 01-2 2H6.75a2 2 0 01-2-2V6.75z" stroke="currentColor" stroke-width="1.8"></path>
								<path d="M8 15l2.5-2.5L13 15l2.5-3 2.5 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>
								<circle cx="9" cy="9" r="1.2" fill="currentColor"></circle>
							</svg>
						</span>
					</div>
				<?php endif; ?>
			</div>
			<div class="eem-event-list-row__content">
				<header class="eem-event-list-row__header">
					<h4 class="eem-event-list-row__title">
						<?php if ( $event_url ) : ?>
							<a href="<?php echo esc_url( $event_url ); ?>"><?php echo esc_html( $event_data['title'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $event_data['title'] ); ?>
						<?php endif; ?>
					</h4>
					<?php if ( $date_label ) : ?>
						<h5 class="eem-event-list-row__date"><?php echo esc_html( $date_label ); ?></h5>
					<?php endif; ?>
				</header>

				<div class="eem-event-list-row__facts">
					<?php if ( $primary_category ) : ?>
						<div class="eem-event-list-row__fact">
							<div class="eem-event-list-row__fact-value">
								<span class="eem-event-list-row__fact-label"><?php esc_html_e( 'Event Type', 'equine-event-manager' ); ?></span>
								<strong><?php echo esc_html( $primary_category ); ?></strong>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( $venue_name ) : ?>
						<div class="eem-event-list-row__fact">
							<div class="eem-event-list-row__fact-value">
								<span class="eem-event-list-row__fact-label"><?php esc_html_e( 'Venue', 'equine-event-manager' ); ?></span>
								<?php if ( $venue_filter_url ) : ?>
									<a href="<?php echo esc_url( $venue_filter_url ); ?>"><strong><?php echo esc_html( $venue_name ); ?></strong></a>
								<?php else : ?>
									<strong><?php echo esc_html( $venue_name ); ?></strong>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( $producer_name ) : ?>
						<div class="eem-event-list-row__fact">
							<div class="eem-event-list-row__fact-value">
								<span class="eem-event-list-row__fact-label"><?php esc_html_e( 'Organizer', 'equine-event-manager' ); ?></span>
								<?php if ( $producer_filter ) : ?>
									<a href="<?php echo esc_url( $producer_filter ); ?>"><strong><?php echo esc_html( $producer_name ); ?></strong></a>
								<?php else : ?>
									<strong><?php echo esc_html( $producer_name ); ?></strong>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( $location_label ) : ?>
						<div class="eem-event-list-row__fact">
							<div class="eem-event-list-row__fact-value">
								<span class="eem-event-list-row__fact-label"><?php esc_html_e( 'City, State', 'equine-event-manager' ); ?></span>
								<strong><?php echo esc_html( $location_label ); ?></strong>
							</div>
						</div>
					<?php endif; ?>
				</div>

				<footer class="eem-event-list-row__footer">
					<div class="eem-event-list-row__actions">
						<?php if ( $event_url ) : ?>
							<a class="eem-event-list-row__view-link" href="<?php echo esc_url( $event_url ); ?>"><?php esc_html_e( 'View Event', 'equine-event-manager' ); ?></a>
						<?php endif; ?>
						<?php if ( $reserve_url ) : ?>
							<a class="eem-event-card__button" href="<?php echo esc_url( $reserve_url ); ?>"><?php echo esc_html( $reserve_label ); ?></a>
						<?php endif; ?>
					</div>
				</footer>
			</div>
		</article>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render event list pagination markup.
	 *
	 * @param int $current_page Current page.
	 * @param int $total_pages Total pages.
	 * @return string
	 */
	private function render_event_list_pagination( $current_page, $total_pages ) {
		$current_page = max( 1, absint( $current_page ) );
		$total_pages  = max( 1, absint( $total_pages ) );

		if ( $total_pages < 2 ) {
			return '';
		}

		$base_url = remove_query_arg( 'eem_events_page' );
		$start    = max( 1, $current_page - 2 );
		$end      = min( $total_pages, $current_page + 2 );

		ob_start();
		?>
		<nav class="eem-event-pagination" aria-label="<?php esc_attr_e( 'Event list pagination', 'equine-event-manager' ); ?>">
			<?php if ( $current_page > 1 ) : ?>
				<a class="eem-event-pagination__link" href="<?php echo esc_url( 1 === ( $current_page - 1 ) ? $base_url : add_query_arg( 'eem_events_page', $current_page - 1, $base_url ) ); ?>"><?php esc_html_e( 'Previous', 'equine-event-manager' ); ?></a>
			<?php endif; ?>

			<?php for ( $page = $start; $page <= $end; $page++ ) : ?>
				<?php if ( $page === $current_page ) : ?>
					<span class="eem-event-pagination__current" aria-current="page"><?php echo esc_html( (string) $page ); ?></span>
				<?php else : ?>
					<a class="eem-event-pagination__link" href="<?php echo esc_url( 1 === $page ? $base_url : add_query_arg( 'eem_events_page', $page, $base_url ) ); ?>"><?php echo esc_html( (string) $page ); ?></a>
				<?php endif; ?>
			<?php endfor; ?>

			<?php if ( $current_page < $total_pages ) : ?>
				<a class="eem-event-pagination__link" href="<?php echo esc_url( add_query_arg( 'eem_events_page', $current_page + 1, $base_url ) ); ?>"><?php esc_html_e( 'Next', 'equine-event-manager' ); ?></a>
			<?php endif; ?>
		</nav>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Build normalized event data for supported event sources.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<string, mixed>
	 */
	public static function get_normalized_event_data( $event_id ) {
		$event_id = absint( $event_id );

		if ( ! $event_id ) {
			return array();
		}

		$post = get_post( $event_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return array();
		}

		if ( self::EVENT_POST_TYPE === $post->post_type ) {
			return self::get_normalized_native_event_data( $event_id, $post );
		}

		if ( 'tribe_events' === $post->post_type ) {
			return self::get_normalized_tec_event_data( $event_id, $post );
		}

		return array();
	}

	/**
	 * Build normalized event data from a reservation-backed source such as feed or external.
	 *
	 * @param int $reservation_id Reservation post ID.
	 * @return array<string, mixed>
	 */
	public static function get_normalized_reservation_event_data( $reservation_id ) {
		$reservation_id = absint( $reservation_id );

		if ( ! $reservation_id || 'en_reservation' !== get_post_type( $reservation_id ) ) {
			return array();
		}

		$reservation = get_post( $reservation_id );

		if ( ! $reservation || 'publish' !== $reservation->post_status ) {
			return array();
		}

		$event_source = self::get_effective_reservation_event_source( $reservation_id );

		if ( in_array( $event_source, array( 'native', 'tec' ), true ) ) {
			$event_id = absint( get_post_meta( $reservation_id, '_en_event_id', true ) );

			return $event_id ? self::get_normalized_event_data( $event_id ) : array();
		}

		$feed_url    = (string) get_post_meta( $reservation_id, '_en_event_feed_url', true );
		$external_id = (string) get_post_meta( $reservation_id, '_en_external_event_id', true );
		$feed_event  = 'feed' === $event_source ? self::get_feed_event_by_external_id( $external_id, $feed_url ) : array();
		$start_date  = self::normalize_date_for_input( (string) get_post_meta( $reservation_id, '_en_available_start_date', true ) );
		$end_date    = self::normalize_date_for_input( (string) get_post_meta( $reservation_id, '_en_available_end_date', true ) );
		$title       = (string) get_post_meta( $reservation_id, '_en_external_event_name', true );
		$location    = (string) get_post_meta( $reservation_id, '_en_event_location', true );
		$venue_name  = (string) get_post_meta( $reservation_id, '_en_venue_name', true );
		$address    = trim( (string) get_post_meta( $reservation_id, '_en_venue_address', true ) );
		$summary    = trim( (string) get_post_meta( $reservation_id, '_en_event_details_summary', true ) );
		$map_url    = '';
		$map_image  = absint( get_post_meta( $reservation_id, '_en_venue_map_image_id', true ) );

		if ( ! empty( get_post_meta( $reservation_id, '_en_venue_map_enabled', true ) ) ) {
			$map_url = (string) get_post_meta( $reservation_id, '_en_venue_map_download_url', true );

			if ( '' === $map_url && $map_image ) {
				$map_url = (string) wp_get_attachment_image_url( $map_image, 'large' );
			}
		}

		// For the 'feed' source the linked event (e.g. a GEMS event) is the
		// authoritative source of dates — like a TEC/native event — so it wins
		// over the reservation's own availability window. Other external sources
		// keep the empty-only fallback.
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
			'event_id'       => 0,
			'source'         => $event_source ? $event_source : 'external',
			'external_event_id' => $external_id,
			'title'          => $title,
			'content_raw'    => $summary,
			'excerpt'        => '',
			'start_date'     => $start_date,
			'end_date'       => $end_date ? $end_date : $start_date,
			'venue_name'     => $venue_name,
			'location'       => $location,
			'venue'          => array(
				'address_display' => $address,
				'map_query'       => trim( implode( ', ', array_filter( array( $venue_name, str_replace( array( "\r\n", "\r", "\n" ), ', ', $address ), $location ) ) ) ),
				'filter_url'      => '',
			),
			'producer'       => array(
				'name'       => '',
				'email'      => '',
				'phone'      => '',
				'website'    => '',
				'filter_url' => '',
			),
			'featured_image' => ! empty( $feed_event['featured_image'] ) ? (string) $feed_event['featured_image'] : '',
			// The single-event hero reads 'hero_image'; feed sources have no
			// separate flyer attachment, so the feed event's featured image is
			// the hero image.
			'hero_image'     => ! empty( $feed_event['featured_image'] ) ? (string) $feed_event['featured_image'] : '',
			'flyer_url'      => ! empty( $feed_event['flyer_url'] ) ? (string) $feed_event['flyer_url'] : '',
			'external_url'   => ! empty( $feed_event['external_url'] ) ? (string) $feed_event['external_url'] : '',
			'reservation_id' => $reservation_id,
			'cta_label'      => __( 'Reserve Now', 'equine-event-manager' ),
			'categories'     => array(),
			'tags'           => array(),
			'map_url'        => $map_url,
			'event_feed_url' => $feed_url,
		);
	}

	/**
	 * Collect normalized events across all supported sources for frontend shortcodes.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_normalized_event_collection( $atts ) {
		$limit      = isset( $atts['limit'] ) ? (int) $atts['limit'] : 20;
		$limit      = $limit < 0 ? 0 : $limit;
		$pull_count = max( 24, $limit * 3 );
		$sources    = self::parse_source_filter( isset( $atts['source'] ) ? $atts['source'] : 'all' );
		$timeframe  = self::parse_timeframe_filter( isset( $atts['timeframe'] ) ? $atts['timeframe'] : 'current_upcoming' );
		$events     = array();

		if ( 0 === $limit ) {
			$pull_count = 500;
		}

		if ( in_array( 'native', $sources, true ) && self::is_native_events_enabled() ) {
			foreach ( self::get_native_events_for_display( $atts, $pull_count ) as $event_id ) {
				$event_data = self::get_normalized_event_data( $event_id );

				if ( ! empty( $event_data ) ) {
					$events[] = $event_data;
				}
			}
		}

		if ( in_array( 'tec', $sources, true ) && self::is_tec_integration_enabled() ) {
			foreach ( self::get_tec_events_for_display( $atts, $pull_count ) as $event_id ) {
				$event_data = self::get_normalized_event_data( $event_id );

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
			foreach ( self::get_reservation_events_for_display( $reservation_sources, $pull_count ) as $reservation_id ) {
				$event_data = self::get_normalized_reservation_event_data( $reservation_id );

				if ( ! empty( $event_data ) ) {
					$events[] = $event_data;
				}
			}
		}

		$events = self::deduplicate_normalized_events( $events );
		$events = self::filter_events_by_timeframe( $events, $timeframe );

		usort(
			$events,
			static function ( $left, $right ) use ( $timeframe ) {
				$left_date  = self::get_event_sort_date( $left );
				$right_date = self::get_event_sort_date( $right );

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

	/**
	 * Parse a source filter into supported source keys.
	 *
	 * @param string $source Raw source attribute.
	 * @return array<int, string>
	 */
	private static function parse_source_filter( $source ) {
		$parts = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $source ) ) ) );

		if ( empty( $parts ) || in_array( 'all', $parts, true ) ) {
			return array( 'native', 'tec', 'feed', 'external' );
		}

		return array_values( array_intersect( $parts, array( 'native', 'tec', 'feed', 'external' ) ) );
	}

	/**
	 * Parse an event timeframe attribute into a supported key.
	 *
	 * @param string $timeframe Raw timeframe attribute.
	 * @return string
	 */
	private static function parse_timeframe_filter( $timeframe ) {
		$timeframe = sanitize_key( (string) $timeframe );

		if ( in_array( $timeframe, array( 'upcoming', 'current_upcoming', 'past', 'all', 'include_past' ), true ) ) {
			if ( 'upcoming' === $timeframe ) {
				return 'current_upcoming';
			}

			if ( 'include_past' === $timeframe ) {
				return 'all';
			}

			return $timeframe;
		}

		return 'current_upcoming';
	}

	/**
	 * Filter normalized events by timeframe.
	 *
	 * @param array<int, array<string, mixed>> $events Event collection.
	 * @param string                           $timeframe Supported timeframe key.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_events_by_timeframe( $events, $timeframe ) {
		if ( 'all' === $timeframe ) {
			return array_values( $events );
		}

		$today = wp_date( 'Y-m-d', current_time( 'timestamp' ) );

		return array_values(
			array_filter(
				$events,
				static function ( $event ) use ( $timeframe, $today ) {
					$start_date = self::normalize_date_for_input( isset( $event['start_date'] ) ? (string) $event['start_date'] : '' );
					$end_date   = self::normalize_date_for_input( isset( $event['end_date'] ) ? (string) $event['end_date'] : '' );
					$sort_date  = $end_date ? $end_date : $start_date;

					if ( '' === $sort_date ) {
						return false;
					}

					if ( 'past' === $timeframe ) {
						return $sort_date < $today;
					}

					return $sort_date >= $today;
				}
			)
		);
	}

	/**
	 * Resolve the best sort date for an event collection row.
	 *
	 * @param array<string, mixed> $event_data Event data.
	 * @return string
	 */
	private static function get_event_sort_date( $event_data ) {
		$start_date = self::normalize_date_for_input( isset( $event_data['start_date'] ) ? (string) $event_data['start_date'] : '' );
		$end_date   = self::normalize_date_for_input( isset( $event_data['end_date'] ) ? (string) $event_data['end_date'] : '' );

		if ( $start_date ) {
			return $start_date;
		}

		if ( $end_date ) {
			return $end_date;
		}

		return '9999-12-31';
	}

	/**
	 * Deduplicate normalized events, preferring reservation-backed rows over raw feed rows.
	 *
	 * @param array<int, array<string, mixed>> $events Event collection.
	 * @return array<int, array<string, mixed>>
	 */
	private static function deduplicate_normalized_events( $events ) {
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
	 * Query native events for mixed-source displays.
	 *
	 * @param array $atts Shortcode attributes.
	 * @param int   $limit Query limit.
	 * @return array<int, int>
	 */
	private static function get_native_events_for_display( $atts, $limit ) {
		$query_args = array(
			'post_type'      => self::EVENT_POST_TYPE,
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
					'taxonomy' => self::EVENT_CATEGORY_TAXONOMY,
					'field'    => is_numeric( $atts['category'] ) ? 'term_id' : 'slug',
					'terms'    => is_numeric( $atts['category'] ) ? absint( $atts['category'] ) : sanitize_title( $atts['category'] ),
				),
			);
		}

		return array_map( 'absint', get_posts( $query_args ) );
	}

	/**
	 * Query TEC events for mixed-source displays.
	 *
	 * @param array $atts Shortcode attributes.
	 * @param int   $limit Query limit.
	 * @return array<int, int>
	 */
	private static function get_tec_events_for_display( $atts, $limit ) {
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
	 * Query reservation-backed event sources for mixed-source displays.
	 *
	 * @param array<int, string> $sources Allowed reservation source keys.
	 * @param int                $limit Query limit.
	 * @return array<int, int>
	 */
	private static function get_reservation_events_for_display( $sources, $limit ) {
		$meta_query = array(
			'relation' => 'OR',
			array(
				'key'     => '_en_event_source',
				'value'   => $sources,
				'compare' => 'IN',
			),
		);

		if ( in_array( self::get_default_event_source(), $sources, true ) ) {
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

	/**
	 * Public customer-facing URL for a reservation, regardless of event source.
	 *
	 * Returns the plugin's virtual event route (/equine-event/{id}/) — the page
	 * that renders the event hero + the booking form. Works for TEC, native, and
	 * feed/GEMS reservations alike, because the en_reservation CPT is
	 * public => false and has no routable permalink of its own. Used by the
	 * editor header "View Event" button and the Reservations list "View on
	 * Frontend" action.
	 *
	 * @param int $reservation_id Reservation post id.
	 * @return string Absolute URL, or '' when the id is invalid.
	 */
	public static function get_reservation_public_url( $reservation_id ) {
		$reservation_id = absint( $reservation_id );
		if ( $reservation_id <= 0 ) {
			return '';
		}
		return (string) home_url( user_trailingslashit( self::VIRTUAL_EVENT_ROUTE_BASE . '/' . self::reservation_route_slug( $reservation_id ) ) );
	}

	/**
	 * Build the URL slug for a reservation's public event page.
	 *
	 * Format: a readable prefix derived from the reservation title (which itself
	 * inherits the linked event name) followed by the post id —
	 * e.g. "ntr-rapid-city-sd-6519". The trailing id is what the route resolves
	 * on; the prefix is cosmetic + SEO. Appending the id keeps the slug unique
	 * for events that recur every year under the same name (no WordPress "-2"
	 * suffixing) and means the bare-id route still matches old links. Falls back
	 * to just the id when the title is empty.
	 *
	 * @param int $reservation_id Reservation post id.
	 * @return string e.g. "ntr-rapid-city-sd-6519", or "6519" when untitled.
	 */
	private static function reservation_route_slug( $reservation_id ) {
		$reservation_id = absint( $reservation_id );
		$title          = $reservation_id ? (string) get_post_field( 'post_title', $reservation_id ) : '';
		$prefix         = sanitize_title( $title );

		return '' !== $prefix ? $prefix . '-' . $reservation_id : (string) $reservation_id;
	}

	/**
	 * Get the frontend URL for any normalized event source.
	 *
	 * @param array<string, mixed> $event_data Normalized event data.
	 * @return string
	 */
	private static function get_event_frontend_url( $event_data ) {
		if ( ! empty( $event_data['event_id'] ) ) {
			return get_permalink( absint( $event_data['event_id'] ) );
		}

		if ( ! empty( $event_data['reservation_id'] ) ) {
			return self::get_reservation_public_url( absint( $event_data['reservation_id'] ) );
		}

		return '';
	}

	/**
	 * Render a monthly calendar view from normalized event data.
	 *
	 * @param array<int, array<string, mixed>> $events Normalized event collection.
	 * @param string                           $month Optional month in `Y-m` format.
	 * @return string
	 */
	private function render_event_calendar_markup( $events, $month = '' ) {
		$month = preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ? (string) $month : '';

		if ( '' === $month ) {
			$month = ! empty( $events[0]['start_date'] ) ? substr( (string) $events[0]['start_date'], 0, 7 ) : gmdate( 'Y-m' );
		}

		$month_start = strtotime( $month . '-01' );

		if ( ! $month_start ) {
			return '';
		}

		$days_in_month = (int) gmdate( 't', $month_start );
		$start_weekday = (int) gmdate( 'w', $month_start );
		$events_by_day = array();

		foreach ( $events as $event_data ) {
			if ( empty( $event_data['start_date'] ) || 0 !== strpos( (string) $event_data['start_date'], $month ) ) {
				continue;
			}

			$day = (int) substr( (string) $event_data['start_date'], 8, 2 );

			if ( $day > 0 ) {
				if ( ! isset( $events_by_day[ $day ] ) ) {
					$events_by_day[ $day ] = array();
				}

				$events_by_day[ $day ][] = $event_data;
			}
		}

		ob_start();
		?>
		<div class="equine-event-manager-calendar">
			<div class="equine-event-manager-calendar__header">
				<h2><?php echo esc_html( wp_date( 'F Y', $month_start ) ); ?></h2>
			</div>
			<div class="equine-event-manager-calendar__weekdays">
				<?php foreach ( array( __( 'Sun', 'equine-event-manager' ), __( 'Mon', 'equine-event-manager' ), __( 'Tue', 'equine-event-manager' ), __( 'Wed', 'equine-event-manager' ), __( 'Thu', 'equine-event-manager' ), __( 'Fri', 'equine-event-manager' ), __( 'Sat', 'equine-event-manager' ) ) as $weekday ) : ?>
					<div class="equine-event-manager-calendar__weekday"><?php echo esc_html( $weekday ); ?></div>
				<?php endforeach; ?>
			</div>
			<div class="equine-event-manager-calendar__grid">
				<?php for ( $blank = 0; $blank < $start_weekday; $blank++ ) : ?>
					<div class="equine-event-manager-calendar__day equine-event-manager-calendar__day--empty" aria-hidden="true"></div>
				<?php endfor; ?>
				<?php for ( $day = 1; $day <= $days_in_month; $day++ ) : ?>
					<div class="equine-event-manager-calendar__day">
						<div class="equine-event-manager-calendar__day-number"><?php echo esc_html( $day ); ?></div>
						<?php if ( ! empty( $events_by_day[ $day ] ) ) : ?>
							<div class="equine-event-manager-calendar__events">
								<?php foreach ( $events_by_day[ $day ] as $event_data ) : ?>
									<?php $event_url = self::get_event_frontend_url( $event_data ); ?>
									<?php if ( $event_url ) : ?>
										<a class="equine-event-manager-calendar__event" href="<?php echo esc_url( $event_url ); ?>"><?php echo esc_html( $event_data['title'] ); ?></a>
									<?php else : ?>
										<span class="equine-event-manager-calendar__event"><?php echo esc_html( $event_data['title'] ); ?></span>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endfor; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Format a US phone number for customer-facing display.
	 *
	 * Normalizes a 10-digit number to `XXX-XXX-XXXX` (and an 11-digit
	 * `1`-prefixed number the same way, dropping the country code). Numbers that
	 * don't match a recognized length are returned trimmed but otherwise
	 * untouched, so international or extension-bearing values are never mangled.
	 *
	 * @param string $phone Raw phone string.
	 * @return string Display-formatted phone.
	 */
	private static function format_us_phone_display( $phone ) {
		$raw    = (string) $phone;
		$digits = preg_replace( '/\D+/', '', $raw );

		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}

		if ( 10 === strlen( $digits ) ) {
			return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
		}

		return trim( $raw );
	}

	/**
	 * Render the shared spotlight markup for any normalized event source.
	 *
	 * @param array<string, mixed> $event_data Normalized event data.
	 * @param bool                 $show_content Whether to show event content.
	 * @param bool                 $show_reservation Whether to show the reservation block.
	 * @return string
	 */
	private function render_normalized_event_markup( $event_data, $show_content = true, $show_reservation = true ) {
		if ( empty( $event_data['event_id'] ) && empty( $event_data['reservation_id'] ) ) {
			return '';
		}

		$date_label     = self::format_date_range_label( $event_data['start_date'], $event_data['end_date'] );
		$directions_url = ! empty( $event_data['venue']['map_query'] ) ? 'https://maps.google.com/?q=' . rawurlencode( $event_data['venue']['map_query'] ) : '';
		$bullets        = self::extract_hero_bullets( $event_data['content_raw'] );
		$hero_image     = ! empty( $event_data['hero_image'] ) ? $event_data['hero_image'] : '';
		$has_reservation = $show_reservation && ! empty( $event_data['reservation_id'] );
		$cta_label      = ! empty( $event_data['cta_label'] ) ? $event_data['cta_label'] : __( 'Reserve Now', 'equine-event-manager' );

		$producer_tag   = '';
		if ( ! empty( $event_data['categories'][0] ) ) {
			$producer_tag = (string) $event_data['categories'][0];
		} elseif ( ! empty( $event_data['producer']['name'] ) ) {
			$producer_tag = (string) $event_data['producer']['name'];
		}

		$location_val = '';
		$location_sub = '';
		if ( ! empty( $event_data['venue_name'] ) ) {
			$location_val = (string) $event_data['venue_name'];
			if ( ! empty( $event_data['venue']['address_display'] ) ) {
				$location_sub = trim( preg_replace( '/\s*\n\s*/', ', ', (string) $event_data['venue']['address_display'] ) );
			} elseif ( ! empty( $event_data['location'] ) ) {
				$location_sub = (string) $event_data['location'];
			}
		} elseif ( ! empty( $event_data['location'] ) ) {
			$location_val = (string) $event_data['location'];
		}

		$producer_val = ! empty( $event_data['producer']['name'] ) ? (string) $event_data['producer']['name'] : '';
		// 2.3.74 — producer phone + email render as clickable tel:/mailto: links.
		$producer_contacts = array();
		if ( ! empty( $event_data['producer']['phone'] ) ) {
			$producer_phone_display = self::format_us_phone_display( $event_data['producer']['phone'] );
			$producer_phone_digits  = preg_replace( '/[^\d+]/', '', $producer_phone_display );
			$producer_contacts[]    = '<a href="tel:' . esc_attr( $producer_phone_digits ) . '">' . esc_html( $producer_phone_display ) . '</a>';
		}
		if ( ! empty( $event_data['producer']['email'] ) ) {
			$producer_email      = (string) $event_data['producer']['email'];
			$producer_contacts[] = '<a href="mailto:' . esc_attr( $producer_email ) . '">' . esc_html( $producer_email ) . '</a>';
		}
		$producer_sub = implode( ' · ', $producer_contacts );

		ob_start();
		self::render_frontend_styles();
		?>
		<div class="eem-event-page">
			<div class="hero">
				<div class="hero-inner">
					<div class="hero-img-col">
						<?php if ( $hero_image ) : ?>
							<img src="<?php echo esc_url( $hero_image ); ?>" alt="<?php echo esc_attr( $event_data['title'] ); ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';" />
							<div class="hero-img-placeholder" aria-hidden="true" style="display:none;">
								<span><?php esc_html_e( 'Event Featured Image', 'equine-event-manager' ); ?></span>
							</div>
						<?php else : ?>
							<div class="hero-img-placeholder" aria-hidden="true">
								<span><?php esc_html_e( 'Event Featured Image', 'equine-event-manager' ); ?></span>
							</div>
						<?php endif; ?>
					</div>
					<div class="hero-info-col">
						<?php // 2.3.58 — Producer/category badge removed per product decision. ?>
						<?php if ( ! empty( $event_data['featured'] ) ) : ?>
							<div class="hero-tags">
								<span class="tag tag-featured"><?php esc_html_e( 'Featured Event', 'equine-event-manager' ); ?></span>
							</div>
						<?php endif; ?>

						<h1 class="hero-title"><?php echo esc_html( $event_data['title'] ); ?></h1>
						<?php if ( $date_label ) : ?>
							<div class="hero-dates"><?php echo esc_html( $date_label ); ?></div>
						<?php endif; ?>

						<?php if ( ! empty( $bullets ) ) : ?>
							<ul class="hero-bullets">
								<?php foreach ( $bullets as $bullet ) : ?>
									<li><?php echo esc_html( $bullet ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<?php if ( '' !== $location_val || '' !== $producer_val ) : ?>
							<div class="hero-meta-grid">
								<?php if ( '' !== $location_val ) : ?>
									<div class="hero-meta-item">
										<div class="hero-meta-label"><?php esc_html_e( 'Location', 'equine-event-manager' ); ?></div>
										<div class="hero-meta-val"><?php echo esc_html( $location_val ); ?></div>
										<?php if ( '' !== $location_sub ) : ?>
											<div class="hero-meta-sub"><?php echo esc_html( $location_sub ); ?></div>
										<?php endif; ?>
									</div>
								<?php endif; ?>
								<?php if ( '' !== $producer_val ) : ?>
									<div class="hero-meta-item">
										<div class="hero-meta-label"><?php esc_html_e( 'Producer', 'equine-event-manager' ); ?></div>
										<div class="hero-meta-val"><?php echo esc_html( $producer_val ); ?></div>
										<?php if ( '' !== $producer_sub ) : ?>
											<div class="hero-meta-sub"><?php echo $producer_sub; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html/esc_attr parts above. ?></div>
										<?php endif; ?>
									</div>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<div class="hero-ctas">
							<?php if ( $has_reservation ) : ?>
								<a class="btn-reserve" href="#reservation-form"><?php echo esc_html( $cta_label ); ?></a>
							<?php endif; ?>
							<?php if ( $directions_url ) : ?>
								<a class="btn-directions" href="<?php echo esc_url( $directions_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Directions', 'equine-event-manager' ); ?></a>
							<?php endif; ?>
						</div>

						<?php
						$social_fb = ! empty( $event_data['social']['facebook'] ) ? (string) $event_data['social']['facebook'] : '';
						$social_ig = ! empty( $event_data['social']['instagram'] ) ? (string) $event_data['social']['instagram'] : '';
						if ( '' !== $social_fb || '' !== $social_ig ) :
							?>
							<div class="hero-social">
								<?php if ( '' !== $social_fb ) : ?>
									<a class="hero-social-link" href="<?php echo esc_url( $social_fb ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Facebook', 'equine-event-manager' ); ?></a>
								<?php endif; ?>
								<?php if ( '' !== $social_ig ) : ?>
									<a class="hero-social-link" href="<?php echo esc_url( $social_ig ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Instagram', 'equine-event-manager' ); ?></a>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<?php if ( $has_reservation ) : ?>
				<div class="mobile-order-drawer">
					<div class="mob-drawer-row">
						<div class="mob-drawer-label"><?php echo esc_html( $event_data['title'] ); ?></div>
						<a class="mob-drawer-btn" href="#reservation-form"><?php echo esc_html( $cta_label ); ?></a>
					</div>
				</div>

				<div id="reservation-form" class="eem-event-reservation-mount">
					<?php echo do_shortcode( sprintf( '[en_reservation id="%d" show_event_header="0"]', absint( $event_data['reservation_id'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endif; ?>
			<?php
			// 2.3.54 - When no reservation is linked, render the event info (hero)
			// only; the area below the hero stays blank. Many events have no
			// reservation tied to them, and the previous elseif branch re-printed
			// the event description here (.eem-event-body via
			// format_event_body_content), duplicating the hero copy. $show_content
			// is retained on the method signature for caller compatibility but no
			// longer drives a body render.
			?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Normalize a native event into the shared frontend data shape.
	 *
	 * @param int     $event_id Event post ID.
	 * @param WP_Post $post Event post.
	 * @return array<string, mixed>
	 */
	private static function get_normalized_native_event_data( $event_id, $post ) {
		$reservation_id   = absint( get_post_meta( $event_id, '_equine_event_manager_reservation_id', true ) );
		$flyer_file_id    = absint( get_post_meta( $event_id, '_equine_event_manager_event_flyer_file_id', true ) );
		$flyer_file_url   = (string) get_post_meta( $event_id, '_equine_event_manager_event_flyer_url', true );
		$event_dates      = self::get_native_event_date_values( $event_id );
		$event_details    = self::get_native_event_card_details( $event_id );
		$venue_id         = absint( get_post_meta( $event_id, '_equine_event_manager_event_venue_id', true ) );
		$producer_id      = absint( get_post_meta( $event_id, '_equine_event_manager_event_producer_id', true ) );
		$categories       = wp_get_post_terms( $event_id, self::EVENT_CATEGORY_TAXONOMY, array( 'fields' => 'names' ) );
		$tags             = wp_get_post_terms( $event_id, self::EVENT_TAG_TAXONOMY, array( 'fields' => 'names' ) );

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
			'venue'          => self::get_venue_details( $venue_id ),
			'producer'       => self::get_producer_details( $producer_id ),
			'featured'       => (bool) get_post_meta( $event_id, '_equine_event_manager_event_featured', true ),
			'featured_image' => get_the_post_thumbnail_url( $event_id, 'large' ),
			'hero_image'     => self::resolve_hero_image_url( $flyer_file_id, (string) get_the_post_thumbnail_url( $event_id, 'large' ) ),
			'flyer_url'      => $flyer_file_url ? $flyer_file_url : ( $flyer_file_id ? wp_get_attachment_url( $flyer_file_id ) : '' ),
			'reservation_id' => $reservation_id,
			'cta_label'      => (string) get_post_meta( $event_id, '_equine_event_manager_event_cta_label', true ),
			'social'         => array(
				'facebook'  => (string) get_post_meta( $event_id, '_en_event_facebook', true ),
				'instagram' => (string) get_post_meta( $event_id, '_en_event_instagram', true ),
			),
			'categories'     => is_wp_error( $categories ) ? array() : array_values( array_filter( array_map( 'strval', $categories ) ) ),
			'tags'           => is_wp_error( $tags ) ? array() : array_values( array_filter( array_map( 'strval', $tags ) ) ),
		);
	}

	/**
	 * Normalize a TEC event into the shared frontend data shape.
	 *
	 * @param int     $event_id Event post ID.
	 * @param WP_Post $post Event post.
	 * @return array<string, mixed>
	 */
	private static function get_normalized_tec_event_data( $event_id, $post ) {
		$flyer_file_id    = absint( get_post_meta( $event_id, '_equine_event_manager_event_flyer_file_id', true ) );
		$flyer_file_url   = (string) get_post_meta( $event_id, '_equine_event_manager_event_flyer_url', true );
		$event_dates      = self::get_tec_event_date_values( $event_id );
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
			'reservation_id' => self::get_linked_reservation_id_for_event( $event_id ),
			'cta_label'      => '',
			'categories'     => is_wp_error( $categories ) ? array() : array_values( array_filter( array_map( 'strval', $categories ) ) ),
			'tags'           => is_wp_error( $tags ) ? array() : array_values( array_filter( array_map( 'strval', $tags ) ) ),
		);
	}

	/**
	 * Resolve the hero image URL: prefer an image-type flyer attachment, then the
	 * post thumbnail. Returns '' when neither resolves (caller shows a placeholder).
	 *
	 * @param int    $flyer_file_id Flyer attachment ID.
	 * @param string $thumbnail_url Post thumbnail URL.
	 * @return string
	 */
	private static function resolve_hero_image_url( $flyer_file_id, $thumbnail_url ) {
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
	 * Derive hero bullet points from raw event content. Prefers explicit list
	 * items; falls back to paragraphs/lines. Returns plain-text bullets.
	 *
	 * @param string $content_raw Raw post content.
	 * @return array<int, string>
	 */
	private static function extract_hero_bullets( $content_raw ) {
		$content_raw = preg_replace( '/\[[^\]]*\]/', '', (string) $content_raw );
		$content_raw = preg_replace( '#<(style|script)\b[^>]*>.*?</\1>#is', '', (string) $content_raw );
		$content_raw = trim( (string) $content_raw );

		if ( '' === $content_raw ) {
			return array();
		}

		$bullets = array();

		if ( preg_match_all( '#<li\b[^>]*>(.*?)</li>#is', $content_raw, $matches ) && ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $item ) {
				$text = trim( wp_strip_all_tags( $item ) );
				if ( '' !== $text ) {
					$bullets[] = $text;
				}
			}
		} else {
			$blocks = preg_split( '#</p>|<br\s*/?>|\R#i', $content_raw );
			foreach ( (array) $blocks as $block ) {
				$text = trim( wp_strip_all_tags( $block ) );
				if ( '' !== $text ) {
					$bullets[] = $text;
				}
			}
		}

		return array_slice( $bullets, 0, 6 );
	}

	/**
	 * Format event body content without recursing through the shared event filter.
	 *
	 * @param string $content_raw Raw post content.
	 * @return string
	 */
	private function format_event_body_content( $content_raw ) {
		$content_raw = preg_replace( '/\[(equine_event_manager_event|equine_event_manager_events|equine_event_manager_event_reservation|en_reservation|en_stall_reservation_form|en_rv_reservation_form)\b[^\]]*\]/i', '', (string) $content_raw );
		$content_raw = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', (string) $content_raw );
		$content_raw = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $content_raw );
		$content_raw = trim( (string) $content_raw );

		if ( '' === $content_raw ) {
			return '';
		}

		// Drop self-referential template markup or reservation assets if they were pasted into event content.
		if ( preg_match( '/(?:eem-event-spotlight|eem-event-hero|eem-event-app-card|\.eem-reservation-form-wrap\b|\.eem-event-details-card\b|var\s+enStripeForms\b|initializeReservationForms\b)/i', $content_raw ) ) {
			return '';
		}

		$content_html = do_shortcode( $content_raw );
		$content_html = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', (string) $content_html );
		$content_html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $content_html );
		$content_html = trim( wp_kses_post( (string) $content_html ) );

		if ( '' === $content_html ) {
			return '';
		}

		if ( preg_match( '/<(p|ul|ol|li|blockquote|h[1-6]|div|figure|img|table|hr|br)\b/i', $content_html ) ) {
			return $content_html;
		}

		return wpautop( $content_html );
	}

	/**
	 * Get producer details for the frontend event view.
	 *
	 * @param int $producer_id Producer post ID.
	 * @return array{name:string,email:string,phone:string,website:string}
	 */
	private static function get_producer_details( $producer_id ) {
		if ( ! $producer_id || self::PRODUCER_POST_TYPE !== get_post_type( $producer_id ) ) {
			return array(
				'name'       => '',
				'email'      => '',
				'phone'      => '',
				'website'    => '',
				'filter_url' => '',
			);
		}

		$name = get_the_title( $producer_id );

		return array(
			'name'       => $name,
			'email'      => (string) get_post_meta( $producer_id, '_equine_event_manager_producer_email', true ),
			'phone'      => (string) get_post_meta( $producer_id, '_equine_event_manager_producer_phone', true ),
			'website'    => (string) get_post_meta( $producer_id, '_equine_event_manager_producer_website', true ),
			'filter_url' => self::get_directory_url( self::DIRECTORY_PRODUCER_ROUTE_BASE, $name ),
		);
	}

	/**
	 * Get venue details for the frontend event view.
	 *
	 * @param int $venue_id Venue post ID.
	 * @return array{address_display:string,map_query:string}
	 */
	private static function get_venue_details( $venue_id ) {
		if ( ! $venue_id || self::VENUE_POST_TYPE !== get_post_type( $venue_id ) ) {
			return array(
				'address_display' => '',
				'map_query'       => '',
				'filter_url'      => '',
			);
		}

		$name = get_the_title( $venue_id );

		$city        = trim( (string) get_post_meta( $venue_id, '_equine_event_manager_venue_city', true ) );
		$state       = trim( (string) get_post_meta( $venue_id, '_equine_event_manager_venue_state', true ) );
		$postal_code = trim( (string) get_post_meta( $venue_id, '_equine_event_manager_venue_postal_code', true ) );
		$city_state  = trim(
			implode(
				', ',
				array_filter(
					array(
						$city,
						$state,
					)
				)
			)
		);

		if ( '' !== $postal_code ) {
			$city_state = trim( $city_state . ' ' . $postal_code );
		}

		$lines = array_filter(
			array(
				(string) get_post_meta( $venue_id, '_equine_event_manager_venue_address_1', true ),
				(string) get_post_meta( $venue_id, '_equine_event_manager_venue_address_2', true ),
				$city_state,
			)
		);

		return array(
			'address_display' => implode( "\n", $lines ),
			'map_query'       => implode( ', ', $lines ),
			'filter_url'      => self::get_directory_url( self::DIRECTORY_LOCATION_ROUTE_BASE, $name ),
		);
	}

	/**
	 * Get posts for a simple select list.
	 *
	 * @param string $post_type Post type slug.
	 * @return array
	 */
	private function get_posts_for_select( $post_type ) {
		return get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Determine whether a post meta save should run.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @param string  $post_type Expected post type.
	 * @param string  $nonce_name Nonce field name.
	 * @param string  $nonce_action Nonce action.
	 * @return bool
	 */
	private function can_save_post_meta( $post_id, $post, $post_type, $nonce_name, $nonce_action ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		if ( ! isset( $_POST[ $nonce_name ] ) ) {
			return false;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ), $nonce_action ) ) {
			return false;
		}

		if ( ! $post instanceof WP_Post || $post_type !== $post->post_type ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Normalize a raw date to `Y-m-d`.
	 *
	 * @param string $value Raw date value.
	 * @return string
	 */
	private static function normalize_date_for_input( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );

		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
	}

	/**
	 * Sanitize a date input value.
	 *
	 * @param string $value Raw date value.
	 * @return string
	 */
	private function sanitize_date_value( $value ) {
		return self::normalize_date_for_input( $value );
	}

	/**
	 * Get a readable date range label.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return string
	 */
	private static function format_date_range_label( $start_date, $end_date ) {
		if ( ! $start_date ) {
			return '';
		}

		if ( ! $end_date || $start_date === $end_date ) {
			return wp_date( get_option( 'date_format' ), strtotime( $start_date ) );
		}

		return sprintf(
			/* translators: 1: start date, 2: end date. */
			__( '%1$s - %2$s', 'equine-event-manager' ),
			wp_date( get_option( 'date_format' ), strtotime( $start_date ) ),
			wp_date( get_option( 'date_format' ), strtotime( $end_date ) )
		);
	}

	/**
	 * Get a readable days-until label for list badges.
	 *
	 * @param string $start_date Event start date.
	 * @param string $end_date Event end date.
	 * @return string
	 */
	private static function get_days_until_event_label( $start_date, $end_date = '' ) {
		$start_date = self::normalize_date_for_input( $start_date );
		$end_date   = self::normalize_date_for_input( $end_date );

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
	 * Get the best available city/state label for an event row.
	 *
	 * @param array<string, mixed> $event_data Event data.
	 * @return string
	 */
	private static function get_event_city_state_label( $event_data ) {
		$location = trim( isset( $event_data['location'] ) ? (string) $event_data['location'] : '' );

		if ( '' !== $location ) {
			return $location;
		}

		if ( ! empty( $event_data['venue']['address_display'] ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $event_data['venue']['address_display'] );

			if ( ! empty( $lines[2] ) ) {
				return trim( (string) $lines[2] );
			}
		}

		return '';
	}

	/**
	 * Get venue and location details for a TEC event.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{venue_name:string,location:string}
	 */
	private static function get_tec_event_card_details( $event_id ) {
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
	 * @return array{address_display:string,map_query:string}
	 */
	private static function get_tec_venue_details( $event_id ) {
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
			'filter_url'      => self::get_directory_url( self::DIRECTORY_LOCATION_ROUTE_BASE, $name ),
			// 2.3.61 — TEC venue single page (its own archive of events at this venue).
			'link'            => (string) get_permalink( $venue_id ),
		);
	}

	/**
	 * Get organizer-style producer details for a TEC event.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{name:string,email:string,phone:string,website:string}
	 */
	private static function get_tec_producer_details( $event_id ) {
		$organizer_id = self::get_tec_event_organizer_id( $event_id );

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
			'filter_url' => self::get_directory_url( self::DIRECTORY_PRODUCER_ROUTE_BASE, $name ),
			// 2.3.61 — TEC organizer single page (its own archive of events).
			'link'       => (string) get_permalink( $organizer_id ),
		);
	}

	/**
	 * Build a public Event Manager directory URL for venue- or producer-filtered listings.
	 *
	 * @param string $directory_type Route base.
	 * @param string $label Display label.
	 * @return string
	 */
	private static function get_directory_url( $directory_type, $label ) {
		$directory_type = sanitize_key( (string) $directory_type );
		$label_slug     = sanitize_title( (string) $label );

		if ( '' === $label_slug || ! in_array( $directory_type, array( self::DIRECTORY_LOCATION_ROUTE_BASE, self::DIRECTORY_PRODUCER_ROUTE_BASE ), true ) ) {
			return '';
		}

		return home_url( user_trailingslashit( self::get_event_rewrite_slug() . '/' . $directory_type . '/' . $label_slug ) );
	}

	/**
	 * Resolve a human-readable label for a venue/producer directory route.
	 *
	 * @param string $directory_type Route base.
	 * @param string $directory_slug Route slug.
	 * @return string
	 */
	private function find_directory_label( $directory_type, $directory_slug ) {
		$directory_slug = sanitize_title( (string) $directory_slug );

		foreach ( self::get_normalized_event_collection( array( 'limit' => 200, 'source' => 'all', 'timeframe' => 'all' ) ) as $event_data ) {
			if ( self::DIRECTORY_LOCATION_ROUTE_BASE === $directory_type && ! empty( $event_data['venue_name'] ) && sanitize_title( (string) $event_data['venue_name'] ) === $directory_slug ) {
				return (string) $event_data['venue_name'];
			}

			if ( self::DIRECTORY_PRODUCER_ROUTE_BASE === $directory_type && ! empty( $event_data['producer']['name'] ) && sanitize_title( (string) $event_data['producer']['name'] ) === $directory_slug ) {
				return (string) $event_data['producer']['name'];
			}
		}

		return ucwords( str_replace( '-', ' ', $directory_slug ) );
	}

	/**
	 * Get the reservation linked to an event, with TEC shortcode fallback.
	 *
	 * @param int $event_id Event post ID.
	 * @return int
	 */
	private static function get_linked_reservation_id_for_event( $event_id ) {
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
	 * Get a readable venue location label.
	 *
	 * @param int $venue_id Venue post ID.
	 * @return string
	 */
	private static function get_venue_location_label( $venue_id ) {
		$city  = (string) get_post_meta( $venue_id, '_equine_event_manager_venue_city', true );
		$state = (string) get_post_meta( $venue_id, '_equine_event_manager_venue_state', true );

		return trim( implode( ', ', array_filter( array( $city, $state ) ) ) );
	}

	/**
	 * Find an already-imported native event by TEC ID.
	 *
	 * @param int $tec_event_id TEC event ID.
	 * @return int
	 */
	private static function find_native_event_by_imported_tec_id( $tec_event_id ) {
		$existing = get_posts(
			array(
				'post_type'      => self::EVENT_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_equine_event_manager_imported_tec_event_id',
						'value' => absint( $tec_event_id ),
					),
				),
			)
		);

		return ! empty( $existing[0] ) ? absint( $existing[0] ) : 0;
	}

	/**
	 * Import or reuse a native venue from a TEC event.
	 *
	 * @param int $tec_event_id TEC event ID.
	 * @return int
	 */
	private static function import_tec_venue( $tec_event_id ) {
		$tec_venue_id = absint( get_post_meta( $tec_event_id, '_EventVenueID', true ) );

		if ( ! $tec_venue_id ) {
			return 0;
		}

		$existing = get_posts(
			array(
				'post_type'      => self::VENUE_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_equine_event_manager_imported_tec_venue_id',
						'value' => $tec_venue_id,
					),
				),
			)
		);

		if ( ! empty( $existing[0] ) ) {
			return absint( $existing[0] );
		}

		$venue_id = wp_insert_post(
			array(
				'post_type'   => self::VENUE_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => get_the_title( $tec_venue_id ),
				'post_content'=> get_post_field( 'post_content', $tec_venue_id ),
			),
			true
		);

		if ( is_wp_error( $venue_id ) ) {
			return 0;
		}

		update_post_meta( $venue_id, '_equine_event_manager_imported_tec_venue_id', $tec_venue_id );
		update_post_meta( $venue_id, '_equine_event_manager_venue_address_1', (string) get_post_meta( $tec_venue_id, '_VenueAddress', true ) );
		update_post_meta( $venue_id, '_equine_event_manager_venue_address_2', (string) get_post_meta( $tec_venue_id, '_VenueAddress2', true ) );
		update_post_meta( $venue_id, '_equine_event_manager_venue_city', (string) get_post_meta( $tec_venue_id, '_VenueCity', true ) );
		$state = (string) get_post_meta( $tec_venue_id, '_VenueStateProvince', true );
		if ( '' === $state ) {
			$state = (string) get_post_meta( $tec_venue_id, '_VenueState', true );
		}
		update_post_meta( $venue_id, '_equine_event_manager_venue_state', $state );
		update_post_meta( $venue_id, '_equine_event_manager_venue_postal_code', (string) get_post_meta( $tec_venue_id, '_VenueZip', true ) );
		update_post_meta( $venue_id, '_equine_event_manager_venue_phone', (string) get_post_meta( $tec_venue_id, '_VenuePhone', true ) );
		update_post_meta( $venue_id, '_equine_event_manager_venue_website', esc_url_raw( (string) get_post_meta( $tec_venue_id, '_VenueWebsite', true ) ) );

		return absint( $venue_id );
	}

	/**
	 * Import or reuse a native producer from a TEC organizer.
	 *
	 * @param int $tec_event_id TEC event ID.
	 * @return int
	 */
	private static function import_tec_producer( $tec_event_id ) {
		$organizer_id = self::get_tec_event_organizer_id( $tec_event_id );

		if ( ! $organizer_id ) {
			return 0;
		}

		$existing = get_posts(
			array(
				'post_type'      => self::PRODUCER_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_equine_event_manager_imported_tec_organizer_id',
						'value' => $organizer_id,
					),
				),
			)
		);

		if ( ! empty( $existing[0] ) ) {
			return absint( $existing[0] );
		}

		$producer_id = wp_insert_post(
			array(
				'post_type'   => self::PRODUCER_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => get_the_title( $organizer_id ),
				'post_content'=> get_post_field( 'post_content', $organizer_id ),
			),
			true
		);

		if ( is_wp_error( $producer_id ) ) {
			return 0;
		}

		update_post_meta( $producer_id, '_equine_event_manager_imported_tec_organizer_id', $organizer_id );
		update_post_meta( $producer_id, '_equine_event_manager_producer_contact_name', get_the_title( $organizer_id ) );
		update_post_meta( $producer_id, '_equine_event_manager_producer_email', sanitize_email( (string) get_post_meta( $organizer_id, '_OrganizerEmail', true ) ) );
		update_post_meta( $producer_id, '_equine_event_manager_producer_phone', (string) get_post_meta( $organizer_id, '_OrganizerPhone', true ) );
		update_post_meta( $producer_id, '_equine_event_manager_producer_website', esc_url_raw( (string) get_post_meta( $organizer_id, '_OrganizerWebsite', true ) ) );

		return absint( $producer_id );
	}

	/**
	 * Import TEC categories into native event categories.
	 *
	 * @param int $tec_event_id TEC event ID.
	 * @param int $event_id Native event ID.
	 * @return void
	 */
	private static function import_tec_categories( $tec_event_id, $event_id ) {
		$terms = wp_get_post_terms( $tec_event_id, 'tribe_events_cat' );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$term_ids = array();

		foreach ( $terms as $term ) {
			$existing = term_exists( $term->slug, self::EVENT_CATEGORY_TAXONOMY );

			if ( ! $existing ) {
				$existing = wp_insert_term(
					$term->name,
					self::EVENT_CATEGORY_TAXONOMY,
					array(
						'slug'        => $term->slug,
						'description' => $term->description,
					)
				);
			}

			if ( is_array( $existing ) && ! empty( $existing['term_id'] ) ) {
				$term_ids[] = absint( $existing['term_id'] );
			} elseif ( ! empty( $existing['term_id'] ) ) {
				$term_ids[] = absint( $existing['term_id'] );
			}
		}

		if ( ! empty( $term_ids ) ) {
			wp_set_post_terms( $event_id, $term_ids, self::EVENT_CATEGORY_TAXONOMY );
		}
	}

	/**
	 * Move a linked reservation from a TEC event to a native event.
	 *
	 * @param int $tec_event_id TEC event ID.
	 * @param int $native_event_id Native event ID.
	 * @return void
	 */
	private static function migrate_linked_reservation_to_native_event( $tec_event_id, $native_event_id ) {
		$reservation_id = absint( get_post_meta( $tec_event_id, '_equine_event_manager_reservation_id', true ) );

		if ( ! $reservation_id ) {
			$stored_shortcode = (string) get_post_meta( $tec_event_id, 'reservations', true );
			if ( preg_match( '/id="(\d+)"/', $stored_shortcode, $matches ) ) {
				$reservation_id = absint( $matches[1] );
			}
		}

		if ( ! $reservation_id || 'en_reservation' !== get_post_type( $reservation_id ) ) {
			return;
		}

		update_post_meta( $native_event_id, '_equine_event_manager_reservation_id', $reservation_id );
		update_post_meta( $reservation_id, '_en_event_source', 'native' );
		update_post_meta( $reservation_id, '_en_event_id', $native_event_id );
	}

	/**
	 * Get a readable location label from a TEC event.
	 *
	 * @param int $tec_event_id TEC event ID.
	 * @param int $venue_id Imported native venue ID.
	 * @return string
	 */
	private static function get_tec_event_location_label( $tec_event_id, $venue_id ) {
		if ( $venue_id ) {
			return self::get_venue_location_label( $venue_id );
		}

		$city = (string) get_post_meta( $tec_event_id, '_VenueCity', true );
		$state = (string) get_post_meta( $tec_event_id, '_VenueStateProvince', true );
		if ( '' === $state ) {
			$state = (string) get_post_meta( $tec_event_id, '_VenueState', true );
		}

		return trim( implode( ', ', array_filter( array( $city, $state ) ) ) );
	}

	/**
	 * Get the first organizer ID attached to a TEC event.
	 *
	 * @param int $tec_event_id TEC event ID.
	 * @return int
	 */
	private static function get_tec_event_organizer_id( $tec_event_id ) {
		$organizer_id = get_post_meta( $tec_event_id, '_EventOrganizerID', true );

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
}

/**
 * Upcoming native events widget.
 */
class EEM_Upcoming_Events_Widget extends WP_Widget {

	/**
	 * Set up widget details.
	 */
	public function __construct() {
		parent::__construct(
			'equine_event_manager_upcoming_events',
			__( 'Equine Upcoming Events', 'equine-event-manager' ),
			array(
				'description' => __( 'Shows upcoming native Equine Event Manager events.', 'equine-event-manager' ),
			)
		);
	}

	/**
	 * Render widget output.
	 *
	 * @param array $args Widget wrapper args.
	 * @param array $instance Saved widget instance.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Upcoming Events', 'equine-event-manager' );
		$limit = ! empty( $instance['limit'] ) ? absint( $instance['limit'] ) : 3;

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo do_shortcode( sprintf( '[equine_event_manager_events limit="%d"]', $limit ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render widget admin form.
	 *
	 * @param array $instance Saved widget instance.
	 * @return void
	 */
	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Upcoming Events', 'equine-event-manager' );
		$limit = ! empty( $instance['limit'] ) ? absint( $instance['limit'] ) : 3;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'equine-event-manager' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"><?php esc_html_e( 'Number of events:', 'equine-event-manager' ); ?></label>
			<input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>" type="number" min="1" max="12" value="<?php echo esc_attr( $limit ); ?>" />
		</p>
		<?php
	}

	/**
	 * Sanitize widget settings.
	 *
	 * @param array $new_instance New values.
	 * @param array $old_instance Previous values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title' => sanitize_text_field( isset( $new_instance['title'] ) ? $new_instance['title'] : '' ),
			'limit' => absint( isset( $new_instance['limit'] ) ? $new_instance['limit'] : 3 ),
		);
	}
}

/**
 * Featured native event widget.
 */
class EEM_Featured_Event_Widget extends WP_Widget {

	/**
	 * Set up widget details.
	 */
	public function __construct() {
		parent::__construct(
			'equine_event_manager_featured_event',
			__( 'Equine Featured Event', 'equine-event-manager' ),
			array(
				'description' => __( 'Shows the first featured native Equine Event Manager event.', 'equine-event-manager' ),
			)
		);
	}

	/**
	 * Render widget output.
	 *
	 * @param array $args Widget wrapper args.
	 * @param array $instance Saved widget instance.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Featured Event', 'equine-event-manager' );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo do_shortcode( '[equine_event_manager_events limit="1" featured="1"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render widget admin form.
	 *
	 * @param array $instance Saved widget instance.
	 * @return void
	 */
	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Featured Event', 'equine-event-manager' );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'equine-event-manager' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	/**
	 * Sanitize widget settings.
	 *
	 * @param array $new_instance New values.
	 * @param array $old_instance Previous values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title' => sanitize_text_field( isset( $new_instance['title'] ) ? $new_instance['title'] : '' ),
		);
	}
}

if ( ! function_exists( 'eem_get_reservation_url_for_event' ) ) {
	/**
	 * Public bridge for external plugins (e.g. GEMS for WordPress): return the
	 * URL of the EN reservation booking page linked to an external event id, or
	 * an empty string when no published reservation is attached.
	 *
	 * Usage in another plugin (guard against EEM being inactive):
	 *   if ( function_exists( 'eem_get_reservation_url_for_event' ) ) {
	 *       $url = eem_get_reservation_url_for_event( $event->eventUID );
	 *       if ( $url ) { echo "...Reservations button linking to $url..."; }
	 *   }
	 *
	 * @param string $external_event_id External event id (GEMS eventUID).
	 * @return string Reservation booking-page URL, or '' when none.
	 */
	function eem_get_reservation_url_for_event( $external_event_id ) {
		if ( ! class_exists( 'EEM_Events' ) ) {
			return '';
		}
		$match = EEM_Events::get_reservation_for_external_event( $external_event_id );
		return is_array( $match ) && ! empty( $match['url'] ) ? (string) $match['url'] : '';
	}
}
