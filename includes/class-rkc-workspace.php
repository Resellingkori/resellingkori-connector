<?php
/**
 * The agent dashboard at ResellingKori: what this shop reports, and how it keeps itself in step.
 *
 * The shop is always the one that calls out. About once a minute (and at once when the dashboard "wakes" it) it makes ONE
 * signed request to ResellingKori that
 *   - reports the orders and products that changed (small "cards": no e-mail, no street address, no full phone number),
 *   - reports what became of the instructions it was given earlier, and
 *   - receives the instructions that are waiting (send an order, cancel it, link a product ...).
 * ResellingKori never opens a connection to this shop except for the "wake" link, which only asks it to run that same
 * routine now. The answer is signed with the shared secret, because an answer carries instructions this shop acts on.
 *
 * This class owns the bookkeeping (what is dirty, what ResellingKori already has), the cards and the sync routine.
 * Instructions are executed by RKC_Workspace_Exec.
 *
 * Switches (Settings): workspace_sync  yes | no | '' (not decided: nothing is sent)
 *                      workspace_actions  yes | no (no = the dashboard may look, but not send, cancel or link)
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Workspace {

	const PROTOCOL     = 1;
	const STATE_OPTION = 'rkc_ws_state';
	const LOCK         = 'lock:ws';
	const MAX_ORDERS   = 25;
	const MAX_PRODUCTS = 100;
	const MAX_GONE     = 200;
	const MAX_RESULTS  = 40;
	const MAX_LINES    = 100;
	const MAX_BODY     = 230000; // ResellingKori accepts 256 KB.
	const BUDGET       = 22;     // Seconds one run may spend.
	const MAX_ROUNDS   = 4;

	/** @var int Last value handed out for dirty_at in this request (strictly increasing). */
	private static $last_ms = 0;

	/** @var array<int,bool> Units whose mapping changed during this request (handled once, at shutdown). */
	private static $pending_units = array();

	/** @var bool */
	private static $shutdown_set = false;

	/* ============================================================== Wiring */

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );

		// Orders: anything that can change what the dashboard shows.
		foreach ( array( 'woocommerce_new_order', 'woocommerce_update_order', 'woocommerce_order_status_changed', 'woocommerce_trash_order', 'woocommerce_untrash_order', 'woocommerce_before_delete_order', 'woocommerce_order_refunded' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_order' ), 20, 1 );
		}
		foreach ( array( 'rkc_order_submitted', 'rkc_order_rejected', 'rkc_order_cancelled', 'rkc_remote_status_applied' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_order' ), 20, 1 );
		}
		add_action( 'before_delete_post', array( __CLASS__, 'on_post' ), 20, 1 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_post' ), 20, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'on_post' ), 20, 1 );

		// Products and variations.
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_product' ), 20, 1 );
		}
		// A link is created, changed, confirmed or removed: every one of those is a change of these post-meta keys.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_meta' ), 20, 3 );
		}

		// The switch in Settings.
		add_action( 'update_option_' . RKC_Settings::OPTION, array( __CLASS__, 'on_settings_updated' ), 10, 2 );
		add_action( 'add_option_' . RKC_Settings::OPTION, array( __CLASS__, 'on_settings_added' ), 10, 2 );
	}

	/** Is the sync switched on and usable right now? */
	public static function enabled() {
		return 'yes' === RKC_Settings::get( 'workspace_sync' ) && RKC_Config::connected();
	}

	/** May the dashboard act (send, cancel, link) on this shop's behalf? */
	public static function actions_allowed() {
		return 'no' !== RKC_Settings::get( 'workspace_actions' );
	}

	/* ================================================================ State */

	public static function state() {
		$d = array(
			'last_ok'   => 0,
			'last_try'  => 0,
			'last_code' => '',
			'last_msg'  => '',
			'fails'     => 0,
			'next_try'  => 0,
			'remote'    => '', // '' | unavailable | off | old
			'full'      => null,
			'last_full' => 0,
			'purge'     => false,
			'skew'      => 0,
			'key'       => '',
			'counts'    => array(),
			'executed'  => 0,
		);
		return wp_parse_args( (array) get_option( self::STATE_OPTION, array() ), $d );
	}

	public static function set_state( array $patch ) {
		$s = array_merge( self::state(), $patch );
		update_option( self::STATE_OPTION, $s, false );
		return $s;
	}

	/* ============================================================== Marking */

	private static function now_ms() {
		$ms = (int) round( microtime( true ) * 1000 );
		if ( $ms <= self::$last_ms ) {
			$ms = self::$last_ms + 1;
		}
		self::$last_ms = $ms;
		return $ms;
	}

	/**
	 * Remember that an order ('o') or a product ('p') has to be reported again.
	 *
	 * @param string $kind        o | p.
	 * @param int    $ref         Order id or product / variation id.
	 * @param bool   $force_known ResellingKori is known to hold a card for it (it asked us to confirm it).
	 */
	public static function mark( $kind, $ref, $force_known = false ) {
		global $wpdb;
		$ref = (int) $ref;
		if ( $ref < 1 || ! self::enabled() ) {
			return;
		}
		$kind = ( 'p' === $kind ) ? 'p' : 'o';
		$t    = RKC_DB::t( 'ws_items' );
		$ms   = self::now_ms();
		$ok   = RKC_DB::insert_unique( 'ws_items', array( 'kind' => $kind, 'ref' => $ref, 'dirty_at' => $ms, 'known' => $force_known ? 1 : 0, 'want_gone' => 0, 'rev' => '' ), array( '%s', '%d', '%d', '%d', '%d', '%s' ) );
		if ( ! $ok ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET dirty_at = %d" . ( $force_known ? ', known = 1' : '' ) . ' WHERE kind = %s AND ref = %d', $ms, $kind, $ref ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		self::kick();
	}

	/** Many at once (the first full run); no per-item cron kicks. */
	private static function mark_many( $kind, array $refs ) {
		global $wpdb;
		$refs = array_values( array_unique( array_filter( array_map( 'intval', $refs ) ) ) );
		if ( ! $refs ) {
			return;
		}
		$kind = ( 'p' === $kind ) ? 'p' : 'o';
		$t    = RKC_DB::t( 'ws_items' );
		foreach ( array_chunk( $refs, 200 ) as $chunk ) {
			$in       = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$existing = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ref FROM {$t} WHERE kind = %s AND ref IN ({$in})", array_merge( array( $kind ), $chunk ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$ms       = self::now_ms();
			if ( $existing ) {
				$in2 = implode( ',', array_fill( 0, count( $existing ), '%d' ) );
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET dirty_at = %d WHERE kind = %s AND ref IN ({$in2})", array_merge( array( $ms, $kind ), $existing ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			}
			foreach ( array_diff( $chunk, $existing ) as $ref ) {
				RKC_DB::insert_unique( 'ws_items', array( 'kind' => $kind, 'ref' => (int) $ref, 'dirty_at' => self::now_ms(), 'known' => 0, 'want_gone' => 0, 'rev' => '' ), array( '%s', '%d', '%d', '%d', '%d', '%s' ) );
			}
		}
	}

	/** Ask WP-Cron to run a sync shortly (at most once every 15 s). */
	public static function kick() {
		static $asked = false;
		if ( $asked ) {
			return;
		}
		$asked = true;
		if ( get_transient( 'rkc_ws_kicked' ) ) {
			return;
		}
		set_transient( 'rkc_ws_kicked', 1, 15 );
		if ( ! wp_next_scheduled( 'rkc_ws_kick' ) ) {
			wp_schedule_single_event( time() + 3, 'rkc_ws_kick' );
		}
	}

	/* ---------------------------------------------------------------- Hooks */

	/**
	 * @param mixed $order Order id or WC_Order (the hooks that feed this differ).
	 */
	public static function on_order( $order ) {
		$id = ( is_object( $order ) && method_exists( $order, 'get_id' ) ) ? (int) $order->get_id() : (int) $order;
		if ( $id > 0 && self::enabled() ) {
			self::mark( 'o', $id );
		}
	}

	/** Trash, untrash and delete of posts: only products and variations matter here (legacy order storage is covered by the order hooks). */
	public static function on_post( $post_id ) {
		if ( ! self::enabled() ) {
			return;
		}
		$type = get_post_type( (int) $post_id );
		if ( 'product' === $type || 'product_variation' === $type ) {
			self::mark( 'p', (int) $post_id );
		} elseif ( 'shop_order' === $type ) {
			self::mark( 'o', (int) $post_id );
		}
	}

	public static function on_product( $product_id ) {
		if ( ! self::enabled() ) {
			return;
		}
		$product_id = (int) $product_id;
		self::mark( 'p', $product_id );
		// A variable product's name is part of its variations' cards.
		if ( 'product' === get_post_type( $product_id ) ) {
			$p = wc_get_product( $product_id );
			if ( $p && $p->is_type( 'variable' ) ) {
				foreach ( array_slice( (array) $p->get_children(), 0, 200 ) as $child ) {
					self::mark( 'p', (int) $child );
				}
			}
		}
	}

	/** A link between a store product and a ResellingKori product changed (created, edited, confirmed or removed). */
	public static function on_meta( $meta_id, $object_id, $meta_key ) {
		if ( ! in_array( $meta_key, array( RKC_Mapping::META_PRODUCT, RKC_Mapping::META_VARIATION, RKC_Mapping::META_REVIEW ), true ) || ! self::enabled() ) {
			return;
		}
		self::$pending_units[ (int) $object_id ] = true;
		if ( ! self::$shutdown_set ) {
			self::$shutdown_set = true;
			add_action( 'shutdown', array( __CLASS__, 'flush_units' ), 5 );
		}
	}

	/** Once per request: the changed units, and the orders that contain them. */
	public static function flush_units() {
		$units                = array_keys( self::$pending_units );
		self::$pending_units = array();
		if ( ! $units || ! self::enabled() ) {
			return;
		}
		foreach ( $units as $u ) {
			self::mark( 'p', (int) $u );
		}
		foreach ( self::orders_with_units( $units, 200 ) as $order_id ) {
			self::mark( 'o', (int) $order_id );
		}
	}

	/**
	 * Orders (newest first) that contain any of these products / variations.
	 *
	 * @param int[] $unit_ids Product or variation ids.
	 * @param int   $limit    Maximum number of orders.
	 * @return int[]
	 */
	public static function orders_with_units( array $unit_ids, $limit ) {
		global $wpdb;
		$unit_ids = array_values( array_unique( array_filter( array_map( 'intval', $unit_ids ) ) ) );
		$out      = array();
		$items    = $wpdb->prefix . 'woocommerce_order_items';
		$meta     = $wpdb->prefix . 'woocommerce_order_itemmeta';
		foreach ( array_chunk( $unit_ids, 300 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$args = array_merge( array_map( 'strval', $chunk ), array( (int) $limit ) );
			$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT oi.order_id FROM {$items} oi INNER JOIN {$meta} im ON im.order_item_id = oi.order_item_id WHERE oi.order_item_type = 'line_item' AND im.meta_key IN ('_product_id','_variation_id') AND im.meta_value IN ({$in}) ORDER BY oi.order_id DESC LIMIT %d", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $ids as $id ) {
				$out[ (int) $id ] = true;
			}
		}
		$ids = array_keys( $out );
		rsort( $ids );
		return array_slice( $ids, 0, (int) $limit );
	}

	/* ===================================================== Settings changes */

	public static function on_settings_added( $option, $value ) {
		self::on_settings_updated( array(), $value );
	}

	public static function on_settings_updated( $old, $new ) {
		$was = ( is_array( $old ) && isset( $old['workspace_sync'] ) ) ? (string) $old['workspace_sync'] : '';
		$now = ( is_array( $new ) && isset( $new['workspace_sync'] ) ) ? (string) $new['workspace_sync'] : '';
		if ( 'yes' !== $was && 'yes' === $now ) {
			self::set_state( array( 'remote' => '', 'fails' => 0, 'next_try' => 0, 'purge' => false, 'last_code' => '', 'last_msg' => '' ) );
			self::start_full();
			self::kick();
		} elseif ( 'yes' === $was && 'yes' !== $now ) {
			// Switched off: ResellingKori is told once to delete what it holds, and nothing is collected any more.
			global $wpdb;
			$wpdb->query( 'DELETE FROM ' . RKC_DB::t( 'ws_items' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			self::set_state( array( 'purge' => true, 'full' => null, 'next_try' => 0 ) );
			self::kick();
		}
	}

	/** Called by RKC_Install when this version is first loaded over another. */
	public static function on_upgrade( $from ) {
		// A new version may speak a newer protocol: forget "unavailable / old" verdicts and try again.
		$s = self::state();
		if ( '' !== $s['remote'] || $s['fails'] ) {
			self::set_state( array( 'remote' => '', 'fails' => 0, 'next_try' => 0 ) );
		}
	}

	/** Before the key is removed: tell ResellingKori to delete what it holds for this shop (best effort), then forget. */
	public static function before_disconnect() {
		global $wpdb;
		if ( 'yes' === RKC_Settings::get( 'workspace_sync' ) && RKC_Config::configured() ) {
			self::send_purge( 8 );
		}
		$wpdb->query( 'DELETE FROM ' . RKC_DB::t( 'ws_items' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'UPDATE ' . RKC_DB::t( 'ws_cmd' ) . " SET reported = 1 WHERE state = 'done'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		delete_option( self::STATE_OPTION );
	}

	/* ==================================================== Privacy helpers */

	/** The customer's data was erased: the dashboard must not keep showing this order's card. */
	public static function forget_order( $order_id ) {
		global $wpdb;
		$order_id = (int) $order_id;
		if ( $order_id < 1 || 'yes' !== RKC_Settings::get( 'workspace_sync' ) ) {
			return;
		}
		$t  = RKC_DB::t( 'ws_items' );
		$ms = self::now_ms();
		if ( ! RKC_DB::insert_unique( 'ws_items', array( 'kind' => 'o', 'ref' => $order_id, 'dirty_at' => $ms, 'known' => 1, 'want_gone' => 1, 'rev' => '' ), array( '%s', '%d', '%d', '%d', '%d', '%s' ) ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET dirty_at = %d, want_gone = 1 WHERE kind = 'o' AND ref = %d", $ms, $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		self::kick();
	}

	/** Has a card of this order been sent to the dashboard? */
	public static function is_listed( $order_id ) {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT known FROM ' . RKC_DB::t( 'ws_items' ) . " WHERE kind = 'o' AND ref = %d", (int) $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/* =============================================================== Cards */

	private static function text( $v, $max ) {
		$s = wp_strip_all_tags( wp_specialchars_decode( is_scalar( $v ) ? (string) $v : '', ENT_QUOTES ) );
		$c = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $s );
		$s = trim( null === $c ? '' : $c );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, (int) $max ) : substr( $s, 0, (int) $max );
	}

	/** "01712345678" -> "017•••••678". Never the whole number. */
	public static function mask_phone( $phone ) {
		$d = preg_replace( '/\D+/', '', (string) $phone );
		$d = ( null === $d ) ? '' : $d;
		if ( '' === $d ) {
			return '';
		}
		if ( 13 === strlen( $d ) && 0 === strpos( $d, '880' ) ) {
			$d = '0' . substr( $d, 3 );
		}
		$n = strlen( $d );
		if ( $n >= 8 ) {
			return substr( $d, 0, 3 ) . '•••••' . substr( $d, -3 );
		}
		return str_repeat( '•', max( 0, $n - 2 ) ) . substr( $d, -2 );
	}

	private static function sort_deep( $v ) {
		if ( ! is_array( $v ) ) {
			return $v;
		}
		$is_list = ( $v === array() ) || ( array_keys( $v ) === range( 0, count( $v ) - 1 ) );
		foreach ( $v as $k => $x ) {
			$v[ $k ] = self::sort_deep( $x );
		}
		if ( ! $is_list ) {
			ksort( $v );
		}
		return $v;
	}

	/** Identity of a card's content (volatile fields excluded): equal content, equal rev. */
	private static function rev_of( array $card, array $ignore ) {
		foreach ( $ignore as $path ) {
			if ( 2 === count( $path ) ) {
				if ( isset( $card[ $path[0] ] ) && is_array( $card[ $path[0] ] ) ) {
					$card[ $path[0] ][ $path[1] ] = 0;
				}
			} else {
				unset( $card[ $path[0] ] );
			}
		}
		return substr( hash( 'sha256', (string) wp_json_encode( self::sort_deep( $card ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), 0, 40 );
	}

	/**
	 * The dashboard's picture of one order, or null when the order is not (or no longer) the dashboard's business: it has no
	 * ResellingKori product, or it was never sent and is cancelled / failed / refunded / trashed / a draft.
	 *
	 * @param WC_Order|mixed $order Order.
	 * @return array|null
	 */
	public static function order_card( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$lines    = RKC_Orders::lines( $order );
		$relevant = false;
		foreach ( $lines as $l ) {
			if ( ! empty( $l['map'] ) || ! empty( $l['needs_review'] ) ) {
				$relevant = true;
				break;
			}
		}
		if ( ! $relevant ) {
			return null;
		}
		$status = (string) $order->get_status();
		$st     = RKC_Orders::state( $order );
		$unsent = in_array( $st['stage'], array( 'none', 'cancelled', 'rejected' ), true );
		if ( $unsent && in_array( $status, array( 'cancelled', 'failed', 'refunded', 'trash', 'draft', 'auto-draft', 'checkout-draft' ), true ) ) {
			return null;
		}
		$can = RKC_Orders::can_send( $order, $lines );
		$ok  = ! is_wp_error( $can );
		if ( $unsent && ! $ok && 'completed' === $status ) {
			return null;
		}
		$block = $ok ? '' : $can->get_error_message();
		if ( count( $lines ) > self::MAX_LINES ) {
			$lines = array_slice( $lines, 0, self::MAX_LINES );
			$ok    = false;
			$block = __( 'This order has more than 100 lines, which the agent dashboard cannot show. Send it from the shop\'s own order screen.', 'resellingkori-connector' );
		}

		$cust = RKC_Orders::customer( $order );
		$out  = array();
		foreach ( $lines as $l ) {
			$map = null;
			if ( ! empty( $l['map'] ) ) {
				$map = array(
					'pid'  => (int) $l['map']['rk_product_id'],
					'vid'  => (int) $l['map']['rk_variation_id'],
					'name' => self::text( $l['map']['name'], 200 ),
					'sku'  => self::text( $l['map']['sku'], 100 ),
				);
			}
			$out[] = array(
				'i'     => (int) $l['item_id'],
				'name'  => self::text( $l['name'], 200 ),
				'label' => '',
				'sku'   => self::text( $l['sku'], 100 ),
				'qty'   => (int) $l['qty'],
				'unit'  => (int) $l['unit_minor'],
				'sell'  => (int) $l['sell_minor'],
				'paid'  => (int) $l['paid_minor'],
				'img'   => (string) $l['image'],
				'map'   => $map,
				'rv'    => ! empty( $l['needs_review'] ),
			);
		}

		$limits = array();
		$opt    = (array) get_option( 'rkc_limits', array() );
		foreach ( array( 'max_delivery_charge', 'max_cod_amount', 'max_order_amount', 'max_lines' ) as $k ) {
			if ( isset( $opt[ $k ] ) && is_numeric( $opt[ $k ] ) && (float) $opt[ $k ] >= 0 ) {
				$limits[ $k ] = 0 + $opt[ $k ];
			}
		}

		$trk = is_array( $st['tracking'] ) ? $st['tracking'] : array();
		$err = null;
		if ( ! empty( $st['error'] ) && is_array( $st['error'] ) ) {
			$err = array(
				'code'    => self::text( isset( $st['error']['code'] ) ? $st['error']['code'] : '', 64 ),
				'message' => self::text( isset( $st['error']['message'] ) ? $st['error']['message'] : '', 300 ),
			);
		}
		$created = $order->get_date_created();

		$card = array(
			'ref'          => (string) (int) $order->get_id(),
			'number'       => self::text( $order->get_order_number(), 40 ),
			'created'      => $created ? (int) $created->getTimestamp() : 0,
			'status'       => $status,
			'status_label' => self::text( wc_get_order_status_name( $status ), 60 ),
			'cod'          => (bool) RKC_Orders::is_cod( $order ),
			'currency'     => strtoupper( substr( (string) $order->get_currency(), 0, 3 ) ),
			'customer'     => array(
				'name'       => self::text( $cust['name'], 120 ),
				'phone_hint' => self::mask_phone( $cust['phone'] ),
				'city'       => self::text( $cust['city'], 60 ),
				'state'      => self::text( $cust['state'], 60 ),
			),
			'edit_url'     => (string) $order->get_edit_order_url(),
			'lines'        => $out,
			'money'        => array(
				'shipping'         => RKC_Money::from_wc( $order->get_shipping_total() ) + RKC_Money::from_wc( $order->get_shipping_tax() ),
				'total'            => RKC_Money::from_wc( $order->get_total() ) - RKC_Money::from_wc( $order->get_total_refunded() ),
				'partial_delivery' => 'order' === RKC_Settings::get( 'partial_delivery' ) ? 'order' : 'none',
				'limits'           => $limits,
			),
			'send'         => array(
				'stage'       => (string) $st['stage'],
				'ok'          => $ok,
				'block'       => self::text( $block, 300 ),
				'rk_id'       => (int) $st['rk_order_id'],
				'rk_number'   => self::text( $st['number'], 64 ),
				'rk_status'   => (string) $st['status'],
				'tracking'    => array(
					'courier' => self::text( isset( $trk['courier'] ) ? $trk['courier'] : '', 80 ),
					'number'  => self::text( isset( $trk['number'] ) ? $trk['number'] : '', 80 ),
					'url'     => isset( $trk['url'] ) && is_scalar( $trk['url'] ) ? esc_url_raw( (string) $trk['url'], array( 'https', 'http' ) ) : '',
				),
				'partial'     => (bool) $st['partial'],
				'sent_items'  => array_values( array_map( 'intval', (array) $st['sent_items'] ) ),
				'error'       => $err,
				'changed'     => (bool) $st['changed'],
				'cancellable' => ( 'accepted' === $st['stage'] && (int) $st['rk_order_id'] > 0 && in_array( $st['status'], array( 'pending', 'processing', 'on_hold' ), true ) ),
				'synced'      => (int) $st['synced_at'],
			),
		);
		// "synced" changes at every status check; it must not make the order look changed.
		$card['rev']   = self::rev_of( $card, array( array( 'send', 'synced' ) ) );
		$card['built'] = (int) round( microtime( true ) * 1000 );
		return $card;
	}

	/**
	 * A store product (simple product or one variation) as the dashboard's "Products" tab shows it, or null when it is not
	 * a unit that can be linked (variable parent, grouped, external) or is not published.
	 *
	 * @param int $woo_id Product or variation id.
	 * @return array|null
	 */
	public static function product_card( $woo_id ) {
		$p = wc_get_product( (int) $woo_id );
		if ( ! $p || $p->is_type( array( 'variable', 'grouped', 'external' ) ) || ! in_array( $p->get_status(), array( 'publish', 'private' ), true ) ) {
			return null;
		}
		if ( $p->is_type( 'variation' ) ) {
			$parent = wc_get_product( $p->get_parent_id() );
			if ( ! $parent || ! in_array( $parent->get_status(), array( 'publish', 'private' ), true ) ) {
				return null;
			}
		}
		$d   = RKC_Mapping::describe( $p );
		$map = null;
		if ( ! empty( $d['map'] ) ) {
			$map = array(
				'pid'    => (int) $d['map']['rk_product_id'],
				'vid'    => (int) $d['map']['rk_variation_id'],
				'name'   => self::text( $d['map']['name'], 255 ),
				'sku'    => self::text( $d['map']['sku'], 100 ),
				'review' => ! empty( $d['map']['review'] ),
			);
		}
		$raw   = (string) $d['price'];
		$price = ( '' === $raw ) ? '' : (string) wc_format_decimal( $raw, 4, true );
		$card  = array(
			'woo'    => (int) $d['id'],
			'parent' => (int) $d['parent_id'],
			'name'   => self::text( $d['name'], 255 ),
			'label'  => self::text( $d['label'], 255 ),
			'sku'    => self::text( $d['sku'], 100 ),
			'price'  => preg_match( '/^\d{1,12}(\.\d{1,4})?$/', $price ) ? $price : '',
			'img'    => (string) $d['image'],
			'map'    => $map,
		);
		$card['rev']   = self::rev_of( $card, array() );
		$card['built'] = (int) round( microtime( true ) * 1000 );
		return $card;
	}

	/* ======================================================= Full re-send */

	/** Report every order that has a ResellingKori product and every linkable product, a batch at a time. */
	public static function start_full() {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . RKC_DB::t( 'ws_items' ) . " SET rev = '' WHERE kind = 'p'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		self::set_state(
			array(
				'full'      => array( 'phase' => 'orders', 'offset' => 0 ),
				'last_full' => time(),
				'key'       => (string) RKC_Config::get( 'key_id' ),
			)
		);
	}

	private static function dirty_count( $kind ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RKC_DB::t( 'ws_items' ) . ' WHERE kind = %s AND dirty_at > 0', $kind ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/** One batch of the full run. */
	private static function step_full() {
		global $wpdb;
		$s = self::state();
		if ( empty( $s['full'] ) || ! is_array( $s['full'] ) ) {
			return;
		}
		$f = $s['full'];
		if ( 'orders' === $f['phase'] ) {
			$units = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_rkc_rk_product_id' ORDER BY post_id DESC LIMIT 5000" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::mark_many( 'o', self::orders_with_units( $units, 500 ) );
			self::set_state( array( 'full' => array( 'phase' => 'products', 'offset' => 0 ) ) );
			return;
		}
		if ( self::dirty_count( 'p' ) > 200 ) {
			return; // Let the queue drain first.
		}
		$batch                 = 300;
		$args                  = RKC_Mapping::query_args( 'all', '', $batch, 1 );
		$args['paged']         = 1;
		$args['offset']        = (int) $f['offset'];
		$args['orderby']       = 'ID';
		$args['order']         = 'ASC';
		$args['no_found_rows'] = true;
		$q                     = new WP_Query( $args );
		$ids                   = array_map( 'intval', (array) $q->posts );
		self::mark_many( 'p', $ids );
		if ( count( $ids ) < $batch ) {
			self::set_state( array( 'full' => null ) );
		} else {
			self::set_state( array( 'full' => array( 'phase' => 'products', 'offset' => (int) $f['offset'] + count( $ids ) ) ) );
		}
	}

	/* ================================================================ Lock */

	private static function lock() {
		global $wpdb;
		$t = RKC_DB::t( 'nonces' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE nonce = %s AND expires_at < %d", self::LOCK, time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return RKC_DB::insert_unique( 'nonces', array( 'nonce' => self::LOCK, 'expires_at' => time() + 60 ), array( '%s', '%d' ) );
	}

	/** Called between steps of a run: the lock lasts 60 s from the last sign of life. */
	public static function renew_lock() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . RKC_DB::t( 'nonces' ) . ' SET expires_at = %d WHERE nonce = %s', time() + 60, self::LOCK ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	private static function unlock() {
		global $wpdb;
		$wpdb->delete( RKC_DB::t( 'nonces' ), array( 'nonce' => self::LOCK ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ============================================================= The run */

	public static function cron_tick() {
		self::tick( 'cron' );
	}

	/**
	 * One run of the routine described at the top of this file.
	 *
	 * @param string $mode cron (respects the pace ResellingKori asked for) | wake (the dashboard asked) | manual (a person pressed the button).
	 * @return array Summary: ran, rounds, orders, products, gone, commands, results, error, skipped.
	 */
	public static function tick( $mode = 'cron' ) {
		$out = array( 'ran' => false, 'rounds' => 0, 'orders' => 0, 'products' => 0, 'gone' => 0, 'commands' => 0, 'results' => 0, 'error' => '', 'skipped' => '' );
		if ( ! RKC_Config::connected() ) {
			$out['skipped'] = 'not_connected';
			return $out;
		}
		$s = self::state();
		if ( 'yes' !== RKC_Settings::get( 'workspace_sync' ) ) {
			if ( $s['purge'] && ( 'manual' === $mode || time() >= (int) $s['next_try'] ) ) {
				self::send_purge( 12 );
			}
			$out['skipped'] = 'off';
			return $out;
		}
		if ( 'manual' !== $mode && time() < (int) $s['next_try'] ) {
			// A wake link is honoured when nothing is wrong; it never overrides a back-off.
			if ( ! ( 'wake' === $mode && '' === $s['remote'] && 0 === (int) $s['fails'] ) ) {
				$out['skipped'] = 'waiting';
				return $out;
			}
		}
		if ( ! self::lock() ) {
			$out['skipped'] = 'busy';
			return $out;
		}
		$out['ran'] = true;
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 75 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		$started  = time();
		$deadline = $started + self::BUDGET;
		try {
			for ( $round = 1; $round <= self::MAX_ROUNDS; $round++ ) {
				self::renew_lock();
				self::maintain();
				$built = self::build();
				$res   = RKC_API_Client::workspace_sync( $built['body'] );
				++$out['rounds'];
				if ( is_wp_error( $res ) ) {
					self::failed( $res );
					$out['error'] = (string) $res->get_error_code();
					break;
				}
				$applied = self::apply( $res, $built );
				if ( null === $applied ) {
					$out['error'] = 'rkc_bad_response';
					break;
				}
				$out['orders']   += count( $built['body']['orders'] );
				$out['products'] += count( $built['body']['products'] );
				$out['gone']     += count( $built['body']['order_gone'] ) + count( $built['body']['product_gone'] );
				$out['results']  += count( $built['body']['results'] );
				$ran              = RKC_Workspace_Exec::run_queued( $deadline );
				$out['commands'] += $ran;
				// What an instruction changed (a link made, confirmed or removed) is reported in the very next round of this
				// run - not only after this request has ended - so the dashboard shows the new state together with "done".
				self::flush_units();
				if ( ! self::more_work() || time() >= $deadline ) {
					break;
				}
			}
			if ( self::more_work() ) {
				self::kick_soon();
			}
		} catch ( Throwable $e ) {
			RKC_Logger::error( 'The agent dashboard sync stopped on an unexpected error: ' . RKC_Redact::text( $e->getMessage() ) );
			$out['error'] = 'rkc_exception';
			self::set_state( array( 'last_try' => time(), 'last_code' => 'rkc_exception', 'last_msg' => __( 'The sync stopped on an unexpected error. See the log.', 'resellingkori-connector' ), 'fails' => (int) self::state()['fails'] + 1, 'next_try' => time() + 120 ) );
		} finally {
			self::unlock();
		}
		if ( $out['commands'] ) {
			self::set_state( array( 'executed' => (int) self::state()['executed'] + $out['commands'] ) );
		}
		return $out;
	}

	/** A run that ended with work left over asks for another one right away. */
	private static function kick_soon() {
		if ( ! wp_next_scheduled( 'rkc_ws_kick' ) ) {
			wp_schedule_single_event( time() + 5, 'rkc_ws_kick' );
		}
	}

	/** Start the full run when it is due, and feed it. */
	private static function maintain() {
		$s = self::state();
		if ( (string) $s['key'] !== (string) RKC_Config::get( 'key_id' ) || ! (int) $s['last_full'] || ( time() - (int) $s['last_full'] ) > 7 * DAY_IN_SECONDS ) {
			self::start_full();
		}
		self::step_full();
	}

	private static function more_work() {
		global $wpdb;
		if ( 1 === (int) $wpdb->get_var( 'SELECT 1 FROM ' . RKC_DB::t( 'ws_items' ) . ' WHERE dirty_at > 0 LIMIT 1' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			return true;
		}
		if ( 1 === (int) $wpdb->get_var( 'SELECT 1 FROM ' . RKC_DB::t( 'ws_cmd' ) . " WHERE ( state = 'done' AND reported = 0 ) OR state = 'queued' LIMIT 1" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			return true;
		}
		$f = self::state()['full'];
		return ! empty( $f );
	}

	/**
	 * Collect what is to be reported.
	 *
	 * @return array { body, claims, result_ids }
	 */
	private static function build() {
		global $wpdb;
		$t      = RKC_DB::t( 'ws_items' );
		$claims = array( 'o' => array(), 'p' => array() );
		$body   = array(
			'schema'        => self::PROTOCOL,
			'connector'     => RKC_VERSION,
			'sync'          => true,
			'state'         => self::shop_state(),
			'orders'        => array(),
			'order_gone'    => array(),
			'products'      => array(),
			'product_gone'  => array(),
			'results'       => array(),
			'purge'         => false,
			'want_commands' => true,
		);

		// Orders.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ref, dirty_at, known, want_gone FROM {$t} WHERE kind = 'o' AND dirty_at > 0 ORDER BY dirty_at ASC LIMIT %d", self::MAX_ORDERS * 3 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$ref = (int) $row->ref;
			$ms  = (int) $row->dirty_at;
			if ( (int) $row->want_gone ) {
				if ( count( $body['order_gone'] ) < self::MAX_GONE ) {
					$body['order_gone'][] = (string) $ref;
					$claims['o'][]        = array( 'ref' => $ref, 'ms' => $ms, 'act' => 'gone', 'rev' => '' );
				}
				continue;
			}
			$card = self::safe_card( 'o', $ref );
			if ( false === $card ) {
				$claims['o'][] = array( 'ref' => $ref, 'ms' => $ms, 'act' => 'same', 'rev' => '' ); // Do not retry until the order changes again.
				continue;
			}
			if ( $card ) {
				if ( count( $body['orders'] ) >= self::MAX_ORDERS ) {
					continue; // Next round.
				}
				$body['orders'][] = $card;
				$claims['o'][]    = array( 'ref' => $ref, 'ms' => $ms, 'act' => 'sent', 'rev' => $card['rev'] );
			} elseif ( (int) $row->known ) {
				if ( count( $body['order_gone'] ) < self::MAX_GONE ) {
					$body['order_gone'][] = (string) $ref;
					$claims['o'][]        = array( 'ref' => $ref, 'ms' => $ms, 'act' => 'gone', 'rev' => '' );
				}
			} else {
				$claims['o'][] = array( 'ref' => $ref, 'ms' => $ms, 'act' => 'drop', 'rev' => '' );
			}
		}

		// Products.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ref, dirty_at, known, want_gone, rev FROM {$t} WHERE kind = 'p' AND dirty_at > 0 ORDER BY dirty_at ASC LIMIT %d", self::MAX_PRODUCTS * 2 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$ref  = (int) $row->ref;
			$ms   = (int) $row->dirty_at;
			$card = self::safe_card( 'p', $ref );
			if ( false === $card ) {
				$claims['p'][] = array( 'ref' => $ref, 'ms' => $ms, 'act' => 'same', 'rev' => '' );
				continue;
			}
			if ( $card ) {
				if ( (int) $row->known && (string) $row->rev === $card['rev'] ) {
					$claims['p'][] = array( 'ref' => $ref, 'ms' => $ms, 'act' => 'same', 'rev' => $card['rev'] );
					continue;
				}
				if ( count( $body['products'] ) >= self::MAX_PRODUCTS ) {
					continue;
				}
				$body['products'][] = $card;
				$claims['p'][]      = array( 'ref' => $ref, 'ms' => $ms, 'act' => 'sent', 'rev' => $card['rev'] );
			} elseif ( (int) $row->known ) {
				if ( count( $body['product_gone'] ) < self::MAX_GONE ) {
					$body['product_gone'][] = $ref;
					$claims['p'][]          = array( 'ref' => $ref, 'ms' => $ms, 'act' => 'gone', 'rev' => '' );
				}
			} else {
				$claims['p'][] = array( 'ref' => $ref, 'ms' => $ms, 'act' => 'drop', 'rev' => '' );
			}
		}

		// Results of earlier instructions.
		$result_ids = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT cmd_id, result FROM ' . RKC_DB::t( 'ws_cmd' ) . " WHERE state = 'done' AND reported = 0 ORDER BY seq ASC LIMIT %d", self::MAX_RESULTS ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$res = json_decode( (string) $r->result, true );
			$res = is_array( $res ) ? $res : array( 'ok' => false, 'code' => 'no_result', 'message' => '', 'data' => array() );
			$body['results'][] = array(
				'id'      => (string) $r->cmd_id,
				'ok'      => ! empty( $res['ok'] ),
				'code'    => isset( $res['code'] ) ? (string) $res['code'] : '',
				'message' => isset( $res['message'] ) ? (string) $res['message'] : '',
				'data'    => ( isset( $res['data'] ) && is_array( $res['data'] ) && $res['data'] ) ? $res['data'] : new stdClass(),
			);
			$result_ids[] = (string) $r->cmd_id;
		}

		// Keep the request inside what ResellingKori accepts: cards that do not fit stay dirty for the next round.
		while ( strlen( (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) > self::MAX_BODY && count( $body['orders'] ) > 1 ) {
			$dropped = array_pop( $body['orders'] );
			foreach ( $claims['o'] as $i => $c ) {
				if ( 'sent' === $c['act'] && (string) $c['ref'] === (string) $dropped['ref'] ) {
					unset( $claims['o'][ $i ] );
				}
			}
		}
		while ( strlen( (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) > self::MAX_BODY && count( $body['products'] ) > 1 ) {
			$dropped = array_pop( $body['products'] );
			foreach ( $claims['p'] as $i => $c ) {
				if ( 'sent' === $c['act'] && (int) $c['ref'] === (int) $dropped['woo'] ) {
					unset( $claims['p'][ $i ] );
				}
			}
		}

		// Rows that need no network at all.
		foreach ( $claims as $kind => $list ) {
			foreach ( $list as $i => $c ) {
				if ( 'drop' === $c['act'] ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE kind = %s AND ref = %d AND dirty_at = %d AND known = 0 AND want_gone = 0", $kind, (int) $c['ref'], (int) $c['ms'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					unset( $claims[ $kind ][ $i ] );
				}
			}
		}
		return array( 'body' => $body, 'claims' => $claims, 'result_ids' => $result_ids );
	}

	/**
	 * Build a card without letting one broken order or product (a third-party filter that throws, say) stop the whole run.
	 *
	 * @return array|null|false The card, null when there is none, false when building it failed.
	 */
	private static function safe_card( $kind, $ref ) {
		try {
			if ( 'o' === $kind ) {
				$order = wc_get_order( (int) $ref );
				return ( $order instanceof WC_Order ) ? self::order_card( $order ) : null;
			}
			return self::product_card( (int) $ref );
		} catch ( Throwable $e ) {
			RKC_Logger::warning( 'A card for the agent dashboard could not be built: ' . RKC_Redact::text( $e->getMessage() ), array( 'kind' => $kind, 'ref' => (int) $ref ) );
			return false;
		}
	}

	/** What the shop says about itself (support and readiness; no personal data). */
	private static function shop_state() {
		$c = RKC_Config::get();
		return array(
			'connected'   => (bool) RKC_Config::connected(),
			'agent_valid' => $c['agent_valid'],
			'agent_match' => $c['agent_match'],
			'hpos'        => (bool) RKC_Connect::hpos_enabled(),
			'currency'    => (string) get_woocommerce_currency(),
			'wc'          => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'wp'          => (string) get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'actions'     => (bool) self::actions_allowed(),
		);
	}

	/**
	 * Apply a signed, successful answer.
	 *
	 * @return array|null null when the answer is not what this version understands.
	 */
	private static function apply( array $res, array $built ) {
		global $wpdb;
		if ( empty( $res['ok'] ) || ! isset( $res['sync'] ) || ! is_array( $res['sync'] ) ) {
			self::failed( new WP_Error( 'rkc_bad_response', __( 'ResellingKori returned a response this plugin cannot read. Update the plugin or contact ResellingKori.', 'resellingkori-connector' ), array( 'status' => 200, 'transient' => false ) ) );
			return null;
		}
		$t = RKC_DB::t( 'ws_items' );

		// A 200 means the whole request was processed: what it carried is settled.
		foreach ( $built['claims'] as $kind => $list ) {
			foreach ( $list as $c ) {
				$keep = 'CASE WHEN dirty_at = %d THEN 0 ELSE dirty_at END';
				switch ( $c['act'] ) {
					case 'sent':
						$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET known = 1, rev = %s, dirty_at = {$keep} WHERE kind = %s AND ref = %d", $c['rev'], (int) $c['ms'], $kind, (int) $c['ref'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
						break;
					case 'same':
						$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET dirty_at = {$keep} WHERE kind = %s AND ref = %d", (int) $c['ms'], $kind, (int) $c['ref'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
						break;
					case 'gone':
						$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET known = 0, want_gone = 0, rev = '', dirty_at = {$keep} WHERE kind = %s AND ref = %d", (int) $c['ms'], $kind, (int) $c['ref'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
						break;
				}
			}
		}
		$wpdb->query( "DELETE FROM {$t} WHERE dirty_at = 0 AND known = 0 AND want_gone = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( $built['result_ids'] ) {
			$in = implode( ',', array_fill( 0, count( $built['result_ids'] ), '%s' ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . RKC_DB::t( 'ws_cmd' ) . " SET reported = 1 WHERE cmd_id IN ({$in})", $built['result_ids'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		$sync   = $res['sync'];
		$now    = time();
		$skew   = isset( $res['server_time'] ) ? (int) $res['server_time'] - $now : 0;
		$next   = isset( $sync['next_in'] ) ? (int) $sync['next_in'] : 60;
		$patch  = array(
			'last_ok'   => $now,
			'last_try'  => $now,
			'last_code' => '',
			'last_msg'  => '',
			'fails'     => 0,
			'remote'    => '',
			'skew'      => ( abs( $skew ) <= DAY_IN_SECONDS ) ? $skew : 0,
			'next_try'  => $now + max( 5, min( 45, $next ) ),
			'counts'    => ( isset( $res['accepted'] ) && is_array( $res['accepted'] ) ) ? array_map( 'intval', array_slice( $res['accepted'], 0, 12 ) ) : array(),
		);
		if ( isset( $sync['enabled'] ) && ! $sync['enabled'] ) {
			$patch['remote']   = 'off';
			$patch['next_try'] = $now + 1800;
		}
		self::set_state( $patch );

		if ( ! empty( $sync['request_full'] ) ) {
			// ResellingKori asks for everything on a store's very first sync. A full run that is already under way delivers it
			// anyway; starting another one would only send every order and product twice.
			$cur = self::state();
			if ( empty( $cur['full'] ) ) {
				self::start_full();
			}
		}
		foreach ( array_slice( ( isset( $sync['verify'] ) && is_array( $sync['verify'] ) ) ? $sync['verify'] : array(), 0, 10 ) as $ref ) {
			if ( is_scalar( $ref ) && preg_match( '/^\d{1,12}$/', (string) $ref ) ) {
				self::mark( 'o', (int) $ref, true );
			}
		}
		if ( ! empty( $res['commands'] ) && is_array( $res['commands'] ) ) {
			RKC_Workspace_Exec::intake( $res['commands'] );
		}
		return $patch;
	}

	/** A failed call: what to tell the owner, and when to try again. */
	private static function failed( WP_Error $e ) {
		$d      = (array) $e->get_error_data();
		$http   = isset( $d['status'] ) ? (int) $d['status'] : 0;
		$api    = isset( $d['api_code'] ) ? (string) $d['api_code'] : '';
		$s      = self::state();
		$fails  = (int) $s['fails'] + 1;
		$remote = '';
		$now    = time();
		if ( 404 === $http || 'rest_no_route' === $api ) {
			$remote = 'unavailable';
			$wait   = HOUR_IN_SECONDS;
		} elseif ( 'rkw_workspace_off' === $api ) {
			$remote = 'off';
			$wait   = 30 * MINUTE_IN_SECONDS;
		} elseif ( 'rkw_schema_unsupported' === $api || 426 === $http ) {
			$remote = 'old';
			$wait   = 6 * HOUR_IN_SECONDS;
		} elseif ( ! empty( $d['transient'] ) ) {
			$wait = min( 900, 30 * ( 1 << min( 5, $fails - 1 ) ) );
			if ( ! empty( $d['retry_after'] ) ) {
				$wait = max( $wait, min( 900, (int) $d['retry_after'] ) );
			}
		} else {
			$wait = 900; // Credentials, permissions, unreadable answers.
		}
		$code = '' !== $api ? $api : (string) $e->get_error_code();
		self::set_state(
			array(
				'last_try'  => $now,
				'last_code' => $code,
				'last_msg'  => substr( (string) $e->get_error_message(), 0, 300 ),
				'fails'     => $fails,
				'remote'    => $remote,
				'next_try'  => $now + $wait,
			)
		);
		if ( 1 === $fails || $remote !== (string) $s['remote'] ) {
			RKC_Logger::warning( 'Agent dashboard sync failed: ' . $code, array( 'http' => $http, 'retry_in' => $wait ) );
		}
	}

	/** Tell ResellingKori to delete what it holds for this shop. */
	private static function send_purge( $timeout ) {
		$body = array( 'schema' => self::PROTOCOL, 'connector' => RKC_VERSION, 'sync' => false, 'purge' => true, 'state' => self::shop_state(), 'want_commands' => false );
		$res  = RKC_API_Client::request( 'POST', '/rk/v1/workspace/sync', array(), $body, (int) $timeout, true );
		if ( is_wp_error( $res ) ) {
			$d = (array) $res->get_error_data();
			// Nothing to delete where the feature does not exist.
			if ( 404 === ( isset( $d['status'] ) ? (int) $d['status'] : 0 ) ) {
				self::set_state( array( 'purge' => false ) );
				return true;
			}
			self::set_state( array( 'next_try' => time() + 300, 'last_code' => (string) $res->get_error_code() ) );
			return false;
		}
		self::set_state( array( 'purge' => false, 'last_ok' => 0, 'next_try' => 0 ) );
		RKC_Logger::info( 'The data shown in the ResellingKori agent dashboard was removed at ResellingKori.' );
		return true;
	}

	/* ================================================================ Wake */

	public static function routes() {
		register_rest_route(
			'rkc/v1',
			'/wake',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'wake' ),
				'permission_callback' => '__return_true', // The signed token in the address is the credential.
			)
		);
	}

	private static function wake_deny() {
		$k = 'rkc_wkf_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		set_transient( $k, (int) get_transient( $k ) + 1, 10 * MINUTE_IN_SECONDS );
		return new WP_REST_Response( array( 'ok' => false ), 403 );
	}

	/**
	 * "Check for instructions now." The address is built by ResellingKori from this shop's verified webhook address and
	 * carries a short-lived HMAC (RKC_Signer::wake_signature). It can do nothing but start the routine above, which is rate
	 * limited, so a leaked or replayed link is harmless.
	 */
	public static function wake( WP_REST_Request $request ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( (int) get_transient( 'rkc_wkf_' . md5( $ip ) ) >= 30 ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}
		$ts     = (string) $request->get_param( 'ts' );
		$sig    = strtolower( (string) $request->get_param( 'sig' ) );
		$uid    = (string) RKC_Config::get( 'store_uid' );
		$secret = RKC_Config::secret();
		if ( ! self::enabled() || '' === $uid || false === $secret || ! ctype_digit( $ts ) || ! preg_match( '/^[a-f0-9]{64}$/', $sig ) ) {
			return self::wake_deny();
		}
		$s = self::state();
		if ( abs( time() + (int) $s['skew'] - (int) $ts ) > 120 || ! hash_equals( RKC_Signer::wake_signature( $secret, $uid, (int) $ts ), $sig ) ) {
			return self::wake_deny();
		}
		if ( get_transient( 'rkc_ws_wake' ) ) {
			// A run was started a moment ago and may have missed this instruction. Do not run twice at once, but make sure
			// somebody looks again in a few seconds (the dashboard page also calls once more with a fresh link).
			self::kick_soon();
			return new WP_REST_Response( array( 'ok' => true, 'run' => false ), 202 );
		}
		set_transient( 'rkc_ws_wake', 1, 4 );
		add_action( 'shutdown', array( __CLASS__, 'wake_run' ), 0 );
		return new WP_REST_Response( array( 'ok' => true, 'run' => true ), 202 );
	}

	/** After the answer has gone out: let the browser go, then do the work. */
	public static function wake_run() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		self::tick( 'wake' );
	}

	/* ========================================================== Housekeeping */

	public static function housekeeping() {
		global $wpdb;
		$c = RKC_DB::t( 'ws_cmd' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$c} WHERE state = 'done' AND reported = 1 AND finished_at < %s", gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$c} WHERE state = 'running' AND started_at < %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$c} WHERE state = 'queued' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . RKC_DB::t( 'ws_items' ) . ' WHERE dirty_at = 0 AND known = 0 AND want_gone = 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/* =============================================================== Status */

	/**
	 * What the owner should see about the sync.
	 *
	 * @return array { kind: ok|warn|bad|off, text, last_ok, ... }
	 */
	public static function summary() {
		global $wpdb;
		$s       = self::state();
		$setting = (string) RKC_Settings::get( 'workspace_sync' );
		$t       = RKC_DB::t( 'ws_items' );
		$out     = array(
			'setting'  => $setting,
			'enabled'  => self::enabled(),
			'actions'  => self::actions_allowed(),
			'last_ok'  => (int) $s['last_ok'],
			'last_try' => (int) $s['last_try'],
			'remote'   => (string) $s['remote'],
			'orders'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE kind = 'o' AND known = 1" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			'products' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE kind = 'p' AND known = 1" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			'waiting'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE dirty_at > 0" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			'running'  => ! empty( $s['full'] ),
			'executed' => (int) $s['executed'],
			'code'     => (string) $s['last_code'],
		);
		if ( 'yes' !== $setting ) {
			$out['kind'] = 'off';
			$out['text'] = ( '' === $setting )
				? __( 'Not switched on yet. Nothing is sent to ResellingKori until you switch it on.', 'resellingkori-connector' )
				: __( 'Switched off. Nothing is sent to ResellingKori.', 'resellingkori-connector' );
			return $out;
		}
		if ( ! RKC_Config::connected() ) {
			$out['kind'] = 'warn';
			$out['text'] = __( 'Waiting for the connection to ResellingKori to be verified.', 'resellingkori-connector' );
			return $out;
		}
		if ( 'unavailable' === $s['remote'] ) {
			$out['kind'] = 'warn';
			$out['text'] = __( 'ResellingKori has not switched the agent dashboard on for stores yet. Nothing is shown there until it does; this plugin keeps checking.', 'resellingkori-connector' );
		} elseif ( 'off' === $s['remote'] ) {
			$out['kind'] = 'warn';
			$out['text'] = __( 'The agent dashboard is switched off at ResellingKori. This plugin keeps checking.', 'resellingkori-connector' );
		} elseif ( 'old' === $s['remote'] ) {
			$out['kind'] = 'warn';
			$out['text'] = __( 'ResellingKori needs to update before this version of the plugin can sync. This plugin keeps checking.', 'resellingkori-connector' );
		} elseif ( (int) $s['fails'] > 0 ) {
			$out['kind'] = 'bad';
			$out['text'] = '' !== $s['last_msg'] ? (string) $s['last_msg'] : __( 'The last sync did not work. This plugin tries again automatically.', 'resellingkori-connector' );
		} elseif ( (int) $s['last_ok'] > 0 ) {
			$out['kind'] = 'ok';
			/* translators: %s: how long ago, e.g. "2 minutes" */
			$out['text'] = sprintf( __( 'Working. Last synced %s ago.', 'resellingkori-connector' ), human_time_diff( (int) $s['last_ok'], time() ) );
		} else {
			$out['kind'] = 'warn';
			$out['text'] = __( 'Switched on. The first sync happens within a minute.', 'resellingkori-connector' );
		}
		return $out;
	}
}
