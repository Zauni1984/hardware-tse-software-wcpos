<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kassenabschluss (Z-Abschluss): closes every finished transaction that is not
 * in a closing yet, numbers it (Z_NR) and freezes the master data, so the
 * DSFinV-K export can be generated for any period later.
 */
class HWTSE_Closing {

	public static function init(): void {
		add_action( 'hwtse_daily_closing', array( __CLASS__, 'run_daily' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule(): void {
		$next = wp_next_scheduled( 'hwtse_daily_closing' );
		if ( 'yes' !== HWTSE_Settings::get( 'closing_auto' ) ) {
			if ( $next ) {
				wp_clear_scheduled_hook( 'hwtse_daily_closing' );
			}
			return;
		}
		if ( $next ) {
			return;
		}
		$time = preg_match( '/^\d{1,2}:\d{2}$/', (string) HWTSE_Settings::get( 'closing_time' ) ) ? HWTSE_Settings::get( 'closing_time' ) : '23:58';
		$at   = new DateTimeImmutable( 'today ' . $time, wp_timezone() );
		if ( $at->getTimestamp() <= time() ) {
			$at = $at->modify( '+1 day' );
		}
		wp_schedule_event( $at->getTimestamp(), 'daily', 'hwtse_daily_closing' );
	}

	public static function run_daily(): void {
		try {
			self::create( 0 );
		} catch ( Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Kassenabschluss: ' . $e->getMessage(), array( 'source' => 'hardware-tse-wcpos' ) );
			}
		}
	}

	/**
	 * Create the next closing. Returns null when there is nothing to close.
	 */
	public static function create( int $user_id ): ?array {
		$lock = 'hwtse_closing_lock';
		if ( get_transient( $lock ) ) {
			throw new HWTSE_Exception( 'Ein Kassenabschluss läuft bereits.', 'E_LOCKED', 409 );
		}
		set_transient( $lock, 1, 120 );
		try {
			$now  = new DateTimeImmutable( 'now', wp_timezone() );
			$rows = HWTSE_Journal::rows_for_closing( gmdate( 'Y-m-d H:i:s' ) );
			if ( empty( $rows ) ) {
				return null;
			}
			$last_ts = 0;
			foreach ( $rows as $r ) {
				$last_ts = max( $last_ts, (int) strtotime( (string) $r['requested_gmt'] . ' UTC' ) );
			}
			$business_date = ( new DateTimeImmutable( '@' . $last_ts ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
			$z_nr          = HWTSE_Journal::last_z_nr() + 1;
			$ids           = array_map( static fn( $r ) => (int) $r['id'], $rows );
			$id            = HWTSE_Journal::insert_closing(
				array(
					'z_nr'          => $z_nr,
					'created_gmt'   => gmdate( 'Y-m-d H:i:s' ),
					'created_local' => $now->format( DATE_RFC3339 ),
					'business_date' => $business_date,
					'client_id'     => (string) HWTSE_Settings::get( 'client_id' ),
					'tx_count'      => count( $rows ),
					'first_id'      => min( $ids ),
					'last_id'       => max( $ids ),
					'totals_json'   => self::totals( $rows ),
					'master_json'   => self::master_data(),
					'user_id'       => $user_id,
				)
			);
			if ( ! $id ) {
				throw new HWTSE_Exception( 'Kassenabschluss konnte nicht gespeichert werden.', 'E_DB', 500 );
			}
			HWTSE_Journal::assign_closing( $ids, $id );
			return HWTSE_Journal::closing( $id );
		} finally {
			delete_transient( $lock );
		}
	}

	/** Master data frozen with each closing (DSFinV-K Stamm_* tables). */
	public static function master_data(): array {
		$s     = HWTSE_Settings::all();
		$wcpos = defined( 'WCPOS\\WooCommercePOS\\VERSION' ) ? (string) constant( 'WCPOS\\WooCommercePOS\\VERSION' ) : '';
		return array(
			'company'  => array(
				'name'    => $s['company_name'],
				'street'  => $s['company_street'],
				'zip'     => $s['company_zip'],
				'city'    => $s['company_city'],
				'country' => $s['company_country'],
				'stnr'    => $s['company_stnr'],
				'ustid'   => $s['company_ustid'],
			),
			'location' => array(
				'name'    => $s['location_name'],
				'street'  => $s['location_street'],
				'zip'     => $s['location_zip'],
				'city'    => $s['location_city'],
				'country' => $s['location_country'],
				'ustid'   => $s['company_ustid'],
			),
			'register' => array(
				'brand'      => $s['register_brand'],
				'model'      => $s['register_model'],
				'serial'     => $s['client_id'],
				'sw_brand'   => 'WCPOS / Hardware-TSE (Epson) für WCPOS',
				'sw_version' => trim( ( '' !== $wcpos ? 'WCPOS ' . $wcpos . ' / ' : '' ) . 'HWTSE ' . HWTSE_VERSION ),
				'currency'   => get_woocommerce_currency(),
			),
			'tse'      => HWTSE_Settings::tse_info(),
			'log_time_format' => $s['log_time_format'],
		);
	}

	/** Totals for the Z-Bon. */
	public static function totals( array $rows ): array {
		$t = array(
			'sales'      => 0,
			'refunds'    => 0,
			'aborts'     => 0,
			'cash_moves' => 0,
			'unsigned'   => 0,
			'revenue'    => 0.0,
			'by_vat'     => array(),
			'by_payment' => array(),
			'cash_moves_by_reason' => array(),
			'cash_total' => 0.0,
		);
		foreach ( $rows as $r ) {
			if ( 'FINISHED' !== $r['state'] ) {
				++$t['unsigned'];
			}
			if ( 'abort' === $r['kind'] ) {
				++$t['aborts'];
				continue;
			}
			if ( 'cash' === $r['kind'] ) {
				++$t['cash_moves'];
				$reason = (string) ( $r['meta_json']['reason'] ?? 'Einzahlung' );
				$t['cash_moves_by_reason'][ $reason ] = round( ( $t['cash_moves_by_reason'][ $reason ] ?? 0 ) + (float) $r['total'], 2 );
			} else {
				++$t[ 'refund' === $r['kind'] ? 'refunds' : 'sales' ];
				$t['revenue'] = round( $t['revenue'] + (float) $r['total'], 2 );
				foreach ( (array) $r['vat_json'] as $key => $v ) {
					$cur = $t['by_vat'][ $key ] ?? array(
						'brutto' => 0.0,
						'netto'  => 0.0,
						'ust'    => 0.0,
					);
					foreach ( array( 'brutto', 'netto', 'ust' ) as $f ) {
						$cur[ $f ] = round( $cur[ $f ] + (float) ( $v[ $f ] ?? 0 ), 2 );
					}
					$t['by_vat'][ $key ] = $cur;
				}
			}
			foreach ( (array) $r['payment_json'] as $p ) {
				$k                     = $p['type'] . '|' . $p['name'];
				$t['by_payment'][ $k ] = round( ( $t['by_payment'][ $k ] ?? 0 ) + (float) $p['amount'], 2 );
				if ( 'Bar' === $p['type'] ) {
					$t['cash_total'] = round( $t['cash_total'] + (float) $p['amount'], 2 );
				}
			}
		}
		ksort( $t['by_vat'] );
		return $t;
	}

	/* ---------- Z-Bon ---------- */

	public static function render_z_bon( array $closing ): string {
		$t      = $closing['totals_json'];
		$m      = $closing['master_json'];
		$money  = static fn( $v ) => number_format( (float) $v, 2, ',', '.' ) . ' €';
		$h      = static fn( $v ) => esc_html( (string) $v );
		$out    = '<div class="hwtse-zbon">';
		$out   .= '<h2>Z-Bon Nr. ' . $h( $closing['z_nr'] ) . '</h2>';
		$out   .= '<p>' . $h( $m['company']['name'] ?? '' ) . '<br>' . $h( $m['location']['name'] ?? '' ) . ', ' . $h( $m['location']['street'] ?? '' ) . ', ' . $h( ( $m['location']['zip'] ?? '' ) . ' ' . ( $m['location']['city'] ?? '' ) ) . '</p>';
		$out   .= '<p>Kasse: ' . $h( $closing['client_id'] ) . '<br>Geschäftstag: ' . $h( wp_date( 'd.m.Y', strtotime( $closing['business_date'] . ' 12:00:00' ) ) ) . '<br>Erstellt: ' . $h( HWTSE_Receipt::local_time( $closing['created_local'] ) ) . '<br>Bon-Nr. ' . $h( $closing['first_id'] ) . ' bis ' . $h( $closing['last_id'] ) . ' (' . $h( $closing['tx_count'] ) . ' Vorgänge)</p>';
		$out   .= '<table class="widefat striped"><tbody>';
		$out   .= '<tr><td>Verkäufe</td><td>' . $h( $t['sales'] ?? 0 ) . '</td></tr>';
		$out   .= '<tr><td>Erstattungen</td><td>' . $h( $t['refunds'] ?? 0 ) . '</td></tr>';
		$out   .= '<tr><td>Abbrüche</td><td>' . $h( $t['aborts'] ?? 0 ) . '</td></tr>';
		$out   .= '<tr><td>Kassenbuchungen</td><td>' . $h( $t['cash_moves'] ?? 0 ) . '</td></tr>';
		$out   .= '<tr><td>Ohne TSE-Signatur</td><td>' . $h( $t['unsigned'] ?? 0 ) . '</td></tr>';
		$out   .= '<tr><th>Umsatz brutto</th><th>' . $money( $t['revenue'] ?? 0 ) . '</th></tr>';
		foreach ( (array) ( $t['by_vat'] ?? array() ) as $key => $v ) {
			$rate = HWTSE_Process_Data::KEY_RATES[ (int) $key ] ?? 0;
			$out .= '<tr><td>USt ' . $h( number_format( $rate, 1, ',', '' ) ) . ' %: brutto ' . $money( $v['brutto'] ) . ', netto ' . $money( $v['netto'] ) . '</td><td>USt ' . $money( $v['ust'] ) . '</td></tr>';
		}
		foreach ( (array) ( $t['by_payment'] ?? array() ) as $k => $amount ) {
			list( $type, $name ) = array_pad( explode( '|', (string) $k, 2 ), 2, '' );
			$out                .= '<tr><td>Zahlart ' . $h( $name ) . ' (' . $h( $type ) . ')</td><td>' . $money( $amount ) . '</td></tr>';
		}
		foreach ( (array) ( $t['cash_moves_by_reason'] ?? array() ) as $reason => $amount ) {
			$out .= '<tr><td>Kassenbuch: ' . $h( $reason ) . '</td><td>' . $money( $amount ) . '</td></tr>';
		}
		$out .= '<tr><th>Bar-Bewegungen gesamt</th><th>' . $money( $t['cash_total'] ?? 0 ) . '</th></tr>';
		$out .= '</tbody></table></div>';
		return $out;
	}
}
