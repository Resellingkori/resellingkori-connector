<?php
/**
 * HTTP client for the ResellingKori Fulfillment API (/wp-json/rk/v1).
 *
 * Every call returns the decoded JSON array on success or a WP_Error whose
 * message is safe to show to the store admin.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_API_Client {

	public static function configured() {
		$s = RKC_Settings::get();
		return '' !== $s['api_base'] && '' !== $s['key_id'] && '' !== $s['secret'];
	}

	/**
	 * Signed request.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $route   REST route, e.g. /rk/v1/products/search.
	 * @param array      $params  Query parameters.
	 * @param array|null $body    JSON body.
	 * @param int        $timeout Seconds.
	 * @return array|WP_Error
	 */
	public static function request( $method, $route, array $params = array(), $body = null, $timeout = 20 ) {
		if ( ! self::configured() ) {
			return new WP_Error( 'rkc_not_configured', __( 'ResellingKori is not connected yet. Add your API key and secret in the Settings tab.', 'resellingkori-connector' ) );
		}

		$s      = RKC_Settings::get();
		$method = strtoupper( $method );
		$query  = RKC_Signer::build_query( $params );
		$url    = untrailingslashit( $s['api_base'] ) . '/wp-json' . $route . ( '' !== $query ? '?' . $query : '' );
		$json   = ( null === $body ) ? '' : wp_json_encode( $body );
		$ts     = time();

		$args = array(
			'method'     => $method,
			'timeout'    => $timeout,
			'user-agent' => 'ResellingKori-Connector/' . RKC_VERSION . '; ' . home_url(),
			'headers'    => array(
				'Accept'         => 'application/json',
				'Content-Type'   => 'application/json',
				'X-RK-Key'       => $s['key_id'],
				'X-RK-Timestamp' => (string) $ts,
				'X-RK-Signature' => RKC_Signer::sign( $s['secret'], $ts, $method, $route, $query, $json ),
			),
			'body'       => $json,
		);

		RKC_Logger::debug( sprintf( '%s %s', $method, $route ), array( 'params' => $params ) );

		$res = wp_remote_request( $url, $args );

		if ( is_wp_error( $res ) ) {
			RKC_Logger::error( 'HTTP error calling ' . $route . ': ' . $res->get_error_message() );
			return new WP_Error(
				'rkc_http',
				sprintf(
					/* translators: %s: low-level error message */
					__( 'Could not reach ResellingKori: %s', 'resellingkori-connector' ),
					$res->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}

		$message = ( is_array( $data ) && ! empty( $data['message'] ) )
			? wp_strip_all_tags( (string) $data['message'] )
			: sprintf(
				/* translators: %d: HTTP status code */
				__( 'Unexpected response from ResellingKori (HTTP %d). A security plugin or firewall may be blocking the API.', 'resellingkori-connector' ),
				$code
			);
		$err_code = ( is_array( $data ) && ! empty( $data['code'] ) ) ? sanitize_key( (string) $data['code'] ) : 'rkc_http_' . $code;

		RKC_Logger::error( 'API error ' . $code . ' on ' . $route . ': ' . $message );

		return new WP_Error(
			$err_code,
			$message,
			array(
				'status'  => $code,
				'details' => ( is_array( $data ) && isset( $data['data'] ) ) ? $data['data'] : null,
			)
		);
	}

	public static function ping() {
		$params = array( 'callback' => rest_url( 'rkc/v1/webhook' ) );
		$email  = (string) RKC_Settings::get( 'agent_email' );
		if ( '' !== $email ) {
			$params['agent_email'] = $email;
		}
		return self::request( 'GET', '/rk/v1/ping', $params );
	}

	public static function search_products( $keywords, $limit = 20 ) {
		return self::request(
			'GET',
			'/rk/v1/products/search',
			array(
				'q'        => $keywords,
				'per_page' => (int) $limit,
			)
		);
	}

	/**
	 * Exact SKU lookup (products and variations).
	 *
	 * @param string[] $skus SKUs (must not contain commas).
	 * @return array|WP_Error
	 */
	public static function products_by_sku( array $skus ) {
		$skus = array_values( array_unique( array_filter( array_map( 'trim', $skus ) ) ) );
		return self::request( 'GET', '/rk/v1/products/by-sku', array( 'skus' => implode( ',', $skus ) ) );
	}

	public static function get_product( $id ) {
		return self::request( 'GET', '/rk/v1/products/' . absint( $id ) );
	}

	public static function create_order( array $payload ) {
		return self::request( 'POST', '/rk/v1/orders', array(), $payload, 30 );
	}

	/**
	 * @param int[] $rk_ids ResellingKori order IDs.
	 * @return array|WP_Error
	 */
	public static function order_statuses( array $rk_ids ) {
		return self::request( 'GET', '/rk/v1/orders/status', array( 'ids' => implode( ',', array_map( 'absint', $rk_ids ) ) ) );
	}
}
