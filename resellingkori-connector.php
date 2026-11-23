<?php
/**
 * Plugin Name:       ResellingKori Fulfillment Connector
 * Plugin URI:        https://resellingkori.com/
 * Description:       Connect your WooCommerce store to ResellingKori: match your products (SKU first, name suggestions second), send paid orders for fulfilment with your own selling prices, receive status and tracking back automatically, and (optionally) manage the same from your ResellingKori agent dashboard.
 * Version:           1.1.0
 * Author:            ResellingKori
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       resellingkori-connector
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

define( 'RKC_VERSION', '1.1.0' );
define( 'RKC_DB_VERSION', '2' );
define( 'RKC_FILE', __FILE__ );
define( 'RKC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RKC_URL', plugin_dir_url( __FILE__ ) );

// Compatible with High-Performance Order Storage (custom order tables).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RKC_FILE, true );
		}
	}
);

require_once RKC_DIR . 'includes/class-rkc-plugin.php';

register_activation_hook( __FILE__, array( 'RKC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RKC_Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'RKC_Plugin', 'instance' ) );
