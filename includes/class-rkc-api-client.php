<?php
/**
 * Signed HTTP client for the ResellingKori Fulfillment API.
 *
 *  - Fixed origin (RKC_Config::base_url()); no redirects are ever followed.
 *  - Every request is HMAC-signed (signing v2). The secret never appears in a URL, a header or a log.
 *  - Failures become WP_Error with data: status, transient (safe to retry), outcome_unknown (a POST that may
 *    have reached the server), retry_after, fields/changes (validation details), api_code.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_API_Client {

	public static function configured() {
		return RKC_Config::configured();
	}

	/**
	 * @param string      $method  GET|POST.
	 * @param string      $route   e.g. /rk/v1/orders (no /wp-json).
	 * @param array       $query   Query parameters (scalars).
	 * @param array|null  $body    JSON body.
	 * @param int         $timeout Seconds.
	 * @param bool        $verify  Require ResellingKori's signature on the answer (used for calls whose answer carries
	 *                             instructions this site will act on: the agent-dashboard sync).
	 * @return array|WP_Error
	 */
	public static function request( $method, $route, array $query = array(), $body = null, $timeout = 20, $verify = false ) {
		$method = strtoupper( (string) $method );
		$secret = RKC_Config::secret();
		if ( ! RKC_Config::configured() || false === $secret ) {
			return new WP_Error( 'rkc_not_configured', __( 'ResellingKori is not connected. Open WooCommerce > ResellingKori and finish the setup.', 'resellingkori-connector' ), array( 'status' => 0, 'transient' => false ) );
		}
		$json  = ( null === $body ) ? '' : wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$tries = 0;
		do {
			++$tries;
			$res = self::send_once( $method, $route, $query, $json, $secret, (int) $timeout, (bool) $verify );
			// A skewed clock is the one authentication failure worth one automatic retry.
			$retry = ( $tries < 2 && is_wp_error( $res ) && ! empty( $res->get_error_data()['server_time'] ) && self::learn_clock( (int) $res->get_error_data()['server_time'] ) );
		} while ( $retry );
		return $res;
	}

	private static function clock_offset() {
		return (int) get_transient( 'rkc_clock_offset' );
	}

	private static function learn_clock( $server_time ) {
		$offset = $server_time - time();
		if ( abs( $offset ) < 5 || abs( $offset ) > DAY_IN_SECONDS ) {
			return false;
		}
		set_transient( 'rkc_clock_offset', $offset, HOUR_IN_SECONDS );
		RKC_Logger::warning( 'This server\'s clock differs from ResellingKori by ' . $offset . ' seconds; requests are being corrected. Please fix the server time.' );
		return true;
	}

	private static function send_once( $method, $route, array $query, $json, $secret, $timeout, $verify = false ) {
		$qs    = RKC_Signer::build_query( $query );
		$ts    = time() + self::clock_offset();
		$nonce = RKC_Crypto::random_hex( 16 );
		$url   = RKC_Config::base_url() . '/wp-json' . $route . ( '' !== $qs ? '?' . $qs : '' );

		$args = array(
			'method'              => $method,
			'timeout'             => $timeout,
			'redirection'         => 0,
			'sslverify'           => true,
			'reject_unsafe_urls'  => ! RKC_Config::is_dev(),
			'limit_response_size' => 2097152,
			'user-agent'          => 'ResellingKori-Connector/' . RKC_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'             => array(
				'Accept'         => 'application/json',
				'Content-Type'   => 'application/json',
				'X-RK-Key'       => RKC_Config::get( 'key_id' ),
				'X-RK-Timestamp' => (string) $ts,
				'X-RK-Nonce'     => $nonce,
				'X-RK-Signature' => RKC_Signer::sign( $secret, $ts, $nonce, $method, $route, $qs, $json ),
			),
		);
		if ( 'GET' !== $method ) {
			$args['body'] = $json;
		}

		$started = microtime( true );
		$res     = wp_remote_request( $url, $args );
		$ms      = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $res ) ) {
			$msg         = $res->get_error_message();
			$timeout_hit = false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'cURL error 28' );
			// Failures before any HTTP request left this server (DNS, connect, TLS handshake): provably not received.
			$not_sent = (bool) preg_match( '/cURL error (6|7|35|51|58|60|77):/', $msg ) || false !== stripos( $msg, 'A valid URL was not provided' );
			RKC_Logger::warning( 'Request failed: ' . $method . ' ' . $route . ' — ' . $msg, array( 'ms' => $ms ) );
			return new WP_Error(
				$timeout_hit ? 'rkc_timeout' : 'rkc_unreachable',
				$timeout_hit ? __( 'ResellingKori did not answer in time.', 'resellingkori-connector' ) : __( 'Could not reach ResellingKori. Check your internet connection and try again.', 'resellingkori-connector' ),
				array(
					'status'          => 0,
					'transient'       => true,
					'not_sent'        => $not_sent,
					'outcome_unknown' => ( 'GET' !== $method && ! $not_sent ),
					'detail'          => RKC_Redact::text( $msg ),
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		if ( $code >= 200 && $code < 300 ) {
			if ( $verify ) {
				// The answer carries instructions this site will act on, so it must be signed by the holder of the secret, for
				// THIS request (the nonce is part of the signed text), and be fresh.
				$answer_ts  = (int) wp_remote_retrieve_header( $res, 'x-rk-response-timestamp' );
				$answer_sig = strtolower( trim( (string) wp_remote_retrieve_header( $res, 'x-rk-response-signature' ) ) );
				$fresh      = $answer_ts > 0 && abs( $answer_ts - $ts ) <= RKC_Signer::TOLERANCE;
				if ( ! $fresh || ! preg_match( '/^[a-f0-9]{64}$/', $answer_sig ) || ! RKC_Signer::verify_response( $secret, $answer_sig, $answer_ts, $nonce, $code, $raw ) ) {
					RKC_Logger::warning( 'The answer to ' . $method . ' ' . $route . ' was not signed correctly and was ignored.', array( 'http' => $code, 'ms' => $ms ) );
					return new WP_Error( 'rkc_bad_signature', __( 'The answer from ResellingKori could not be verified, so it was ignored. If this keeps happening, a proxy or security plugin may be changing the response.', 'resellingkori-connector' ), array( 'status' => $code, 'transient' => true, 'outcome_unknown' => false ) );
				}
			}
			if ( ! is_array( $data ) ) {
				RKC_Logger::error( 'Unreadable success response: ' . $method . ' ' . $route, array( 'http' => $code ) );
				return new WP_Error( 'rkc_bad_response', __( 'ResellingKori returned a response this plugin cannot read. Update the plugin or contact ResellingKori.', 'resellingkori-connector' ), array( 'status' => $code, 'transient' => false, 'outcome_unknown' => ( 'GET' !== $method ) ) );
			}
			RKC_Logger::debug( $method . ' ' . $route . ' → ' . $code, array( 'ms' => $ms ) );
			// An authenticated success proves the credentials work again (e.g. a key that was re-enabled).
			if ( 'error' === RKC_Config::get( 'status' ) ) {
				RKC_Config::mark( array( 'status' => 'connected', 'last_error' => '' ) );
			}
			$data['_http'] = $code;
			return $data;
		}

		$api_code    = ( is_array( $data ) && isset( $data['code'] ) ) ? sanitize_key( (string) $data['code'] ) : '';
		$message     = ( is_array( $data ) && isset( $data['message'] ) ) ? sanitize_text_field( (string) $data['message'] ) : '';
		$extra       = ( is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : array();
		$retry_after = (int) wp_remote_retrieve_header( $res, 'retry-after' );
		if ( ! $retry_after && isset( $extra['retry_after'] ) ) {
			$retry_after = (int) $extra['retry_after'];
		}
		if ( '' === $message ) {
			/* translators: %d: HTTP status code */
			$message = sprintf( __( 'ResellingKori answered with HTTP %d.', 'resellingkori-connector' ), $code );
		}
		$transient = ( $code >= 500 || in_array( $code, array( 408, 425, 429 ), true ) || 'rkapi_in_progress' === $api_code );
		$err_data  = array(
			'status'          => $code,
			'transient'       => $transient,
			'outcome_unknown' => ( 'GET' !== $method && $code >= 500 ),
			'retry_after'     => $retry_after,
			'api_code'        => $api_code,
			'fields'          => isset( $extra['fields'] ) && is_array( $extra['fields'] ) ? $extra['fields'] : array(),
			'changes'         => isset( $extra['changes'] ) && is_array( $extra['changes'] ) ? $extra['changes'] : array(),
			'shortages'       => isset( $extra['shortages'] ) && is_array( $extra['shortages'] ) ? $extra['shortages'] : array(),
			'server_time'     => isset( $extra['server_time'] ) ? (int) $extra['server_time'] : 0,
		);
		RKC_Logger::warning( $method . ' ' . $route . ' → ' . $code . ' ' . $api_code, array( 'ms' => $ms, 'message' => $message ) );

		// Credentials the server will never accept: surface it on the dashboard.
		if ( in_array( $api_code, array( 'rkapi_key_revoked', 'rkapi_store_disabled', 'rkapi_upgrade_required' ), true ) || ( 'rkapi_auth_invalid' === $api_code && ! $err_data['server_time'] ) ) {
			RKC_Config::mark( array( 'status' => 'error', 'last_error' => $message ) );
		}
		return new WP_Error( '' !== $api_code ? $api_code : 'rkc_http_' . $code, $message, $err_data );
	}

	/* ------------------------------------------------------------ Endpoints */

	/** Who am I? Store, key and agent, all taken from the authenticated key. */
	public static function ping() {
		return self::request( 'GET', '/rk/v1/ping' );
	}

	/**
	 * The agent-dashboard sync: one signed call carries order and product cards, results of earlier instructions and
	 * the readiness of this shop; the signed answer carries the instructions that are waiting.
	 */
	public static function workspace_sync( array $body ) {
		return self::request( 'POST', '/rk/v1/workspace/sync', array(), $body, 15, true );
	}

	/** Same as ping, and registers the webhook URL (proving ownership with a signed challenge). */
	public static function connection( array $body ) {
		return self::request( 'POST', '/rk/v1/connection', array(), $body, 30 );
	}

	public static function search_products( $keywords, $limit = 20 ) {
		return self::request( 'GET', '/rk/v1/products/search', array( 'q' => $keywords, 'per_page' => (int) $limit ) );
	}

	/** Exact SKU lookup (products and variations). */
	public static function products_by_sku( array $skus ) {
		$skus = array_values( array_unique( array_filter( array_map( 'trim', $skus ) ) ) );
		return self::request( 'GET', '/rk/v1/products/by-sku', array( 'skus' => implode( ',', $skus ) ) );
	}

	public static function get_product( $id ) {
		return self::request( 'GET', '/rk/v1/products/' . absint( $id ) );
	}

	public static function create_order( array $payload ) {
		return self::request( 'POST', '/rk/v1/orders', array(), $payload, 40 );
	}

	/** Find the order this store submitted under an external id (used to recover from an unknown outcome). */
	public static function lookup_order( $external_id ) {
		return self::request( 'GET', '/rk/v1/orders/lookup', array( 'external_order_id' => (string) $external_id ) );
	}

	public static function cancel_order( $external_id, $reason = '' ) {
		return self::request( 'POST', '/rk/v1/orders/cancel', array(), array( 'external_order_id' => (string) $external_id, 'reason' => (string) $reason ), 30 );
	}

	/** @param int[] $rk_ids ResellingKori order IDs (max 100). */
	public static function order_statuses( array $rk_ids ) {
		return self::request( 'GET', '/rk/v1/orders/status', array( 'ids' => implode( ',', array_map( 'absint', $rk_ids ) ) ) );
	}
}
