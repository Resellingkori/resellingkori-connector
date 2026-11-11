<?php
/**
 * Verifying the connection with a REAL authenticated call, and describing the health of this installation.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Connect {

	/**
	 * Call ResellingKori with the saved key. Optionally (re)register this site's webhook address; ResellingKori proves
	 * it by sending a signed challenge that only this site (holding the secret) can answer.
	 *
	 * @param bool $register_webhook Register the webhook address too.
	 * @return array|WP_Error
	 */
	public static function verify( $register_webhook = true ) {
		if ( ! RKC_Config::configured() ) {
			return new WP_Error( 'rkc_not_configured', __( 'Enter your Key ID and secret first.', 'resellingkori-connector' ) );
		}
		$expected = (string) RKC_Settings::get( 'expected_agent_email' );
		$body     = array();
		if ( '' !== $expected ) {
			$body['expected_agent_email'] = $expected;
		}
		if ( $register_webhook ) {
			$body['callback_url'] = RKC_Webhook::url();
			$res                  = RKC_API_Client::connection( $body );
		} else {
			$res = RKC_API_Client::ping();
		}
		if ( is_wp_error( $res ) ) {
			RKC_Logger::warning( 'Connection check failed: ' . $res->get_error_code() );
			return new WP_Error( $res->get_error_code(), self::friendly( $res ), $res->get_error_data() );
		}
		if ( empty( $res['ok'] ) || empty( $res['store']['id'] ) ) {
			return new WP_Error( 'rkc_bad_response', __( 'ResellingKori answered, but not with the information this plugin expects. Update the plugin, or contact ResellingKori.', 'resellingkori-connector' ) );
		}

		$store   = $res['store'];
		$agent   = ( isset( $res['agent'] ) && is_array( $res['agent'] ) ) ? $res['agent'] : array();
		$match   = array_key_exists( 'agent_match', $res ) ? $res['agent_match'] : null;
		$cb_state = isset( $res['callback']['state'] ) ? (string) $res['callback']['state'] : 'none';
		$cb_msg   = '';
		if ( $register_webhook && isset( $res['callback_result'] ) && is_array( $res['callback_result'] ) ) {
			$cb_state = isset( $res['callback_result']['state'] ) ? (string) $res['callback_result']['state'] : $cb_state;
			$cb_msg   = isset( $res['callback_result']['message'] ) ? sanitize_text_field( (string) $res['callback_result']['message'] ) : '';
		} elseif ( 'verified' === $cb_state && isset( $res['callback']['url'] ) && untrailingslashit( (string) $res['callback']['url'] ) !== untrailingslashit( RKC_Webhook::url() ) ) {
			$cb_state = 'pending'; // ResellingKori holds a different address (the site moved?).
			$cb_msg   = __( 'ResellingKori has a different webhook address on file. Run "Test connection" to register this one.', 'resellingkori-connector' );
		}

		$warnings = array();
		$status   = 'connected';
		$error    = '';
		if ( false === $match ) {
			$status = 'unverified';
			$error  = __( 'The sales agent on this key is not the agent you expect. Orders will not be sent. Check the key with ResellingKori, or correct the expected agent email in Settings.', 'resellingkori-connector' );
		}
		if ( isset( $agent['valid'] ) && false === (bool) $agent['valid'] ) {
			$warnings[] = __( 'No valid sales agent is linked to this key at ResellingKori. You can map products, but orders cannot be placed until they link your agent account.', 'resellingkori-connector' );
		}
		if ( ! empty( $res['currency'] ) && 'BDT' !== $res['currency'] ) {
			$warnings[] = __( 'ResellingKori reports a currency other than BDT. Contact ResellingKori before placing orders.', 'resellingkori-connector' );
		}
		if ( 'verified' !== $cb_state ) {
			$warnings[] = __( 'Live status updates (webhook) are not active. The plugin will check ResellingKori for updates every 15 minutes instead.', 'resellingkori-connector' );
		}

		$cur = RKC_Config::get();
		RKC_Config::mark(
			array(
				'status'         => $status,
				'connected_at'   => $cur['connected_at'] ? $cur['connected_at'] : time(),
				'verified_at'    => time(),
				'store_uid'      => substr( sanitize_text_field( (string) $store['id'] ), 0, 40 ),
				'store_name'     => substr( sanitize_text_field( isset( $store['name'] ) ? (string) $store['name'] : '' ), 0, 120 ),
				'agent_name'     => substr( sanitize_text_field( isset( $agent['name'] ) ? (string) $agent['name'] : '' ), 0, 120 ),
				'agent_hint'     => substr( sanitize_text_field( isset( $agent['email_hint'] ) ? (string) $agent['email_hint'] : '' ), 0, 120 ),
				'agent_valid'    => isset( $agent['valid'] ) ? (bool) $agent['valid'] : false,
				'agent_match'    => $match,
				'callback_state' => in_array( $cb_state, array( 'none', 'pending', 'verified', 'failed', 'unreachable', 'rejected' ), true ) ? $cb_state : 'failed',
				'callback_msg'   => $cb_msg,
				'api_version'    => substr( sanitize_text_field( isset( $res['api_version'] ) ? (string) $res['api_version'] : '' ), 0, 10 ),
				'last_error'     => $error,
			)
		);
		if ( isset( $res['limits'] ) && is_array( $res['limits'] ) ) {
			$limits = array();
			foreach ( array( 'max_lines', 'max_order_amount', 'max_cod_amount', 'max_delivery_charge' ) as $k ) {
				if ( isset( $res['limits'][ $k ] ) && is_numeric( $res['limits'][ $k ] ) ) {
					$limits[ $k ] = (float) $res['limits'][ $k ];
				}
			}
			update_option( 'rkc_limits', $limits, false );
		}
		RKC_Logger::info( 'Connection verified', array( 'store' => $store['id'], 'webhook' => $cb_state, 'agent_match' => $match ) );

		return array(
			'status'      => $status,
			'store_name'  => RKC_Config::get( 'store_name' ),
			'store_url'   => isset( $store['url'] ) ? esc_url_raw( (string) $store['url'] ) : '',
			'agent_name'  => RKC_Config::get( 'agent_name' ),
			'agent_hint'  => RKC_Config::get( 'agent_hint' ),
			'agent_valid' => RKC_Config::get( 'agent_valid' ),
			'agent_match' => $match,
			'webhook'     => RKC_Config::get( 'callback_state' ),
			'webhook_msg' => $cb_msg,
			'warnings'    => $warnings,
			'error'       => $error,
			'api_version' => RKC_Config::get( 'api_version' ),
			'key_status'  => isset( $store['key_status'] ) ? sanitize_key( (string) $store['key_status'] ) : '',
		);
	}

	/** Plain-language explanation for the errors a person can fix. */
	public static function friendly( WP_Error $e ) {
		switch ( $e->get_error_code() ) {
			case 'rkapi_auth_invalid':
			case 'rkapi_auth_missing':
				return __( 'ResellingKori did not accept this Key ID and secret. Copy both again exactly as they were shown when the key was issued (the secret is shown only once). If you no longer have the secret, ask ResellingKori to rotate the key.', 'resellingkori-connector' );
			case 'rkapi_key_revoked':
				return __( 'This key has been revoked by ResellingKori. Ask them for a new key and enter it in Settings.', 'resellingkori-connector' );
			case 'rkapi_store_disabled':
				return __( 'Your store account is disabled at ResellingKori. Contact them to re-enable it.', 'resellingkori-connector' );
			case 'rkapi_upgrade_required':
				return __( 'ResellingKori requires a newer version of this plugin. Update it and try again.', 'resellingkori-connector' );
			case 'rkapi_locked':
			case 'rkapi_rate_limited':
				return __( 'Too many requests in a short time. Wait a few minutes and try again.', 'resellingkori-connector' );
			case 'rkc_unreachable':
				return __( 'This site could not reach ResellingKori. Check that your server can make outgoing HTTPS requests, then try again.', 'resellingkori-connector' );
			case 'rkc_timeout':
				return __( 'ResellingKori did not answer in time. Try again in a moment.', 'resellingkori-connector' );
			default:
				return $e->get_error_message();
		}
	}

	/* ------------------------------------------------------------ Diagnostics */

	public static function hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Safe-to-share facts for support. Never contains the secret, the key id or any signature.
	 *
	 * @return array label => value
	 */
	public static function diagnostics() {
		global $wpdb;
		$next_poll = wp_next_scheduled( 'rkc_poll_orders' );
		$last_evt  = $wpdb->get_var( 'SELECT MAX(received_at) FROM ' . RKC_DB::t( 'events' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$sub_count = (array) $wpdb->get_results( 'SELECT state, COUNT(*) AS n FROM ' . RKC_DB::t( 'submissions' ) . ' GROUP BY state', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$states    = array();
		foreach ( $sub_count as $r ) {
			$states[] = $r['state'] . ': ' . (int) $r['n'];
		}
		$c = RKC_Config::get();
		return array(
			'Plugin version'           => RKC_VERSION . ' (database ' . get_option( 'rkc_db_version', '?' ) . ')',
			'WordPress / WooCommerce'  => get_bloginfo( 'version' ) . ' / ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '—' ),
			'PHP'                      => PHP_VERSION . ( function_exists( 'sodium_crypto_secretbox' ) ? ', sodium ok' : ', sodium MISSING' ),
			'Order storage'            => self::hpos_enabled() ? 'HPOS (custom order tables)' : 'Posts (legacy)',
			'Environment'              => wp_get_environment_type(),
			'API address'              => wp_parse_url( RKC_Config::base_url(), PHP_URL_HOST ) . ( RKC_Config::is_dev() ? ' (developer override)' : '' ),
			'Connection status'        => $c['status'] . ( $c['verified_at'] ? ', verified ' . human_time_diff( (int) $c['verified_at'] ) . ' ago' : '' ),
			'Webhook'                  => $c['callback_state'],
			'API version'              => '' !== $c['api_version'] ? $c['api_version'] : '—',
			'Secret encryption'        => ( RKC_Crypto::available() && RKC_Crypto::self_test() ) ? 'working (key from ' . RKC_Crypto::key_source() . ')' : 'NOT working',
			'HTTPS'                    => is_ssl() ? 'yes' : 'no',
			'Site URL'                 => wp_parse_url( home_url(), PHP_URL_HOST ),
			'WP-Cron'                  => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'disabled in wp-config (a real cron job must call wp-cron.php)' : 'enabled',
			'Next status check'        => $next_poll ? wp_date( 'Y-m-d H:i', $next_poll ) : 'not scheduled',
			'Last webhook received'    => $last_evt ? RKC_DB::local( $last_evt, 'Y-m-d H:i' ) : 'never',
			'Clock correction'         => (int) get_transient( 'rkc_clock_offset' ) ? (int) get_transient( 'rkc_clock_offset' ) . ' s (server clock differs from ResellingKori)' : 'none needed',
			'Submissions'              => $states ? implode( ', ', $states ) : 'none',
			'Mapped products'          => (string) RKC_Mapping::counts()['mapped'],
		);
	}
}
