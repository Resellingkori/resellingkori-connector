<?php
/**
 * Schema, capabilities, scheduled jobs and the v0.2.0 -> v1.0.0 migration.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Install {

	/** All capabilities of this plugin. None of them is implied by manage_woocommerce. */
	const CAPS = array(
		'rkc_manage_connection',  // Setup wizard, settings, test/reconnect.
		'rkc_manage_credentials', // Enter, replace or remove the API key and secret.
		'rkc_manage_mapping',     // Link / unlink products.
		'rkc_submit_orders',      // Send, retry and cancel orders at ResellingKori.
		'rkc_view_orders',        // See ResellingKori status on orders and the dashboard.
		'rkc_view_logs',          // Logs and diagnostics.
	);

	/** @return array role => capabilities. Filterable: add_filter( 'rkc_role_caps', ... ). */
	public static function role_caps() {
		return (array) apply_filters(
			'rkc_role_caps',
			array(
				'administrator' => self::CAPS,
				'shop_manager'  => array( 'rkc_manage_mapping', 'rkc_submit_orders', 'rkc_view_orders' ),
			)
		);
	}

	public static function add_caps() {
		foreach ( self::role_caps() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( (array) $caps as $cap ) {
					if ( ! $role->has_cap( $cap ) ) {
						$role->add_cap( $cap );
					}
				}
			}
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$p = $wpdb->prefix . 'rkc_';
		$c = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$p}submissions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  attempt smallint(5) unsigned NOT NULL DEFAULT 1,
  external_id varchar(64) NOT NULL,
  state varchar(16) NOT NULL DEFAULT 'claimed',
  fingerprint varchar(64) NOT NULL DEFAULT '',
  partial tinyint(1) NOT NULL DEFAULT 0,
  payload longtext NOT NULL,
  expected_cod bigint(20) NOT NULL DEFAULT 0,
  rk_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  rk_order_number varchar(64) NOT NULL DEFAULT '',
  http_status smallint(5) unsigned NOT NULL DEFAULT 0,
  error_code varchar(64) NOT NULL DEFAULT '',
  error_message varchar(500) NOT NULL DEFAULT '',
  tries smallint(5) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_attempt (order_id,attempt),
  UNIQUE KEY external_id (external_id),
  KEY state (state)
) {$c};" );

		dbDelta( "CREATE TABLE {$p}events (
  event_id varchar(48) NOT NULL,
  received_at datetime NOT NULL,
  type varchar(40) NOT NULL DEFAULT '',
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sequence int(10) unsigned NOT NULL DEFAULT 0,
  outcome varchar(24) NOT NULL DEFAULT '',
  PRIMARY KEY  (event_id),
  KEY received_at (received_at)
) {$c};" );

		dbDelta( "CREATE TABLE {$p}nonces (
  nonce varchar(64) NOT NULL,
  expires_at bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (nonce),
  KEY expires_at (expires_at)
) {$c};" );

		// Agent dashboard (1.1.0): what still has to be reported, and the instructions this shop has received.
		dbDelta( "CREATE TABLE {$p}ws_items (
  kind char(1) NOT NULL,
  ref bigint(20) unsigned NOT NULL,
  dirty_at bigint(20) unsigned NOT NULL DEFAULT 0,
  known tinyint(1) NOT NULL DEFAULT 0,
  want_gone tinyint(1) NOT NULL DEFAULT 0,
  rev varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (kind,ref),
  KEY dirty_at (dirty_at)
) {$c};" );

		dbDelta( "CREATE TABLE {$p}ws_cmd (
  seq bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  cmd_id varchar(32) NOT NULL,
  type varchar(24) NOT NULL DEFAULT '',
  target varchar(40) NOT NULL DEFAULT '',
  payload longtext NOT NULL,
  state varchar(12) NOT NULL DEFAULT 'queued',
  result text,
  reported tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  started_at datetime DEFAULT NULL,
  finished_at datetime DEFAULT NULL,
  PRIMARY KEY  (seq),
  UNIQUE KEY cmd_id (cmd_id),
  KEY state (state)
) {$c};" );

		dbDelta( "CREATE TABLE {$p}log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  level varchar(10) NOT NULL DEFAULT 'info',
  message varchar(500) NOT NULL DEFAULT '',
  context text,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY level (level),
  KEY order_id (order_id)
) {$c};" );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( 'rkc_poll_orders' ) ) {
			wp_schedule_event( time() + 300, 'rkc_15min', 'rkc_poll_orders' );
		}
		if ( ! wp_next_scheduled( 'rkc_daily' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'rkc_daily' );
		}
		if ( ! wp_next_scheduled( 'rkc_ws_tick' ) ) {
			wp_schedule_event( time() + 90, 'rkc_minute', 'rkc_ws_tick' );
		}
	}

	public static function activate() {
		self::install();
		self::add_caps();
		self::migrate_legacy();
		self::schedule();
		update_option( 'rkc_db_version', RKC_DB_VERSION, false );
		update_option( 'rkc_version', RKC_VERSION, false );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'rkc_poll_orders' );
		wp_clear_scheduled_hook( 'rkc_daily' );
		wp_clear_scheduled_hook( 'rkc_ws_tick' );
		wp_clear_scheduled_hook( 'rkc_ws_kick' );
	}

	/** Runs on init: upgrades from older versions and repairs missing pieces. */
	public static function maybe_upgrade() {
		if ( get_option( 'rkc_db_version' ) !== RKC_DB_VERSION || get_option( 'rkc_version' ) !== RKC_VERSION ) {
			$from = (string) get_option( 'rkc_version', '' );
			self::install();
			self::add_caps();
			self::migrate_legacy();
			update_option( 'rkc_db_version', RKC_DB_VERSION, false );
			update_option( 'rkc_version', RKC_VERSION, false );
			self::on_upgrade( $from );
		}
		self::schedule();
	}

	/**
	 * Runs once when this version is first loaded over an older one. A shop that already used 1.0.0 has not been asked
	 * about the agent dashboard, so nothing is sent until its administrator says yes (an admin notice offers it).
	 */
	private static function on_upgrade( $from ) {
		if ( class_exists( 'RKC_Workspace' ) ) {
			RKC_Workspace::on_upgrade( (string) $from );
		}
	}

	/**
	 * v0.2.0 kept the API key and secret (plaintext) in the rkc_settings option. Move them into the encrypted
	 * connection record, erase the plaintext, and keep the agent email as an expectation to verify.
	 */
	public static function migrate_legacy() {
		$old = get_option( RKC_Settings::OPTION, array() );
		if ( ! is_array( $old ) ) {
			return;
		}
		$had = isset( $old['secret'] ) || isset( $old['key_id'] ) || isset( $old['api_base'] ) || isset( $old['agent_email'] ) || isset( $old['debug'] );
		if ( ! $had ) {
			return;
		}
		$moved = false;
		if ( ! RKC_Config::configured() && ! empty( $old['key_id'] ) && ! empty( $old['secret'] ) && RKC_Crypto::available() ) {
			$res = RKC_Config::save_credentials( (string) $old['key_id'], (string) $old['secret'] );
			if ( true === $res ) {
				$moved = true;
				RKC_Config::mark( array( 'wizard_step' => 3 ) );
			} else {
				RKC_Logger::warning( 'Legacy credentials could not be migrated: ' . $res->get_error_message() );
			}
		}
		if ( ! empty( $old['agent_email'] ) && empty( $old['expected_agent_email'] ) ) {
			$old['expected_agent_email'] = sanitize_email( (string) $old['agent_email'] );
		}
		if ( ! empty( $old['api_base'] ) && untrailingslashit( (string) $old['api_base'] ) !== RKC_Config::PRODUCTION_BASE ) {
			RKC_Logger::warning( 'A custom API address from v0.2.0 was ignored: the connector always uses ' . RKC_Config::PRODUCTION_BASE . '.' );
		}
		// Erase plaintext secrets and settings that no longer exist.
		foreach ( array( 'secret', 'key_id', 'api_base', 'agent_email', 'debug' ) as $gone ) {
			unset( $old[ $gone ] );
		}
		update_option( RKC_Settings::OPTION, $old );
		if ( $moved ) {
			RKC_Logger::info( 'Migrated the v0.2.0 key to encrypted storage and erased the plaintext secret. Verify the connection once (WooCommerce > ResellingKori).' );
		}
	}

	/** Daily housekeeping. */
	public static function housekeeping() {
		global $wpdb;
		RKC_Logger::purge();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RKC_DB::t( 'nonces' ) . ' WHERE expires_at < %d', time() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RKC_DB::t( 'events' ) . ' WHERE received_at < %s', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( class_exists( 'RKC_Workspace' ) ) {
			RKC_Workspace::housekeeping();
		}
	}
}
