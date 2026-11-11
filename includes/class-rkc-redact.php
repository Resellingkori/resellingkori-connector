<?php
/**
 * Redaction of secrets before anything is logged, exported or shown in an error.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Redact {

	const SENSITIVE_KEYS = '/secret|signature|authorization|password|passwd|token|nonce|cookie|private/i';

	public static function text( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/rks_[A-Za-z0-9]{6,}/', 'rks_[redacted]', $text );
		$text = preg_replace( '/(x-rk-signature|authorization|x-rk-secret)\s*[:=]\s*[^\s,;]+/i', '$1: [redacted]', $text );
		$text = preg_replace( '/([?&](?:secret|signature|token|key)=)[^&\s]+/i', '$1[redacted]', $text );
		// Personal data that may appear in a message: e-mail addresses keep their first letter and domain; telephone numbers
		// (Bangladeshi mobile numbers, or any number written with a leading "+") keep their last three digits.
		$text = preg_replace_callback(
			'/[A-Za-z0-9._%+\-]{1,64}@[A-Za-z0-9.\-]{1,253}\.[A-Za-z]{2,24}/',
			function ( $m ) {
				return RKC_Redact::email( $m[0] );
			},
			$text
		);
		$text = preg_replace_callback(
			'/(?<![\w.])(?:\+\d{8,15}|(?:\+?88)?01[3-9]\d{8})(?!\w)/',
			function ( $m ) {
				return RKC_Redact::phone( $m[0] );
			},
			$text
		);
		return $text;
	}

	public static function data( $data ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$out[ $k ] = ( is_string( $k ) && preg_match( self::SENSITIVE_KEYS, $k ) ) ? '[redacted]' : self::data( $v );
			}
			return $out;
		}
		return is_string( $data ) ? self::text( $data ) : $data;
	}

	public static function email( $email ) {
		$email = (string) $email;
		$at    = strrpos( $email, '@' );
		return ( false === $at || $at < 1 ) ? '' : substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/** Mask a phone number for logs: keep the last 3 digits. */
	public static function phone( $phone ) {
		$digits = preg_replace( '/\D/', '', (string) $phone );
		return strlen( $digits ) > 3 ? str_repeat( '*', strlen( $digits ) - 3 ) . substr( $digits, -3 ) : '***';
	}
}
