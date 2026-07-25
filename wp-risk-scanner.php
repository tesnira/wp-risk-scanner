<?php
/**
 * Plugin Name: WP Risk Scanner
 * Plugin URI: https://wordpress.org/plugins/wp-risk-scanner/
 * Description: 风险词管理：违禁词表、全站/定时扫描、保存拦截与通知（Webhook / 飞书）。
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: biancaplus
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-risk-scanner
 * Domain Path: /languages
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPRS_VERSION', '1.0.0' );
define( 'WPRS_DB_VERSION', '1.0.0' );
define( 'WPRS_FILE', __FILE__ );
define( 'WPRS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPRS_URL', plugin_dir_url( __FILE__ ) );

require_once WPRS_PATH . 'includes/class-settings.php';
require_once WPRS_PATH . 'includes/class-activator.php';
require_once WPRS_PATH . 'includes/class-terms.php';
require_once WPRS_PATH . 'includes/class-scanner.php';
require_once WPRS_PATH . 'includes/class-interceptor.php';
require_once WPRS_PATH . 'includes/class-notifier.php';
require_once WPRS_PATH . 'includes/class-cron.php';
require_once WPRS_PATH . 'includes/admin/class-scan-page.php';
require_once WPRS_PATH . 'includes/admin/class-terms-page.php';
require_once WPRS_PATH . 'includes/admin/class-settings-page.php';
require_once WPRS_PATH . 'includes/class-admin.php';
require_once WPRS_PATH . 'includes/class-plugin.php';

register_activation_hook( WPRS_FILE, array( 'WPRS_Activator', 'activate' ) );

/**
 * 启动插件。
 *
 * @return void
 */
function wprs_run_plugin() {
	$plugin = new WPRS_Plugin();
	$plugin->run();
}

wprs_run_plugin();
