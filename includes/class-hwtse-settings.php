<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings storage (wp_options) with defaults.
 *
 * Secrets (PUK, PINs, secret key) are stored here because the TSE agent in the
 * browser needs them to log in to the TSE. They are only handed out to users
 * with manage_woocommerce and are masked everywhere else.
 */
class HWTSE_Settings {

	const SECRET_KEYS = array( 'puk', 'admin_pin', 'time_admin_pin', 'secret_key' );

	public static function defaults(): array {
		return array(
			'enabled'          => 'yes',
			// TSE / printer.
			'printer_host'     => '',
			'printer_port'     => 8043,
			'device_id'        => 'local_TSE',
			'client_id'        => 'HANFJACK-INGOLSTADT-KASSE-1',
			'puk'              => '',
			'admin_pin'        => '',
			'time_admin_pin'   => '',
			'secret_key'       => 'EPSONKEY',
			'log_time_format'  => 'utcTime',
			'sdk_url'          => '',
			// Process.
			'start_mode'       => 'create',
			'hold_print'       => 'yes',
			'agent_timeout'    => 25,
			'pos_gateways'     => 'pos_cash,pos_card,sumup_terminal_for_woocommerce,invoice',
			'cash_gateways'    => 'pos_cash,cod,invoice',
			'receipt_label'    => 'Kassenbeleg',
			'print_signature'  => 'yes',
			// Kassenabschluss.
			'closing_auto'     => 'yes',
			'closing_time'     => '23:58',
			// Stammdaten DSFinV-K.
			'company_name'     => 'SZM Trade & IT Solutions e.K.',
			'company_street'   => 'Kratzmühlstr. 14',
			'company_zip'      => '92339',
			'company_city'     => 'Beilngries',
			'company_country'  => 'DEU',
			'company_stnr'     => '',
			'company_ustid'    => '',
			'location_name'    => 'Hanfjack Ingolstadt',
			'location_street'  => 'Harderstraße 1',
			'location_zip'     => '85049',
			'location_city'    => 'Ingolstadt',
			'location_country' => 'DEU',
			'register_brand'   => 'WooCommerce POS (WCPOS)',
			'register_model'   => 'Web-Kasse',
		);
	}

	public static function all(): array {
		$saved = get_option( HWTSE_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function update( array $values ): array {
		$defaults = self::defaults();
		$current  = self::all();
		foreach ( $values as $k => $v ) {
			if ( ! array_key_exists( $k, $defaults ) ) {
				continue;
			}
			if ( is_int( $defaults[ $k ] ) ) {
				$v = max( 0, (int) $v );
			} else {
				$v = is_string( $v ) ? trim( wp_unslash( $v ) ) : $v;
			}
			$current[ $k ] = $v;
		}
		$current['client_id'] = self::sanitize_printable( (string) $current['client_id'], 30 );
		update_option( HWTSE_OPTION, $current, false );
		return $current;
	}

	/**
	 * Reduce a string to the ASN.1 PrintableString set the TSE accepts for client IDs.
	 */
	public static function sanitize_printable( string $value, int $max ): string {
		$value = preg_replace( "/[^A-Za-z0-9'()+,\\-.\\/:=? ]/", '-', $value );
		return substr( (string) $value, 0, $max );
	}

	public static function is_enabled(): bool {
		return 'yes' === self::get( 'enabled' );
	}

	public static function list_setting( string $key ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) self::get( $key, '' ) ) ) ) );
	}

	/** Settings with secrets masked, for status output and the admin form. */
	public static function masked(): array {
		$s = self::all();
		foreach ( self::SECRET_KEYS as $k ) {
			$s[ $k ] = '' === (string) $s[ $k ] ? '' : '••••' . substr( (string) $s[ $k ], -1 );
		}
		return $s;
	}

	/* ---------- cached TSE information (written by the agent) ---------- */

	public static function tse_info(): array {
		$info = get_option( 'hwtse_tse_info', array() );
		return is_array( $info ) ? $info : array();
	}

	public static function update_tse_info( array $info ): array {
		$current = self::tse_info();
		$allowed = array( 'vendor_type', 'serial_b64', 'serial_hex', 'public_key', 'signature_algorithm', 'certificate', 'certificate_expiration', 'tse_description', 'initialization_state', 'created_signatures', 'remaining_signatures', 'max_signatures', 'capacity_blocks', 'used_blocks', 'started_transactions', 'max_started_transactions', 'has_valid_time', 'has_passed_self_test', 'time_until_next_self_test', 'is_unlocked', 'tse_health', 'last_export', 'software_version', 'hardware_version', 'cdc_id', 'updated' );
		foreach ( $info as $k => $v ) {
			if ( in_array( $k, $allowed, true ) && ( is_scalar( $v ) || null === $v ) ) {
				$current[ $k ] = is_string( $v ) ? sanitize_text_field( $v ) : $v;
			}
		}
		if ( ! empty( $current['serial_b64'] ) && empty( $current['serial_hex'] ) ) {
			$bin = base64_decode( (string) $current['serial_b64'], true );
			if ( false !== $bin ) {
				$current['serial_hex'] = bin2hex( $bin );
			}
		}
		$current['updated'] = time();
		update_option( 'hwtse_tse_info', $current, false );
		return $current;
	}

	/* ---------- agent status ---------- */

	public static function agent_status(): array {
		$s = get_option( 'hwtse_agent_status', array() );
		return is_array( $s ) ? $s : array();
	}

	public static function agent_online(): bool {
		$s = self::agent_status();
		return ! empty( $s['seen'] ) && ( time() - (int) $s['seen'] ) < 90 && ! empty( $s['connected'] );
	}
}
