<?php
/**
 * 设置页。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 模块开关、扫描范围、通知与卸载选项。
 */
class WPRS_Settings_Page {

	const PAGE_SLUG = 'wprs-risk-settings';

	/**
	 * 注册保存处理。
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * 设置页脚本（测试通知）。
	 *
	 * @param string $hook 钩子。
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_script(
			'wprs-settings',
			WPRS_URL . 'assets/js/settings.js',
			array(),
			WPRS_VERSION,
			true
		);
		wp_localize_script(
			'wprs-settings',
			'WPRS_SETTINGS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wprs_scan_ajax' ),
			)
		);
	}

	/**
	 * 处理设置保存。
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! isset( $_POST['wprs_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( WPRS_Settings::cap_manage() ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wprs_settings_nonce'] ) ), 'wprs_save_settings' ) ) {
			return;
		}
		if ( ! isset( $_POST['wprs_settings'] ) || ! is_array( $_POST['wprs_settings'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 经 Settings::update 清洗。
		$input = wp_unslash( $_POST['wprs_settings'] );
		WPRS_Settings::update( is_array( $input ) ? $input : array() );
		WPRS_Cron::ensure_secret( false );
		WPRS_Cron::write_install_script();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::PAGE_SLUG,
					'wprs_settings_updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * 渲染设置页。
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( WPRS_Settings::cap_manage() ) ) {
			wp_die( esc_html__( '您没有权限访问此页面。', 'wp-risk-scanner' ) );
		}

		$settings   = WPRS_Settings::get();
		$can_sensitive = current_user_can( WPRS_Settings::cap_sensitive() );
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		if ( ! empty( $_GET['wprs_settings_updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '设置已保存。', 'wp-risk-scanner' ) . '</p></div>';
		}
		?>
		<div class="wrap wprs-wrap">
			<h1><?php esc_html_e( '风险词设置', 'wp-risk-scanner' ); ?></h1>
			<p class="description">
				<?php esc_html_e( '各策略默认关闭：启用插件后不会拦截保存、不会发通知、不会扫描归档页。', 'wp-risk-scanner' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'wprs_save_settings', 'wprs_settings_nonce' ); ?>

				<h2><?php esc_html_e( '扫描与拦截', 'wp-risk-scanner' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '启用保存拦截', 'wp-risk-scanner' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wprs_settings[enable_save_intercept]" value="1" <?php checked( ! empty( $settings['enable_save_intercept'] ) ); ?> />
								<?php esc_html_e( '拦截 Gutenberg / Elementor 保存中的风险词（含 Yoast 软依赖）', 'wp-risk-scanner' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '启用归档页扫描', 'wp-risk-scanner' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wprs_settings[enable_archive_scan]" value="1" <?php checked( ! empty( $settings['enable_archive_scan'] ) ); ?> />
								<?php esc_html_e( '扫描已配置 taxonomy 的归档页（实现阶段完善配置项）', 'wp-risk-scanner' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '扫描文章类型', 'wp-risk-scanner' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $post_types as $pt ) : ?>
									<label style="display:inline-block;margin:0 1rem 0.5rem 0;">
										<input
											type="checkbox"
											name="wprs_settings[scan_post_types][]"
											value="<?php echo esc_attr( $pt->name ); ?>"
											<?php checked( in_array( $pt->name, $settings['scan_post_types'], true ) ); ?>
										/>
										<?php echo esc_html( $pt->labels->singular_name . ' (' . $pt->name . ')' ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( '默认文章与页面；全站扫 / 定时 / 保存拦截共用此范围。', 'wp-risk-scanner' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '定时模式', 'wp-risk-scanner' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:0.35rem;">
								<input type="radio" name="wprs_settings[cron_mode]" value="wp_cron" <?php checked( $settings['cron_mode'], 'wp_cron' ); ?> />
								<?php esc_html_e( 'WP-Cron（默认，一般无需上服务器）', 'wp-risk-scanner' ); ?>
							</label>
							<label style="display:block;">
								<input type="radio" name="wprs_settings[cron_mode]" value="system" <?php checked( $settings['cron_mode'], 'system' ); ?> />
								<?php esc_html_e( '系统 / 外部 Cron（URL 触发；改时间后可能需重装服务器任务）', 'wp-risk-scanner' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wprs-cron-times"><?php esc_html_e( '每日扫描时间', 'wp-risk-scanner' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="3" id="wprs-cron-times" name="wprs_settings[cron_times_text]" placeholder="09:00&#10;18:30"><?php echo esc_textarea( implode( "\n", $settings['cron_times'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( '每行一个 HH:MM。WP-Cron 模式约每 5 分钟检查一次；站点无访问时可能不准点。', 'wp-risk-scanner' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wprs-cron-tz"><?php esc_html_e( '时区', 'wp-risk-scanner' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="wprs-cron-tz" name="wprs_settings[cron_timezone]" value="<?php echo esc_attr( (string) $settings['cron_timezone'] ); ?>" placeholder="<?php echo esc_attr( wp_timezone_string() ); ?>" />
							<p class="description"><?php esc_html_e( '留空则使用站点时区。', 'wp-risk-scanner' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '系统 Cron 触发 URL', 'wp-risk-scanner' ); ?></th>
						<td>
							<?php
							$trigger = WPRS_Cron::trigger_url();
							if ( '' === $trigger ) {
								WPRS_Cron::ensure_secret( false );
								$trigger = WPRS_Cron::trigger_url();
							}
							$last_ok = (int) get_option( WPRS_Cron::OPTION_LAST_SUCCESS, 0 );
							?>
							<code style="display:block;word-break:break-all;margin-bottom:0.5rem;"><?php echo esc_html( $trigger ); ?></code>
							<p class="description">
								<?php esc_html_e( '供 crontab / 宝塔 / 外部定时服务 curl 调用。改时间或密钥后，若使用系统 crontab，请重新安装脚本。', 'wp-risk-scanner' ); ?>
							</p>
							<p>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wprs_cron_download_sh' ), 'wprs_cron_download_sh' ) ); ?>">
									<?php esc_html_e( '下载安装脚本', 'wp-risk-scanner' ); ?>
								</a>
								<?php if ( $can_sensitive ) : ?>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wprs_cron_regenerate_secret' ), 'wprs_cron_regenerate_secret' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( '重新生成密钥后，旧 URL 将失效，需重装服务器任务。继续？', 'wp-risk-scanner' ) ); ?>');">
										<?php esc_html_e( '重新生成密钥', 'wp-risk-scanner' ); ?>
									</a>
								<?php endif; ?>
							</p>
							<p class="description">
								<?php
								if ( $last_ok > 0 ) {
									printf(
										/* translators: %s: datetime */
										esc_html__( '上次定时成功：%s', 'wp-risk-scanner' ),
										esc_html( wp_date( 'Y-m-d H:i:s', $last_ok ) )
									);
								} else {
									esc_html_e( '尚未记录到定时成功执行。', 'wp-risk-scanner' );
								}
								?>
							</p>
							<p>
								<label for="wprs-cron-host"><?php esc_html_e( '可选 Host 头', 'wp-risk-scanner' ); ?></label><br />
								<input type="text" class="regular-text" id="wprs-cron-host" name="wprs_settings[cron_host_header]" value="<?php echo esc_attr( (string) $settings['cron_host_header'] ); ?>" />
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '通知', 'wp-risk-scanner' ); ?></h2>
				<?php if ( ! $can_sensitive ) : ?>
					<p class="description"><?php esc_html_e( '通知通道与密钥仅管理员可修改。', 'wp-risk-scanner' ); ?></p>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '启用通知', 'wp-risk-scanner' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wprs_settings[enable_notification]" value="1" <?php checked( ! empty( $settings['enable_notification'] ) ); ?> <?php disabled( ! $can_sensitive ); ?> />
								<?php esc_html_e( '扫描发现违规时发送通知', 'wp-risk-scanner' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '通道', 'wp-risk-scanner' ); ?></th>
						<td>
							<label style="margin-right:1rem;">
								<input type="radio" name="wprs_settings[notification_channel]" value="webhook" <?php checked( $settings['notification_channel'], 'webhook' ); ?> <?php disabled( ! $can_sensitive ); ?> />
								<?php esc_html_e( '通用 Webhook', 'wp-risk-scanner' ); ?>
							</label>
							<label>
								<input type="radio" name="wprs_settings[notification_channel]" value="feishu" <?php checked( $settings['notification_channel'], 'feishu' ); ?> <?php disabled( ! $can_sensitive ); ?> />
								<?php esc_html_e( '飞书预设', 'wp-risk-scanner' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wprs-webhook-url"><?php esc_html_e( 'Webhook URL', 'wp-risk-scanner' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="wprs-webhook-url" name="wprs_settings[webhook_url]" value="<?php echo esc_attr( (string) $settings['webhook_url'] ); ?>" <?php disabled( ! $can_sensitive ); ?> />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wprs-feishu-webhook"><?php esc_html_e( '飞书 Webhook', 'wp-risk-scanner' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="wprs-feishu-webhook" name="wprs_settings[feishu_webhook]" value="<?php echo esc_attr( (string) $settings['feishu_webhook'] ); ?>" <?php disabled( ! $can_sensitive ); ?> />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wprs-feishu-secret"><?php esc_html_e( '飞书签名密钥', 'wp-risk-scanner' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="wprs-feishu-secret" name="wprs_settings[feishu_secret]" value="" <?php disabled( ! $can_sensitive ); ?> autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $settings['feishu_secret'] ) ? __( '已配置，不修改请留空', 'wp-risk-scanner' ) : __( '选填', 'wp-risk-scanner' ) ); ?>" />
							<p class="description"><?php esc_html_e( '可选；与飞书机器人「签名校验」一致。留空保存不改动已有密钥。', 'wp-risk-scanner' ); ?></p>
						</td>
					</tr>
					<?php if ( $can_sensitive ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( '测试通知', 'wp-risk-scanner' ); ?></th>
							<td>
								<button type="button" class="button" id="wprs-notify-test"><?php esc_html_e( '发送测试通知', 'wp-risk-scanner' ); ?></button>
								<span id="wprs-notify-test-msg" class="description" style="margin-left:8px;"></span>
							</td>
						</tr>
					<?php endif; ?>
				</table>

				<?php if ( $can_sensitive ) : ?>
					<h2><?php esc_html_e( '卸载', 'wp-risk-scanner' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( '卸载时删除数据', 'wp-risk-scanner' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="wprs_settings[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> />
									<?php esc_html_e( '卸载插件时删除设置与自定义数据表（默认保留）', 'wp-risk-scanner' ); ?>
								</label>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<?php submit_button( __( '保存设置', 'wp-risk-scanner' ) ); ?>
			</form>
		</div>
		<?php
	}
}
