<?php
/**
 * 插件卸载清理。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/includes/class-settings.php';
require_once dirname( __FILE__ ) . '/includes/class-activator.php';

/**
 * 清理当前站点数据（仅在设置允许时）。
 *
 * @return void
 */
function wprs_uninstall_site_data() {
	$settings = get_option( WPRS_Settings::OPTION, array() );
	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	WPRS_Activator::drop_tables();
	delete_option( WPRS_Settings::OPTION );
	delete_option( 'wprs_db_version' );
	delete_option( 'wprs_needs_global_rescan' );
	delete_option( 'wprs_terms_changed_at' );
	delete_option( 'wprs_last_global_scan_time' );
	delete_option( 'wprs_last_full_scan_terms_changed_at' );
	delete_option( 'wprs_last_cron_success_at' );
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		wprs_uninstall_site_data();
		restore_current_blog();
	}
} else {
	wprs_uninstall_site_data();
}
