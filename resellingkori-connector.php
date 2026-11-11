<?php
/**
 * Plugin Name:       ResellingKori Fulfillment Connector
 * Plugin URI:        https://resellingkori.com/
 * Description:       Map your WooCommerce products to ResellingKori products (SKU first, product-name keyword suggestions second) and send orders to ResellingKori for fulfillment with one click. Order status and tracking sync back to your store.
 * Version:           0.2.0
 * Author:            ResellingKori
 * License:           GPL-2.0-or-later
 * Text Domain:       resellingkori-connector
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

define( 'RKC_VERSION', '0.2.0' );
define( 'RKC_FILE', __FILE__ );
define( 'RKC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RKC_URL', plugin_dir_url( __FILE__ ) );

// Declare compatibility with High-Performance Order Storage (custom order tables).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RKC_FILE, true );
		}
	}
);

// 15-minute schedule used by the status-polling fallback.
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['rkc_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'resellingkori-connector' ),
		);
		return $schedules;
	}
);

require_once RKC_DIR . 'includes/class-rkc-plugin.php';

register_activation_hook( __FILE__, array( 'RKC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RKC_Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'RKC_Plugin', 'instance' ) );
