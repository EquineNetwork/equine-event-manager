<?php
/**
 * EEM_Secret_Store — transparent at-rest encryption for the payment-gateway
 * secret credentials (ship-readiness P5 / task #20).
 *
 * The Stripe + Authorize.net secret keys live inside the
 * `equine_event_manager_payment_settings` wp_option, historically as plaintext.
 * This class encrypts the secret fields on the way INTO the option and decrypts
 * them on the way OUT, using WordPress's own `pre_update_option_{$option}` /
 * `option_{$option}` filters — so every existing reader (gateway charge code,
 * refund engine, dashboard, setup checklist) keeps receiving plaintext with NO
 * code change at the call sites.
 *
 * Design goals (non-breaking, fail-safe):
 *  - **Backward compatible:** a stored value with no ciphertext envelope is
 *    returned verbatim (legacy plaintext keeps working). Encryption only takes
 *    effect once the gateway settings are next saved.
 *  - **Never store something we can't read:** if libsodium is unavailable the
 *    encrypt step is a no-op (the value stays plaintext) rather than producing
 *    an unreadable blob.
 *  - **Fail closed, not wrong:** if a ciphertext can't be decrypted (e.g. the
 *    site auth salts changed after encryption) the reader gets an empty string,
 *    so the gateway reports "missing credentials" and the admin re-enters them —
 *    it never silently charges with a corrupt key.
 *
 * Key material is derived from the site's wp-config auth salts, so the key is
 * stable across requests, unique per site, and never itself stored in the DB.
 *
 * @package   EEM_Plugin
 * @license   GPL-2.0-or-later
 * @copyright 2024-2026 Equine Network
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transparent encryption layer for the payment-settings option.
 */
class EEM_Secret_Store {

	/** Option whose secret fields are encrypted at rest. */
	private const OPTION = 'equine_event_manager_payment_settings';

	/** Ciphertext envelope marker + version (lets us evolve the scheme later). */
	private const PREFIX = 'eemenc:v1:';

	/**
	 * Secret field paths within the option, as [group, field] pairs. Only these
	 * are encrypted; publishable keys / API login IDs / mode flags stay clear.
	 *
	 * @var array<int, array{0:string,1:string}>
	 */
	private const SECRET_PATHS = array(
		array( 'stripe', 'test_secret_key' ),
		array( 'stripe', 'live_secret_key' ),
		array( 'stripe', 'webhook_signing_secret' ),
		array( 'authorize_net', 'test_transaction_key' ),
		array( 'authorize_net', 'live_transaction_key' ),
	);

	/**
	 * Register the option read/write filters. Called once during plugin run().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'pre_update_option_' . self::OPTION, array( __CLASS__, 'on_update' ), 10, 1 );
		add_filter( 'option_' . self::OPTION, array( __CLASS__, 'on_read' ), 10, 1 );
	}

	/**
	 * Whether libsodium symmetric encryption is available on this host.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * Whether a value already carries our ciphertext envelope.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $value ): bool {
		return is_string( $value ) && 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Encrypt secret fields before the option is written.
	 *
	 * @param mixed $value Option value about to be saved.
	 * @return mixed Value with secret fields encrypted (when possible).
	 */
	public static function on_update( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( self::SECRET_PATHS as $path ) {
			list( $group, $field ) = $path;
			if ( isset( $value[ $group ][ $field ] ) && is_string( $value[ $group ][ $field ] ) ) {
				$value[ $group ][ $field ] = self::encrypt( $value[ $group ][ $field ] );
			}
		}
		return $value;
	}

	/**
	 * Decrypt secret fields as the option is read.
	 *
	 * @param mixed $value Stored option value.
	 * @return mixed Value with secret fields decrypted to plaintext.
	 */
	public static function on_read( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( self::SECRET_PATHS as $path ) {
			list( $group, $field ) = $path;
			if ( isset( $value[ $group ][ $field ] ) && self::is_encrypted( $value[ $group ][ $field ] ) ) {
				$value[ $group ][ $field ] = self::decrypt( $value[ $group ][ $field ] );
			}
		}
		return $value;
	}

	/**
	 * Encrypt a single plaintext secret into the ciphertext envelope.
	 *
	 * Empty strings (the write-only "leave unchanged" sentinel) and values that
	 * are already encrypted pass through untouched. If libsodium is unavailable
	 * the plaintext is returned as-is so we never persist an unreadable value.
	 *
	 * @param string $plaintext Secret to encrypt.
	 * @return string Ciphertext envelope, or the original value when not encryptable.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || self::is_encrypted( $plaintext ) || ! self::available() ) {
			return $plaintext;
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
		$out    = self::PREFIX . base64_encode( $nonce . $cipher );
		sodium_memzero( $plaintext );
		return $out;
	}

	/**
	 * Decrypt a ciphertext envelope back to plaintext.
	 *
	 * Non-envelope values are returned verbatim (legacy plaintext). A value that
	 * fails to decrypt yields '' (fail closed) rather than leaking the ciphertext.
	 *
	 * @param string $value Stored value.
	 * @return string Plaintext, or '' when an envelope can't be opened.
	 */
	public static function decrypt( string $value ): string {
		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}
		if ( ! self::available() ) {
			return '';
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Derive the 32-byte symmetric key from the site's auth salts.
	 *
	 * Uses the wp-config salts (never the DB) so the key is stable per site and
	 * absent from any backup of the database alone. Falls back to wp_salt() when
	 * the explicit constants aren't defined.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function key(): string {
		$material = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $const ) {
			if ( defined( $const ) ) {
				$material .= (string) constant( $const );
			}
		}
		if ( '' === $material ) {
			$material = wp_salt( 'auth' );
		}
		return sodium_crypto_generichash( 'eem-secret-store|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
