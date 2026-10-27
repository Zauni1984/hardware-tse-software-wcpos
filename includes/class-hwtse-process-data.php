<?php
defined( 'ABSPATH' ) || exit;

/**
 * Turns WooCommerce orders and refunds into immutable journal snapshots and the
 * TSE process data (processType "Kassenbeleg-V1", DSFinV-K Anhang C).
 *
 * Amounts are gross (incl. VAT). VAT is keyed by the DSFinV-K UST_SCHLUESSEL.
 */
class HWTSE_Process_Data {

	const PROCESS_TYPE = 'Kassenbeleg-V1';

	/** WC tax percent (4 decimals) → DSFinV-K UST_SCHLUESSEL. */
	const PERCENT_KEYS = array(
		'19.0000' => 1,
		'7.0000'  => 2,
		'10.7000' => 3,
		'5.5000'  => 4,
		'0.0000'  => 5,
		'16.0000' => 21,
		'5.0000'  => 22,
	);

	/** UST_SCHLUESSEL → position in the Kassenbeleg-V1 amount list (5 slots). */
	const KEY_SLOTS = array(
		1  => 0,
		11 => 0,
		21 => 0,
		2  => 1,
		12 => 1,
		22 => 1,
		3  => 2,
		13 => 2,
		23 => 2,
		4  => 3,
	);

	/** UST_SCHLUESSEL → rate in percent (Stamm_USt). */
	const KEY_RATES = array(
		1  => 19.0,
		2  => 7.0,
		3  => 10.7,
		4  => 5.5,
		5  => 0.0,
		6  => 0.0,
		7  => 0.0,
		21 => 16.0,
		22 => 5.0,
	);

	const KEY_NAMES = array(
		1  => 'Allgemeiner Steuersatz (§ 12 Abs. 1 UStG)',
		2  => 'Ermäßigter Steuersatz (§ 12 Abs. 2 UStG)',
		3  => 'Durchschnittsatz (§ 24 Abs. 1 Nr. 3 UStG)',
		4  => 'Durchschnittsatz (§ 24 Abs. 1 Nr. 1 UStG)',
		5  => 'Nicht Steuerbar',
		6  => 'Umsatzsteuerfrei',
		7  => 'UmsatzsteuerNichtErmittelbar',
		21 => 'Historischer allgemeiner Steuersatz (16 %)',
		22 => 'Historischer ermäßigter Steuersatz (5 %)',
	);

	public static function key_for_percent( float $percent ): int {
		$k = number_format( $percent, 4, '.', '' );
		return self::PERCENT_KEYS[ $k ] ?? ( $percent > 0 ? 1 : 5 );
	}

	/* ---------- payments ---------- */

	public static function is_cash_gateway( string $gateway ): bool {
		return in_array( $gateway, HWTSE_Settings::list_setting( 'cash_gateways' ), true );
	}

	/**
	 * DSFinV-K payment type and name for a gateway id.
	 *
	 * @return array{0:string,1:string} ZAHLART_TYP, ZAHLART_NAME
	 */
	public static function payment_type_for( string $gateway ): array {
		if ( self::is_cash_gateway( $gateway ) ) {
			$type = array( 'Bar', 'Bar' );
		} elseif ( str_contains( $gateway, 'sumup' ) ) {
			$type = array( 'Unbar', 'SumUp Terminal' );
		} elseif ( 'pos_card' === $gateway ) {
			$type = array( 'Unbar', 'Kartenzahlung' );
		} elseif ( str_contains( $gateway, 'crypto' ) ) {
			$type = array( 'Unbar', 'Kryptowährung' );
		} elseif ( in_array( $gateway, array( 'paypal', 'ppcp-gateway', 'stripe', 'klarna' ), true ) ) {
			$type = array( 'ElZahlungsdienstleister', $gateway );
		} else {
			$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
			$title    = isset( $gateways[ $gateway ] ) ? (string) $gateways[ $gateway ]->get_title() : ( '' !== $gateway ? $gateway : 'Unbar' );
			$type     = array( 'Unbar', wp_strip_all_tags( $title ) );
		}
		return (array) apply_filters( 'hwtse_payment_type', $type, $gateway );
	}

