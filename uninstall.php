<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Settings and the cron event are removed. Product mappings (post meta) and the
 * data saved on orders are deliberately kept so reinstalling does not lose work.
 *
 * @package ResellingKori_Connector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'rkc_settings' );
delete_transient( 'rkc_conn' );
wp_clear_scheduled_hook( 'rkc_poll_orders' );
