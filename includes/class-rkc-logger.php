<?php
/**
 * Thin wrapper around the WooCommerce logger (WooCommerce > Status > Logs).
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Logger {

	/**
	 * Errors are always logged; everything else only when debug logging is enabled.
	 *
	 * @param string $level   WC log level (error, warning, info, debug).
	 * @param string $message Message.
	 * @param array  $context Extra context.
	 */
	public static function log( $level, $message, array $context = array() ) {
		if ( 'error' !== $level && 'yes' !== RKC_Settings::get( 'debug' ) ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			$context['source'] = 'resellingkori-connector';
			wc_get_logger()->log( $level, $message, $context );
		}
	}

	public static function error( $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	public static function debug( $message, array $context = array() ) {
		self::log( 'debug', $message, $context );
	}
}
