<?php
/**
 * Stock monitor: when a linked product runs out at ResellingKori, it goes out of stock in this shop too - and comes back
 * when ResellingKori has it again. Per product (and per variation), only where the owner or the agent ticked it.
 *
 *   _rkc_stock_monitor  yes            the unit is monitored
 *   _rkc_stock_auto_out 1              THIS plugin made the unit unavailable (nothing else ever sets it)
 *   _rkc_stock_prev     instock | ...  the shop's own stock status before that, restored afterwards
 *   _rkc_rk_out_since   unix time      when ResellingKori was first seen out of stock (any linked unit, monitored or not)
 *
 * How the unit is made unavailable:
 *   - stock not managed by quantity: its stock status is set to "Out of stock" (and restored later). This is what shop
 *     filters, "hide out of stock" and the catalogue lookup tables see.
 *   - stock managed by quantity: the quantity is never touched (it belongs to the owner). The unit is reported as out of
 *     stock while the marker is set (is_in_stock / get_stock_status filters), so it cannot be bought.
 *
 * Availability comes from ResellingKori's API (RKC_Mapping::refresh_availability), never from what the dashboard says: the
 * dashboard's hint ("look at these now") only decides WHEN this shop asks, not WHAT it concludes.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Stock {

	const META_MONITOR   = '_rkc_stock_monitor';
	const META_AUTO      = '_rkc_stock_auto_out';
	const META_PREV      = '_rkc_stock_prev';
	const META_OUT_SINCE = '_rkc_rk_out_since';

	const BATCH   = 50;  // Units per availability call (the API's SKU lookup takes 50).
	const PER_RUN = 300; // Units the 15 minute check looks at per run.
	const BUDGET  = 20;  // Seconds one check may spend.

	/** @var bool Re-entrancy guard: our own save must not run apply() again. */
	private static $saving = false;

	public static function init() {
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'filter_in_stock' ), 50, 2 );
		add_filter( 'woocommerce_product_get_stock_status', array( __CLASS__, 'filter_status' ), 50, 2 );
		add_filter( 'woocommerce_product_variation_get_stock_status', array( __CLASS__, 'filter_status' ), 50, 2 );
		add_action( 'rkc_stock_check', array( __CLASS__, 'cron_check' ) );

		if ( is_admin() ) {
			add_action( 'woocommerce_product_options_stock_status', array( __CLASS__, 'field_simple' ) );
			add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple' ) );
			add_action( 'woocommerce_variation_options_inventory', array( __CLASS__, 'field_variation' ), 20, 3 );
			add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 20, 2 );
		}
	}

	/* =============================================================== State */

	public static function monitored( $woo_id ) {
		return 'yes' === get_post_meta( (int) $woo_id, self::META_MONITOR, true );
	}

	public static function auto_out( $woo_id ) {
		return (bool) get_post_meta( (int) $woo_id, self::META_AUTO, true );
	}

	/**
	 * What the dashboard's product card shows about stock.
	 *
	 * @return array { mon: bool, auto: bool, shop: instock|outofstock|onbackorder, managed: bool, since: int }
	 */
	public static function card( WC_Product $p ) {
		$id = (int) $p->get_id();
		$st = (string) $p->get_stock_status( 'edit' );
		return array(
			'mon'     => self::monitored( $id ),
			'auto'    => self::auto_out( $id ),
			'shop'    => in_array( $st, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $st : 'instock',
			'managed' => (bool) $p->managing_stock(),
			'since'   => (int) get_post_meta( $id, self::META_OUT_SINCE, true ),
		);
	}

	/** ResellingKori's availability of a unit changed (called by RKC_Mapping after every check). */
	public static function availability_changed( $woo_id, $old, $new ) {
		$woo_id = (int) $woo_id;
		$out    = in_array( $new, array( 'out', 'gone' ), true );
		if ( $out && ! in_array( $old, array( 'out', 'gone' ), true ) ) {
			update_post_meta( $woo_id, self::META_OUT_SINCE, time() );
		} elseif ( ! $out && 'in' === $new ) {
			delete_post_meta( $woo_id, self::META_OUT_SINCE );
		}
	}

	/**
	 * Switch the monitor on or off for one unit and apply the result at once.
	 *
	 * @return string|null Result of apply(): out | in | null (nothing changed).
	 */
	public static function set_monitor( $woo_id, $on ) {
		$woo_id = (int) $woo_id;
		if ( $on ) {
			update_post_meta( $woo_id, self::META_MONITOR, 'yes' );
		} else {
			delete_post_meta( $woo_id, self::META_MONITOR );
		}
		return self::apply( $woo_id );
	}

	/**
	 * Bring the unit's availability in line with ResellingKori's (as last checked) and the monitor switch.
	 *
	 * @return string|null out (made unavailable) | in (given back) | null (nothing to do)
	 */
	public static function apply( $woo_id ) {
		if ( self::$saving ) {
			return null;
		}
		$woo_id = (int) $woo_id;
		$p      = wc_get_product( $woo_id );
		if ( ! $p || $p->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
			return null;
		}
		$map      = RKC_Mapping::get( $woo_id );
		$want_out = $map && self::monitored( $woo_id ) && in_array( $map['avail'], array( 'out', 'gone' ), true );
		$auto     = self::auto_out( $woo_id );

		if ( $want_out && ! $auto ) {
			$prev = (string) $p->get_stock_status( 'edit' );
			update_post_meta( $woo_id, self::META_PREV, '' !== $prev ? $prev : 'instock' );
			update_post_meta( $woo_id, self::META_AUTO, 1 );
			if ( ! $p->managing_stock() && 'outofstock' !== $prev ) {
				self::save_status( $p, 'outofstock' );
			} else {
				self::touch( $p );
			}
			RKC_Logger::info( 'Stock monitor: product made unavailable because it is out of stock at ResellingKori.', array( 'product' => $woo_id ) );
			return 'out';
		}
		if ( ! $want_out && $auto ) {
			$prev = (string) get_post_meta( $woo_id, self::META_PREV, true );
			$prev = in_array( $prev, array( 'instock', 'onbackorder' ), true ) ? $prev : 'instock';
			delete_post_meta( $woo_id, self::META_AUTO );
			delete_post_meta( $woo_id, self::META_PREV );
			if ( ! $p->managing_stock() && 'outofstock' === (string) $p->get_stock_status( 'edit' ) ) {
				self::save_status( $p, $prev );
			} else {
				self::touch( $p );
			}
			RKC_Logger::info( 'Stock monitor: product available again.', array( 'product' => $woo_id ) );
			return 'in';
		}
		return null;
	}

	private static function save_status( WC_Product $p, $status ) {
		self::$saving = true;
		try {
			$p->set_stock_status( $status );
			$p->save();
		} finally {
			self::$saving = false;
		}
	}

	/** Managed stock: nothing is saved, but caches and the dashboard card must notice the marker. */
	private static function touch( WC_Product $p ) {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $p->is_type( 'variation' ) ? $p->get_parent_id() : $p->get_id() );
		}
		if ( class_exists( 'RKC_Workspace' ) ) {
			RKC_Workspace::mark( 'p', (int) $p->get_id() );
		}
	}

	/* ============================================================= Filters */

	public static function filter_in_stock( $in_stock, $product ) {
		if ( $in_stock && $product instanceof WC_Product && $product->get_meta( self::META_AUTO, true, 'edit' ) ) {
			return false;
		}
		return $in_stock;
	}

	public static function filter_status( $status, $product ) {
		if ( 'outofstock' !== $status && $product instanceof WC_Product && $product->get_meta( self::META_AUTO, true, 'edit' ) ) {
			return 'outofstock';
		}
		return $status;
	}

	/* ============================================================== Checks */

	/** @return int[] Monitored, linked units, the least recently checked first. */
	public static function due_units( $limit ) {
		$q = new WP_Query(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => (int) $limit,
				'no_found_rows'  => true,
				'meta_key'       => RKC_Mapping::META_CHECKED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array( 'key' => self::META_MONITOR, 'value' => 'yes' ),
					array( 'key' => RKC_Mapping::META_PRODUCT, 'compare' => 'EXISTS' ),
				),
			)
		);
		return array_map( 'intval', (array) $q->posts );
	}

	/** Every 15 minutes: re-read ResellingKori's availability of the monitored units. */
	public static function cron_check() {
		if ( ! RKC_Config::connected() || get_transient( 'rkc_stock_lock' ) ) {
			return;
		}
		set_transient( 'rkc_stock_lock', 1, 2 * MINUTE_IN_SECONDS );
		try {
			self::check( self::due_units( self::PER_RUN ), time() + self::BUDGET );
		} finally {
			delete_transient( 'rkc_stock_lock' );
		}
	}

	/**
	 * ResellingKori's dashboard says the stock of these units changed: check them now (only the monitored, linked ones).
	 *
	 * @param int[] $woo_ids  Unit ids from the signed sync answer.
	 * @param int   $deadline Unix time.
	 */
	public static function check_now( array $woo_ids, $deadline ) {
		$ids = array();
		foreach ( array_slice( array_map( 'intval', $woo_ids ), 0, 100 ) as $id ) {
			if ( $id > 0 && self::monitored( $id ) && RKC_Mapping::get( $id ) ) {
				$ids[] = $id;
			}
		}
		return $ids ? self::check( $ids, $deadline ) : array( 'checked' => 0, 'unavailable' => 0 );
	}

	/** @return array { checked, unavailable } */
	public static function check( array $ids, $deadline ) {
		$out = array( 'checked' => 0, 'unavailable' => 0 );
		foreach ( array_chunk( $ids, self::BATCH ) as $chunk ) {
			if ( time() >= (int) $deadline ) {
				break;
			}
			$res = RKC_Mapping::refresh_availability( $chunk );
			if ( is_wp_error( $res ) ) {
				RKC_Logger::warning( 'Stock monitor: ResellingKori could not be asked about stock: ' . $res->get_error_code() );
				break;
			}
			$out['checked']     += (int) $res['checked'];
			$out['unavailable'] += (int) $res['unavailable'];
		}
		return $out;
	}

	/* =========================================================== Admin UI */

	private static function checkbox( $id, $name, $checked, $wrapper ) {
		$map = RKC_Mapping::get( $id );
		if ( ! $map ) {
			return;
		}
		$label = __( 'ResellingKori stock monitor', 'resellingkori-connector' );
		$help  = __( 'When this product is out of stock at ResellingKori, make it out of stock in this shop too (and available again when ResellingKori has it).', 'resellingkori-connector' );
		if ( 'variation' === $wrapper ) {
			echo '<label class="tips" data-tip="' . esc_attr( $help ) . '"><input type="checkbox" class="checkbox" name="' . esc_attr( $name ) . '" value="yes"' . checked( $checked, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
			return;
		}
		woocommerce_wp_checkbox(
			array(
				'id'          => $name,
				'label'       => $label,
				'description' => $help,
				'value'       => $checked ? 'yes' : 'no',
				'cbvalue'     => 'yes',
			)
		);
		wp_nonce_field( 'rkc_stock_monitor', 'rkc_stock_monitor_nonce' );
	}

	public static function field_simple() {
		global $product_object;
		if ( $product_object instanceof WC_Product && $product_object->is_type( 'simple' ) && current_user_can( 'rkc_manage_mapping' ) ) {
			self::checkbox( (int) $product_object->get_id(), 'rkc_stock_monitor', self::monitored( $product_object->get_id() ), 'simple' );
		}
	}

	public static function save_simple( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'simple' ) || ! current_user_can( 'rkc_manage_mapping' ) || ! RKC_Mapping::get( $product->get_id() ) ) {
			return;
		}
		if ( ! isset( $_POST['rkc_stock_monitor_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rkc_stock_monitor_nonce'] ) ), 'rkc_stock_monitor' ) ) {
			return;
		}
		$on = isset( $_POST['rkc_stock_monitor'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['rkc_stock_monitor'] ) );
		if ( $on !== self::monitored( $product->get_id() ) ) {
			// Applied after WooCommerce saved the product, so the owner's own stock change in the same save is respected.
			$id = (int) $product->get_id();
			add_action(
				'woocommerce_update_product',
				static function ( $saved_id ) use ( $id, $on ) {
					if ( (int) $saved_id === $id ) {
						RKC_Stock::set_monitor( $id, $on );
					}
				},
				99
			);
		}
	}

	public static function field_variation( $loop, $variation_data, $variation ) {
		if ( ! current_user_can( 'rkc_manage_mapping' ) || ! isset( $variation->ID ) ) {
			return;
		}
		self::checkbox( (int) $variation->ID, 'rkc_stock_monitor_v[' . (int) $loop . ']', self::monitored( $variation->ID ), 'variation' );
	}

	public static function save_variation( $variation_id, $loop ) {
		// WooCommerce checks the nonce of the variations request (save-variations) before this action runs.
		if ( ! current_user_can( 'rkc_manage_mapping' ) || ! RKC_Mapping::get( $variation_id ) ) {
			return;
		}
		$on = isset( $_POST['rkc_stock_monitor_v'][ $loop ] ) && 'yes' === sanitize_key( wp_unslash( $_POST['rkc_stock_monitor_v'][ $loop ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $on !== self::monitored( $variation_id ) ) {
			self::set_monitor( (int) $variation_id, $on );
		}
	}
}
