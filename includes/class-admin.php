<?php
/**
 * 后台菜单与公共资源。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 注册顶级菜单与三个子页面。
 */
class WPRS_Admin {

	const MENU_SLUG = 'wprs-risk-scanner';

	/**
	 * 注册钩子。
	 *
	 * @return void
	 */
	public function register() {
		$scan     = new WPRS_Scan_Page();
		$terms    = new WPRS_Terms_Page();
		$settings = new WPRS_Settings_Page();

		add_action( 'admin_menu', array( $this, 'add_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		$scan->register();
		$terms->register();
		$settings->register();
	}

	/**
	 * 添加菜单。
	 *
	 * @return void
	 */
	public function add_menus() {
		$cap = WPRS_Settings::cap_manage();

		add_menu_page(
			__( '风险词管理', 'wp-risk-scanner' ),
			__( '风险词管理', 'wp-risk-scanner' ),
			$cap,
			self::MENU_SLUG,
			array( 'WPRS_Scan_Page', 'render' ),
			'dashicons-shield',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( '风险扫描', 'wp-risk-scanner' ),
			__( '风险扫描', 'wp-risk-scanner' ),
			$cap,
			self::MENU_SLUG,
			array( 'WPRS_Scan_Page', 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( '违禁词表', 'wp-risk-scanner' ),
			__( '违禁词表', 'wp-risk-scanner' ),
			$cap,
			'wprs-risk-terms',
			array( 'WPRS_Terms_Page', 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( '设置', 'wp-risk-scanner' ),
			__( '设置', 'wp-risk-scanner' ),
			$cap,
			'wprs-risk-settings',
			array( 'WPRS_Settings_Page', 'render' )
		);
	}

	/**
	 * 加载后台样式。
	 *
	 * @param string $hook 当前页面钩子。
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'wprs-risk' ) && false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'wprs-admin',
			WPRS_URL . 'assets/css/admin.css',
			array(),
			WPRS_VERSION
		);
	}
}
