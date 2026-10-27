<?php
defined( 'ABSPATH' ) || exit;

/**
 * DSFinV-K export (Version 2.4) generated locally from the journal.
 *
 * The table layout (file names, column order, numeric accuracy) is read from
 * the official index.xml shipped in schema/, so every CSV matches the
 * description file that is put into the archive next to it.
 */
class HWTSE_Dsfinvk {

	const TAXONOMY_VERSION = '2.4';
	const TSE_ID           = 1;
	const CERT_CHUNK       = 1000;

	/** @var array<string,array{name:string,columns:array<int,array{name:string,numeric:bool,accuracy:int}>}> */
	private array $tables = array();

	/** @var array<string,array<int,array<int,string>>> */
	private array $rows = array();

	private int $extra_cert_columns = 0;

	public function __construct() {
		$this->load_schema();
	}

	private function load_schema(): void {
		$xml = (string) file_get_contents( HWTSE_DIR . 'schema/index.xml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$xml = preg_replace( '/^\xEF\xBB\xBF/', '', $xml );
		$xml = preg_replace( '/<!DOCTYPE[^>]*>/', '', (string) $xml );
		$doc = new DOMDocument();
		$doc->loadXML( (string) $xml );
		foreach ( $doc->getElementsByTagName( 'Table' ) as $table ) {
			$url  = trim( (string) $table->getElementsByTagName( 'URL' )->item( 0 )?->textContent );
			$cols = array();
			foreach ( $table->getElementsByTagName( 'VariableColumn' ) as $col ) {
				$numeric  = $col->getElementsByTagName( 'Numeric' )->length > 0;
				$accuracy = 0;
				if ( $numeric ) {
					$acc      = $col->getElementsByTagName( 'Accuracy' )->item( 0 );
					$accuracy = $acc ? (int) $acc->textContent : 0;
				}
				$cols[] = array(
					'name'     => trim( (string) $col->getElementsByTagName( 'Name' )->item( 0 )?->textContent ),
					'numeric'  => $numeric,
					'accuracy' => $accuracy,
				);
			}
			$this->tables[ $url ] = array(
				'name'    => trim( (string) $table->getElementsByTagName( 'Name' )->item( 0 )?->textContent ),
				'columns' => $cols,
			);
			$this->rows[ $url ]   = array();
		}
	}

	/* ---------- public API ---------- */

	/**
	 * Build the export for all closings whose business date is in [from, to] and
	 * store it as ZIP. Returns the stored file name.
	 */
	public static function export( string $from_date, string $to_date ): array {
		foreach ( array( $from_date, $to_date ) as $d ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				throw new HWTSE_Exception( 'Ungültiges Datum (JJJJ-MM-TT).', 'E_DATE' );
			}
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new HWTSE_Exception( 'PHP-Erweiterung zip fehlt auf dem Server.', 'E_ZIP', 500 );
		}
		$closings = HWTSE_Journal::closings_between( $from_date, $to_date );
		if ( empty( $closings ) ) {
			throw new HWTSE_Exception( 'Im Zeitraum gibt es keinen Kassenabschluss. Erst einen Kassenabschluss erstellen.', 'E_EMPTY', 404 );
		}
		$export = new self();
		foreach ( $closings as $closing ) {
			$export->add_closing( $closing, HWTSE_Journal::rows_of_closing( (int) $closing['id'] ) );
		}
		// Random suffix: on nginx the .htaccess of the storage folder does not apply, the name must not be guessable.
		$name = sprintf( 'dsfinvk-%s-%s-%s-%s.zip', HWTSE_Settings::sanitize_printable( (string) HWTSE_Settings::get( 'client_id' ), 30 ), str_replace( '-', '', $from_date ), str_replace( '-', '', $to_date ), bin2hex( random_bytes( 6 ) ) );
		$name = preg_replace( '/[^A-Za-z0-9._-]/', '-', $name );
		$path = HWTSE_Storage::dir( 'dsfinvk' ) . '/' . $name;
		$export->write_zip( $path );
		return array(
			'file'     => $name,
			'bytes'    => (int) filesize( $path ),
			'closings' => count( $closings ),
		);
	}

