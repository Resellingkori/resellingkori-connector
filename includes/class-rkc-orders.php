<?php
/**
 * Everything the connector knows about a store order in relation to ResellingKori: its state, which lines are
 * ResellingKori products, and the DRAFT of what would be sent (customer, lines, selling prices, payment split).
 *
 * Money is handled in integer minor units (paisa). The payload is always rebuilt on the server from the live
 * order; the browser only supplies the choices (which lines, delivery charge, include other charges).
 *
 * Payment model (see API_DOCUMENTATION.md):
 *   selling_total   = sum(selling_unit_price x quantity) of the lines sent
 *   order_total     = selling_total + delivery_charge
 *   cod_amount      = order_total - prepaid_amount          (what the ResellingKori courier collects)
 *   Nothing is guessed: delivery_charge is an explicit number the staff member confirms.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Orders {

	const FINAL_STATUSES = array( 'delivered', 'returned', 'cancelled' );

	/* ------------------------------------------------------------- State */

	/**
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function state( WC_Order $order ) {
		$rk_id    = (int) $order->get_meta( '_rkc_rk_order_id' );
		$error    = $order->get_meta( '_rkc_last_error' );
		$tracking = $order->get_meta( '_rkc_tracking' );
		$sent     = $order->get_meta( '_rkc_sent_items' );
		$status   = (string) $order->get_meta( '_rkc_rk_status' );
		$sub      = RKC_Submit::latest( $order->get_id() );

		if ( $sub ) {
			$stage = $sub->state;
		} else {
			$stage = $rk_id > 0 ? 'accepted' : 'none'; // Submitted by v0.2.0: no submission row, but linked.
		}

		return array(
			'stage'       => $stage, // none | claimed | sending | unknown | rejected | accepted | cancelled
			'submitted'   => 'accepted' === $stage && $rk_id > 0,
			'rk_order_id' => $rk_id,
			'number'      => (string) $order->get_meta( '_rkc_rk_order_number' ),
			'status'      => '' !== $status ? $status : ( 'accepted' === $stage ? 'pending' : '' ),
			'tracking'    => is_array( $tracking ) ? $tracking : array(),
			'synced_at'   => (int) $order->get_meta( '_rkc_synced_at' ),
			'partial'     => (bool) $order->get_meta( '_rkc_partial' ),
			'sent_items'  => is_array( $sent ) ? array_map( 'intval', $sent ) : array(),
			'error'       => ( is_array( $error ) && ! empty( $error['message'] ) ) ? $error : null,
			'submission'  => $sub,
			'legacy'      => ( ! $sub && $rk_id > 0 ),
			'changed'     => $sub && in_array( $sub->state, array( 'accepted', 'unknown' ), true ) ? self::changed_since( $order, $sub ) : false,
		);
	}

	/** True when the order's items no longer match what was submitted (so staff know an amendment is needed). */
	private static function changed_since( WC_Order $order, $sub ) {
		$payload = json_decode( (string) $sub->payload, true );
		if ( ! is_array( $payload ) || empty( $payload['items'] ) ) {
			return false;
		}
		$sent = array();
		foreach ( $payload['items'] as $it ) {
			$sent[ (int) $it['external_item_id'] ] = (int) $it['quantity'];
		}
		foreach ( self::lines( $order ) as $l ) {
			if ( isset( $sent[ $l['item_id'] ] ) && $sent[ $l['item_id'] ] !== $l['qty'] ) {
				return true;
			}
			if ( ! isset( $sent[ $l['item_id'] ] ) && ! empty( $l['map'] ) && ! $order->get_meta( '_rkc_partial' ) ) {
				return true;
			}
		}
		foreach ( array_keys( $sent ) as $id ) {
			if ( ! $order->get_item( $id ) ) {
				return true;
			}
		}
		return false;
	}

	public static function status_label( $status ) {
		$labels = RKC_Settings::rk_statuses();
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( str_replace( '_', ' ', (string) $status ) );
	}

	/* -------------------------------------------------------------- Lines */

	/**
	 * One entry per product line with its ResellingKori mapping and what the customer paid for it.
	 *
	 * @param WC_Order $order Order.
	 * @return array[]
	 */
	public static function lines( WC_Order $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$woo_id   = $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
			$product  = $item->get_product();
			$qty      = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id ); // Refunded quantity is negative.
			$paid     = RKC_Money::from_wc( $item->get_total() ) + RKC_Money::from_wc( $item->get_total_tax() ) - RKC_Money::from_wc( $order->get_total_refunded_for_item( $item_id ) );
			$unit     = $qty > 0 ? intdiv( max( 0, $paid ), $qty ) : 0; // Rounded DOWN: unit x qty never exceeds what the customer paid.
			$map      = ( $product && $woo_id ) ? RKC_Mapping::get( $woo_id ) : null;
			$lines[]  = array(
				'item_id'     => (int) $item_id,
				'woo_id'      => $product ? $woo_id : 0,
				'name'        => $item->get_name(),
				'qty'         => max( 0, $qty ),
				'sku'         => $product ? (string) $product->get_sku() : '',
				'paid_minor'  => max( 0, $paid ),
				'unit_minor'  => $unit,
				'sell_minor'  => $unit * max( 0, $qty ),
				// A mapping nobody has confirmed yet is not used: the line is treated as "not a ResellingKori product".
				'map'         => ( $map && empty( $map['review'] ) ) ? $map : null,
				'needs_review' => (bool) ( $map && ! empty( $map['review'] ) ),
				'image'       => ( $product && $product->get_image_id() ) ? (string) wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '',
			);
		}
		return $lines;
	}

	/** @return string full | partial | none */
	public static function send_mode( array $lines ) {
		$mapped = 0;
		$other  = 0;
		foreach ( $lines as $l ) {
			if ( $l['qty'] < 1 ) {
				continue;
			}
			if ( ! empty( $l['map'] ) ) {
				++$mapped;
			} else {
				++$other;
			}
		}
		if ( ! $mapped ) {
			return 'none';
		}
		return $other ? 'partial' : 'full';
	}

	public static function is_cod( WC_Order $order ) {
		$ids = array_filter( array_map( 'trim', explode( ',', (string) RKC_Settings::get( 'cod_gateways' ) ) ) );
		return in_array( $order->get_payment_method(), $ids, true );
	}

	/* ----------------------------------------------------------- Customer */

	public static function customer( WC_Order $order ) {
		$ship = $order->has_shipping_address();
		$pick = function ( $field ) use ( $order, $ship ) {
			$val = '';
			if ( $ship ) {
				$m   = 'get_shipping_' . $field;
				$val = method_exists( $order, $m ) ? trim( (string) $order->$m() ) : '';
			}
			if ( '' === $val ) {
				$m   = 'get_billing_' . $field;
				$val = method_exists( $order, $m ) ? trim( (string) $order->$m() ) : '';
			}
			return $val;
		};
		$ship_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		$bill_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$country   = $pick( 'country' );
		return array(
			'name'      => ( $ship && '' !== $ship_name ) ? $ship_name : $bill_name,
			'phone'     => $pick( 'phone' ),
			'email'     => trim( (string) $order->get_billing_email() ),
			'address_1' => $pick( 'address_1' ),
			'address_2' => $pick( 'address_2' ),
			'city'      => $pick( 'city' ),
			'state'     => $pick( 'state' ),
			'postcode'  => $pick( 'postcode' ),
			'country'   => '' !== $country ? $country : 'BD',
		);
	}

	public static function missing_customer_fields( array $c ) {
		$missing = array();
		if ( '' === $c['name'] ) {
			$missing[] = __( 'customer name', 'resellingkori-connector' );
		}
		if ( strlen( (string) preg_replace( '/\D+/', '', $c['phone'] ) ) < 10 ) {
			$missing[] = __( 'valid phone number', 'resellingkori-connector' );
		}
		if ( '' === $c['address_1'] ) {
			$missing[] = __( 'address', 'resellingkori-connector' );
		}
		return $missing;
	}

	/* ---------------------------------------------------- Pre-flight check */

	/**
	 * Checks that do not depend on the staff member's choices. A mixed order is allowed (staff then choose the
	 * items); an order with no ResellingKori item is not.
	 *
	 * @return true|WP_Error
	 */
	public static function can_send( WC_Order $order, $lines = null ) {
		if ( ! RKC_Config::connected() ) {
			return new WP_Error( 'rkc_not_configured', __( 'ResellingKori is not connected yet. Finish the setup in WooCommerce > ResellingKori.', 'resellingkori-connector' ) );
		}
		if ( false === RKC_Config::get( 'agent_valid' ) ) {
			return new WP_Error( 'rkc_no_agent', __( 'No valid sales agent is linked to your ResellingKori key, so orders cannot be placed. Ask ResellingKori to link your agent account.', 'resellingkori-connector' ) );
		}
		if ( false === RKC_Config::get( 'agent_match' ) ) {
			// The key belongs to a different sales agent than the one this shop expects: commission would go to the wrong person.
			return new WP_Error( 'rkc_agent_mismatch', __( 'The sales agent linked to your ResellingKori key is not the agent you expect (see Settings). Orders are paused until this is resolved, so the commission does not go to the wrong agent.', 'resellingkori-connector' ) );
		}
		$st = self::state( $order );
		if ( in_array( $st['stage'], array( 'accepted', 'sending', 'claimed' ), true ) ) {
			return new WP_Error( 'rkc_already', __( 'This order has already been submitted to ResellingKori.', 'resellingkori-connector' ) );
		}
		if ( 'unknown' === $st['stage'] ) {
			return new WP_Error( 'rkc_unknown', __( 'The last attempt to send this order did not get a clear answer. Press "Check status" so nothing is sent twice.', 'resellingkori-connector' ) );
		}
		if ( ! in_array( $order->get_status(), (array) RKC_Settings::get( 'send_statuses' ), true ) ) {
			return new WP_Error( 'rkc_status', sprintf( /* translators: %s: order status name */ __( 'Orders with status "%s" cannot be sent. Change the allowed statuses in Settings if needed.', 'resellingkori-connector' ), wc_get_order_status_name( $order->get_status() ) ) );
		}
		if ( 'BDT' !== $order->get_currency() ) {
			return new WP_Error( 'rkc_currency', sprintf( /* translators: %s: currency code */ __( 'ResellingKori only accepts orders in BDT (this order is in %s).', 'resellingkori-connector' ), $order->get_currency() ) );
		}
		$missing = self::missing_customer_fields( self::customer( $order ) );
		if ( $missing ) {
			return new WP_Error( 'rkc_customer', sprintf( /* translators: %s: list of missing fields */ __( 'Customer information missing: %s.', 'resellingkori-connector' ), implode( ', ', $missing ) ) );
		}
		$lines = ( null === $lines ) ? self::lines( $order ) : $lines;
		if ( ! $lines ) {
			return new WP_Error( 'rkc_no_items', __( 'This order has no products to fulfil.', 'resellingkori-connector' ) );
		}
		if ( 'none' === self::send_mode( $lines ) ) {
			$names = array();
			foreach ( $lines as $l ) {
				$names[] = $l['name'];
			}
			return new WP_Error( 'rkc_unmapped', sprintf( /* translators: %s: product names */ __( 'Not connected with ResellingKori: %s. Map at least one product first.', 'resellingkori-connector' ), implode( ', ', $names ) ), array( 'products' => $names ) );
		}
		$can = apply_filters( 'rkc_can_send_order', true, $order, $lines );
		if ( true !== $can && ! is_wp_error( $can ) ) {
			return new WP_Error( 'rkc_blocked', __( 'Sending this order is blocked.', 'resellingkori-connector' ) );
		}
		return $can;
	}

	/* -------------------------------------------------------------- Draft */

	/**
	 * Build the proposal. Never throws; blocking problems are in ['errors'].
	 *
	 * @param WC_Order $order Order.
	 * @param array    $opts  { item_ids: int[], delivery: string|null (BDT), include_other: bool|null }
	 * @param array    $rk    Live ResellingKori data per "productId:variationId": { base_minor, purchasable, name } (from review).
	 * @return array
	 */
	public static function draft( WC_Order $order, array $opts = array(), array $rk = array() ) {
		$lines    = self::lines( $order );
		$mode     = self::send_mode( $lines );
		$partial  = ( 'partial' === $mode );
		$errors   = array();
		$warnings = array();
		$limits   = (array) get_option( 'rkc_limits', array() );

		$pre = self::can_send( $order, $lines );
		if ( is_wp_error( $pre ) ) {
			$errors[] = $pre->get_error_message();
		}

		// Which lines go.
		$chosen = isset( $opts['item_ids'] ) ? array_map( 'intval', (array) $opts['item_ids'] ) : null;
		$send   = array();
		foreach ( $lines as &$l ) {
			$l['mapped']   = ! empty( $l['map'] ) && $l['qty'] > 0;
			$l['selected'] = $l['mapped'] && ( ! $partial || null === $chosen || in_array( $l['item_id'], $chosen, true ) );
			$key           = $l['mapped'] ? $l['map']['rk_product_id'] . ':' . $l['map']['rk_variation_id'] : '';
			$l['rk']       = ( '' !== $key && isset( $rk[ $key ] ) ) ? $rk[ $key ] : null;
			if ( $l['selected'] ) {
				$send[] = $l;
			}
		}
		unset( $l );
		if ( ! $send ) {
			$errors[] = $partial ? __( 'Select at least one ResellingKori item to send.', 'resellingkori-connector' ) : __( 'There is nothing to send.', 'resellingkori-connector' );
		}

		// Money.
		$selling = 0;
		$paid_sel = 0;
		$items   = array();
		foreach ( $send as $l ) {
			if ( $l['unit_minor'] < 100 ) {
				/* translators: %s: product name */
				$errors[] = sprintf( __( '"%s" has a price below 1 BDT and cannot be sent.', 'resellingkori-connector' ), $l['name'] );
			}
			$selling  += $l['sell_minor'];
			$paid_sel += $l['paid_minor'];
			$item      = array(
				'external_item_id'   => (int) $l['item_id'],
				'rk_product_id'      => (int) $l['map']['rk_product_id'],
				'rk_variation_id'    => (int) $l['map']['rk_variation_id'],
				'sku'                => (string) $l['sku'],
				'name'               => (string) $l['name'],
				'quantity'           => (int) $l['qty'],
				'selling_unit_price' => RKC_Money::format( $l['unit_minor'] ),
			);
			if ( $l['rk'] && isset( $l['rk']['base_minor'] ) && null !== $l['rk']['base_minor'] ) {
				$item['expected_base_unit_price'] = RKC_Money::format( (int) $l['rk']['base_minor'] );
				if ( $l['unit_minor'] < (int) $l['rk']['base_minor'] ) {
					/* translators: %s: product name */
					$warnings[] = sprintf( __( '"%s" is priced below the ResellingKori base price. ResellingKori may refuse it.', 'resellingkori-connector' ), $l['name'] );
				}
			}
			if ( $l['rk'] && isset( $l['rk']['purchasable'] ) && false === $l['rk']['purchasable'] ) {
				/* translators: %s: product name */
				$errors[] = sprintf( __( '"%s" is out of stock at ResellingKori.', 'resellingkori-connector' ), $l['name'] );
			}
			$items[] = $item;
		}

		$is_cod       = self::is_cod( $order );
		$shipping     = RKC_Money::from_wc( $order->get_shipping_total() ) + RKC_Money::from_wc( $order->get_shipping_tax() );
		$order_total  = RKC_Money::from_wc( $order->get_total() ) - RKC_Money::from_wc( $order->get_total_refunded() );
		$gap          = $order_total - $selling - $shipping; // Fees, discounts and rounding that are neither items nor shipping.

		if ( $partial ) {
			$default_delivery = 'order' === RKC_Settings::get( 'partial_delivery' ) ? $shipping : 0;
		} else {
			$default_delivery = $shipping;
		}

		// The delivery charge is the one amount the person may type. It is always the base figure; nothing below is folded into it
		// on screen, so re-posting the box together with the checkbox can never count the same money twice.
		$delivery_base = $default_delivery;
		if ( isset( $opts['delivery'] ) && '' !== trim( (string) $opts['delivery'] ) ) {
			$typed = RKC_Money::parse( (string) $opts['delivery'] );
			if ( null === $typed ) {
				$errors[] = __( 'The delivery charge is not a valid amount (use numbers with at most 2 decimals).', 'resellingkori-connector' );
			} else {
				$delivery_base = $typed;
			}
		}

		// What else the store's total contains. Whole orders only (a partial order cannot be compared with the store's total).
		$other_shown  = false; // A choice the person must see (1 BDT or more).
		$include      = null;
		$other_charge = 0;     // A fee the customer agreed to pay; ResellingKori's contract has no separate field, so it travels inside delivery_charge.
		$credit       = 0;     // A discount or refund already given: counted as money the customer does not owe at the door.
		$rounding     = 0;     // Under 1 BDT left over from unit-price rounding, collected so the customer pays exactly the order total.
		if ( ! $partial ) {
			if ( $gap >= 100 || $gap <= -100 ) {
				$other_shown = true;
				$include     = isset( $opts['include_other'] ) ? (bool) $opts['include_other'] : true;
				if ( $include ) {
					if ( $gap > 0 ) {
						$other_charge = $gap;
					} else {
						$credit = -$gap;
					}
				}
			} elseif ( $gap > 0 ) {
				$rounding = $gap;
			} elseif ( $gap < 0 ) {
				$credit = -$gap;
			}
		}
		$delivery = $delivery_base + $rounding + $other_charge; // The figure that is sent as delivery_charge.
		$prepaid  = $credit;

		$order_amount = $selling + $delivery;
		if ( ! $is_cod ) {
			$method  = 'prepaid';
			$prepaid = $order_amount;
			$cod     = 0;
		} else {
			$method = 'cod';
			if ( $prepaid > $order_amount ) {
				$prepaid = $order_amount;
			}
			$cod = $order_amount - $prepaid;
			if ( $cod < 1 ) {
				$method  = 'prepaid';
				$prepaid = $order_amount;
				$cod     = 0;
			}
		}

		if ( isset( $limits['max_delivery_charge'] ) && $delivery > (int) round( (float) $limits['max_delivery_charge'] * 100 ) ) {
			/* translators: %s: amount */
			$errors[] = sprintf( __( 'The delivery charge is more than ResellingKori allows (%s).', 'resellingkori-connector' ), RKC_Money::display( (int) round( (float) $limits['max_delivery_charge'] * 100 ) ) );
		}
		if ( 'cod' === $method && isset( $limits['max_cod_amount'] ) && $cod > (int) round( (float) $limits['max_cod_amount'] * 100 ) ) {
			/* translators: %s: amount */
			$errors[] = sprintf( __( 'The cash to collect is more than ResellingKori allows (%s).', 'resellingkori-connector' ), RKC_Money::display( (int) round( (float) $limits['max_cod_amount'] * 100 ) ) );
		}
		if ( isset( $limits['max_order_amount'] ) && $order_amount > (int) round( (float) $limits['max_order_amount'] * 100 ) ) {
			/* translators: %s: amount */
			$errors[] = sprintf( __( 'The order total is more than ResellingKori allows (%s).', 'resellingkori-connector' ), RKC_Money::display( (int) round( (float) $limits['max_order_amount'] * 100 ) ) );
		}
		if ( isset( $limits['max_lines'] ) && count( $items ) > (int) $limits['max_lines'] ) {
			$errors[] = __( 'This order has more lines than ResellingKori accepts in one order.', 'resellingkori-connector' );
		}

		$payload = array(
			'schema'                => 2,
			'external_order_number' => (string) $order->get_order_number(),
			'currency'              => 'BDT',
			'partial'               => (bool) $partial,
			'customer'              => self::customer( $order ),
			'items'                 => $items,
			'payment'               => array(
				'method'          => $method,
				'delivery_charge' => RKC_Money::format( $delivery ),
				'prepaid_amount'  => RKC_Money::format( $prepaid ),
				'cod_amount'      => RKC_Money::format( $cod ),
			),
			'note'                  => substr( (string) $order->get_customer_note(), 0, 500 ),
		);
		$payload = (array) apply_filters( 'rkc_order_payload', $payload, $order, $lines );

		return array(
			'order_id'    => (int) $order->get_id(),
			'mode'        => $mode,
			'partial'     => $partial,
			'lines'       => $lines,
			'payload'     => $payload,
			'errors'      => array_values( array_unique( $errors ) ),
			'warnings'    => array_values( array_unique( $warnings ) ),
			'fingerprint' => self::fingerprint( $payload ),
			'money'       => array(
				'selling'     => $selling,
				'delivery'    => $delivery,
				'delivery_base' => $delivery_base,
				'default_delivery' => $default_delivery,
				'other_charge' => $other_charge,
				'credit'      => $credit,
				'prepaid'     => $prepaid,
				'cod'         => $cod,
				'order'       => $order_amount,
				'store_total' => $order_total,
				'shipping'    => $shipping,
				'gap'         => $gap,
				'other_shown' => $other_shown,
				'include'     => $include,
				'rounding'    => $rounding,
				'method'      => $method,
			),
		);
	}

	/** Content fingerprint of a payload (the external id is not part of it). */
	public static function fingerprint( array $payload ) {
		unset( $payload['external_order_id'] );
		return hash( 'sha256', wp_json_encode( self::ksort_deep( $payload ) ) );
	}

	private static function ksort_deep( $v ) {
		if ( ! is_array( $v ) ) {
			return $v;
		}
		$is_list = array_keys( $v ) === range( 0, count( $v ) - 1 );
		foreach ( $v as $k => $x ) {
			$v[ $k ] = self::ksort_deep( $x );
		}
		if ( ! $is_list ) {
			ksort( $v );
		}
		return $v;
	}

	/**
	 * Live ResellingKori data for the lines (base price, availability), keyed "productId:variationId".
	 *
	 * @return array|WP_Error
	 */
	public static function fetch_live( array $lines ) {
		$out  = array();
		$seen = array();
		foreach ( $lines as $l ) {
			if ( empty( $l['map'] ) || $l['qty'] < 1 ) {
				continue;
			}
			$pid = (int) $l['map']['rk_product_id'];
			if ( ! isset( $seen[ $pid ] ) ) {
				$res = RKC_API_Client::get_product( $pid );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$seen[ $pid ] = ( isset( $res['item'] ) && is_array( $res['item'] ) ) ? $res['item'] : array();
			}
			$item = $seen[ $pid ];
			$vid  = (int) $l['map']['rk_variation_id'];
			$node = $item;
			if ( $vid && ! empty( $item['variations'] ) ) {
				foreach ( $item['variations'] as $v ) {
					if ( (int) $v['id'] === $vid ) {
						$node = $v;
						break;
					}
				}
			}
			$base = isset( $node['base_price'] ) ? RKC_Money::parse( (string) $node['base_price'] ) : null;
			$out[ $pid . ':' . $vid ] = array(
				'base_minor'  => $base,
				'purchasable' => isset( $node['purchasable'] ) ? (bool) $node['purchasable'] : true,
				'name'        => isset( $item['name'] ) ? (string) $item['name'] : '',
				'stock'       => isset( $node['stock_quantity'] ) ? $node['stock_quantity'] : null,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------- Review */

	const REVIEW_TTL = 900; // A review is valid for 15 minutes.

	private static function review_key( $order_id, $user_id ) {
		return 'rkc_rv_' . (int) $order_id . '_' . (int) $user_id;
	}

	/** Identifies the mapping + quantities a review was made for; any change invalidates the cached ResellingKori data. */
	private static function lines_signature( array $lines ) {
		$sig = array();
		foreach ( $lines as $l ) {
			$sig[] = array( $l['item_id'], $l['qty'], empty( $l['map'] ) ? 0 : (int) $l['map']['rk_product_id'], empty( $l['map'] ) ? 0 : (int) $l['map']['rk_variation_id'] );
		}
		return md5( wp_json_encode( $sig ) );
	}

	/**
	 * The proposal shown to the staff member, built with live ResellingKori data (base price, availability).
	 *
	 * @param WC_Order $order       Order.
	 * @param array    $opts        Choices: item_ids, delivery, include_other.
	 * @param int      $user_id     Reviewing user (reviews are per user).
	 * @param bool     $refresh     Ask ResellingKori again even if a recent review exists.
	 * @param bool     $cached_only When sending: never trust anything but the review the person actually saw.
	 * @return array|WP_Error
	 */
	public static function review( WC_Order $order, array $opts, $user_id, $refresh = false, $cached_only = false ) {
		$key    = self::review_key( $order->get_id(), $user_id );
		$cached = get_transient( $key );
		$lines  = self::lines( $order );
		$sig    = self::lines_signature( $lines );
		$rk     = null;

		if ( is_array( $cached ) && ! $refresh && isset( $cached['rk'], $cached['at'], $cached['sig'] ) && $cached['sig'] === $sig && ( time() - (int) $cached['at'] ) < self::REVIEW_TTL ) {
			$rk = (array) $cached['rk'];
		}
		if ( null === $rk ) {
			if ( $cached_only ) {
				return new WP_Error( 'rkc_review_expired', __( 'The review has expired or the order changed. Review the order again before sending.', 'resellingkori-connector' ) );
			}
			if ( 'none' === self::send_mode( $lines ) ) {
				$rk = array();
			} else {
				$rk = self::fetch_live( $lines );
				if ( is_wp_error( $rk ) ) {
					return $rk;
				}
			}
			set_transient(
				$key,
				array(
					'rk'  => $rk,
					'at'  => time(),
					'sig' => $sig,
				),
				self::REVIEW_TTL
			);
		}
		return self::draft( $order, $opts, $rk );
	}

	public static function forget_review( $order_id, $user_id ) {
		delete_transient( self::review_key( $order_id, $user_id ) );
	}
}
