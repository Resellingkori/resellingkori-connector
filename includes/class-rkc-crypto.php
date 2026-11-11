<?php
/**
 * Encryption of the API secret at rest (libsodium secretbox, XSalsa20-Poly1305).
 *
 * LIMITS (see SECURITY.md): the key is derived from the WordPress salts in wp-config.php, or from
 * RKC_ENCRYPTION_KEY when you define it there. A database-only leak (SQL injection, stolen backup, a
 * shared dump) does NOT reveal the secret. Someone who can read both the database and wp-config.php can
 * decrypt it, because signing requests needs the raw secret on this server. Changing the salts makes the
 * stored secret unreadable: enter the secret again in that case.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Crypto {

	const PREFIX = 'v1:';

	/** @return string 32-byte key. */
	private static function key() {
		if ( defined( 'RKC_ENCRYPTION_KEY' ) && is_string( RKC_ENCRYPTION_KEY ) && strlen( RKC_ENCRYPTION_KEY ) >= 32 ) {
			$material = 'const|' . RKC_ENCRYPTION_KEY;
		} else {
			$material = 'salts|' . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' );
		}
		return sodium_crypto_generichash( 'rkc-secret-v1|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/** @return string "constant" when RKC_ENCRYPTION_KEY is defined, otherwise "salts". */
	public static function key_source() {
		return ( defined( 'RKC_ENCRYPTION_KEY' ) && is_string( RKC_ENCRYPTION_KEY ) && strlen( RKC_ENCRYPTION_KEY ) >= 32 ) ? 'constant' : 'salts';
	}

	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_generichash' );
	}

	/** @return string Encrypted blob. */
	public static function encrypt( $plain ) {
		if ( ! self::available() ) {
			throw new RuntimeException( 'Encryption is not available on this server.' );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** @return string|false Plain text, or false when it cannot be decrypted. */
	public static function decrypt( $blob ) {
		$blob = (string) $blob;
		if ( 0 !== strpos( $blob, self::PREFIX ) || ! self::available() ) {
			return false;
		}
		$raw = base64_decode( substr( $blob, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return ( false === $plain ) ? false : $plain;
	}

	public static function self_test() {
		try {
			$t = 'rkc-selftest-' . bin2hex( random_bytes( 4 ) );
			return self::decrypt( self::encrypt( $t ) ) === $t;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	public static function random_hex( $bytes ) {
		return bin2hex( random_bytes( max( 1, (int) $bytes ) ) );
	}
}
