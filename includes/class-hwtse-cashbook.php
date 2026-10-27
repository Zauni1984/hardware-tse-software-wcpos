<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kassenbuch: cash movements without a sale (Anfangsbestand, Einlage, Entnahme ...).
 *
 * Each movement is its own TSE transaction (Vorgangstyp "Beleg", amount not
 * taxable, paid "Bar") and appears in the DSFinV-K with its Geschäftsvorfall type.
 */
class HWTSE_Cashbook {

	/** DSFinV-K GV_TYP => label. Sign: +1 money into the drawer, -1 out, 0 either. */
	const REASONS = array(
		'Anfangsbestand'   => array( 'Anfangsbestand / Wechselgeld', 1 ),
		'Privateinlage'    => array( 'Privateinlage', 1 ),
		'Privatentnahme'   => array( 'Privatentnahme', -1 ),
		'Geldtransit'      => array( 'Geldtransit (Bank, Tresor)', 0 ),
		'Einzahlung'       => array( 'Einzahlung (sonstige)', 1 ),
		'Auszahlung'       => array( 'Auszahlung (z. B. Barauslage)', -1 ),
		'Lohnzahlung'      => array( 'Lohnzahlung aus der Kasse', -1 ),
		'DifferenzSollIst' => array( 'Kassendifferenz (Soll/Ist)', 0 ),
	);

	/**
	 * Book a cash movement. A positive amount puts money into the drawer, a negative one takes it out.
	 *
	 * @return array Journal row.
	 */
	public static function book( float $amount, string $reason, string $text, int $user_id ): array {
		if ( ! isset( self::REASONS[ $reason ] ) ) {
			throw new HWTSE_Exception( 'Unbekannter Buchungsgrund.', 'E_REASON' );
		}
		$amount = round( $amount, 2 );
		if ( 0.0 === $amount ) {
			throw new HWTSE_Exception( 'Der Betrag darf nicht 0 sein.', 'E_AMOUNT' );
		}
		$sign = self::REASONS[ $reason ][1];
		if ( 0 !== $sign ) {
			$amount = $sign * abs( $amount );
		}
		$user = $user_id ? get_userdata( $user_id ) : null;
		$text = '' !== trim( $text ) ? trim( $text ) : self::REASONS[ $reason ][0];
		$snap = HWTSE_Process_Data::cash_snapshot( $amount, $reason, $text );
		$id   = HWTSE_Journal::insert(
			array(
				'kind'             => 'cash',
				'state'            => 'PENDING',
				'finish_requested' => 1,
				'requested_gmt'    => gmdate( 'Y-m-d H:i:s' ),
				'bon_type'         => 'Beleg',
				'process_data'     => HWTSE_Process_Data::kassenbeleg( 'Beleg', $snap['vat'], $snap['payments'] ),
				'total'            => $amount,
				'vat_json'         => $snap['vat'],
				'payment_json'     => $snap['payments'],
				'lines_json'       => $snap['lines'],
				'meta_json'        => array(
					'reason'    => $reason,
					'text'      => mb_substr( $text, 0, 200 ),
					'user_id'   => (string) $user_id,
					'user_name' => $user ? mb_substr( $user->display_name, 0, 50 ) : 'Kasse',
				),
			)
		);
		HWTSE_Sources::schedule_deadline( $id );
		return HWTSE_Journal::get( $id );
	}
}
