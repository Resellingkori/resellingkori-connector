<?php
/**
 * The connection to ResellingKori: key id, ENCRYPTED secret, and what the last real authenticated call returned.
 *
 *  - The API address is fixed (https://resellingkori.com). It is not a setting. A developer may point a NON-production
 *    site at a test server by defining RKC_DEV_API_BASE in wp-config.php; this is ignored when the environment type is
 *    "production".
 *  - The secret is stored encrypted, in its own non-autoloaded option, and is never printed, localised to JavaScript,
 *    returned by AJAX/REST, logged or exported.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Config {

	const OPTION          = 'rkc_connection';
	const PRODUCTION_BASE = 'https://resellingkori.com';

	private static $cache = null;

	public static function is_dev() {
		return defined( 'RKC_DEV_API_BASE' ) && is_string( RKC_DEV_API_BASE ) && '' !== RKC_DEV_API_BASE && 'production' !== wp_get_environment_type();
	}

	/** @return string API origin without trailing slash. */
	public static function base_url() {
		if ( self::is_dev() ) {
			$p = wp_parse_url( RKC_DEV_API_BASE );
			if ( is_array( $p ) && ! empty( $p['host'] ) && ! empty( $p['scheme'] ) && in_array( $p['scheme'], array( 'http', 'https' ), true ) && ! isset( $p['user'] ) && ! isset( $p['pass'] ) ) {
				return untrailingslashit( $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' ) . ( isset( $p['path'] ) ? $p['path'] : '' ) );
			}
		}
		return self::PRODUCTION_BASE;
	}

	public static function defaults() {
		return array(
			'key_id'         => '',
			'secret_enc'     => '',
			'status'         => 'none', // none | unverified | connected | error
			'connected_at'   => 0,
			'verified_at'    => 0,
			'store_uid'      => '',
			'store_name'     => '',
			'agent_name'     => '',
			'agent_hint'     => '',
			'agent_valid'    => false,
			'agent_match'    => null,
			'callback_state' => 'none', // none | pending | verified | failed | unreachable | rejected
			'callback_msg'   => '',
			'api_version'    => '',
			'last_error'     => '',
			'wizard_step'    => 1,
		);
	}

	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			self::$cache = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		}
		return null === $key ? self::$cache : ( isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null );
	}

	private static function write( array $data ) {
		self::$cache = wp_parse_args( $data, self::defaults() );
		update_option( self::OPTION, self::$cache, false ); // Never autoloaded.
	}

	public static function reset_cache() {
		self::$cache = null;
	}

	/** Merge non-secret fields (status, store info ...). */
	public static function mark( array $fields ) {
		unset( $fields['secret_enc'], $fields['key_id'] ); // Credentials change only through save_credentials().
		self::write( array_merge( self::get(), $fields ) );
	}

	public static function valid_key_id( $key_id ) {
		return (bool) preg_match( '/^[A-Za-z0-9_\-]{8,40}$/', (string) $key_id );
	}

	public static function valid_secret( $secret ) {
		return (bool) preg_match( '/^[A-Za-z0-9_\-]{20,200}$/', (string) $secret );
	}

	/**
	 * Store a key id and secret (encrypted). Status becomes "unverified" until a real call succeeds.
	 *
	 * @return true|WP_Error
	 */
	public static function save_credentials( $key_id, $secret ) {
		$key_id = trim( (string) $key_id );
		$secret = trim( (string) $secret );
		if ( ! self::valid_key_id( $key_id ) ) {
			return new WP_Error( 'rkc_bad_key', __( 'The Key ID looks wrong. It starts with "rkk_" and is shown next to the secret when ResellingKori issues your key.', 'resellingkori-connector' ) );
		}
		if ( ! self::valid_secret( $secret ) ) {
			return new WP_Error( 'rkc_bad_secret', __( 'The secret looks wrong. Copy it again exactly as ResellingKori showed it (letters, numbers, - and _ only, no spaces).', 'resellingkori-connector' ) );
		}
		if ( ! RKC_Crypto::available() ) {
			return new WP_Error( 'rkc_no_sodium', __( 'This server cannot encrypt the secret (the PHP sodium extension is missing). Ask your host to enable it.', 'resellingkori-connector' ) );
		}
		$cur = self::get();
		self::write(
			array_merge(
				self::defaults(),
				array(
					'key_id'      => $key_id,
					'secret_enc'  => RKC_Crypto::encrypt( $secret ),
					'status'      => 'unverified',
					'wizard_step' => max( 3, (int) $cur['wizard_step'] ),
				)
			)
		);
		return true;
	}

	public static function configured() {
		$c = self::get();
		return '' !== $c['key_id'] && '' !== $c['secret_enc'];
	}

	public static function connected() {
		return self::configured() && 'connected' === self::get( 'status' );
	}

	/** @return string|false Decrypted secret (server-side use only). */
	public static function secret() {
		return self::configured() ? RKC_Crypto::decrypt( self::get( 'secret_enc' ) ) : false;
	}

	/** Remove the credentials. Product mappings and order history are kept. */
	public static function disconnect() {
		// While the secret still exists: ask ResellingKori to delete what its agent dashboard holds for this shop.
		if ( class_exists( 'RKC_Workspace' ) ) {
			RKC_Workspace::before_disconnect();
		}
		self::write( self::defaults() );
		delete_transient( 'rkc_clock_offset' );
	}

	public static function masked_key() {
		$k = (string) self::get( 'key_id' );
		return strlen( $k ) > 8 ? substr( $k, 0, 6 ) . '…' . substr( $k, -4 ) : $k;
	}
}
