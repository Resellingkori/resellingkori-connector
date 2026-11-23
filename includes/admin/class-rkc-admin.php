<?php
/**
 * Admin controller of the Connector: menu, capability checks, assets and the admin-post handlers.
 *
 * Every state-changing form is an admin-post.php handler that (1) checks the capability that action needs, (2) verifies
 * a nonce bound to that action, (3) validates and sanitises its input and (4) redirects (post/redirect/get) with a
 * notice. Interactive features (mapping, order panel) use AJAX endpoints in class-rkc-admin-ajax.php with the same four
 * checks. The API secret is accepted only by the credentials form (POST body), is never printed back, never added to
 * JavaScript data, notices, logs or exports.
 *
 * Capabilities (none is implied by manage_woocommerce; administrators get all, shop managers get mapping/submit/view):
 *   rkc_manage_connection   setup wizard, settings, test connection
 *   rkc_manage_credentials  enter / replace / remove the key and secret
 *   rkc_manage_mapping      link products
 *   rkc_submit_orders       send / retry / cancel orders at ResellingKori
 *   rkc_view_orders         see ResellingKori status on orders and the dashboard
 *   rkc_view_logs           logs and diagnostics
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-rkc-admin-pages.php';
require_once __DIR__ . '/class-rkc-admin-ajax.php';
require_once __DIR__ . '/class-rkc-admin-order.php';

class RKC_Admin {

	const SLUG = 'resellingkori';

	/** tab => capability. */
	const TABS = array(
		'dashboard' => 'rkc_view_orders',
		'setup'     => 'rkc_manage_connection',
		'products'  => 'rkc_manage_mapping',
		'orders'    => 'rkc_view_orders',
		'logs'      => 'rkc_view_logs',
		'settings'  => 'rkc_manage_connection',
	);

	/** admin-post action suffix => capability. */
	const ACTIONS = array(
		'credentials_save'       => 'rkc_manage_credentials',
		'credentials_disconnect' => 'rkc_manage_credentials',
		'settings_save'          => 'rkc_manage_connection',
		'wizard_goto'            => 'rkc_manage_connection',
		'logs_export'            => 'rkc_view_logs',
		'logs_clear'             => 'rkc_manage_connection',
		'workspace_sync_now'     => 'rkc_manage_connection',
		'workspace_enable'       => 'rkc_manage_connection',
		'workspace_decline'      => 'rkc_manage_connection',
	);

	/** @var string */
	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array_keys( self::ACTIONS ) as $name ) {
			add_action(
				'admin_post_rkc_' . $name,
				function () use ( $name ) {
					RKC_Admin::dispatch( $name );
				}
			);
		}
		add_filter( 'plugin_action_links_' . plugin_basename( RKC_FILE ), array( __CLASS__, 'plugin_links' ) );
		RKC_Admin_Ajax::init();
		RKC_Admin_Order::init();
	}

	/* ------------------------------------------------------------------ Menu */

	/**
	 * The page lives under WooCommerce for people who manage the shop. A person who was given only some of this
	 * plugin's capabilities (for example a fulfilment assistant with rkc_submit_orders) cannot open WooCommerce's own
	 * menu, so for them the same page is a top-level menu item. Either way the page checks the capability of each tab.
	 */
	public static function menu() {
		$cap = '';
		foreach ( array_unique( array_values( self::TABS ) ) as $c ) {
			if ( current_user_can( $c ) ) {
				$cap = $c;
				break;
			}
		}
		if ( '' === $cap ) {
			return;
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			self::$hook = (string) add_submenu_page( 'woocommerce', __( 'ResellingKori', 'resellingkori-connector' ), __( 'ResellingKori', 'resellingkori-connector' ), $cap, self::SLUG, array( __CLASS__, 'page' ) );
		} else {
			self::$hook = (string) add_menu_page( __( 'ResellingKori', 'resellingkori-connector' ), __( 'ResellingKori', 'resellingkori-connector' ), $cap, self::SLUG, array( __CLASS__, 'page' ), 'dashicons-randomize', 56 );
		}
	}

	public static function url( $tab = '', array $args = array() ) {
		$q = array( 'page' => self::SLUG );
		if ( '' !== $tab ) {
			$q['tab'] = $tab;
		}
		return add_query_arg( array_merge( $q, $args ), admin_url( 'admin.php' ) );
	}

	public static function plugin_links( $links ) {
		if ( current_user_can( 'rkc_view_orders' ) || current_user_can( 'rkc_manage_connection' ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'resellingkori-connector' ) . '</a>' );
		}
		return $links;
	}

	/* ---------------------------------------------------------------- Assets */

	/** Our own screen, the order list and the order edit screen (for the panel). */
	private static function wants_assets( $hook ) {
		if ( '' !== self::$hook && $hook === self::$hook ) {
			return true;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		$ids = array( 'shop_order', 'edit-shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$ids[] = wc_get_page_screen_id( 'shop-order' );
		}
		return in_array( $screen->id, $ids, true );
	}

	public static function assets( $hook ) {
		if ( ! self::wants_assets( $hook ) ) {
			return;
		}
		wp_enqueue_style( 'rkc-admin', RKC_URL . 'assets/css/admin.css', array(), RKC_VERSION );
		wp_enqueue_script( 'rkc-admin', RKC_URL . 'assets/js/admin.js', array(), RKC_VERSION, true );
		// NOTE: nothing secret is ever put here. The key id and secret never leave the server except to ResellingKori.
		wp_localize_script(
			'rkc-admin',
			'rkcAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'rkc_ajax' ),
				'i18n'  => array(
					// General.
					'working'         => __( 'Working…', 'resellingkori-connector' ),
					'error'           => __( 'Something went wrong. Reload the page and try again.', 'resellingkori-connector' ),
					'copied'          => __( 'Copied', 'resellingkori-connector' ),
					'copyFailed'      => __( 'Press Ctrl+C to copy', 'resellingkori-connector' ),
					'none'            => __( 'None', 'resellingkori-connector' ),
					'close'           => __( 'Close', 'resellingkori-connector' ),
					'search'          => __( 'Search', 'resellingkori-connector' ),
					'reload'          => __( 'Reload the list', 'resellingkori-connector' ),
					// Connection test.
					'verifyOk'        => __( 'Connected', 'resellingkori-connector' ),
					'verifyNot'       => __( 'Not verified', 'resellingkori-connector' ),
					'lblStore'        => __( 'Store', 'resellingkori-connector' ),
					'lblAgent'        => __( 'Sales agent', 'resellingkori-connector' ),
					'lblLive'         => __( 'Live updates', 'resellingkori-connector' ),
					'liveOn'          => __( 'Active', 'resellingkori-connector' ),
					'liveOff'         => __( 'Not active (the plugin checks every 15 minutes)', 'resellingkori-connector' ),
					// Mapping.
					'confirmUnlink'   => __( 'Remove the link for the selected products? Orders containing them will no longer be sent to ResellingKori until you link them again.', 'resellingkori-connector' ),
					'noSku'           => __( 'This product has no SKU, so there is nothing to match exactly.', 'resellingkori-connector' ),
					'noSkuMatch'      => __( 'No ResellingKori product has this SKU.', 'resellingkori-connector' ),
					'noResults'       => __( 'No products found. Try fewer or different words.', 'resellingkori-connector' ),
					'useThis'         => __( 'Use this product', 'resellingkori-connector' ),
					'chooseVariation' => __( 'Choose the exact variation', 'resellingkori-connector' ),
					'outOfStock'      => __( 'Out of stock', 'resellingkori-connector' ),
					'inStock'         => __( 'Available', 'resellingkori-connector' ),
					'hasVariations'   => __( 'Has variations', 'resellingkori-connector' ),
					'candMeta'        => __( 'SKU: %1$s · ID %2$s', 'resellingkori-connector' ),
					'candBase'        => __( 'Base price %s', 'resellingkori-connector' ),
					'notInName'       => __( 'Not in your product name: %s', 'resellingkori-connector' ),
					'match'           => array(
						'high'   => __( 'Very likely', 'resellingkori-connector' ),
						'medium' => __( 'Possible', 'resellingkori-connector' ),
						'low'    => __( 'Unlikely', 'resellingkori-connector' ),
					),
					'skuMismatch'     => __( 'The SKU matches but the names look different. Check carefully before you use it.', 'resellingkori-connector' ),
					'colExact'        => __( '1 · Exact SKU', 'resellingkori-connector' ),
					'colSimilar'      => __( '2 · Similar names', 'resellingkori-connector' ),
					'colFind'         => __( '3 · Search, or paste an ID or SKU', 'resellingkori-connector' ),
					'keywords'        => __( 'Keywords', 'resellingkori-connector' ),
					'findHint'        => __( 'Name, SKU or ID', 'resellingkori-connector' ),
					'loopSku'         => __( 'Matching… linked %1$s · need review %2$s · no SKU match %3$s', 'resellingkori-connector' ),
					'loopSkuDone'     => __( 'Finished. Linked %1$s · need review %2$s · no SKU match %3$s', 'resellingkori-connector' ),
					'loopCheck'       => __( 'Checking… checked %1$s · unavailable %2$s', 'resellingkori-connector' ),
					'loopCheckDone'   => __( 'Finished. Checked %1$s · unavailable %2$s', 'resellingkori-connector' ),
					// Order review.
					'rvTitle'         => __( 'Review before sending', 'resellingkori-connector' ),
					'rvErrors'        => __( 'This order cannot be sent yet:', 'resellingkori-connector' ),
					'rvWarnings'      => __( 'Please check:', 'resellingkori-connector' ),
					'rvPartial'       => __( 'This order also has products from other suppliers. Tick the items you want ResellingKori to deliver.', 'resellingkori-connector' ),
					'rvNeedsReview'   => __( 'Link needs review', 'resellingkori-connector' ),
					'rvNotRk'         => __( 'Not a ResellingKori product', 'resellingkori-connector' ),
					'rvSellAt'        => __( 'You sell at %s', 'resellingkori-connector' ),
					'rvRkPrice'       => __( 'ResellingKori price %s', 'resellingkori-connector' ),
					'rvProfit'        => __( 'Profit %s', 'resellingkori-connector' ),
					'rvLoss'          => __( 'Loss %s', 'resellingkori-connector' ),
					'rvRkName'        => __( 'ResellingKori product: %s', 'resellingkori-connector' ),
					'rvDelivery'      => __( 'Delivery charge the customer pays (BDT)', 'resellingkori-connector' ),
					'rvOtherCredit'   => __( 'The customer already received a credit or discount of %s. Subtract it, so the courier does not collect it.', 'resellingkori-connector' ),
					'rvOtherCollect'  => __( 'Also collect %s of fees or adjustments from the customer.', 'resellingkori-connector' ),
					'rvSelling'       => __( 'Items at your selling prices', 'resellingkori-connector' ),
					'rvDeliveryRow'   => __( 'Delivery charge', 'resellingkori-connector' ),
					'rvOtherRow'      => __( 'Fees or adjustments', 'resellingkori-connector' ),
					'rvRoundingRow'   => __( 'Rounding', 'resellingkori-connector' ),
					'rvOtherNote'     => __( 'ResellingKori has no separate field for this amount, so it is sent together with the delivery charge.', 'resellingkori-connector' ),
					'rvTotal'         => __( 'Order total', 'resellingkori-connector' ),
					'rvPaid'          => __( 'Already paid or credited', 'resellingkori-connector' ),
					'rvPaidPrepaid'   => __( 'Already paid by the customer', 'resellingkori-connector' ),
					'rvCod'           => __( 'Cash the courier collects', 'resellingkori-connector' ),
					'rvProfitRow'     => __( 'Your profit on the items', 'resellingkori-connector' ),
					'rvDeliverTo'     => __( 'Delivery to', 'resellingkori-connector' ),
					'rvHint'          => __( 'Check the amounts once more: the courier collects exactly the "cash the courier collects" amount.', 'resellingkori-connector' ),
					'rvSend'          => __( 'Confirm and send to ResellingKori', 'resellingkori-connector' ),
					// Cancel.
					'cancelTitle'     => __( 'Cancel this order at ResellingKori', 'resellingkori-connector' ),
					'cancelHelp'      => __( 'ResellingKori stops fulfilling it. You can send a corrected order afterwards.', 'resellingkori-connector' ),
					'cancelWhy'       => __( 'Reason (optional)', 'resellingkori-connector' ),
					'cancelDo'        => __( 'Cancel the order at ResellingKori', 'resellingkori-connector' ),
					'cancelKeep'      => __( 'Keep the order', 'resellingkori-connector' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ Flash */

	public static function flash( $type, $message ) {
		set_transient( 'rkc_flash_' . get_current_user_id(), array( 'type' => $type, 'message' => (string) $message ), 120 );
	}

	/** @return array|null */
	public static function take_flash() {
		$key  = 'rkc_flash_' . get_current_user_id();
		$data = get_transient( $key );
		if ( $data ) {
			delete_transient( $key );
		}
		return is_array( $data ) ? $data : null;
	}

	/* ------------------------------------------------------------------ Pages */

	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( self::TABS[ $tab ] ) && current_user_can( self::TABS[ $tab ] ) ) {
			return $tab;
		}
		if ( current_user_can( 'rkc_manage_connection' ) && ! RKC_Config::connected() ) {
			return 'setup';
		}
		foreach ( self::TABS as $t => $cap ) {
			if ( current_user_can( $cap ) ) {
				return $t;
			}
		}
		return 'dashboard';
	}

	public static function page() {
		$tab = self::current_tab();
		if ( ! current_user_can( self::TABS[ $tab ] ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'resellingkori-connector' ), 403 );
		}
		echo '<div class="wrap rkc-wrap">';
		RKC_Admin_Pages::header( $tab );
		$flash = self::take_flash();
		if ( $flash ) {
			echo '<div class="rkc-notice rkc-notice--' . esc_attr( in_array( $flash['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $flash['type'] : 'info' ) . '" role="status"><p>' . esc_html( $flash['message'] ) . '</p></div>';
		}
		call_user_func( array( 'RKC_Admin_Pages', $tab ) );
		echo '</div>';
	}

	/* ------------------------------------------------------------- Dispatch */

	public static function dispatch( $name ) {
		if ( ! isset( self::ACTIONS[ $name ] ) ) {
			wp_die( esc_html__( 'Unknown action.', 'resellingkori-connector' ), 400 );
		}
		if ( ! current_user_can( self::ACTIONS[ $name ] ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'resellingkori-connector' ), 403 );
		}
		check_admin_referer( 'rkc_' . $name );
		call_user_func( array( __CLASS__, 'do_' . $name ) );
		self::back( 'dashboard' ); // Handlers redirect themselves; this is a safety net.
	}

	private static function back( $tab, array $args = array() ) {
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/** Where a form came from, restricted to known tabs. */
	private static function return_tab() {
		$t = isset( $_POST['rkc_return'] ) ? sanitize_key( wp_unslash( $_POST['rkc_return'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in dispatch().
		return in_array( $t, array( 'setup', 'settings' ), true ) ? $t : 'settings';
	}

	/* ------------------------------------------------------------- Handlers */

	private static function do_credentials_save() {
		$key_id = isset( $_POST['rkc_key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rkc_key_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in dispatch().
		// The secret is validated against a strict character set by save_credentials() and is never echoed or logged.
		$secret = isset( $_POST['rkc_secret'] ) ? trim( (string) wp_unslash( $_POST['rkc_secret'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in dispatch(); validated by regex.
		$return = self::return_tab();

		$res = RKC_Config::save_credentials( $key_id, $secret );
		unset( $secret );
		if ( is_wp_error( $res ) ) {
			self::flash( 'error', $res->get_error_message() );
			self::back( $return, 'setup' === $return ? array( 'step' => 2 ) : array() );
		}
		delete_transient( 'rkc_clock_offset' );
		RKC_Logger::info( 'API credentials were saved (not yet verified)' );
		self::flash( 'success', __( 'Saved. The secret is stored encrypted and will not be shown again. Now verify the connection.', 'resellingkori-connector' ) );
		self::back( 'setup', array( 'step' => 3 ) );
	}

	private static function do_credentials_disconnect() {
		RKC_Config::disconnect();
		RKC_Logger::warning( 'Disconnected from ResellingKori (credentials removed)' );
		self::flash( 'success', __( 'Disconnected. The key and secret were removed from this site. Your product links and order history are kept.', 'resellingkori-connector' ) );
		self::back( 'setup', array( 'step' => 2 ) );
	}

	private static function do_settings_save() {
		$input = ( isset( $_POST['rkc'] ) && is_array( $_POST['rkc'] ) ) ? wp_unslash( $_POST['rkc'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in dispatch(); sanitised by RKC_Settings::sanitize().
		$old   = RKC_Settings::get();
		update_option( RKC_Settings::OPTION, RKC_Settings::sanitize( $input ), false );
		$new   = RKC_Settings::get();
		RKC_Logger::info( 'Settings saved' );

		$message = __( 'Settings saved.', 'resellingkori-connector' );
		if ( $old['expected_agent_email'] !== $new['expected_agent_email'] && RKC_Config::configured() ) {
			$res = RKC_Connect::verify( false ); // Re-check who the key belongs to against the new expectation.
			if ( is_wp_error( $res ) ) {
				$message .= ' ' . $res->get_error_message();
			} elseif ( 'connected' !== $res['status'] ) {
				self::flash( 'warning', $res['error'] );
				self::back( 'settings' );
			}
		}
		self::flash( 'success', $message );
		$next = ( isset( $_POST['rkc_wizard_next'] ) && '1' === $_POST['rkc_wizard_next'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in dispatch().
		if ( $next ) {
			RKC_Config::mark( array( 'wizard_step' => max( 6, (int) RKC_Config::get( 'wizard_step' ) ) ) );
			self::back( 'setup', array( 'step' => 6 ) );
		}
		self::back( 'settings' );
	}

	/** Run the agent-dashboard routine now and say what came of it. */
	private static function do_workspace_sync_now() {
		$res = RKC_Workspace::tick( 'manual' );
		if ( 'busy' === $res['skipped'] ) {
			self::flash( 'info', __( 'A sync is already running. Try again in a moment.', 'resellingkori-connector' ) );
		} elseif ( '' !== $res['skipped'] ) {
			$sum = RKC_Workspace::summary();
			self::flash( 'warning', $sum['text'] );
		} elseif ( '' !== $res['error'] ) {
			$sum = RKC_Workspace::summary();
			self::flash( 'warning', $sum['text'] );
		} else {
			/* translators: 1: orders, 2: products, 3: instructions */
			self::flash( 'success', sprintf( __( 'Synced. Reported %1$d orders and %2$d products; carried out %3$d instructions.', 'resellingkori-connector' ), (int) $res['orders'], (int) $res['products'], (int) $res['commands'] ) );
		}
		self::back( 'settings' );
	}

	/** "Turn on" from the notice on the dashboard. */
	private static function do_workspace_enable() {
		update_option( RKC_Settings::OPTION, RKC_Settings::sanitize( array( 'workspace_sync' => 'yes' ) ), false );
		RKC_Logger::info( 'The agent dashboard sync was switched on' );
		$res = RKC_Workspace::tick( 'manual' );
		$sum = RKC_Workspace::summary();
		self::flash( '' === $res['error'] ? 'success' : 'warning', __( 'Switched on.', 'resellingkori-connector' ) . ' ' . $sum['text'] );
		self::back( 'dashboard' );
	}

	/** "Not now": remembered, so the notice does not come back. It can be switched on in Settings at any time. */
	private static function do_workspace_decline() {
		update_option( RKC_Settings::OPTION, RKC_Settings::sanitize( array( 'workspace_sync' => 'no' ) ), false );
		self::flash( 'info', __( 'Understood. Nothing is shared. You can switch the agent dashboard on later in Settings.', 'resellingkori-connector' ) );
		self::back( 'dashboard' );
	}

	private static function do_wizard_goto() {
		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in dispatch().
		$step = RKC_Admin_Pages::clamp_step( $step );
		RKC_Config::mark( array( 'wizard_step' => max( $step, (int) RKC_Config::get( 'wizard_step' ) ) ) );
		self::back( 'setup', array( 'step' => $step ) );
	}

	private static function do_logs_export() {
		global $wpdb;
		$q    = RKC_Logger::query( self::log_filters( false ) + array( 'limit' => 5000 ) );
		$name = 'rkc-logs-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'time_utc', 'level', 'message', 'order_id', 'context' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		foreach ( $q['rows'] as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), array( $row->created_at, $row->level, $row->message, (int) $row->order_id ?: '', (string) $row->context ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/** Neutralise spreadsheet formulas in exported text. */
	public static function csv_cell( $v ) {
		$v = (string) $v;
		return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v;
	}

	private static function do_logs_clear() {
		RKC_Logger::clear();
		RKC_Logger::info( 'The log was cleared by a user' );
		self::flash( 'success', __( 'The log was cleared.', 'resellingkori-connector' ) );
		self::back( 'logs' );
	}

	/** Filters for the log screen / export, read from the request. */
	public static function log_filters( $post = false ) {
		$src   = $post ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		$level = isset( $src['level'] ) ? sanitize_key( wp_unslash( $src['level'] ) ) : '';
		return array(
			'level'    => isset( RKC_Logger::LEVELS[ $level ] ) ? $level : '',
			'order_id' => isset( $src['order_id'] ) ? absint( $src['order_id'] ) : 0,
			'search'   => isset( $src['s'] ) ? sanitize_text_field( wp_unslash( $src['s'] ) ) : '',
		);
	}
}
