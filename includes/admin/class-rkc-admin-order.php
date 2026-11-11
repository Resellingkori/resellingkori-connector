<?php
/**
 * The "ResellingKori" box on the order screen (legacy posts and HPOS) and the status column in the order list.
 *
 * The box is rendered on the server (all text escaped) and refreshed after every action. The review / confirmation
 * step is drawn by admin.js from JSON produced by draft_view(); the amounts shown there are the amounts the server
 * computed and will send, never numbers typed in the browser (only the delivery charge may be edited, and it is
 * validated on the server).
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Admin_Order {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_legacy' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_hpos' ), 10, 2 );
	}

	public static function add_box() {
		if ( ! current_user_can( 'rkc_view_orders' ) || ! RKC_Config::configured() ) {
			return;
		}
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		add_meta_box( 'rkc-order', __( 'ResellingKori', 'resellingkori-connector' ), array( __CLASS__, 'box' ), $screens, 'side', 'high' );
	}

	public static function box( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		echo '<div id="rkc-panel-wrap" class="rkc-wrap">' . self::panel_html( $order ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- panel_html() escapes every value.
	}

	/* --------------------------------------------------------------- Column */

	public static function columns( $cols ) {
		if ( ! current_user_can( 'rkc_view_orders' ) ) {
			return $cols;
		}
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$out['rkc'] = esc_html__( 'ResellingKori', 'resellingkori-connector' );
			}
		}
		if ( ! isset( $out['rkc'] ) ) {
			$out['rkc'] = esc_html__( 'ResellingKori', 'resellingkori-connector' );
		}
		return $out;
	}

	public static function column_legacy( $column, $post_id ) {
		if ( 'rkc' === $column ) {
			self::column_cell( wc_get_order( $post_id ) );
		}
	}

	public static function column_hpos( $column, $order ) {
		if ( 'rkc' === $column ) {
			self::column_cell( $order instanceof WC_Order ? $order : wc_get_order( $order ) );
		}
	}

	private static function column_cell( $order ) {
		if ( ! $order instanceof WC_Order || ! RKC_Config::configured() ) {
			return;
		}
		$st = RKC_Orders::state( $order );
		if ( 'none' === $st['stage'] ) {
			if ( 'none' !== RKC_Orders::send_mode( RKC_Orders::lines( $order ) ) ) {
				echo RKC_Admin_Pages::badge( __( 'Can be sent', 'resellingkori-connector' ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			return;
		}
		echo RKC_Admin_Pages::state_badge( $st['stage'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( 'accepted' === $st['stage'] && '' !== $st['status'] ) {
			echo ' ' . RKC_Admin_Pages::status_badge( $st['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( $st['partial'] && 'accepted' === $st['stage'] ) {
			echo '<br><small>' . esc_html__( 'selected items only', 'resellingkori-connector' ) . '</small>';
		}
	}

	/* ----------------------------------------------------------------- Panel */

	private static function row( $label, $value_html ) {
		return '<div class="rkc-dl__row"><dt>' . esc_html( $label ) . '</dt><dd>' . $value_html . '</dd></div>';
	}

	/**
	 * @param WC_Order $order Order.
	 * @return string HTML (escaped).
	 */
	public static function panel_html( WC_Order $order ) {
		$can_submit = current_user_can( 'rkc_submit_orders' ) && current_user_can( 'edit_shop_orders' );
		$st         = RKC_Orders::state( $order );
		$lines      = RKC_Orders::lines( $order );
		$mode       = RKC_Orders::send_mode( $lines );
		$html       = '<div class="rkc-panel" data-order-id="' . (int) $order->get_id() . '">';

		if ( ! RKC_Config::connected() ) {
			$html .= '<p>' . esc_html__( 'ResellingKori is not connected.', 'resellingkori-connector' ) . '</p>';
			if ( current_user_can( 'rkc_manage_connection' ) ) {
				$html .= '<p><a href="' . esc_url( RKC_Admin::url( 'setup' ) ) . '">' . esc_html__( 'Finish the setup', 'resellingkori-connector' ) . '</a></p>';
			}
			return $html . '</div>';
		}

		$html .= '<p class="rkc-panel__badges">' . RKC_Admin_Pages::state_badge( $st['stage'] );
		if ( 'accepted' === $st['stage'] && '' !== $st['status'] ) {
			$html .= ' ' . RKC_Admin_Pages::status_badge( $st['status'] );
		}
		$html .= '</p>';

		// What happened last.
		if ( $st['error'] && in_array( $st['stage'], array( 'rejected', 'unknown', 'none', 'cancelled' ), true ) ) {
			$html .= self::error_box( $st['error'] );
		}
		if ( ! empty( $st['changed'] ) && 'accepted' === $st['stage'] ) {
			$html .= '<div class="rkc-notice rkc-notice--warning"><p>' . esc_html__( 'The items of this order changed after it was sent. ResellingKori still has the original. Cancel it there and send the corrected order.', 'resellingkori-connector' ) . '</p></div>';
		}
		$remote_err = (string) $order->get_meta( '_rkc_remote_error' );
		if ( '' !== $remote_err ) {
			$html .= '<div class="rkc-notice rkc-notice--warning"><p><strong>' . esc_html__( 'ResellingKori reports a problem:', 'resellingkori-connector' ) . '</strong> ' . esc_html( $remote_err ) . '</p></div>';
		}

		// Details once sent.
		if ( in_array( $st['stage'], array( 'accepted', 'cancelled' ), true ) && $st['rk_order_id'] ) {
			$sub  = $st['submission'];
			$html .= '<dl class="rkc-dl rkc-dl--compact">';
			$html .= self::row( __( 'ResellingKori order', 'resellingkori-connector' ), esc_html( '' !== $st['number'] ? '#' . $st['number'] : '#' . $st['rk_order_id'] ) );
			if ( 'accepted' === $st['stage'] ) {
				$trk   = $st['tracking'];
				$html .= self::row( __( 'Tracking', 'resellingkori-connector' ), RKC_Admin_Pages::tracking_html( $trk ) );
				if ( $sub && (int) $sub->expected_cod > 0 ) {
					$html .= self::row( __( 'Cash to collect', 'resellingkori-connector' ), esc_html( RKC_Money::bdt( (int) $sub->expected_cod ) ) );
				} elseif ( $sub ) {
					$html .= self::row( __( 'Cash to collect', 'resellingkori-connector' ), esc_html__( 'None (already paid)', 'resellingkori-connector' ) );
				}
				$html .= self::row( __( 'Last update', 'resellingkori-connector' ), $st['synced_at'] ? esc_html( RKC_DB::ago( $st['synced_at'] ) ) : '—' );
				if ( $st['partial'] ) {
					$html .= self::row( __( 'Scope', 'resellingkori-connector' ), esc_html__( 'Selected items only. This order\'s own status is not changed by ResellingKori.', 'resellingkori-connector' ) );
				}
			}
			$html .= '</dl>';
		}

		// Items.
		if ( $lines ) {
			$html .= '<ul class="rkc-lines">';
			foreach ( $lines as $l ) {
				$sent   = in_array( $l['item_id'], $st['sent_items'], true );
				$html  .= '<li>' . esc_html( $l['name'] ) . ' × ' . (int) $l['qty'] . ' ';
				if ( ! empty( $l['needs_review'] ) ) {
					$html .= RKC_Admin_Pages::badge( __( 'Link needs review', 'resellingkori-connector' ), 'warn' );
				} elseif ( ! empty( $l['map'] ) ) {
					$html .= RKC_Admin_Pages::badge( $sent ? __( 'Sent', 'resellingkori-connector' ) : __( 'ResellingKori', 'resellingkori-connector' ), $sent ? 'good' : 'info' );
				} else {
					$html .= RKC_Admin_Pages::badge( __( 'Not a ResellingKori product', 'resellingkori-connector' ), 'neutral' );
				}
				$html .= '</li>';
			}
			$html .= '</ul>';
		}

		// Actions.
		if ( $can_submit ) {
			$html .= '<div class="rkc-panel__actions">';
			switch ( $st['stage'] ) {
				case 'accepted':
					$html .= '<button type="button" class="rkc-btn rkc-btn--small" data-rkc="order-refresh">' . esc_html__( 'Refresh status', 'resellingkori-connector' ) . '</button> ';
					if ( in_array( $st['status'], array( 'pending', 'processing', 'on_hold' ), true ) ) {
						$html .= '<button type="button" class="rkc-btn rkc-btn--small rkc-btn--danger" data-rkc="order-cancel">' . esc_html__( 'Cancel at ResellingKori', 'resellingkori-connector' ) . '</button>';
					}
					break;
				case 'unknown':
				case 'sending':
				case 'claimed':
					$html .= '<button type="button" class="rkc-btn rkc-btn--small rkc-btn--primary" data-rkc="order-recover">' . esc_html__( 'Check status', 'resellingkori-connector' ) . '</button>';
					$html .= '<p class="rkc-muted">' . esc_html__( 'Nothing is sent twice. The plugin asks ResellingKori what it received.', 'resellingkori-connector' ) . '</p>';
					break;
				default: // none | rejected | cancelled.
					$can = RKC_Orders::can_send( $order, $lines );
					if ( is_wp_error( $can ) ) {
						$html .= '<p class="rkc-muted">' . esc_html( $can->get_error_message() ) . '</p>';
					} else {
						$label = in_array( $st['stage'], array( 'rejected', 'cancelled' ), true ) ? __( 'Review and send again', 'resellingkori-connector' ) : __( 'Review and send', 'resellingkori-connector' );
						if ( 'partial' === $mode ) {
							$html .= '<p class="rkc-muted">' . esc_html__( 'This order also contains products from other suppliers. You will choose which items to send.', 'resellingkori-connector' ) . '</p>';
						}
						$html .= '<button type="button" class="rkc-btn rkc-btn--primary" data-rkc="order-review">' . esc_html( $label ) . '</button>';
					}
			}
			$html .= '</div>';
		} elseif ( 'none' === $st['stage'] && 'none' !== $mode ) {
			$html .= '<p class="rkc-muted">' . esc_html__( 'You do not have permission to send orders.', 'resellingkori-connector' ) . '</p>';
		}

		$html .= '<div class="rkc-review" hidden></div><div class="rkc-panel__msg" role="status" aria-live="polite"></div></div>';
		return $html;
	}

	private static function error_box( array $err ) {
		$html = '<div class="rkc-notice rkc-notice--error"><p>' . esc_html( (string) $err['message'] ) . '</p>';
		if ( ! empty( $err['fields'] ) ) {
			$html .= '<ul>';
			foreach ( $err['fields'] as $row ) {
				$text = isset( $row['message'] ) ? $row['message'] : '';
				if ( '' !== $text ) {
					$html .= '<li>' . esc_html( $text ) . '</li>';
				}
			}
			$html .= '</ul>';
		}
		if ( ! empty( $err['shortages'] ) ) {
			$html .= '<ul>';
			foreach ( $err['shortages'] as $row ) {
				$label = isset( $row['label'] ) ? (string) $row['label'] : ( isset( $row['name'] ) ? (string) $row['name'] : '' );
				if ( isset( $row['requested'], $row['available'] ) ) {
					/* translators: 1: product, 2: quantity ordered, 3: quantity available at ResellingKori */
					$html .= '<li>' . esc_html( sprintf( __( '%1$s: you need %2$s, ResellingKori has %3$s.', 'resellingkori-connector' ), $label, $row['requested'], $row['available'] ) ) . '</li>';
				} elseif ( '' !== $label ) {
					$html .= '<li>' . esc_html( $label ) . '</li>';
				}
			}
			$html .= '</ul>';
		}
		if ( ! empty( $err['changes'] ) ) {
			$html .= '<ul>';
			foreach ( $err['changes'] as $row ) {
				$html .= '<li>' . esc_html( sprintf( /* translators: 1: old price, 2: new price */ __( 'ResellingKori price changed from %1$s to %2$s.', 'resellingkori-connector' ), isset( $row['expected_base_unit_price'] ) ? $row['expected_base_unit_price'] : '?', isset( $row['current_base_unit_price'] ) ? $row['current_base_unit_price'] : '?' ) ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( ! empty( $err['time'] ) ) {
			$html .= '<p class="rkc-muted">' . esc_html( RKC_DB::ago( (int) $err['time'] ) ) . '</p>';
		}
		return $html . '</div>';
	}

	/* --------------------------------------------------------------- Review */

	/** JSON for the confirmation step. Display strings are formatted here, on the server. */
	public static function draft_view( array $draft ) {
		$lines = array();
		$profit_total = 0;
		$profit_known = true;
		foreach ( $draft['lines'] as $l ) {
			$base   = ( $l['rk'] && isset( $l['rk']['base_minor'] ) && null !== $l['rk']['base_minor'] ) ? (int) $l['rk']['base_minor'] : null;
			$profit = ( null !== $base && $l['mapped'] ) ? $l['sell_minor'] - $base * $l['qty'] : null;
			if ( $l['selected'] ) {
				if ( null === $profit ) {
					$profit_known = false;
				} else {
					$profit_total += $profit;
				}
			}
			$lines[] = array(
				'item_id'      => (int) $l['item_id'],
				'name'         => (string) $l['name'],
				'sku'          => (string) $l['sku'],
				'qty'          => (int) $l['qty'],
				'image'        => esc_url_raw( (string) $l['image'], array( 'http', 'https' ) ),
				'mapped'       => (bool) $l['mapped'],
				'needs_review' => ! empty( $l['needs_review'] ),
				'selected'     => (bool) $l['selected'],
				'rk_name'      => ! empty( $l['map'] ) ? (string) $l['map']['name'] : '',
				'sell_unit'    => RKC_Money::bdt( $l['unit_minor'] ),
				'sell_total'   => RKC_Money::bdt( $l['sell_minor'] ),
				'base_unit'    => null !== $base ? RKC_Money::bdt( $base ) : '',
				'profit'       => null !== $profit ? RKC_Money::bdt( $profit ) : '',
				'loss'         => null !== $profit && $profit < 0,
				'available'    => ( $l['rk'] && isset( $l['rk']['purchasable'] ) ) ? (bool) $l['rk']['purchasable'] : null,
			);
		}
		$m    = $draft['money'];
		$cust = $draft['payload']['customer'];
		$addr = trim( implode( ', ', array_filter( array( $cust['address_1'], $cust['address_2'], $cust['city'], $cust['state'], $cust['postcode'] ) ) ) );
		return array(
			'fingerprint' => $draft['fingerprint'],
			'mode'        => $draft['mode'],
			'partial'     => (bool) $draft['partial'],
			'lines'       => $lines,
			'errors'      => array_map( 'wp_strip_all_tags', $draft['errors'] ),
			'warnings'    => array_map( 'wp_strip_all_tags', $draft['warnings'] ),
			'can_send'    => empty( $draft['errors'] ),
			'customer'    => array(
				'name'    => (string) $cust['name'],
				'phone'   => (string) $cust['phone'],
				'email'   => (string) $cust['email'],
				'address' => $addr,
			),
			'money'       => array(
				'method'         => $m['method'],
				'selling'        => RKC_Money::bdt( $m['selling'] ),
				'delivery'       => RKC_Money::bdt( $m['delivery_base'] ),
				'delivery_input' => RKC_Money::format( $m['delivery_base'] ),
				'delivery_sent'  => RKC_Money::bdt( $m['delivery'] ),
				'other_charge'   => $m['other_charge'] > 0 ? RKC_Money::bdt( $m['other_charge'] ) : '',
				'credit'         => $m['credit'] > 0 ? RKC_Money::bdt( $m['credit'] ) : '',
				'rounding'       => $m['rounding'] > 0 ? RKC_Money::bdt( $m['rounding'] ) : '',
				'order_total'    => RKC_Money::bdt( $m['order'] ),
				'prepaid'        => RKC_Money::bdt( $m['prepaid'] ),
				'cod'            => RKC_Money::bdt( $m['cod'] ),
				'profit'         => $profit_known ? RKC_Money::bdt( $profit_total ) : '',
				'other_shown'    => (bool) $m['other_shown'],
				'other_amount'   => RKC_Money::bdt( abs( $m['gap'] ) ),
				'other_credit'   => $m['gap'] < 0,
				'include_other'  => (bool) $m['include'],
			),
		);
	}
}
