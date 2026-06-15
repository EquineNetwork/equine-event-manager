<?php
/**
 * Venues REST controller.
 *
 * Authenticated endpoints for the canonical venue entity and its saved layouts.
 * Venues are source-agnostic — a single venue row unifies references from TEC,
 * GEMS, and Native Events via the source map.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for Venues.
 *
 * Routes:
 *   GET /eem/v1/venues                          — list with counts (staff+)
 *   GET /eem/v1/venues/{id}                     — detail + source mappings (staff+)
 *   GET /eem/v1/venues/{id}/layouts             — saved layouts for a venue (staff+)
 *   GET /eem/v1/venues/{id}/layouts/{layout_id} — single layout with full snapshot (staff+)
 */
class EEM_REST_Venues_Controller extends EEM_REST_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/venues',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_venues' ),
				'permission_callback' => array( static::class, 'require_staff' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/venues/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_venue' ),
				'permission_callback' => array( static::class, 'require_staff' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/venues/(?P<id>\d+)/layouts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_layouts' ),
				'permission_callback' => array( static::class, 'require_staff' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/venues/(?P<id>\d+)/layouts/(?P<layout_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_layout' ),
				'permission_callback' => array( static::class, 'require_staff' ),
			)
		);
	}

	/**
	 * GET /venues — all venues with layout and source counts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_venues( WP_REST_Request $request ): WP_REST_Response {
		$rows = EEM_Venue::all_with_counts();

		$items = array_map(
			static function ( array $row ): array {
				return array(
					'id'           => (int) $row['id'],
					'name'         => (string) $row['name'],
					'layout_count' => (int) $row['layout_count'],
					'source_count' => (int) $row['source_count'],
					'created_at'   => (string) $row['created_at'],
				);
			},
			$rows
		);

		return $this->respond( $items );
	}

	/**
	 * GET /venues/{id} — venue detail with address, coordinates, and source mappings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_venue( WP_REST_Request $request ): WP_REST_Response {
		$id  = (int) $request->get_param( 'id' );
		$row = EEM_Venue::get( $id );

		if ( ! $row ) {
			return $this->respond_error( 'venue_not_found', __( 'Venue not found.', 'equine-event-manager' ), 404 );
		}

		$detail   = EEM_Venue::get_detail( $id );
		$mappings = EEM_Venue::get_source_mappings( $id );

		return $this->respond( array(
			'id'              => (int) $row['id'],
			'name'            => (string) $row['name'],
			'created_at'      => (string) $row['created_at'],
			'address'         => array(
				'address_1'   => $detail['address_1'],
				'address_2'   => $detail['address_2'],
				'city'        => $detail['city'],
				'state'       => $detail['state'],
				'postal_code' => $detail['postal_code'],
			),
			'phone'           => $detail['phone'],
			'website'         => $detail['website'],
			'coordinates'     => array(
				'lat' => $detail['lat'],
				'lng' => $detail['lng'],
			),
			'geocoded_address' => $detail['geocoded_address'],
			'source_mappings'  => array_map(
				static function ( array $m ): array {
					return array(
						'id'                => (int) $m['id'],
						'source'            => (string) $m['source'],
						'source_venue_id'   => (string) $m['source_venue_id'],
						'source_venue_name' => (string) $m['source_venue_name'],
					);
				},
				$mappings
			),
		) );
	}

	/**
	 * GET /venues/{id}/layouts — saved layouts (metadata only, no snapshot).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_layouts( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		if ( ! EEM_Venue::get( $id ) ) {
			return $this->respond_error( 'venue_not_found', __( 'Venue not found.', 'equine-event-manager' ), 404 );
		}

		$layouts = EEM_Venue::get_layouts( $id );

		$items = array_map(
			static function ( array $row ): array {
				return array(
					'id'          => (int) $row['id'],
					'venue_id'    => (int) $row['venue_id'],
					'name'        => (string) $row['name'],
					'based_on_id' => (int) $row['based_on_id'],
					'created_at'  => (string) $row['created_at'],
				);
			},
			$layouts
		);

		return $this->respond( $items );
	}

	/**
	 * GET /venues/{id}/layouts/{layout_id} — single layout with full snapshot.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_layout( WP_REST_Request $request ): WP_REST_Response {
		$venue_id  = (int) $request->get_param( 'id' );
		$layout_id = (int) $request->get_param( 'layout_id' );

		$layout = EEM_Venue::get_layout( $layout_id );

		if ( ! $layout || (int) $layout['venue_id'] !== $venue_id ) {
			return $this->respond_error( 'layout_not_found', __( 'Layout not found.', 'equine-event-manager' ), 404 );
		}

		return $this->respond( array(
			'id'          => (int) $layout['id'],
			'venue_id'    => (int) $layout['venue_id'],
			'name'        => (string) $layout['name'],
			'based_on_id' => (int) $layout['based_on_id'],
			'created_at'  => (string) $layout['created_at'],
			'layout'      => $layout['layout'],
		) );
	}
}
