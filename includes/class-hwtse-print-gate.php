<?php
defined( 'ABSPATH' ) || exit;

/**
 * Holds WCPOS cloud-print jobs (e.g. Epson Server Direct Print) of a paid
 * register order until its TSE signature exists, then queues them again.
 *
 * WCPOS evaluates its print assignments at the end of the request that paid
 * the order. Returning no assignments there prints nothing; WCPOS counts
 * printed copies per order, so evaluating the order again after signing
 * prints exactly the missing copies.
 */
class HWTSE_Print_Gate {

	const HELD_META = '_hwtse_print_held';

	public static function init(): void {
		add_filter( 'woocommerce_pos_cloud_print_assignments', array( __CLASS__, 'filter_assignments' ), 50, 2 );
	}

	public static function enabled(): bool {
		return 'yes' === HWTSE_Settings::get( 'hold_print' ) && HWTSE_Settings::is_enabled();
	}

	public static function filter_assignments( $assignments, $order ) {
		if ( ! self::enabled() || ! is_array( $assignments ) || empty( $assignments ) || ! $order instanceof WC_Order ) {
			return $assignments;
		}
		$row = HWTSE_Journal::for_order( $order->get_id() );
		if ( ! $row || 'sale' !== $row['kind'] || ! (int) $row['finish_requested'] ) {
			return $assignments; // Not paid yet ("created" triggers print as configured) or not a register order.
		}
		if ( 'FINISHED' === $row['state'] || 'FAILED' === $row['state'] || (int) $row['fallback_released'] ) {
			return $assignments;
		}
		$order->update_meta_data( self::HELD_META, (string) time() );
		$order->save_meta_data();
		return array();
	}

	/**
	 * Re-run WCPOS' print evaluation for an order whose jobs were held.
	 */
	public static function release( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || '' === (string) $order->get_meta( self::HELD_META ) ) {
			return;
		}
		$order->delete_meta_data( self::HELD_META );
		$order->save_meta_data();
		$class = '\\WCPOS\\WooCommercePOS\\Services\\Cloud_Print_Trigger_Service';
		if ( ! class_exists( $class ) ) {
			return;
		}
		try {
			$service = new $class();
			if ( method_exists( $service, 'defer_order' ) ) {
				// Queued and evaluated at the end of this request, like WCPOS' own order events.
				$service->defer_order( $order_id );
			}
		} catch ( Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Cloud-Print nach TSE-Signatur fehlgeschlagen: ' . $e->getMessage(), array( 'source' => 'hardware-tse-wcpos' ) );
			}
		}
	}
}
