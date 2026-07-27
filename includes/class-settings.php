<?php
/**
 * 插件选项读写。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理风险词设置（安全默认）。
 */
class WPRS_Settings {

	const OPTION = 'wprs_settings';

	/**
	 * 日常操作所需能力。
	 *
	 * @return string
	 */
	public static function cap_manage() {
		return 'edit_others_posts';
	}

	/**
	 * 敏感操作所需能力。
	 *
	 * @return string
	 */
	public static function cap_sensitive() {
		return 'manage_options';
	}

	/**
	 * 默认设置。
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'enable_save_intercept'    => 0,
			'enable_archive_scan'      => 0,
			'scan_post_types'          => array( 'post', 'page' ),
			'archive_taxonomies'       => array(),
			'cron_mode'                => 'wp_cron',
			'cron_times'               => array(),
			'cron_timezone'            => '',
			'cron_secret'              => '',
			'cron_host_header'         => '',
			'enable_notification'      => 0,
			'notification_channel'     => 'webhook',
			'webhook_url'              => '',
			'feishu_webhook'           => '',
			'feishu_secret'            => '',
			'delete_data_on_uninstall' => 0,
		);
	}

	/**
	 * 读取设置。
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings = array_merge( self::defaults(), $stored );
		$settings['scan_post_types']    = self::sanitize_post_types( $settings['scan_post_types'] );
		$settings['archive_taxonomies'] = self::sanitize_taxonomies( $settings['archive_taxonomies'] );
		$settings['cron_times']         = self::sanitize_cron_times( $settings['cron_times'] );
		$settings['cron_mode']          = in_array( $settings['cron_mode'], array( 'wp_cron', 'system' ), true )
			? $settings['cron_mode']
			: 'wp_cron';
		$settings['notification_channel'] = in_array( $settings['notification_channel'], array( 'webhook', 'feishu' ), true )
			? $settings['notification_channel']
			: 'webhook';
		return $settings;
	}

	/**
	 * 保存设置页提交的数据。
	 *
	 * @param array<string, mixed> $input 表单输入。
	 * @return array<string, mixed>
	 */
	public static function update( $input ) {
		$current = self::get();
		$clean   = array(
			'enable_save_intercept'    => empty( $input['enable_save_intercept'] ) ? 0 : 1,
			'enable_archive_scan'      => empty( $input['enable_archive_scan'] ) ? 0 : 1,
			'scan_post_types'          => self::sanitize_post_types(
				isset( $input['scan_post_types'] ) ? $input['scan_post_types'] : array( 'post', 'page' )
			),
			'archive_taxonomies'       => self::sanitize_taxonomies(
				isset( $input['archive_taxonomies'] ) ? $input['archive_taxonomies'] : array()
			),
			'cron_mode'                => ( isset( $input['cron_mode'] ) && 'system' === $input['cron_mode'] )
				? 'system'
				: 'wp_cron',
			'cron_times'               => self::sanitize_cron_times(
				isset( $input['cron_times'] ) ? $input['cron_times'] : ( isset( $current['cron_times'] ) ? $current['cron_times'] : array() )
			),
			'cron_timezone'            => isset( $input['cron_timezone'] )
				? sanitize_text_field( (string) $input['cron_timezone'] )
				: ( isset( $current['cron_timezone'] ) ? (string) $current['cron_timezone'] : '' ),
			'cron_secret'              => isset( $current['cron_secret'] ) ? (string) $current['cron_secret'] : '',
			'cron_host_header'         => isset( $input['cron_host_header'] )
				? sanitize_text_field( (string) $input['cron_host_header'] )
				: '',
			'enable_notification'      => empty( $input['enable_notification'] ) ? 0 : 1,
			'notification_channel'     => ( isset( $input['notification_channel'] ) && 'feishu' === $input['notification_channel'] )
				? 'feishu'
				: 'webhook',
			'webhook_url'              => isset( $input['webhook_url'] )
				? esc_url_raw( trim( (string) $input['webhook_url'] ) )
				: '',
			'feishu_webhook'           => isset( $input['feishu_webhook'] )
				? esc_url_raw( trim( (string) $input['feishu_webhook'] ) )
				: '',
			'feishu_secret'            => isset( $current['feishu_secret'] ) ? (string) $current['feishu_secret'] : '',
			'delete_data_on_uninstall' => empty( $input['delete_data_on_uninstall'] ) ? 0 : 1,
		);

		// 飞书密钥：留空表示不修改。
		if ( isset( $input['feishu_secret'] ) && '' !== trim( (string) $input['feishu_secret'] ) ) {
			$clean['feishu_secret'] = sanitize_text_field( (string) $input['feishu_secret'] );
		}

		// cron_times 支持多行文本。
		if ( isset( $input['cron_times_text'] ) ) {
			$clean['cron_times'] = self::sanitize_cron_times(
				preg_split( '/[\r\n,]+/', (string) $input['cron_times_text'] )
			);
		}

		// 非管理员不得改通知密钥类字段与卸载选项。
		if ( ! current_user_can( self::cap_sensitive() ) ) {
			$clean['webhook_url']              = $current['webhook_url'];
			$clean['feishu_webhook']           = $current['feishu_webhook'];
			$clean['feishu_secret']            = $current['feishu_secret'];
			$clean['enable_notification']      = $current['enable_notification'];
			$clean['notification_channel']     = $current['notification_channel'];
			$clean['delete_data_on_uninstall'] = $current['delete_data_on_uninstall'];
		}

		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * 清洗文章类型 slug 列表。
	 *
	 * @param mixed $types 原始值。
	 * @return string[]
	 */
	public static function sanitize_post_types( $types ) {
		if ( is_string( $types ) ) {
			$types = preg_split( '/[\s,]+/', $types );
		}
		if ( ! is_array( $types ) ) {
			return array( 'post', 'page' );
		}
		$clean = array();
		foreach ( $types as $type ) {
			$type = sanitize_key( (string) $type );
			if ( '' !== $type ) {
				$clean[] = $type;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		return $clean ? $clean : array( 'post', 'page' );
	}

	/**
	 * 清洗字符串列表。
	 *
	 * @param mixed $list 原始值。
	 * @return string[]
	 */
	public static function sanitize_string_list( $list ) {
		if ( is_string( $list ) ) {
			$list = preg_split( '/[\r\n,]+/', $list );
		}
		if ( ! is_array( $list ) ) {
			return array();
		}
		$clean = array();
		foreach ( $list as $item ) {
			$item = sanitize_key( (string) $item );
			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * 清洗 taxonomy slug 列表（仅保留已注册）。
	 *
	 * @param mixed $list 原始值。
	 * @return string[]
	 */
	public static function sanitize_taxonomies( $list ) {
		$list = self::sanitize_string_list( $list );
		$out  = array();
		foreach ( $list as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$out[] = $tax;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * 清洗定时时间点（HH:MM）。
	 *
	 * @param mixed $times 原始值。
	 * @return string[]
	 */
	public static function sanitize_cron_times( $times ) {
		if ( is_string( $times ) ) {
			$times = preg_split( '/[\r\n,]+/', $times );
		}
		if ( ! is_array( $times ) ) {
			return array();
		}
		$clean = array();
		foreach ( $times as $time ) {
			$time = sanitize_text_field( (string) $time );
			if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $m ) ) {
				$clean[] = sprintf( '%02d:%02d', min( 23, (int) $m[1] ), min( 59, (int) $m[2] ) );
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * 是否启用保存拦截。
	 *
	 * @return bool
	 */
	public static function is_intercept_enabled() {
		$s = self::get();
		return ! empty( $s['enable_save_intercept'] );
	}

	/**
	 * 是否启用归档页扫描。
	 *
	 * @return bool
	 */
	public static function is_archive_scan_enabled() {
		$s = self::get();
		return ! empty( $s['enable_archive_scan'] );
	}

	/**
	 * 是否启用通知。
	 *
	 * @return bool
	 */
	public static function is_notification_enabled() {
		$s = self::get();
		return ! empty( $s['enable_notification'] );
	}
}
