<?php
/**
 * ResellingKori -> WooCommerce status and tracking sync.
 *
 *  1. Push: ResellingKori POSTs signed events to /wp-json/rkc/v1/webhook.
 *  2. Pull: a 15-minute cron polls open orders as a safety net.
 *
 * Both paths end in apply_remote_status(), which is idempotent.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Sync {

	const FINAL_STATUSES = array( 'delivered', 'returned', 'cancelled' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'rkc_poll_orders', array( __CLASS__, 'poll' ) );
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( 'rkc_poll_orders' ) ) {
					wp_schedule_event( time() + 300, 'rkc_15min', 'rkc_poll_orders' );
				}
			}
		);
	}

	/* ------------------------------------------------------------- Webhook */

	public static function register_routes() {
		register_rest_route(
			'rkc/v1',
			'/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => array( __CLASS__, 'verify_webhook' ),
			)
		);
	}

	/**
	 * Authenticate an incoming webhook with the shared API secret.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function verify_webhook( WP_REST_Request $request ) {
		$s      = RKC_Settings::get();
		$key    = (string) $request->get_header( 'X-RK-Key' );
		$ts     = (string) $request->get_header( 'X-RK-Timestamp' );
		$sig    = (string) $request->get_header( 'X-RK-Signature' );

		if ( '' === $s['secret'] || '' === $s['key_id'] || '' === $key || ! hash_equals( (string) $s['key_id'], $key ) ) {
			return new WP_Error( 'rkc_forbidden', __( 'Forbidden.', 'resellingkori-connector' ), array( 'status' => 403 ) );
		}
		if ( '' === $ts || ! RKC_Signer::is_fresh( $ts ) ) {
			return new WP_Error( 'rkc_expired', __( 'Request timestamp is outside the allowed window.', 'resellingkori-connector' ), array( 'status' => 401 ) );
		}
		// Webhooks are always signed with an empty query and the canonical route below,
		// so the signature does not depend on the site's permalink structure.
		if ( ! RKC_Signer::verify( $s['secret'], $sig, $ts, 'POST', '/rkc/v1/webhook', '', $request->get_body() ) ) {
			return new WP_Error( 'rkc_bad_signature', __( 'Invalid signature.', 'resellingkori-connector' ), array( 'status' => 401 ) );
		}
		return true;
	}

	public static function handle_webhook( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'rkc_bad_payload', __( 'Invalid payload.', 'resellingkori-connector' ), array( 'status' => 400 ) );
		}

		$event = isset( $data['event'] ) ? sanitize_key( $data['event'] ) : '';
		if ( 'ping' === $event ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$order_id = isset( $data['external_order_id'] ) ? absint( $data['external_order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'rkc_order_not_found', __( 'Order not found.', 'resellingkori-connector' ), array( 'status' => 404 ) );
		}
		$rk_id = isset( $data['rk_order_id'] ) ? absint( $data['rk_order_id'] ) : 0;
		if ( (int) $order->get_meta( '_rkc_rk_order_id' ) !== $rk_id ) {
			return new WP_Error( 'rkc_order_mismatch', __( 'ResellingKori order does not match this order.', 'resellingkori-connector' ), array( 'status' => 404 ) );
		}

		$result = self::apply_remote_status( $order, $data, 'webhook' );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'changed' => (bool) $result ) );
	}

	/* ------------------------------------------------------------- Polling */

	/**
	 * Cron: refresh every open order in one API call.
	 */
	public static function poll() {
		if ( ! RKC_API_Client::configured() ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 100,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_rkc_open',
						'value' => '1',
					),
				),
			)
		);
		if ( ! $orders ) {
			return;
		}

		$by_rk = array();
		foreach ( $orders as $order ) {
			$rk_id = (int) $order->get_meta( '_rkc_rk_order_id' );
			if ( $rk_id ) {
				$by_rk[ $rk_id ] = $order;
			}
		}
		if ( ! $by_rk ) {
			return;
		}

		$res = RKC_API_Client::order_statuses( array_keys( $by_rk ) );
		if ( is_wp_error( $res ) ) {
			RKC_Logger::error( 'Status poll failed: ' . $res->get_error_message() );
			return;
		}

		$rows = ( isset( $res['orders'] ) && is_array( $res['orders'] ) ) ? $res['orders'] : array();
		foreach ( $rows as $row ) {
			$rk_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $rk_id && isset( $by_rk[ $rk_id ] ) ) {
				self::apply_remote_status( $by_rk[ $rk_id ], $row, 'poll' );
			}
		}
	}

	/**
	 * Refresh a single order on demand ("Refresh status" button).
	 *
	 * @param WC_Order $order Order.
	 * @return true|WP_Error
	 */
	public static function refresh_order( WC_Order $order ) {
		$rk_id = (int) $order->get_meta( '_rkc_rk_order_id' );
		if ( ! $rk_id ) {
			return new WP_Error( 'rkc_not_submitted', __( 'This order has not been submitted to ResellingKori.', 'resellingkori-connector' ) );
		}
		$res = RKC_API_Client::order_statuses( array( $rk_id ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$rows = ( isset( $res['orders'] ) && is_array( $res['orders'] ) ) ? $res['orders'] : array();
		foreach ( $rows as $row ) {
			if ( isset( $row['id'] ) && (int) $row['id'] === $rk_id ) {
				$applied = self::apply_remote_status( $order, $row, 'manual' );
				return is_wp_error( $applied ) ? $applied : true;
			}
		}
		return new WP_Error( 'rkc_rk_missing', __( 'ResellingKori could not find this order. Check it on ResellingKori.', 'resellingkori-connector' ) );
	}

	/* ------------------------------------------------------- Apply a status */

	/**
	 * Apply a status/tracking update. Safe to call repeatedly with the same data.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $data   { status, tracking{courier,number,url}, updated_at }.
	 * @param string   $source webhook | poll | manual.
	 * @return bool|WP_Error True when something changed, false when nothing did.
	 */
	public static function apply_remote_status( WC_Order $order, array $data, $source = 'webhook' ) {
		$valid = array_keys( RKC_Settings::rk_statuses() );
		$new   = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : '';
		if ( ! in_array( $new, $valid, true ) ) {
			return new WP_Error( 'rkc_bad_status', __( 'Unknown ResellingKori status.', 'resellingkori-connector' ) );
		}

		// Ignore events that are older than what we already applied (webhook/poll can race).
		$updated = isset( $data['updated_at'] ) ? (int) strtotime( (string) $data['updated_at'] ) : 0;
		$last    = (int) $order->get_meta( '_rkc_rk_updated_at' );
		if ( $updated && $last && $updated < $last ) {
			return false;
		}

		$old       = (string) $order->get_meta( '_rkc_rk_status' );
		$tracking  = self::clean_tracking( isset( $data['tracking'] ) ? $data['tracking'] : array() );
		$old_track = $order->get_meta( '_rkc_tracking' );
		$old_track = is_array( $old_track ) ? $old_track : array();

		$status_changed   = ( $new !== $old );
		$tracking_changed = ( ! empty( $tracking ) && $tracking !== $old_track );

		$order->update_meta_data( '_rkc_synced_at', time() );
		if ( $updated ) {
			$order->update_meta_data( '_rkc_rk_updated_at', $updated );
		}
		if ( ! $status_changed && ! $tracking_changed ) {
			$order->save_meta_data();
			return false;
		}

		$order->update_meta_data( '_rkc_rk_status', $new );
		if ( $tracking ) {
			$order->update_meta_data( '_rkc_tracking', $tracking );
		}
		$order->update_meta_data( '_rkc_open', in_array( $new, self::FINAL_STATUSES, true ) ? 0 : 1 );
		$order->save();

		$parts = array();
		if ( ! empty( $tracking['courier'] ) ) {
			/* translators: %s: courier name */
			$parts[] = sprintf( __( 'Courier: %s', 'resellingkori-connector' ), $tracking['courier'] );
		}
		if ( ! empty( $tracking['number'] ) ) {
			/* translators: %s: tracking / consignment number */
			$parts[] = sprintf( __( 'Tracking: %s', 'resellingkori-connector' ), $tracking['number'] );
		}
		$extra = $parts ? ' (' . implode( ', ', $parts ) . ')' : '';

		if ( $status_changed ) {
			/* translators: 1: status label, 2: tracking details */
			$order->add_order_note( sprintf( __( 'ResellingKori status: %1$s%2$s', 'resellingkori-connector' ), RKC_Orders::status_label( $new ), $extra ) );

			// A partial shipment covers only some items, so its status must not complete/cancel the whole store order.
			if ( ! $order->get_meta( '_rkc_partial' ) ) {
				$map    = RKC_Settings::get( 'status_map' );
				$target = isset( $map[ $new ] ) ? (string) $map[ $new ] : '';
				$known  = RKC_Settings::statuses();
				if ( '' !== $target && isset( $known[ $target ] ) && $order->get_status() !== $target ) {
					$order->update_status( $target, __( 'Status synced from ResellingKori.', 'resellingkori-connector' ) );
				}
			}
		} else {
			/* translators: %s: tracking details */
			$order->add_order_note( sprintf( __( 'ResellingKori tracking updated%s', 'resellingkori-connector' ), $extra ) );
		}

		do_action( 'rkc_remote_status_applied', $order, $new, $old, $tracking, $source );

		return true;
	}

	/**
	 * @param mixed $t Raw tracking array from the API.
	 * @return array Empty when there is nothing useful.
	 */
	private static function clean_tracking( $t ) {
		if ( ! is_array( $t ) ) {
			return array();
		}
		$out = array(
			'courier' => isset( $t['courier'] ) ? sanitize_text_field( (string) $t['courier'] ) : '',
			'number'  => isset( $t['number'] ) ? sanitize_text_field( (string) $t['number'] ) : '',
			'url'     => isset( $t['url'] ) ? esc_url_raw( (string) $t['url'] ) : '',
		);
		return ( '' === $out['courier'] && '' === $out['number'] && '' === $out['url'] ) ? array() : $out;
	}
}
