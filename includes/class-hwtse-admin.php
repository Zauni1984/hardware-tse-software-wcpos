<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin UI: WooCommerce → Hardware-TSE (tabs: Übersicht, TSE-Agent, Kassenbuch,
 * Abschlüsse & Export, Journal, Einstellungen).
 */
class HWTSE_Admin {

	const SLUG = 'hwtse';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_hwtse_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_hwtse_download', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_hwtse_zbon', array( __CLASS__, 'zbon' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', 'Hardware-TSE', 'Hardware-TSE', HWTSE_Rest::capability(), self::SLUG, array( __CLASS__, 'page' ) );
	}

	private static function tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, array( 'overview', 'agent', 'cashbook', 'closings', 'journal', 'settings' ), true ) ? $tab : 'overview';
	}

	public static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'hwtse-admin', HWTSE_URL . 'assets/admin.css', array(), HWTSE_VERSION );
		wp_enqueue_script( 'hwtse-admin', HWTSE_URL . 'assets/admin.js', array(), HWTSE_VERSION, true );
		$data = array(
			'rest'  => esc_url_raw( rest_url( HWTSE_Rest::NS ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		);
		if ( 'agent' === self::tab() ) {
			$sdk = HWTSE_Rest::sdk_url();
			if ( '' !== $sdk ) {
				wp_enqueue_script( 'hwtse-epos-sdk', $sdk, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			}
			wp_enqueue_script( 'hwtse-agent', HWTSE_URL . 'assets/agent.js', array( 'hwtse-admin' ), HWTSE_VERSION, true );
			$data['sdk']     = $sdk;
			$data['version'] = HWTSE_VERSION;
		}
		wp_add_inline_script( 'hwtse-admin', 'window.HWTSE_DATA = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	public static function page(): void {
		if ( ! HWTSE_Rest::can() ) {
			wp_die( 'Keine Berechtigung.' );
		}
		$tab  = self::tab();
		$tabs = array(
			'overview' => 'Übersicht',
			'agent'    => 'TSE-Agent',
			'cashbook' => 'Kassenbuch',
			'closings' => 'Abschlüsse & Export',
			'journal'  => 'Journal',
			'settings' => 'Einstellungen',
		);
		echo '<div class="wrap hwtse"><h1>Hardware-TSE (Epson) für WCPOS</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $k === $tab ? 'nav-tab-active' : '', esc_url( self::url( $k ) ), esc_html( $label ) );
		}
		echo '</nav>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>Gespeichert.</p></div>';
		}
		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div>';
	}

	/* ---------- Übersicht ---------- */

	private static function warnings(): array {
		$s    = HWTSE_Settings::all();
		$info = HWTSE_Settings::tse_info();
		$w    = array();
		if ( '' === $s['printer_host'] ) {
			$w[] = 'Drucker-IP fehlt (Einstellungen).';
		}
		if ( '' === HWTSE_Rest::sdk_url() ) {
			$w[] = 'Epson ePOS SDK für JavaScript fehlt (Einstellungen → SDK-Datei hochladen).';
		}
		if ( 'EPSONKEY' === $s['secret_key'] && 'INITIALIZED' === ( $info['initialization_state'] ?? '' ) ) {
			$w[] = 'Der Secret Key ist noch der Werkswert „EPSONKEY“. Laut Epson muss er nach der Einrichtung geändert werden (TSE-Agent → Einrichtung).';
		}
		if ( '' === $s['time_admin_pin'] ) {
			$w[] = 'TimeAdmin-PIN fehlt: TSE im TSE-Agenten einrichten oder vorhandene Zugangsdaten eintragen.';
		}
		if ( class_exists( 'HJ_TSE_Orders' ) ) {
			$w[] = 'Das alte Plugin „Hanfjack TSE (fiskaly)“ ist aktiv. Beide Plugins würden jeden Verkauf signieren und den Bon befüllen; für den Echtbetrieb das fiskaly-Plugin deaktivieren.';
		}
		if ( ! HWTSE_Settings::agent_online() ) {
			$w[] = 'Kein TSE-Agent verbunden. Am Kassen-PC die Seite „TSE-Agent“ geöffnet lassen.';
		}
		if ( '' === $s['company_stnr'] && '' === $s['company_ustid'] ) {
			$w[] = 'Steuernummer oder USt-IdNr. fehlt (Einstellungen → Stammdaten), wird für den DSFinV-K-Export gebraucht.';
		}
		if ( ! empty( $info['certificate_expiration'] ) && strtotime( (string) $info['certificate_expiration'] ) < time() + 180 * DAY_IN_SECONDS ) {
			$w[] = 'Das TSE-Zertifikat läuft am ' . esc_html( HWTSE_Receipt::local_time( (string) $info['certificate_expiration'] ) ) . ' ab. Vorher exportieren und TSE tauschen.';
		}
		return $w;
	}

	private static function tab_overview(): void {
		$info   = HWTSE_Settings::tse_info();
		$agent  = HWTSE_Settings::agent_status();
		$counts = HWTSE_Journal::counts();
		foreach ( self::warnings() as $w ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $w ) . '</p></div>';
		}
		echo '<div class="hwtse-cards">';
		self::card(
			'TSE-Agent',
			array(
				'Status'        => HWTSE_Settings::agent_online() ? '✅ verbunden' : '❌ nicht verbunden',
				'Zuletzt'       => ! empty( $agent['seen'] ) ? wp_date( 'd.m.Y H:i:s', (int) $agent['seen'] ) : '–',
				'Benutzer'      => $agent['user'] ?? '–',
				'Meldung'       => $agent['error'] ?? '',
				'Offene Aufträge' => (string) HWTSE_Journal::count_open_work(),
			)
		);
		self::card(
			'TSE',
			array(
				'Modell'        => $info['vendor_type'] ?? '–',
				'Status'        => $info['initialization_state'] ?? '–',
				'Seriennummer'  => $info['serial_hex'] ?? '–',
				'BSI-Zertifikat' => $info['tse_description'] ?? '–',
				'Signaturen'    => isset( $info['created_signatures'] ) ? $info['created_signatures'] . ' / ' . ( $info['max_signatures'] ?? '?' ) : '–',
				'Speicher'      => isset( $info['used_blocks'], $info['capacity_blocks'] ) && $info['capacity_blocks'] ? round( 100 * $info['used_blocks'] / $info['capacity_blocks'], 1 ) . ' % belegt' : '–',
				'Zertifikat bis' => ! empty( $info['certificate_expiration'] ) ? HWTSE_Receipt::local_time( (string) $info['certificate_expiration'] ) : '–',
				'Zustand'       => $info['tse_health'] ?? '–',
				'Stand'         => ! empty( $info['updated'] ) ? wp_date( 'd.m.Y H:i', (int) $info['updated'] ) : '–',
			)
		);
		$journal = array();
		$labels  = array(
			'FINISHED'      => 'Signiert',
			'ACTIVE'        => 'Gestartet, wartet auf Zahlung/Abschluss',
			'ACTIVE_OPEN'   => 'Gestartet (offener Warenkorb)',
			'PENDING'       => 'Wartet auf TSE',
			'PENDING_OPEN'  => 'Angelegt (offener Warenkorb)',
			'FAILED'        => 'TSE-Ausfall (aufgegeben)',
			'VOID_OPEN'     => 'Verworfen (nie an TSE)',
		);
		foreach ( $counts as $k => $c ) {
			$journal[ $labels[ $k ] ?? $k ] = (string) $c;
		}
		self::card( 'Journal', $journal ?: array( 'Einträge' => '0' ) );
		$last = HWTSE_Journal::closings( 1 );
		self::card(
			'Letzter Kassenabschluss',
			$last ? array(
				'Z-Nr.'      => (string) $last[0]['z_nr'],
				'Geschäftstag' => wp_date( 'd.m.Y', strtotime( $last[0]['business_date'] . ' 12:00:00' ) ),
				'Vorgänge'   => (string) $last[0]['tx_count'],
				'Umsatz'     => wc_price( (float) ( $last[0]['totals_json']['revenue'] ?? 0 ) ),
			) : array( 'Z-Nr.' => 'noch keiner' )
		);
		echo '</div>';
	}

	private static function card( string $title, array $rows ): void {
		echo '<div class="hwtse-card"><h2>' . esc_html( $title ) . '</h2><table>';
		foreach ( $rows as $k => $v ) {
			echo '<tr><th>' . esc_html( (string) $k ) . '</th><td>' . wp_kses_post( (string) $v ) . '</td></tr>';
		}
		echo '</table></div>';
	}

	/* ---------- TSE-Agent ---------- */

	private static function tab_agent(): void {
		if ( '' === HWTSE_Rest::sdk_url() ) {
			echo '<div class="notice notice-error inline"><p>Die Datei <code>epos-2.x.x.js</code> aus dem Epson ePOS SDK für JavaScript fehlt. Unter <a href="' . esc_url( self::url( 'settings' ) ) . '">Einstellungen</a> hochladen.</p></div>';
		}
		$host = (string) HWTSE_Settings::get( 'printer_host' );
		$port = (int) HWTSE_Settings::get( 'printer_port' );
		echo '<p>Diese Seite ist der TSE-Agent: Sie verbindet sich direkt mit der TSE im Epson-Drucker und signiert die Kassenvorgänge. <strong>Am Kassen-PC während der Öffnungszeiten geöffnet lassen</strong> (eigenes Browserfenster, nicht minimieren).</p>';
		if ( '' !== $host ) {
			printf(
				'<p>Einmalig je Browser: <a href="%1$s" target="_blank" rel="noopener">%1$s</a> öffnen und das selbstsignierte Zertifikat des Druckers akzeptieren (Erweitert → Weiter), sonst verweigert der Browser die verschlüsselte Verbindung. Fragt der Browser nach dem Zugriff auf Geräte im lokalen Netzwerk, erlauben.</p>',
				esc_url( 'https://' . $host . ':' . $port . '/' )
			);
		}
		echo '<div id="hwtse-agent" class="hwtse-agent"><p>TSE-Agent wird geladen …</p></div>';
	}

	/* ---------- Kassenbuch ---------- */

	private static function tab_cashbook(): void {
		echo '<p>Bargeldbewegungen ohne Verkauf. Jede Buchung wird als eigener Vorgang von der TSE signiert. Beträge immer positiv eingeben; Entnahmen und Auszahlungen werden automatisch als Abgang gebucht. Beim Geldtransit und bei Kassendifferenzen das Vorzeichen selbst setzen (− = aus der Kasse).</p>';
		echo '<form id="hwtse-cashbook" class="hwtse-form"><p><label>Grund<br><select name="reason">';
		foreach ( HWTSE_Cashbook::REASONS as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $v[0] ) . '</option>';
		}
		echo '</select></label></p><p><label>Betrag (€)<br><input name="amount" type="text" inputmode="decimal" required pattern="-?[0-9]+([,.][0-9]{1,2})?"></label></p>';
		echo '<p><label>Text<br><input name="text" type="text" maxlength="200" class="regular-text"></label></p>';
		echo '<p><button class="button button-primary">Buchen</button> <span class="hwtse-result"></span></p></form>';
		$rows = array_filter( HWTSE_Journal::recent( 200 ), static fn( $r ) => 'cash' === $r['kind'] );
		self::journal_table( array_slice( $rows, 0, 50 ) );
	}

	/* ---------- Abschlüsse & Export ---------- */

	private static function tab_closings(): void {
		echo '<h2>Kassenabschluss</h2><p>Fasst alle abgeschlossenen Vorgänge seit dem letzten Abschluss zusammen (Z-Bon). ';
		echo 'yes' === HWTSE_Settings::get( 'closing_auto' ) ? 'Läuft automatisch täglich um ' . esc_html( (string) HWTSE_Settings::get( 'closing_time' ) ) . ' Uhr.' : 'Automatischer Abschluss ist ausgeschaltet.';
		echo '</p><p><button class="button button-primary" id="hwtse-closing">Kassenabschluss jetzt erstellen</button> <span class="hwtse-result"></span></p>';
		echo '<table class="widefat striped"><thead><tr><th>Z-Nr.</th><th>Geschäftstag</th><th>Erstellt</th><th>Vorgänge</th><th>Umsatz</th><th>Bar</th><th>ohne Signatur</th><th></th></tr></thead><tbody>';
		foreach ( HWTSE_Journal::closings( 60 ) as $c ) {
			$t = $c['totals_json'];
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%d</td><td><a href="%s" target="_blank">Z-Bon</a></td></tr>',
				(int) $c['z_nr'],
				esc_html( wp_date( 'd.m.Y', strtotime( $c['business_date'] . ' 12:00:00' ) ) ),
				esc_html( HWTSE_Receipt::local_time( (string) $c['created_local'] ) ),
				(int) $c['tx_count'],
				wp_kses_post( wc_price( (float) ( $t['revenue'] ?? 0 ) ) ),
				wp_kses_post( wc_price( (float) ( $t['cash_total'] ?? 0 ) ) ),
				(int) ( $t['unsigned'] ?? 0 ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hwtse_zbon&id=' . (int) $c['id'] ), 'hwtse_zbon' ) )
			);
		}
		echo '</tbody></table>';

		$today = wp_date( 'Y-m-d' );
		echo '<h2>DSFinV-K-Export</h2><p>Erzeugt das Archiv für die Finanzverwaltung (DSFinV-K 2.4, alle Kassenabschlüsse im Zeitraum).</p>';
		echo '<form id="hwtse-dsfinvk" class="hwtse-form"><label>von <input type="date" name="from" value="' . esc_attr( wp_date( 'Y-01-01' ) ) . '" required></label> <label>bis <input type="date" name="to" value="' . esc_attr( $today ) . '" required></label> <button class="button button-primary">Export erstellen</button> <span class="hwtse-result"></span></form>';
		self::files_table( 'dsfinvk' );
		echo '<h2>TSE-Exporte (TAR)</h2><p>Werden im <a href="' . esc_url( self::url( 'agent' ) ) . '">TSE-Agenten</a> erstellt und hier gespeichert.</p>';
		self::files_table( 'tse-export' );
	}

	private static function files_table( string $dir ): void {
		$files = HWTSE_Storage::files( $dir );
		if ( empty( $files ) ) {
			echo '<p><em>Noch keine Dateien.</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Datei</th><th>Größe</th><th>Erstellt</th></tr></thead><tbody>';
		foreach ( $files as $f ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=hwtse_download&dir=' . rawurlencode( $dir ) . '&file=' . rawurlencode( $f['name'] ) ), 'hwtse_download' );
			printf( '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>', esc_url( $url ), esc_html( $f['name'] ), esc_html( size_format( $f['bytes'] ) ), esc_html( wp_date( 'd.m.Y H:i', $f['time'] ) ) );
		}
		echo '</tbody></table>';
	}

	/* ---------- Journal ---------- */

	private static function tab_journal(): void {
		echo '<p>Jeder Kassenvorgang ist ein Eintrag (= Bon-Nr.). Hängende Vorgänge können als TSE-Ausfall abgeschlossen werden; sie werden dann nicht mehr signiert.</p>';
		self::journal_table( HWTSE_Journal::recent( 200 ) );
	}

	private static function journal_table( array $rows ): void {
		$kinds = array(
			'sale'   => 'Verkauf',
			'refund' => 'Erstattung',
			'abort'  => 'Abbruch',
			'cash'   => 'Kassenbuch',
		);
		echo '<table class="widefat striped hwtse-journal"><thead><tr><th>Bon-Nr.</th><th>Zeit</th><th>Art</th><th>Bestellung</th><th>Betrag</th><th>Status</th><th>TSE-Nr.</th><th>Sig.-Zähler</th><th>Fehler</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$order_link = $r['order_id'] ? '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $r['order_id'] . '&action=edit' ) ) . '">#' . (int) $r['order_id'] . '</a>' : '';
			if ( $r['order_id'] && class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$order_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $r['order_id'] ) ) . '">#' . (int) $r['order_id'] . '</a>';
			}
			$state = $r['state'] . ( (int) $r['finish_requested'] ? '' : ' (offen)' ) . ( (int) $r['fallback_released'] && 'FINISHED' !== $r['state'] ? ' · Bon ohne Signatur' : '' );
			$give  = (int) $r['finish_requested'] && in_array( $r['state'], array( 'PENDING', 'ACTIVE' ), true ) ? '<button class="button button-small hwtse-give-up" data-id="' . (int) $r['id'] . '">Als Ausfall abschließen</button>' : '';
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="hwtse-error">%s</td><td>%s</td></tr>',
				(int) $r['id'],
				esc_html( get_date_from_gmt( (string) ( $r['requested_gmt'] ?: $r['created_gmt'] ), 'd.m.Y H:i:s' ) ),
				esc_html( ( $kinds[ $r['kind'] ] ?? $r['kind'] ) . ( 'cash' === $r['kind'] ? ': ' . ( $r['meta_json']['reason'] ?? '' ) : '' ) ),
				wp_kses_post( $order_link ),
				wp_kses_post( wc_price( (float) $r['total'] ) ),
				esc_html( $state ),
				esc_html( $r['tx_number'] ? (string) $r['tx_number'] : '' ),
				esc_html( $r['signature_counter'] ? (string) $r['signature_counter'] : '' ),
				esc_html( mb_substr( (string) $r['error'], 0, 160 ) ),
				$give // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
		echo '</tbody></table>';
	}

	/* ---------- Einstellungen ---------- */

	private static function tab_settings(): void {
		$s = HWTSE_Settings::masked();
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="hwtse-settings">';
		wp_nonce_field( 'hwtse_save_settings' );
		echo '<input type="hidden" name="action" value="hwtse_save_settings">';
		$sections = array(
			'Allgemein'      => array(
				'enabled'         => array( 'Signierung aktiv', 'yesno' ),
				'start_mode'      => array( 'TSE-Transaktion starten', 'select', array( 'create' => 'bei Anlage der Kassenbestellung (empfohlen)', 'paid' => 'erst bei Zahlung (Start und Ende zusammen)' ) ),
				'hold_print'      => array( 'Cloud-Druck bis zur Signatur anhalten', 'yesno' ),
				'agent_timeout'   => array( 'Wartezeit auf die TSE in Sekunden, danach Bon mit Ausfallvermerk', 'number' ),
				'pos_gateways'    => array( 'Zahlarten, die immer Kassenvorgänge sind (IDs, kommagetrennt)', 'text' ),
				'cash_gateways'   => array( 'Bar-Zahlarten (IDs, kommagetrennt)', 'text' ),
				'receipt_label'   => array( 'Belegbezeichnung', 'text' ),
				'print_signature' => array( 'Signatur im Klartext auf den Bon drucken', 'yesno' ),
			),
			'Drucker & TSE'  => array(
				'printer_host'    => array( 'IP-Adresse des Epson-Druckers (feste IP im Laden-Netz)', 'text' ),
				'printer_port'    => array( 'ePOS-Device-Port (8043 = verschlüsselt, nötig für https)', 'number' ),
				'device_id'       => array( 'Geräte-ID der TSE', 'text' ),
				'client_id'       => array( 'Kassen-ID / Client-ID (max. 30 Zeichen, A–Z 0–9 - . : / ( ) + , = ?)', 'text' ),
				'log_time_format' => array( 'TSE-Zeitformat (für QR-Code und DSFinV-K)', 'select', array( 'utcTime' => 'utcTime', 'unixTime' => 'unixTime', 'generalizedTime' => 'generalizedTime', 'utcTimeWithSeconds' => 'utcTimeWithSeconds', 'generalizedTimeWithMilliseconds' => 'generalizedTimeWithMilliseconds' ) ),
				'puk'             => array( 'PUK (6 Ziffern)', 'secret' ),
				'admin_pin'       => array( 'Admin-PIN (5 Ziffern)', 'secret' ),
				'time_admin_pin'  => array( 'TimeAdmin-PIN (5 Ziffern)', 'secret' ),
				'secret_key'      => array( 'Secret Key (8 Zeichen)', 'secret' ),
				'sdk_url'         => array( 'Alternative URL zur ePOS-SDK-Datei (leer = hochgeladene Datei)', 'text' ),
			),
			'Kassenabschluss' => array(
				'closing_auto' => array( 'Täglicher automatischer Kassenabschluss', 'yesno' ),
				'closing_time' => array( 'Uhrzeit (HH:MM)', 'text' ),
			),
			'Stammdaten (DSFinV-K)' => array(
				'company_name'     => array( 'Unternehmen', 'text' ),
				'company_street'   => array( 'Straße', 'text' ),
				'company_zip'      => array( 'PLZ', 'text' ),
				'company_city'     => array( 'Ort', 'text' ),
				'company_country'  => array( 'Land (ISO 3166 Alpha-3, z. B. DEU)', 'text' ),
				'company_stnr'     => array( 'Steuernummer', 'text' ),
				'company_ustid'    => array( 'USt-IdNr.', 'text' ),
				'location_name'    => array( 'Betriebsstätte', 'text' ),
				'location_street'  => array( 'Straße Betriebsstätte', 'text' ),
				'location_zip'     => array( 'PLZ Betriebsstätte', 'text' ),
				'location_city'    => array( 'Ort Betriebsstätte', 'text' ),
				'location_country' => array( 'Land Betriebsstätte', 'text' ),
				'register_brand'   => array( 'Kassen-Hersteller', 'text' ),
				'register_model'   => array( 'Kassen-Modell', 'text' ),
			),
		);
		foreach ( $sections as $title => $fields ) {
			echo '<h2>' . esc_html( $title ) . '</h2><table class="form-table">';
			foreach ( $fields as $key => $f ) {
				echo '<tr><th><label for="hwtse-' . esc_attr( $key ) . '">' . esc_html( $f[0] ) . '</label></th><td>';
				$val = (string) $s[ $key ];
				switch ( $f[1] ) {
					case 'yesno':
						echo '<select id="hwtse-' . esc_attr( $key ) . '" name="hwtse[' . esc_attr( $key ) . ']"><option value="yes" ' . selected( $val, 'yes', false ) . '>Ja</option><option value="no" ' . selected( $val, 'no', false ) . '>Nein</option></select>';
						break;
					case 'select':
						echo '<select id="hwtse-' . esc_attr( $key ) . '" name="hwtse[' . esc_attr( $key ) . ']">';
						foreach ( $f[2] as $ok => $ol ) {
							echo '<option value="' . esc_attr( $ok ) . '" ' . selected( $val, $ok, false ) . '>' . esc_html( $ol ) . '</option>';
						}
						echo '</select>';
						break;
					case 'secret':
						echo '<input id="hwtse-' . esc_attr( $key ) . '" type="password" autocomplete="new-password" name="hwtse[' . esc_attr( $key ) . ']" value="" placeholder="' . esc_attr( '' !== $val ? 'gespeichert (' . $val . ') – leer lassen = unverändert' : 'nicht gesetzt' ) . '" class="regular-text">';
						break;
					case 'number':
						echo '<input id="hwtse-' . esc_attr( $key ) . '" type="number" min="0" name="hwtse[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" class="small-text">';
						break;
					default:
						echo '<input id="hwtse-' . esc_attr( $key ) . '" type="text" name="hwtse[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" class="regular-text">';
				}
				echo '</td></tr>';
			}
			if ( 'Drucker & TSE' === $title ) {
				$sdk = HWTSE_Storage::path( 'sdk', 'epos-device.js' );
				echo '<tr><th>Epson ePOS SDK für JavaScript</th><td><input type="file" name="hwtse_sdk" accept=".js"> <p class="description">Datei <code>epos-2.x.x.js</code> aus dem „Epson ePOS SDK for JavaScript“ (Download bei Epson). ' . ( $sdk ? 'Hochgeladen am ' . esc_html( wp_date( 'd.m.Y H:i', (int) filemtime( $sdk ) ) ) . '.' : 'Noch nicht hochgeladen.' ) . '</p></td></tr>';
			}
			echo '</table>';
		}
		submit_button( 'Einstellungen speichern' );
		echo '</form>';
	}

	public static function save_settings(): void {
		if ( ! HWTSE_Rest::can() ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'hwtse_save_settings' );
		$in     = isset( $_POST['hwtse'] ) && is_array( $_POST['hwtse'] ) ? wp_unslash( $_POST['hwtse'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$values = array();
		foreach ( $in as $k => $v ) {
			$k = sanitize_key( $k );
			if ( in_array( $k, HWTSE_Settings::SECRET_KEYS, true ) ) {
				if ( '' === (string) $v ) {
					continue; // Empty secret field = keep the stored value.
				}
				$values[ $k ] = trim( (string) $v );
				continue;
			}
			$values[ $k ] = sanitize_text_field( (string) $v );
		}
		$error = '';
		try {
			$check = array_intersect_key( $values, array_flip( HWTSE_Settings::SECRET_KEYS ) );
			if ( $check ) {
				$req = new WP_REST_Request( 'POST' );
				foreach ( $check as $k => $v ) {
					$req->set_param( $k, $v );
				}
				HWTSE_Rest::save_credentials( $req );
			}
			HWTSE_Settings::update( array_diff_key( $values, array_flip( HWTSE_Settings::SECRET_KEYS ) ) );
			self::handle_sdk_upload();
		} catch ( HWTSE_Exception $e ) {
			$error = $e->getMessage();
		}
		wp_clear_scheduled_hook( 'hwtse_daily_closing' );
		HWTSE_Closing::schedule();
		wp_safe_redirect( self::url( 'settings', '' !== $error ? array( 'error' => rawurlencode( $error ) ) : array( 'saved' => 1 ) ) );
		exit;
	}

	private static function handle_sdk_upload(): void {
		if ( empty( $_FILES['hwtse_sdk']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$file = $_FILES['hwtse_sdk']; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
		if ( ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > 3 * MB_IN_BYTES || ! str_ends_with( strtolower( (string) $file['name'] ), '.js' ) ) {
			throw new HWTSE_Exception( 'Bitte die Datei epos-2.x.x.js (max. 3 MB) hochladen.', 'E_SDK' );
		}
		$content = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! str_contains( $content, 'ePOSDevice' ) || ! str_contains( $content, 'GermanyFiscalElement' ) ) {
			throw new HWTSE_Exception( 'Die Datei ist kein Epson ePOS SDK mit TSE-Unterstützung (GermanyFiscalElement fehlt).', 'E_SDK' );
		}
		file_put_contents( HWTSE_Storage::dir( 'sdk' ) . '/epos-device.js', $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ---------- downloads ---------- */

	public static function download(): void {
		if ( ! HWTSE_Rest::can() ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'hwtse_download' );
		$dir = isset( $_GET['dir'] ) ? sanitize_key( wp_unslash( $_GET['dir'] ) ) : '';
		if ( ! in_array( $dir, array( 'dsfinvk', 'tse-export' ), true ) ) {
			wp_die( 'Ungültiges Verzeichnis.' );
		}
		HWTSE_Storage::send( $dir, isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '' );
	}

	public static function zbon(): void {
		if ( ! HWTSE_Rest::can() ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'hwtse_zbon' );
		$closing = HWTSE_Journal::closing( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		if ( ! $closing ) {
			wp_die( 'Kassenabschluss nicht gefunden.' );
		}
		echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Z-Bon ' . (int) $closing['z_nr'] . '</title><style>body{font:14px/1.4 sans-serif;max-width:640px;margin:24px auto}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #ddd;padding:4px;text-align:left}td:last-child,th:last-child{text-align:right}@media print{button{display:none}}</style></head><body>';
		echo HWTSE_Closing::render_z_bon( $closing ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p><button onclick="window.print()">Drucken</button></p></body></html>';
		exit;
	}
}
