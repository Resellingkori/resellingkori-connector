<?php
/**
 * Privacy: what this plugin sends and stores, and WordPress personal-data export / erase support.
 *
 * Sent to ResellingKori ONLY when a person presses "Send": for each order, the recipient's name, phone, e-mail
 * (optional), delivery address, the products (SKU, name, quantity, selling price), the amounts and an optional customer
 * note. Product-matching sends product names/SKUs typed or taken from your catalogue. Nothing is sent in the
 * background except status checks for orders you already sent (they carry ResellingKori order ids only).
 * No analytics, telemetry or advertising identifiers are collected.
 *
 * Agent dashboard (optional, Settings): when it is switched on, the shop also reports to ResellingKori a short "card" for
 * every order that contains a ResellingKori product: order number, status, items and amounts, the recipient's name and
 * city, and a masked phone number (for example 017•••••678). Never the e-mail address, the street address or the complete
 * phone number. The orders are shown to the sales agent who owns the store at ResellingKori and are deleted there when
 * the switch is turned off, when the shop is disconnected, or after the retention period set at ResellingKori.
 *
 * Stored here: a copy of each submitted payload (an immutable snapshot used to prevent duplicates and to recover from
 * network failures), which contains the recipient details above. The diagnostic log masks phone numbers and e-mail
 * addresses and is deleted after the retention period.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Privacy {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<h2>' . esc_html__( 'ResellingKori orders', 'resellingkori-connector' ) . '</h2>';
		$content .= '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for your privacy policy:', 'resellingkori-connector' ) . '</p>';
		$content .= '<p>' . esc_html__( 'When we fulfil an order through our supplier ResellingKori, we send them the recipient name, phone number, delivery address, optional e-mail address, the items ordered and the amounts, so that they can pack and deliver the order. We keep a copy of what we sent for our records and to prevent duplicate orders.', 'resellingkori-connector' ) . '</p>';
		$content .= '<p>' . esc_html__( 'If the ResellingKori agent dashboard is switched on, orders that contain a ResellingKori product are also shown to the sales agent who runs our ResellingKori account: order number, status, items and amounts, the recipient\'s name and city and a partly hidden phone number. The e-mail address, the street address and the full phone number are not shown there.', 'resellingkori-connector' ) . '</p>';
		wp_add_privacy_policy_content( 'ResellingKori Fulfillment Connector', wp_kses_post( $content ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['resellingkori-connector'] = array(
			'exporter_friendly_name' => __( 'ResellingKori submissions', 'resellingkori-connector' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['resellingkori-connector'] = array(
			'eraser_friendly_name' => __( 'ResellingKori submissions', 'resellingkori-connector' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/** Orders placed with this e-mail address (billing). */
	private static function order_ids_for( $email ) {
		return wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => -1,
				'return'        => 'ids',
			)
		);
	}

	public static function export( $email, $page = 1 ) {
		$data = array();
		foreach ( (array) self::order_ids_for( $email ) as $order_id ) {
			foreach ( RKC_Submit::history( $order_id ) as $sub ) {
				$payload = json_decode( (string) $sub->payload, true );
				$cust    = ( is_array( $payload ) && ! empty( $payload['customer'] ) ) ? $payload['customer'] : array();
				$rows    = array(
					array( 'name' => __( 'Order', 'resellingkori-connector' ), 'value' => '#' . $order_id ),
					array( 'name' => __( 'ResellingKori reference', 'resellingkori-connector' ), 'value' => $sub->external_id ),
					array( 'name' => __( 'State', 'resellingkori-connector' ), 'value' => $sub->state ),
					array( 'name' => __( 'Sent on', 'resellingkori-connector' ), 'value' => $sub->created_at . ' UTC' ),
				);
				foreach ( array( 'name', 'phone', 'email', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $f ) {
					if ( ! empty( $cust[ $f ] ) ) {
						$rows[] = array( 'name' => ucfirst( str_replace( '_', ' ', $f ) ), 'value' => (string) $cust[ $f ] );
					}
				}
				$rows[] = array( 'name' => __( 'Shown in the ResellingKori agent dashboard', 'resellingkori-connector' ), 'value' => ( class_exists( 'RKC_Workspace' ) && RKC_Workspace::is_listed( $order_id ) ) ? __( 'Yes', 'resellingkori-connector' ) : __( 'No', 'resellingkori-connector' ) );
				$data[] = array(
					'group_id'    => 'resellingkori-submissions',
					'group_label' => __( 'ResellingKori submissions', 'resellingkori-connector' ),
					'item_id'     => 'rkc-' . $sub->id,
					'data'        => $rows,
				);
			}
		}
		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * Blank the recipient details inside stored snapshots. The rows themselves (state, ids, amounts) stay so that
	 * duplicate protection and accounting keep working; ResellingKori holds its own copy, which this site cannot erase.
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$removed  = false;
		$t        = RKC_DB::t( 'submissions' );
		foreach ( (array) self::order_ids_for( $email ) as $order_id ) {
			// The dashboard must stop showing this customer's order, whatever else the eraser does.
			if ( class_exists( 'RKC_Workspace' ) ) {
				RKC_Workspace::forget_order( (int) $order_id );
			}
			foreach ( RKC_Submit::history( $order_id ) as $sub ) {
				$payload = json_decode( (string) $sub->payload, true );
				if ( ! is_array( $payload ) || empty( $payload['customer'] ) || ! empty( $payload['_erased'] ) ) {
					continue;
				}
				$payload['customer'] = array( 'name' => '[erased]', 'phone' => '', 'email' => '', 'address_1' => '[erased]', 'address_2' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country' => isset( $payload['customer']['country'] ) ? $payload['customer']['country'] : '' );
				$payload['note']     = '';
				$payload['_erased']  = 1;
				$wpdb->update( $t, array( 'payload' => wp_json_encode( $payload ) ), array( 'id' => (int) $sub->id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$removed = true;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $removed ? array( __( 'Recipient details were removed from the copies stored by the ResellingKori connector. ResellingKori keeps its own record of orders already sent; ask them to erase it.', 'resellingkori-connector' ) ) : array(),
			'done'           => true,
		);
	}
}