	/* ---------- building ---------- */

	public function add_closing( array $closing, array $rows ): void {
		$m    = $closing['master_json'];
		$base = array(
			'Z_KASSE_ID'   => (string) $closing['client_id'],
			'Z_ERSTELLUNG' => (string) $closing['created_local'],
			'Z_NR'         => (string) $closing['z_nr'],
		);

		$bons = array_values(
			array_filter(
				$rows,
				static fn( $r ) => in_array( $r['kind'], array( 'sale', 'refund', 'abort', 'cash' ), true )
			)
		);

		// Aggregates over all Bons of type "Beleg" (spec 2.4, 3.1.2).
		$gv     = array();
		$pay    = array();
		$cash   = 0.0;
		$all    = 0.0;
		foreach ( $bons as $r ) {
			if ( 'Beleg' !== $r['bon_type'] ) {
				continue;
			}
			foreach ( (array) $r['lines_json'] as $line ) {
				$k = $line['gv_typ'] . '|' . (int) $line['key'];
				if ( ! isset( $gv[ $k ] ) ) {
					$gv[ $k ] = array( 0.0, 0.0, 0.0 );
				}
				$gv[ $k ][0] += (float) $line['brutto'];
				$gv[ $k ][1] += (float) $line['netto'];
				$gv[ $k ][2] += (float) $line['ust'];
			}
			foreach ( (array) $r['payment_json'] as $p ) {
				$k         = $p['type'] . '|' . $p['name'];
				$pay[ $k ] = ( $pay[ $k ] ?? 0.0 ) + (float) $p['amount'];
				$all      += (float) $p['amount'];
				if ( 'Bar' === $p['type'] ) {
					$cash += (float) $p['amount'];
				}
			}
		}

		$created_date = substr( (string) $closing['created_local'], 0, 10 );
		$this->add(
			'cashpointclosing.csv',
			$base + array(
				'Z_BUCHUNGSTAG'     => $created_date !== $closing['business_date'] ? (string) $closing['business_date'] : '',
				'TAXONOMIE_VERSION' => self::TAXONOMY_VERSION,
				'Z_START_ID'        => $bons ? (string) $bons[0]['id'] : '',
				'Z_ENDE_ID'         => $bons ? (string) $bons[ count( $bons ) - 1 ]['id'] : '',
				'NAME'              => $m['company']['name'] ?? '',
				'STRASSE'           => $m['company']['street'] ?? '',
				'PLZ'               => $m['company']['zip'] ?? '',
				'ORT'               => $m['company']['city'] ?? '',
				'LAND'              => $m['company']['country'] ?? 'DEU',
				'STNR'              => $m['company']['stnr'] ?? '',
				'USTID'             => $m['company']['ustid'] ?? '',
				'Z_SE_ZAHLUNGEN'    => round( $all, 2 ),
				'Z_SE_BARZAHLUNGEN' => round( $cash, 2 ),
			)
		);
		$this->add(
			'location.csv',
			$base + array(
				'LOC_NAME'    => $m['location']['name'] ?? '',
				'LOC_STRASSE' => $m['location']['street'] ?? '',
				'LOC_PLZ'     => $m['location']['zip'] ?? '',
				'LOC_ORT'     => $m['location']['city'] ?? '',
				'LOC_LAND'    => $m['location']['country'] ?? 'DEU',
				'LOC_USTID'   => $m['location']['ustid'] ?? '',
			)
		);
		$this->add(
			'cashregister.csv',
			$base + array(
				'KASSE_BRAND'          => $m['register']['brand'] ?? '',
				'KASSE_MODELL'         => $m['register']['model'] ?? '',
				'KASSE_SERIENNR'       => $m['register']['serial'] ?? $closing['client_id'],
				'KASSE_SW_BRAND'       => $m['register']['sw_brand'] ?? '',
				'KASSE_SW_VERSION'     => $m['register']['sw_version'] ?? '',
				'KASSE_BASISWAEH_CODE' => $m['register']['currency'] ?? 'EUR',
				'KEINE_UST_ZUORDNUNG'  => '0',
			)
		);

		$tse  = (array) ( $m['tse'] ?? array() );
		$cert = preg_replace( '/-----[A-Z ]+-----|\s+/', '', (string) ( $tse['certificate'] ?? '' ) );
		$tse_row = $base + array(
			'TSE_ID'          => (string) self::TSE_ID,
			'TSE_SERIAL'      => (string) ( $tse['serial_hex'] ?? '' ),
			'TSE_SIG_ALGO'    => (string) ( $tse['signature_algorithm'] ?? '' ),
			'TSE_ZEITFORMAT'  => (string) ( $m['log_time_format'] ?? 'utcTime' ),
			'TSE_PD_ENCODING' => 'UTF-8',
			'TSE_PUBLIC_KEY'  => (string) ( $tse['public_key'] ?? '' ),
		);
		foreach ( str_split( (string) $cert, self::CERT_CHUNK ) as $i => $chunk ) {
			if ( '' === $chunk ) {
				continue;
			}
			$tse_row[ 'TSE_ZERTIFIKAT_' . self::roman( $i + 1 ) ] = $chunk;
			$this->extra_cert_columns = max( $this->extra_cert_columns, $i + 1 - 2 );
		}
		$this->add( 'tse.csv', $tse_row );

		foreach ( HWTSE_Process_Data::KEY_RATES as $key => $rate ) {
			$this->add(
				'vat.csv',
				$base + array(
					'UST_SCHLUESSEL' => (string) $key,
					'UST_SATZ'       => $rate,
					'UST_BESCHR'     => HWTSE_Process_Data::KEY_NAMES[ $key ] ?? '',
				)
			);
		}
		foreach ( $gv as $k => $v ) {
			list( $typ, $key ) = explode( '|', $k, 2 );
			$this->add(
				'businesscases.csv',
				$base + array(
					'GV_TYP'         => $typ,
					'GV_NAME'        => '',
					'AGENTUR_ID'     => '0',
					'UST_SCHLUESSEL' => $key,
					'Z_UMS_BRUTTO'   => round( $v[0], 2 ),
					'Z_UMS_NETTO'    => round( $v[1], 2 ),
					'Z_UST'          => round( $v[2], 2 ),
				)
			);
		}
		foreach ( $pay as $k => $amount ) {
			list( $typ, $name ) = explode( '|', $k, 2 );
			$this->add(
				'payment.csv',
				$base + array(
					'ZAHLART_TYP'      => $typ,
					'ZAHLART_NAME'     => $name,
					'Z_ZAHLART_BETRAG' => round( $amount, 2 ),
				)
			);
		}
		$this->add(
			'cash_per_currency.csv',
			$base + array(
				'ZAHLART_WAEH'        => $m['register']['currency'] ?? 'EUR',
				'ZAHLART_BETRAG_WAEH' => round( $cash, 2 ),
			)
		);

		foreach ( $bons as $r ) {
			$this->add_bon( $base, $r, $closing );
		}
	}

