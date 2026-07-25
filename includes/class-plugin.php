<?php
/**
 * 插件主控制器。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 组装并启动各模块。
 */
class WPRS_Plugin {

	/**
	 * 注册插件功能。
	 *
	 * @return void
	 */
	public function run() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( 'WPRS_Activator', 'maybe_upgrade' ) );

		$admin        = new WPRS_Admin();
		$scanner      = new WPRS_Scanner();
		$interceptor  = new WPRS_Interceptor();
		$notifier     = new WPRS_Notifier();
		$cron         = new WPRS_Cron();

		$admin->register();
		$scanner->register();
		$interceptor->register();
		$notifier->register();
		$cron->register();
	}

	/**
	 * 加载翻译。
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-risk-scanner', false, dirname( plugin_basename( WPRS_FILE ) ) . '/languages' );
	}
}
