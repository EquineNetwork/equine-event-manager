<?php
/**
 * Cancellation policy resolver (C7.A).
 *
 * Single source of truth for "what's the cancellation policy text to
 * display for THIS reservation" — consumed by all four customer-facing
 * surfaces (event_page.html checkout, confirmation email, PDF receipt,
 * hosted order page) once those ship in C10/C11/C12.
 *
 * Precedence (per HANDOFF 9.2 + 9.6):
 *   1. _eem_cancellation_policy_enabled = false  → null (suppress block)
 *   2. _eem_cancellation_policy_override present → override text
 *   3. wp_eem_event_defaults row for reservation's event → event default
 *   4. nothing → null (consumers gracefully omit block)
 *
 * Per Q7: empty `_eem_cancellation_policy_enabled` meta resolves to
 * ENABLED (default ON). Only an explicit non-truthy value suppresses.
 *
 * Per §3 corrected audit: event_id + event_source come from
 * `EEM_Events::get_effective_reservation_event_source()` + the matching
 * meta key (`_en_event_id` for native/tec, `_en_external_event_id` for
 * feed). NOT from EEM_Reservation_Source_Resolver::resolve_event_fields()
 * which returns title/start/end/venue only.
 *
 * Request-scoped memo cache — same reservation rendered in multiple
 * surfaces per request hits the DB once. Invalidated on any
 * EEM_Event_Defaults_Repo::set_cancellation_policy() call via
 * eem_clear_cancellation_policy_resolver_cache() (per in-flight C).
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Whitney Mitchell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the displayable cancellation policy text for a reservation.
 *
 * @param int $reservation_id
 * @return string|null  Policy text, or null when no policy should display
 */
function eem_resolve_cancellation_policy( $reservation_id ) {
	$reservation_id = absint( $reservation_id );
	if ( $reservation_id <= 0 ) {
		return null;
	}

	$cache = eem_cancellation_policy_resolver_cache();
	if ( array_key_exists( $reservation_id, $cache ) ) {
		return $cache[ $reservation_id ];
	}

	// HANDOFF 9.6: toggle OFF suppresses entirely. Empty meta = ON (Q7).
	$enabled_raw = get_post_meta( $reservation_id, '_eem_cancellation_policy_enabled', true );
	if ( '' !== $enabled_raw && ! filter_var( $enabled_raw, FILTER_VALIDATE_BOOLEAN ) ) {
		return eem_cancellation_policy_resolver_cache_set( $reservation_id, null );
	}

	// HANDOFF 9.2 step 1: per-reservation override wins.
	$override = (string) get_post_meta( $reservation_id, '_eem_cancellation_policy_override', true );
	if ( '' !== trim( $override ) ) {
		return eem_cancellation_policy_resolver_cache_set( $reservation_id, $override );
	}

	// HANDOFF 9.2 step 2: event-default fallback.
	if ( ! class_exists( 'EEM_Events' ) || ! class_exists( 'EEM_Event_Defaults_Repo' ) ) {
		return eem_cancellation_policy_resolver_cache_set( $reservation_id, null );
	}

	$event_source = EEM_Events::get_effective_reservation_event_source( $reservation_id );
	$event_id_meta_key = in_array( $event_source, array( 'native', 'tec' ), true )
		? '_en_event_id'
		: '_en_external_event_id';
	$event_id = (string) get_post_meta( $reservation_id, $event_id_meta_key, true );

	if ( '' !== trim( $event_id ) ) {
		$repo    = new EEM_Event_Defaults_Repo();
		$default = $repo->get_cancellation_policy( $event_id, $event_source );
		if ( null !== $default && '' !== trim( $default ) ) {
			return eem_cancellation_policy_resolver_cache_set( $reservation_id, $default );
		}
	}

	return eem_cancellation_policy_resolver_cache_set( $reservation_id, null );
}

/**
 * Convenience predicate — is the cancellation policy block enabled for
 * this reservation? Empty meta resolves to TRUE per Q7.
 *
 * @param int $reservation_id
 * @return bool
 */
function eem_is_cancellation_policy_enabled( $reservation_id ) {
	$reservation_id = absint( $reservation_id );
	if ( $reservation_id <= 0 ) {
		return false;
	}
	$raw = get_post_meta( $reservation_id, '_eem_cancellation_policy_enabled', true );
	if ( '' === $raw ) {
		return true;
	}
	return (bool) filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
}

/**
 * Request-scoped memo cache accessor. Returns the current cache map
 * (reservation_id => resolved string|null).
 *
 * @return array<int, string|null>
 */
function eem_cancellation_policy_resolver_cache() {
	$cache =& eem_cancellation_policy_resolver_cache_ref();
	return $cache;
}

/**
 * Set + return the cached value for a reservation_id. Uses a
 * reference-passing trick so the static cache in
 * eem_cancellation_policy_resolver_cache() stays the single store.
 *
 * @param int         $reservation_id
 * @param string|null $value
 * @return string|null
 */
function eem_cancellation_policy_resolver_cache_set( $reservation_id, $value ) {
	$cache =& eem_cancellation_policy_resolver_cache_ref();
	$cache[ (int) $reservation_id ] = $value;
	return $value;
}

/**
 * Clear the entire resolver cache. Called from
 * EEM_Event_Defaults_Repo::set_cancellation_policy() on any write
 * (per in-flight C — simplest correct invalidation, given the cache
 * is small + writes are rare).
 *
 * @return void
 */
function eem_clear_cancellation_policy_resolver_cache() {
	$cache =& eem_cancellation_policy_resolver_cache_ref();
	$cache = array();
}

/**
 * Reference-returning accessor for the static cache map. Internal —
 * keeps the static state in one place while letting set/clear mutate
 * it without each function maintaining its own static.
 *
 * @return array<int, string|null>
 */
function &eem_cancellation_policy_resolver_cache_ref() {
	static $cache = array();
	return $cache;
}