	private function add_bon( array $base, array $r, array $closing ): void {
		$bon_id = (string) $r['id'];
		$meta   = (array) $r['meta_json'];
		$tz     = wp_timezone();
		$start  = '' !== (string) $r['time_start'] ? (string) $r['time_start'] : (string) $r['created_gmt'] . ' UTC';
		$end    = '' !== (string) $r['time_end'] ? (string) $r['time_end'] : (string) $r['requested_gmt'] . ' UTC';
		$stamp  = static function ( string $t ) use ( $tz ): string {
			try {
				return ( new DateTimeImmutable( $t ) )->setTimezone( $tz )->format( DATE_RFC3339 );
			} catch ( Exception $e ) {
				return '';
			}
		};
		$brutto = 'AVBelegabbruch' === $r['bon_type'] ? array_sum( array_column( (array) $r['vat_json'], 'brutto' ) ) : (float) $r['total'];
		$note   = '';
		if ( ! empty( $meta['order_number'] ) ) {
			$note = 'Bestellung #' . $meta['order_number'] . ( 'refund' === $r['kind'] ? ', Erstattung #' . $r['refund_id'] : '' );
		}
		if ( ! empty( $meta['refund_reason'] ) ) {
			$note .= ': ' . $meta['refund_reason'];
		}
		if ( 'cash' === $r['kind'] ) {
			$note = (string) ( $meta['text'] ?? '' );
		}
		$this->add(
			'transactions.csv',
			$base + array(
				'BON_ID'        => $bon_id,
				'BON_NR'        => (string) $r['id'],
				'BON_TYP'       => (string) $r['bon_type'],
				'BON_NAME'      => '',
				'TERMINAL_ID'   => '',
				'BON_STORNO'    => '0',
				'BON_START'     => $stamp( $start ),
				'BON_ENDE'      => $stamp( $end ),
				'BEDIENER_ID'   => (string) ( $meta['user_id'] ?? '' ),
				'BEDIENER_NAME' => (string) ( $meta['user_name'] ?? '' ),
				'UMS_BRUTTO'    => round( $brutto, 2 ),
				'KUNDE_NAME'    => (string) ( $meta['company'] ?? '' ) ?: (string) ( $meta['customer_name'] ?? '' ),
				'KUNDE_ID'      => (string) ( $meta['customer_id'] ?? '' ),
				'KUNDE_TYP'     => '',
				'KUNDE_STRASSE' => '',
				'KUNDE_PLZ'     => '',
				'KUNDE_ORT'     => '',
				'KUNDE_LAND'    => '',
				'KUNDE_USTID'   => (string) ( $meta['vat_id'] ?? '' ),
				'BON_NOTIZ'     => mb_substr( $note, 0, 255 ),
			)
		);
		foreach ( (array) $r['payment_json'] as $p ) {
			$this->add(
				'datapayment.csv',
				$base + array(
					'BON_ID'           => $bon_id,
					'ZAHLART_TYP'      => (string) $p['type'],
					'ZAHLART_NAME'     => (string) $p['name'],
					'ZAHLWAEH_CODE'    => 'EUR',
					'ZAHLWAEH_BETRAG'  => '',
					'BASISWAEH_BETRAG' => round( (float) $p['amount'], 2 ),
				)
			);
		}
		foreach ( (array) $r['vat_json'] as $key => $v ) {
			$this->add(
				'transactions_vat.csv',
				$base + array(
					'BON_ID'         => $bon_id,
					'UST_SCHLUESSEL' => (string) $key,
					'BON_BRUTTO'     => (float) $v['brutto'],
					'BON_NETTO'      => (float) $v['netto'],
					'BON_UST'        => (float) $v['ust'],
				)
			);
		}
		if ( 'refund' === $r['kind'] && (int) $r['ref_journal_id'] ) {
			$ref     = HWTSE_Journal::get( (int) $r['ref_journal_id'] );
			$ref_z   = $ref && $ref['closing_id'] ? HWTSE_Journal::closing( (int) $ref['closing_id'] ) : null;
			$this->add(
				'references.csv',
				$base + array(
					'BON_ID'         => $bon_id,
					'POS_ZEILE'      => '',
					'REF_TYP'        => 'Transaktion',
					'REF_NAME'       => 'Ursprungsbeleg',
					'REF_DATUM'      => $ref ? $stamp( '' !== (string) $ref['time_end'] ? (string) $ref['time_end'] : (string) $ref['requested_gmt'] . ' UTC' ) : '',
					'REF_Z_KASSE_ID' => $ref ? (string) $ref['client_id'] : '',
					'REF_Z_NR'       => $ref_z ? (string) $ref_z['z_nr'] : '',
					'REF_BON_ID'     => (string) $r['ref_journal_id'],
				)
			);
		}
		$signed = 'FINISHED' === $r['state'];
		$this->add(
			'transactions_tse.csv',
			$base + array(
				'BON_ID'             => $bon_id,
				'TSE_ID'             => (string) self::TSE_ID,
				'TSE_TANR'           => $r['tx_number'] ? (string) $r['tx_number'] : '',
				'TSE_TA_START'       => HWTSE_Process_Data::tse_time( (string) $r['time_start'] ),
				'TSE_TA_ENDE'        => $signed ? HWTSE_Process_Data::tse_time( (string) $r['time_end'] ) : '',
				'TSE_TA_VORGANGSART' => (string) $r['process_type'],
				'TSE_TA_SIGZ'        => $signed ? (string) $r['signature_counter'] : '',
				'TSE_TA_SIG'         => $signed ? (string) $r['signature'] : '',
				'TSE_TA_FEHLER'      => $signed ? '' : mb_substr( '' !== (string) $r['error'] ? (string) $r['error'] : 'TSE ausgefallen', 0, 200 ),
				'TSE_VORGANGSDATEN'  => mb_substr( (string) $r['process_data'], 0, 1000 ),
			)
		);
		foreach ( (array) $r['lines_json'] as $line ) {
			$this->add(
				'lines.csv',
				$base + array(
					'BON_ID'          => $bon_id,
					'POS_ZEILE'       => (string) $line['pos'],
					'GUTSCHEIN_NR'    => '',
					'ARTIKELTEXT'     => (string) $line['text'],
					'POS_TERMINAL_ID' => '',
					'GV_TYP'          => (string) $line['gv_typ'],
					'GV_NAME'         => '',
					'INHAUS'          => '0',
					'P_STORNO'        => '0',
					'AGENTUR_ID'      => '0',
					'ART_NR'          => (string) $line['sku'],
					'GTIN'            => (string) $line['gtin'],
					'WARENGR_ID'      => (string) $line['group_id'],
					'WARENGR'         => (string) $line['group'],
					'MENGE'           => (float) $line['qty'],
					'FAKTOR'          => '',
					'EINHEIT'         => (string) $line['unit'],
					'STK_BR'          => (float) $line['unit_br'],
				)
			);
			$this->add(
				'lines_vat.csv',
				$base + array(
					'BON_ID'         => $bon_id,
					'POS_ZEILE'      => (string) $line['pos'],
					'UST_SCHLUESSEL' => (string) $line['key'],
					'POS_BRUTTO'     => (float) $line['brutto'],
					'POS_NETTO'      => (float) $line['netto'],
					'POS_UST'        => (float) $line['ust'],
				)
			);
			$discount = round( (float) $line['brutto'] - (float) $line['base_br'], 2 );
			if ( 0.0 !== $discount ) {
				$this->add(
					'itemamounts.csv',
					$base + array(
						'BON_ID'         => $bon_id,
						'POS_ZEILE'      => (string) $line['pos'],
						'TYP'            => 'base_amount',
						'UST_SCHLUESSEL' => (string) $line['key'],
						'PF_BRUTTO'      => (float) $line['base_br'],
						'PF_NETTO'       => (float) $line['base_net'],
						'PF_UST'         => (float) $line['base_ust'],
					)
				);
				$this->add(
					'itemamounts.csv',
					$base + array(
						'BON_ID'         => $bon_id,
						'POS_ZEILE'      => (string) $line['pos'],
						'TYP'            => 'discount',
						'UST_SCHLUESSEL' => (string) $line['key'],
						'PF_BRUTTO'      => $discount,
						'PF_NETTO'       => round( (float) $line['netto'] - (float) $line['base_net'], 2 ),
						'PF_UST'         => round( (float) $line['ust'] - (float) $line['base_ust'], 2 ),
					)
				);
			}
		}
	}

