<?php
/**
 * Runs when the plugin is DELETED from the Plugins screen (not on deactivation).
 *
 * Always removed: the saved API credentials (key id and encrypted secret), scheduled jobs, the agent-dashboard sync state,
 * temporary data. Nothing is sent to ResellingKori from here: to remove what the agent dashboard holds, switch the dashboard
 * sync off in Settings BEFORE deleting the plugin (it is then deleted at ResellingKori at once; otherwise it expires there).
 * Removed only if "Delete all plugin data when the plugin is deleted" is ON in Settings: the order submission records,
 * event log, diagnostic log, settings, capabilities and the product mappings / order information saved as meta.
 * Otherwise they are kept so that reinstalling the plugin does not lose any work.
 *
 * @package ResellingKori_Connector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$settings = get_option( 'rkc_settings', array() );
$wipe     = is_array( $settings ) && isset( $settings['delete_data_on_uninstall'] ) && 'yes' === $settings['delete_data_on_uninstall'];

// Credentials are never left behind.
delete_option( 'rkc_connection' );
delete_option( 'rkc_limits' );
delete_transient( 'rkc_clock_offset' );
wp_clear_scheduled_hook( 'rkc_poll_orders' );
wp_clear_scheduled_hook( 'rkc_daily' );
wp_clear_scheduled_hook( 'rkc_ws_tick' );
wp_clear_scheduled_hook( 'rkc_ws_kick' );
delete_option( 'rkc_ws_state' );

// Review caches, throttles and flash messages. The names are matched in PHP, not with an escaped LIKE pattern, so that this
// behaves identically on MySQL, MariaDB and SQLite.
$rkc_candidates = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%rkc%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( (array) $rkc_candidates as $rkc_option_name ) {
	if ( 0 === strpos( $rkc_option_name, '_transient_rkc_' ) || 0 === strpos( $rkc_option_name, '_transient_timeout_rkc_' ) ) {
		delete_option( $rkc_option_name );
	}
}

if ( ! $wipe ) {
	return;
}

delete_option( 'rkc_settings' );
delete_option( 'rkc_db_version' );
delete_option( 'rkc_version' );

foreach ( array( 'submissions', 'events', 'nonces', 'log', 'ws_items', 'ws_cmd' ) as $table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'rkc_' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
}

// Product mappings.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_rkc\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Order information (HPOS and legacy storage).
$orders_meta = $wpdb->prefix . 'wc_orders_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta ) ) === $orders_meta ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$orders_meta} WHERE meta_key LIKE '\_rkc\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

// Capabilities.
foreach ( array( 'rkc_manage_connection', 'rkc_manage_credentials', 'rkc_manage_mapping', 'rkc_submit_orders', 'rkc_view_orders', 'rkc_view_logs' ) as $cap ) {
	foreach ( wp_roles()->role_objects as $role ) {
		$role->remove_cap( $cap );
	}
}
