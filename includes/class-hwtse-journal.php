<?php
defined( 'ABSPATH' ) || exit;

/**
 * Journal of every cash register transaction (one row per TSE transaction).
 *
 * The journal is the single source for receipts, Kassenabschlüsse and the
 * DSFinV-K export. Rows are never deleted; amounts and lines are snapshotted
 * when the transaction is finished so later order edits do not change the record.
 *
 * state:            PENDING (nothing at the TSE yet) → ACTIVE (started) → FINISHED,
 *                   VOID (abandoned before the TSE ever saw it), FAILED (given up: TSE-Ausfall).
 *                   A row whose deadline passed keeps its state and is still retried, but
 *                   fallback_released = 1 lets the receipt print with the outage notice.
 * finish_requested: 1 once the business event that closes the transaction happened
 *                   (payment, refund, abort, cash movement) and process data is final.
 */
class HWTSE_Journal {

	const DB_VERSION = '1';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwtse_journal';
	}

	public static function closings_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'hwtse_closings';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_gmt DATETIME NOT NULL,
			updated_gmt DATETIME NOT NULL,
			requested_gmt DATETIME NULL,
			kind VARCHAR(20) NOT NULL DEFAULT '',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			refund_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ref_journal_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			state VARCHAR(20) NOT NULL DEFAULT 'PENDING',
			finish_requested TINYINT(1) NOT NULL DEFAULT 0,
			bon_type VARCHAR(30) NOT NULL DEFAULT 'Beleg',
			process_type VARCHAR(100) NOT NULL DEFAULT 'Kassenbeleg-V1',
			process_data TEXT NULL,
			total DECIMAL(14,2) NOT NULL DEFAULT 0,
			vat_json TEXT NULL,
			payment_json TEXT NULL,
			lines_json LONGTEXT NULL,
			meta_json TEXT NULL,
			client_id VARCHAR(30) NOT NULL DEFAULT '',
			tx_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
			time_start VARCHAR(32) NOT NULL DEFAULT '',
			time_end VARCHAR(32) NOT NULL DEFAULT '',
			sig_counter_start BIGINT UNSIGNED NOT NULL DEFAULT 0,
			signature_start TEXT NULL,
			signature_counter BIGINT UNSIGNED NOT NULL DEFAULT 0,
			signature TEXT NULL,
			tse_serial VARCHAR(128) NOT NULL DEFAULT '',
			error TEXT NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			lease_agent VARCHAR(40) NOT NULL DEFAULT '',
			lease_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
			fallback_released TINYINT(1) NOT NULL DEFAULT 0,
			closing_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY refund_id (refund_id),
			KEY state (state, finish_requested),
			KEY closing_id (closing_id),
			KEY tx_number (tx_number)
		) {$charset};"
		);
		$closings = self::closings_table();
		dbDelta(
			"CREATE TABLE {$closings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			z_nr BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_gmt DATETIME NOT NULL,
			created_local VARCHAR(32) NOT NULL DEFAULT '',
			business_date DATE NOT NULL,
			client_id VARCHAR(30) NOT NULL DEFAULT '',
			tx_count INT UNSIGNED NOT NULL DEFAULT 0,
			first_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			totals_json LONGTEXT NULL,
			master_json LONGTEXT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY z_nr (z_nr),
			KEY business_date (business_date)
		) {$charset};"
		);
		update_option( 'hwtse_db_version', self::DB_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'hwtse_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* ---------- rows ---------- */

	public static function insert( array $row ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array_merge(
			array(
				'created_gmt' => $now,
				'updated_gmt' => $now,
				'client_id'   => (string) HWTSE_Settings::get( 'client_id' ),
			),
			$row
		);
		$wpdb->insert( self::table(), self::encode( $row ) );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $row ): void {
		global $wpdb;
		$row['updated_gmt'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( self::table(), self::encode( $row ), array( 'id' => $id ) );
	}

	/**
	 * Conditional update (compare-and-set on state) so two requests cannot both move a row.
	 */
	public static function transition( int $id, array $from_states, array $row ): bool {
		global $wpdb;
		$row['updated_gmt'] = gmdate( 'Y-m-d H:i:s' );
		$row                = self::encode( $row );
		$sets               = array();
		$values             = array();
		foreach ( $row as $k => $v ) {
			$sets[] = "`{$k}` = %s";
			$values[] = $v;
		}
		$in       = implode( ',', array_fill( 0, count( $from_states ), '%s' ) );
		$values   = array_merge( $values, array( $id ), $from_states );
		$table    = self::table();
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $sets ) . " WHERE id = %d AND state IN ({$in})", $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $affected > 0;
	}

	private static function encode( array $row ): array {
		foreach ( array( 'vat_json', 'payment_json', 'lines_json', 'meta_json' ) as $k ) {
			if ( isset( $row[ $k ] ) && is_array( $row[ $k ] ) ) {
				$row[ $k ] = wp_json_encode( $row[ $k ], JSON_UNESCAPED_UNICODE );
			}
		}
		return $row;
	}

	public static function decode( ?array $row ): ?array {
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'vat_json', 'payment_json', 'lines_json', 'meta_json' ) as $k ) {
			$row[ $k ] = isset( $row[ $k ] ) && '' !== (string) $row[ $k ] ? ( json_decode( (string) $row[ $k ], true ) ?: array() ) : array();
		}
		return $row;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		return self::decode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Sale row (kind sale/abort) of an order, newest first. */
	public static function for_order( int $order_id ): ?array {
		global $wpdb;
		$table = self::table();
		return self::decode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d AND refund_id = 0 AND kind IN ('sale','abort') ORDER BY id DESC LIMIT 1", $order_id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function for_refund( int $refund_id ): ?array {
		global $wpdb;
		$table = self::table();
		return self::decode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE refund_id = %d ORDER BY id DESC LIMIT 1", $refund_id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Refund rows of an order. */
	public static function refunds_of( int $order_id ): array {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d AND kind = 'refund' ORDER BY id ASC", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'decode' ), $rows );
	}

	/**
	 * Lease up to $limit rows the agent has to work on.
	 *
	 * A row needs work when nothing is at the TSE yet (PENDING) or when it is
	 * started and its finishing event happened (ACTIVE + finish_requested), and
	 * no other agent holds a live lease.
	 */
	public static function lease_jobs( string $agent_id, int $limit = 10, int $lease_seconds = 90 ): array {
		global $wpdb;
		$table = self::table();
		$now   = time();
		$ids   = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE ( state = 'PENDING' OR ( state = 'ACTIVE' AND finish_requested = 1 ) ) AND ( lease_until < %d OR lease_agent = %s ) AND attempts < 50 ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$agent_id,
				$limit
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lease_agent = %s, lease_until = %d WHERE id IN ({$in}) AND ( lease_until < %d OR lease_agent = %s )", $agent_id, $now + $lease_seconds, $now, $agent_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$in}) AND lease_agent = %s ORDER BY id ASC", $agent_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( __CLASS__, 'decode' ), $rows );
	}

	public static function count_open_work(): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE ( state = 'PENDING' AND finish_requested = 1 ) OR ( state = 'ACTIVE' AND finish_requested = 1 )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function recent( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'decode' ), $rows );
	}

	public static function counts(): array {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( "SELECT state, finish_requested, COUNT(*) AS c FROM {$table} GROUP BY state, finish_requested", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( $rows as $r ) {
			$key         = $r['state'] . ( '1' === (string) $r['finish_requested'] ? '' : '_OPEN' );
			$out[ $key ] = ( $out[ $key ] ?? 0 ) + (int) $r['c'];
		}
		return $out;
	}

	/** Rows that started at the TSE long ago and never got their finishing event. */
	public static function stale_active( int $older_than_seconds ): array {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE state IN ('ACTIVE','PENDING') AND finish_requested = 0 AND created_gmt < %s ORDER BY id ASC LIMIT 200", gmdate( 'Y-m-d H:i:s', time() - $older_than_seconds ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'decode' ), $rows );
	}

	/** Finish-requested rows still waiting for the TSE after their deadline. */
	public static function overdue( int $seconds ): array {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE finish_requested = 1 AND state IN ('PENDING','ACTIVE') AND fallback_released = 0 AND requested_gmt < %s ORDER BY id ASC LIMIT 200", gmdate( 'Y-m-d H:i:s', time() - $seconds ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'decode' ), $rows );
	}

	/* ---------- closings ---------- */

	/**
	 * Rows that belong into a Kassenabschluss: finished (or failed) transactions whose
	 * business event happened up to $until_gmt and that are not in a closing yet.
	 */
	public static function rows_for_closing( string $until_gmt ): array {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE closing_id = 0 AND finish_requested = 1 AND ( state IN ('FINISHED','FAILED') OR fallback_released = 1 ) AND requested_gmt <= %s ORDER BY id ASC", $until_gmt ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'decode' ), $rows );
	}

	public static function rows_of_closing( int $closing_id ): array {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE closing_id = %d ORDER BY id ASC", $closing_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'decode' ), $rows );
	}

	public static function assign_closing( array $ids, int $closing_id ): void {
		global $wpdb;
		if ( empty( $ids ) ) {
			return;
		}
		$in    = implode( ',', array_map( 'intval', $ids ) );
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET closing_id = %d WHERE id IN ({$in}) AND closing_id = 0", $closing_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function insert_closing( array $row ): int {
		global $wpdb;
		foreach ( array( 'totals_json', 'master_json' ) as $k ) {
			if ( isset( $row[ $k ] ) && is_array( $row[ $k ] ) ) {
				$row[ $k ] = wp_json_encode( $row[ $k ], JSON_UNESCAPED_UNICODE );
			}
		}
		$wpdb->insert( self::closings_table(), $row );
		return (int) $wpdb->insert_id;
	}

	public static function closing( int $id ): ?array {
		global $wpdb;
		$table = self::closings_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::decode_closing( $row ) : null;
	}

	public static function closings( int $limit = 60 ): array {
		global $wpdb;
		$table = self::closings_table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY z_nr DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'decode_closing' ), $rows );
	}

	public static function closings_between( string $from_date, string $to_date ): array {
		global $wpdb;
		$table = self::closings_table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE business_date BETWEEN %s AND %s ORDER BY z_nr ASC", $from_date, $to_date ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'decode_closing' ), $rows );
	}

	public static function last_z_nr(): int {
		global $wpdb;
		$table = self::closings_table();
		return (int) $wpdb->get_var( "SELECT MAX(z_nr) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function closing_by_z_nr( int $z_nr ): ?array {
		global $wpdb;
		$table = self::closings_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE z_nr = %d", $z_nr ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::decode_closing( $row ) : null;
	}

	private static function decode_closing( array $row ): array {
		foreach ( array( 'totals_json', 'master_json' ) as $k ) {
			$row[ $k ] = '' !== (string) ( $row[ $k ] ?? '' ) ? ( json_decode( (string) $row[ $k ], true ) ?: array() ) : array();
		}
		return $row;
	}
}
