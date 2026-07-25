<?php
/**
 * 激活与数据库升级。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 建表、写入默认设置、升级库版本。
 */
class WPRS_Activator {

	/**
	 * 插件激活回调。
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_tables();
		if ( false === get_option( WPRS_Settings::OPTION, false ) ) {
			add_option( WPRS_Settings::OPTION, WPRS_Settings::defaults(), '', false );
		}
		update_option( 'wprs_db_version', WPRS_DB_VERSION, false );
	}

	/**
	 * 后台检查是否需要升级表结构。
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$current = get_option( 'wprs_db_version', '' );
		if ( (string) $current === (string) WPRS_DB_VERSION ) {
			return;
		}
		self::install_tables();
		update_option( 'wprs_db_version', WPRS_DB_VERSION, false );
	}

	/**
	 * 使用 dbDelta 创建/更新数据表。
	 *
	 * @return void
	 */
	public static function install_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$terms   = self::table_terms();
		$results = self::table_results();
		$archives = self::table_archives();
		$log     = self::table_log();

		$sql_terms = "CREATE TABLE {$terms} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			risk_term varchar(255) NOT NULL,
			recommended_term varchar(255) DEFAULT '',
			guidelines text,
			create_time datetime DEFAULT CURRENT_TIMESTAMP,
			update_time datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			update_user varchar(100) DEFAULT '',
			PRIMARY KEY  (id),
			KEY risk_term (risk_term)
		) {$charset};";

		$sql_results = "CREATE TABLE {$results} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			post_title text,
			post_type varchar(20) DEFAULT '',
			post_update_time datetime,
			risk_terms_found text,
			risk_snippets text,
			risk_terms_by_source text,
			ignored_terms text,
			scan_time datetime,
			PRIMARY KEY  (id),
			KEY post_id (post_id)
		) {$charset};";

		$sql_archives = "CREATE TABLE {$archives} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_type varchar(32) NOT NULL DEFAULT 'taxonomy_archive',
			taxonomy varchar(32) NOT NULL,
			term_id bigint(20) unsigned NOT NULL,
			title text,
			archive_url text,
			resource_update_time datetime NULL,
			risk_terms_found text,
			risk_terms_by_source text,
			risk_snippets text,
			ignored_terms text,
			scan_time datetime,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_tax_term (taxonomy, term_id),
			KEY taxonomy (taxonomy),
			KEY term_id (term_id),
			KEY scan_time (scan_time)
		) {$charset};";

		$sql_log = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_type varchar(20) NOT NULL DEFAULT 'cron',
			scan_type_label varchar(32) NOT NULL DEFAULT '',
			posts_scanned int unsigned NOT NULL DEFAULT 0,
			risk_count int unsigned NOT NULL DEFAULT 0,
			archives_scanned int unsigned NOT NULL DEFAULT 0,
			archives_risk_count int unsigned NOT NULL DEFAULT 0,
			execution_time_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			failed_posts int unsigned NOT NULL DEFAULT 0,
			last_error text,
			operator varchar(100) NOT NULL DEFAULT '',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql_terms );
		dbDelta( $sql_results );
		dbDelta( $sql_archives );
		dbDelta( $sql_log );
	}

	/**
	 * 违禁词表名。
	 *
	 * @return string
	 */
	public static function table_terms() {
		global $wpdb;
		return $wpdb->prefix . 'wprs_risk_terms';
	}

	/**
	 * 文章扫描结果表名。
	 *
	 * @return string
	 */
	public static function table_results() {
		global $wpdb;
		return $wpdb->prefix . 'wprs_risk_scan_results';
	}

	/**
	 * 归档页扫描结果表名。
	 *
	 * @return string
	 */
	public static function table_archives() {
		global $wpdb;
		return $wpdb->prefix . 'wprs_risk_scan_archives';
	}

	/**
	 * 扫描日志表名。
	 *
	 * @return string
	 */
	public static function table_log() {
		global $wpdb;
		return $wpdb->prefix . 'wprs_risk_scan_log';
	}

	/**
	 * 删除全部自定义表（卸载时可选）。
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 表名来自本类固定方法。
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_terms() );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_results() );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_archives() );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_log() );
	}
}
