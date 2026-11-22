<?php
/**
 * Request signing, version 2. MUST stay byte-for-byte identical to the API plugin's RKAPI_Signer.
 *
 *   canonical = "RK2" \n timestamp \n nonce \n METHOD \n route \n query \n sha256hex(body)
 *   signature = hex( HMAC-SHA256( secret, canonical ) )
 *
 *   route : REST route WITHOUT the /wp-json prefix, e.g. /rk/v1/orders
 *   query : parameters sorted by key, RFC 3986 encoded, joined with & ("" when none)
 *   body  : the raw request bytes ("" for GET)
 *
 * Headers: X-RK-Key, X-RK-Timestamp, X-RK-Nonce, X-RK-Signature (+ X-RK-Event-Id on webhooks).
 * Test vectors: SECURITY.md (asserted by the test-suites of both plugins).
 *
 * Version 1.1.0 adds two signatures for the agent-dashboard sync (they must match ResellingKori's; its WORKSPACE_PROTOCOL.md has the vectors):
 *   answer : hex( HMAC-SHA256( secret, "RK2-RESP" \n timestamp \n request-nonce \n http-status \n sha256hex(body) ) )
 *   wake   : hex( HMAC-SHA256( secret, "RK2-WAKE" \n store_uid \n timestamp ) )
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Signer {

	const VERSION   = 'RK2';
	const TOLERANCE = 300;

	public static function build_query( array $params ) {
		ksort( $params );
		return http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	public static function canonical( $timestamp, $nonce, $method, $route, $query, $body ) {
		return implode( "\n", array( self::VERSION, (string) (int) $timestamp, (string) $nonce, strtoupper( (string) $method ), (string) $route, (string) $query, hash( 'sha256', (string) $body ) ) );
	}

	public static function sign( $secret, $timestamp, $nonce, $method, $route, $query, $body ) {
		return hash_hmac( 'sha256', self::canonical( $timestamp, $nonce, $method, $route, $query, $body ), (string) $secret );
	}

	public static function verify( $secret, $signature, $timestamp, $nonce, $method, $route, $query, $body ) {
		return hash_equals( self::sign( $secret, $timestamp, $nonce, $method, $route, $query, $body ), strtolower( (string) $signature ) );
	}

	/**
	 * What ResellingKori signs when it answers a sync call. The request nonce ties the answer to the one request it
	 * answers (an old answer cannot be replayed against a new request); the status ties it to "200".
	 */
	public static function response_canonical( $timestamp, $nonce, $status, $body ) {
		return implode( "\n", array( 'RK2-RESP', (string) (int) $timestamp, (string) $nonce, (string) (int) $status, hash( 'sha256', (string) $body ) ) );
	}

	public static function sign_response( $secret, $timestamp, $nonce, $status, $body ) {
		return hash_hmac( 'sha256', self::response_canonical( $timestamp, $nonce, $status, $body ), (string) $secret );
	}

	public static function verify_response( $secret, $signature, $timestamp, $nonce, $status, $body ) {
		return hash_equals( self::sign_response( $secret, $timestamp, $nonce, $status, $body ), strtolower( (string) $signature ) );
	}

	/** The token in a "wake" link (the address that makes this shop check for new instructions right now). */
	public static function wake_signature( $secret, $store_uid, $timestamp ) {
		return hash_hmac( 'sha256', "RK2-WAKE\n" . (string) $store_uid . "\n" . (string) (int) $timestamp, (string) $secret );
	}

	/** Proof returned for the webhook ownership challenge. */
	public static function challenge_proof( $secret, $challenge ) {
		return hash_hmac( 'sha256', "RK2-CALLBACK\n" . (string) $challenge, (string) $secret );
	}
}
