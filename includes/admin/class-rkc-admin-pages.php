<?php
/**
 * Screens of the Connector: dashboard, setup wizard, products, orders, logs & diagnostics, settings.
 * All output is escaped at the point of printing. Nothing here ever prints the API secret.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Admin_Pages {

	const STEPS = 6;

	/* ------------------------------------------------------------- Helpers */

	public static function badge( $text, $kind = 'neutral' ) {
		$kinds = array( 'neutral', 'good', 'warn', 'bad', 'info' );
		return '<span class="rkc-badge rkc-badge--' . esc_attr( in_array( $kind, $kinds, true ) ? $kind : 'neutral' ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function e( $text ) {
		echo esc_html( $text );
	}

	/** Steps that make sense right now (3 needs credentials, 4+ need a working connection). */
	public static function clamp_step( $step ) {
		$step = max( 1, min( self::STEPS, (int) $step ) );
		if ( $step >= 3 && ! RKC_Config::configured() ) {
			return 2;
		}
		if ( $step >= 4 && ! RKC_Config::connected() ) {
			return 3;
		}
		return $step;
	}

	public static function state_badge( $stage ) {
		switch ( $stage ) {
			case 'accepted':
				return self::badge( __( 'Sent', 'resellingkori-connector' ), 'good' );
			case 'unknown':
			case 'sending':
			case 'claimed':
				return self::badge( __( 'Waiting for confirmation', 'resellingkori-connector' ), 'warn' );
			case 'rejected':
				return self::badge( __( 'Refused', 'resellingkori-connector' ), 'bad' );
			case 'cancelled':
				return self::badge( __( 'Cancelled', 'resellingkori-connector' ), 'neutral' );
			default:
				return self::badge( __( 'Not sent', 'resellingkori-connector' ), 'neutral' );
		}
	}

	public static function status_badge( $status ) {
		if ( '' === (string) $status ) {
			return '';
		}
		$kind = array(
			'delivered' => 'good',
			'shipped'   => 'info',
			'returned'  => 'bad',
			'cancelled' => 'neutral',
			'on_hold'   => 'warn',
		);
		return self::badge( RKC_Orders::status_label( $status ), isset( $kind[ $status ] ) ? $kind[ $status ] : 'info' );
	}

	private static function connection_pill() {
		$status = RKC_Config::get( 'status' );
		$map    = array(
			'connected'  => array( __( 'Connected', 'resellingkori-connector' ), 'good' ),
			'unverified' => array( __( 'Not verified', 'resellingkori-connector' ), 'warn' ),
			'error'      => array( __( 'Connection problem', 'resellingkori-connector' ), 'bad' ),
			'none'       => array( __( 'Not connected', 'resellingkori-connector' ), 'neutral' ),
		);
		$m = isset( $map[ $status ] ) ? $map[ $status ] : $map['none'];
		return self::badge( $m[0], $m[1] );
	}

	private static function post_form_open( $action, $extra_class = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $extra_class ) . '">';
		echo '<input type="hidden" name="action" value="rkc_' . esc_attr( $action ) . '">';
		// Unique element id: WordPress's own helper prints id="_wpnonce" every time, which is invalid when a page has several forms.
		static $n = 0;
		++$n;
		echo '<input type="hidden" id="rkc-nonce-' . (int) $n . '" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'rkc_' . $action ) ) . '">' . wp_referer_field( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_referer_field() escapes.
	}

	private static function action_url( $action, array $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'rkc_' . $action ), $args ), admin_url( 'admin-post.php' ) ), 'rkc_' . $action );
	}

	private static function order_link( $order_id, $label = '' ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return esc_html( '#' . (int) $order_id );
		}
		return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( '' !== $label ? $label : '#' . $order->get_order_number() ) . '</a>';
	}

	/* --------------------------------------------------------------- Header */

	public static function header( $current ) {
		$labels = array(
			'dashboard' => __( 'Dashboard', 'resellingkori-connector' ),
			'setup'     => __( 'Setup', 'resellingkori-connector' ),
			'products'  => __( 'Products', 'resellingkori-connector' ),
			'orders'    => __( 'Orders', 'resellingkori-connector' ),
			'logs'      => __( 'Logs & diagnostics', 'resellingkori-connector' ),
			'settings'  => __( 'Settings', 'resellingkori-connector' ),
		);
		echo '<header class="rkc-header"><h1>' . esc_html__( 'ResellingKori', 'resellingkori-connector' ) . '</h1>';
		echo '<span class="rkc-header__pill" data-status="' . esc_attr( RKC_Config::get( 'status' ) ) . '">' . self::connection_pill() . '</span></header>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '<nav class="rkc-tabs" aria-label="' . esc_attr__( 'ResellingKori sections', 'resellingkori-connector' ) . '">';
		foreach ( RKC_Admin::TABS as $tab => $cap ) {
			if ( ! current_user_can( $cap ) ) {
				continue;
			}
			printf(
				'<a class="rkc-tab%s" href="%s"%s>%s</a>',
				$tab === $current ? ' is-active' : '',
				esc_url( RKC_Admin::url( $tab ) ),
				$tab === $current ? ' aria-current="page"' : '',
				esc_html( $labels[ $tab ] )
			);
		}
		echo '</nav>';
		// Persistent warnings that stop orders from working.
		if ( 'error' === RKC_Config::get( 'status' ) && '' !== RKC_Config::get( 'last_error' ) ) {
			echo '<div class="rkc-notice rkc-notice--error" role="alert"><p><strong>' . esc_html__( 'Connection problem:', 'resellingkori-connector' ) . '</strong> ' . esc_html( RKC_Config::get( 'last_error' ) ) . '</p></div>';
		}
		if ( ! RKC_Crypto::available() ) {
			echo '<div class="rkc-notice rkc-notice--error" role="alert"><p>' . esc_html__( 'This server cannot encrypt the API secret (PHP sodium extension is missing). Ask your host to enable it before connecting.', 'resellingkori-connector' ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------ Dashboard */

	public static function dashboard() {
		$c = RKC_Config::get();
		if ( ! RKC_Config::configured() ) {
			echo '<div class="rkc-card rkc-card--hero"><h2>' . esc_html__( 'Connect your store to ResellingKori', 'resellingkori-connector' ) . '</h2>';
			echo '<p>' . esc_html__( 'Link your products, send orders for fulfilment with your own selling prices, and get tracking back automatically. Setup takes about five minutes.', 'resellingkori-connector' ) . '</p>';
			if ( current_user_can( 'rkc_manage_connection' ) ) {
				echo '<p><a class="rkc-btn rkc-btn--primary" href="' . esc_url( RKC_Admin::url( 'setup' ) ) . '">' . esc_html__( 'Start setup', 'resellingkori-connector' ) . '</a></p>';
			} else {
				echo '<p>' . esc_html__( 'Ask a store administrator to finish the setup.', 'resellingkori-connector' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		// Connection.
		echo '<div class="rkc-grid">';
		echo '<section class="rkc-card" aria-labelledby="rkc-h-conn"><h2 id="rkc-h-conn">' . esc_html__( 'Connection', 'resellingkori-connector' ) . '</h2><dl class="rkc-dl">';
		self::dl( __( 'Status', 'resellingkori-connector' ), self::connection_pill(), true );
		self::dl( __( 'Store at ResellingKori', 'resellingkori-connector' ), '' !== $c['store_name'] ? $c['store_name'] : '—' );
		self::dl( __( 'Sales agent', 'resellingkori-connector' ), '' !== $c['agent_name'] ? $c['agent_name'] . ( '' !== $c['agent_hint'] ? ' (' . $c['agent_hint'] . ')' : '' ) : '—' );
		self::dl( __( 'Key', 'resellingkori-connector' ), RKC_Config::masked_key() . ' · ' . __( 'secret saved (hidden)', 'resellingkori-connector' ) );
		self::dl( __( 'Live updates (webhook)', 'resellingkori-connector' ), self::webhook_badge( $c['callback_state'] ), true );
		self::dl( __( 'Last checked', 'resellingkori-connector' ), $c['verified_at'] ? RKC_DB::ago( (int) $c['verified_at'] ) : '—' );
		$ws = RKC_Workspace::summary();
		self::dl( __( 'Agent dashboard', 'resellingkori-connector' ), self::badge( self::ws_label( $ws ), self::ws_kind( $ws ) ) . ' <a href="' . esc_url( RKC_Admin::url( 'settings' ) . '#rkc-h-ws' ) . '">' . esc_html__( 'Details', 'resellingkori-connector' ) . '</a>', true );
		echo '</dl>';
		if ( 'verified' !== $c['callback_state'] && '' !== $c['callback_msg'] ) {
			echo '<p class="rkc-muted">' . esc_html( $c['callback_msg'] ) . '</p>';
		}
		if ( current_user_can( 'rkc_manage_connection' ) ) {
			echo '<p class="rkc-actions"><button type="button" class="rkc-btn" data-rkc="verify" data-target="rkc-dash-verify">' . esc_html__( 'Test connection', 'resellingkori-connector' ) . '</button> ';
			echo '<a class="rkc-btn rkc-btn--ghost" href="' . esc_url( RKC_Admin::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'resellingkori-connector' ) . '</a></p>';
			echo '<div id="rkc-dash-verify" class="rkc-result" aria-live="polite"></div>';
		}
		echo '</section>';

		// Needs attention.
		echo '<section class="rkc-card" aria-labelledby="rkc-h-att"><h2 id="rkc-h-att">' . esc_html__( 'Needs attention', 'resellingkori-connector' ) . '</h2>';
		$items = self::attention_items();
		if ( ! $items ) {
			echo '<p class="rkc-ok">' . esc_html__( 'Nothing needs your attention.', 'resellingkori-connector' ) . '</p>';
		} else {
			echo '<ul class="rkc-attention">';
			foreach ( $items as $it ) {
				echo '<li class="rkc-attention__' . esc_attr( $it['kind'] ) . '">' . $it['html'] . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html is assembled from escaped parts in attention_items().
			}
			echo '</ul>';
		}
		echo '</section></div>';

		// Numbers.
		$map = RKC_Mapping::counts();
		$ord = RKC_Submit::counts();
		$open = wc_get_orders( array( 'limit' => 1, 'paginate' => true, 'return' => 'ids', 'meta_key' => '_rkc_open', 'meta_value' => '1' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		echo '<section class="rkc-card" aria-labelledby="rkc-h-num"><h2 id="rkc-h-num">' . esc_html__( 'Overview', 'resellingkori-connector' ) . '</h2><div class="rkc-stats">';
		self::stat( (string) $map['mapped'], __( 'Products linked', 'resellingkori-connector' ), RKC_Admin::url( 'products', array( 'filter' => 'mapped' ) ) );
		self::stat( (string) $map['unmapped'], __( 'Not linked yet', 'resellingkori-connector' ), RKC_Admin::url( 'products', array( 'filter' => 'unmapped' ) ) );
		self::stat( (string) $ord['accepted'], __( 'Orders sent', 'resellingkori-connector' ), RKC_Admin::url( 'orders', array( 'state' => 'accepted' ) ) );
		self::stat( (string) ( is_object( $open ) ? (int) $open->total : 0 ), __( 'In progress at ResellingKori', 'resellingkori-connector' ), RKC_Admin::url( 'orders' ) );
		echo '</div></section>';

		// Recent activity.
		if ( current_user_can( 'rkc_view_logs' ) ) {
			$log = RKC_Logger::query( array( 'limit' => 6 ) );
			echo '<section class="rkc-card" aria-labelledby="rkc-h-act"><h2 id="rkc-h-act">' . esc_html__( 'Recent activity', 'resellingkori-connector' ) . '</h2>';
			if ( ! $log['rows'] ) {
				echo '<p class="rkc-muted">' . esc_html__( 'Nothing yet.', 'resellingkori-connector' ) . '</p>';
			} else {
				echo '<ul class="rkc-activity">';
				foreach ( $log['rows'] as $row ) {
					echo '<li>' . self::level_badge( $row->level ) . ' <span class="rkc-muted">' . esc_html( RKC_DB::ago( $row->created_at ) ) . '</span> ' . esc_html( $row->message ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</ul><p><a href="' . esc_url( RKC_Admin::url( 'logs' ) ) . '">' . esc_html__( 'Open the full log', 'resellingkori-connector' ) . '</a></p>';
			}
			echo '</section>';
		}
	}

	private static function dl( $label, $value, $is_html = false ) {
		echo '<div class="rkc-dl__row"><dt>' . esc_html( $label ) . '</dt><dd>' . ( $is_html ? $value : esc_html( $value ) ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html values are built from escaped parts.
	}

	private static function stat( $number, $label, $url ) {
		echo '<a class="rkc-stat" href="' . esc_url( $url ) . '"><span class="rkc-stat__n">' . esc_html( $number ) . '</span><span class="rkc-stat__l">' . esc_html( $label ) . '</span></a>';
	}

	public static function webhook_badge( $state ) {
		switch ( $state ) {
			case 'verified':
				return self::badge( __( 'Active', 'resellingkori-connector' ), 'good' );
			case 'failed':
			case 'unreachable':
			case 'rejected':
				return self::badge( __( 'Not working — checking every 15 min instead', 'resellingkori-connector' ), 'warn' );
			default:
				return self::badge( __( 'Not set up — checking every 15 min instead', 'resellingkori-connector' ), 'neutral' );
		}
	}

	public static function level_badge( $level ) {
		$kind = array( 'error' => 'bad', 'warning' => 'warn', 'info' => 'info', 'debug' => 'neutral' );
		return self::badge( strtoupper( $level ), isset( $kind[ $level ] ) ? $kind[ $level ] : 'neutral' );
	}

	/** @return array[] { kind, html } */
	private static function attention_items() {
		$out = array();
		$c   = RKC_Config::get();
		if ( 'unverified' === $c['status'] && '' !== $c['last_error'] ) {
			$out[] = array( 'kind' => 'warn', 'html' => esc_html( $c['last_error'] ) );
		} elseif ( 'unverified' === $c['status'] ) {
			$out[] = array( 'kind' => 'warn', 'html' => esc_html__( 'The connection has not been verified yet.', 'resellingkori-connector' ) . ' <a href="' . esc_url( RKC_Admin::url( 'setup', array( 'step' => 3 ) ) ) . '">' . esc_html__( 'Verify now', 'resellingkori-connector' ) . '</a>' );
		}
		if ( false === $c['agent_valid'] && 'connected' === $c['status'] ) {
			$out[] = array( 'kind' => 'bad', 'html' => esc_html__( 'No valid sales agent is linked to your key at ResellingKori. Orders cannot be placed until they link your agent account.', 'resellingkori-connector' ) );
		}
		if ( ! RKC_Crypto::self_test() ) {
			$out[] = array( 'kind' => 'bad', 'html' => esc_html__( 'The saved secret cannot be decrypted (the WordPress salts changed?). Enter the key and secret again in Settings.', 'resellingkori-connector' ) );
		} elseif ( RKC_Config::configured() && false === RKC_Config::secret() ) {
			$out[] = array( 'kind' => 'bad', 'html' => esc_html__( 'The saved secret cannot be decrypted (the WordPress salts or RKC_ENCRYPTION_KEY changed). Enter the key and secret again in Settings.', 'resellingkori-connector' ) );
		}
		if ( RKC_Config::connected() && current_user_can( 'rkc_manage_connection' ) ) {
			if ( '' === (string) RKC_Settings::get( 'workspace_sync' ) ) {
				$out[] = array(
					'kind' => 'warn',
					'html' => '<strong>' . esc_html__( 'New: use your ResellingKori agent dashboard for this shop.', 'resellingkori-connector' ) . '</strong> '
						. esc_html__( 'See the shop\'s orders that contain ResellingKori products, send them, and link products from your agent dashboard, without opening WooCommerce. This shares a short summary of those orders (order number, items, amounts, the customer\'s name and city and a partly hidden phone number) with your ResellingKori agent account.', 'resellingkori-connector' )
						. ' <a class="rkc-btn rkc-btn--small rkc-btn--primary" href="' . esc_url( self::action_url( 'workspace_enable' ) ) . '">' . esc_html__( 'Turn on', 'resellingkori-connector' ) . '</a> '
						. '<a class="rkc-btn rkc-btn--small" href="' . esc_url( self::action_url( 'workspace_decline' ) ) . '">' . esc_html__( 'Not now', 'resellingkori-connector' ) . '</a>',
				);
			} elseif ( 'yes' === RKC_Settings::get( 'workspace_sync' ) ) {
				$ws = RKC_Workspace::summary();
				if ( 'bad' === $ws['kind'] ) {
					$out[] = array( 'kind' => 'bad', 'html' => esc_html__( 'Agent dashboard:', 'resellingkori-connector' ) . ' ' . esc_html( $ws['text'] ) . ' <a href="' . esc_url( RKC_Admin::url( 'settings' ) . '#rkc-h-ws' ) . '">' . esc_html__( 'Details', 'resellingkori-connector' ) . '</a>' );
				}
			}
		}
		foreach ( array( 'unknown' => __( 'Waiting for confirmation from ResellingKori', 'resellingkori-connector' ), 'rejected' => __( 'Refused by ResellingKori', 'resellingkori-connector' ) ) as $state => $label ) {
			foreach ( RKC_Submit::latest_rows( $state, 5, 0 ) as $row ) {
				$out[] = array( 'kind' => 'unknown' === $state ? 'warn' : 'bad', 'html' => self::order_link( $row->order_id ) . ' — ' . esc_html( $label ) . ( '' !== $row->error_message ? ': ' . esc_html( $row->error_message ) : '' ) );
			}
		}
		$map = RKC_Mapping::counts();
		if ( $map['review'] > 0 ) {
			/* translators: %d: number of products */
			$out[] = array( 'kind' => 'warn', 'html' => esc_html( sprintf( _n( '%d product link needs your confirmation before orders will use it.', '%d product links need your confirmation before orders will use them.', $map['review'], 'resellingkori-connector' ), $map['review'] ) ) . ' <a href="' . esc_url( RKC_Admin::url( 'products', array( 'filter' => 'review' ) ) ) . '">' . esc_html__( 'Review', 'resellingkori-connector' ) . '</a>' );
		}
		if ( $map['unavailable'] > 0 ) {
			/* translators: %d: number of products */
			$out[] = array( 'kind' => 'warn', 'html' => esc_html( sprintf( _n( '%d linked product is unavailable at ResellingKori.', '%d linked products are unavailable at ResellingKori.', $map['unavailable'], 'resellingkori-connector' ), $map['unavailable'] ) ) . ' <a href="' . esc_url( RKC_Admin::url( 'products', array( 'filter' => 'unavailable' ) ) ) . '">' . esc_html__( 'See which', 'resellingkori-connector' ) . '</a>' );
		}
		return $out;
	}

	/* --------------------------------------------------------------- Wizard */

	public static function setup() {
		$c    = RKC_Config::get();
		$want = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : max( 1, (int) $c['wizard_step'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = self::clamp_step( $want );
		$names = array(
			1 => __( 'Welcome', 'resellingkori-connector' ),
			2 => __( 'Connect', 'resellingkori-connector' ),
			3 => __( 'Verify', 'resellingkori-connector' ),
			4 => __( 'Products', 'resellingkori-connector' ),
			5 => __( 'Orders', 'resellingkori-connector' ),
			6 => __( 'Finish', 'resellingkori-connector' ),
		);
		echo '<ol class="rkc-steps" aria-label="' . esc_attr__( 'Setup progress', 'resellingkori-connector' ) . '">';
		foreach ( $names as $n => $label ) {
			$reachable = ( self::clamp_step( $n ) === $n );
			$class     = 'rkc-steps__item' . ( $n === $step ? ' is-current' : '' ) . ( $n < $step ? ' is-done' : '' );
			echo '<li class="' . esc_attr( $class ) . '"' . ( $n === $step ? ' aria-current="step"' : '' ) . '>';
			if ( $reachable && $n !== $step ) {
				echo '<a href="' . esc_url( RKC_Admin::url( 'setup', array( 'step' => $n ) ) ) . '"><span class="rkc-steps__n">' . (int) $n . '</span> ' . esc_html( $label ) . '</a>';
			} else {
				echo '<span class="rkc-steps__n">' . (int) $n . '</span> ' . esc_html( $label );
			}
			echo '</li>';
		}
		echo '</ol>';

		echo '<div class="rkc-card rkc-step">';
		call_user_func( array( __CLASS__, 'step_' . $step ) );
		echo '</div>';
	}

	private static function step_nav( $prev, $next_label = '', $next_step = 0, $next_disabled = false ) {
		echo '<p class="rkc-actions rkc-step__nav">';
		if ( $prev ) {
			echo '<a class="rkc-btn rkc-btn--ghost" href="' . esc_url( RKC_Admin::url( 'setup', array( 'step' => $prev ) ) ) . '">' . esc_html__( 'Back', 'resellingkori-connector' ) . '</a> ';
		}
		if ( $next_step ) {
			if ( $next_disabled ) {
				echo '<span class="rkc-btn rkc-btn--primary is-disabled" aria-disabled="true">' . esc_html( $next_label ) . '</span>';
			} else {
				echo '<a class="rkc-btn rkc-btn--primary" id="rkc-next" href="' . esc_url( self::action_url( 'wizard_goto', array( 'step' => $next_step ) ) ) . '">' . esc_html( $next_label ) . '</a>';
			}
		}
		echo '</p>';
	}

	private static function step_1() {
		echo '<h2>' . esc_html__( 'Welcome', 'resellingkori-connector' ) . '</h2>';
		echo '<p>' . esc_html__( 'This plugin connects your WooCommerce store to ResellingKori so that you can sell ResellingKori products under your own prices and have each order fulfilled for you.', 'resellingkori-connector' ) . '</p>';
		echo '<h3>' . esc_html__( 'Before you start', 'resellingkori-connector' ) . '</h3>';
		echo '<ul class="rkc-checks">';
		$checks = array(
			array( class_exists( 'WooCommerce' ), __( 'WooCommerce is active', 'resellingkori-connector' ), '' ),
			array( RKC_Crypto::available(), __( 'The server can encrypt the secret (PHP sodium)', 'resellingkori-connector' ), __( 'Ask your host to enable the PHP sodium extension.', 'resellingkori-connector' ) ),
			array( is_ssl(), __( 'Your site uses HTTPS', 'resellingkori-connector' ), __( 'ResellingKori only sends live updates to HTTPS addresses. Without HTTPS the plugin falls back to checking every 15 minutes.', 'resellingkori-connector' ) ),
			array( 'BDT' === get_woocommerce_currency(), __( 'Your store currency is BDT', 'resellingkori-connector' ), __( 'ResellingKori accepts orders in BDT only.', 'resellingkori-connector' ) ),
		);
		foreach ( $checks as $ch ) {
			echo '<li class="' . ( $ch[0] ? 'is-ok' : 'is-warn' ) . '"><span class="rkc-checks__mark" aria-hidden="true">' . ( $ch[0] ? '✔' : '!' ) . '</span> ' . esc_html( $ch[1] );
			if ( ! $ch[0] && '' !== $ch[2] ) {
				echo '<br><span class="rkc-muted">' . esc_html( $ch[2] ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<p>' . esc_html__( 'You also need your Key ID and secret. ResellingKori issues them to you (the secret is shown only once, so keep it safe).', 'resellingkori-connector' ) . '</p>';
		echo '<details class="rkc-details"><summary>' . esc_html__( 'What information is sent to ResellingKori?', 'resellingkori-connector' ) . '</summary><ul>';
		echo '<li>' . esc_html__( 'When you press "Send" on an order: the recipient name, phone, address, optional e-mail, the items (SKU, name, quantity, your selling price), the amounts and the customer note.', 'resellingkori-connector' ) . '</li>';
		echo '<li>' . esc_html__( 'When you look for matching products: the product names or SKUs you search for.', 'resellingkori-connector' ) . '</li>';
		echo '<li>' . esc_html__( 'Every 15 minutes, status checks for orders you already sent (ResellingKori order numbers only).', 'resellingkori-connector' ) . '</li>';
		echo '<li>' . esc_html__( 'Nothing else. There is no analytics, tracking or advertising. The log on this site masks phone numbers and e-mail addresses.', 'resellingkori-connector' ) . '</li></ul></details>';
		self::step_nav( 0, __( 'Continue', 'resellingkori-connector' ), 2 );
	}

	private static function step_2() {
		$c = RKC_Config::get();
		echo '<h2>' . esc_html__( 'Connect with your key', 'resellingkori-connector' ) . '</h2>';
		if ( RKC_Config::configured() ) {
			echo '<p class="rkc-ok">' . esc_html( sprintf( /* translators: %s: masked key id */ __( 'A key is saved: %s. The secret is stored encrypted and is never shown again.', 'resellingkori-connector' ), RKC_Config::masked_key() ) ) . '</p>';
			echo '<details class="rkc-details"><summary>' . esc_html__( 'Replace the key and secret', 'resellingkori-connector' ) . '</summary>';
			self::credentials_form( 'setup' );
			echo '</details>';
			self::step_nav( 1, __( 'Continue', 'resellingkori-connector' ), 3 );
			return;
		}
		echo '<p>' . esc_html__( 'Enter the Key ID and secret that ResellingKori gave you. Nothing else is needed: the store, your sales agent and the delivery address for updates are all taken from the key.', 'resellingkori-connector' ) . '</p>';
		self::credentials_form( 'setup' );
		self::step_nav( 1 );
		unset( $c );
	}

	/** The only place a secret is typed. POST body only; the field is never pre-filled. */
	public static function credentials_form( $return ) {
		self::post_form_open( 'credentials_save', 'rkc-form' );
		echo '<input type="hidden" name="rkc_return" value="' . esc_attr( $return ) . '">';
		echo '<p><label for="rkc_key_id"><strong>' . esc_html__( 'Key ID', 'resellingkori-connector' ) . '</strong></label><br><input type="text" id="rkc_key_id" name="rkc_key_id" class="regular-text rkc-input" value="" placeholder="rkk_…" autocomplete="off" autocapitalize="off" spellcheck="false" required></p>';
		echo '<p><label for="rkc_secret"><strong>' . esc_html__( 'Secret', 'resellingkori-connector' ) . '</strong></label><br><input type="password" id="rkc_secret" name="rkc_secret" class="regular-text rkc-input" value="" placeholder="rks_…" autocomplete="new-password" autocapitalize="off" spellcheck="false" required></p>';
		echo '<p class="rkc-muted">' . esc_html__( 'The secret is encrypted before it is saved. It is sent only to ResellingKori, as a signature, never as text.', 'resellingkori-connector' ) . '</p>';
		echo '<p><button type="submit" class="rkc-btn rkc-btn--primary">' . esc_html__( 'Save and continue', 'resellingkori-connector' ) . '</button></p></form>';
	}

	private static function step_3() {
		$c = RKC_Config::get();
		echo '<h2>' . esc_html__( 'Verify the connection', 'resellingkori-connector' ) . '</h2>';
		echo '<p>' . esc_html__( 'This makes a real signed request to ResellingKori and shows which store and sales agent your key belongs to. Check that they are yours.', 'resellingkori-connector' ) . '</p>';
		echo '<p><button type="button" class="rkc-btn rkc-btn--primary" data-rkc="verify" data-target="rkc-verify-result" data-next="rkc-next">' . esc_html__( 'Verify connection', 'resellingkori-connector' ) . '</button></p>';
		echo '<div id="rkc-verify-result" class="rkc-result" aria-live="polite">';
		if ( RKC_Config::connected() ) {
			echo '<div class="rkc-notice rkc-notice--success"><p><strong>' . esc_html__( 'Connected.', 'resellingkori-connector' ) . '</strong> ' . esc_html( sprintf( /* translators: 1: store, 2: agent */ __( 'Store: %1$s · Sales agent: %2$s', 'resellingkori-connector' ), '' !== $c['store_name'] ? $c['store_name'] : '—', '' !== $c['agent_name'] ? $c['agent_name'] : '—' ) ) . '</p></div>';
		}
		echo '</div>';
		echo '<p class="rkc-muted">' . esc_html__( 'Stuck? The most common cause is a typing mistake in the secret. Go back and enter it again.', 'resellingkori-connector' ) . '</p>';
		self::step_nav( 2, __( 'Continue', 'resellingkori-connector' ), 4, ! RKC_Config::connected() );
	}

	private static function step_4() {
		$map = RKC_Mapping::counts();
		echo '<h2>' . esc_html__( 'Link your products', 'resellingkori-connector' ) . '</h2>';
		echo '<p>' . esc_html__( 'Orders can only be sent for products that are linked to a ResellingKori product. The plugin links by SKU first (exact match). For everything else it suggests ResellingKori products with similar names, and you choose.', 'resellingkori-connector' ) . '</p>';
		echo '<div class="rkc-stats"><span class="rkc-stat"><span class="rkc-stat__n">' . (int) $map['mapped'] . '</span><span class="rkc-stat__l">' . esc_html__( 'linked', 'resellingkori-connector' ) . '</span></span><span class="rkc-stat"><span class="rkc-stat__n">' . (int) $map['unmapped'] . '</span><span class="rkc-stat__l">' . esc_html__( 'not linked', 'resellingkori-connector' ) . '</span></span></div>';
		if ( current_user_can( 'rkc_manage_mapping' ) ) {
			echo '<p class="rkc-actions"><button type="button" class="rkc-btn" data-rkc="bulk-sku-all" data-target="rkc-bulk-result">' . esc_html__( 'Match all by SKU now', 'resellingkori-connector' ) . '</button> <a class="rkc-btn rkc-btn--ghost" href="' . esc_url( RKC_Admin::url( 'products' ) ) . '">' . esc_html__( 'Open the product list', 'resellingkori-connector' ) . '</a></p>';
			echo '<div id="rkc-bulk-result" class="rkc-result" aria-live="polite"></div>';
		}
		echo '<p class="rkc-muted">' . esc_html__( 'You can do this later; nothing is sent automatically. Matches with very different names are never linked without your review.', 'resellingkori-connector' ) . '</p>';
		self::step_nav( 3, __( 'Continue', 'resellingkori-connector' ), 5 );
	}

	private static function step_5() {
		echo '<h2>' . esc_html__( 'Order preferences', 'resellingkori-connector' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sensible defaults are already selected. Change them only if you need to.', 'resellingkori-connector' ) . '</p>';
		self::preferences_form( true );
		self::step_nav( 4 );
	}

	private static function step_6() {
		$c   = RKC_Config::get();
		$map = RKC_Mapping::counts();
		echo '<h2>' . esc_html__( 'You are all set', 'resellingkori-connector' ) . '</h2>';
		echo '<ul class="rkc-checks">';
		echo '<li class="is-ok"><span class="rkc-checks__mark" aria-hidden="true">✔</span> ' . esc_html( sprintf( /* translators: %s: store name */ __( 'Connected as %s', 'resellingkori-connector' ), $c['store_name'] ) ) . '</li>';
		echo '<li class="' . ( $map['mapped'] ? 'is-ok' : 'is-warn' ) . '"><span class="rkc-checks__mark" aria-hidden="true">' . ( $map['mapped'] ? '✔' : '!' ) . '</span> ' . esc_html( sprintf( /* translators: %d: number of products */ _n( '%d product linked', '%d products linked', $map['mapped'], 'resellingkori-connector' ), $map['mapped'] ) ) . '</li>';
		echo '<li class="' . ( 'verified' === $c['callback_state'] ? 'is-ok' : 'is-warn' ) . '"><span class="rkc-checks__mark" aria-hidden="true">' . ( 'verified' === $c['callback_state'] ? '✔' : '!' ) . '</span> ' . esc_html( 'verified' === $c['callback_state'] ? __( 'Live status updates are active', 'resellingkori-connector' ) : __( 'Live updates are not active; the plugin checks every 15 minutes', 'resellingkori-connector' ) ) . '</li>';
		echo '</ul>';
		echo '<h3>' . esc_html__( 'How to send an order', 'resellingkori-connector' ) . '</h3><ol>';
		echo '<li>' . esc_html__( 'Open an order in WooCommerce > Orders.', 'resellingkori-connector' ) . '</li>';
		echo '<li>' . esc_html__( 'In the "ResellingKori" box press "Review and send".', 'resellingkori-connector' ) . '</li>';
		echo '<li>' . esc_html__( 'Check the items, your profit and the cash the courier will collect, then confirm.', 'resellingkori-connector' ) . '</li></ol>';
		echo '<p class="rkc-actions"><a class="rkc-btn rkc-btn--primary" href="' . esc_url( RKC_Admin::url( 'dashboard' ) ) . '">' . esc_html__( 'Go to the dashboard', 'resellingkori-connector' ) . '</a></p>';
		RKC_Config::mark( array( 'wizard_step' => 7 ) );
	}

	/* -------------------------------------------------------------- Settings */

	public static function settings() {
		$c = RKC_Config::get();

		echo '<section class="rkc-card" aria-labelledby="rkc-h-conn2"><h2 id="rkc-h-conn2">' . esc_html__( 'Connection', 'resellingkori-connector' ) . '</h2>';
		if ( RKC_Config::configured() ) {
			echo '<dl class="rkc-dl">';
			self::dl( __( 'Status', 'resellingkori-connector' ), self::connection_pill(), true );
			self::dl( __( 'Key', 'resellingkori-connector' ), RKC_Config::masked_key() . ' · ' . __( 'secret saved (hidden)', 'resellingkori-connector' ) );
			self::dl( __( 'Store', 'resellingkori-connector' ), '' !== $c['store_name'] ? $c['store_name'] : '—' );
			self::dl( __( 'Sales agent', 'resellingkori-connector' ), '' !== $c['agent_name'] ? $c['agent_name'] . ( '' !== $c['agent_hint'] ? ' (' . $c['agent_hint'] . ')' : '' ) : '—' );
			self::dl( __( 'Webhook address', 'resellingkori-connector' ), RKC_Webhook::url() );
			echo '</dl><p class="rkc-actions"><button type="button" class="rkc-btn" data-rkc="verify" data-target="rkc-settings-verify">' . esc_html__( 'Test connection', 'resellingkori-connector' ) . '</button></p><div id="rkc-settings-verify" class="rkc-result" aria-live="polite"></div>';
		} else {
			echo '<p>' . esc_html__( 'Not connected.', 'resellingkori-connector' ) . ' <a href="' . esc_url( RKC_Admin::url( 'setup' ) ) . '">' . esc_html__( 'Run the setup', 'resellingkori-connector' ) . '</a></p>';
		}
		if ( current_user_can( 'rkc_manage_credentials' ) ) {
			echo '<details class="rkc-details"><summary>' . esc_html__( 'Replace the key and secret (reconnect or key rotation)', 'resellingkori-connector' ) . '</summary>';
			echo '<p class="rkc-muted">' . esc_html__( 'When ResellingKori rotates or re-issues your key you receive a new Key ID and secret. Enter them here, then press "Test connection". The old key keeps working only for the grace period ResellingKori set, so do this promptly.', 'resellingkori-connector' ) . '</p>';
			self::credentials_form( 'settings' );
			echo '</details>';
			if ( RKC_Config::configured() ) {
				echo '<details class="rkc-details rkc-details--danger"><summary>' . esc_html__( 'Disconnect', 'resellingkori-connector' ) . '</summary>';
				echo '<p>' . esc_html__( 'Removes the key and secret from this site. Your product links and order history are kept. Orders cannot be sent until you connect again.', 'resellingkori-connector' ) . '</p>';
				self::post_form_open( 'credentials_disconnect', 'rkc-form' );
				echo '<button type="submit" class="rkc-btn rkc-btn--danger" data-confirm="' . esc_attr__( 'Disconnect from ResellingKori and remove the saved key and secret?', 'resellingkori-connector' ) . '">' . esc_html__( 'Disconnect', 'resellingkori-connector' ) . '</button></form></details>';
			}
		}
		echo '</section>';

		self::workspace_card();

		echo '<section class="rkc-card" aria-labelledby="rkc-h-pref"><h2 id="rkc-h-pref">' . esc_html__( 'Preferences', 'resellingkori-connector' ) . '</h2>';
		self::preferences_form( false );
		echo '</section>';

		echo '<section class="rkc-card" aria-labelledby="rkc-h-help"><h2 id="rkc-h-help">' . esc_html__( 'Help', 'resellingkori-connector' ) . '</h2><ul>';
		echo '<li>' . esc_html__( 'Step-by-step guides (setup, sending orders, troubleshooting) are in the "docs" folder that came with this plugin.', 'resellingkori-connector' ) . '</li>';
		echo '<li>' . esc_html__( 'If something does not work, open "Logs & diagnostics", copy the diagnostics (it never contains your secret) and send it to ResellingKori support.', 'resellingkori-connector' ) . '</li></ul></section>';
	}

	/**
	 * Preferences shared by the wizard (step 5) and the settings screen.
	 *
	 * @param bool $wizard Inside the wizard: after saving, continue to the last step.
	 */
	private static function preferences_form( $wizard ) {
		$s = RKC_Settings::get();
		self::post_form_open( 'settings_save', 'rkc-form rkc-prefs' );
		if ( $wizard ) {
			echo '<input type="hidden" name="rkc_wizard_next" value="1">';
		}

		echo '<fieldset><legend>' . esc_html__( 'Which orders may be sent', 'resellingkori-connector' ) . '</legend>';
		echo '<p class="rkc-muted">' . esc_html__( 'The "Send" button is offered only for orders in these statuses.', 'resellingkori-connector' ) . '</p>';
		echo '<input type="hidden" name="rkc[send_statuses][]" value="">';
		foreach ( RKC_Settings::statuses() as $slug => $label ) {
			printf( '<label class="rkc-check"><input type="checkbox" name="rkc[send_statuses][]" value="%s"%s> %s</label>', esc_attr( $slug ), checked( in_array( $slug, $s['send_statuses'], true ), true, false ), esc_html( $label ) );
		}
		echo '</fieldset>';

		echo '<fieldset><legend>' . esc_html__( 'Payment', 'resellingkori-connector' ) . '</legend>';
		echo '<p><label for="rkc_cod">' . esc_html__( 'Payment methods that mean "cash on delivery" (ids, comma separated)', 'resellingkori-connector' ) . '</label><br><input type="text" id="rkc_cod" name="rkc[cod_gateways]" class="regular-text rkc-input" value="' . esc_attr( $s['cod_gateways'] ) . '"></p>';
		echo '<p class="rkc-muted">' . esc_html__( 'Orders paid with any other method are sent as already paid: the courier collects only the delivery charge you confirm, if any.', 'resellingkori-connector' ) . '</p></fieldset>';

		echo '<fieldset><legend>' . esc_html__( 'After sending', 'resellingkori-connector' ) . '</legend>';
		echo '<p><label for="rkc_after">' . esc_html__( 'Change the order status to', 'resellingkori-connector' ) . '</label><br><select id="rkc_after" name="rkc[after_send]" class="rkc-input"><option value="">' . esc_html__( "— Don't change —", 'resellingkori-connector' ) . '</option>';
		foreach ( RKC_Settings::statuses() as $slug => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $s['after_send'], $slug, false ), esc_html( $label ) );
		}
		echo '</select></p><p class="rkc-muted">' . esc_html__( 'Orders that were only partly sent (mixed orders) never change status.', 'resellingkori-connector' ) . '</p></fieldset>';

		echo '<fieldset><legend>' . esc_html__( 'Orders with products from other suppliers (mixed orders)', 'resellingkori-connector' ) . '</legend>';
		echo '<p class="rkc-muted">' . esc_html__( 'When an order contains some ResellingKori products and some others, you choose which items to send. This sets the delivery charge suggested for that parcel.', 'resellingkori-connector' ) . '</p>';
		printf( '<label class="rkc-check"><input type="radio" name="rkc[partial_delivery]" value="none"%s> %s</label>', checked( 'none', $s['partial_delivery'], false ), esc_html__( 'Suggest no delivery charge (I will enter it)', 'resellingkori-connector' ) );
		printf( '<label class="rkc-check"><input type="radio" name="rkc[partial_delivery]" value="order"%s> %s</label>', checked( 'order', $s['partial_delivery'], false ), esc_html__( "Suggest the order's shipping charge", 'resellingkori-connector' ) );
		echo '</fieldset>';

		echo '<fieldset><legend>' . esc_html__( 'Status updates from ResellingKori', 'resellingkori-connector' ) . '</legend>';
		echo '<p class="rkc-muted">' . esc_html__( 'Choose how your own order status follows ResellingKori. Leave blank to only record the status and tracking on the order. Mixed orders are never changed.', 'resellingkori-connector' ) . '</p><div class="rkc-maprow">';
		foreach ( RKC_Settings::rk_statuses() as $rk => $label ) {
			echo '<label for="rkc_sm_' . esc_attr( $rk ) . '">' . esc_html( $label ) . '</label><select id="rkc_sm_' . esc_attr( $rk ) . '" name="rkc[status_map][' . esc_attr( $rk ) . ']" class="rkc-input"><option value="">' . esc_html__( "— Don't change —", 'resellingkori-connector' ) . '</option>';
			foreach ( RKC_Settings::statuses() as $slug => $wc_label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $s['status_map'][ $rk ], $slug, false ), esc_html( $wc_label ) );
			}
			echo '</select>';
		}
		echo '</div></fieldset>';

		echo '<fieldset><legend>' . esc_html__( 'Sales agent check (optional)', 'resellingkori-connector' ) . '</legend>';
		echo '<p><label for="rkc_agent">' . esc_html__( 'The e-mail of the sales agent you expect to be linked to your key', 'resellingkori-connector' ) . '</label><br><input type="email" id="rkc_agent" name="rkc[expected_agent_email]" class="regular-text rkc-input" value="' . esc_attr( $s['expected_agent_email'] ) . '" placeholder="agent@example.com"></p>';
		echo '<p class="rkc-muted">' . esc_html__( 'If set, orders are blocked when ResellingKori reports a different agent for your key. ResellingKori never receives this address except to compare it.', 'resellingkori-connector' ) . '</p></fieldset>';

		if ( $wizard ) {
			echo '<fieldset><legend>' . esc_html__( 'ResellingKori agent dashboard', 'resellingkori-connector' ) . '</legend>';
			echo '<p class="rkc-muted">' . esc_html( self::ws_disclosure() ) . '</p>';
			$on = ( 'no' !== $s['workspace_sync'] ); // Not decided yet counts as the recommended choice; the person confirms it by saving.
			echo '<input type="hidden" name="rkc[workspace_sync]" value="no"><label class="rkc-check"><input type="checkbox" name="rkc[workspace_sync]" value="yes"' . checked( true, $on, false ) . '> ' . esc_html__( 'Show this shop\'s ResellingKori orders and products in my ResellingKori agent dashboard', 'resellingkori-connector' ) . '</label>';
			echo '</fieldset>';
		}

		echo '<fieldset><legend>' . esc_html__( 'Notifications', 'resellingkori-connector' ) . '</legend>';
		echo '<input type="hidden" name="rkc[notify_failures]" value="no"><label class="rkc-check"><input type="checkbox" name="rkc[notify_failures]" value="yes"' . checked( 'yes', $s['notify_failures'], false ) . '> ' . esc_html__( 'E-mail me when an order cannot be confirmed or ResellingKori reports a problem', 'resellingkori-connector' ) . '</label>';
		echo '<p><label for="rkc_mail">' . esc_html__( 'Send to (blank = the site administrator e-mail)', 'resellingkori-connector' ) . '</label><br><input type="email" id="rkc_mail" name="rkc[notify_email]" class="regular-text rkc-input" value="' . esc_attr( $s['notify_email'] ) . '"></p></fieldset>';

		if ( ! $wizard ) {
			echo '<fieldset><legend>' . esc_html__( 'Product matching', 'resellingkori-connector' ) . '</legend>';
			echo '<input type="hidden" name="rkc[sku_safety_check]" value="no"><label class="rkc-check"><input type="checkbox" name="rkc[sku_safety_check]" value="yes"' . checked( 'yes', $s['sku_safety_check'], false ) . '> ' . esc_html__( 'Do not link automatically when the SKU matches but the product names look completely different (ask me instead)', 'resellingkori-connector' ) . '</label></fieldset>';

			echo '<fieldset><legend>' . esc_html__( 'Logs and data', 'resellingkori-connector' ) . '</legend><p class="rkc-fields">';
			echo '<label for="rkc_ll">' . esc_html__( 'Log detail', 'resellingkori-connector' ) . '</label><select id="rkc_ll" name="rkc[log_level]" class="rkc-input">';
			foreach ( array( 'error' => __( 'Errors only', 'resellingkori-connector' ), 'warning' => __( 'Warnings and errors', 'resellingkori-connector' ), 'info' => __( 'Normal (recommended)', 'resellingkori-connector' ), 'debug' => __( 'Everything (for troubleshooting)', 'resellingkori-connector' ) ) as $k => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $s['log_level'], $k, false ), esc_html( $label ) );
			}
			echo '</select> <label for="rkc_lr">' . esc_html__( 'Keep log entries for (days)', 'resellingkori-connector' ) . '</label><input type="number" id="rkc_lr" name="rkc[log_retention_days]" class="small-text rkc-input" min="1" max="365" value="' . esc_attr( (string) (int) $s['log_retention_days'] ) . '"></p>';
			echo '<input type="hidden" name="rkc[delete_data_on_uninstall]" value="no"><label class="rkc-check"><input type="checkbox" name="rkc[delete_data_on_uninstall]" value="yes"' . checked( 'yes', $s['delete_data_on_uninstall'], false ) . '> ' . esc_html__( 'Delete all plugin data (links, submission records, logs) when the plugin is deleted', 'resellingkori-connector' ) . '</label>';
			echo '<p class="rkc-muted">' . esc_html__( 'Your key and secret are always removed when the plugin is deleted.', 'resellingkori-connector' ) . '</p></fieldset>';
		}

		echo '<p><button type="submit" class="rkc-btn rkc-btn--primary">' . esc_html( $wizard ? __( 'Save and continue', 'resellingkori-connector' ) : __( 'Save preferences', 'resellingkori-connector' ) ) . '</button></p></form>';
	}

	/* ------------------------------------------------------ Agent dashboard */

	/** What leaves the shop when the dashboard sync is on - said in plain words wherever the choice is offered. */
	private static function ws_disclosure() {
		return __( 'Your ResellingKori agent dashboard can then list the orders of this shop that contain ResellingKori products, and let you send them, cancel them and link products from there. For that, this shop sends ResellingKori a short summary of those orders: order number, status, items and amounts, the customer\'s name and city and a partly hidden phone number. The e-mail address, the street address and the complete phone number are never included; they leave the shop only when an order is sent, as before. You can switch this off at any time and everything is deleted at ResellingKori.', 'resellingkori-connector' );
	}

	private static function ws_kind( array $ws ) {
		$map = array( 'ok' => 'good', 'warn' => 'warn', 'bad' => 'bad', 'off' => 'neutral' );
		return isset( $map[ $ws['kind'] ] ) ? $map[ $ws['kind'] ] : 'neutral';
	}

	private static function ws_label( array $ws ) {
		$map = array(
			'ok'   => __( 'Working', 'resellingkori-connector' ),
			'warn' => __( 'Waiting', 'resellingkori-connector' ),
			'bad'  => __( 'Problem', 'resellingkori-connector' ),
			'off'  => __( 'Off', 'resellingkori-connector' ),
		);
		return isset( $map[ $ws['kind'] ] ) ? $map[ $ws['kind'] ] : $map['off'];
	}

	private static function workspace_card() {
		echo '<section class="rkc-card" aria-labelledby="rkc-h-ws"><h2 id="rkc-h-ws">' . esc_html__( 'ResellingKori agent dashboard', 'resellingkori-connector' ) . '</h2>';
		if ( ! RKC_Config::configured() ) {
			echo '<p class="rkc-muted">' . esc_html__( 'Available after the shop is connected to ResellingKori.', 'resellingkori-connector' ) . '</p></section>';
			return;
		}
		$ws = RKC_Workspace::summary();
		$s  = RKC_Settings::get();
		echo '<p>' . esc_html( self::ws_disclosure() ) . '</p>';
		echo '<p>' . self::badge( self::ws_label( $ws ), self::ws_kind( $ws ) ) . ' ' . esc_html( $ws['text'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		if ( $ws['enabled'] ) {
			echo '<dl class="rkc-dl">';
			self::dl( __( 'Orders shown in the dashboard', 'resellingkori-connector' ), (string) $ws['orders'] );
			self::dl( __( 'Products shown in the dashboard', 'resellingkori-connector' ), (string) $ws['products'] );
			self::dl( __( 'Waiting to be reported', 'resellingkori-connector' ), (string) $ws['waiting'] );
			self::dl( __( 'Instructions carried out', 'resellingkori-connector' ), (string) $ws['executed'] );
			echo '</dl>';
		}
		if ( ! current_user_can( 'rkc_manage_connection' ) ) {
			echo '</section>';
			return;
		}
		self::post_form_open( 'settings_save', 'rkc-form' );
		echo '<input type="hidden" name="rkc_return" value="settings">';
		echo '<input type="hidden" name="rkc[workspace_sync]" value="no"><label class="rkc-check"><input type="checkbox" name="rkc[workspace_sync]" value="yes"' . checked( 'yes', $s['workspace_sync'], false ) . '> ' . esc_html__( 'Show this shop\'s ResellingKori orders and products in my ResellingKori agent dashboard', 'resellingkori-connector' ) . '</label>';
		echo '<input type="hidden" name="rkc[workspace_actions]" value="no"><label class="rkc-check"><input type="checkbox" name="rkc[workspace_actions]" value="yes"' . checked( 'yes', $s['workspace_actions'], false ) . '> ' . esc_html__( 'Let the dashboard send, cancel and link on this shop\'s behalf (leave off to only look)', 'resellingkori-connector' ) . '</label>';
		echo '<p class="rkc-muted">' . esc_html__( 'Switching the first box off tells ResellingKori to delete everything it holds from this shop. Deactivating or deleting the plugin does not contact ResellingKori: switch it off first if you want the data removed at once; otherwise it expires there.', 'resellingkori-connector' ) . '</p>';
		echo '<p><button type="submit" class="rkc-btn rkc-btn--primary">' . esc_html__( 'Save', 'resellingkori-connector' ) . '</button></p></form>';
		if ( $ws['enabled'] ) {
			self::post_form_open( 'workspace_sync_now', 'rkc-form' );
			echo '<p><button type="submit" class="rkc-btn">' . esc_html__( 'Sync now', 'resellingkori-connector' ) . '</button> <span class="rkc-muted">' . esc_html__( 'Checks for new instructions and sends the latest changes straight away. This happens automatically about once a minute.', 'resellingkori-connector' ) . '</span></p></form>';
		}
		echo '</section>';
	}

	/* -------------------------------------------------------------- Products */

	public static function products() {
		$allowed = array( 'all', 'mapped', 'unmapped', 'review', 'unavailable' );
		$filter  = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter  = in_array( $filter, $allowed, true ) ? $filter : 'all';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$counts  = RKC_Mapping::counts();
		$per     = 20;
		$q       = new WP_Query( RKC_Mapping::query_args( $filter, $search, $per, $paged ) );

		$labels = array(
			'all'         => __( 'All', 'resellingkori-connector' ),
			'mapped'      => __( 'Linked', 'resellingkori-connector' ),
			'unmapped'    => __( 'Not linked', 'resellingkori-connector' ),
			'review'      => __( 'Needs review', 'resellingkori-connector' ),
			'unavailable' => __( 'Unavailable at ResellingKori', 'resellingkori-connector' ),
		);
		echo '<ul class="rkc-filters" aria-label="' . esc_attr__( 'Filter products', 'resellingkori-connector' ) . '">';
		foreach ( $labels as $k => $label ) {
			printf(
				'<li><a class="%s" href="%s"%s>%s <span class="rkc-count">%d</span></a></li>',
				$k === $filter ? 'is-active' : '',
				esc_url( RKC_Admin::url( 'products', array( 'filter' => $k ) ) ),
				$k === $filter ? ' aria-current="true"' : '',
				esc_html( $label ),
				(int) $counts[ $k ]
			);
		}
		echo '</ul>';

		echo '<div class="rkc-toolbar"><form method="get" class="rkc-search" role="search"><input type="hidden" name="page" value="' . esc_attr( RKC_Admin::SLUG ) . '"><input type="hidden" name="tab" value="products"><input type="hidden" name="filter" value="' . esc_attr( $filter ) . '">';
		echo '<label class="screen-reader-text" for="rkc-search">' . esc_html__( 'Search your products by name or exact SKU', 'resellingkori-connector' ) . '</label><input type="search" id="rkc-search" name="s" class="rkc-input" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search by name or exact SKU', 'resellingkori-connector' ) . '"> <button type="submit" class="rkc-btn">' . esc_html__( 'Search', 'resellingkori-connector' ) . '</button></form>';
		echo '<div class="rkc-bulk" role="group" aria-label="' . esc_attr__( 'Bulk actions', 'resellingkori-connector' ) . '"><span class="rkc-muted">' . esc_html__( 'Selected:', 'resellingkori-connector' ) . '</span> ';
		echo '<button type="button" class="rkc-btn" data-rkc="bulk-sku-selected">' . esc_html__( 'Match by SKU', 'resellingkori-connector' ) . '</button> ';
		echo '<button type="button" class="rkc-btn" data-rkc="bulk-confirm">' . esc_html__( 'Confirm links', 'resellingkori-connector' ) . '</button> ';
		echo '<button type="button" class="rkc-btn rkc-btn--danger" data-rkc="bulk-unlink">' . esc_html__( 'Unlink', 'resellingkori-connector' ) . '</button></div></div>';
		echo '<p class="rkc-actions"><button type="button" class="rkc-btn" data-rkc="bulk-sku-all" data-target="rkc-bulk-result">' . esc_html__( 'Match every unlinked product by SKU', 'resellingkori-connector' ) . '</button> <button type="button" class="rkc-btn" data-rkc="refresh-availability" data-target="rkc-bulk-result">' . esc_html__( 'Check prices and availability', 'resellingkori-connector' ) . '</button></p>';
		echo '<div id="rkc-bulk-result" class="rkc-result" aria-live="polite"></div>';

		if ( ! $q->have_posts() ) {
			echo '<div class="rkc-card"><p>' . esc_html__( 'No products match this filter.', 'resellingkori-connector' ) . '</p></div>';
			return;
		}

		echo '<div class="rkc-tablewrap"><table class="rkc-table" id="rkc-products"><thead><tr><th class="rkc-check"><input type="checkbox" id="rkc-all" aria-label="' . esc_attr__( 'Select all on this page', 'resellingkori-connector' ) . '"></th><th>' . esc_html__( 'Your product', 'resellingkori-connector' ) . '</th><th>' . esc_html__( 'ResellingKori product', 'resellingkori-connector' ) . '</th><th class="rkc-col-actions"><span class="screen-reader-text">' . esc_html__( 'Actions', 'resellingkori-connector' ) . '</span></th></tr></thead><tbody>';
		foreach ( $q->posts as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				self::product_row( RKC_Mapping::describe( $p ) );
			}
		}
		echo '</tbody></table></div>';

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', RKC_Admin::url( 'products', array( 'filter' => $filter, 's' => $search ) ) ),
				'format'    => '',
				'current'   => $paged,
				'total'     => max( 1, (int) $q->max_num_pages ),
				'prev_text' => '‹',
				'next_text' => '›',
				'type'      => 'plain',
			)
		);
		if ( $links ) {
			echo '<nav class="rkc-pagination" aria-label="' . esc_attr__( 'Pagination', 'resellingkori-connector' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	public static function product_row( array $d ) {
		$m    = $d['map'];
		$edit = get_edit_post_link( $d['parent_id'] ? $d['parent_id'] : $d['id'], 'raw' );
		echo '<tr class="rkc-row" data-id="' . (int) $d['id'] . '" data-name="' . esc_attr( $d['name'] ) . '" data-sku="' . esc_attr( $d['sku'] ) . '">';
		echo '<td class="rkc-check"><input type="checkbox" class="rkc-sel" value="' . (int) $d['id'] . '" aria-label="' . esc_attr( sprintf( /* translators: %s: product name */ __( 'Select %s', 'resellingkori-connector' ), $d['name'] . ( '' !== $d['label'] ? ' — ' . $d['label'] : '' ) ) ) . '"></td>';
		echo '<td class="rkc-prod" data-label="' . esc_attr__( 'Your product', 'resellingkori-connector' ) . '">';
		self::thumb( $d['image'] );
		echo '<div class="rkc-prod__text"><a class="rkc-prod__name" href="' . esc_url( (string) $edit ) . '">' . esc_html( $d['name'] ) . '</a>';
		if ( '' !== $d['label'] ) {
			echo '<div class="rkc-sub">' . esc_html( $d['label'] ) . '</div>';
		}
		echo '<div class="rkc-meta">' . esc_html( sprintf( /* translators: 1: SKU, 2: ID */ __( 'SKU: %1$s · ID %2$d', 'resellingkori-connector' ), '' !== $d['sku'] ? $d['sku'] : '—', $d['id'] ) );
		if ( '' !== $d['price'] ) {
			echo ' · ' . esc_html( RKC_Money::display( RKC_Money::from_wc( $d['price'] ) ) );
		}
		echo '</div></div></td>';

		echo '<td class="rkc-map" data-label="' . esc_attr__( 'ResellingKori product', 'resellingkori-connector' ) . '">';
		if ( $m ) {
			self::thumb( $m['image'] );
			echo '<div class="rkc-prod__text">';
			if ( '' !== $m['url'] ) {
				echo '<a class="rkc-prod__name" href="' . esc_url( $m['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $m['name'] ) . '</a>';
			} else {
				echo '<span class="rkc-prod__name">' . esc_html( $m['name'] ) . '</span>';
			}
			echo '<div class="rkc-meta">' . esc_html( sprintf( /* translators: 1: SKU, 2: product id, 3: variation part */ __( 'SKU: %1$s · ID %2$d%3$s', 'resellingkori-connector' ), '' !== $m['sku'] ? $m['sku'] : '—', $m['rk_product_id'], $m['rk_variation_id'] ? ' / ' . $m['rk_variation_id'] : '' ) ) . '</div>';
			echo '<div class="rkc-badges">';
			if ( '' !== $m['base'] ) {
				echo self::badge( sprintf( /* translators: %s: price */ __( 'Base %s', 'resellingkori-connector' ), RKC_Money::bdt( (int) RKC_Money::parse( $m['base'] ) ) ), 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( 'out' === $m['avail'] ) {
				echo self::badge( __( 'Out of stock', 'resellingkori-connector' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'gone' === $m['avail'] ) {
				echo self::badge( __( 'No longer available', 'resellingkori-connector' ), 'bad' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'in' === $m['avail'] ) {
				echo self::badge( __( 'Available', 'resellingkori-connector' ), 'good' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			$src = array( 'sku' => __( 'by SKU', 'resellingkori-connector' ), 'keyword' => __( 'by name', 'resellingkori-connector' ), 'manual' => __( 'by hand', 'resellingkori-connector' ), 'attributes' => __( 'by variation', 'resellingkori-connector' ) );
			if ( isset( $src[ $m['source'] ] ) ) {
				echo self::badge( $src[ $m['source'] ], 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( $m['review'] ) {
				echo self::badge( __( 'Needs review', 'resellingkori-connector' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div></div>';
		} else {
			echo '<span class="rkc-muted">' . esc_html__( 'Not linked', 'resellingkori-connector' ) . '</span>';
		}
		echo '</td>';

		echo '<td class="rkc-col-actions">';
		echo '<button type="button" class="rkc-btn rkc-btn--small" data-rkc="open-link" aria-expanded="false">' . esc_html( $m ? __( 'Change', 'resellingkori-connector' ) : __( 'Link', 'resellingkori-connector' ) ) . '</button>';
		if ( $m && $m['review'] ) {
			echo ' <button type="button" class="rkc-btn rkc-btn--small rkc-btn--primary" data-rkc="confirm">' . esc_html__( 'Confirm', 'resellingkori-connector' ) . '</button>';
		}
		if ( $m ) {
			echo ' <button type="button" class="rkc-btn rkc-btn--small rkc-btn--ghost" data-rkc="unlink">' . esc_html__( 'Unlink', 'resellingkori-connector' ) . '</button>';
		}
		echo '</td></tr>';
	}

	private static function thumb( $url ) {
		if ( '' !== (string) $url ) {
			echo '<img class="rkc-thumb" src="' . esc_url( $url ) . '" alt="" width="48" height="48" loading="lazy">';
		} else {
			echo '<span class="rkc-thumb rkc-thumb--empty" aria-hidden="true"></span>';
		}
	}

	/* ---------------------------------------------------------------- Orders */

	public static function orders() {
		$state   = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$states  = array( 'accepted', 'unknown', 'rejected', 'cancelled' );
		$state   = in_array( $state, $states, true ) ? $state : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per     = 20;
		$counts  = RKC_Submit::counts();
		$total   = $state ? $counts[ $state ] : array_sum( $counts );
		$rows    = RKC_Submit::latest_rows( $state, $per, ( $paged - 1 ) * $per );

		$labels = array( '' => __( 'All', 'resellingkori-connector' ), 'accepted' => __( 'Sent', 'resellingkori-connector' ), 'unknown' => __( 'Waiting for confirmation', 'resellingkori-connector' ), 'rejected' => __( 'Refused', 'resellingkori-connector' ), 'cancelled' => __( 'Cancelled', 'resellingkori-connector' ) );
		echo '<ul class="rkc-filters">';
		foreach ( $labels as $k => $label ) {
			$n = '' === $k ? array_sum( $counts ) : $counts[ $k ];
			printf( '<li><a class="%s" href="%s">%s <span class="rkc-count">%d</span></a></li>', $k === $state ? 'is-active' : '', esc_url( RKC_Admin::url( 'orders', '' === $k ? array() : array( 'state' => $k ) ) ), esc_html( $label ), (int) $n );
		}
		echo '</ul>';

		if ( ! $rows ) {
			echo '<div class="rkc-card"><p>' . esc_html__( 'No orders here yet. Open an order in WooCommerce and use the "ResellingKori" box to send it.', 'resellingkori-connector' ) . '</p></div>';
			return;
		}
		echo '<div class="rkc-tablewrap"><table class="rkc-table"><thead><tr><th>' . esc_html__( 'Your order', 'resellingkori-connector' ) . '</th><th>' . esc_html__( 'Sent', 'resellingkori-connector' ) . '</th><th>' . esc_html__( 'ResellingKori', 'resellingkori-connector' ) . '</th><th>' . esc_html__( 'Status', 'resellingkori-connector' ) . '</th><th>' . esc_html__( 'Tracking', 'resellingkori-connector' ) . '</th><th class="rkc-num">' . esc_html__( 'Cash to collect', 'resellingkori-connector' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$order = wc_get_order( (int) $row->order_id );
			$trk   = $order ? $order->get_meta( '_rkc_tracking' ) : array();
			$trk   = is_array( $trk ) ? $trk : array();
			echo '<tr><td data-label="' . esc_attr__( 'Your order', 'resellingkori-connector' ) . '">' . self::order_link( $row->order_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( $row->partial ) {
				echo ' ' . self::badge( __( 'Selected items', 'resellingkori-connector' ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</td><td data-label="' . esc_attr__( 'Sent', 'resellingkori-connector' ) . '">' . esc_html( RKC_DB::local( $row->created_at ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'ResellingKori', 'resellingkori-connector' ) . '">' . ( $row->rk_order_number ? esc_html( '#' . $row->rk_order_number ) : '—' ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Status', 'resellingkori-connector' ) . '">' . self::state_badge( $row->state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( 'accepted' === $row->state && $order ) {
				echo ' ' . self::status_badge( (string) $order->get_meta( '_rkc_rk_status' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</td><td data-label="' . esc_attr__( 'Tracking', 'resellingkori-connector' ) . '">' . self::tracking_html( $trk ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="rkc-num" data-label="' . esc_attr__( 'Cash to collect', 'resellingkori-connector' ) . '">' . esc_html( RKC_Money::display( (int) $row->expected_cod ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		$links = paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', RKC_Admin::url( 'orders', $state ? array( 'state' => $state ) : array() ) ), 'format' => '', 'current' => $paged, 'total' => max( 1, (int) ceil( $total / $per ) ), 'prev_text' => '‹', 'next_text' => '›' ) );
		if ( $links ) {
			echo '<nav class="rkc-pagination" aria-label="' . esc_attr__( 'Pagination', 'resellingkori-connector' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
	}

	/** Tracking number as text, linked only when the URL is http(s). */
	public static function tracking_html( array $t ) {
		if ( empty( $t['number'] ) ) {
			return '—';
		}
		$text = trim( ( isset( $t['courier'] ) ? $t['courier'] . ' ' : '' ) . $t['number'] );
		if ( ! empty( $t['url'] ) && preg_match( '#^https?://#i', $t['url'] ) ) {
			return '<a href="' . esc_url( $t['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
		}
		return esc_html( $text );
	}

	/* ------------------------------------------------------------------ Logs */

	public static function logs() {
		$f      = RKC_Admin::log_filters( false );
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per    = 50;
		$q      = RKC_Logger::query( $f + array( 'limit' => $per, 'offset' => ( $paged - 1 ) * $per ) );

		echo '<section class="rkc-card" aria-labelledby="rkc-h-diag"><h2 id="rkc-h-diag">' . esc_html__( 'Diagnostics', 'resellingkori-connector' ) . '</h2>';
		echo '<p class="rkc-muted">' . esc_html__( 'Safe to share with support. It never contains your secret, key or signatures.', 'resellingkori-connector' ) . '</p>';
		$diag = RKC_Connect::diagnostics();
		$text = '';
		echo '<dl class="rkc-dl rkc-dl--compact">';
		foreach ( $diag as $label => $value ) {
			$text .= $label . ': ' . $value . "\n";
			self::dl( $label, (string) $value );
		}
		echo '</dl><p><button type="button" class="rkc-btn" data-rkc="copy" data-copy="rkc-diag-text">' . esc_html__( 'Copy diagnostics', 'resellingkori-connector' ) . '</button></p>';
		echo '<textarea id="rkc-diag-text" class="rkc-sr-only" readonly tabindex="-1" aria-hidden="true">' . esc_textarea( $text ) . '</textarea></section>';

		echo '<section class="rkc-card" aria-labelledby="rkc-h-log"><h2 id="rkc-h-log">' . esc_html__( 'Log', 'resellingkori-connector' ) . '</h2>';
		echo '<form method="get" class="rkc-toolbar"><input type="hidden" name="page" value="' . esc_attr( RKC_Admin::SLUG ) . '"><input type="hidden" name="tab" value="logs">';
		echo '<label class="screen-reader-text" for="rkc-lv">' . esc_html__( 'Level', 'resellingkori-connector' ) . '</label><select id="rkc-lv" name="level" class="rkc-input"><option value="">' . esc_html__( 'All levels', 'resellingkori-connector' ) . '</option>';
		foreach ( array_keys( RKC_Logger::LEVELS ) as $lv ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $lv ), selected( $f['level'], $lv, false ), esc_html( ucfirst( $lv ) ) );
		}
		echo '</select> <label class="screen-reader-text" for="rkc-lo">' . esc_html__( 'Order ID', 'resellingkori-connector' ) . '</label><input type="number" id="rkc-lo" name="order_id" class="rkc-input small-text" min="1" value="' . ( $f['order_id'] ? esc_attr( (string) $f['order_id'] ) : '' ) . '" placeholder="' . esc_attr__( 'Order ID', 'resellingkori-connector' ) . '"> ';
		echo '<label class="screen-reader-text" for="rkc-ls">' . esc_html__( 'Search', 'resellingkori-connector' ) . '</label><input type="search" id="rkc-ls" name="s" class="rkc-input" value="' . esc_attr( $f['search'] ) . '" placeholder="' . esc_attr__( 'Search', 'resellingkori-connector' ) . '"> <button type="submit" class="rkc-btn">' . esc_html__( 'Filter', 'resellingkori-connector' ) . '</button></form>';
		echo '<p class="rkc-actions"><a class="rkc-btn" href="' . esc_url( self::action_url( 'logs_export', array_filter( array( 'level' => $f['level'], 'order_id' => $f['order_id'], 's' => $f['search'] ) ) ) ) . '">' . esc_html__( 'Export CSV', 'resellingkori-connector' ) . '</a> ';
		if ( current_user_can( 'rkc_manage_connection' ) ) {
			self::post_form_open( 'logs_clear', 'rkc-inline' );
			echo '<button type="submit" class="rkc-btn rkc-btn--danger" data-confirm="' . esc_attr__( 'Delete all log entries?', 'resellingkori-connector' ) . '">' . esc_html__( 'Clear the log', 'resellingkori-connector' ) . '</button></form>';
		}
		echo '</p>';

		if ( ! $q['rows'] ) {
			echo '<p class="rkc-muted">' . esc_html__( 'No log entries.', 'resellingkori-connector' ) . '</p></section>';
			return;
		}
		echo '<div class="rkc-tablewrap"><table class="rkc-table rkc-table--log"><thead><tr><th>' . esc_html__( 'Time', 'resellingkori-connector' ) . '</th><th>' . esc_html__( 'Level', 'resellingkori-connector' ) . '</th><th>' . esc_html__( 'Message', 'resellingkori-connector' ) . '</th><th>' . esc_html__( 'Order', 'resellingkori-connector' ) . '</th></tr></thead><tbody>';
		foreach ( $q['rows'] as $row ) {
			echo '<tr><td data-label="' . esc_attr__( 'Time', 'resellingkori-connector' ) . '">' . esc_html( RKC_DB::local( $row->created_at, 'Y-m-d H:i:s' ) ) . '</td><td data-label="' . esc_attr__( 'Level', 'resellingkori-connector' ) . '">' . self::level_badge( $row->level ) . '</td><td data-label="' . esc_attr__( 'Message', 'resellingkori-connector' ) . '">' . esc_html( $row->message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( ! empty( $row->context ) ) {
				echo '<details class="rkc-details"><summary>' . esc_html__( 'Details', 'resellingkori-connector' ) . '</summary><pre class="rkc-pre">' . esc_html( (string) $row->context ) . '</pre></details>';
			}
			echo '</td><td data-label="' . esc_attr__( 'Order', 'resellingkori-connector' ) . '">' . ( $row->order_id ? self::order_link( $row->order_id ) : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div>';
		$links = paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', RKC_Admin::url( 'logs', array_filter( array( 'level' => $f['level'], 'order_id' => $f['order_id'], 's' => $f['search'] ) ) ) ), 'format' => '', 'current' => $paged, 'total' => max( 1, (int) ceil( $q['total'] / $per ) ), 'prev_text' => '‹', 'next_text' => '›' ) );
		if ( $links ) {
			echo '<nav class="rkc-pagination" aria-label="' . esc_attr__( 'Pagination', 'resellingkori-connector' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}
		echo '</section>';
	}
}