	/* ---------- snapshots ---------- */

	/**
	 * Snapshot of an order or refund: lines, VAT buckets, total, payments.
	 *
	 * @param WC_Abstract_Order $obj     Order or refund.
	 * @param int               $sign    1 for sales, -1 for refunds.
	 * @param string            $gateway Gateway id of the (parent) order; '' for no payment (abort).
	 */
	public static function snapshot( WC_Abstract_Order $obj, int $sign, string $gateway ): array {
		$lines = array();
		$vat   = array();
		$n     = 0;
		$items = array_merge( $obj->get_items( 'line_item' ), $obj->get_items( 'fee' ), $obj->get_items( 'shipping' ) );
		foreach ( $items as $item ) {
			$net      = abs( (float) $item->get_total() ) * $sign;
			$tax      = abs( (float) $item->get_total_tax() ) * $sign;
			$qty      = abs( (float) $item->get_quantity() );
			$qty      = ( $qty > 0 ? $qty : 1.0 ) * $sign;
			$percent  = self::item_percent( $item );
			$key      = self::key_for_percent( $percent );
			$brutto   = round( $net + $tax, 2 );
			$base_net = $item instanceof WC_Order_Item_Product ? abs( (float) $item->get_subtotal() ) * $sign : $net;
			$base_tax = $item instanceof WC_Order_Item_Product ? abs( (float) $item->get_subtotal_tax() ) * $sign : $tax;
			$product  = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
			++$n;
			$line = array(
				'pos'       => (string) $n,
				'type'      => $item->get_type(),
				'gv_typ'    => 'Umsatz',
				'text'      => mb_substr( wp_strip_all_tags( $item->get_name() ), 0, 255 ),
				'sku'       => $product ? mb_substr( (string) $product->get_sku(), 0, 50 ) : '',
				'gtin'      => $product ? self::gtin( $product ) : '',
				'group_id'  => '',
				'group'     => '',
				'qty'       => round( $qty, 3 ),
				'unit'      => 'shipping' === $item->get_type() ? 'Pauschale' : 'Stück',
				'key'       => $key,
				'brutto'    => $brutto,
				'netto'     => round( $net, 2 ),
				'ust'       => round( $tax, 2 ),
				'unit_br'   => round( 0.0 !== $qty ? $brutto / $qty : $brutto, 5 ),
				'base_br'   => round( $base_net + $base_tax, 2 ),
				'base_net'  => round( $base_net, 2 ),
				'base_ust'  => round( $base_tax, 2 ),
			);
			if ( $product ) {
				$cat_ids = $product->get_category_ids();
				if ( ! $cat_ids && $product->get_parent_id() ) {
					$parent  = wc_get_product( $product->get_parent_id() );
					$cat_ids = $parent ? $parent->get_category_ids() : array();
				}
				if ( $cat_ids ) {
					$term = get_term( (int) $cat_ids[0], 'product_cat' );
					if ( $term && ! is_wp_error( $term ) ) {
						$line['group_id'] = (string) $term->term_id;
						$line['group']    = mb_substr( $term->name, 0, 50 );
					}
				}
			}
			$lines[] = $line;
			self::add( $vat, $key, $brutto, $net, $tax );
		}

		$total = round( abs( (float) $obj->get_total() ) * $sign, 2 );

		// Amount-only refund (no items): spread the amount over the parent order's VAT split.
		if ( empty( $lines ) && 0.0 !== $total && $obj instanceof WC_Order_Refund ) {
			$parent = wc_get_order( $obj->get_parent_id() );
			$split  = $parent instanceof WC_Order ? self::snapshot( $parent, 1, '' )['vat'] : array();
			$base   = array_sum( array_column( $split, 'brutto' ) );
			$n      = 0;
			foreach ( $split ?: array( 5 => array( 'brutto' => 1.0 ) ) as $key => $amounts ) {
				$share  = $base > 0 ? $amounts['brutto'] / $base : 1.0;
				$brutto = round( $total * $share, 2 );
				$rate   = self::KEY_RATES[ $key ] ?? 0.0;
				$net    = round( $brutto / ( 1 + $rate / 100 ), 2 );
				++$n;
				$lines[] = array(
					'pos'      => (string) $n,
					'type'     => 'refund',
					'gv_typ'   => 'Umsatz',
					'text'     => 'Erstattung' . ( $obj->get_reason() ? ': ' . mb_substr( wp_strip_all_tags( $obj->get_reason() ), 0, 200 ) : '' ),
					'sku'      => '',
					'gtin'     => '',
					'group_id' => '',
					'group'    => '',
					'qty'      => -1.0,
					'unit'     => 'Stück',
					'key'      => (int) $key,
					'brutto'   => $brutto,
					'netto'    => $net,
					'ust'      => round( $brutto - $net, 2 ),
					'unit_br'  => -$brutto,
					'base_br'  => $brutto,
					'base_net' => $net,
					'base_ust' => round( $brutto - $net, 2 ),
				);
				self::add( $vat, (int) $key, $brutto, $net, $brutto - $net );
			}
		}

		// Rounding: the VAT buckets must add up to the order total.
		$sum  = round( array_sum( array_column( $vat, 'brutto' ) ), 2 );
		$diff = round( $total - $sum, 2 );
		if ( 0.0 !== $diff && ! empty( $vat ) && abs( $diff ) < 1.0 ) {
			uasort( $vat, static fn( $a, $b ) => abs( $b['brutto'] ) <=> abs( $a['brutto'] ) );
			$first                   = array_key_first( $vat );
			$vat[ $first ]['brutto'] = round( $vat[ $first ]['brutto'] + $diff, 2 );
			$vat[ $first ]['netto']  = round( $vat[ $first ]['netto'] + $diff, 2 );
		} elseif ( empty( $vat ) ) {
			$total = 0.0;
		} else {
			$total = $sum;
		}
		ksort( $vat );

		$payments = array();
		if ( '' !== $gateway ) {
			list( $ptype, $pname ) = self::payment_type_for( $gateway );
			$payments[]            = array(
				'type'    => $ptype,
				'name'    => mb_substr( $pname, 0, 60 ),
				'gateway' => $gateway,
				'amount'  => $total,
			);
		}
		return array(
			'lines'    => $lines,
			'vat'      => $vat,
			'total'    => $total,
			'payments' => $payments,
		);
	}

