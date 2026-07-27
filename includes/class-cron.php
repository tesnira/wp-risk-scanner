<?php
/**
 * 定时扫描：WP-Cron 与系统 URL Cron。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 调度、触发 URL、安装脚本下载。
 */
class WPRS_Cron {

	const HOOK_TICK           = 'wprs_cron_tick';
	const OPTION_RAN_SLOTS    = 'wprs_cron_ran_slots';
	const OPTION_LAST_SUCCESS = 'wprs_last_cron_success_at';
	const QUERY_KEY           = 'wprs_cron';

	/**
	 * 注册钩子。
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( 'init', array( $this, 'ensure_tick_scheduled' ) );
		add_action( self::HOOK_TICK, array( $this, 'on_tick' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_handle_url_trigger' ), 1 );
		add_action( 'admin_post_wprs_cron_download_sh', array( $this, 'download_install_script' ) );
		add_action( 'admin_post_wprs_cron_regenerate_secret', array( $this, 'regenerate_secret' ) );
		add_action( 'update_option_' . WPRS_Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 2 );
	}

	/**
	 * 增加 5 分钟周期。
	 *
	 * @param array<string, array<string, mixed>> $schedules 调度表。
	 * @return array<string, array<string, mixed>>
	 */
	public function add_schedule( $schedules ) {
		if ( ! isset( $schedules['wprs_five_minutes'] ) ) {
			$schedules['wprs_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( '每 5 分钟（WP Risk Scanner）', 'wp-risk-scanner' ),
			);
		}
		return $schedules;
	}

	/**
	 * 确保 WP-Cron tick 已调度（模式为 wp_cron 且配置了时间点时）。
	 *
	 * @return void
	 */
	public function ensure_tick_scheduled() {
		$settings = WPRS_Settings::get();
		$need     = ( 'wp_cron' === $settings['cron_mode'] && ! empty( $settings['cron_times'] ) );
		$next     = wp_next_scheduled( self::HOOK_TICK );

		if ( $need && ! $next ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wprs_five_minutes', self::HOOK_TICK );
		}
		if ( ! $need && $next ) {
			wp_unschedule_event( $next, self::HOOK_TICK );
			wp_clear_scheduled_hook( self::HOOK_TICK );
		}
	}

	/**
	 * 设置变更后重算调度，并尝试写 uploads 脚本。
	 *
	 * @param mixed $old 旧值。
	 * @param mixed $new 新值。
	 * @return void
	 */
	public function on_settings_updated( $old, $new ) {
		unset( $old, $new );
		$this->ensure_tick_scheduled();
		self::write_install_script();
	}

