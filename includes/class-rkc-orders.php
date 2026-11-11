<?php
/**
 * WooCommerce order integration: the "Send to ResellingKori" button.
 *
 * Works with both legacy order storage and HPOS.
 *
 * Send modes (decided from how many order lines are mapped to ResellingKori):
 *   full     every line is mapped            -> one button sends the whole order
 *   partial  some lines are mapped, some not -> the admin picks which ResellingKori items to send
 *   none     no line is mapped               -> blocked until products are mapped
 *
 * The price each customer paid for a line travels with the order (unit_price), so
 * ResellingKori records it as the SalesKing "set price".
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Orders {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );

		// Orders list column: HPOS screen first, then the legacy post-type screen.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );

		add_action( 'wp_ajax_rkc_send_order', array( __CLASS__, 'ajax_send' ) );
		add_action( 'wp_ajax_rkc_refresh_order', array( __CLASS__, 'ajax_refresh' ) );
	}

	/* ------------------------------------------------------------------ UI */

	public static function add_meta_box() {
		$screen = 'shop_order';
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$screen = wc_get_page_screen_id( 'shop-order' );
		}
		add_meta_box( 'rkc-order-box', __( 'ResellingKori', 'resellingkori-connector' ), array( __CLASS__, 'meta_box' ), $screen, 'side', 'high' );
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order Legacy storage passes a post, HPOS passes an order.
	 */
	public static function meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		echo '<div class="rkc-box" data-order-id="' . esc_attr( $order->get_id() ) . '">';
		echo self::render_box( $order, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render_box().
		echo '</div>';
	}

	public static function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['rkc_status'] = __( 'ResellingKori', 'resellingkori-connector' );
			}
		}
		if ( ! isset( $out['rkc_status'] ) ) {
			$out['rkc_status'] = __( 'ResellingKori', 'resellingkori-connector' );
		}
		return $out;
	}

	/**
	 * @param string       $column      Column key.
	 * @param WC_Order|int $order_or_id HPOS passes the order, legacy passes the post ID.
	 */
	public static function render_column( $column, $order_or_id ) {
		if ( 'rkc_status' !== $column ) {
			return;
		}
		$order = ( $order_or_id instanceof WC_Order ) ? $order_or_id : wc_get_order( $order_or_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		echo '<div class="rkc-box" data-compact="1" data-order-id="' . esc_attr( $order->get_id() ) . '">';
		echo self::render_box( $order, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render_box().
		echo '</div>';
	}

	public static function status_label( $status ) {
		$all = RKC_Settings::rk_statuses();
		return isset( $all[ $status ] ) ? $all[ $status ] : ucfirst( (string) $status );
	}

	/**
	 * Inner HTML of the ResellingKori box. Everything is escaped here.
	 *
	 * @param WC_Order $order   Order.
	 * @param bool     $compact Compact layout for the orders list.
	 * @return string
	 */
	public static function render_box( WC_Order $order, $compact = false ) {
		$state = self::state( $order );
		$id    = (int) $order->get_id();

		ob_start();

		// Already submitted.
		if ( $state['submitted'] ) {
			echo '<div class="rkc-line"><span class="rkc-badge rkc-s-' . esc_attr( $state['status'] ) . '">' . esc_html( self::status_label( $state['status'] ) ) . '</span> ';
			echo '<strong class="rkc-num">' . esc_html( $state['number'] ) . '</strong>';
			if ( $state['partial'] ) {
				echo ' <span class="rkc-badge rkc-warn">' . esc_html__( 'Partial', 'resellingkori-connector' ) . '</span>';
			}
			echo '</div>';

			if ( ! $compact ) {
				$t = $state['tracking'];
				if ( ! empty( $t['number'] ) || ! empty( $t['courier'] ) ) {
					echo '<div class="rkc-track">';
					if ( ! empty( $t['courier'] ) ) {
						echo '<span>' . esc_html( $t['courier'] ) . '</span> ';
					}
					if ( ! empty( $t['number'] ) ) {
						if ( ! empty( $t['url'] ) ) {
							echo '<a href="' . esc_url( $t['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $t['number'] ) . '</a>';
						} else {
							echo '<code>' . esc_html( $t['number'] ) . '</code>';
						}
					}
					echo '</div>';
				}
				if ( $state['partial'] ) {
					self::render_partial_summary( $order, $state );
				}
				if ( $state['synced_at'] ) {
					/* translators: %s: human readable time difference */
					echo '<div class="rkc-muted">' . esc_html( sprintf( __( 'Last synced %s ago', 'resellingkori-connector' ), human_time_diff( $state['synced_at'] ) ) ) . '</div>';
				}
				echo '<p><button type="button" class="button rkc-refresh" data-order-id="' . esc_attr( $id ) . '">' . esc_html__( 'Refresh status', 'resellingkori-connector' ) . '</button></p>';
			}
			return ob_get_clean();
		}

		// Not submitted yet.
		$lines = self::resolve_items( $order );
		$mode  = self::send_mode( $lines );
		$can   = self::can_send( $order, $lines );

		if ( $compact ) {
			if ( true === $can ) {
				if ( 'partial' === $mode ) {
					// Choosing items needs the order screen.
					echo '<a class="button button-small" href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Choose items', 'resellingkori-connector' ) . '</a>';
				} else {
					echo '<button type="button" class="button button-small rkc-send" data-order-id="' . esc_attr( $id ) . '">' . esc_html__( 'Send to RK', 'resellingkori-connector' ) . '</button>';
				}
			} else {
				$short = array(
					'rkc_unmapped'       => __( 'Not mapped', 'resellingkori-connector' ),
					'rkc_customer'       => __( 'Missing info', 'resellingkori-connector' ),
					'rkc_not_configured' => __( 'Not connected', 'resellingkori-connector' ),
				);
				$code  = $can->get_error_code();
				if ( isset( $short[ $code ] ) ) {
					echo '<span class="rkc-badge rkc-warn" title="' . esc_attr( $can->get_error_message() ) . '">' . esc_html( $short[ $code ] ) . '</span>';
				} else {
					echo '<span class="rkc-muted" title="' . esc_attr( $can->get_error_message() ) . '">&ndash;</span>';
				}
			}
			if ( $state['error'] ) {
				echo '<div class="rkc-msg rkc-error">' . esc_html( $state['error']['message'] ) . '</div>';
			}
			return ob_get_clean();
		}

		if ( $state['error'] ) {
			echo '<div class="rkc-msg rkc-error">' . esc_html( $state['error']['message'] ) . '</div>';
		}

		// Mixed order: some items are from ResellingKori, some are not.
		if ( 'partial' === $mode && true === $can ) {
			self::render_partial_form( $order, $lines );
			return ob_get_clean();
		}

		if ( $lines ) {
			echo '<ul class="rkc-lines">';
			foreach ( $lines as $l ) {
				echo '<li><span class="rkc-line-name">' . esc_html( $l['name'] ) . ' &times; ' . (int) $l['qty'] . '</span>';
				if ( ! empty( $l['map'] ) ) {
					echo '<span class="rkc-ok">&check; ' . esc_html( self::map_label( $l['map'] ) ) . '</span>';
				} elseif ( $l['woo_id'] ) {
					echo '<a class="rkc-bad" href="' . esc_url( RKC_Admin::map_url( $l['woo_id'] ) ) . '">' . esc_html__( 'Not mapped — map now', 'resellingkori-connector' ) . '</a>';
				} else {
					echo '<span class="rkc-bad">' . esc_html__( 'Product no longer exists', 'resellingkori-connector' ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		if ( true === $can ) {
			echo '<p><button type="button" class="button button-primary rkc-send" data-order-id="' . esc_attr( $id ) . '">' . esc_html__( 'Send to ResellingKori', 'resellingkori-connector' ) . '</button></p>';
		} else {
			echo '<p class="rkc-muted">' . esc_html( $can->get_error_message() ) . '</p>';
			echo '<p><button type="button" class="button" disabled>' . esc_html__( 'Send to ResellingKori', 'resellingkori-connector' ) . '</button></p>';
		}

		return ob_get_clean();
	}

	private static function map_label( array $map ) {
		return '' !== $map['name'] ? $map['name'] : '#' . $map['rk_product_id'];
	}

	/**
	 * The picker shown ONLY for mixed orders: ResellingKori items are checkboxes (pre-checked),
	 * items that are not from ResellingKori are listed as "will not be sent".
	 *
	 * @param WC_Order $order Order.
	 * @param array    $lines Output of resolve_items().
	 */
	private static function render_partial_form( WC_Order $order, array $lines ) {
		$is_cod = self::is_cod( $order );
		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$sum    = 0.0;

		echo '<p class="rkc-muted">' . esc_html__( 'Some items in this order are not from ResellingKori. Choose the ResellingKori items to send. Unchecked items cannot be sent later (one ResellingKori order per store order).', 'resellingkori-connector' ) . '</p>';
		echo '<div class="rkc-partial" data-cod="' . ( $is_cod ? '1' : '0' ) . '" data-symbol="' . esc_attr( $symbol ) . '">';
		echo '<ul class="rkc-lines">';
		foreach ( $lines as $l ) {
			echo '<li>';
			if ( ! empty( $l['map'] ) ) {
				$sum += (float) $l['total'];
				echo '<label><input type="checkbox" class="rkc-pick" value="' . esc_attr( (int) $l['item_id'] ) . '" data-total="' . esc_attr( round( (float) $l['total'], 2 ) ) . '" checked="checked" /> ' . esc_html( $l['name'] ) . ' &times; ' . (int) $l['qty'] . '</label>';
				echo '<span class="rkc-ok">&check; ' . esc_html( self::map_label( $l['map'] ) ) . '</span>';
			} else {
				echo '<span class="rkc-line-name">' . esc_html( $l['name'] ) . ' &times; ' . (int) $l['qty'] . '</span>';
				echo '<span class="rkc-muted">' . esc_html__( 'Not from ResellingKori: will not be sent', 'resellingkori-connector' ) . '</span>';
				if ( $l['woo_id'] ) {
					echo '<a class="rkc-bad" href="' . esc_url( RKC_Admin::map_url( $l['woo_id'] ) ) . '">' . esc_html__( 'Map it', 'resellingkori-connector' ) . '</a>';
				}
			}
			echo '</li>';
		}
		echo '</ul>';

		if ( $is_cod ) {
			echo '<p><label>' . esc_html__( 'Extra amount to collect on this parcel (delivery charge etc.)', 'resellingkori-connector' ) . ' ' . esc_html( $symbol ) . ' <input type="number" class="rkc-extra small-text" min="0" step="0.01" value="0" /></label></p>';
			echo '<p class="rkc-cod">' . esc_html__( 'ResellingKori will collect:', 'resellingkori-connector' ) . ' <strong class="rkc-cod-amount">' . esc_html( $symbol . ' ' . number_format( $sum, 2 ) ) . '</strong></p>';
		}
		echo '<p><button type="button" class="button button-primary rkc-send" data-order-id="' . esc_attr( (int) $order->get_id() ) . '">' . esc_html__( 'Send selected items to ResellingKori', 'resellingkori-connector' ) . '</button></p>';
		echo '<p class="rkc-muted">' . esc_html__( 'The order status will not be changed automatically for a partial shipment.', 'resellingkori-connector' ) . '</p>';
		echo '</div>';
	}

	/** After a partial send: which items went to ResellingKori and which did not. */
	private static function render_partial_summary( WC_Order $order, array $state ) {
		echo '<ul class="rkc-lines">';
		foreach ( self::resolve_items( $order ) as $l ) {
			$sent = in_array( (int) $l['item_id'], $state['sent_items'], true );
			echo '<li><span class="rkc-line-name">' . esc_html( $l['name'] ) . ' &times; ' . (int) $l['qty'] . '</span>';
			if ( $sent ) {
				echo '<span class="rkc-ok">&check; ' . esc_html__( 'Sent to ResellingKori', 'resellingkori-connector' ) . '</span>';
			} else {
				echo '<span class="rkc-muted">' . esc_html__( 'Not sent (not from ResellingKori)', 'resellingkori-connector' ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<p class="rkc-muted">' . esc_html__( 'Partial shipment: the order status is not changed automatically.', 'resellingkori-connector' ) . '</p>';
	}

	/* --------------------------------------------------------------- State */

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

		return array(
			'submitted'   => $rk_id > 0,
			'rk_order_id' => $rk_id,
			'number'      => (string) $order->get_meta( '_rkc_rk_order_number' ),
			'status'      => '' !== $status ? $status : 'pending',
			'tracking'    => is_array( $tracking ) ? $tracking : array(),
			'synced_at'   => (int) $order->get_meta( '_rkc_synced_at' ),
			'partial'     => (bool) $order->get_meta( '_rkc_partial' ),
			'sent_items'  => is_array( $sent ) ? array_map( 'intval', $sent ) : array(),
			'error'       => ( is_array( $error ) && ! empty( $error['message'] ) ) ? $error : null,
		);
	}

	/**
	 * One entry per product line with its ResellingKori mapping.
	 *
	 * @param WC_Order $order Order.
	 * @return array[]
	 */
	public static function resolve_items( WC_Order $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$woo_id  = $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
			$product = $item->get_product();
			$lines[] = array(
				'item_id' => (int) $item_id,
				'woo_id'  => $product ? $woo_id : 0,
				'name'    => $item->get_name(),
				'qty'     => (int) $item->get_quantity(),
				'sku'     => $product ? (string) $product->get_sku() : '',
				'total'   => (float) $item->get_total(),
				'map'     => ( $product && $woo_id ) ? RKC_Mapping::get( $woo_id ) : null,
			);
		}
		return $lines;
	}

	/**
	 * @param array[] $lines Output of resolve_items().
	 * @return string full | partial | none
	 */
	public static function send_mode( array $lines ) {
		$mapped   = 0;
		$unmapped = 0;
		foreach ( $lines as $l ) {
			if ( ! empty( $l['map'] ) ) {
				++$mapped;
			} else {
				++$unmapped;
			}
		}
		if ( ! $mapped ) {
			return 'none';
		}
		return $unmapped ? 'partial' : 'full';
	}

	private static function is_cod( WC_Order $order ) {
		$ids = array_filter( array_map( 'trim', explode( ',', (string) RKC_Settings::get( 'cod_gateways' ) ) ) );
		return in_array( $order->get_payment_method(), $ids, true );
	}

	/**
	 * Customer details, preferring the shipping address and falling back to billing.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function customer( WC_Order $order ) {
		$ship = $order->has_shipping_address();
		$pick = function ( $field ) use ( $order, $ship ) {
			$val = '';
			if ( $ship ) {
				$method = 'get_shipping_' . $field;
				$val    = method_exists( $order, $method ) ? trim( (string) $order->$method() ) : '';
			}
			if ( '' === $val ) {
				$method = 'get_billing_' . $field;
				$val    = method_exists( $order, $method ) ? trim( (string) $order->$method() ) : '';
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

	/**
	 * Every pre-flight check in one place. Returns true or a WP_Error whose message is shown to the admin.
	 * A mixed order is allowed (the admin then chooses the items); an order with no mapped item is not.
	 *
	 * @param WC_Order   $order Order.
	 * @param array|null $lines Output of resolve_items().
	 * @return true|WP_Error
	 */
	public static function can_send( WC_Order $order, $lines = null ) {
		if ( ! RKC_API_Client::configured() ) {
			return new WP_Error( 'rkc_not_configured', __( 'ResellingKori is not connected. Add your API key in WooCommerce > ResellingKori > Settings.', 'resellingkori-connector' ) );
		}
		if ( $order->get_meta( '_rkc_rk_order_id' ) ) {
			return new WP_Error( 'rkc_already', __( 'This order has already been submitted to ResellingKori.', 'resellingkori-connector' ) );
		}
		if ( ! in_array( $order->get_status(), (array) RKC_Settings::get( 'send_statuses' ), true ) ) {
			return new WP_Error(
				'rkc_status',
				sprintf(
					/* translators: %s: order status name */
					__( 'Orders with status "%s" cannot be sent. Change the allowed statuses in Settings if needed.', 'resellingkori-connector' ),
					wc_get_order_status_name( $order->get_status() )
				)
			);
		}

		$missing = self::missing_customer_fields( self::customer( $order ) );
		if ( $missing ) {
			return new WP_Error(
				'rkc_customer',
				sprintf(
					/* translators: %s: list of missing fields */
					__( 'Customer information missing: %s.', 'resellingkori-connector' ),
					implode( ', ', $missing )
				)
			);
		}

		$lines = ( null === $lines ) ? self::resolve_items( $order ) : $lines;
		if ( ! $lines ) {
			return new WP_Error( 'rkc_no_items', __( 'This order has no products to fulfil.', 'resellingkori-connector' ) );
		}
		if ( 'none' === self::send_mode( $lines ) ) {
			$names = array();
			foreach ( $lines as $l ) {
				$names[] = $l['name'];
			}
			return new WP_Error(
				'rkc_unmapped',
				sprintf(
					/* translators: %s: product names */
					__( 'Not connected with ResellingKori: %s. Map at least one product first.', 'resellingkori-connector' ),
					implode( ', ', $names )
				),
				array( 'products' => $names )
			);
		}

		$can = apply_filters( 'rkc_can_send_order', true, $order, $lines );
		if ( true !== $can && ! is_wp_error( $can ) ) {
			return new WP_Error( 'rkc_blocked', __( 'Sending this order is blocked.', 'resellingkori-connector' ) );
		}
		return $can;
	}

	/* ---------------------------------------------------------------- Send */

	/**
	 * Submit an order (or, for a mixed order, the chosen ResellingKori items) to ResellingKori.
	 *
	 * Duplicate protection: the API de-duplicates on (API key + external order ID), so a
	 * retry after a timeout returns the original order instead of creating another.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $opts  { item_ids: int[] (order item IDs, partial mode), extra: float (extra amount to collect, partial mode) }.
	 * @return array|WP_Error ResellingKori order data.
	 */
	public static function send( WC_Order $order, array $opts = array() ) {
		$lines = self::resolve_items( $order );
		$can   = self::can_send( $order, $lines );
		if ( is_wp_error( $can ) ) {
			return $can;
		}

		$mode       = self::send_mode( $lines );
		$partial    = ( 'partial' === $mode );
		$send_lines = $lines;
		$extra      = 0.0;

		if ( $partial ) {
			$chosen     = isset( $opts['item_ids'] ) ? array_map( 'intval', (array) $opts['item_ids'] ) : array();
			$send_lines = array();
			foreach ( $lines as $l ) {
				if ( ! empty( $l['map'] ) && in_array( (int) $l['item_id'], $chosen, true ) ) {
					$send_lines[] = $l;
				}
			}
			if ( ! $send_lines ) {
				return new WP_Error( 'rkc_select_items', __( 'Select at least one ResellingKori item to send.', 'resellingkori-connector' ) );
			}
			$extra = isset( $opts['extra'] ) ? max( 0.0, round( (float) $opts['extra'], 2 ) ) : 0.0;
		}

		$lock = (int) $order->get_meta( '_rkc_lock' );
		if ( $lock && ( time() - $lock ) < 60 ) {
			return new WP_Error( 'rkc_busy', __( 'This order is already being sent. Please wait a moment.', 'resellingkori-connector' ) );
		}
		$order->update_meta_data( '_rkc_lock', time() );
		$order->save_meta_data();

		$payload = self::build_payload( $order, $send_lines, $partial, $extra );
		$res     = RKC_API_Client::create_order( $payload );

		$order->delete_meta_data( '_rkc_lock' );

		if ( is_wp_error( $res ) ) {
			return self::record_failure( $order, $res );
		}

		$rk = ( isset( $res['order'] ) && is_array( $res['order'] ) ) ? $res['order'] : null;
		if ( ! $rk || empty( $rk['id'] ) ) {
			return self::record_failure(
				$order,
				new WP_Error( 'rkc_bad_response', __( 'ResellingKori returned no order ID. It is safe to try again: duplicate orders are prevented.', 'resellingkori-connector' ) )
			);
		}

		$number = isset( $rk['number'] ) ? sanitize_text_field( (string) $rk['number'] ) : (string) $rk['id'];
		$sent   = array();
		foreach ( $send_lines as $l ) {
			$sent[] = (int) $l['item_id'];
		}

		$order->update_meta_data( '_rkc_rk_order_id', (int) $rk['id'] );
		$order->update_meta_data( '_rkc_rk_order_number', $number );
		$order->update_meta_data( '_rkc_rk_status', isset( $rk['status'] ) ? sanitize_key( $rk['status'] ) : 'pending' );
		$order->update_meta_data( '_rkc_submitted_at', time() );
		$order->update_meta_data( '_rkc_synced_at', time() );
		$order->update_meta_data( '_rkc_open', 1 );
		$order->update_meta_data( '_rkc_partial', $partial ? 1 : 0 );
		$order->update_meta_data( '_rkc_sent_items', $sent );
		$order->delete_meta_data( '_rkc_last_error' );
		$order->save();

		if ( ! empty( $res['duplicate'] ) ) {
			/* translators: %s: ResellingKori order number */
			$order->add_order_note( sprintf( __( 'This order already existed on ResellingKori (%s). Linked it instead of creating a duplicate.', 'resellingkori-connector' ), $number ) );
		} elseif ( $partial ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: items sent, 2: items in the order, 3: ResellingKori order number */
					__( 'Submitted %1$d of %2$d items to ResellingKori as order %3$s (partial shipment; the other items are fulfilled by this store).', 'resellingkori-connector' ),
					count( $send_lines ),
					count( $lines ),
					$number
				)
			);
		} else {
			/* translators: %s: ResellingKori order number */
			$order->add_order_note( sprintf( __( 'Submitted to ResellingKori as order %s.', 'resellingkori-connector' ), $number ) );
		}

		// A partial shipment never changes the store order's status on its own.
		$after = (string) RKC_Settings::get( 'after_send' );
		if ( ! $partial && '' !== $after && $after !== $order->get_status() ) {
			$known = RKC_Settings::statuses();
			if ( isset( $known[ $after ] ) ) {
				$order->update_status( $after, __( 'Order sent to ResellingKori.', 'resellingkori-connector' ) );
			}
		}

		do_action( 'rkc_order_submitted', $order, $rk, $payload );

		return $rk;
	}

	private static function record_failure( WC_Order $order, WP_Error $error ) {
		$order->update_meta_data(
			'_rkc_last_error',
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'time'    => time(),
			)
		);
		$order->save();
		/* translators: %s: error message */
		$order->add_order_note( sprintf( __( 'ResellingKori submission failed: %s', 'resellingkori-connector' ), $error->get_error_message() ) );
		return $error;
	}

	/**
	 * @param WC_Order $order   Order.
	 * @param array    $lines   The lines being sent (all mapped).
	 * @param bool     $partial True when only some of the order's items are sent.
	 * @param float    $extra   Extra amount to collect on a partial parcel (delivery etc.).
	 * @return array
	 */
	private static function build_payload( WC_Order $order, array $lines, $partial, $extra ) {
		$is_cod = self::is_cod( $order );
		$total  = (float) $order->get_total();

		$items       = array();
		$items_total = 0.0;
		foreach ( $lines as $l ) {
			$qty = max( 1, (int) $l['qty'] );
			// Round DOWN to the cent so unit price x quantity never exceeds what the customer paid for the line.
			$unit         = floor( ( $l['total'] / $qty ) * 100 + 0.000001 ) / 100;
			$items_total += round( $unit * $qty, 2 );
			$items[]      = array(
				'external_item_id' => (int) $l['item_id'],
				'rk_product_id'    => (int) $l['map']['rk_product_id'],
				'rk_variation_id'  => (int) $l['map']['rk_variation_id'],
				'quantity'         => $qty,
				'external_name'    => $l['name'],
				'external_sku'     => $l['sku'],
				// The price this store's customer pays: ResellingKori records it as the SalesKing set price.
				'unit_price'       => $unit,
			);
		}

		// Amount the ResellingKori courier collects. Whole order: the order total. Partial parcel: the items' price plus any extra.
		$cod = 0.0;
		if ( $is_cod ) {
			$cod = $partial ? ( $items_total + (float) $extra ) : $total;
		}

		$payload = array(
			'external_order_id'     => (string) $order->get_id(),
			'external_order_number' => (string) $order->get_order_number(),
			'store_name'            => get_bloginfo( 'name' ),
			'store_url'             => home_url(),
			'callback_url'          => rest_url( 'rkc/v1/webhook' ),
			'agent_email'           => (string) RKC_Settings::get( 'agent_email' ),
			'customer'              => self::customer( $order ),
			'items'                 => $items,
			'payment_method'        => $is_cod ? 'cod' : 'prepaid',
			'partial'               => (bool) $partial,
			'items_total'           => round( $items_total, 2 ),
			'cod_amount'            => round( $cod, 2 ),
			'order_total'           => round( $total, 2 ),
			'shipping_total'        => round( (float) $order->get_shipping_total(), 2 ),
			'currency'              => $order->get_currency(),
			'note'                  => (string) $order->get_customer_note(),
		);

		return apply_filters( 'rkc_order_payload', $payload, $order, $lines );
	}

	/* ---------------------------------------------------------------- AJAX */

	private static function ajax_guard() {
		check_ajax_referer( 'rkc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'resellingkori-connector' ) ) );
		}
	}

	public static function ajax_send() {
		self::ajax_guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in ajax_guard().
		$order   = wc_get_order( isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0 );
		$compact = ! empty( $_POST['compact'] );
		$opts    = array(
			'item_ids' => isset( $_POST['item_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['item_ids'] ) ) : array(),
			'extra'    => isset( $_POST['extra'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['extra'] ) ) : 0.0,
		);
		// phpcs:enable
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'resellingkori-connector' ) ) );
		}

		$res   = self::send( $order, $opts );
		$order = wc_get_order( $order->get_id() );
		$html  = self::render_box( $order, $compact );

		if ( is_wp_error( $res ) ) {
			wp_send_json_error(
				array(
					'message' => $res->get_error_message(),
					'html'    => $html,
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: ResellingKori order number */
					__( 'Order successfully submitted to ResellingKori. RK order %s.', 'resellingkori-connector' ),
					isset( $res['number'] ) ? $res['number'] : $res['id']
				),
				'html'    => $html,
			)
		);
	}

	public static function ajax_refresh() {
		self::ajax_guard();
		$order = wc_get_order( isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'resellingkori-connector' ) ) );
		}

		$res   = RKC_Sync::refresh_order( $order );
		$order = wc_get_order( $order->get_id() );
		$html  = self::render_box( $order, false );

		if ( is_wp_error( $res ) ) {
			wp_send_json_error(
				array(
					'message' => $res->get_error_message(),
					'html'    => $html,
				)
			);
		}
		wp_send_json_success(
			array(
				'message' => __( 'Status refreshed.', 'resellingkori-connector' ),
				'html'    => $html,
			)
		);
	}
}
