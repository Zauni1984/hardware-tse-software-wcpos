<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API (namespace hwtse/v1) for the TSE agent and the admin pages.
 *
 * Every route requires manage_woocommerce (filter hwtse_capability); the agent
 * runs in the browser of a logged-in shop manager and sends the wp_rest nonce.
 */
class HWTSE_Rest {

	const NS = 'hwtse/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function capability(): string {
		return (string) apply_filters( 'hwtse_capability', 'manage_woocommerce' );
	}

	public static function can(): bool {
		return current_user_can( self::capability() );
	}

	public static function routes(): void {
		$r = static function ( string $path, string $methods, callable $cb ) {
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $methods,
					'callback'            => static function ( WP_REST_Request $req ) use ( $cb ) {
						try {
							return rest_ensure_response( $cb( $req ) );
						} catch ( HWTSE_Exception $e ) {
							return new WP_Error( $e->error_code, $e->getMessage(), array( 'status' => $e->getCode() ?: 400 ) );
						}
					},
					'permission_callback' => array( __CLASS__, 'can' ),
				)
			);
		};

		$r( '/status', 'GET', array( __CLASS__, 'status' ) );
		$r( '/agent/config', 'GET', array( __CLASS__, 'agent_config' ) );
		$r( '/agent/heartbeat', 'POST', array( __CLASS__, 'heartbeat' ) );
		$r( '/agent/jobs', 'GET', array( __CLASS__, 'jobs' ) );
		$r( '/agent/jobs/(?P<id>\d+)/started', 'POST', array( __CLASS__, 'job_started' ) );
		$r( '/agent/jobs/(?P<id>\d+)/finished', 'POST', array( __CLASS__, 'job_finished' ) );
		$r( '/agent/jobs/(?P<id>\d+)/failed', 'POST', array( __CLASS__, 'job_failed' ) );
		$r( '/agent/tse-info', 'POST', array( __CLASS__, 'tse_info' ) );
		$r( '/agent/credentials', 'POST', array( __CLASS__, 'save_credentials' ) );
		$r( '/agent/generate-credentials', 'POST', array( __CLASS__, 'generate_credentials' ) );
		$r( '/agent/open-transactions', 'POST', array( __CLASS__, 'open_transactions' ) );
		$r( '/agent/export/start', 'POST', array( __CLASS__, 'export_start' ) );
		$r( '/agent/export/(?P<id>[a-f0-9]{12})/chunk', 'POST', array( __CLASS__, 'export_chunk' ) );
		$r( '/agent/export/(?P<id>[a-f0-9]{12})/finish', 'POST', array( __CLASS__, 'export_finish' ) );
		$r( '/closing', 'POST', array( __CLASS__, 'closing' ) );
		$r( '/dsfinvk', 'POST', array( __CLASS__, 'dsfinvk' ) );
		$r( '/cashbook', 'POST', array( __CLASS__, 'cashbook' ) );
		$r( '/journal', 'GET', array( __CLASS__, 'journal' ) );
		$r( '/journal/(?P<id>\d+)/give-up', 'POST', array( __CLASS__, 'give_up' ) );
	}

	/* ---------- status ---------- */

	public static function status(): array {
		return array(
			'version'     => HWTSE_VERSION,
			'settings'    => HWTSE_Settings::masked(),
			'tse'         => HWTSE_Settings::tse_info(),
			'agent'       => HWTSE_Settings::agent_status(),
			'agent_online' => HWTSE_Settings::agent_online(),
			'journal'     => HWTSE_Journal::counts(),
			'open_work'   => HWTSE_Journal::count_open_work(),
			'wcpos'       => defined( 'WCPOS\\WooCommercePOS\\VERSION' ) ? (string) constant( 'WCPOS\\WooCommercePOS\\VERSION' ) : '',
			'sdk'         => self::sdk_url(),
			'closings'    => array_slice( HWTSE_Journal::closings( 1 ), 0, 1 ),
		);
	}

	public static function sdk_url(): string {
		$url = (string) HWTSE_Settings::get( 'sdk_url' );
		if ( '' !== $url ) {
			return $url;
		}
		$file = HWTSE_Storage::path( 'sdk', 'epos-device.js' );
		return $file ? HWTSE_Storage::url( 'sdk', 'epos-device.js' ) . '?v=' . filemtime( $file ) : '';
	}

	/* ---------- agent ---------- */

	public static function agent_config(): array {
		$s = HWTSE_Settings::all();
		return array(
			'printer_host'    => $s['printer_host'],
			'printer_port'    => (int) $s['printer_port'],
			'device_id'       => $s['device_id'],
			'client_id'       => $s['client_id'],
			'puk'             => $s['puk'],
			'admin_pin'       => $s['admin_pin'],
			'time_admin_pin'  => $s['time_admin_pin'],
			'secret_key'      => $s['secret_key'],
			'log_time_format' => $s['log_time_format'],
			'tse'             => HWTSE_Settings::tse_info(),
			'enabled'         => HWTSE_Settings::is_enabled(),
		);
	}

	private static function agent_id( WP_REST_Request $req ): string {
		$id = preg_replace( '/[^a-zA-Z0-9-]/', '', (string) $req->get_param( 'agent_id' ) );
		if ( strlen( (string) $id ) < 8 ) {
			throw new HWTSE_Exception( 'agent_id fehlt.', 'E_AGENT' );
		}
		return substr( (string) $id, 0, 40 );
	}

	public static function heartbeat( WP_REST_Request $req ): array {
		$agent = self::agent_id( $req );
		$prev  = HWTSE_Settings::agent_status();
		$other = ! empty( $prev['agent_id'] ) && $prev['agent_id'] !== $agent && ( time() - (int) ( $prev['seen'] ?? 0 ) ) < 60;
		if ( $other && ! $req->get_param( 'force' ) ) {
			return array(
				'conflict'  => true,
				'other'     => $prev,
				'open_work' => HWTSE_Journal::count_open_work(),
			);
		}
		$tse = (array) $req->get_param( 'tse' );
		update_option(
			'hwtse_agent_status',
			array(
				'agent_id'  => $agent,
				'seen'      => time(),
				'connected' => (bool) $req->get_param( 'connected' ),
				'error'     => sanitize_text_field( (string) $req->get_param( 'error' ) ),
				'user'      => wp_get_current_user()->display_name,
				'browser'   => sanitize_text_field( substr( (string) $req->get_header( 'user_agent' ), 0, 160 ) ),
				'tse'       => array_map( static fn( $v ) => is_scalar( $v ) ? $v : '', array_slice( $tse, 0, 30 ) ),
			),
			false
		);
		return array(
			'conflict'  => false,
			'open_work' => HWTSE_Journal::count_open_work(),
			'enabled'   => HWTSE_Settings::is_enabled(),
		);
	}

	public static function jobs( WP_REST_Request $req ): array {
		$agent = self::agent_id( $req );
		if ( ! HWTSE_Settings::is_enabled() ) {
			return array( 'jobs' => array() );
		}
		$status = HWTSE_Settings::agent_status();
		if ( ! empty( $status['agent_id'] ) && $status['agent_id'] !== $agent && ( time() - (int) ( $status['seen'] ?? 0 ) ) < 60 ) {
			return array(
				'jobs'     => array(),
				'conflict' => true,
			);
		}
		$jobs = array();
		foreach ( HWTSE_Journal::lease_jobs( $agent, 10 ) as $row ) {
			if ( 'PENDING' === $row['state'] ) {
				$action = (int) $row['finish_requested'] ? 'start_finish' : 'start';
			} else {
				$action = 'finish';
			}
			$jobs[] = array(
				'id'           => (int) $row['id'],
				'action'       => $action,
				'kind'         => $row['kind'],
				'order_id'     => (int) $row['order_id'],
				'tx_number'    => (int) $row['tx_number'],
				'process_type' => $row['process_type'],
				'process_data' => (string) $row['process_data'],
				'total'        => (float) $row['total'],
				'attempts'     => (int) $row['attempts'],
			);
		}
		return array( 'jobs' => $jobs );
	}

	private static function row_or_fail( WP_REST_Request $req ): array {
		$row = HWTSE_Journal::get( (int) $req['id'] );
		if ( ! $row ) {
			throw new HWTSE_Exception( 'Journal-Eintrag nicht gefunden.', 'E_NOT_FOUND', 404 );
		}
		return $row;
	}

	private static function serial_hex( string $b64 ): string {
		$bin = base64_decode( $b64, true );
		return false === $bin ? '' : bin2hex( $bin );
	}

	public static function job_started( WP_REST_Request $req ): array {
		self::agent_id( $req );
		$row = self::row_or_fail( $req );
		$tx  = (int) $req->get_param( 'tx_number' );
		if ( $tx <= 0 ) {
			throw new HWTSE_Exception( 'Transaktionsnummer fehlt.', 'E_TX' );
		}
		if ( (int) $row['tx_number'] === $tx ) {
			return array( 'ok' => true, 'duplicate' => true ); // Re-sent report.
		}
		if ( (int) $row['tx_number'] > 0 ) {
			throw new HWTSE_Exception( 'Für diesen Vorgang wurde bereits eine andere TSE-Transaktion gestartet.', 'E_TX_MISMATCH', 409 );
		}
		$data = array(
			'state'             => 'ACTIVE',
			'tx_number'         => $tx,
			'time_start'        => sanitize_text_field( (string) $req->get_param( 'log_time' ) ),
			'sig_counter_start' => (int) $req->get_param( 'signature_counter' ),
			'signature_start'   => sanitize_text_field( (string) $req->get_param( 'signature' ) ),
			'tse_serial'        => self::serial_hex( (string) $req->get_param( 'serial_b64' ) ),
		);
		if ( 'VOID' === $row['state'] ) {
			// The order was abandoned while the agent was starting: close it as abort.
			HWTSE_Journal::transition( (int) $row['id'], array( 'VOID' ), $data );
			$order = $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : null;
			HWTSE_Sources::abort_row( HWTSE_Journal::get( (int) $row['id'] ), $order instanceof WC_Order ? $order : null );
			return array( 'ok' => true, 'aborted' => true );
		}
		if ( ! HWTSE_Journal::transition( (int) $row['id'], array( 'PENDING' ), $data ) ) {
			throw new HWTSE_Exception( 'Vorgang ist nicht mehr offen (Status ' . $row['state'] . ').', 'E_STATE', 409 );
		}
		$fresh = HWTSE_Journal::get( (int) $row['id'] );
		return array(
			'ok'               => true,
			'finish_requested' => (bool) $fresh['finish_requested'],
			'process_data'     => (string) $fresh['process_data'],
			'process_type'     => (string) $fresh['process_type'],
		);
	}

	public static function job_finished( WP_REST_Request $req ): array {
		self::agent_id( $req );
		$row = self::row_or_fail( $req );
		$tx  = (int) $req->get_param( 'tx_number' );
		if ( 'FINISHED' === $row['state'] && (int) $row['tx_number'] === $tx ) {
			return array( 'ok' => true, 'duplicate' => true );
		}
		if ( (int) $row['tx_number'] !== $tx ) {
			throw new HWTSE_Exception( 'Transaktionsnummer passt nicht zum Vorgang.', 'E_TX_MISMATCH', 409 );
		}
		$ok = HWTSE_Journal::transition(
			(int) $row['id'],
			array( 'ACTIVE', 'FAILED' ),
			array(
				'state'             => 'FINISHED',
				'time_end'          => sanitize_text_field( (string) $req->get_param( 'log_time' ) ),
				'signature_counter' => (int) $req->get_param( 'signature_counter' ),
				'signature'         => sanitize_text_field( (string) $req->get_param( 'signature' ) ),
				// What the TSE signed is authoritative; it equals the queued data unless the job changed meanwhile.
				'process_data'      => self::signed_process_data( $req, $row ),
				'lease_agent'       => '',
				'lease_until'       => 0,
			)
		);
		if ( ! $ok ) {
			throw new HWTSE_Exception( 'Vorgang konnte nicht abgeschlossen werden (Status ' . $row['state'] . ').', 'E_STATE', 409 );
		}
		$fresh = HWTSE_Journal::get( (int) $row['id'] );
		$order = $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : null;
		if ( $order instanceof WC_Order && 'cash' !== $row['kind'] ) {
			$labels = array(
				'sale'   => 'Verkauf',
				'refund' => 'Erstattung',
				'abort'  => 'Vorgangsabbruch',
			);
			$order->add_order_note(
				sprintf(
					'TSE signiert (%s): Bon-Nr. %d, TSE-Transaktion %d, Signaturzähler %d.',
					$labels[ $row['kind'] ] ?? $row['kind'],
					(int) $row['id'],
					(int) $fresh['tx_number'],
					(int) $fresh['signature_counter']
				)
			);
		}
		if ( 'sale' === $row['kind'] && $row['order_id'] ) {
			HWTSE_Print_Gate::release( (int) $row['order_id'] );
		}
		return array( 'ok' => true );
	}

	private static function signed_process_data( WP_REST_Request $req, array $row ): string {
		$signed = (string) $req->get_param( 'process_data' );
		if ( '' === $signed || strlen( $signed ) > 2000 || ! preg_match( '/^[A-Za-z]+\^[0-9._\-]*\^[0-9.:_\-A-Za-z]*$/', $signed ) ) {
			return (string) $row['process_data'];
		}
		return $signed;
	}

	public static function job_failed( WP_REST_Request $req ): array {
		self::agent_id( $req );
		$row      = self::row_or_fail( $req );
		$code     = sanitize_text_field( (string) $req->get_param( 'code' ) );
		$message  = sanitize_text_field( (string) $req->get_param( 'error' ) );
		$phase    = sanitize_key( (string) $req->get_param( 'phase' ) );
		$attempts = (int) $row['attempts'] + 1;
		$data     = array(
			'attempts'    => $attempts,
			'error'       => mb_substr( sprintf( '[%s] %s %s', $phase, $code, $message ), 0, 1000 ),
			'lease_agent' => '',
			'lease_until' => time() + min( 300, 5 * $attempts ),
		);
		// The TSE does not know the transaction (e.g. TSE replaced): start a new one.
		if ( 'finish' === $phase && in_array( $code, array( 'TSE1_ERROR_TRANSACTION_NOT_STARTED', 'TSE1_ERROR_NO_TRANSACTION_IN_PROGRESS' ), true ) && 'ACTIVE' === $row['state'] ) {
			$data += array(
				'state'     => 'PENDING',
				'tx_number' => 0,
			);
		}
		HWTSE_Journal::update( (int) $row['id'], $data );
		if ( $attempts >= 50 ) {
			HWTSE_Journal::update(
				(int) $row['id'],
				array(
					'state'             => 'FAILED',
					'fallback_released' => 1,
				)
			);
		}
		// A receipt is waiting for this signature and the TSE failed: do not keep the customer waiting.
		$fresh = HWTSE_Journal::get( (int) $row['id'] );
		if ( (int) $fresh['finish_requested'] && ! (int) $fresh['fallback_released'] && $attempts >= 2 ) {
			HWTSE_Sources::release_fallback( $fresh, 'TSE-Fehler ' . $code );
		}
		return array(
			'ok'       => true,
			'attempts' => $attempts,
		);
	}

	public static function tse_info( WP_REST_Request $req ): array {
		$info = (array) $req->get_json_params();
		return array( 'tse' => HWTSE_Settings::update_tse_info( $info ) );
	}

	public static function save_credentials( WP_REST_Request $req ): array {
		$values = array();
		foreach ( HWTSE_Settings::SECRET_KEYS as $k ) {
			$v = $req->get_param( $k );
			if ( null !== $v && '' !== (string) $v ) {
				$values[ $k ] = (string) $v;
			}
		}
		self::validate_credentials( $values );
		HWTSE_Settings::update( $values );
		return array( 'ok' => true );
	}

	private static function validate_credentials( array $v ): void {
		if ( isset( $v['puk'] ) && ! preg_match( '/^\d{6}$/', $v['puk'] ) ) {
			throw new HWTSE_Exception( 'Die PUK muss 6 Ziffern haben.', 'E_PUK' );
		}
		foreach ( array( 'admin_pin', 'time_admin_pin' ) as $k ) {
			if ( isset( $v[ $k ] ) && ! preg_match( '/^\d{5}$/', $v[ $k ] ) ) {
				throw new HWTSE_Exception( 'PINs müssen 5 Ziffern haben.', 'E_PIN' );
			}
		}
		if ( isset( $v['secret_key'] ) && ! preg_match( '/^[\x21-\x7E]{8}$/', $v['secret_key'] ) ) {
			throw new HWTSE_Exception( 'Der Secret Key muss genau 8 ASCII-Zeichen haben.', 'E_SECRET' );
		}
	}

	/** Fresh random credentials for the initial TSE set-up (not stored until the set-up succeeded). */
	public static function generate_credentials(): array {
		$digits = static function ( int $n ): string {
			$s = '';
			for ( $i = 0; $i < $n; $i++ ) {
				$s .= (string) random_int( 0, 9 );
			}
			return $s;
		};
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
		$secret   = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$secret .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return array(
			'puk'            => $digits( 6 ),
			'admin_pin'      => $digits( 5 ),
			'time_admin_pin' => $digits( 5 ),
			'secret_key'     => $secret,
		);
	}

	/**
	 * The agent sends the TSE's list of started transactions; the answer tells
	 * which of them the journal does not know or no longer needs open.
	 */
	public static function open_transactions( WP_REST_Request $req ): array {
		global $wpdb;
		$numbers = array_values( array_filter( array_map( 'intval', (array) $req->get_param( 'numbers' ) ) ) );
		$out     = array();
		$table   = HWTSE_Journal::table();
		foreach ( $numbers as $n ) {
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, state, finish_requested, kind, order_id FROM {$table} WHERE tx_number = %d ORDER BY id DESC LIMIT 1", $n ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[] = array(
				'tx_number' => $n,
				'journal'   => $row ? (int) $row['id'] : 0,
				'state'     => $row ? $row['state'] : '',
				'kind'      => $row ? $row['kind'] : '',
				'order_id'  => $row ? (int) $row['order_id'] : 0,
				'orphan'    => ! $row || ! in_array( $row['state'], array( 'ACTIVE' ), true ),
			);
		}
		return array( 'transactions' => $out );
	}

	/* ---------- TSE export (TAR) ---------- */

	public static function export_start( WP_REST_Request $req ): array {
		$kind = sanitize_key( (string) $req->get_param( 'kind' ) );
		$id   = substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
		$name = sprintf( 'tse-export-%s-%s-%s.tar', $kind ?: 'archive', wp_date( 'Ymd-His' ), $id );
		$path = HWTSE_Storage::dir( 'tse-export' ) . '/' . $name . '.part';
		file_put_contents( $path, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		set_transient(
			'hwtse_export_' . $id,
			array(
				'name' => $name,
				'seq'  => 0,
			),
			DAY_IN_SECONDS
		);
		return array(
			'id'   => $id,
			'name' => $name,
		);
	}

	private static function export_state( string $id ): array {
		$state = get_transient( 'hwtse_export_' . $id );
		if ( ! is_array( $state ) ) {
			throw new HWTSE_Exception( 'Export unbekannt oder abgelaufen.', 'E_EXPORT', 404 );
		}
		return $state;
	}

	public static function export_chunk( WP_REST_Request $req ): array {
		$id    = (string) $req['id'];
		$state = self::export_state( $id );
		$seq   = (int) $req->get_param( 'seq' );
		if ( $seq !== (int) $state['seq'] ) {
			if ( $seq < (int) $state['seq'] ) {
				return array( 'ok' => true, 'duplicate' => true );
			}
			throw new HWTSE_Exception( 'Exportteil fehlt (erwartet ' . $state['seq'] . ').', 'E_EXPORT_SEQ', 409 );
		}
		$bin = base64_decode( (string) $req->get_param( 'data' ), true );
		if ( false === $bin ) {
			throw new HWTSE_Exception( 'Exportteil ist kein Base64.', 'E_EXPORT_DATA' );
		}
		$path = HWTSE_Storage::dir( 'tse-export' ) . '/' . $state['name'] . '.part';
		file_put_contents( $path, $bin, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$state['seq'] = $seq + 1;
		set_transient( 'hwtse_export_' . $id, $state, DAY_IN_SECONDS );
		return array(
			'ok'    => true,
			'bytes' => (int) filesize( $path ),
		);
	}

	public static function export_finish( WP_REST_Request $req ): array {
		$id    = (string) $req['id'];
		$state = self::export_state( $id );
		$dir   = HWTSE_Storage::dir( 'tse-export' );
		$part  = $dir . '/' . $state['name'] . '.part';
		if ( ! $req->get_param( 'complete' ) ) {
			if ( is_file( $part ) ) {
				wp_delete_file( $part );
			}
			delete_transient( 'hwtse_export_' . $id );
			return array( 'ok' => true, 'cancelled' => true );
		}
		$final = $dir . '/' . $state['name'];
		rename( $part, $final ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		delete_transient( 'hwtse_export_' . $id );
		return array(
			'ok'    => true,
			'file'  => $state['name'],
			'bytes' => (int) filesize( $final ),
		);
	}

	/* ---------- admin actions ---------- */

	public static function closing(): array {
		$closing = HWTSE_Closing::create( get_current_user_id() );
		if ( ! $closing ) {
			return array(
				'ok'      => true,
				'closing' => null,
				'message' => 'Keine abgeschlossenen Vorgänge seit dem letzten Kassenabschluss.',
			);
		}
		return array(
			'ok'      => true,
			'closing' => $closing,
		);
	}

	public static function dsfinvk( WP_REST_Request $req ): array {
		return HWTSE_Dsfinvk::export( (string) $req->get_param( 'from' ), (string) $req->get_param( 'to' ) );
	}

	public static function cashbook( WP_REST_Request $req ): array {
		$row = HWTSE_Cashbook::book(
			(float) str_replace( ',', '.', (string) $req->get_param( 'amount' ) ),
			(string) $req->get_param( 'reason' ),
			sanitize_text_field( (string) $req->get_param( 'text' ) ),
			get_current_user_id()
		);
		return array(
			'ok'  => true,
			'row' => $row,
		);
	}

	public static function journal( WP_REST_Request $req ): array {
		$limit = min( 500, max( 1, (int) ( $req->get_param( 'limit' ) ?: 100 ) ) );
		return array( 'rows' => HWTSE_Journal::recent( $limit, max( 0, (int) $req->get_param( 'offset' ) ) ) );
	}

	/** Give up on a row (TSE-Ausfall): it is closed unsigned and no longer retried. */
	public static function give_up( WP_REST_Request $req ): array {
		$row = self::row_or_fail( $req );
		if ( ! (int) $row['finish_requested'] ) {
			throw new HWTSE_Exception( 'Der Vorgang ist noch offen (nicht bezahlt).', 'E_STATE', 409 );
		}
		if ( ! HWTSE_Journal::transition( (int) $row['id'], array( 'PENDING', 'ACTIVE' ), array( 'state' => 'FAILED', 'fallback_released' => 1 ) ) ) {
			throw new HWTSE_Exception( 'Vorgang ist nicht offen.', 'E_STATE', 409 );
		}
		if ( 'sale' === $row['kind'] && $row['order_id'] ) {
			HWTSE_Print_Gate::release( (int) $row['order_id'] );
		}
		return array( 'ok' => true );
	}
}
