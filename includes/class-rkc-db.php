<?php
/**
 * Small database helpers.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_DB {

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'rkc_' . $name;
	}

	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** Insert a row; true only if THIS call created it (false on duplicate key). */
	public static function insert_unique( $table, array $data, array $format ) {
		global $wpdb;
		$prev = $wpdb->suppress_errors( true );
		$res  = $wpdb->insert( self::t( $table ), $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $prev );
		return ( false !== $res && 1 === (int) $res );
	}

	/** UTC MySQL datetime -> site-local display. */
	public static function local( $mysql_utc, $format = 'Y-m-d H:i' ) {
		$ts = $mysql_utc ? strtotime( $mysql_utc . ' UTC' ) : 0;
		return $ts ? wp_date( $format, $ts ) : '—';
	}

	public static function ago( $ts ) {
		$ts = is_numeric( $ts ) ? (int) $ts : ( $ts ? (int) strtotime( $ts . ' UTC' ) : 0 );
		/* translators: %s: time difference such as "5 minutes" */
		return $ts ? sprintf( __( '%s ago', 'resellingkori-connector' ), human_time_diff( $ts, time() ) ) : '—';
	}
}