	private function add( string $file, array $values ): void {
		$this->rows[ $file ][] = $values;
	}

	/* ---------- writing ---------- */

	/** Columns of a table, including extra certificate columns for tse.csv. */
	private function columns( string $file ): array {
		$cols = $this->tables[ $file ]['columns'];
		if ( 'tse.csv' === $file ) {
			for ( $i = 0; $i < $this->extra_cert_columns; $i++ ) {
				$cols[] = array(
					'name'     => 'TSE_ZERTIFIKAT_' . self::roman( 3 + $i ),
					'numeric'  => false,
					'accuracy' => 0,
				);
			}
		}
		return $cols;
	}

	public function csv( string $file ): string {
		$cols  = $this->columns( $file );
		$lines = array( implode( ';', array_map( static fn( $c ) => self::field( $c['name'] ), $cols ) ) );
		foreach ( $this->rows[ $file ] as $values ) {
			$fields = array();
			foreach ( $cols as $c ) {
				$v = $values[ $c['name'] ] ?? '';
				if ( $c['numeric'] && '' !== $v && null !== $v ) {
					$v = self::number( $v, $c['accuracy'] );
				}
				$fields[] = self::field( (string) $v );
			}
			$lines[] = implode( ';', $fields );
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/** Numbers with comma, at least 2 (amounts) / 3 (quantities) decimals, up to the column accuracy. */
	public static function number( $v, int $accuracy ): string {
		if ( $accuracy <= 0 ) {
			return (string) (int) $v;
		}
		$min = 3 === $accuracy ? 3 : min( 2, $accuracy );
		$s   = number_format( (float) $v, $accuracy, '.', '' );
		if ( str_contains( $s, '.' ) ) {
			list( $int, $frac ) = explode( '.', $s );
			$frac               = rtrim( $frac, '0' );
			$frac               = str_pad( $frac, $min, '0' );
			$s                  = $int . ',' . $frac;
		}
		return '-0,00' === $s ? '0,00' : $s;
	}

	private static function field( string $s ): string {
		if ( '' === $s || ! preg_match( '/[;"\r\n]/', $s ) ) {
			return $s;
		}
		return '"' . str_replace( '"', '""', $s ) . '"';
	}

	private function index_xml(): string {
		$xml = (string) file_get_contents( HWTSE_DIR . 'schema/index.xml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $this->extra_cert_columns > 0 ) {
			$extra = '';
			for ( $i = 0; $i < $this->extra_cert_columns; $i++ ) {
				$extra .= "\n        <VariableColumn>\n          <Name>TSE_ZERTIFIKAT_" . self::roman( 3 + $i ) . "</Name>\n          <Description>Ggf. Rest des base64-codierten Zertifikats der TSE (in base64-Codierung)</Description>\n          <AlphaNumeric />\n          <MaxLength>1000</MaxLength>\n        </VariableColumn>";
			}
			$xml = preg_replace( '/(<Name>TSE_ZERTIFIKAT_II<\/Name>.*?<\/VariableColumn>)/s', '$1' . $extra, $xml, 1 );
		}
		return (string) $xml;
	}

	public function write_zip( string $path ): void {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new HWTSE_Exception( 'ZIP-Datei konnte nicht angelegt werden.', 'E_ZIP', 500 );
		}
		$zip->addFromString( 'index.xml', $this->index_xml() );
		$zip->addFromString( 'gdpdu-01-09-2004.dtd', (string) file_get_contents( HWTSE_DIR . 'schema/gdpdu-01-09-2004.dtd' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( array_keys( $this->tables ) as $file ) {
			$zip->addFromString( $file, $this->csv( $file ) );
		}
		$zip->close();
	}

	private static function roman( int $n ): string {
		$map = array(
			'M'  => 1000,
			'CM' => 900,
			'D'  => 500,
			'CD' => 400,
			'C'  => 100,
			'XC' => 90,
			'L'  => 50,
			'XL' => 40,
			'X'  => 10,
			'IX' => 9,
			'V'  => 5,
			'IV' => 4,
			'I'  => 1,
		);
		$out = '';
		foreach ( $map as $sym => $val ) {
			while ( $n >= $val ) {
				$out .= $sym;
				$n   -= $val;
			}
		}
		return $out;
	}
}
