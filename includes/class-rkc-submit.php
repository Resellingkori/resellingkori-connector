<?php
/**
 * Sending an order to ResellingKori - exactly once.
 *
 * Rules this class enforces:
 *   1. One row per (order, attempt) in rkc_submissions, claimed by a unique insert, so two people (or two tabs)
 *      cannot both send the same order.
 *   2. The payload that is sent is stored as an immutable snapshot BEFORE the request leaves. A snapshot is only ever
 *      replaced after ResellingKori definitively refused it ("rejected").
 *   3. The external order id is derived from the order: "{order_id}" for the first attempt and "{order_id}-r{n}"
 *      after a cancelled attempt. ResellingKori treats (store, external id) as the idempotency key.
 *   4. If the outcome is unknown (timeout, 5xx, connection reset) the plugin NEVER sends again blindly. It asks
 *      ResellingKori what it holds for that external id and only re-sends the very same snapshot when nothing exists.
 *
 * States: claimed -> sending -> accepted | rejected | unknown ; accepted -> cancelled ; unknown -> accepted|rejected
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Submit {

	const STALE_SECONDS  = 120;
	const MAX_AUTO_TRIES = 6;
	const BACKOFF        = array( 60, 120, 240, 480, 960, 1920 );

	/* ---------------------------------------------------------------- Rows */

	public static function get( $id ) {
		global $wpdb;
		$t   = RKC_DB::t( 'submissions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? $row : null;
	}

	public static function latest( $order_id ) {
		global $wpdb;
		$t   = RKC_DB::t( 'submissions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY attempt DESC LIMIT 1", (int) $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? $row : null;
	}

	/** @return object[] Oldest first. */
	public static function history( $order_id ) {
		global $wpdb;
		$t = RKC_DB::t( 'submissions' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY attempt ASC", (int) $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public static function by_rk_order( $rk_order_id ) {
		global $wpdb;
		$t   = RKC_DB::t( 'submissions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE rk_order_id = %d AND state IN ('accepted','cancelled') ORDER BY id DESC LIMIT 1", (int) $rk_order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $row ? $row : null;
	}

	/** First attempt: the order id (compatible with v0.2.0). Later attempts: "{id}-r{n}". */
	public static function external_id( $order_id, $attempt ) {
		return (int) $attempt > 1 ? (int) $order_id . '-r' . (int) $attempt : (string) (int) $order_id;
	}

	private static function transition( $id, array $from, $set_sql, array $set_args ) {
		global $wpdb;
		$t     = RKC_DB::t( 'submissions' );
		$in    = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$args  = array_merge( $set_args, array( RKC_DB::now(), (int) $id ), $from );
		$query = "UPDATE {$t} SET {$set_sql}, updated_at = %s WHERE id = %d AND state IN ({$in})";
		$won   = 1 === (int) $wpdb->query( $wpdb->prepare( $query, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( $won && class_exists( 'RKC_Workspace' ) ) {
			// Whatever happens to a send (sending, accepted, rejected, unknown, cancelled) is news for the agent dashboard, even
			// when the process dies right afterwards and no other order data is written.
			$order_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			if ( $order_id > 0 ) {
				RKC_Workspace::on_order( $order_id );
			}
		}
		return $won;
	}

	/* ---------------------------------------------------------------- Send */

	/**
	 * @param WC_Order $order     Order.
	 * @param array    $opts      { item_ids, delivery, include_other } - the choices the person made.
	 * @param string   $client_fp Fingerprint of the proposal the person saw and confirmed.
	 * @param int      $user_id   Acting user.
	 * @return array|WP_Error     array(stage, ...) on acceptance; WP_Error otherwise (data.stage: rejected|unknown|blocked).
	 */
	public static function send( WC_Order $order, array $opts, $client_fp, $user_id ) {
		$draft = RKC_Orders::review( $order, $opts, $user_id, false, true );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}
		if ( $draft['errors'] ) {
			return new WP_Error( 'rkc_blocked', reset( $draft['errors'] ), array( 'stage' => 'blocked', 'errors' => $draft['errors'] ) );
		}
		if ( ! is_string( $client_fp ) || ! hash_equals( $draft['fingerprint'], $client_fp ) ) {
			return new WP_Error( 'rkc_changed', __( 'The order or the amounts changed after you reviewed them. Review the order again, then confirm.', 'resellingkori-connector' ), array( 'stage' => 'blocked' ) );
		}
		$result = self::dispatch( $order, $draft, (int) $user_id );
		RKC_Orders::forget_review( $order->get_id(), $user_id );
		return $result;
	}

	private static function dispatch( WC_Order $order, array $draft, $user_id ) {
		global $wpdb;
		$order_id = (int) $order->get_id();
		$latest   = self::latest( $order_id );
		$reuse    = null;

		if ( ! $latest ) {
			$attempt = 1;
		} elseif ( 'rejected' === $latest->state ) {
			$attempt = (int) $latest->attempt; // Refused definitively: nothing exists at ResellingKori, same reference is safe.
			$reuse   = $latest;
		} elseif ( 'cancelled' === $latest->state ) {
			$attempt = (int) $latest->attempt + 1;
		} else {
			return new WP_Error( 'rkc_already', __( 'This order has already been submitted to ResellingKori.', 'resellingkori-connector' ), array( 'stage' => 'blocked' ) );
		}

		$ext                         = self::external_id( $order_id, $attempt );
		$payload                     = $draft['payload'];
		$payload['external_order_id'] = $ext;
		$json                        = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$now                         = RKC_DB::now();
		$cod_minor                   = (int) $draft['money']['cod'];

		if ( $reuse ) {
			$t   = RKC_DB::t( 'submissions' );
			$won = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET state = 'claimed', payload = %s, fingerprint = %s, partial = %d, expected_cod = %d, http_status = 0, error_code = '', error_message = '', created_by = %d, updated_at = %s WHERE id = %d AND state = 'rejected'", $json, $draft['fingerprint'], $draft['partial'] ? 1 : 0, $cod_minor, $user_id, $now, (int) $reuse->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$id  = (int) $reuse->id;
		} else {
			$won = RKC_DB::insert_unique(
				'submissions',
				array(
					'order_id'     => $order_id,
					'attempt'      => $attempt,
					'external_id'  => $ext,
					'state'        => 'claimed',
					'fingerprint'  => $draft['fingerprint'],
					'partial'      => $draft['partial'] ? 1 : 0,
					'payload'      => $json,
					'expected_cod' => $cod_minor,
					'created_by'   => $user_id,
					'created_at'   => $now,
					'updated_at'   => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s' )
			);
			$id = (int) $wpdb->insert_id;
		}
		if ( ! $won || ! $id ) {
			return new WP_Error( 'rkc_busy', __( 'Someone else is sending this order right now. Reload the page to see the result.', 'resellingkori-connector' ), array( 'stage' => 'blocked' ) );
		}
		if ( ! self::transition( $id, array( 'claimed' ), 'state = %s, tries = tries + 1', array( 'sending' ) ) ) {
			return new WP_Error( 'rkc_busy', __( 'Someone else is sending this order right now. Reload the page to see the result.', 'resellingkori-connector' ), array( 'stage' => 'blocked' ) );
		}
		RKC_Logger::info( 'Sending order to ResellingKori', array( 'external_id' => $ext, 'partial' => $draft['partial'], 'lines' => count( $payload['items'] ) ), $order_id );

		return self::transmit( $order, self::get( $id ) );
	}

	/** Send the stored snapshot and settle the row with whatever came back. */
	private static function transmit( WC_Order $order, $sub ) {
		$payload = json_decode( (string) $sub->payload, true );
		if ( ! is_array( $payload ) ) {
			return self::reject( $order, $sub, new WP_Error( 'rkc_snapshot', __( 'The stored order snapshot is unreadable.', 'resellingkori-connector' ), array( 'status' => 0 ) ) );
		}
		if ( ! empty( $payload['_erased'] ) ) {
			// The WordPress personal-data eraser blanked the recipient details in this snapshot: never send placeholders on.
			return self::reject( $order, $sub, new WP_Error( 'rkc_erased', __( 'The recipient details of this order were erased at the customer\'s request, so it can no longer be sent.', 'resellingkori-connector' ), array( 'status' => 0 ) ) );
		}
		$res = RKC_API_Client::create_order( $payload );
		return self::settle( $order, $sub, $res );
	}

	/**
	 * @param WC_Order       $order Order.
	 * @param object         $sub   Submission row (state sending).
	 * @param array|WP_Error $res   API client result.
	 */
	private static function settle( WC_Order $order, $sub, $res ) {
		if ( ! is_wp_error( $res ) ) {
			$rk = ( isset( $res['order'] ) && is_array( $res['order'] ) ) ? $res['order'] : null;
			if ( ! $rk || empty( $rk['id'] ) || ! isset( $rk['external_order_id'] ) || (string) $rk['external_order_id'] !== (string) $sub->external_id ) {
				return self::unknown( $order, $sub, 'rkc_unexpected', __( 'ResellingKori answered with something this plugin did not expect.', 'resellingkori-connector' ), isset( $res['_http'] ) ? (int) $res['_http'] : 0, 60 );
			}
			return self::accept( $order, $sub, $rk, isset( $res['_http'] ) ? (int) $res['_http'] : 200, ! empty( $res['duplicate'] ) );
		}

		switch ( self::classify( $res ) ) {
			case 'conflict':
				// ResellingKori already holds an order under this reference but with different contents. Find out which.
				self::unknown( $order, $sub, 'rkapi_order_conflict', $res->get_error_message(), 409, 1 );
				$linked = self::recover( (int) $sub->id, true );
				if ( ! is_wp_error( $linked ) && ! empty( $linked['stage'] ) && 'accepted' === $linked['stage'] ) {
					$order->add_order_note( __( 'ResellingKori already had an order under this reference with different contents. It was linked to this order. Check the items before it ships, or cancel it and send again.', 'resellingkori-connector' ) );
					$linked['warning'] = __( 'ResellingKori already held an order for this reference with different contents. It has been linked; please check it carefully.', 'resellingkori-connector' );
				}
				return $linked;
			case 'unknown':
				$d = (array) $res->get_error_data();
				return self::unknown( $order, $sub, (string) $res->get_error_code(), $res->get_error_message(), isset( $d['status'] ) ? (int) $d['status'] : 0, max( 60, isset( $d['retry_after'] ) ? (int) $d['retry_after'] : 0 ) );
			default:
				return self::reject( $order, $sub, $res );
		}
	}

	/** @return string accepted-equivalent kinds: rejected | unknown | conflict */
	public static function classify( WP_Error $e ) {
		$d      = (array) $e->get_error_data();
		$code   = (string) $e->get_error_code();
		$status = isset( $d['status'] ) ? (int) $d['status'] : 0;
		if ( 'rkapi_order_conflict' === $code ) {
			return 'conflict';
		}
		if ( 'rkapi_in_progress' === $code || ! empty( $d['outcome_unknown'] ) ) {
			return 'unknown';
		}
		if ( $status >= 500 || in_array( $status, array( 408, 425, 429 ), true ) ) {
			return 'unknown';
		}
		return 'rejected';
	}

	/* --------------------------------------------------------- Settle paths */

	private static function accept( WC_Order $order, $sub, array $rk, $http, $duplicate ) {
		$number = substr( (string) ( isset( $rk['number'] ) ? $rk['number'] : $rk['id'] ), 0, 64 );
		$won    = self::transition( $sub->id, array( 'claimed', 'sending', 'unknown' ), "state = %s, rk_order_id = %d, rk_order_number = %s, http_status = %d, error_code = '', error_message = ''", array( 'accepted', (int) $rk['id'], $number, (int) $http ) );
		$fresh  = self::get( $sub->id );
		if ( ! $won ) {
			if ( $fresh && 'accepted' === $fresh->state ) {
				return self::result( $fresh, true ); // Settled by someone else a moment ago.
			}
			return new WP_Error( 'rkc_state_changed', __( 'The state of this order changed while it was being sent. Reload the page.', 'resellingkori-connector' ), array( 'stage' => 'blocked' ) );
		}

		self::link_order( $order, $fresh, $rk );

		$partial = (bool) $fresh->partial;
		$note    = $partial
			? sprintf( /* translators: %s: ResellingKori order number */ __( 'Sent to ResellingKori as order %s (selected items only).', 'resellingkori-connector' ), '#' . $number )
			: sprintf( /* translators: %s: ResellingKori order number */ __( 'Sent to ResellingKori as order %s.', 'resellingkori-connector' ), '#' . $number );
		if ( (int) $fresh->expected_cod > 0 ) {
			$note .= ' ' . sprintf( /* translators: %s: amount */ __( 'The courier will collect %s.', 'resellingkori-connector' ), RKC_Money::display( (int) $fresh->expected_cod ) );
		}
		$order->add_order_note( $note );

		$after = (string) RKC_Settings::get( 'after_send' );
		if ( ! $partial && '' !== $after && $order->get_status() !== $after ) {
			$order->update_status( $after, __( 'Sent to ResellingKori.', 'resellingkori-connector' ) );
		}

		RKC_Logger::info( 'ResellingKori accepted the order', array( 'rk_order' => (int) $rk['id'], 'number' => $number, 'duplicate' => $duplicate, 'http' => (int) $http ), (int) $order->get_id() );
		do_action( 'rkc_order_submitted', $order, $fresh, $rk );
		return self::result( $fresh, $duplicate );
	}

	/**
	 * A different ResellingKori order replaces the previous one (the first was cancelled and the order was sent again).
	 * Status, tracking and the event sequence belong to the old order and start from scratch: sequence numbers restart at 1
	 * for every ResellingKori order, so keeping the old counter would make the new order's events look stale.
	 */
	private static function forget_previous_remote( WC_Order $order, $new_rk_id ) {
		$old = (int) $order->get_meta( '_rkc_rk_order_id' );
		if ( $old && $old !== (int) $new_rk_id ) {
			foreach ( array( '_rkc_event_seq', '_rkc_rk_status', '_rkc_tracking', '_rkc_remote_error', '_rkc_rk_updated_at', '_rkc_synced_at' ) as $k ) {
				$order->delete_meta_data( $k );
			}
		}
	}

	/** Mirror the identity of the ResellingKori order onto the store order. */
	private static function link_order( WC_Order $order, $sub, array $rk ) {
		$sent = array();
		if ( ! empty( $rk['items'] ) && is_array( $rk['items'] ) ) {
			foreach ( $rk['items'] as $it ) {
				if ( isset( $it['external_item_id'] ) ) {
					$sent[] = (int) $it['external_item_id'];
				}
			}
		}
		if ( ! $sent ) {
			$p = json_decode( (string) $sub->payload, true );
			foreach ( ( is_array( $p ) && ! empty( $p['items'] ) ) ? $p['items'] : array() as $it ) {
				$sent[] = (int) $it['external_item_id'];
			}
		}
		self::forget_previous_remote( $order, (int) $rk['id'] );
		$order->update_meta_data( '_rkc_submission_id', (int) $sub->id );
		$order->update_meta_data( '_rkc_rk_order_id', (int) $rk['id'] );
		$order->update_meta_data( '_rkc_rk_order_number', (string) $sub->rk_order_number );
		$order->update_meta_data( '_rkc_partial', ( (bool) $sub->partial || ! empty( $rk['partial'] ) ) ? 1 : 0 );
		$order->update_meta_data( '_rkc_sent_items', array_values( array_unique( $sent ) ) );
		$order->delete_meta_data( '_rkc_last_error' );
		$order->save();
		RKC_Webhook::apply_remote( $order, $rk, 'submit' ); // Status, tracking, sequence, open flag.
	}

	private static function reject( WC_Order $order, $sub, WP_Error $e ) {
		$d      = (array) $e->get_error_data();
		$code   = (string) $e->get_error_code();
		$msg    = substr( $e->get_error_message(), 0, 500 );
		$status = isset( $d['status'] ) ? (int) $d['status'] : 0;
		self::transition( $sub->id, array( 'claimed', 'sending', 'unknown' ), 'state = %s, http_status = %d, error_code = %s, error_message = %s', array( 'rejected', $status, $code, $msg ) );

		$detail = array(
			'code'      => $code,
			'message'   => $msg,
			'time'      => time(),
			'fields'    => self::trim_details( isset( $d['fields'] ) ? $d['fields'] : array() ),
			'changes'   => self::trim_details( isset( $d['changes'] ) ? $d['changes'] : array() ),
			'shortages' => self::trim_details( isset( $d['shortages'] ) ? $d['shortages'] : array() ),
		);
		$order->update_meta_data( '_rkc_last_error', $detail );
		$order->save();
		$order->add_order_note( sprintf( /* translators: %s: reason */ __( 'ResellingKori did not accept the order: %s', 'resellingkori-connector' ), $msg ) );
		RKC_Logger::warning( 'ResellingKori rejected the order', array( 'code' => $code, 'http' => $status ), (int) $order->get_id() );
		do_action( 'rkc_order_rejected', $order, $sub, $e );
		return new WP_Error( $code, $msg, array( 'stage' => 'rejected', 'status' => $status, 'details' => $detail ) );
	}

	private static function unknown( WC_Order $order, $sub, $code, $message, $http, $delay ) {
		$message = substr( (string) $message, 0, 500 );
		self::transition( $sub->id, array( 'claimed', 'sending', 'unknown' ), 'state = %s, http_status = %d, error_code = %s, error_message = %s', array( 'unknown', (int) $http, (string) $code, $message ) );
		$friendly = __( 'ResellingKori did not confirm this order, so it may or may not have arrived. Nothing will be sent twice: the plugin checks with ResellingKori automatically, or press "Check status".', 'resellingkori-connector' );
		$order->update_meta_data( '_rkc_last_error', array( 'code' => 'rkc_unknown', 'message' => $friendly, 'time' => time(), 'fields' => array(), 'changes' => array(), 'shortages' => array() ) );
		$order->save();
		RKC_Logger::warning( 'Outcome unknown, will check with ResellingKori', array( 'code' => $code, 'http' => (int) $http ), (int) $order->get_id() );
		self::schedule_recovery( (int) $sub->id, (int) $delay );
		return new WP_Error( 'rkc_unknown', $friendly, array( 'stage' => 'unknown', 'detail' => $message ) );
	}

	private static function trim_details( $list ) {
		$out = array();
		foreach ( array_slice( is_array( $list ) ? $list : array(), 0, 20 ) as $row ) {
			if ( is_array( $row ) ) {
				$clean = array();
				foreach ( $row as $k => $v ) {
					$clean[ sanitize_key( (string) $k ) ] = is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
				}
				$out[] = $clean;
			}
		}
		return $out;
	}

	private static function result( $sub, $duplicate = false ) {
		return array(
			'stage'           => (string) $sub->state,
			'duplicate'       => (bool) $duplicate,
			'rk_order_id'     => (int) $sub->rk_order_id,
			'rk_order_number' => (string) $sub->rk_order_number,
			'external_id'     => (string) $sub->external_id,
			'partial'         => (bool) $sub->partial,
		);
	}

	/* ------------------------------------------------------------ Recovery */

	public static function schedule_recovery( $sub_id, $delay ) {
		$args = array( (int) $sub_id );
		if ( ! wp_next_scheduled( 'rkc_recover_submission', $args ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), 'rkc_recover_submission', $args );
		}
	}

	/** Cron entry point. */
	public static function cron_recover( $sub_id ) {
		self::recover( (int) $sub_id, false );
	}

	/**
	 * A claimed row that never left the server was abandoned; a row that was "sending" for too long may have
	 * reached ResellingKori and becomes "unknown".
	 */
	private static function expire_stale( $sub ) {
		$age = time() - (int) strtotime( $sub->updated_at . ' UTC' );
		if ( $age <= self::STALE_SECONDS ) {
			return $sub;
		}
		if ( 'claimed' === $sub->state ) {
			self::transition( $sub->id, array( 'claimed' ), 'state = %s, error_code = %s, error_message = %s', array( 'rejected', 'rkc_abandoned', __( 'The send was interrupted before anything was transmitted. You can send the order again.', 'resellingkori-connector' ) ) );
		} elseif ( 'sending' === $sub->state ) {
			self::transition( $sub->id, array( 'sending' ), 'state = %s, error_code = %s, error_message = %s', array( 'unknown', 'rkc_interrupted', __( 'The send was interrupted; ResellingKori may or may not have received the order.', 'resellingkori-connector' ) ) );
		}
		return self::get( $sub->id );
	}

	/**
	 * Find out what ResellingKori holds for an unknown submission, and only when it holds nothing send the same snapshot again.
	 *
	 * @param int  $sub_id Submission id.
	 * @param bool $manual True when a person asked (ignores the automatic retry cap).
	 * @return array|WP_Error
	 */
	public static function recover( $sub_id, $manual = false ) {
		global $wpdb;
		$sub = self::get( $sub_id );
		if ( ! $sub ) {
			return new WP_Error( 'rkc_not_found', __( 'Submission not found.', 'resellingkori-connector' ) );
		}
		$order = wc_get_order( (int) $sub->order_id );
		if ( ! $order ) {
			return new WP_Error( 'rkc_no_order', __( 'The order no longer exists.', 'resellingkori-connector' ) );
		}
		$sub = self::expire_stale( $sub );
		if ( 'accepted' === $sub->state ) {
			return self::result( $sub, true );
		}
		if ( 'unknown' !== $sub->state ) {
			return new WP_Error( 'rkc_nothing_to_do', __( 'There is nothing to check for this order.', 'resellingkori-connector' ), array( 'stage' => (string) $sub->state ) );
		}
		if ( ! $manual && (int) $sub->tries >= self::MAX_AUTO_TRIES ) {
			return new WP_Error( 'rkc_gave_up', __( 'Automatic checks have stopped. Press "Check status" to try again.', 'resellingkori-connector' ), array( 'stage' => 'unknown' ) );
		}

		// Compare-and-swap on tries: only one process checks at a time.
		$t    = RKC_DB::t( 'submissions' );
		$took = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET tries = tries + 1, updated_at = %s WHERE id = %d AND state = 'unknown' AND tries = %d", RKC_DB::now(), (int) $sub->id, (int) $sub->tries ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ! $took ) {
			return new WP_Error( 'rkc_busy', __( 'This order is already being checked. Reload in a moment.', 'resellingkori-connector' ), array( 'stage' => 'unknown' ) );
		}
		$sub = self::get( $sub_id );

		$res = RKC_API_Client::lookup_order( $sub->external_id );
		if ( ! is_wp_error( $res ) ) {
			$rk = ( isset( $res['order'] ) && is_array( $res['order'] ) && ! empty( $res['order']['id'] ) ) ? $res['order'] : null;
			if ( $rk ) {
				if ( 'cancelled' === ( isset( $res['state'] ) ? $res['state'] : '' ) || 'cancelled' === ( isset( $rk['status'] ) ? $rk['status'] : '' ) ) {
					return self::adopt_cancelled( $order, $sub, $rk );
				}
				return self::accept( $order, $sub, $rk, 200, true );
			}
			return self::still_unknown( $order, $sub, __( 'ResellingKori is still processing this order.', 'resellingkori-connector' ), 0 );
		}

		if ( 'rkapi_order_not_found' === $res->get_error_code() ) {
			// ResellingKori holds nothing for this reference. Sending the same snapshot again is safe: it is idempotent.
			RKC_Logger::info( 'Nothing found at ResellingKori; sending the stored snapshot again', array( 'external_id' => $sub->external_id ), (int) $order->get_id() );
			if ( ! self::transition( $sub->id, array( 'unknown' ), 'state = %s', array( 'sending' ) ) ) {
				return new WP_Error( 'rkc_busy', __( 'This order is already being checked. Reload in a moment.', 'resellingkori-connector' ), array( 'stage' => 'unknown' ) );
			}
			return self::transmit( $order, self::get( $sub->id ) );
		}

		$d = (array) $res->get_error_data();
		return self::still_unknown( $order, $sub, $res->get_error_message(), isset( $d['retry_after'] ) ? (int) $d['retry_after'] : 0 );
	}

	private static function still_unknown( WC_Order $order, $sub, $message, $retry_after ) {
		self::transition( $sub->id, array( 'unknown' ), 'error_message = %s', array( substr( (string) $message, 0, 500 ) ) );
		$tries = (int) $sub->tries;
		if ( $tries < self::MAX_AUTO_TRIES ) {
			self::schedule_recovery( (int) $sub->id, max( (int) $retry_after, self::BACKOFF[ min( $tries, count( self::BACKOFF ) - 1 ) ] ) );
		} else {
			self::notify(
				'unknown-' . $sub->id,
				/* translators: %s: order number */
				sprintf( __( 'Order %s: ResellingKori status is still unknown', 'resellingkori-connector' ), $order->get_order_number() ),
				sprintf( /* translators: 1: order number, 2: link */ __( "The plugin could not confirm whether ResellingKori received order %1\$s. It will not send it again by itself.\n\nOpen the order and press \"Check status\":\n%2\$s", 'resellingkori-connector' ), $order->get_order_number(), $order->get_edit_order_url() )
			);
		}
		return new WP_Error( 'rkc_unknown', __( 'ResellingKori has not confirmed this order yet. It will be checked again; nothing is sent twice.', 'resellingkori-connector' ), array( 'stage' => 'unknown', 'detail' => (string) $message ) );
	}

	/** The order exists at ResellingKori but was already cancelled there. */
	private static function adopt_cancelled( WC_Order $order, $sub, array $rk ) {
		self::transition( $sub->id, array( 'sending', 'unknown', 'claimed', 'accepted' ), "state = %s, rk_order_id = %d, rk_order_number = %s, error_code = '', error_message = ''", array( 'cancelled', (int) $rk['id'], substr( (string) $rk['number'], 0, 64 ) ) );
		self::forget_previous_remote( $order, (int) $rk['id'] );
		$order->update_meta_data( '_rkc_rk_order_id', (int) $rk['id'] );
		$order->update_meta_data( '_rkc_rk_order_number', (string) $rk['number'] );
		$order->delete_meta_data( '_rkc_last_error' );
		$order->save();
		RKC_Webhook::apply_remote( $order, $rk, 'recover' );
		$order->add_order_note( __( 'ResellingKori reports that this order was cancelled. You can send it again.', 'resellingkori-connector' ) );
		return self::result( self::get( $sub->id ) );
	}

	/** Mark an accepted submission cancelled because ResellingKori cancelled it (webhook / polling). */
	public static function mark_cancelled_remote( $order_id ) {
		$sub = self::latest( $order_id );
		if ( $sub && 'accepted' === $sub->state ) {
			self::transition( $sub->id, array( 'accepted' ), 'state = %s', array( 'cancelled' ) );
		}
	}

	/** Background sweep (every 15 minutes): settle stale rows, retry due checks. */
	public static function sweep() {
		global $wpdb;
		$t    = RKC_DB::t( 'submissions' );
		$ids  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE state IN ('claimed','sending','unknown') AND updated_at < %s ORDER BY id ASC LIMIT 20", gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$done = 0;
		foreach ( $ids as $id ) {
			$sub = self::get( $id );
			if ( ! $sub ) {
				continue;
			}
			$sub = self::expire_stale( $sub );
			if ( 'unknown' !== $sub->state || (int) $sub->tries >= self::MAX_AUTO_TRIES ) {
				continue;
			}
			$due = (int) strtotime( $sub->updated_at . ' UTC' ) + self::BACKOFF[ min( max( 0, (int) $sub->tries - 1 ), count( self::BACKOFF ) - 1 ) ];
			if ( time() >= $due ) {
				self::recover( (int) $sub->id, false );
				++$done;
			}
		}
		return $done;
	}

	/* -------------------------------------------------------------- Cancel */

	/**
	 * Cancel the order at ResellingKori (only possible before it is processed or shipped).
	 *
	 * @return array|WP_Error
	 */
	public static function cancel( WC_Order $order, $reason, $user_id ) {
		$st = RKC_Orders::state( $order );
		if ( 'accepted' !== $st['stage'] ) {
			return new WP_Error( 'rkc_not_submitted', __( 'This order is not currently active at ResellingKori.', 'resellingkori-connector' ) );
		}
		$sub    = $st['submission'];
		$ext    = $sub ? (string) $sub->external_id : (string) (int) $order->get_id();
		$reason = substr( sanitize_text_field( (string) $reason ), 0, 200 );

		$res = RKC_API_Client::cancel_order( $ext, $reason );
		if ( is_wp_error( $res ) ) {
			$code = (string) $res->get_error_code();
			if ( 'rkapi_not_cancellable' === $code ) {
				return new WP_Error( $code, __( 'ResellingKori has already started processing or shipped this order, so it can no longer be cancelled here. Contact ResellingKori.', 'resellingkori-connector' ) );
			}
			if ( 'rkapi_order_not_found' === $code ) {
				return new WP_Error( 'rkc_cancel_not_found', __( 'ResellingKori has no order under this reference. Use "Check status".', 'resellingkori-connector' ) );
			}
			$d = (array) $res->get_error_data();
			if ( ! empty( $d['transient'] ) ) {
				return new WP_Error( 'rkc_cancel_unknown', __( 'ResellingKori did not confirm the cancellation. Use "Check status" to see where the order stands, then try again.', 'resellingkori-connector' ) );
			}
			return $res;
		}
		$rk = ( isset( $res['order'] ) && is_array( $res['order'] ) ) ? $res['order'] : array();
		if ( empty( $rk['id'] ) || 'cancelled' !== ( isset( $rk['status'] ) ? $rk['status'] : '' ) ) {
			return new WP_Error( 'rkc_cancel_unexpected', __( 'ResellingKori answered the cancellation with something unexpected. Use "Check status".', 'resellingkori-connector' ) );
		}

		if ( $sub ) {
			self::transition( $sub->id, array( 'accepted' ), 'state = %s', array( 'cancelled' ) );
		} else {
			// Order sent by v0.2.0: no row exists yet. Record it so a corrected order can be sent as attempt 2.
			RKC_DB::insert_unique(
				'submissions',
				array(
					'order_id'        => (int) $order->get_id(),
					'attempt'         => 1,
					'external_id'     => $ext,
					'state'           => 'cancelled',
					'fingerprint'     => '',
					'partial'         => $st['partial'] ? 1 : 0,
					'payload'         => '{}',
					'rk_order_id'     => (int) $rk['id'],
					'rk_order_number' => substr( (string) $rk['number'], 0, 64 ),
					'created_by'      => (int) $user_id,
					'created_at'      => RKC_DB::now(),
					'updated_at'      => RKC_DB::now(),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s' )
			);
		}
		RKC_Webhook::apply_remote( $order, $rk, 'cancel' );
		$order->add_order_note( sprintf( /* translators: %s: reason */ __( 'Cancelled at ResellingKori. %s', 'resellingkori-connector' ), $reason ) );
		RKC_Logger::info( 'Order cancelled at ResellingKori', array( 'external_id' => $ext ), (int) $order->get_id() );
		do_action( 'rkc_order_cancelled', $order, $rk );
		return array( 'stage' => 'cancelled', 'already' => ! empty( $res['already_cancelled'] ) );
	}

	/* ------------------------------------------------------------- Listings */

	private static function latest_join() {
		$t = RKC_DB::t( 'submissions' );
		return "{$t} s INNER JOIN (SELECT order_id, MAX(attempt) AS a FROM {$t} GROUP BY order_id) m ON m.order_id = s.order_id AND m.a = s.attempt";
	}

	/**
	 * The newest submission of every order, newest first.
	 *
	 * @param string $state  Optional state filter.
	 * @param int    $limit  Rows.
	 * @param int    $offset Offset.
	 * @return object[]
	 */
	public static function latest_rows( $state = '', $limit = 20, $offset = 0 ) {
		global $wpdb;
		$join = self::latest_join();
		if ( '' !== $state ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.* FROM {$join} WHERE s.state = %s ORDER BY s.id DESC LIMIT %d OFFSET %d", $state, (int) $limit, (int) $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.* FROM {$join} ORDER BY s.id DESC LIMIT %d OFFSET %d", (int) $limit, (int) $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/** @return array state => number of orders whose newest submission is in that state. */
	public static function counts() {
		global $wpdb;
		$join = self::latest_join();
		$rows = (array) $wpdb->get_results( "SELECT s.state AS st, COUNT(*) AS n FROM {$join} GROUP BY s.state", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$out  = array( 'claimed' => 0, 'sending' => 0, 'unknown' => 0, 'rejected' => 0, 'accepted' => 0, 'cancelled' => 0 );
		foreach ( $rows as $r ) {
			$out[ $r['st'] ] = (int) $r['n'];
		}
		return $out;
	}

	/* -------------------------------------------------------- Notifications */

	/** Email about a problem nobody is watching (rate limited: once per 6 hours per subject). */
	public static function notify( $key, $subject, $message ) {
		if ( 'yes' !== RKC_Settings::get( 'notify_failures' ) ) {
			return;
		}
		$tk = 'rkc_n_' . md5( (string) $key );
		if ( get_transient( $tk ) ) {
			return;
		}
		set_transient( $tk, 1, 6 * HOUR_IN_SECONDS );
		$to = (string) RKC_Settings::get( 'notify_email' );
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		wp_mail( $to, '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . $subject, $message );
	}
}
