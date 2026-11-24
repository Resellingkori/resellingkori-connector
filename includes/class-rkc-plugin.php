<?php
/**
 * Plugin bootstrap: loads the classes and wires the hooks.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

final class RKC_Plugin {

	/** @var RKC_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Core classes (needed by activation, cron and requests alike). */
	private static function load_core() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$dir  = RKC_DIR . 'includes/';
		foreach ( array( 'crypto', 'signer', 'redact', 'money', 'db', 'config', 'settings', 'logger', 'install', 'api-client', 'matcher', 'mapping', 'orders', 'submit', 'webhook', 'connect', 'workspace', 'workspace-exec', 'privacy', 'stock', 'import' ) as $file ) {
			require_once $dir . 'class-rkc-' . $file . '.php';
		}
	}

	/** The 15 minute schedule used for status polling and recovery sweeps, and the one minute schedule of the agent-dashboard sync. */
	public static function cron_schedules( $schedules ) {
		$schedules['rkc_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (ResellingKori)', 'resellingkori-connector' ),
		);
		$schedules['rkc_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (ResellingKori)', 'resellingkori-connector' ),
		);
		return $schedules;
	}

	public static function activate() {
		self::load_core();
		RKC_Install::activate();
	}

	public static function deactivate() {
		self::load_core();
		RKC_Install::deactivate();
	}

	private function __construct() {
		self::load_core();
		load_plugin_textdomain( 'resellingkori-connector', false, dirname( plugin_basename( RKC_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		add_action( 'init', array( 'RKC_Install', 'maybe_upgrade' ), 20 );
		add_action( 'rkc_poll_orders', array( 'RKC_Webhook', 'cron_poll' ) );
		add_action( 'rkc_recover_submission', array( 'RKC_Submit', 'cron_recover' ) );
		add_action( 'rkc_daily', array( 'RKC_Install', 'housekeeping' ) );
		add_action( 'rkc_ws_tick', array( 'RKC_Workspace', 'cron_tick' ) );
		add_action( 'rkc_ws_kick', array( 'RKC_Workspace', 'cron_tick' ) );
		add_action( 'rkc_import_media', array( 'RKC_Import', 'cron_media' ) );

		RKC_Webhook::init();
		RKC_Workspace::init();
		RKC_Stock::init();
		RKC_Privacy::init();

		if ( is_admin() ) {
			require_once RKC_DIR . 'includes/admin/class-rkc-admin.php';
			RKC_Admin::init();
		}
	}

	public function missing_woocommerce_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'ResellingKori Fulfillment Connector requires WooCommerce to be installed and active.', 'resellingkori-connector' );
		echo '</p></div>';
	}
}

// Registered when the file loads so that activation can schedule the 15 minute and one minute events.
add_filter( 'cron_schedules', array( 'RKC_Plugin', 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
