<?php
/**
 * Receives status updates from ResellingKori (and polls as a fallback).
 *
 * POST /wp-json/rkc/v1/webhook
 *   Headers: X-RK-Key, X-RK-Timestamp, X-RK-Nonce, X-RK-Event-Id, X-RK-Signature   (signing v2, route "/rkc/v1/webhook")
 *   Body:    { id, type, created, api_version, store_id, sequence, data: { order: {...} } }
 *
 * A request is acted on only if ALL of these hold: the key id is ours, the timestamp is within 5 minutes, the HMAC
 * (with our stored secret) matches the exact bytes received, the nonce was never seen before, and the event id in the
 * header equals the one in the body. Events are applied idempotently: every event carries the complete current state
 * and a sequence number, and anything older than what is already applied is ignored.
 *
 * Mixed orders: only the fulfilment data of the ResellingKori part (status, tracking, notes) is stored. The store's
 * own WooCommerce order status is never changed for a partial order.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Webhook {

	const ROUTE_PATH = '/rkc/v1/webhook';
	const MAX_BODY   = 262144; // 256 KB.

	/** @var array|null Verified request context handed from the permission callback to the handler. */
	private static $ctx = null;

	/** @var WP_REST_Request|null The request whose authentication result is remembered (see authenticate()). */
	private static $memo_request = null;

	/** @var true|WP_Error|null Result remembered for that request. */
	private static $memo_result = null;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'headers' ), 10, 3 );
	}

	public static function routes() {
		register_rest_route(
			'rkc/v1',
			'/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'authenticate' ),
			)
		);
	}

	public static function headers( $result, $server, $request ) {
		if ( 0 === strpos( (string) $request->get_route(), '/rkc/v1/' ) && $result instanceof WP_REST_Response ) {
			$result->header( 'Cache-Control', 'no-store, private' );
			$result->header( 'X-Content-Type-Options', 'nosniff' );
		}
		return $result;
	}

	public static function url() {
		return rest_url( 'rkc/v1/webhook' );
	}

	/* ------------------------------------------------------ Authentication */

	private static function deny( $code, $message, $status = 401 ) {
		self::note_failure();
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	private static function note_failure() {
		$k = 'rkc_whf_' . md5( self::client_ip() );
		set_transient( $k, (int) get_transient( $k ) + 1, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ) {
		// WordPress core (rest_send_allow_header) calls a route's permission callback a second time, after the handler has
		// run, with the very same request object. That second call must not consume the nonce again, count a failure for a
		// request that succeeded, or clear the verified context, so it is answered from memory.
		if ( null !== self::$memo_request && self::$memo_request === $request ) {
			return self::$memo_result;
		}
		$result             = self::check( $request );
		self::$memo_request = $request;
		self::$memo_result  = $result;
		return $result;
	}

	/**
	 * The actual authentication, run once per request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private static function check( WP_REST_Request $request ) {
		self::$ctx = null;
		if ( (int) get_transient( 'rkc_whf_' . md5( self::client_ip() ) ) >= 30 ) {
			return new WP_Error( 'rkc_rate_limited', __( 'Too many invalid requests. Try again later.', 'resellingkori-connector' ), array( 'status' => 429 ) );
		}
		if ( ! RKC_Config::configured() ) {
			return self::deny( 'rkc_unauthorized', __( 'Unauthorized.', 'resellingkori-connector' ) );
		}
		$key   = trim( (string) $request->get_header( 'x_rk_key' ) );
		$ts    = trim( (string) $request->get_header( 'x_rk_timestamp' ) );
		$nonce = trim( (string) $request->get_header( 'x_rk_nonce' ) );
		$sig   = strtolower( trim( (string) $request->get_header( 'x_rk_signature' ) ) );
		$evt   = trim( (string) $request->get_header( 'x_rk_event_id' ) );
		if ( '' === $key || '' === $ts || '' === $nonce || '' === $sig || '' === $evt ) {
			return self::deny( 'rkc_unauthorized', __( 'Unauthorized.', 'resellingkori-connector' ) );
		}
		if ( ! hash_equals( (string) RKC_Config::get( 'key_id' ), $key ) || ! ctype_digit( $ts ) || ! preg_match( '/^[A-Za-z0-9_\-]{8,64}$/', $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/', $sig ) || ! preg_match( '/^[A-Za-z0-9_\-]{6,48}$/', $evt ) ) {
			return self::deny( 'rkc_unauthorized', __( 'Unauthorized.', 'resellingkori-connector' ) );
		}
		$body = (string) $request->get_body();
		if ( '' === $body || strlen( $body ) > self::MAX_BODY ) {
			return self::deny( 'rkc_bad_request', __( 'Invalid request body.', 'resellingkori-connector' ), 400 );
		}
		$now = time() + (int) get_transient( 'rkc_clock_offset' ); // Honour a clock correction learned from the API.
		if ( abs( $now - (int) $ts ) > RKC_Signer::TOLERANCE ) {
			return self::deny( 'rkc_timestamp', __( 'Request timestamp is outside the allowed window.', 'resellingkori-connector' ) );
		}
		$secret = RKC_Config::secret();
		if ( false === $secret ) {
			return self::deny( 'rkc_unauthorized', __( 'Unauthorized.', 'resellingkori-connector' ) );
		}
		// The signature covers the request line exactly as received: method, route and the query string.
		// ResellingKori signs the webhook address without a query, so anything appended on the way (a proxy, an attacker)
		// invalidates it. "rest_route" is WordPress's own routing parameter on sites with plain permalinks.
		$params = $request->get_query_params();
		$params = is_array( $params ) ? $params : array();
		unset( $params['rest_route'] );
		foreach ( $params as $v ) {
			if ( ! is_scalar( $v ) ) {
				return self::deny( 'rkc_unauthorized', __( 'Unauthorized.', 'resellingkori-connector' ) );
			}
		}
		if ( ! RKC_Signer::verify( $secret, $sig, (int) $ts, $nonce, 'POST', self::ROUTE_PATH, RKC_Signer::build_query( $params ), $body ) ) {
			return self::deny( 'rkc_unauthorized', __( 'Unauthorized.', 'resellingkori-connector' ) );
		}
		// Replay protection: a nonce is accepted once. Inserted only after the signature checked out.
		if ( ! RKC_DB::insert_unique( 'nonces', array( 'nonce' => $nonce, 'expires_at' => time() + 2 * RKC_Signer::TOLERANCE ), array( '%s', '%d' ) ) ) {
			return self::deny( 'rkc_replay', __( 'This request was already received.', 'resellingkori-connector' ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! isset( $data['id'], $data['type'] ) || ! is_string( $data['id'] ) || ! is_string( $data['type'] ) || $data['id'] !== $evt ) {
			return new WP_Error( 'rkc_bad_request', __( 'Invalid event.', 'resellingkori-connector' ), array( 'status' => 400 ) );
		}
		self::$ctx = array( 'event' => $data, 'secret' => $secret );
		return true;
	}

	/* ---------------------------------------------------------------- Handle */

	public static function handle( WP_REST_Request $request ) {
		$ctx = self::$ctx;
		self::$ctx = null;
		if ( ! $ctx ) {
			return new WP_Error( 'rkc_unauthorized', __( 'Unauthorized.', 'resellingkori-connector' ), array( 'status' => 401 ) );
		}
		$ev   = $ctx['event'];
		$type = sanitize_text_field( $ev['type'] );

		// Ownership challenge: prove that this site holds the secret.
		if ( 'callback.verify' === $type ) {
			$challenge = isset( $ev['challenge'] ) && is_string( $ev['challenge'] ) ? $ev['challenge'] : '';
			if ( ! preg_match( '/^[A-Za-z0-9]{16,128}$/', $challenge ) ) {
				return new WP_Error( 'rkc_bad_request', __( 'Invalid challenge.', 'resellingkori-connector' ), array( 'status' => 400 ) );
			}
			RKC_Logger::info( 'Webhook address verification requested by ResellingKori' );
			return new WP_REST_Response( array( 'proof' => RKC_Signer::challenge_proof( $ctx['secret'], $challenge ) ), 200 );
		}

		if ( 0 !== strpos( $type, 'order.' ) || ! preg_match( '/^order\.[a-z_]{3,32}$/', $type ) ) {
			RKC_Logger::debug( 'Ignored unknown event type', array( 'type' => $type ) );
			return new WP_REST_Response( array( 'ok' => true, 'outcome' => 'ignored' ), 200 );
		}
		$rk = ( isset( $ev['data']['order'] ) && is_array( $ev['data']['order'] ) ) ? $ev['data']['order'] : null;
		if ( ! $rk || empty( $rk['id'] ) || empty( $rk['external_order_id'] ) || ! is_string( $rk['external_order_id'] ) ) {
			return new WP_Error( 'rkc_bad_request', __( 'The event has no order.', 'resellingkori-connector' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $ev['store_id'] ) && '' !== (string) RKC_Config::get( 'store_uid' ) && (string) $ev['store_id'] !== (string) RKC_Config::get( 'store_uid' ) ) {
			RKC_Logger::warning( 'Event for a different store was ignored', array( 'type' => $type ) );
			return new WP_REST_Response( array( 'ok' => true, 'outcome' => 'ignored' ), 200 );
		}

		// Event-level de-duplication (best effort; correctness does not depend on it, see header comment).
		$event_id = substr( (string) $ev['id'], 0, 48 );
		$claimed  = RKC_DB::insert_unique(
			'events',
			array(
				'event_id'    => $event_id,
				'received_at' => RKC_DB::now(),
				'type'        => substr( $type, 0, 40 ),
				'order_id'    => 0,
				'sequence'    => isset( $ev['sequence'] ) ? max( 0, (int) $ev['sequence'] ) : 0,
				'outcome'     => 'received',
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		if ( ! $claimed && ! self::retake_event( $event_id ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'outcome' => 'duplicate' ), 200 );
		}

		$outcome = self::process_order_event( $type, $rk, $ev );
		if ( is_wp_error( $outcome ) ) {
			self::forget_event( $event_id ); // Let ResellingKori retry.
			return $outcome;
		}
		self::finish_event( $event_id, $outcome['outcome'], $outcome['order_id'] );
		return new WP_REST_Response( array( 'ok' => true, 'outcome' => $outcome['outcome'] ), 200 );
	}

	private static function retake_event( $event_id ) {
		global $wpdb;
		$t = RKC_DB::t( 'events' );
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET received_at = %s WHERE event_id = %s AND outcome = 'received' AND received_at < %s", RKC_DB::now(), $event_id, gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	private static function forget_event( $event_id ) {
		global $wpdb;
		$wpdb->delete( RKC_DB::t( 'events' ), array( 'event_id' => $event_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function finish_event( $event_id, $outcome, $order_id ) {
		global $wpdb;
		$wpdb->update( RKC_DB::t( 'events' ), array( 'outcome' => substr( (string) $outcome, 0, 24 ), 'order_id' => (int) $order_id ), array( 'event_id' => $event_id ), array( '%s', '%d' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return array|WP_Error { outcome, order_id }
	 */
	private static function process_order_event( $type, array $rk, array $ev ) {
		$ext = substr( (string) $rk['external_order_id'], 0, 64 );
		$sub = self::submission_by_external( $ext );
		$order = null;

		if ( $sub ) {
			$order  = wc_get_order( (int) $sub->order_id );
			$latest = RKC_Submit::latest( (int) $sub->order_id );
			if ( $latest && (int) $latest->id !== (int) $sub->id ) {
				// An event about an EARLIER attempt, typically the "cancelled" notice of the order that was cancelled and then sent
				// again. It must never overwrite the state of the attempt that is active now (nor cancel it).
				RKC_Logger::debug( 'Event for a superseded attempt was ignored', array( 'type' => $type, 'external_id' => $ext ), (int) $sub->order_id );
				return array( 'outcome' => 'superseded', 'order_id' => (int) $sub->order_id );
			}
			if ( in_array( $sub->state, array( 'claimed', 'sending' ), true ) ) {
				// Our own request is still in flight; the event will be repeated.
				return new WP_Error( 'rkc_try_later', __( 'The order is still being recorded. Try again shortly.', 'resellingkori-connector' ), array( 'status' => 503 ) );
			}
			if ( 'unknown' === $sub->state ) {
				// The event itself proves the order exists: settle through the normal recovery path.
				$res = RKC_Submit::recover( (int) $sub->id, true );
				if ( is_wp_error( $res ) && 'rkc_busy' === $res->get_error_code() ) {
					return new WP_Error( 'rkc_try_later', __( 'The order is being checked. Try again shortly.', 'resellingkori-connector' ), array( 'status' => 503 ) );
				}
				$sub = RKC_Submit::get( $sub->id );
			}
			if ( $sub && 'rejected' === $sub->state ) {
				RKC_Logger::error( 'ResellingKori reports an order that this site recorded as rejected', array( 'external_id' => $ext, 'rk_order' => (int) $rk['id'] ), (int) $sub->order_id );
				return array( 'outcome' => 'conflict', 'order_id' => (int) $sub->order_id );
			}
		} elseif ( ctype_digit( $ext ) ) {
			// Order sent by v0.2.0 (no submission row): the external id is the order id and the ResellingKori id must match.
			$maybe = wc_get_order( (int) $ext );
			if ( $maybe && (int) $maybe->get_meta( '_rkc_rk_order_id' ) === (int) $rk['id'] ) {
				$order = $maybe;
			}
		}
		if ( ! $order instanceof WC_Order ) {
			RKC_Logger::warning( 'Event for an unknown order was ignored', array( 'type' => $type, 'external_id' => $ext ) );
			return array( 'outcome' => 'ignored', 'order_id' => 0 );
		}
		if ( (int) $order->get_meta( '_rkc_rk_order_id' ) && (int) $order->get_meta( '_rkc_rk_order_id' ) !== (int) $rk['id'] && $sub && 'accepted' === $sub->state ) {
			RKC_Logger::warning( 'Event ignored: ResellingKori order id does not match the linked order', array( 'external_id' => $ext ), (int) $order->get_id() );
			return array( 'outcome' => 'ignored', 'order_id' => (int) $order->get_id() );
		}

		$res = self::apply_remote( $order, $rk, 'webhook' );
		if ( 'order.error' === $type ) {
			RKC_Submit::notify(
				'rkerr-' . $order->get_id(),
				/* translators: %s: order number */
				sprintf( __( 'Order %s: ResellingKori reported a problem', 'resellingkori-connector' ), $order->get_order_number() ),
				sprintf( /* translators: 1: message, 2: link */ __( "ResellingKori reported a problem with order %1\$s:\n%2\$s\n\n%3\$s", 'resellingkori-connector' ), $order->get_order_number(), isset( $rk['error'] ) ? sanitize_text_field( (string) $rk['error'] ) : '', $order->get_edit_order_url() )
			);
		}
		return array( 'outcome' => $res['applied'] ? 'applied' : $res['reason'], 'order_id' => (int) $order->get_id() );
	}

	private static function submission_by_external( $ext ) {
		global $wpdb;
		$t   = RKC_DB::t( 'submissions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE external_id = %s", $ext ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? $row : null;
	}

	/* ------------------------------------------------------- Apply a state */

	/**
	 * Store the fulfilment state of the ResellingKori order on the store order. Used by webhooks, polling, recovery
	 * and cancellation, so all paths behave identically.
	 *
	 * @param WC_Order $order  Store order.
	 * @param array    $rk     ResellingKori order (see API_DOCUMENTATION.md, "Order object").
	 * @param string   $source submit|webhook|poll|recover|cancel.
	 * @return array { applied: bool, reason: string }
	 */
	public static function apply_remote( WC_Order $order, array $rk, $source ) {
		$known  = array_keys( RKC_Settings::rk_statuses() );
		$status = isset( $rk['status'] ) ? (string) $rk['status'] : '';
		if ( ! in_array( $status, $known, true ) ) {
			RKC_Logger::warning( 'Unknown ResellingKori status ignored', array( 'status' => substr( $status, 0, 40 ) ), (int) $order->get_id() );
			return array( 'applied' => false, 'reason' => 'ignored' );
		}
		$seq     = isset( $rk['sequence'] ) ? max( 0, (int) $rk['sequence'] ) : 0;
		$cur_seq = (int) $order->get_meta( '_rkc_event_seq' );
		if ( $seq > 0 && $seq < $cur_seq ) {
			return array( 'applied' => false, 'reason' => 'stale' );
		}

		$old_status = (string) $order->get_meta( '_rkc_rk_status' );
		$old_track  = $order->get_meta( '_rkc_tracking' );
		$old_track  = is_array( $old_track ) ? $old_track : array();
		$track      = array(
			'courier' => '',
			'number'  => '',
			'url'     => '',
		);
		if ( isset( $rk['tracking'] ) && is_array( $rk['tracking'] ) ) {
			$track['courier'] = substr( sanitize_text_field( isset( $rk['tracking']['courier'] ) ? (string) $rk['tracking']['courier'] : '' ), 0, 80 );
			$track['number']  = substr( sanitize_text_field( isset( $rk['tracking']['number'] ) ? (string) $rk['tracking']['number'] : '' ), 0, 80 );
			$track['url']     = isset( $rk['tracking']['url'] ) ? esc_url_raw( (string) $rk['tracking']['url'], array( 'http', 'https' ) ) : '';
		}
		// An event that carries no tracking at all (for example a later "delivered") must not erase what is already known.
		// A new tracking number replaces the old details as a whole, so courier, number and link always belong together.
		if ( ! array_filter( $track ) && $old_track ) {
			$track = array_merge( $track, array_intersect_key( $old_track, $track ) );
		}
		$error = isset( $rk['error'] ) ? substr( sanitize_text_field( (string) $rk['error'] ), 0, 300 ) : '';

		$status_changed = ( $old_status !== $status );
		$track_changed  = ( $old_track !== $track );
		$final          = in_array( $status, RKC_Orders::FINAL_STATUSES, true );

		$order->update_meta_data( '_rkc_rk_status', $status );
		$order->update_meta_data( '_rkc_tracking', $track );
		$order->update_meta_data( '_rkc_synced_at', time() );
		$order->update_meta_data( '_rkc_open', $final ? 0 : 1 );
		if ( $seq > $cur_seq ) {
			$order->update_meta_data( '_rkc_event_seq', $seq );
		}
		if ( ! empty( $rk['updated_at'] ) ) {
			$order->update_meta_data( '_rkc_rk_updated_at', substr( sanitize_text_field( (string) $rk['updated_at'] ), 0, 40 ) );
		}
		$error_changed = ( $error !== (string) $order->get_meta( '_rkc_remote_error' ) );
		if ( $error_changed ) {
			if ( '' === $error ) {
				$order->delete_meta_data( '_rkc_remote_error' );
			} else {
				$order->update_meta_data( '_rkc_remote_error', $error );
			}
		}
		$order->save();

		if ( 'submit' !== $source ) {
			if ( $status_changed ) {
				$order->add_order_note( sprintf( /* translators: %s: status */ __( 'ResellingKori status: %s.', 'resellingkori-connector' ), RKC_Orders::status_label( $status ) ) );
			}
			if ( $track_changed && '' !== $track['number'] ) {
				$order->add_order_note( sprintf( /* translators: 1: courier, 2: tracking number */ __( 'ResellingKori tracking: %1$s %2$s', 'resellingkori-connector' ), $track['courier'], $track['number'] ) );
			}
			if ( $error_changed && '' !== $error ) {
				$order->add_order_note( sprintf( /* translators: %s: message */ __( 'ResellingKori reported a problem: %s', 'resellingkori-connector' ), $error ) );
			}
		}
		if ( 'cancelled' === $status ) {
			RKC_Submit::mark_cancelled_remote( (int) $order->get_id() );
		}

		// Whole-order shipments only: the store's own order status follows ResellingKori. Mixed orders are never touched.
		if ( $status_changed && ! (int) $order->get_meta( '_rkc_partial' ) ) {
			$map    = (array) RKC_Settings::get( 'status_map' );
			$target = isset( $map[ $status ] ) ? (string) $map[ $status ] : '';
			$cur    = $order->get_status();
			if ( '' !== $target && $cur !== $target ) {
				if ( in_array( $cur, array( 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) {
					$order->add_order_note( sprintf( /* translators: 1: current status, 2: wanted status */ __( 'The order status was left as "%1$s" (ResellingKori status would map to "%2$s").', 'resellingkori-connector' ), wc_get_order_status_name( $cur ), wc_get_order_status_name( $target ) ) );
				} else {
					$order->update_status( $target, sprintf( /* translators: %s: status */ __( 'ResellingKori status: %s.', 'resellingkori-connector' ), RKC_Orders::status_label( $status ) ) );
				}
			}
		}

		do_action( 'rkc_remote_status_applied', $order, $rk, $source, $status_changed );
		return array( 'applied' => true, 'reason' => 'applied' );
	}

	/* -------------------------------------------------------------- Polling */

	/**
	 * Fallback for missed webhooks: ask ResellingKori about every order that is still open.
	 *
	 * @return int Number of orders refreshed.
	 */
	public static function poll() {
		if ( ! RKC_Config::connected() ) {
			return 0;
		}
		$verified = ( 'verified' === RKC_Config::get( 'callback_state' ) );
		$stale_after = $verified ? 6 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS;
		$orders = wc_get_orders(
			array(
				'limit'      => 200,
				'orderby'    => 'date',
				'order'      => 'ASC',
				'meta_key'   => '_rkc_open', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'return'     => 'objects',
			)
		);
		$by_rk = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$rk_id = (int) $order->get_meta( '_rkc_rk_order_id' );
			if ( $rk_id && ( time() - (int) $order->get_meta( '_rkc_synced_at' ) ) >= $stale_after ) {
				$by_rk[ $rk_id ] = $order;
			}
		}
		$count = 0;
		foreach ( array_chunk( array_keys( $by_rk ), 100 ) as $chunk ) {
			$res = RKC_API_Client::order_statuses( $chunk );
			if ( is_wp_error( $res ) ) {
				RKC_Logger::warning( 'Status polling failed: ' . $res->get_error_message() );
				break;
			}
			foreach ( (array) ( isset( $res['orders'] ) ? $res['orders'] : array() ) as $rk ) {
				if ( is_array( $rk ) && ! empty( $rk['id'] ) && isset( $by_rk[ (int) $rk['id'] ] ) ) {
					self::apply_remote( $by_rk[ (int) $rk['id'] ], $rk, 'poll' );
					++$count;
				}
			}
			foreach ( (array) ( isset( $res['not_found'] ) ? $res['not_found'] : array() ) as $gone ) {
				if ( isset( $by_rk[ (int) $gone ] ) ) {
					RKC_Logger::warning( 'ResellingKori no longer knows this order', array( 'rk_order' => (int) $gone ), (int) $by_rk[ (int) $gone ]->get_id() );
				}
			}
		}
		if ( $count ) {
			RKC_Logger::debug( 'Polling refreshed orders', array( 'count' => $count ) );
		}
		return $count;
	}

	/** Cron entry point for rkc_poll_orders. */
	public static function cron_poll() {
		RKC_Submit::sweep();
		self::poll();
	}
}
