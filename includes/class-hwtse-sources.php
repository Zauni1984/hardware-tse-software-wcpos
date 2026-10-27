<?php
defined( 'ABSPATH' ) || exit;

/**
 * Turns business events into journal rows (TSE jobs for the agent).
 *
 * The plugin does not depend on a particular register: any WooCommerce order
 * that is a cash register transaction (see is_register_order()) is recorded.
 *
 * Lifecycle of a sale:
 *   order created  → row PENDING (start_mode "create": the agent starts the TSE transaction)
 *   order paid     → process data fixed, finish_requested = 1 (the agent finishes it)
 *   cancelled/trash before payment → AVBelegabbruch (or VOID when the TSE never saw it)
 *   refund         → own row with negative amounts (start + finish together)
 */
class HWTSE_Sources {

	public static function init(): void {
		// Priority 5: the row must exist before WCPOS cloud print evaluates the order (priority 20, at shutdown).
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ), 5, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ), 5, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ), 5, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ), 5, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_abandoned' ), 5, 1 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_abandoned' ), 5, 1 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'on_abandoned' ), 5, 1 );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'on_abandoned' ), 5, 1 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refunded' ), 5, 2 );
		add_action( 'hwtse_housekeeping', array( __CLASS__, 'housekeeping' ) );
		add_action( 'hwtse_check_deadline', array( __CLASS__, 'check_deadline' ), 10, 1 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'wp_head', array( __CLASS__, 'wake_agent_script' ), 1 );
	}

	/**
	 * On the WCPOS checkout pages: wake a TSE agent running in another tab of the
	 * same browser right away instead of waiting for its next poll.
	 */
	public static function wake_agent_script(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! HWTSE_Settings::is_enabled() || ! str_contains( $uri, '/wcpos-checkout/' ) ) {
			return;
		}
		echo "<script>try{var c=new BroadcastChannel('hwtse');c.postMessage({type:'wake'});setTimeout(function(){c.postMessage({type:'wake'});},1500);}catch(e){}</script>\n";
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( 'hwtse_housekeeping' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'hwtse_housekeeping' );
		}
	}

	/* ---------- which orders belong into the register ---------- */

	public static function is_register_order( WC_Order $order ): bool {
		$is = false;
		if ( 'woocommerce-pos' === $order->get_created_via() || '1' === (string) $order->get_meta( '_pos' ) ) {
			$is = true;
		} elseif ( '' !== (string) $order->get_meta( '_woocommerce_pos_uuid' ) || '' !== (string) $order->get_meta( '_pos_user' ) ) {
			$is = true; // Online order taken over and paid at the POS.
		} elseif ( in_array( (string) $order->get_payment_method(), HWTSE_Settings::list_setting( 'pos_gateways' ), true ) ) {
			$is = true; // Paid at the counter (cash, card terminal, "Bar (nur für Laden)").
		}
		return (bool) apply_filters( 'hwtse_is_register_order', $is, $order );
	}

	private static function active(): bool {
		return HWTSE_Settings::is_enabled();
	}

	/* ---------- order hooks ---------- */

	public static function on_new_order( $order_id ): void {
		if ( ! self::active() || 'create' !== HWTSE_Settings::get( 'start_mode' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! self::is_register_order( $order ) || $order->is_paid() ) {
			return;
		}
		self::ensure_sale_row( $order );
	}

	public static function on_paid( $order_id ): void {
		if ( ! self::active() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! self::is_register_order( $order ) ) {
			return;
		}
		self::request_sale_finish( $order );
	}

	public static function on_abandoned( $order_id ): void {
		if ( ! self::active() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$row = HWTSE_Journal::for_order( $order->get_id() );
		if ( ! $row || (int) $row['finish_requested'] || ! in_array( $row['state'], array( 'PENDING', 'ACTIVE' ), true ) ) {
			return; // Nothing open; paid receipts are reversed through refunds, never aborted.
		}
		self::abort_row( $row, $order );
	}

	public static function on_refunded( $order_id, $refund_id ): void {
		if ( ! self::active() ) {
			return;
		}
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order instanceof WC_Order || ! $refund instanceof WC_Order_Refund || ! self::is_register_order( $order ) ) {
			return;
		}
		if ( HWTSE_Journal::for_refund( $refund->get_id() ) ) {
			return;
		}
		$sale = HWTSE_Journal::for_order( $order->get_id() );
		$snap = HWTSE_Process_Data::snapshot( $refund, -1, (string) $order->get_payment_method() );
		$id   = HWTSE_Journal::insert(
			array(
				'kind'             => 'refund',
				'order_id'         => $order->get_id(),
				'refund_id'        => $refund->get_id(),
				'ref_journal_id'   => $sale ? (int) $sale['id'] : 0,
				'state'            => 'PENDING',
				'finish_requested' => 1,
				'requested_gmt'    => gmdate( 'Y-m-d H:i:s' ),
				'bon_type'         => 'Beleg',
				'process_data'     => HWTSE_Process_Data::kassenbeleg( 'Beleg', $snap['vat'], $snap['payments'] ),
				'total'            => $snap['total'],
				'vat_json'         => $snap['vat'],
				'payment_json'     => $snap['payments'],
				'lines_json'       => $snap['lines'],
				'meta_json'        => self::meta_for( $order, $refund ),
			)
		);
		self::schedule_deadline( $id );
		$order->add_order_note( sprintf( 'TSE: Erstattung #%d an den TSE-Agenten übergeben (Journal %d).', $refund->get_id(), $id ) );
	}

	/* ---------- operations ---------- */

	/** Create the sale row of an order if it does not exist yet. */
	public static function ensure_sale_row( WC_Order $order ): array {
		$row = HWTSE_Journal::for_order( $order->get_id() );
		if ( $row && in_array( $row['state'], array( 'PENDING', 'ACTIVE', 'FINISHED', 'FAILED' ), true ) && 'abort' !== $row['kind'] ) {
			return $row;
		}
		$id = HWTSE_Journal::insert(
			array(
				'kind'     => 'sale',
				'order_id' => $order->get_id(),
				'state'    => 'PENDING',
				'bon_type' => 'Beleg',
			)
		);
		return HWTSE_Journal::get( $id );
	}

	/** Fix the process data of a paid order and ask the agent to finish its TSE transaction. */
	public static function request_sale_finish( WC_Order $order ): ?array {
		$row = self::ensure_sale_row( $order );
		if ( (int) $row['finish_requested'] ) {
			return $row; // Idempotent: processing → completed must not sign twice.
		}
		$snap = HWTSE_Process_Data::snapshot( $order, 1, (string) $order->get_payment_method() );
		HWTSE_Journal::update(
			(int) $row['id'],
			array(
				'finish_requested' => 1,
				'requested_gmt'    => gmdate( 'Y-m-d H:i:s' ),
				'bon_type'         => 'Beleg',
				'process_data'     => HWTSE_Process_Data::kassenbeleg( 'Beleg', $snap['vat'], $snap['payments'] ),
				'total'            => $snap['total'],
				'vat_json'         => $snap['vat'],
				'payment_json'     => $snap['payments'],
				'lines_json'       => $snap['lines'],
				'meta_json'        => self::meta_for( $order ),
			)
		);
		self::schedule_deadline( (int) $row['id'] );
		return HWTSE_Journal::get( (int) $row['id'] );
	}

	/** Abandoned before payment: AVBelegabbruch if the TSE knows the transaction, VOID otherwise. */
	public static function abort_row( array $row, ?WC_Order $order ): void {
		// Never started and no agent working on it: the TSE never saw it, nothing to sign.
		// (If an agent still reports a start for a VOID row, the REST API turns it into an abort.)
		if ( 'PENDING' === $row['state'] && (int) $row['tx_number'] <= 0 && (int) $row['lease_until'] < time() ) {
			if ( HWTSE_Journal::transition( (int) $row['id'], array( 'PENDING' ), array( 'state' => 'VOID' ) ) ) {
				return;
			}
			$row = HWTSE_Journal::get( (int) $row['id'] );
		}
		$snap = $order ? HWTSE_Process_Data::snapshot( $order, 1, '' ) : array(
			'lines'    => array(),
			'vat'      => array(),
			'total'    => 0.0,
			'payments' => array(),
		);
		HWTSE_Journal::update(
			(int) $row['id'],
			array(
				'kind'             => 'abort',
				'finish_requested' => 1,
				'requested_gmt'    => gmdate( 'Y-m-d H:i:s' ),
				'bon_type'         => 'AVBelegabbruch',
				'process_data'     => HWTSE_Process_Data::kassenbeleg( 'AVBelegabbruch', $snap['vat'], array() ),
				'total'            => 0,
				'vat_json'         => $snap['vat'],
				'payment_json'     => array(),
				'lines_json'       => $snap['lines'],
				'meta_json'        => $order ? self::meta_for( $order ) : array( 'note' => 'Vorgang abgebrochen' ),
				// An abort never holds a receipt, so it never needs the outage fallback.
				'fallback_released' => 1,
			)
		);
	}

	/** Cashier, customer and order reference for the DSFinV-K Bonkopf. */
	public static function meta_for( WC_Order $order, ?WC_Order_Refund $refund = null ): array {
		$uid = (int) $order->get_meta( '_pos_user' );
		if ( $refund && $refund->get_refunded_by() ) {
			$uid = (int) $refund->get_refunded_by();
		}
		if ( ! $uid ) {
			$uid = (int) get_current_user_id();
		}
		$user = $uid ? get_userdata( $uid ) : null;
		$name = trim( $order->get_formatted_billing_full_name() );
		return array(
			'order_number'  => (string) $order->get_order_number(),
			'user_id'       => (string) $uid,
			'user_name'     => $user ? mb_substr( $user->display_name, 0, 50 ) : 'Kasse',
			'customer_id'   => $order->get_customer_id() ? (string) $order->get_customer_id() : '',
			'customer_name' => '' !== $name && $order->get_customer_id() ? mb_substr( $name, 0, 50 ) : '',
			'company'       => mb_substr( (string) $order->get_billing_company(), 0, 50 ),
			'vat_id'        => mb_substr( (string) $order->get_meta( '_billing_vat_id' ), 0, 15 ),
			'tendered'      => (float) $order->get_meta( '_pos_cash_amount_tendered' ),
			'change'        => (float) $order->get_meta( '_pos_cash_change' ),
			'refund_reason' => $refund ? mb_substr( wp_strip_all_tags( (string) $refund->get_reason() ), 0, 200 ) : '',
		);
	}

	/* ---------- deadlines / housekeeping ---------- */

	public static function schedule_deadline( int $row_id ): void {
		$seconds = max( 5, (int) HWTSE_Settings::get( 'agent_timeout', 25 ) );
		wp_schedule_single_event( time() + $seconds, 'hwtse_check_deadline', array( $row_id ) );
	}

	/**
	 * The agent did not sign in time: release the receipt with the outage notice.
	 * The row stays in the queue, so the TSE still records it once it is back.
	 */
	public static function check_deadline( $row_id ): void {
		$row = HWTSE_Journal::get( (int) $row_id );
		if ( ! $row || ! (int) $row['finish_requested'] || (int) $row['fallback_released'] || ! in_array( $row['state'], array( 'PENDING', 'ACTIVE' ), true ) ) {
			return;
		}
		$seconds = max( 5, (int) HWTSE_Settings::get( 'agent_timeout', 25 ) );
		if ( strtotime( $row['requested_gmt'] . ' UTC' ) > time() - $seconds ) {
			return;
		}
		self::release_fallback( $row, 'TSE nicht erreichbar (TSE-Agent offline oder Zeitüberschreitung)' );
	}

	public static function release_fallback( array $row, string $reason ): void {
		HWTSE_Journal::update(
			(int) $row['id'],
			array(
				'fallback_released' => 1,
				'error'             => $reason . ( '' !== (string) $row['error'] ? ' | ' . $row['error'] : '' ),
			)
		);
		$order = $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : null;
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( 'TSE-Ausfall: ' . $reason . '. Beleg ohne Signatur freigegeben, die Signatur wird nachgeholt, sobald die TSE erreichbar ist.' );
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( sprintf( 'Journal %d: %s', (int) $row['id'], $reason ), array( 'source' => 'hardware-tse-wcpos' ) );
		}
		if ( 'refund' !== $row['kind'] && $row['order_id'] ) {
			HWTSE_Print_Gate::release( (int) $row['order_id'] );
		}
	}

	/** Hourly: catch missed deadlines and abort transactions that were started but never closed. */
	public static function housekeeping(): void {
		$seconds = max( 5, (int) HWTSE_Settings::get( 'agent_timeout', 25 ) );
		foreach ( HWTSE_Journal::overdue( $seconds ) as $row ) {
			self::release_fallback( $row, 'TSE nicht erreichbar (TSE-Agent offline oder Zeitüberschreitung)' );
		}
		foreach ( HWTSE_Journal::stale_active( 12 * HOUR_IN_SECONDS ) as $row ) {
			$order = $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : null;
			if ( $order instanceof WC_Order && $order->is_paid() ) {
				self::request_sale_finish( $order ); // Missed payment hook.
				continue;
			}
			self::abort_row( $row, $order instanceof WC_Order ? $order : null );
		}
	}
}
