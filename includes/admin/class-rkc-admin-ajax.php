<?php
/**
 * AJAX endpoints of the Connector (admin-ajax.php).
 *
 * Every endpoint, without exception: 1) verifies the nonce, 2) checks the capability for that action, 3) checks the
 * person may touch the specific product / order, 4) validates and sanitises each parameter, 5) answers with JSON that
 * never contains the API key, secret, signatures or raw ResellingKori responses. The browser renders the JSON with
 * textContent, so store or supplier text cannot inject markup.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Admin_Ajax {

	/** action => capability. */
	const ACTIONS = array(
		'verify'        => 'rkc_manage_connection',
		'map_sku'       => 'rkc_manage_mapping',
		'map_suggest'   => 'rkc_manage_mapping',
		'map_search'    => 'rkc_manage_mapping',
		'map_link'      => 'rkc_manage_mapping',
		'map_unlink'    => 'rkc_manage_mapping',
		'map_confirm'   => 'rkc_manage_mapping',
		'map_bulk_sku'  => 'rkc_manage_mapping',
		'map_refresh'   => 'rkc_manage_mapping',
		'order_review'  => 'rkc_submit_orders',
		'order_send'    => 'rkc_submit_orders',
		'order_recover' => 'rkc_submit_orders',
		'order_cancel'  => 'rkc_submit_orders',
		'order_refresh' => 'rkc_view_orders',
	);

	public static function init() {
		foreach ( array_keys( self::ACTIONS ) as $name ) {
			add_action(
				'wp_ajax_rkc_' . $name,
				function () use ( $name ) {
					RKC_Admin_Ajax::handle( $name );
				}
			);
		}
	}

	/* ------------------------------------------------------------ Plumbing */

	private static function fail( $message, $status = 400, array $extra = array() ) {
		wp_send_json_error( array_merge( array( 'message' => (string) $message ), $extra ), $status );
	}

	private static function guard( $name ) {
		if ( ! check_ajax_referer( 'rkc_ajax', 'nonce', false ) ) {
			self::fail( __( 'Your session expired. Reload the page and try again.', 'resellingkori-connector' ), 403 );
		}
		if ( ! isset( self::ACTIONS[ $name ] ) || ! current_user_can( self::ACTIONS[ $name ] ) ) {
			self::fail( __( 'You do not have permission to do this.', 'resellingkori-connector' ), 403 );
		}
	}

	private static function throttle( $key, $seconds ) {
		$k = 'rkc_thr_' . md5( $key . '|' . get_current_user_id() );
		if ( get_transient( $k ) ) {
			self::fail( __( 'Please wait a moment before trying again.', 'resellingkori-connector' ), 429 );
		}
		set_transient( $k, 1, max( 1, (int) $seconds ) );
	}

	private static function int_param( $key ) {
		return isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	private static function text_param( $key, $max = 200 ) {
		return isset( $_POST[ $key ] ) ? substr( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), 0, $max ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	/** @return int[] Positive integers, unique, at most $max. */
	private static function ids_param( $key = 'ids', $max = 200 ) {
		$raw = ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) ? wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); each value is cast with absint().
		return array_slice( array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) ), 0, $max );
	}

	/** A product the current person may edit. */
	private static function product_param( $key = 'woo_id' ) {
		$id = self::int_param( $key );
		$p  = $id ? wc_get_product( $id ) : null;
		if ( ! $p || ! current_user_can( 'edit_post', $id ) ) {
			self::fail( __( 'Product not found.', 'resellingkori-connector' ), 404 );
		}
		return $p;
	}

	/** An order the current person may edit (WooCommerce has no per-order owner; editing rights are the boundary). */
	private static function order_param() {
		$id    = self::int_param( 'order_id' );
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_orders' ) ) {
			self::fail( __( 'Order not found.', 'resellingkori-connector' ), 404 );
		}
		return $order;
	}

	public static function handle( $name ) {
		self::guard( $name );
		if ( ! class_exists( 'WooCommerce' ) ) {
			self::fail( __( 'WooCommerce is not active.', 'resellingkori-connector' ), 500 );
		}
		call_user_func( array( __CLASS__, 'do_' . $name ) );
		self::fail( __( 'Nothing happened.', 'resellingkori-connector' ), 500 ); // Safety net.
	}

	private static function error_from( WP_Error $e, array $extra = array() ) {
		self::fail( RKC_Connect::friendly( $e ), 400, array_merge( array( 'code' => $e->get_error_code() ), $extra ) );
	}

	/* --------------------------------------------------------- Connection */

	private static function do_verify() {
		self::throttle( 'verify', 3 );
		$res = RKC_Connect::verify( true );
		if ( is_wp_error( $res ) ) {
			self::error_from( $res );
		}
		wp_send_json_success( array( 'result' => $res, 'pill' => RKC_Config::get( 'status' ) ) );
	}

	/* ------------------------------------------------------------- Mapping */

	private static function bdt_str( $value ) {
		$minor = RKC_Money::parse( (string) $value );
		return null === $minor ? '' : RKC_Money::bdt( $minor );
	}

	/** Only what the screen needs from a ResellingKori product; image URLs restricted to http(s). */
	private static function shape_item( array $it ) {
		$vars = array();
		foreach ( (array) ( isset( $it['variations'] ) ? $it['variations'] : array() ) as $v ) {
			if ( ! is_array( $v ) || empty( $v['id'] ) ) {
				continue;
			}
			$vars[] = array(
				'id'    => (int) $v['id'],
				'sku'   => isset( $v['sku'] ) ? (string) $v['sku'] : '',
				'label' => isset( $v['label'] ) ? (string) $v['label'] : '',
				'base'  => self::bdt_str( isset( $v['base_price'] ) ? $v['base_price'] : '' ),
				'ok'    => ! isset( $v['purchasable'] ) || (bool) $v['purchasable'],
			);
		}
		return array(
			'id'         => (int) $it['id'],
			'name'       => isset( $it['name'] ) ? (string) $it['name'] : '',
			'sku'        => isset( $it['sku'] ) ? (string) $it['sku'] : '',
			'type'       => ( isset( $it['type'] ) && 'variable' === $it['type'] ) ? 'variable' : 'simple',
			'image'      => isset( $it['image'] ) ? esc_url_raw( (string) $it['image'], array( 'http', 'https' ) ) : '',
			'url'        => isset( $it['permalink'] ) ? esc_url_raw( (string) $it['permalink'], array( 'http', 'https' ) ) : '',
			'base'       => self::bdt_str( isset( $it['base_price'] ) ? $it['base_price'] : '' ),
			'ok'         => ! isset( $it['purchasable'] ) || (bool) $it['purchasable'],
			'matched_vid' => isset( $it['matched_variation_id'] ) ? (int) $it['matched_variation_id'] : 0,
			'score'      => isset( $it['score'] ) ? (float) $it['score'] : null,
			'confidence' => isset( $it['confidence'] ) ? sanitize_key( (string) $it['confidence'] ) : '',
			'missing'    => isset( $it['diff']['missing'] ) ? array_map( 'sanitize_text_field', array_slice( (array) $it['diff']['missing'], 0, 8 ) ) : array(),
			'extra'      => isset( $it['diff']['extra'] ) ? array_map( 'sanitize_text_field', array_slice( (array) $it['diff']['extra'], 0, 8 ) ) : array(),
			'variations' => $vars,
		);
	}

	private static function do_map_sku() {
		$p   = self::product_param();
		$res = RKC_Matcher::sku_option( $p );
		if ( is_wp_error( $res ) ) {
			self::error_from( $res );
		}
		$out = array( 'state' => $res['state'] );
		if ( 'exact' === $res['state'] ) {
			$out['item']   = self::shape_item( $res['item'] );
			$out['review'] = ! empty( $res['review'] );
		}
		wp_send_json_success( $out );
	}

	private static function do_map_suggest() {
		$p = self::product_param();
		self::throttle( 'suggest', 1 );
		$res = RKC_Matcher::suggest( RKC_Mapping::base_name( $p ), self::text_param( 'keywords', 120 ) );
		if ( is_wp_error( $res ) ) {
			self::error_from( $res );
		}
		$items = array();
		foreach ( $res['items'] as $it ) {
			$items[] = self::shape_item( $it );
		}
		wp_send_json_success( array( 'keywords' => $res['keywords'], 'items' => $items ) );
	}

	/** Manual: free-text search, or paste a ResellingKori product ID, or an exact SKU. */
	private static function do_map_search() {
		$p = self::product_param();
		self::throttle( 'search', 1 );
		$q = self::text_param( 'q', 120 );
		if ( strlen( $q ) < 2 ) {
			self::fail( __( 'Type at least two characters.', 'resellingkori-connector' ) );
		}
		$items = array();
		if ( ctype_digit( $q ) ) {
			$res = RKC_API_Client::get_product( (int) $q );
			if ( ! is_wp_error( $res ) && ! empty( $res['item']['id'] ) ) {
				$items[] = $res['item'];
			}
		}
		if ( ! $items && false === strpos( $q, ' ' ) ) {
			$res = RKC_API_Client::products_by_sku( array( $q ) );
			if ( ! is_wp_error( $res ) && ! empty( $res['matches'][ $q ] ) ) {
				$items[] = $res['matches'][ $q ];
			}
		}
		if ( ! $items ) {
			$res = RKC_Matcher::suggest( RKC_Mapping::base_name( $p ), $q );
			if ( is_wp_error( $res ) ) {
				self::error_from( $res );
			}
			$items = $res['items'];
		}
		wp_send_json_success( array( 'items' => array_map( array( __CLASS__, 'shape_item' ), $items ) ) );
	}

	private static function row_html( WC_Product $p ) {
		ob_start();
		RKC_Admin_Pages::product_row( RKC_Mapping::describe( $p ) );
		return (string) ob_get_clean();
	}

	private static function do_map_link() {
		$p      = self::product_param();
		$rk_pid = self::int_param( 'rk_product_id' );
		$rk_vid = self::int_param( 'rk_variation_id' );
		$source = self::text_param( 'source', 12 );
		$source = in_array( $source, array( 'sku', 'keyword', 'manual' ), true ) ? $source : 'manual';
		if ( ! $rk_pid ) {
			self::fail( __( 'Choose a ResellingKori product.', 'resellingkori-connector' ) );
		}
		$res = RKC_Mapping::link( $p->get_id(), $rk_pid, $rk_vid, $source );
		if ( is_wp_error( $res ) ) {
			self::error_from( $res );
		}
		RKC_Logger::info( 'Product linked', array( 'store_product' => $p->get_id(), 'rk_product' => $rk_pid, 'rk_variation' => $rk_vid, 'source' => $source ) );
		wp_send_json_success(
			array(
				'html'     => self::row_html( wc_get_product( $p->get_id() ) ),
				'siblings' => (int) $res['siblings'],
				'message'  => $res['siblings']
					? sprintf( /* translators: %d: number of variations */ _n( 'Linked. %d other variation was matched by its attributes: please confirm it.', 'Linked. %d other variations were matched by their attributes: please confirm them.', $res['siblings'], 'resellingkori-connector' ), $res['siblings'] )
					: __( 'Linked.', 'resellingkori-connector' ),
			)
		);
	}

	private static function do_map_unlink() {
		$done = 0;
		foreach ( self::ids_param() as $id ) {
			if ( wc_get_product( $id ) && current_user_can( 'edit_post', $id ) && RKC_Mapping::get( $id ) ) {
				RKC_Mapping::clear( $id );
				++$done;
			}
		}
		RKC_Logger::info( 'Products unlinked', array( 'count' => $done ) );
		/* translators: %d: number of products */
		RKC_Admin::flash( 'success', sprintf( _n( '%d link was removed.', '%d links were removed.', $done, 'resellingkori-connector' ), $done ) );
		wp_send_json_success( array( 'count' => $done ) );
	}

	private static function do_map_confirm() {
		$done = 0;
		foreach ( self::ids_param() as $id ) {
			if ( wc_get_product( $id ) && current_user_can( 'edit_post', $id ) && RKC_Mapping::confirm( $id ) ) {
				++$done;
			}
		}
		RKC_Logger::info( 'Product links confirmed', array( 'count' => $done ) );
		/* translators: %d: number of products */
		RKC_Admin::flash( 'success', sprintf( _n( '%d link was confirmed.', '%d links were confirmed.', $done, 'resellingkori-connector' ), $done ) );
		wp_send_json_success( array( 'count' => $done ) );
	}

	/**
	 * Exact SKU linking. mode=ids: the ticked rows. mode=all: every unlinked product with a SKU, in batches
	 * (the browser repeats the call until done=true, passing the cursor back).
	 *
	 * The cursor counts every product of a batch that stays in the "unlinked" list (not linked, skipped, not allowed),
	 * so the next batch starts after them and the loop always ends.
	 */
	private static function do_map_bulk_sku() {
		$mode = self::text_param( 'mode', 6 );
		if ( 'all' === $mode ) {
			$cursor = self::int_param( 'cursor' );
			$raw    = RKC_Mapping::unmapped_with_sku( 40, $cursor );
		} else {
			$mode   = 'ids';
			$cursor = 0;
			$raw    = self::ids_param();
		}
		if ( ! $raw ) {
			wp_send_json_success( array( 'done' => true, 'cursor' => $cursor, 'linked' => 0, 'review' => 0, 'not_found' => 0, 'count' => 0 ) );
		}
		$ids = array_values(
			array_filter(
				$raw,
				function ( $id ) {
					return current_user_can( 'edit_post', $id );
				}
			)
		);
		if ( ! $ids ) { // Nothing in this batch may be touched by this person: move past it.
			wp_send_json_success( array( 'done' => ( 'all' !== $mode ), 'cursor' => $cursor + count( $raw ), 'linked' => 0, 'review' => 0, 'not_found' => 0, 'count' => 0 ) );
		}
		$res = RKC_Matcher::bulk_sku_link( $ids );
		if ( is_wp_error( $res ) ) {
			self::error_from( $res );
		}
		RKC_Logger::info( 'Bulk SKU matching', array( 'mode' => $mode, 'linked' => $res['linked'], 'review' => $res['review'], 'not_found' => $res['not_found'] ) );
		if ( 'ids' === $mode ) {
			RKC_Admin::flash(
				$res['review'] ? 'warning' : 'success',
				sprintf(
					/* translators: 1: linked, 2: need review, 3: no exact SKU match */
					__( 'Matched by SKU: %1$d linked. %2$d need your review because the names differ or the variation is unclear. %3$d have no exact SKU match at ResellingKori.', 'resellingkori-connector' ),
					(int) $res['linked'],
					(int) $res['review'],
					(int) $res['not_found']
				)
			);
		}
		wp_send_json_success(
			array(
				'done'      => ( 'all' !== $mode ),
				'cursor'    => $cursor + count( $raw ) - (int) $res['linked'], // Linked units leave the unlinked list; all others stay in it.
				'linked'    => (int) $res['linked'],
				'review'    => (int) $res['review'],
				'not_found' => (int) $res['not_found'],
				'count'     => count( $ids ),
			)
		);
	}

	private static function do_map_refresh() {
		$offset = self::int_param( 'cursor' );
		$ids    = RKC_Mapping::mapped_ids( 25, $offset );
		if ( ! $ids ) {
			wp_send_json_success( array( 'done' => true, 'cursor' => $offset, 'checked' => 0, 'unavailable' => 0 ) );
		}
		$res = RKC_Mapping::refresh_availability( $ids );
		if ( is_wp_error( $res ) ) {
			self::error_from( $res );
		}
		wp_send_json_success(
			array(
				'done'        => count( $ids ) < 25,
				'cursor'      => $offset + count( $ids ),
				'checked'     => (int) $res['checked'],
				'unavailable' => (int) $res['unavailable'],
			)
		);
	}

	/* -------------------------------------------------------------- Orders */

	/** The person's choices for the proposal. Only choices; the amounts are always recomputed on the server. */
	private static function order_opts() {
		$opts = array();
		if ( isset( $_POST['item_ids'] ) && is_array( $_POST['item_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
			$opts['item_ids'] = self::ids_param( 'item_ids', 100 );
		}
		$delivery = self::text_param( 'delivery', 16 );
		if ( '' !== $delivery ) {
			$opts['delivery'] = $delivery;
		}
		if ( isset( $_POST['include_other'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
			$opts['include_other'] = ( '1' === self::text_param( 'include_other', 1 ) );
		}
		return $opts;
	}

	private static function panel( WC_Order $order ) {
		return RKC_Admin_Order::panel_html( wc_get_order( $order->get_id() ) );
	}

	private static function do_order_review() {
		$order = self::order_param();
		$draft = RKC_Orders::review( $order, self::order_opts(), get_current_user_id(), '1' === self::text_param( 'refresh', 1 ) );
		if ( is_wp_error( $draft ) ) {
			self::error_from( $draft );
		}
		wp_send_json_success( array( 'draft' => RKC_Admin_Order::draft_view( $draft ) ) );
	}

	private static function do_order_send() {
		$order = self::order_param();
		self::throttle( 'send-' . $order->get_id(), 2 );
		$res = RKC_Submit::send( $order, self::order_opts(), self::text_param( 'fingerprint', 64 ), get_current_user_id() );
		if ( is_wp_error( $res ) ) {
			$d = (array) $res->get_error_data();
			self::fail(
				$res->get_error_message(),
				'unknown' === ( isset( $d['stage'] ) ? $d['stage'] : '' ) ? 202 : 400,
				array(
					'code'    => $res->get_error_code(),
					'stage'   => isset( $d['stage'] ) ? $d['stage'] : '',
					'details' => isset( $d['details'] ) ? self::safe_details( $d['details'] ) : array(),
					'errors'  => isset( $d['errors'] ) ? array_map( 'wp_strip_all_tags', (array) $d['errors'] ) : array(),
					'panel'   => self::panel( $order ),
				)
			);
		}
		wp_send_json_success(
			array(
				'stage'   => $res['stage'],
				'message' => ! empty( $res['warning'] ) ? $res['warning'] : sprintf( /* translators: %s: ResellingKori order number */ __( 'Sent to ResellingKori as order #%s.', 'resellingkori-connector' ), $res['rk_order_number'] ),
				'panel'   => self::panel( $order ),
			)
		);
	}

	private static function safe_details( $d ) {
		return array(
			'fields'    => self::flat_rows( isset( $d['fields'] ) ? $d['fields'] : array() ),
			'changes'   => self::flat_rows( isset( $d['changes'] ) ? $d['changes'] : array() ),
			'shortages' => self::flat_rows( isset( $d['shortages'] ) ? $d['shortages'] : array() ),
		);
	}

	private static function flat_rows( $rows ) {
		$out = array();
		foreach ( array_slice( (array) $rows, 0, 20 ) as $r ) {
			$out[] = is_array( $r ) ? array_map( 'wp_strip_all_tags', array_map( 'strval', array_filter( $r, 'is_scalar' ) ) ) : array();
		}
		return $out;
	}

	private static function do_order_recover() {
		$order = self::order_param();
		self::throttle( 'recover-' . $order->get_id(), 3 );
		$sub = RKC_Submit::latest( $order->get_id() );
		if ( ! $sub ) {
			self::fail( __( 'This order was not sent yet.', 'resellingkori-connector' ) );
		}
		$res = RKC_Submit::recover( (int) $sub->id, true );
		if ( is_wp_error( $res ) ) {
			wp_send_json_success( array( 'stage' => 'unknown', 'message' => $res->get_error_message(), 'panel' => self::panel( $order ) ) );
		}
		wp_send_json_success( array( 'stage' => $res['stage'], 'message' => __( 'Checked with ResellingKori.', 'resellingkori-connector' ), 'panel' => self::panel( $order ) ) );
	}

	private static function do_order_cancel() {
		$order = self::order_param();
		self::throttle( 'cancel-' . $order->get_id(), 3 );
		$res = RKC_Submit::cancel( $order, self::text_param( 'reason', 200 ), get_current_user_id() );
		if ( is_wp_error( $res ) ) {
			self::fail( $res->get_error_message(), 400, array( 'panel' => self::panel( $order ) ) );
		}
		wp_send_json_success( array( 'stage' => 'cancelled', 'message' => __( 'The order was cancelled at ResellingKori.', 'resellingkori-connector' ), 'panel' => self::panel( $order ) ) );
	}

	private static function do_order_refresh() {
		$order = self::order_param();
		self::throttle( 'refresh-' . $order->get_id(), 5 );
		$rk_id = (int) $order->get_meta( '_rkc_rk_order_id' );
		if ( ! $rk_id ) {
			self::fail( __( 'This order was not sent to ResellingKori.', 'resellingkori-connector' ) );
		}
		$res = RKC_API_Client::order_statuses( array( $rk_id ) );
		if ( is_wp_error( $res ) ) {
			self::error_from( $res );
		}
		foreach ( (array) ( isset( $res['orders'] ) ? $res['orders'] : array() ) as $rk ) {
			if ( is_array( $rk ) && isset( $rk['id'] ) && (int) $rk['id'] === $rk_id ) {
				RKC_Webhook::apply_remote( $order, $rk, 'poll' );
			}
		}
		wp_send_json_success( array( 'message' => __( 'Status refreshed.', 'resellingkori-connector' ), 'panel' => self::panel( $order ) ) );
	}
}
