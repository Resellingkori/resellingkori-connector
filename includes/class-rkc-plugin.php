<?php
/**
 * Plugin bootstrap.
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

	public static function activate() {
		if ( ! wp_next_scheduled( 'rkc_poll_orders' ) ) {
			wp_schedule_event( time() + 300, 'rkc_15min', 'rkc_poll_orders' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'rkc_poll_orders' );
	}

	private function __construct() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		$dir = RKC_DIR . 'includes/';
		require_once $dir . 'class-rkc-logger.php';
		require_once $dir . 'class-rkc-settings.php';
		require_once $dir . 'class-rkc-signer.php';
		require_once $dir . 'class-rkc-api-client.php';
		require_once $dir . 'class-rkc-matcher.php';
		require_once $dir . 'class-rkc-mapping.php';
		require_once $dir . 'class-rkc-orders.php';
		require_once $dir . 'class-rkc-sync.php';
		require_once $dir . 'admin/class-rkc-admin.php';

		RKC_Settings::init();
		RKC_Orders::init();
		RKC_Sync::init();
		RKC_Admin::init();
	}

	public function missing_woocommerce_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'ResellingKori Fulfillment Connector requires WooCommerce to be installed and active.', 'resellingkori-connector' );
		echo '</p></div>';
	}
}
