<?php
/**
 * Logging of cleanup operations to a dedicated table.
 *
 * We deliberately store only non-sensitive metadata (ids, counts, result,
 * error message) — never personal data such as answers or emails.
 *
 * @package TutorUserCleanup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Tutor_User_Cleanup_Logger
 */
class Tutor_User_Cleanup_Logger {

	/**
	 * Unqualified table name (without prefix).
	 */
	const TABLE = 'tutor_user_cleanup_log';

	/**
	 * Fully-qualified log table name for the current site.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the log table. Safe to call repeatedly (dbDelta).
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			target_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			actor_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			context VARCHAR(40) NOT NULL DEFAULT '',
			dry_run TINYINT(1) NOT NULL DEFAULT 0,
			result VARCHAR(20) NOT NULL DEFAULT '',
			counts LONGTEXT NULL,
			message TEXT NULL,
			PRIMARY KEY  (id),
			KEY target_user_id (target_user_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * True if the log table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::table_name();
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Write a log record.
	 *
	 * @param array{
	 *     target_user_id?:int,
	 *     context?:string,
	 *     dry_run?:bool,
	 *     result?:string,
	 *     counts?:array<string,int>,
	 *     operations?:array<string,array<string,mixed>>,
	 *     message?:string
	 * } $entry Log entry data.
	 * @return void
	 */
	public static function log( array $entry ) {
		if ( ! Tutor_User_Cleanup_Settings::get( 'enable_logging', true ) ) {
			return;
		}
		if ( ! self::table_exists() ) {
			// Table missing (e.g. plugin files updated without re-activation) — try to build it once.
			self::install();
			if ( ! self::table_exists() ) {
				return;
			}
		}

		global $wpdb;

		$counts     = isset( $entry['counts'] ) && is_array( $entry['counts'] ) ? $entry['counts'] : array();
		$operations = isset( $entry['operations'] ) && is_array( $entry['operations'] ) ? $entry['operations'] : array();
		$payload    = empty( $operations )
			? $counts
			: array(
				'requested'  => $counts,
				'operations' => $operations,
			);

		$wpdb->insert(
			self::table_name(),
			array(
					'created_at'     => current_time( 'mysql', true ),
				'blog_id'        => (int) get_current_blog_id(),
				'target_user_id' => isset( $entry['target_user_id'] ) ? absint( $entry['target_user_id'] ) : 0,
				'actor_user_id'  => (int) get_current_user_id(),
				'context'        => isset( $entry['context'] ) ? substr( (string) $entry['context'], 0, 40 ) : '',
				'dry_run'        => ! empty( $entry['dry_run'] ) ? 1 : 0,
				'result'         => isset( $entry['result'] ) ? substr( (string) $entry['result'], 0, 20 ) : '',
					'counts'         => wp_json_encode( $payload ),
				'message'        => isset( $entry['message'] ) ? (string) $entry['message'] : '',
			),
			array( '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Fetch recent log rows (newest first) for the admin viewer.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,object>
	 */
	public static function get_recent( $limit = 100 ) {
		if ( ! self::table_exists() ) {
			return array();
		}
		global $wpdb;
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$table = self::table_name();

		// $table is built from $wpdb->prefix; $limit is an int — safe to interpolate.
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete log rows older than the retention window.
	 *
	 * @return int Rows removed.
	 */
	public static function purge_expired() {
		if ( ! self::table_exists() ) {
			return 0;
		}
		$days = (int) Tutor_User_Cleanup_Settings::get( 'log_retention_days', 180 );
		if ( $days <= 0 ) {
			return 0; // keep forever.
		}

		global $wpdb;
		$table  = self::table_name();
		$cutoff = self::utc_mysql_time( time() - ( $days * DAY_IN_SECONDS ) );

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Format a Unix timestamp for the UTC DATETIME values stored in the log.
	 *
	 * @param int|null $timestamp Timestamp; null means now.
	 * @return string
	 */
	public static function utc_mysql_time( $timestamp = null ) {
		$timestamp = null === $timestamp ? time() : (int) $timestamp;
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Drop the log table (used by uninstall).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
