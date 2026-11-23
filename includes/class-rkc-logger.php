<?php
/**
 * Diagnostic log (own table). Everything is redacted BEFORE it is stored: secrets, signatures, nonces and
 * authorisation headers never reach the log; phone numbers are masked. Old rows are purged automatically.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Logger {

	const LEVELS = array( 'debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3 );

	public static function log( $level, $message, array $context = array(), $order_id = 0 ) {
		global $wpdb;
		$level = isset( self::LEVELS[ $level ] ) ? $level : 'info';
		$min   = (string) RKC_Settings::get( 'log_level' );
		if ( self::LEVELS[ $level ] < ( isset( self::LEVELS[ $min ] ) ? self::LEVELS[ $min ] : 1 ) ) {
			return;
		}
		$ctx = $context ? wp_json_encode( RKC_Redact::data( $context ) ) : null;
		$prev = $wpdb->suppress_errors( true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			RKC_DB::t( 'log' ),
			array(
				'created_at' => RKC_DB::now(),
				'level'      => $level,
				'message'    => substr( RKC_Redact::text( (string) $message ), 0, 500 ),
				'context'    => is_string( $ctx ) ? substr( $ctx, 0, 4000 ) : null,
				'order_id'   => (int) $order_id,
				'user_id'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d' )
		);
		$wpdb->suppress_errors( $prev );
	}

	public static function error( $m, array $c = array(), $o = 0 ) { self::log( 'error', $m, $c, $o ); }
	public static function warning( $m, array $c = array(), $o = 0 ) { self::log( 'warning', $m, $c, $o ); }
	public static function info( $m, array $c = array(), $o = 0 ) { self::log( 'info', $m, $c, $o ); }
	public static function debug( $m, array $c = array(), $o = 0 ) { self::log( 'debug', $m, $c, $o ); }

	/** @return array { rows, total } */
	public static function query( array $a = array() ) {
		global $wpdb;
		$t     = RKC_DB::t( 'log' );
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $a['level'] ) && isset( self::LEVELS[ $a['level'] ] ) ) {
			$where[] = 'level = %s';
			$vals[]  = $a['level'];
		}
		if ( ! empty( $a['order_id'] ) ) {
			$where[] = 'order_id = %d';
			$vals[]  = (int) $a['order_id'];
		}
		if ( ! empty( $a['search'] ) ) {
			$where[] = 'message LIKE %s';
			$vals[]  = '%' . $wpdb->esc_like( (string) $a['search'] ) . '%';
		}
		$sql   = implode( ' AND ', $where );
		$limit = isset( $a['limit'] ) ? max( 1, min( 1000, (int) $a['limit'] ) ) : 50;
		$off   = isset( $a['offset'] ) ? max( 0, (int) $a['offset'] ) : 0;
		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$sql}", $vals ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$sql}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$v2    = array_merge( $vals, array( $limit, $off ) );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", $v2 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return array( 'rows' => $rows, 'total' => $total );
	}

	public static function purge( $days = null ) {
		global $wpdb;
		$days = null === $days ? (int) RKC_Settings::get( 'log_retention_days' ) : (int) $days;
		$t    = RKC_DB::t( 'log' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . RKC_DB::t( 'log' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}
}
