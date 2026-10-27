<?php
defined( 'ABSPATH' ) || exit;

/**
 * Puts the TSE data into the WCPOS receipt payload (section "fiscal").
 *
 * WCPOS renders receipts live from this payload (HTML, PDF and the thermal
 * templates), so the data is on every print made after the signature exists.
 * Thermal templates need the block from templates/wcpos-thermal-tse-block.xml.
 */
class HWTSE_Receipt {

	public static function init(): void {
		add_filter( 'woocommerce_pos_receipt_data', array( __CLASS__, 'filter_receipt_data' ), 30, 3 );
	}

	public static function filter_receipt_data( $data, $order, $mode ) {
		if ( ! is_array( $data ) || 'preview' === $mode || ! $order instanceof WC_Order || ! $order->get_id() ) {
			return $data;
		}
		$row = HWTSE_Journal::for_order( $order->get_id() );
		if ( ! $row || 'sale' !== $row['kind'] ) {
			return $data;
		}
		$fiscal = is_array( $data['fiscal'] ?? null ) ? $data['fiscal'] : array();
		$fields = self::fields_for( $row );
		foreach ( HWTSE_Journal::refunds_of( $order->get_id() ) as $refund_row ) {
			$fields = array_merge( $fields, self::refund_fields( $refund_row ) );
		}
		if ( 'FINISHED' === $row['state'] ) {
			if ( '' === (string) ( $fiscal['document_label'] ?? '' ) ) {
				$fiscal['document_label'] = (string) HWTSE_Settings::get( 'receipt_label', 'Kassenbeleg' );
			}
			$fiscal['receipt_number']    = (string) $row['id'];
			$fiscal['sequence']          = (int) $row['tx_number'];
			$fiscal['qr_payload']        = HWTSE_Process_Data::qr_payload( $row );
			$fiscal['signed_at']         = HWTSE_Process_Data::tse_time( (string) $row['time_end'] );
			$fiscal['signature_excerpt'] = substr( (string) $row['signature'], 0, 12 );
			$fiscal['hash']              = (string) $row['signature'];
		}
		$existing               = is_array( $fiscal['extra_fields'] ?? null ) ? $fiscal['extra_fields'] : array();
		$fiscal['extra_fields'] = array_merge( $existing, $fields );
		$data['fiscal']         = $fiscal;
		return $data;
	}

	/** Plain-text TSE fields (label/value) for a sale row, as § 6 KassenSichV requires them on the receipt. */
	public static function fields_for( array $row ): array {
		$info = HWTSE_Settings::tse_info();
		if ( 'FINISHED' === $row['state'] ) {
			$fields = array(
				array( 'label' => 'Bon-Nr.', 'value' => (string) $row['id'] ),
				array( 'label' => 'Kassen-ID', 'value' => (string) $row['client_id'] ),
				array( 'label' => 'TSE-Seriennr.', 'value' => (string) ( $row['tse_serial'] ?: ( $info['serial_hex'] ?? '' ) ) ),
				array( 'label' => 'TSE-Transaktion', 'value' => (string) $row['tx_number'] ),
				array( 'label' => 'TSE-Start', 'value' => self::local_time( (string) $row['time_start'] ) ),
				array( 'label' => 'TSE-Ende', 'value' => self::local_time( (string) $row['time_end'] ) ),
				array( 'label' => 'TSE-Signaturzähler', 'value' => (string) $row['signature_counter'] ),
				array( 'label' => 'TSE-Zeitformat', 'value' => (string) HWTSE_Settings::get( 'log_time_format', 'utcTime' ) ),
				array( 'label' => 'TSE-Signaturalgorithmus', 'value' => (string) ( $info['signature_algorithm'] ?? '' ) ),
			);
			if ( 'yes' === HWTSE_Settings::get( 'print_signature' ) ) {
				$fields[] = array( 'label' => 'TSE-Signatur', 'value' => (string) $row['signature'] );
			}
			return $fields;
		}
		if ( (int) $row['finish_requested'] && ( (int) $row['fallback_released'] || 'FAILED' === $row['state'] ) ) {
			return array(
				array( 'label' => 'Bon-Nr.', 'value' => (string) $row['id'] ),
				array( 'label' => 'Kassen-ID', 'value' => (string) $row['client_id'] ),
				array( 'label' => 'TSE', 'value' => 'TSE ausgefallen – Beleg ohne Signatur' ),
			);
		}
		if ( (int) $row['finish_requested'] ) {
			return array(
				array( 'label' => 'Bon-Nr.', 'value' => (string) $row['id'] ),
				array( 'label' => 'TSE', 'value' => 'Signatur wird erstellt …' ),
			);
		}
		return array();
	}

	private static function refund_fields( array $row ): array {
		if ( 'FINISHED' !== $row['state'] ) {
			return array( array( 'label' => 'Erstattung Bon-Nr. ' . $row['id'], 'value' => 'TSE-Signatur ausstehend' ) );
		}
		return array(
			array( 'label' => 'Erstattung Bon-Nr.', 'value' => (string) $row['id'] . ' (' . wc_format_decimal( $row['total'], 2 ) . ' €)' ),
			array( 'label' => 'Erstattung TSE-Transaktion', 'value' => (string) $row['tx_number'] . ' / Signaturzähler ' . $row['signature_counter'] ),
		);
	}

	public static function local_time( string $iso ): string {
		if ( '' === $iso ) {
			return '';
		}
		try {
			return ( new DateTimeImmutable( $iso ) )->setTimezone( wp_timezone() )->format( 'd.m.Y H:i:s' );
		} catch ( Exception $e ) {
			return $iso;
		}
	}
}
