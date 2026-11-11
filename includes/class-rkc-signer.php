<?php
/**
 * Request signing used on both ends of the integration.
 *
 *   canonical = timestamp \n METHOD \n route \n sorted-query \n sha256(body)
 *   signature = hex( HMAC-SHA256( secret, canonical ) )
 *
 * The secret itself is never sent over the wire. A 5 minute timestamp window
 * limits replay of captured requests.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Signer {

	const TOLERANCE = 300;

	/**
	 * Deterministic query string (sorted keys, RFC 3986 encoding).
	 *
	 * @param array $params Query parameters.
	 * @return string
	 */
	public static function build_query( array $params ) {
		ksort( $params );
		return http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	public static function canonical( $timestamp, $method, $route, $query, $body ) {
		return implode(
			"\n",
			array(
				(string) (int) $timestamp,
				strtoupper( (string) $method ),
				(string) $route,
				(string) $query,
				hash( 'sha256', (string) $body ),
			)
		);
	}

	public static function sign( $secret, $timestamp, $method, $route, $query, $body ) {
		return hash_hmac( 'sha256', self::canonical( $timestamp, $method, $route, $query, $body ), (string) $secret );
	}

	public static function verify( $secret, $signature, $timestamp, $method, $route, $query, $body ) {
		return hash_equals( self::sign( $secret, $timestamp, $method, $route, $query, $body ), (string) $signature );
	}

	public static function is_fresh( $timestamp ) {
		return abs( time() - (int) $timestamp ) <= self::TOLERANCE;
	}
}