	/**
	 * Snapshot for a cash movement (Einlage/Entnahme/Anfangsbestand ...).
	 */
	public static function cash_snapshot( float $amount, string $gv_typ, string $text ): array {
		$amount = round( $amount, 2 );
		return array(
			'lines'    => array(
				array(
					'pos'      => '1',
					'type'     => 'cash',
					'gv_typ'   => $gv_typ,
					'text'     => mb_substr( $text, 0, 255 ),
					'sku'      => '',
					'gtin'     => '',
					'group_id' => '',
					'group'    => '',
					'qty'      => 1.0,
					'unit'     => '',
					'key'      => 5,
					'brutto'   => $amount,
					'netto'    => $amount,
					'ust'      => 0.0,
					'unit_br'  => $amount,
					'base_br'  => $amount,
					'base_net' => $amount,
					'base_ust' => 0.0,
				),
			),
			'vat'      => array(
				5 => array(
					'brutto' => $amount,
					'netto'  => $amount,
					'ust'    => 0.0,
				),
			),
			'total'    => $amount,
			'payments' => array(
				array(
					'type'    => 'Bar',
					'name'    => 'Bar',
					'gateway' => 'cash',
					'amount'  => $amount,
				),
			),
		);
	}

	private static function add( array &$vat, int $key, float $brutto, float $net, float $tax ): void {
		if ( ! isset( $vat[ $key ] ) ) {
			$vat[ $key ] = array(
				'brutto' => 0.0,
				'netto'  => 0.0,
				'ust'    => 0.0,
			);
		}
		$vat[ $key ]['brutto'] = round( $vat[ $key ]['brutto'] + $brutto, 2 );
		$vat[ $key ]['netto']  = round( $vat[ $key ]['netto'] + $net, 2 );
		$vat[ $key ]['ust']    = round( $vat[ $key ]['ust'] + $tax, 2 );
	}

