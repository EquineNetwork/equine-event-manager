<?php
/**
 * Events REST controller.
 *
 * Public (no-auth) endpoints for event listing and detail. The list endpoint
 * returns the lightweight card shape via EEM_Events_Repo::get_for_event_listing();
 * the detail endpoint returns the full normalized shape via EEM_Events_Repo::get().
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for events.
 *
 * Routes:
 *   GET /eem/v1/events       — paginated event card listing (public)
 *   GET /eem/v1/events/{id}  — full normalized event detail (public)
 */
class EEM_REST_Events_Controller extends EEM_REST_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_events' ),
				'permission_callback' => array( static::class, 'allow_public' ),
				'args'                => $this->get_list_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/events/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_event' ),
				'permission_callback' => array( static::class, 'allow_public' ),
			)
		);
	}

	/**
	 * GET /events — paginated event card listing.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_events( WP_REST_Request $request ): WP_REST_Response {
		$result = EEM_Events_Repo::get_for_event_listing(
			array(
				'page'          => (int) $request->get_param( 'page' ),
				'per_page'      => (int) $request->get_param( 'per_page' ),
				'source'        => $request->get_param( 'source' ),
				'timeframe'     => $request->get_param( 'timeframe' ),
				'search'        => $request->get_param( 'search' ),
				'venue_name'    => $request->get_param( 'venue_name' ),
				'producer_name' => $request->get_param( 'producer_name' ),
				'category'      => $request->get_param( 'category' ),
				'venue'         => (int) $request->get_param( 'venue' ),
				'producer'      => (int) $request->get_param( 'producer' ),
				'featured'      => ! empty( $request->get_param( 'featured' ) ),
				'orderby'       => $request->get_param( 'orderby' ),
				'order'         => $request->get_param( 'order' ),
			)
		);

		return $this->respond_paginated(
			$result['items'],
			$result['total'],
			$result['page'],
			$result['per_page']
		);
	}

	/**
	 * GET /events/{id} — full normalized event detail.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_event( WP_REST_Request $request ): WP_REST_Response {
		$id         = (int) $request->get_param( 'id' );
		$event_data = EEM_Events_Repo::get( $id );

		if ( empty( $event_data ) ) {
			return $this->respond_error( 'event_not_found', __( 'Event not found.', 'equine-event-manager' ), 404 );
		}

		return $this->respond( $event_data );
	}

	/**
	 * Argument definitions for the list endpoint.
	 *
	 * @return array
	 */
	private function get_list_args(): array {
		return array(
			'page'          => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'      => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'source'        => array(
				'type'              => 'string',
				'default'           => 'all',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'timeframe'     => array(
				'type'              => 'string',
				'default'           => 'current_upcoming',
				'enum'              => array( 'current_upcoming', 'past', 'ongoing', 'all' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'search'        => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'venue_name'    => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'producer_name' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'category'      => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'venue'         => array(
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
			'producer'      => array(
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
			'featured'      => array(
				'type'              => 'boolean',
				'default'           => false,
			),
			'orderby'       => array(
				'type'              => 'string',
				'default'           => 'date',
				'enum'              => array( 'date', 'title' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'order'         => array(
				'type'              => 'string',
				'default'           => 'asc',
				'enum'              => array( 'asc', 'desc' ),
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}
}
