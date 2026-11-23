<?php
/**
 * Non-secret preferences. (Credentials live in RKC_Config.)
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Settings {

	const OPTION = 'rkc_settings';

	public static function defaults() {
		return array(
			'send_statuses'            => array( 'processing', 'on-hold' ),
			'after_send'               => '',
			'cod_gateways'             => 'cod',
			'sku_safety_check'         => 'yes',
			'expected_agent_email'     => '',
			'partial_delivery'         => 'none', // Delivery charge pre-filled for a partial parcel: none | order.
			'status_map'               => array(
				'pending'    => '',
				'processing' => '',
				'on_hold'    => '',
				'shipped'    => '',
				'delivered'  => 'completed',
				'returned'   => '',
				'cancelled'  => '',
			),
			'notify_failures'          => 'yes',
			'notify_email'             => '',
			// Agent dashboard at ResellingKori (Connector 1.1.0). '' = not decided yet (nothing is sent), yes | no.
			'workspace_sync'           => '',
			// May the agent dashboard send, cancel and link on this shop's behalf? (Viewing needs only the switch above.)
			'workspace_actions'        => 'yes',
			'log_level'                => 'info',
			'log_retention_days'       => 30,
			'delete_data_on_uninstall' => 'no',
		);
	}

	public static function get( $key = null ) {
		$d    = self::defaults();
		$opts = wp_parse_args( (array) get_option( self::OPTION, array() ), $d );
		$opts['status_map'] = wp_parse_args( (array) $opts['status_map'], $d['status_map'] );
		if ( ! is_array( $opts['send_statuses'] ) ) {
			$opts['send_statuses'] = array();
		}
		return null === $key ? $opts : ( isset( $opts[ $key ] ) ? $opts[ $key ] : null );
	}

	/** @return array slug (without wc-) => label */
	public static function statuses() {
		$out = array();
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$out[ preg_replace( '/^wc-/', '', $slug ) ] = $label;
		}
		return $out;
	}

	public static function rk_statuses() {
		return array(
			'pending'    => __( 'Pending', 'resellingkori-connector' ),
			'processing' => __( 'Processing', 'resellingkori-connector' ),
			'on_hold'    => __( 'On hold', 'resellingkori-connector' ),
			'shipped'    => __( 'Shipped', 'resellingkori-connector' ),
			'delivered'  => __( 'Delivered', 'resellingkori-connector' ),
			'returned'   => __( 'Returned', 'resellingkori-connector' ),
			'cancelled'  => __( 'Cancelled', 'resellingkori-connector' ),
		);
	}

	public static function sanitize( $input ) {
		$old   = self::get();
		$input = is_array( $input ) ? $input : array();
		$out   = $old;
		$valid = array_keys( self::statuses() );

		if ( isset( $input['send_statuses'] ) ) {
			$out['send_statuses'] = array_values( array_intersect( array_map( 'sanitize_key', (array) $input['send_statuses'] ), $valid ) );
		}
		if ( isset( $input['after_send'] ) ) {
			$s                 = sanitize_key( $input['after_send'] );
			$out['after_send'] = in_array( $s, $valid, true ) ? $s : '';
		}
		if ( isset( $input['cod_gateways'] ) ) {
			$out['cod_gateways'] = implode( ',', array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', (string) $input['cod_gateways'] ) ) ) );
		}
		if ( isset( $input['expected_agent_email'] ) ) {
			$out['expected_agent_email'] = sanitize_email( (string) $input['expected_agent_email'] );
		}
		if ( isset( $input['partial_delivery'] ) ) {
			$out['partial_delivery'] = 'order' === $input['partial_delivery'] ? 'order' : 'none';
		}
		if ( isset( $input['notify_email'] ) ) {
			$out['notify_email'] = sanitize_email( (string) $input['notify_email'] );
		}
		if ( isset( $input['log_level'] ) ) {
			$out['log_level'] = in_array( $input['log_level'], array( 'error', 'warning', 'info', 'debug' ), true ) ? $input['log_level'] : 'info';
		}
		if ( isset( $input['log_retention_days'] ) ) {
			$out['log_retention_days'] = max( 1, min( 365, (int) $input['log_retention_days'] ) );
		}
		foreach ( array( 'sku_safety_check', 'notify_failures', 'delete_data_on_uninstall', 'workspace_sync', 'workspace_actions' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = 'yes' === $input[ $flag ] ? 'yes' : 'no';
			}
		}
		if ( isset( $input['status_map'] ) && is_array( $input['status_map'] ) ) {
			foreach ( array_keys( self::rk_statuses() ) as $rk ) {
				$v                        = isset( $input['status_map'][ $rk ] ) ? sanitize_key( $input['status_map'][ $rk ] ) : '';
				$out['status_map'][ $rk ] = in_array( $v, $valid, true ) ? $v : '';
			}
		}
		return $out;
	}
}
