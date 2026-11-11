<?php
/**
 * Carries out the instructions the agent dashboard hands to this shop (see RKC_Workspace).
 *
 * Nothing here trusts the instruction beyond what it must:
 *   - it arrived in an answer signed with this shop's secret (checked by the API client), and its shape is validated again;
 *   - it is refused when the owner switched "allow the dashboard to act" off, when it is out of date, and when the shop is
 *     not connected;
 *   - every instruction id is remembered. A repeat delivery never repeats the work: it only repeats the report. If this
 *     process dies while an instruction runs, the instruction is reported as "no result" and is NOT run again, because
 *     whether it had any effect is not known (the order's own state shows what happened);
 *   - "send" does not re-use the dashboard's figures. It rebuilds the order and its amounts with the same code the shop's own
 *     "Review and send" screen uses, with ResellingKori's live prices, and sends only if everything the agent confirmed
 *     still holds (order revision, cash to collect, order total, each line's quantity, price and ResellingKori base price).
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Workspace_Exec {

	const TYPES = array( 'send_order', 'cancel_order', 'check_status', 'refresh_order', 'link_product', 'unlink_product', 'confirm_mapping', 'bulk_sku', 'resync' );

	/** Instructions that only make the dashboard look again; they are allowed when "act" is switched off. */
	const VIEW_ONLY_OK = array( 'refresh_order', 'resync' );

	/** The only keys a report may carry back. */
	const DATA_KEYS = array( 'rk_order_id', 'linked', 'review', 'not_found', 'siblings', 'checked', 'rk_number', 'stage', 'rk_status', 'errors' );

	/* ================================================================ Intake */

	/**
	 * Store the instructions of a sync answer. An instruction that is already known is not stored again.
	 *
	 * @param array $commands Wire objects: { id, type, target, args, guards, expires, attempt }.
	 * @return int Number of new instructions.
	 */
	public static function intake( array $commands ) {
		global $wpdb;
		$t   = RKC_DB::t( 'ws_cmd' );
		$new = 0;
		foreach ( array_slice( $commands, 0, 20 ) as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$id   = ( isset( $c['id'] ) && is_string( $c['id'] ) ) ? $c['id'] : '';
			$type = ( isset( $c['type'] ) && is_string( $c['type'] ) ) ? $c['type'] : '';
			if ( ! preg_match( '/^c_[a-f0-9]{20}$/', $id ) || ! in_array( $type, self::TYPES, true ) ) {
				continue;
			}
			$payload = wp_json_encode(
				array(
					'args'    => ( isset( $c['args'] ) && is_array( $c['args'] ) ) ? $c['args'] : array(),
					'guards'  => ( isset( $c['guards'] ) && is_array( $c['guards'] ) ) ? $c['guards'] : array(),
					'expires' => isset( $c['expires'] ) ? (int) $c['expires'] : 0,
					'attempt' => isset( $c['attempt'] ) ? (int) $c['attempt'] : 1,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			if ( ! is_string( $payload ) || strlen( $payload ) > 65536 ) {
				continue;
			}
			$ok = RKC_DB::insert_unique(
				'ws_cmd',
				array(
					'cmd_id'     => $id,
					'type'       => $type,
					'target'     => substr( ( isset( $c['target'] ) && is_scalar( $c['target'] ) ) ? (string) $c['target'] : '', 0, 40 ),
					'payload'    => $payload,
					'state'      => 'queued',
					'reported'   => 0,
					'created_at' => RKC_DB::now(),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			if ( $ok ) {
				++$new;
				continue;
			}
			// Delivered again: ResellingKori has not received our report yet. Report again; never run again.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT state, started_at FROM {$t} WHERE cmd_id = %s", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			if ( ! $row ) {
				continue;
			}
			if ( 'done' === $row->state ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET reported = 0 WHERE cmd_id = %s", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			} elseif ( 'running' === $row->state && $row->started_at && strtotime( $row->started_at . ' UTC' ) < time() - 120 ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET state = 'done', result = %s, finished_at = %s, reported = 0 WHERE cmd_id = %s AND state = 'running'", self::encode( self::fail( 'no_result', __( 'This shop received the instruction but stopped before it could report. Look at the order to see what happened before you try again.', 'resellingkori-connector' ) ) ), RKC_DB::now(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				// The dashboard must show what really happened to the order (it may well have been sent), not what it knew before.
				self::touch( $type, isset( $c['target'] ) && is_scalar( $c['target'] ) ? (string) $c['target'] : '' );
			}
		}
		return $new;
	}

	/* ============================================================== Execution */

	/**
	 * Run the queued instructions, oldest first, until none is left or time is up.
	 *
	 * @param int $deadline Unix time after which no further instruction is started.
	 * @return int Number run.
	 */
	public static function run_queued( $deadline ) {
		global $wpdb;
		$t = RKC_DB::t( 'ws_cmd' );
		$n = 0;
		while ( time() < (int) $deadline ) {
			$row = $wpdb->get_row( "SELECT * FROM {$t} WHERE state = 'queued' ORDER BY seq ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			if ( ! $row ) {
				break;
			}
			// Written BEFORE the work starts: a crash from here on can never lead to a second run.
			$won = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET state = 'running', started_at = %s WHERE seq = %d AND state = 'queued'", RKC_DB::now(), (int) $row->seq ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			if ( ! $won ) {
				continue;
			}
			RKC_Workspace::renew_lock();
			try {
				$res = self::execute( $row );
			} catch ( Throwable $e ) {
				RKC_Logger::error( 'An agent dashboard instruction stopped on an unexpected error: ' . RKC_Redact::text( $e->getMessage() ), array( 'type' => $row->type ) );
				$res = self::fail( 'exception', __( 'This shop hit an unexpected error while carrying this out. Look at the order to see whether anything happened before you try again.', 'resellingkori-connector' ) );
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET state = 'done', result = %s, finished_at = %s, reported = 0 WHERE seq = %d", self::encode( $res ), RKC_DB::now(), (int) $row->seq ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			self::touch( (string) $row->type, (string) $row->target );
			RKC_Logger::info( 'Agent dashboard instruction ' . ( $res['ok'] ? 'carried out' : 'not carried out' ) . ': ' . $row->type . ( '' !== (string) $row->target ? ' ' . $row->target : '' ) . ( $res['ok'] ? '' : ' (' . $res['code'] . ')' ) );
			++$n;
		}
		return $n;
	}

	/** After any instruction about an order: report that order again, so the dashboard shows the truth whatever the outcome was. */
	private static function touch( $type, $target ) {
		if ( in_array( $type, array( 'send_order', 'cancel_order', 'check_status', 'refresh_order' ), true ) && ctype_digit( $target ) && (int) $target > 0 ) {
			RKC_Workspace::mark( 'o', (int) $target, true );
		}
	}

	private static function ok( $code, $message, array $data = array() ) {
		return array( 'ok' => true, 'code' => $code, 'message' => $message, 'data' => $data );
	}

	private static function fail( $code, $message, array $data = array() ) {
		return array( 'ok' => false, 'code' => $code, 'message' => $message, 'data' => $data );
	}

	/** A WP_Error from the shop's own code, as a report. */
	private static function from_error( WP_Error $e, $fallback = 'error' ) {
		$d    = (array) $e->get_error_data();
		$code = sanitize_key( (string) $e->get_error_code() );
		$data = array();
		if ( ! empty( $d['stage'] ) && is_string( $d['stage'] ) ) {
			$data['stage'] = $d['stage'];
		}
		if ( ! empty( $d['errors'] ) && is_array( $d['errors'] ) ) {
			$data['errors'] = array_slice( array_map( 'strval', $d['errors'] ), 0, 5 );
		}
		return self::fail( '' !== $code ? $code : $fallback, (string) $e->get_error_message(), $data );
	}

	private static function encode( array $res ) {
		$data = array();
		if ( isset( $res['data'] ) && is_array( $res['data'] ) ) {
			foreach ( self::DATA_KEYS as $k ) {
				if ( isset( $res['data'][ $k ] ) ) {
					$data[ $k ] = $res['data'][ $k ];
				}
			}
		}
		return (string) wp_json_encode(
			array(
				'ok'      => ! empty( $res['ok'] ),
				'code'    => substr( sanitize_key( (string) ( isset( $res['code'] ) ? $res['code'] : '' ) ), 0, 40 ),
				'message' => substr( wp_strip_all_tags( (string) ( isset( $res['message'] ) ? $res['message'] : '' ) ), 0, 480 ),
				'data'    => $data,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * @param object $row ws_cmd row.
	 * @return array { ok, code, message, data }
	 */
	private static function execute( $row ) {
		$p      = json_decode( (string) $row->payload, true );
		$type   = (string) $row->type;
		if ( ! is_array( $p ) || ! in_array( $type, self::TYPES, true ) ) {
			return self::fail( 'bad_command', __( 'This shop could not read the instruction.', 'resellingkori-connector' ) );
		}
		$args   = ( isset( $p['args'] ) && is_array( $p['args'] ) ) ? $p['args'] : array();
		$guards = ( isset( $p['guards'] ) && is_array( $p['guards'] ) ) ? $p['guards'] : array();
		$skew   = (int) RKC_Workspace::state()['skew'];

		if ( ! empty( $p['expires'] ) && time() + $skew > (int) $p['expires'] + 5 ) {
			return self::fail( 'expired', __( 'This shop picked the instruction up too late, so it was not carried out. Nothing was changed. Try again.', 'resellingkori-connector' ) );
		}
		if ( ! RKC_Config::connected() ) {
			return self::fail( 'not_connected', __( 'This shop is not connected to ResellingKori right now. Nothing was changed.', 'resellingkori-connector' ) );
		}
		if ( ! RKC_Workspace::actions_allowed() && ! in_array( $type, self::VIEW_ONLY_OK, true ) ) {
			return self::fail( 'actions_off', __( 'The shop owner has switched off sending, cancelling and linking from the agent dashboard. Nothing was changed.', 'resellingkori-connector' ) );
		}

		switch ( $type ) {
			case 'send_order':
				return self::send_order( $row, $args, $guards );
			case 'cancel_order':
				return self::cancel_order( $row, $args, $guards );
			case 'check_status':
				return self::check_status( $row, $args );
			case 'refresh_order':
				return self::refresh_order( $row, $args );
			case 'link_product':
				return self::link_product( $row, $args );
			case 'unlink_product':
				return self::unlink_product( $row, $args );
			case 'confirm_mapping':
				return self::confirm_mapping( $row, $args );
			case 'bulk_sku':
				return self::bulk_sku( $args );
			case 'resync':
				RKC_Workspace::start_full();
				return self::ok( 'resync_started', __( 'This shop will send everything again.', 'resellingkori-connector' ) );
		}
		return self::fail( 'bad_command', __( 'This shop does not know this instruction.', 'resellingkori-connector' ) );
	}

	/* ================================================================ Lookups */

	/** @return WC_Order|array The order, or a failed report. */
	private static function order_for( $row, array $args ) {
		$ref = isset( $args['ref'] ) && is_scalar( $args['ref'] ) ? (string) $args['ref'] : '';
		if ( ! preg_match( '/^\d{1,12}$/', $ref ) || $ref !== (string) $row->target ) {
			return self::fail( 'bad_command', __( 'This shop could not read the instruction.', 'resellingkori-connector' ) );
		}
		$order = wc_get_order( (int) $ref );
		if ( ! $order instanceof WC_Order ) {
			return self::fail( 'order_missing', __( 'This order no longer exists in the shop.', 'resellingkori-connector' ) );
		}
		return $order;
	}

	/** @return WC_Product|array The product, or a failed report. */
	private static function product_for( $row, array $args ) {
		$id = isset( $args['woo_id'] ) && is_numeric( $args['woo_id'] ) ? (int) $args['woo_id'] : 0;
		if ( $id < 1 || (string) $id !== (string) $row->target ) {
			return self::fail( 'bad_command', __( 'This shop could not read the instruction.', 'resellingkori-connector' ) );
		}
		$p = wc_get_product( $id );
		if ( ! $p ) {
			return self::fail( 'product_missing', __( 'This product no longer exists in the shop.', 'resellingkori-connector' ) );
		}
		return $p;
	}

	/* ================================================================== Orders */

	private static function send_order( $row, array $args, array $guards ) {
		$order = self::order_for( $row, $args );
		if ( is_array( $order ) ) {
			return $order;
		}
		$card = RKC_Workspace::order_card( $order );
		if ( ! $card ) {
			return self::fail( 'changed', __( 'This order no longer has a ResellingKori product, so nothing was sent.', 'resellingkori-connector' ) );
		}
		// 1. The agent confirmed THIS version of the order.
		if ( empty( $guards['rev'] ) || ! is_string( $guards['rev'] ) || ! hash_equals( $card['rev'], $guards['rev'] ) ) {
			return self::fail( 'changed', __( 'The order changed after it was confirmed in the dashboard, so nothing was sent. Open the order again and confirm.', 'resellingkori-connector' ) );
		}
		if ( ! in_array( $card['send']['stage'], array( 'none', 'cancelled', 'rejected' ), true ) ) {
			return self::fail( 'already_sent', __( 'This order has already been sent, or is being checked.', 'resellingkori-connector' ), array( 'stage' => $card['send']['stage'] ) );
		}
		if ( empty( $card['send']['ok'] ) ) {
			return self::fail( 'blocked', '' !== $card['send']['block'] ? $card['send']['block'] : __( 'This order cannot be sent right now.', 'resellingkori-connector' ) );
		}

		// 2. The choices the agent made.
		$opts = array();
		if ( isset( $args['item_ids'] ) && is_array( $args['item_ids'] ) ) {
			$opts['item_ids'] = array_slice( array_map( 'intval', $args['item_ids'] ), 0, 100 );
		}
		if ( isset( $args['delivery'] ) && is_scalar( $args['delivery'] ) && '' !== trim( (string) $args['delivery'] ) ) {
			$opts['delivery'] = substr( trim( (string) $args['delivery'] ), 0, 20 );
		}
		if ( array_key_exists( 'include_other', $args ) && null !== $args['include_other'] ) {
			$opts['include_other'] = (bool) $args['include_other'];
		}

		// 3. The same review the shop's own screen makes, with ResellingKori's prices as they are right now.
		$draft = RKC_Orders::review( $order, $opts, 0, true );
		if ( is_wp_error( $draft ) ) {
			return self::from_error( $draft, 'rk_unreachable' );
		}
		if ( $draft['errors'] ) {
			return self::fail( 'blocked', (string) reset( $draft['errors'] ), array( 'errors' => $draft['errors'] ) );
		}
		if ( ! isset( $guards['cod'], $guards['order'] ) || (int) $draft['money']['cod'] !== (int) $guards['cod'] || (int) $draft['money']['order'] !== (int) $guards['order'] ) {
			return self::fail( 'changed', __( 'The amounts are not what was confirmed in the dashboard, so nothing was sent. Open the order again and check the amounts.', 'resellingkori-connector' ) );
		}
		$chosen = array();
		foreach ( $draft['lines'] as $l ) {
			if ( ! empty( $l['selected'] ) ) {
				$chosen[ (int) $l['item_id'] ] = $l;
			}
		}
		$want = ( isset( $guards['lines'] ) && is_array( $guards['lines'] ) ) ? $guards['lines'] : array();
		if ( count( $want ) !== count( $chosen ) ) {
			return self::fail( 'changed', __( 'The items to send are not what was confirmed in the dashboard, so nothing was sent. Open the order again and check them.', 'resellingkori-connector' ) );
		}
		foreach ( $want as $w ) {
			$i = ( is_array( $w ) && isset( $w['i'] ) ) ? (int) $w['i'] : 0;
			if ( ! isset( $chosen[ $i ] ) || ! isset( $w['qty'], $w['unit'] ) || (int) $chosen[ $i ]['qty'] !== (int) $w['qty'] || (int) $chosen[ $i ]['unit_minor'] !== (int) $w['unit'] ) {
				return self::fail( 'changed', __( 'The items to send are not what was confirmed in the dashboard, so nothing was sent. Open the order again and check them.', 'resellingkori-connector' ) );
			}
			if ( isset( $w['base'] ) && null !== $w['base'] ) {
				$live = ( $chosen[ $i ]['rk'] && isset( $chosen[ $i ]['rk']['base_minor'] ) ) ? $chosen[ $i ]['rk']['base_minor'] : null;
				if ( null === $live || (int) $live !== (int) $w['base'] ) {
					return self::fail( 'price_changed', __( 'A ResellingKori price changed after it was confirmed in the dashboard, so nothing was sent. Open the order again and check your profit.', 'resellingkori-connector' ) );
				}
			}
		}

		// 4. Send. The shop's duplicate protection, recovery and webhooks apply exactly as for a send from the order screen.
		$order->add_order_note( __( 'Sending was requested from the ResellingKori agent dashboard.', 'resellingkori-connector' ) );
		$res = RKC_Submit::send( $order, $opts, $draft['fingerprint'], 0 );
		if ( is_wp_error( $res ) ) {
			$out = self::from_error( $res );
			if ( 'rkc_unknown' === $out['code'] ) {
				$out['code'] = 'outcome_unknown';
			}
			return $out;
		}
		if ( ! is_array( $res ) || ! isset( $res['stage'] ) || 'accepted' !== $res['stage'] ) {
			return self::fail( 'unexpected', __( 'ResellingKori answered with something this shop did not expect. Look at the order before you try again.', 'resellingkori-connector' ), array( 'stage' => is_array( $res ) && isset( $res['stage'] ) ? (string) $res['stage'] : '' ) );
		}
		return self::ok(
			'sent',
			! empty( $res['warning'] ) ? (string) $res['warning'] : '',
			array(
				'rk_order_id' => (int) $res['rk_order_id'],
				'rk_number'   => (string) $res['rk_order_number'],
				'stage'       => 'accepted',
			)
		);
	}

	private static function cancel_order( $row, array $args, array $guards ) {
		$order = self::order_for( $row, $args );
		if ( is_array( $order ) ) {
			return $order;
		}
		$st = RKC_Orders::state( $order );
		if ( 'accepted' !== $st['stage'] ) {
			return self::fail( 'not_active', __( 'This order is not active at ResellingKori, so there is nothing to cancel.', 'resellingkori-connector' ), array( 'stage' => (string) $st['stage'] ) );
		}
		if ( isset( $guards['rk_id'] ) && (int) $guards['rk_id'] !== (int) $st['rk_order_id'] ) {
			return self::fail( 'changed', __( 'This order was replaced at ResellingKori after you opened it, so nothing was cancelled. Open it again.', 'resellingkori-connector' ) );
		}
		if ( ! in_array( $st['status'], array( 'pending', 'processing', 'on_hold' ), true ) ) {
			return self::fail( 'not_cancellable', __( 'ResellingKori has already started processing or shipped this order, so it can no longer be cancelled here. Contact ResellingKori.', 'resellingkori-connector' ) );
		}
		$reason = isset( $args['reason'] ) && is_scalar( $args['reason'] ) ? (string) $args['reason'] : '';
		$reason = '' !== $reason ? $reason : __( 'Cancelled from the agent dashboard.', 'resellingkori-connector' );
		$res    = RKC_Submit::cancel( $order, $reason, 0 );
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}
		return self::ok( 'cancelled', '', array( 'stage' => 'cancelled' ) );
	}

	private static function check_status( $row, array $args ) {
		$order = self::order_for( $row, $args );
		if ( is_array( $order ) ) {
			return $order;
		}
		$st = RKC_Orders::state( $order );
		if ( in_array( $st['stage'], array( 'unknown', 'claimed', 'sending' ), true ) && $st['submission'] ) {
			$res = RKC_Submit::recover( (int) $st['submission']->id, true );
			if ( is_wp_error( $res ) ) {
				return self::from_error( $res );
			}
			return self::ok(
				'checked',
				'',
				array(
					'stage'       => isset( $res['stage'] ) ? (string) $res['stage'] : '',
					'rk_order_id' => isset( $res['rk_order_id'] ) ? (int) $res['rk_order_id'] : 0,
					'rk_number'   => isset( $res['rk_order_number'] ) ? (string) $res['rk_order_number'] : '',
				)
			);
		}
		if ( 'accepted' === $st['stage'] && (int) $st['rk_order_id'] > 0 ) {
			$rk_id = (int) $st['rk_order_id'];
			$res   = RKC_API_Client::order_statuses( array( $rk_id ) );
			if ( is_wp_error( $res ) ) {
				return self::from_error( $res );
			}
			$found = false;
			foreach ( (array) ( isset( $res['orders'] ) ? $res['orders'] : array() ) as $rk ) {
				if ( is_array( $rk ) && isset( $rk['id'] ) && (int) $rk['id'] === $rk_id ) {
					RKC_Webhook::apply_remote( $order, $rk, 'poll' );
					$found = true;
				}
			}
			RKC_Workspace::mark( 'o', (int) $order->get_id(), true );
			$fresh = RKC_Orders::state( $order );
			return self::ok( $found ? 'checked' : 'not_found_at_rk', '', array( 'checked' => $found ? 1 : 0, 'rk_status' => (string) $fresh['status'], 'stage' => (string) $fresh['stage'] ) );
		}
		return self::ok( 'nothing_to_check', __( 'There is nothing to check for this order.', 'resellingkori-connector' ), array( 'stage' => (string) $st['stage'] ) );
	}

	private static function refresh_order( $row, array $args ) {
		$order = self::order_for( $row, $args );
		if ( is_array( $order ) ) {
			return $order;
		}
		RKC_Workspace::mark( 'o', (int) $order->get_id(), true );
		return self::ok( 'refreshed', '' );
	}

	/* ================================================================= Products */

	private static function link_product( $row, array $args ) {
		$p = self::product_for( $row, $args );
		if ( is_array( $p ) ) {
			return $p;
		}
		if ( $p->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
			return self::fail( 'not_a_unit', __( 'Link the individual variations of a variable product, not the parent.', 'resellingkori-connector' ) );
		}
		$pid = isset( $args['rk_product_id'] ) && is_numeric( $args['rk_product_id'] ) ? (int) $args['rk_product_id'] : 0;
		$vid = isset( $args['rk_variation_id'] ) && is_numeric( $args['rk_variation_id'] ) ? (int) $args['rk_variation_id'] : 0;
		if ( $pid < 1 || $vid < 0 ) {
			return self::fail( 'bad_command', __( 'This shop could not read the instruction.', 'resellingkori-connector' ) );
		}
		$res = RKC_Mapping::link( (int) $p->get_id(), $pid, $vid, 'manual' );
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}
		return self::ok( 'linked', '', array( 'linked' => 1, 'siblings' => (int) $res['siblings'] ) );
	}

	private static function unlink_product( $row, array $args ) {
		$p = self::product_for( $row, $args );
		if ( is_array( $p ) ) {
			return $p;
		}
		if ( ! RKC_Mapping::get( (int) $p->get_id() ) ) {
			return self::ok( 'already_unlinked', '' );
		}
		RKC_Mapping::clear( (int) $p->get_id() );
		return self::ok( 'unlinked', '' );
	}

	private static function confirm_mapping( $row, array $args ) {
		$p = self::product_for( $row, $args );
		if ( is_array( $p ) ) {
			return $p;
		}
		$map = RKC_Mapping::get( (int) $p->get_id() );
		if ( ! $map ) {
			return self::fail( 'not_linked', __( 'This product is not linked any more, so there is nothing to confirm.', 'resellingkori-connector' ) );
		}
		if ( empty( $map['review'] ) ) {
			return self::ok( 'already_confirmed', '' );
		}
		RKC_Mapping::confirm( (int) $p->get_id() );
		return self::ok( 'confirmed', '' );
	}

	private static function bulk_sku( array $args ) {
		$ids  = ( isset( $args['woo_ids'] ) && is_array( $args['woo_ids'] ) ) ? array_slice( array_map( 'intval', $args['woo_ids'] ), 0, 100 ) : array();
		$todo = array();
		foreach ( $ids as $id ) {
			if ( $id > 0 && ! RKC_Mapping::get( $id ) ) {
				$todo[] = $id;
			}
		}
		if ( ! $todo ) {
			return self::ok( 'nothing_to_do', '', array( 'linked' => 0, 'review' => 0, 'not_found' => 0 ) );
		}
		$res = RKC_Matcher::bulk_sku_link( $todo );
		if ( is_wp_error( $res ) ) {
			return self::from_error( $res );
		}
		return self::ok(
			'matched',
			'',
			array(
				'linked'    => (int) $res['linked'],
				'review'    => (int) $res['review'],
				'not_found' => (int) $res['not_found'],
			)
		);
	}
}
