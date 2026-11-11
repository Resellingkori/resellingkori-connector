<?php
/**
 * Exact money arithmetic in integer minor units (paisa). No floating point anywhere in the order contract.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Money {

	const MAX_MINOR = 100000000000;

	/** Parse "830", "830.5", 830.5 ... into minor units, or null when malformed. */
	public static function parse( $value ) {
		if ( is_int( $value ) ) {
			$s = (string) $value;
		} elseif ( is_float( $value ) ) {
			if ( is_nan( $value ) || is_infinite( $value ) ) {
				return null;
			}
			$s = rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
			$s = '' === $s ? '0' : $s;
		} elseif ( is_string( $value ) ) {
			$s = trim( $value );
		} else {
			return null;
		}
		if ( ! preg_match( '/^(\d{1,12})(?:\.(\d{1,2}))?$/', $s, $m ) ) {
			return null;
		}
		$minor = ( (int) $m[1] ) * 100 + ( isset( $m[2] ) ? (int) str_pad( $m[2], 2, '0' ) : 0 );
		return $minor > self::MAX_MINOR ? null : $minor;
	}

	public static function format( $minor ) {
		$minor = (int) $minor;
		$sign  = $minor < 0 ? '-' : '';
		$minor = abs( $minor );
		return $sign . intdiv( $minor, 100 ) . '.' . str_pad( (string) ( $minor % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/** WooCommerce amount (float/string) to minor units, rounding half up. Negative values are kept (discount lines). */
	public static function from_wc( $amount ) {
		return is_numeric( $amount ) ? (int) round( (float) $amount * 100 ) : 0;
	}

	/** Plain "1,250.00 BDT" for amounts that are ResellingKori's (always BDT), regardless of the store currency. */
	public static function bdt( $minor ) {
		return number_format_i18n( ( (int) $minor ) / 100, 2 ) . ' BDT';
	}

	/** Display, e.g. "৳ 1,250.00" using the store's own currency format. */
	public static function display( $minor ) {
		return wp_strip_all_tags( html_entity_decode( wc_price( ( (int) $minor ) / 100 ), ENT_QUOTES, 'UTF-8' ) );
	}
}
