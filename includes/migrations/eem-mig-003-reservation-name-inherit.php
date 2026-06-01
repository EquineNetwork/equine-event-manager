<?php
/**
 * Migration #003 — Reservation names always inherit the linked event name.
 *
 * Product decision (2.3.56): admins can no longer name/slug a reservation; the
 * reservation title is ALWAYS the linked event's name. This one-time sweep:
 *
 *   1. For every `en_reservation` that has a linked event (`_en_event_id` set,
 *      non-zero), overwrites post_title + post_name with the resolved event
 *      title — overwriting any previously-custom name. Reservations with no
 *      linked event are left untouched (they keep their placeholder title until
 *      an event is linked).
 *   2. Deletes the now-vestigial override flags (`_eem_reservation_name_overridden`
 *      / `_eem_reservation_slug_overridden`) on ALL reservations — the override
 *      capability is retired and `apply_mirror()` no longer reads them.
 *
 * Flag-gated so it runs once. Row-level idempotent (re-running produces no
 * further change once titles already match).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run the reservation-name inheritance overwrite.
 *
 * @return array{renamed:int, flags_cleared:int, source:string}
 */
function eem_mig_003_reservation_name_inherit() {
	$flag_key = 'eem_mig_003_reservation_name_inherit_complete';
	if ( get_option( $flag_key ) ) {
		return array( 'renamed' => 0, 'flags_cleared' => 0, 'source' => 'already-complete' );
	}

	if ( ! class_exists( 'EEM_Reservations_CPT' ) || ! class_exists( 'EEM_Reservation_Source_Resolver' ) ) {
		// Dependencies not loaded yet — defer; re-runs on next admin load.
		return array( 'renamed' => 0, 'flags_cleared' => 0, 'source' => 'deps-missing' );
	}

	$reservation_ids = get_posts( array(
		'post_type'        => EEM_Reservations_CPT::POST_TYPE,
		'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page'   => -1,
		'fields'           => 'ids',
		'suppress_filters' => true,
	) );

	$renamed       = 0;
	$flags_cleared = 0;

	foreach ( (array) $reservation_ids as $reservation_id ) {
		$reservation_id = (int) $reservation_id;

		// Retire the override flags everywhere.
		if ( '' !== (string) get_post_meta( $reservation_id, '_eem_reservation_name_overridden', true ) ) {
			delete_post_meta( $reservation_id, '_eem_reservation_name_overridden' );
			$flags_cleared++;
		}
		delete_post_meta( $reservation_id, '_eem_reservation_slug_overridden' );

		// Overwrite the title from the linked event, when one resolves.
		$src   = EEM_Reservation_Source_Resolver::resolve_event_fields( $reservation_id );
		$title = isset( $src['title'] ) ? (string) $src['title'] : '';
		if ( '' === $title ) {
			continue; // No linked event — leave placeholder title.
		}

		$current = (string) get_post_field( 'post_title', $reservation_id );
		if ( $current !== $title ) {
			wp_update_post( array(
				'ID'         => $reservation_id,
				'post_title' => $title,
				'post_name'  => sanitize_title( $title ),
			) );
			$renamed++;
		}
	}

	update_option( $flag_key, time() );

	return array(
		'renamed'       => $renamed,
		'flags_cleared' => $flags_cleared,
		'source'        => 'sweep',
	);
}