	private static function item_percent( WC_Order_Item $item ): float {
		$taxes = method_exists( $item, 'get_taxes' ) ? $item->get_taxes() : array();
		if ( ! empty( $taxes['total'] ) ) {
			foreach ( $taxes['total'] as $rate_id => $amount ) {
				if ( '' !== $amount && null !== $amount ) {
					return (float) WC_Tax::get_rate_percent_value( (int) $rate_id );
				}
			}
		}
		$net = (float) $item->get_total();
		$tax = (float) $item->get_total_tax();
		return 0.0 !== $net ? round( $tax / $net * 100, 1 ) : 0.0;
	}

	private static function gtin( WC_Product $product ): string {
		$gtin = method_exists( $product, 'get_global_unique_id' ) ? (string) $product->get_global_unique_id() : '';
		if ( '' === $gtin ) {
			$gtin = (string) $product->get_meta( '_ts_gtin' );
		}
		return mb_substr( $gtin, 0, 50 );
	}

	/* ---------- Kassenbeleg-V1 ---------- */

	public static function amount( float $v ): string {
		return number_format( round( $v, 2 ), 2, '.', '' );
	}

	/**
	 * processData for processType Kassenbeleg-V1:
	 * "<Vorgangstyp>^<brutto 19>_<brutto 7>_<brutto 10,7>_<brutto 5,5>_<brutto 0>^<betrag>:<Bar|Unbar>_…"
	 */
	public static function kassenbeleg( string $bon_type, array $vat, array $payments ): string {
		$slots = array( 0.0, 0.0, 0.0, 0.0, 0.0 );
		foreach ( $vat as $key => $amounts ) {
			$slot           = self::KEY_SLOTS[ (int) $key ] ?? 4;
			$slots[ $slot ] += (float) $amounts['brutto'];
		}
		$pay = array();
		foreach ( self::payment_groups( $payments ) as $class => $amount ) {
			$pay[] = self::amount( $amount ) . ':' . $class;
		}
		return $bon_type . '^' . implode( '_', array_map( array( __CLASS__, 'amount' ), $slots ) ) . '^' . implode( '_', $pay );
	}

	/** Payments summed per Kassenbeleg-V1 class (Bar first, then Unbar). */
	private static function payment_groups( array $payments ): array {
		$groups = array();
		foreach ( $payments as $p ) {
			$class            = 'Bar' === ( $p['type'] ?? '' ) ? 'Bar' : 'Unbar';
			$groups[ $class ] = round( ( $groups[ $class ] ?? 0.0 ) + (float) $p['amount'], 2 );
		}
		uksort( $groups, static fn( $a, $b ) => strcmp( $a, $b ) );
		return $groups;
	}

	/* ---------- QR code (DSFinV-K Anhang I, Version V0) ---------- */

	/** ISO 8601 with milliseconds in UTC, as the QR code and DSFinV-K expect. */
	public static function tse_time( string $iso ): string {
		if ( '' === $iso ) {
			return '';
		}
		try {
			$dt = new DateTimeImmutable( $iso );
		} catch ( Exception $e ) {
			return $iso;
		}
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.v\Z' );
	}

	public static function qr_payload( array $row ): string {
		if ( 'FINISHED' !== $row['state'] ) {
			return '';
		}
		$info = HWTSE_Settings::tse_info();
		return implode(
			';',
			array(
				'V0',
				(string) $row['client_id'],
				(string) $row['process_type'],
				(string) $row['process_data'],
				(string) $row['tx_number'],
				(string) $row['signature_counter'],
				self::tse_time( (string) $row['time_start'] ),
				self::tse_time( (string) $row['time_end'] ),
				(string) ( $info['signature_algorithm'] ?? '' ),
				(string) HWTSE_Settings::get( 'log_time_format', 'utcTime' ),
				(string) $row['signature'],
				(string) ( $info['public_key'] ?? '' ),
			)
		);
	}
}
