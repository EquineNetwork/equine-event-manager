<?php
/**
 * Reservations REST controller.
 *
 * Exposes reservation list, detail, config read/write, and status
 * change via the WP REST API. Every read and write goes through the
 * existing repo classes — no direct DB or postmeta calls.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for reservations.
 *
 * Routes:
 *   GET  /eem/v1/reservations                — paginated list
 *   GET  /eem/v1/reservations/{id}           — detail (summary + config)
 *   GET  /eem/v1/reservations/{id}/config    — full config blob
 *   PUT  /eem/v1/reservations/{id}/config    — partial config update
 *   PUT  /eem/v1/reservations/{id}/status    — publish/draft/trash
 */
class EEM_REST_Reservations_Controller extends EEM_REST_Controller {

	/**
	 * Spatial/chart JSON keys stripped from the default config response.
	 * Opt-in via ?include=spatial.
	 *
	 * @var string[]
	 */
	const SPATIAL_KEYS = array(
		'stall_map',
		'rv_map',
		'stall_chart_stall_blocks',
		'stall_chart_rv_blocks',
		'stall_chart_blocked_stall_units',
		'stall_chart_blocked_rv_units',
	);

	/**
	 * CPT helper instance.
	 *
	 * @var EEM_Reservations_CPT
	 */
	private EEM_Reservations_CPT $cpt;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->cpt = new EEM_Reservations_CPT();
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/reservations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_reservations' ),
				'permission_callback' => array( static::class, 'require_staff' ),
				'args'                => $this->get_list_args(),
			)
		);

		$id_pattern = '/reservations/(?P<id>\d+)';

		register_rest_route(
			self::NAMESPACE,
			$id_pattern,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_reservation' ),
				'permission_callback' => array( static::class, 'require_staff' ),
				'args'                => $this->get_include_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$id_pattern . '/config',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => array( static::class, 'require_staff' ),
					'args'                => $this->get_include_args(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_config' ),
					'permission_callback' => array( static::class, 'require_admin' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$id_pattern . '/status',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_status' ),
				'permission_callback' => array( static::class, 'require_admin' ),
				'args'                => array(
					'status' => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( 'publish', 'draft', 'trash', 'pending' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * GET /reservations — paginated list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_reservations( WP_REST_Request $request ): WP_REST_Response {
		$result = EEM_Reservations_List_Repo::get_paginated(
			array(
				'status'      => $request->get_param( 'status' ),
				'search'      => $request->get_param( 'search' ),
				'orderby'     => $request->get_param( 'orderby' ),
				'order'       => $request->get_param( 'order' ),
				'paged'       => (int) $request->get_param( 'page' ),
				'per_page'    => (int) $request->get_param( 'per_page' ),
				'date_filter' => $request->get_param( 'date_filter' ),
			)
		);

		$items = array();
		foreach ( $result['items'] as $post ) {
			$items[] = $this->shape_list_item( $post );
		}

		return $this->respond_paginated(
			$items,
			$result['total'],
			$result['page'],
			$result['per_page']
		);
	}

	/**
	 * GET /reservations/{id} — detail with summary + config.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_reservation( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'en_reservation' !== $post->post_type ) {
			return $this->respond_error( 'reservation_not_found', __( 'Reservation not found.', 'equine-event-manager' ), 404 );
		}

		$include_spatial = $this->wants_spatial( $request );
		$summary         = $this->cpt->get_editor_summary( $id );
		$config          = EEM_Reservation_Config::for( $id )->all();

		if ( ! $include_spatial ) {
			$config = $this->strip_spatial( $config );
		}

		return $this->respond(
			array(
				'id'      => $id,
				'title'   => $post->post_title,
				'status'  => $post->post_status,
				'summary' => $summary,
				'config'  => $config,
			)
		);
	}

	/**
	 * GET /reservations/{id}/config — full config blob.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_config( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'en_reservation' !== $post->post_type ) {
			return $this->respond_error( 'reservation_not_found', __( 'Reservation not found.', 'equine-event-manager' ), 404 );
		}

		$config = EEM_Reservation_Config::for( $id )->all();

		if ( ! $this->wants_spatial( $request ) ) {
			$config = $this->strip_spatial( $config );
		}

		return $this->respond( $config );
	}

	/**
	 * PUT /reservations/{id}/config — partial config update.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function update_config( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'en_reservation' !== $post->post_type ) {
			return $this->respond_error( 'reservation_not_found', __( 'Reservation not found.', 'equine-event-manager' ), 404 );
		}

		$body = $request->get_json_params();
		if ( empty( $body ) || ! is_array( $body ) ) {
			return $this->respond_error( 'invalid_body', __( 'Request body must be a JSON object with config keys.', 'equine-event-manager' ), 400 );
		}

		$instance = EEM_Reservation_Config::for( $id );
		$instance->set_many( $body )->save();

		EEM_Reservation_Config::flush_cache( $id );
		$updated = EEM_Reservation_Config::for( $id )->all();

		return $this->respond( $this->strip_spatial( $updated ) );
	}

	/**
	 * PUT /reservations/{id}/status — change post status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function update_status( WP_REST_Request $request ): WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'en_reservation' !== $post->post_type ) {
			return $this->respond_error( 'reservation_not_found', __( 'Reservation not found.', 'equine-event-manager' ), 404 );
		}

		$status = $request->get_param( 'status' );
		$result = EEM_Reservations_CPT::update_status( $id, $status );

		if ( ! $result ) {
			return $this->respond_error( 'status_update_failed', __( 'Failed to update reservation status.', 'equine-event-manager' ), 422 );
		}

		$updated_post = get_post( $id );

		return $this->respond(
			array(
				'id'     => $id,
				'title'  => $updated_post->post_title,
				'status' => $updated_post->post_status,
			)
		);
	}

	/**
	 * Shape a WP_Post into a list-item representation.
	 *
	 * @param WP_Post $post Reservation post.
	 * @return array
	 */
	private function shape_list_item( WP_Post $post ): array {
		$id = $post->ID;

		return array(
			'id'           => $id,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'type_badges'  => EEM_Reservations_List_Repo::get_type_badges( $id ),
			'orders_count' => EEM_Reservations_List_Repo::get_orders_count_for_reservation( $id ),
			'event_dates'  => EEM_Reservations_List_Repo::get_event_date_range_label( $id ),
			'created_at'   => $post->post_date,
			'modified_at'  => $post->post_modified,
		);
	}

	/**
	 * Whether the request opts in to spatial data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	private function wants_spatial( WP_REST_Request $request ): bool {
		$include = $request->get_param( 'include' );
		return 'spatial' === $include;
	}

	/**
	 * Remove spatial/chart keys from a config array.
	 *
	 * @param array $config Full config array.
	 * @return array Config without spatial keys.
	 */
	private function strip_spatial( array $config ): array {
		foreach ( self::SPATIAL_KEYS as $key ) {
			unset( $config[ $key ] );
		}
		return $config;
	}

	/**
	 * Argument definitions for the list endpoint.
	 *
	 * @return array
	 */
	private function get_list_args(): array {
		return array(
			'page'        => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'    => array(
				'type'              => 'integer',
				'default'           => 25,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'status'      => array(
				'type'              => 'string',
				'default'           => 'all',
				'sanitize_callback' => 'sanitize_key',
			),
			'orderby'     => array(
				'type'              => 'string',
				'default'           => 'event_dates',
				'enum'              => array( 'event_dates', 'title', 'date', 'modified' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'order'       => array(
				'type'              => 'string',
				'default'           => 'asc',
				'enum'              => array( 'asc', 'desc' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'search'      => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_filter' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Argument definitions for the include param.
	 *
	 * @return array
	 */
	private function get_include_args(): array {
		return array(
			'include' => array(
				'type'              => 'string',
				'default'           => '',
				'enum'              => array( '', 'spatial' ),
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}
}
