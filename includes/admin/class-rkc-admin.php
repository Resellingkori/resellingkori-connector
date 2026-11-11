<?php
/**
 * Admin screens: WooCommerce > ResellingKori (Product Mapping + Settings).
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Admin {

	const PAGE = 'rkc';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RKC_FILE ), array( __CLASS__, 'action_links' ) );

		// "RK" column on the Products list.
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'product_column' ) );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'product_column_render' ), 10, 2 );

		foreach ( array( 'match', 'lookup', 'link', 'unlink', 'bulk_sku', 'test' ) as $action ) {
			add_action( 'wp_ajax_rkc_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	/* ----------------------------------------------------------- Navigation */

	public static function page_url( $tab = 'products', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/** Link that opens the mapping modal for one store product. */
	public static function map_url( $woo_id ) {
		return self::page_url(
			'products',
			array(
				'filter'   => 'unmapped',
				'rkc_open' => (int) $woo_id,
			)
		);
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'ResellingKori Fulfillment', 'resellingkori-connector' ),
			__( 'ResellingKori', 'resellingkori-connector' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'resellingkori-connector' ) . '</a>' );
		return $links;
	}

	/* --------------------------------------------------------------- Assets */

	public static function enqueue( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$sid    = $screen ? $screen->id : '';
		$ours   = ( 'woocommerce_page_' . self::PAGE === $hook );
		$other  = in_array( $sid, array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders', 'edit-product' ), true );
		if ( ! $ours && ! $other ) {
			return;
		}

		wp_enqueue_style( 'rkc-admin', RKC_URL . 'assets/css/admin.css', array(), RKC_VERSION );
		wp_enqueue_script( 'rkc-admin', RKC_URL . 'assets/js/admin.js', array(), RKC_VERSION, true );
		wp_localize_script(
			'rkc-admin',
			'RKC',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rkc_admin' ),
				'i18n'    => self::js_strings(),
			)
		);
	}

	private static function js_strings() {
		return array(
			'loading'        => __( 'Looking on ResellingKori…', 'resellingkori-connector' ),
			'close'          => __( 'Close', 'resellingkori-connector' ),
			'matchTitle'     => __( 'Match with a ResellingKori product', 'resellingkori-connector' ),
			'noSku'          => __( 'No SKU', 'resellingkori-connector' ),
			'optSku'         => __( 'Match by SKU', 'resellingkori-connector' ),
			'optKeywords'    => __( 'Suggestions by product name keywords', 'resellingkori-connector' ),
			'optManual'      => __( 'Enter a ResellingKori product ID or SKU', 'resellingkori-connector' ),
			'skuNoSku'       => __( 'This product has no SKU, so exact matching is skipped. Use the keyword suggestions below.', 'resellingkori-connector' ),
			'skuNone'        => __( 'No ResellingKori product has this SKU:', 'resellingkori-connector' ),
			'skuExact'       => __( 'Exact SKU match found.', 'resellingkori-connector' ),
			'skuReview'      => __( 'The SKU matches, but the names look different. Check this carefully before linking.', 'resellingkori-connector' ),
			'link'           => __( 'Link this product', 'resellingkori-connector' ),
			'select'         => __( 'Select', 'resellingkori-connector' ),
			'pickVariation'  => __( 'Choose the matching variation:', 'resellingkori-connector' ),
			'linking'        => __( 'Linking…', 'resellingkori-connector' ),
			'keywords'       => __( 'Keywords', 'resellingkori-connector' ),
			'search'         => __( 'Search', 'resellingkori-connector' ),
			'noResults'      => __( 'No ResellingKori products found. Try fewer or different keywords.', 'resellingkori-connector' ),
			'manualHint'     => __( 'Paste the product ID from ResellingKori, or its SKU.', 'resellingkori-connector' ),
			'find'           => __( 'Find', 'resellingkori-connector' ),
			'confHigh'       => __( 'Likely match', 'resellingkori-connector' ),
			'confMedium'     => __( 'Possible match', 'resellingkori-connector' ),
			'confLow'        => __( 'Weak match', 'resellingkori-connector' ),
			'inStock'        => __( 'In stock', 'resellingkori-connector' ),
			'outOfStock'     => __( 'Out of stock', 'resellingkori-connector' ),
			'notInRk'        => __( 'Not in RK title:', 'resellingkori-connector' ),
			'onlyOnRk'       => __( 'Only in RK title:', 'resellingkori-connector' ),
			'network'        => __( 'The request failed. Please try again.', 'resellingkori-connector' ),
			'bulkRunning'    => __( 'Matching by SKU…', 'resellingkori-connector' ),
			'bulkDone'       => __( 'Done. Linked: %1$d · Need review: %2$d · No SKU match: %3$d', 'resellingkori-connector' ),
			'confirmUnmap'   => __( 'Remove this mapping?', 'resellingkori-connector' ),
			'testing'        => __( 'Testing…', 'resellingkori-connector' ),
			'sending'        => __( 'Sending…', 'resellingkori-connector' ),
			'refreshing'     => __( 'Refreshing…', 'resellingkori-connector' ),
		);
	}

	/* ----------------------------------------------------------------- Page */

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'products'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'products', 'settings' ), true ) ) {
			$tab = 'products';
		}

		echo '<div class="wrap rkc-wrap">';
		echo '<h1>' . esc_html__( 'ResellingKori Fulfillment', 'resellingkori-connector' ) . '</h1>';
		settings_errors();
		self::connection_notice();

		echo '<nav class="nav-tab-wrapper">';
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( self::page_url( 'products' ) ),
			'products' === $tab ? 'nav-tab-active' : '',
			esc_html__( 'Product Mapping', 'resellingkori-connector' )
		);
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( self::page_url( 'settings' ) ),
			'settings' === $tab ? 'nav-tab-active' : '',
			esc_html__( 'Settings', 'resellingkori-connector' )
		);
		echo '</nav>';

		if ( 'settings' === $tab ) {
			RKC_Settings::render();
		} else {
			self::render_products();
		}
		echo '</div>';
	}

	private static function connection_notice() {
		if ( ! RKC_API_Client::configured() ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: settings link */
				esc_html__( 'Not connected yet. Add your ResellingKori API key and secret in %s.', 'resellingkori-connector' ),
				'<a href="' . esc_url( self::page_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'resellingkori-connector' ) . '</a>'
			);
			echo '</p></div>';
			return;
		}
		$conn = get_transient( 'rkc_conn' );
		if ( is_array( $conn ) && empty( $conn['ok'] ) && ! empty( $conn['message'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( /* translators: %s: error */ __( 'Last connection test failed: %s', 'resellingkori-connector' ), $conn['message'] ) ) . '</p></div>';
		}
	}

	private static function render_products() {
		require_once RKC_DIR . 'includes/admin/class-rkc-mapping-table.php';

		$table = new RKC_Mapping_Table();
		$table->prepare_items();
		$filter = $table->current_filter();
		?>
		<div class="rkc-intro">
			<p>
				<strong><?php esc_html_e( 'How matching works', 'resellingkori-connector' ); ?></strong><br />
				<?php esc_html_e( '1) Exact SKU match, the most reliable. 2) If there is no SKU match, suggestions are found by searching ResellingKori with keywords from your product name; you always confirm them. 3) Or enter a ResellingKori product ID or SKU yourself.', 'resellingkori-connector' ); ?>
			</p>
			<p class="rkc-toolbar">
				<button type="button" class="button button-primary" id="rkc-bulk-sku"><?php esc_html_e( 'Auto-match all by SKU', 'resellingkori-connector' ); ?></button>
				<span id="rkc-bulk-status" class="rkc-muted"></span>
			</p>
		</div>

		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="hidden" name="tab" value="products" />
			<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>" />
			<?php
			$table->views();
			$table->search_box( __( 'Search by name or SKU', 'resellingkori-connector' ), 'rkc-product' );
			$table->display();
			?>
		</form>
		<?php
	}

	/* ------------------------------------------------------- Products column */

	public static function product_column( $columns ) {
		$columns['rkc'] = __( 'RK', 'resellingkori-connector' );
		return $columns;
	}

	public static function product_column_render( $column, $post_id ) {
		if ( 'rkc' !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
		if ( $product->is_type( 'variable' ) ) {
			$children = $product->get_children();
			$mapped   = 0;
			foreach ( $children as $child_id ) {
				if ( RKC_Mapping::get( $child_id ) ) {
					++$mapped;
				}
			}
			printf(
				'<span class="rkc-badge %s">%d/%d</span>',
				( $children && count( $children ) === $mapped ) ? 'rkc-high' : 'rkc-low',
				(int) $mapped,
				count( $children )
			);
		} elseif ( RKC_Mapping::get( $post_id ) ) {
			echo '<span class="rkc-badge rkc-high" title="' . esc_attr__( 'Mapped to ResellingKori', 'resellingkori-connector' ) . '">&check;</span>';
		} else {
			echo '<span class="rkc-muted">&ndash;</span>';
		}
	}

	/* ----------------------------------------------------------------- AJAX */

	private static function guard() {
		check_ajax_referer( 'rkc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'resellingkori-connector' ) ) );
		}
	}

	/**
	 * Matching modal data: Option 1 (SKU) and Option 2 (keyword suggestions).
	 */
	public static function ajax_match() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$id    = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$parts = isset( $_POST['parts'] ) ? sanitize_key( wp_unslash( $_POST['parts'] ) ) : 'all';
		$kw    = isset( $_POST['keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['keywords'] ) ) : '';
		// phpcs:enable

		$product = $id ? wc_get_product( $id ) : false;
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'resellingkori-connector' ) ) );
		}

		$data = array( 'product' => RKC_Mapping::describe( $product ) );

		if ( 'keywords' !== $parts ) {
			$sku = RKC_Matcher::sku_option( $product );
			if ( is_wp_error( $sku ) ) {
				wp_send_json_error( array( 'message' => $sku->get_error_message() ) );
			}
			$data['sku'] = $sku;
		}

		$sug = RKC_Matcher::suggest( RKC_Mapping::base_name( $product ), $kw );
		if ( is_wp_error( $sug ) ) {
			wp_send_json_error( array( 'message' => $sug->get_error_message() ) );
		}
		$data['keywords']    = $sug['keywords'];
		$data['suggestions'] = $sug['items'];

		wp_send_json_success( $data );
	}

	/**
	 * Option 3: manual lookup by ResellingKori product ID or SKU.
	 */
	public static function ajax_lookup() {
		self::guard();
		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $term ) {
			wp_send_json_error( array( 'message' => __( 'Enter a ResellingKori product ID or SKU.', 'resellingkori-connector' ) ) );
		}

		$items = array();
		if ( ctype_digit( $term ) ) {
			$res = RKC_API_Client::get_product( (int) $term );
			if ( ! is_wp_error( $res ) && isset( $res['item'] ) && is_array( $res['item'] ) ) {
				$items[] = $res['item'];
			} elseif ( is_wp_error( $res ) && 404 !== (int) ( $res->get_error_data()['status'] ?? 0 ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}
		}
		if ( ! $items ) {
			$res = RKC_API_Client::products_by_sku( array( $term ) );
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}
			if ( isset( $res['matches'][ $term ] ) && is_array( $res['matches'][ $term ] ) ) {
				$items[] = $res['matches'][ $term ];
			}
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	public static function ajax_link() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$rk_pid = isset( $_POST['rk_product_id'] ) ? absint( $_POST['rk_product_id'] ) : 0;
		$rk_vid = isset( $_POST['rk_variation_id'] ) ? absint( $_POST['rk_variation_id'] ) : 0;
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'manual';
		// phpcs:enable
		if ( ! in_array( $source, array( 'sku', 'keyword', 'manual' ), true ) ) {
			$source = 'manual';
		}
		if ( ! $id || ! $rk_pid ) {
			wp_send_json_error( array( 'message' => __( 'Missing product.', 'resellingkori-connector' ) ) );
		}

		$res = RKC_Mapping::link( $id, $rk_pid, $rk_vid, $source );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Linked.', 'resellingkori-connector' ),
				'siblings' => (int) $res['siblings'],
			)
		);
	}

	public static function ajax_unlink() {
		self::guard();
		$id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $id ) {
			RKC_Mapping::clear( $id );
		}
		wp_send_json_success();
	}

	/**
	 * Bulk Option 1. The browser calls this repeatedly; each call handles one batch.
	 */
	public static function ajax_bulk_sku() {
		self::guard();
		$skipped = isset( $_POST['skipped'] ) ? absint( $_POST['skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$batch   = 25;
		$ids     = RKC_Mapping::unmapped_with_sku( $batch, $skipped );

		if ( ! $ids ) {
			wp_send_json_success(
				array(
					'linked'    => 0,
					'review'    => 0,
					'not_found' => 0,
					'skipped'   => $skipped,
					'done'      => true,
				)
			);
		}

		$res = RKC_Matcher::bulk_sku_link( $ids );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		// Units that were not linked stay in the "unmapped" set, so the next batch must skip them.
		$not_linked = count( $ids ) - (int) $res['linked'];

		wp_send_json_success(
			array(
				'linked'    => (int) $res['linked'],
				'review'    => (int) $res['review'],
				'not_found' => (int) $res['not_found'],
				'skipped'   => $skipped + $not_linked,
				'done'      => count( $ids ) < $batch,
			)
		);
	}

	public static function ajax_test() {
		self::guard();
		$res = RKC_API_Client::ping();

		if ( is_wp_error( $res ) ) {
			set_transient(
				'rkc_conn',
				array(
					'ok'      => false,
					'message' => $res->get_error_message(),
				),
				DAY_IN_SECONDS
			);
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		$site  = isset( $res['site_name'] ) ? (string) $res['site_name'] : 'ResellingKori';
		$store = isset( $res['store']['name'] ) ? (string) $res['store']['name'] : '';
		$msg   = sprintf(
			/* translators: 1: site name, 2: registered store name */
			__( 'Connected to %1$s as "%2$s".', 'resellingkori-connector' ),
			$site,
			$store
		);

		$agent_name  = isset( $res['store']['agent']['name'] ) ? (string) $res['store']['agent']['name'] : '';
		$agent_email = isset( $res['store']['agent']['email'] ) ? (string) $res['store']['agent']['email'] : '';
		if ( '' !== $agent_email ) {
			$msg .= ' ' . sprintf(
				/* translators: 1: agent name, 2: agent email */
				__( 'Orders are assigned to sales agent %1$s (%2$s).', 'resellingkori-connector' ),
				$agent_name,
				$agent_email
			);
		} else {
			$msg .= ' ' . __( 'Warning: no sales agent is linked to this API key yet, so orders will be rejected. Ask ResellingKori to link your agent account.', 'resellingkori-connector' );
		}
		if ( array_key_exists( 'agent_match', $res ) && false === $res['agent_match'] ) {
			$msg .= ' ' . __( 'Warning: the sales agent email you entered does not match the agent linked to this API key. Orders will be refused until they match.', 'resellingkori-connector' );
		}

		if ( isset( $res['server_time'] ) && abs( (int) $res['server_time'] - time() ) > 120 ) {
			$msg .= ' ' . sprintf(
				/* translators: %d: seconds */
				__( 'Warning: your server clock differs from ResellingKori by %d seconds. Requests are rejected above 300 seconds.', 'resellingkori-connector' ),
				abs( (int) $res['server_time'] - time() )
			);
		}
		if ( isset( $res['callback_accepted'] ) && ! $res['callback_accepted'] ) {
			$msg .= ' ' . __( 'Note: this site URL does not match the store URL registered on ResellingKori, so status updates cannot be pushed here. The 15-minute polling fallback still works.', 'resellingkori-connector' );
		}

		set_transient( 'rkc_conn', array( 'ok' => true ), DAY_IN_SECONDS );
		wp_send_json_success( array( 'message' => $msg ) );
	}
}