	/**
	 * WP-Cron 滴答：匹配今日未跑过的时间点。
	 *
	 * @return void
	 */
	public function on_tick() {
		$settings = WPRS_Settings::get();
		if ( 'wp_cron' !== $settings['cron_mode'] ) {
			return;
		}
		$times = $settings['cron_times'];
		if ( ! $times ) {
			return;
		}

		$tz_string = $settings['cron_timezone'] ? $settings['cron_timezone'] : wp_timezone_string();
		try {
			$tz  = new DateTimeZone( $tz_string );
			$now = new DateTime( 'now', $tz );
		} catch ( Exception $e ) {
			$now = new DateTime( 'now', wp_timezone() );
		}

		$hm       = $now->format( 'H:i' );
		$today    = $now->format( 'Y-m-d' );
		$ran      = get_option( self::OPTION_RAN_SLOTS, array() );
		if ( ! is_array( $ran ) ) {
			$ran = array();
		}

		foreach ( $times as $time ) {
			$time = self::normalize_time( $time );
			if ( '' === $time || $time !== $hm ) {
				continue;
			}
			$slot = $today . '_' . $time;
			if ( ! empty( $ran[ $slot ] ) ) {
				continue;
			}
			self::execute_scan( 'wp_cron', __( '定时任务扫描（WP-Cron）', 'wp-risk-scanner' ) );
			$ran[ $slot ] = time();
			// 只保留近 3 天槽位。
			foreach ( array_keys( $ran ) as $key ) {
				if ( strncmp( $key, $today, 10 ) !== 0 && substr( $key, 0, 10 ) < gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) ) {
					unset( $ran[ $key ] );
				}
			}
			update_option( self::OPTION_RAN_SLOTS, $ran, false );
			break;
		}
	}

	/**
	 * 规范化 HH:MM。
	 *
	 * @param string $time 时间。
	 * @return string
	 */
	public static function normalize_time( $time ) {
		$time = trim( (string) $time );
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $m ) ) {
			return '';
		}
		$h = min( 23, max( 0, (int) $m[1] ) );
		$i = min( 59, max( 0, (int) $m[2] ) );
		return sprintf( '%02d:%02d', $h, $i );
	}

	/**
	 * 系统 Cron URL 触发。
	 *
	 * @return void
	 */
	public function maybe_handle_url_trigger() {
		if ( ! isset( $_GET[ self::QUERY_KEY ] ) ) {
			return;
		}
		$key    = (string) wp_unslash( $_GET[ self::QUERY_KEY ] );
		$saved  = (string) ( WPRS_Settings::get()['cron_secret'] ?? '' );
		if ( '' === $saved || '' === $key || ! hash_equals( $saved, $key ) ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'FORBIDDEN';
			exit;
		}

		$settings = WPRS_Settings::get();
		// URL 触发在 system 模式使用；wp_cron 模式下也允许手动/外部调用以便调试。
		self::execute_scan( 'system', __( '定时任务扫描（系统 Cron）', 'wp-risk-scanner' ) );
		update_option( self::OPTION_LAST_SUCCESS, current_time( 'timestamp' ), false );

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'OK';
		exit;
	}

	/**
	 * 执行一次定时扫描（增量或因词表变更强制全量）。
	 *
	 * @param string $type  日志类型。
	 * @param string $label 日志文案。
	 * @return array<string, mixed>
	 */
	public static function execute_scan( $type, $label ) {
		global $wpdb;
		$started = microtime( true );

		$force_full = WPRS_Terms::needs_global_rescan();
		$last_scan  = get_option( WPRS_Scanner::OPTION_LAST_SCAN, '1970-01-01 00:00:00' );
		if ( $force_full ) {
			$last_scan = '1970-01-01 00:00:00';
			$label    .= '【全局】';
		} else {
			$label .= '【增量】';
		}

		$types = WPRS_Scanner::eligible_post_types();
		$in    = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";
		$sql   = "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in})";
		if ( '1970-01-01 00:00:00' !== $last_scan ) {
			$sql .= $wpdb->prepare( ' AND post_modified > %s', $last_scan );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$post_ids = $wpdb->get_col( $sql );
		$post_ids = array_map( 'intval', is_array( $post_ids ) ? $post_ids : array() );

		$result   = WPRS_Scanner::scan_posts( $post_ids );
		$failed   = (int) $result['failed_posts'];
		$error    = (string) $result['last_error'];

		$archives_scanned = 0;
		$archive_targets  = WPRS_Archive_Scanner::collect_targets( ! $force_full, $last_scan );
		if ( $archive_targets ) {
			$archives_scanned = count( $archive_targets );
			$archive_result   = WPRS_Archive_Scanner::scan_targets( $archive_targets );
			$failed          += (int) $archive_result['failed'];
			if ( ! empty( $archive_result['last_error'] ) ) {
				$error = (string) $archive_result['last_error'];
			}
		}

		$duration            = (int) round( ( microtime( true ) - $started ) * 1000 );
		$risk                = WPRS_Scanner::count_with_risk();
		$archives_risk_count = WPRS_Archive_Scanner::count_with_risk();

		update_option( WPRS_Scanner::OPTION_LAST_SCAN, current_time( 'mysql' ), false );
		update_option( self::OPTION_LAST_SUCCESS, current_time( 'timestamp' ), false );

		WPRS_Scanner::write_log(
			$type,
			$label,
			count( $post_ids ),
			$risk,
			$duration,
			$failed,
			$error,
			$archives_scanned,
			$archives_risk_count
		);

		if ( $force_full && 0 === $failed ) {
			$terms_changed_at = (int) get_option( WPRS_Terms::OPTION_CHANGED_AT, 0 );
			$t                = $terms_changed_at > 0 ? $terms_changed_at : (int) current_time( 'timestamp' );
			update_option( WPRS_Scanner::OPTION_LAST_FULL_AT, $t, false );
			WPRS_Terms::clear_rescan_flag();
		}

		WPRS_Notifier::maybe_notify( $risk + $archives_risk_count, count( $post_ids ) + $archives_scanned, false );

		return array(
			'scanned'          => count( $post_ids ),
			'archives_scanned' => $archives_scanned,
			'risk'             => $risk,
			'archives_risk'    => $archives_risk_count,
			'result'           => $result,
		);
	}

	/**
	 * 触发 URL。
	 *
	 * @return string
	 */
	public static function trigger_url() {
		$secret = (string) ( WPRS_Settings::get()['cron_secret'] ?? '' );
		if ( '' === $secret ) {
			return '';
		}
		return add_query_arg( self::QUERY_KEY, rawurlencode( $secret ), home_url( '/' ) );
	}

	/**
	 * 生成或确保密钥存在。
	 *
	 * @param bool $force 强制刷新。
	 * @return string
	 */
	public static function ensure_secret( $force = false ) {
		$settings = WPRS_Settings::get();
		if ( ! $force && ! empty( $settings['cron_secret'] ) ) {
			return (string) $settings['cron_secret'];
		}
		$settings['cron_secret'] = wp_generate_password( 32, false, false );
		update_option( WPRS_Settings::OPTION, $settings, false );
		self::write_install_script();
		return (string) $settings['cron_secret'];
	}

	/**
	 * 安装脚本内容。
	 *
	 * @return string
	 */
	public static function build_install_script() {
		$settings = WPRS_Settings::get();
		$times    = $settings['cron_times'];
		$url      = self::trigger_url();
		$host     = isset( $settings['cron_host_header'] ) ? trim( (string) $settings['cron_host_header'] ) : '';

		$lines   = array();
		$lines[] = '#!/usr/bin/env bash';
		$lines[] = '# WP Risk Scanner cron installer';
		$lines[] = '# Marker: WPRS_RISK_SCANNER';
		$lines[] = 'set -euo pipefail';
		$lines[] = 'TMP="$(mktemp)"';
		$lines[] = 'crontab -l 2>/dev/null | grep -v "WPRS_RISK_SCANNER" > "$TMP" || true';

		if ( ! $times || '' === $url ) {
			$lines[] = '# No schedule times — remove old WPRS jobs only.';
		} else {
			foreach ( $times as $time ) {
				$time = self::normalize_time( $time );
				if ( '' === $time ) {
					continue;
				}
				list( $h, $m ) = array_map( 'intval', explode( ':', $time ) );
				$curl          = 'curl -fsS';
				if ( '' !== $host ) {
					$curl .= ' -H ' . escapeshellarg( 'Host: ' . $host );
				}
				$curl   .= ' ' . escapeshellarg( $url );
				$lines[] = sprintf(
					'echo %s >> "$TMP"',
					escapeshellarg( sprintf( '%d %d * * * %s # WPRS_RISK_SCANNER', $m, $h, $curl ) )
				);
			}
		}

		$lines[] = 'crontab "$TMP"';
		$lines[] = 'rm -f "$TMP"';
		$lines[] = 'echo "WPRS cron installed."';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * 写入 uploads 目录脚本。
	 *
	 * @return true|WP_Error
	 */
	public static function write_install_script() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wprs_upload', (string) $upload['error'] );
		}
		$dir = trailingslashit( $upload['basedir'] ) . 'wp-risk-scanner';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'wprs_mkdir', __( '无法创建 uploads/wp-risk-scanner 目录。', 'wp-risk-scanner' ) );
		}
		// 禁止 Web 直接访问。
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}
		$path = $dir . '/install-cron.sh';
		$ok   = file_put_contents( $path, self::build_install_script() );
		if ( false === $ok ) {
			return new WP_Error( 'wprs_write', __( '无法写入 install-cron.sh，请使用「下载安装脚本」。', 'wp-risk-scanner' ) );
		}
		return true;
	}

	/**
	 * 下载安装脚本。
	 *
	 * @return void
	 */
	public function download_install_script() {
		if ( ! current_user_can( WPRS_Settings::cap_manage() ) ) {
			wp_die( esc_html__( '无权限', 'wp-risk-scanner' ) );
		}
		check_admin_referer( 'wprs_cron_download_sh' );
		self::ensure_secret( false );
		$script = self::build_install_script();
		nocache_headers();
		header( 'Content-Type: text/x-shellscript; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wprs-install-cron.sh"' );
		echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * 重新生成密钥。
	 *
	 * @return void
	 */
	public function regenerate_secret() {
		if ( ! current_user_can( WPRS_Settings::cap_sensitive() ) ) {
			wp_die( esc_html__( '无权限', 'wp-risk-scanner' ) );
		}
		check_admin_referer( 'wprs_cron_regenerate_secret' );
		self::ensure_secret( true );
		wp_safe_redirect( admin_url( 'admin.php?page=wprs-risk-settings&wprs_settings_updated=1' ) );
		exit;
	}
}
