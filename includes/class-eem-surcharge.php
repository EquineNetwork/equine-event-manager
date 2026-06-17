<?php
/**
 * Per-rate-type surcharge value type (RV + stall premium pricing).
 *
 * A surcharge is a price bump applied to a premium spot — a paddock, an indoor
 * stall, a lakefront RV lot. It is expressed in the SAME unit the spot is sold
 * in, so it carries one amount per active rate type:
 *
 *   { nightly: float, packages: { <package_id>: float, ... } }
 *
 *   - nightly  : added PER NIGHT on top of the base nightly rate.
 *   - packages : a FLAT amount added on top of each Stay Package price
 *                (e.g. a $500/week stall that's indoors costs $600/week → +100).
 *
 * The same value type attaches to several owners, all stacking "most layers add"
 * (a +$5 tab with a +$10 paddock area inside → those cells pay +$15):
 *   - a Lot/Stall Row (Quantity-mode tier),
 *   - a Map Builder tab/zone (whole-tab surcharge),
 *   - a painted area inside a tab (subset of cells).
 *
 * Pure value helpers — no DB, no WP calls beyond sanitize_key(). Unit-testable in
 * isolation. The canonical shape is produced by {@see self::sanitize()}; never
 * hand-build the array elsewhere.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical per-rate-type surcharge value + arithmetic.
 *
 * Caller contract: storage and POST values pass through {@see self::sanitize()}
 * before use; readers go through {@see self::nightly()} / {@see self::for_package()};
 * stacking goes through {@see self::add()}. The array shape is an implementation
 * detail — treat instances as opaque and operate via these helpers.
 */
class EEM_Surcharge {

	/**
	 * The canonical "no surcharge" value.
	 *
	 * @return array{nightly:float,packages:array<string,float>}
	 */
	public static function zero(): array {
		return array(
			'nightly'  => 0.0,
			'packages' => array(),
		);
	}

	/**
	 * Coerce any raw value (POST input, stored value, legacy float) into the
	 * canonical shape. Accepts a numeric (legacy nightly-only) or an array with
	 * `nightly` and/or `packages` keys; anything else becomes {@see self::zero()}.
	 *
	 * @param mixed $raw Numeric, array, or junk.
	 * @return array{nightly:float,packages:array<string,float>}
	 */
	public static function sanitize( $raw ): array {
		if ( is_numeric( $raw ) ) {
			return array(
				'nightly'  => self::money( $raw ),
				'packages' => array(),
			);
		}
		if ( ! is_array( $raw ) ) {
			return self::zero();
		}

		$nightly  = isset( $raw['nightly'] ) ? self::money( $raw['nightly'] ) : 0.0;
		$packages = array();
		if ( isset( $raw['packages'] ) && is_array( $raw['packages'] ) ) {
			foreach ( $raw['packages'] as $pid => $amt ) {
				$pid = sanitize_key( (string) $pid );
				if ( '' === $pid ) {
					continue;
				}
				$packages[ $pid ] = self::money( $amt );
			}
		}

		return array(
			'nightly'  => $nightly,
			'packages' => $packages,
		);
	}

	/**
	 * Build the canonical shape from a legacy nightly-only amount (migration).
	 *
	 * @param mixed $nightly Legacy per-night surcharge float.
	 * @return array{nightly:float,packages:array<string,float>}
	 */
	public static function from_legacy_nightly( $nightly ): array {
		return array(
			'nightly'  => self::money( $nightly ),
			'packages' => array(),
		);
	}

	/**
	 * Per-night surcharge amount.
	 *
	 * @param array $surcharge Canonical surcharge.
	 * @return float
	 */
	public static function nightly( array $surcharge ): float {
		return isset( $surcharge['nightly'] ) ? (float) $surcharge['nightly'] : 0.0;
	}

	/**
	 * Flat per-package surcharge for a given Stay Package id.
	 *
	 * @param array  $surcharge Canonical surcharge.
	 * @param string $pkg_id    Package identifier.
	 * @return float
	 */
	public static function for_package( array $surcharge, string $pkg_id ): float {
		$pkg_id = sanitize_key( $pkg_id );
		return isset( $surcharge['packages'][ $pkg_id ] ) ? (float) $surcharge['packages'][ $pkg_id ] : 0.0;
	}

	/**
	 * Stack two surcharges (tab + area): sum nightly, union-and-sum packages.
	 * Used to compute a cell's effective surcharge from its layered owners.
	 *
	 * @param mixed $a First surcharge (sanitized internally).
	 * @param mixed $b Second surcharge (sanitized internally).
	 * @return array{nightly:float,packages:array<string,float>}
	 */
	public static function add( $a, $b ): array {
		$a = self::sanitize( $a );
		$b = self::sanitize( $b );

		$out = array(
			'nightly'  => round( $a['nightly'] + $b['nightly'], 2 ),
			'packages' => $a['packages'],
		);
		foreach ( $b['packages'] as $pid => $amt ) {
			$out['packages'][ $pid ] = round( ( $out['packages'][ $pid ] ?? 0.0 ) + $amt, 2 );
		}
		return $out;
	}

	/**
	 * True when no surcharge applies (nightly 0 and every package amount 0).
	 *
	 * @param array $surcharge Canonical surcharge.
	 * @return bool
	 */
	public static function is_zero( array $surcharge ): bool {
		if ( self::nightly( $surcharge ) > 0 ) {
			return false;
		}
		foreach ( ( $surcharge['packages'] ?? array() ) as $amt ) {
			if ( (float) $amt > 0 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Clamp to a non-negative 2-decimal float (money).
	 *
	 * @param mixed $value Raw numeric.
	 * @return float
	 */
	private static function money( $value ): float {
		$float = (float) $value;
		if ( $float < 0 ) {
			$float = 0.0;
		}
		return round( $float, 2 );
	}
}
