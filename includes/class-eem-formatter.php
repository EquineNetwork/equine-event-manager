<?php
/**
 * Shared display formatters.
 *
 * One source of truth for cross-surface display formatting so the same value
 * renders identically everywhere (lists, detail pages, reports, exports, REST).
 *
 * @package EEM_Plugin
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EEM_Formatter — static display helpers.
 */
class EEM_Formatter {

	/**
	 * Render an order number as the canonical "#NNNNN" (5-digit zero-padded).
	 *
	 * Preserves a leading alpha source-prefix (e.g. "IMP-" on CSV-imported
	 * orders) so an order's origin stays visible; only the numeric portion is
	 * zero-padded. Plain numeric numbers render as "#00020"; empty/no-digit
	 * input renders as "#00000". Display-side only — never mutates stored data.
	 *
	 * @param int|string $order_number Whatever the order repo stored.
	 * @return string Formatted order number, e.g. "#00020" or "IMP-90668".
	 */
	public static function format_order_number( $order_number ): string {
		$raw = trim( (string) $order_number );
		if ( preg_match( '/^([A-Za-z]+)-?(\d+)$/', $raw, $m ) ) {
			return sprintf( '%s-%05d', strtoupper( $m[1] ), (int) $m[2] );
		}
		$digits = preg_replace( '/\D/', '', $raw );
		$n      = '' === $digits ? 0 : (int) $digits;
		return sprintf( '#%05d', $n );
	}
}
