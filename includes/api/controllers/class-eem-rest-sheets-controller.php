<?php
/**
 * Sheets & Results REST controller.
 *
 * Public (no-auth) endpoints for draw-sheet and result PDF listings per event,
 * and individual entry detail. PDF attachment IDs are resolved to URLs so
 * consumers never need WordPress internals.
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for Sheets & Results.
 *
 * Routes:
 *   GET /eem/v1/events/{id}/sheets  — grouped by discipline with counts (public)
 *   GET /eem/v1/sheets/{id}         — single entry detail (public)
 */
class EEM_REST_Sheets_Controller extends EEM_REST_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/events/(?P<id>\d+)/sheets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_sheets' ),
				'permission_callback' => array( static::class, 'allow_public' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sheets/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sheet' ),
				'permission_callback' => array( static::class, 'allow_public' ),
			)
		);
	}

	/**
	 * GET /events/{id}/sheets — disciplines with entries, plus aggregate counts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_sheets( WP_REST_Request $request ): WP_REST_Response {
		$event_id = (int) $request->get_param( 'id' );

		if ( 'publish' !== get_post_status( $event_id ) ) {
			return $this->respond_error(
				'event_not_found',
				__( 'Event not found.', 'equine-event-manager' ),
				404
			);
		}

		$groups = EEM_Sheet_Entries::get_for_event_grouped_by_discipline( $event_id );

		foreach ( $groups as &$group ) {
			$group['entries'] = array_map(
				array( $this, 'shape_entry' ),
				$group['entries']
			);
		}
		unset( $group );

		return $this->respond( array(
			'event_id'    => $event_id,
			'counts'      => EEM_Sheet_Entries::counts( $event_id ),
			'disciplines' => $groups,
		) );
	}

	/**
	 * GET /sheets/{id} — single entry detail.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_sheet( WP_REST_Request $request ): WP_REST_Response {
		$id    = (int) $request->get_param( 'id' );
		$entry = EEM_Sheet_Entries::get( $id );

		if ( ! $entry ) {
			return $this->respond_error(
				'sheet_entry_not_found',
				__( 'Sheet entry not found.', 'equine-event-manager' ),
				404
			);
		}

		return $this->respond( $this->shape_entry( $entry ) );
	}

	/**
	 * Transform a raw entry array into the API response shape.
	 *
	 * Resolves attachment IDs to {url, filename} objects or null.
	 *
	 * @param array $entry Typed row from EEM_Sheet_Entries::shape_row().
	 * @return array
	 */
	private function shape_entry( array $entry ): array {
		return array(
			'id'              => $entry['id'],
			'event_id'        => $entry['event_id'],
			'discipline_id'   => $entry['discipline_id'],
			'label'           => $entry['label'],
			'round'           => $entry['round'],
			'round_label'     => EEM_Sheet_Entries::round_label( $entry['round'] ),
			'entry_date'      => $entry['entry_date'],
			'drawsheet_pdf'   => $this->resolve_pdf( $entry['drawsheet_pdf'] ),
			'result_pdf'      => $this->resolve_pdf( $entry['result_pdf'] ),
			'sort_order'      => $entry['sort_order'],
		);
	}

	/**
	 * Resolve a WordPress attachment ID to a {url, filename} object.
	 *
	 * @param int $attachment_id Media Library attachment ID (0 = none).
	 * @return array{url:string,filename:string}|null Null when no PDF.
	 */
	private function resolve_pdf( int $attachment_id ) {
		if ( $attachment_id <= 0 ) {
			return null;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return null;
		}

		$filename = basename( get_attached_file( $attachment_id ) ?: $url );

		return array(
			'url'      => $url,
			'filename' => $filename,
		);
	}
}
