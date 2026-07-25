<?php
/**
 * 风险扫描后台页。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 风险列表、全站扫描进度、扫描日志。
 */
class WPRS_Scan_Page {

	/**
	 * 注册资源钩子。
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * 加载扫描页脚本。
	 *
	 * @param string $hook 页面钩子。
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'wprs-risk-scanner' ) && 'toplevel_page_wprs-risk-scanner' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'wprs-scan',
			WPRS_URL . 'assets/js/scan.js',
			array(),
			WPRS_VERSION,
			true
		);
		wp_localize_script(
			'wprs-scan',
			'WPRS_SCAN',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'wprs_scan_ajax' ),
				'batchSize' => 10,
				'i18n'      => array(
					'starting'   => __( '正在准备扫描…', 'wp-risk-scanner' ),
					'scanning'   => __( '正在扫描', 'wp-risk-scanner' ),
					'done'       => __( '全站扫描完成', 'wp-risk-scanner' ),
					'fail'       => __( '扫描失败', 'wp-risk-scanner' ),
					'empty'      => __( '没有可扫描的已发布文章（请检查设置中的文章类型）。', 'wp-risk-scanner' ),
					'reload'     => __( '即将刷新列表…', 'wp-risk-scanner' ),
					'confirmRun' => __( '开始全站重新扫描？', 'wp-risk-scanner' ),
				),
			)
		);
	}

	/**
	 * 渲染页面。
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( WPRS_Settings::cap_manage() ) ) {
			wp_die( esc_html__( '您没有权限访问此页面。', 'wp-risk-scanner' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'results';
		if ( ! in_array( $tab, array( 'results', 'logs' ), true ) ) {
			$tab = 'results';
		}

		$notice = get_transient( 'wprs_scan_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'wprs_scan_notice_' . get_current_user_id() );
		}

		$settings   = WPRS_Settings::get();
		$clear_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=wprs_clear_scan_results' ),
			'wprs_clear_scan_results',
			'nonce'
		);
		$base_url   = admin_url( 'admin.php?page=wprs-risk-scanner' );
		?>
		<div class="wrap wprs-wrap">
			<h1><?php esc_html_e( '风险扫描', 'wp-risk-scanner' ); ?></h1>

			<?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> is-dismissible">
					<p><?php echo esc_html( (string) $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( WPRS_Terms::needs_global_rescan() ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( '违禁词表已变更，建议执行一次全站扫描。', 'wp-risk-scanner' ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php
				printf(
					/* translators: %s: post types */
					esc_html__( '当前扫描文章类型：%s（仅已发布）。可在「设置」中修改。', 'wp-risk-scanner' ),
					esc_html( implode( ', ', $settings['scan_post_types'] ) )
				);
				?>
			</p>

			<div class="wprs-scan-toolbar">
				<button type="button" class="button button-primary" id="wprs-scan-run"><?php esc_html_e( '全站重新扫描', 'wp-risk-scanner' ); ?></button>
				<?php if ( current_user_can( WPRS_Settings::cap_sensitive() ) ) : ?>
					<a class="button" href="<?php echo esc_url( $clear_url ); ?>" onclick="return confirm('<?php echo esc_js( __( '确定清空全部扫描结果吗？', 'wp-risk-scanner' ) ); ?>');">
						<?php esc_html_e( '清空结果', 'wp-risk-scanner' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<div id="wprs-scan-progress" class="wprs-scan-progress" hidden>
				<div class="wprs-scan-progress__bar"><span id="wprs-scan-bar"></span></div>
				<p id="wprs-scan-status" class="description"></p>
			</div>

			<nav class="nav-tab-wrapper wprs-tabs">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'results', $base_url ) ); ?>" class="nav-tab <?php echo 'results' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( '风险列表', 'wp-risk-scanner' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'logs', $base_url ) ); ?>" class="nav-tab <?php echo 'logs' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( '扫描日志', 'wp-risk-scanner' ); ?>
				</a>
			</nav>

			<?php
			if ( 'logs' === $tab ) {
				self::render_logs();
			} else {
				self::render_results();
			}
			?>
		</div>
		<?php
	}

	/**
	 * 渲染风险列表。
	 *
	 * @return void
	 */
	private static function render_results() {
		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'risk';
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$result = WPRS_Scanner::query_results(
			array(
				'status'    => $status,
				'post_type' => $post_type,
				's'         => $search,
				'paged'     => $paged,
				'per_page'  => 20,
			)
		);
		$items = $result['items'];
		$total = $result['total'];
		$pages = $result['pages'];
		$types = WPRS_Scanner::eligible_post_types();
		?>
		<form method="get" class="wprs-filters">
			<input type="hidden" name="page" value="wprs-risk-scanner" />
			<input type="hidden" name="tab" value="results" />
			<select name="status">
				<option value="risk" <?php selected( $status, 'risk' ); ?>><?php esc_html_e( '有风险', 'wp-risk-scanner' ); ?></option>
				<option value="clean" <?php selected( $status, 'clean' ); ?>><?php esc_html_e( '无风险', 'wp-risk-scanner' ); ?></option>
				<option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( '全部已扫', 'wp-risk-scanner' ); ?></option>
			</select>
			<select name="post_type">
				<option value=""><?php esc_html_e( '全部类型', 'wp-risk-scanner' ); ?></option>
				<?php foreach ( $types as $pt ) : ?>
					<option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $post_type, $pt ); ?>><?php echo esc_html( $pt ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( '搜索标题…', 'wp-risk-scanner' ); ?>" />
			<?php submit_button( __( '筛选', 'wp-risk-scanner' ), 'secondary', '', false ); ?>
		</form>

		<p class="description">
			<?php
			printf(
				/* translators: %d: total */
				esc_html__( '共 %d 条', 'wp-risk-scanner' ),
				(int) $total
			);
			?>
		</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( '标题', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '类型', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '违禁词（按来源）', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '片段', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '忽略词', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '扫描时间', 'wp-risk-scanner' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $items ) : ?>
					<tr><td colspan="6"><?php esc_html_e( '暂无结果。请先维护违禁词表并执行全站扫描。', 'wp-risk-scanner' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $items as $row ) : ?>
						<?php
						$found   = json_decode( (string) $row->risk_terms_found, true );
						$by_src  = json_decode( (string) $row->risk_terms_by_source, true );
						$snips   = json_decode( (string) $row->risk_snippets, true );
						$ignored = json_decode( (string) $row->ignored_terms, true );
						if ( ! is_array( $found ) ) {
							$found = array();
						}
						if ( ! is_array( $by_src ) ) {
							$by_src = array();
						}
						if ( ! is_array( $snips ) ) {
							$snips = array();
						}
						if ( ! is_array( $ignored ) ) {
							$ignored = array();
						}
						$edit = get_edit_post_link( (int) $row->post_id );
						?>
						<tr>
							<td>
								<?php if ( $edit ) : ?>
									<a href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( (string) $row->post_title ); ?></strong></a>
								<?php else : ?>
									<strong><?php echo esc_html( (string) $row->post_title ); ?></strong>
								<?php endif; ?>
								<div class="row-actions">#<?php echo (int) $row->post_id; ?></div>
							</td>
							<td><code><?php echo esc_html( (string) $row->post_type ); ?></code></td>
							<td class="wprs-terms-cell">
								<?php echo wp_kses_post( self::format_terms_by_source( $by_src, $found, (int) $row->post_id ) ); ?>
							</td>
							<td class="wprs-snip-cell">
								<?php
								foreach ( $found as $term ) {
									if ( empty( $snips[ $term ] ) ) {
										continue;
									}
									echo '<div class="wprs-snip"><strong>' . esc_html( $term ) . '</strong><br />';
									echo wp_kses(
										$snips[ $term ],
										array(
											'mark' => array( 'class' => true, 'style' => true ),
											'br'   => array(),
										)
									);
									echo '</div>';
								}
								?>
							</td>
							<td class="wprs-ignored-cell">
								<?php echo wp_kses_post( self::format_ignored( $ignored, (int) $row->post_id ) ); ?>
							</td>
							<td><?php echo esc_html( (string) $row->scan_time ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg(
									array(
										'page'      => 'wprs-risk-scanner',
										'tab'       => 'results',
										'status'    => $status,
										'post_type' => $post_type,
										's'         => $search,
										'paged'     => '%#%',
									),
									admin_url( 'admin.php' )
								),
								'format'  => '',
								'current' => $paged,
								'total'   => $pages,
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * 按来源格式化违禁词，并附忽略按钮。
	 *
	 * @param array<string, mixed> $by_src 来源分组。
	 * @param string[]             $found  命中词。
	 * @param int                  $post_id 文章 ID。
	 * @return string
	 */
	private static function format_terms_by_source( $by_src, $found, $post_id ) {
		$article = isset( $by_src['article'] ) && is_array( $by_src['article'] ) ? $by_src['article'] : array();
		$yoast   = isset( $by_src['yoast'] ) && is_array( $by_src['yoast'] ) ? $by_src['yoast'] : array();
		if ( ! $article && ! $yoast && $found ) {
			$article = $found;
		}
		$html = '';
		if ( $article ) {
			$html .= '<div><strong>' . esc_html__( '【文章内容】', 'wp-risk-scanner' ) . '</strong> ';
			$html .= self::term_chips( $article, $post_id, 'ignore' );
			$html .= '</div>';
		}
		if ( $yoast ) {
			$html .= '<div><strong>' . esc_html__( '【Yoast内容】', 'wp-risk-scanner' ) . '</strong> ';
			$html .= self::term_chips( $yoast, $post_id, 'ignore' );
			$html .= '</div>';
		}
		if ( ! $html ) {
			$html = '<span class="description">' . esc_html__( '无', 'wp-risk-scanner' ) . '</span>';
		}
		return $html;
	}

	/**
	 * 忽略词展示与取消忽略。
	 *
	 * @param string[] $ignored 忽略列表。
	 * @param int      $post_id 文章 ID。
	 * @return string
	 */
	private static function format_ignored( $ignored, $post_id ) {
		if ( ! $ignored ) {
			return '<span class="description">' . esc_html__( '无', 'wp-risk-scanner' ) . '</span>';
		}
		return self::term_chips( $ignored, $post_id, 'unignore' );
	}

	/**
	 * 词芯片 HTML。
	 *
	 * @param string[] $terms   词列表。
	 * @param int      $post_id 文章 ID。
	 * @param string   $action  ignore|unignore。
	 * @return string
	 */
	private static function term_chips( $terms, $post_id, $action ) {
		$out = array();
		foreach ( $terms as $term ) {
			$term = (string) $term;
			if ( '' === $term ) {
				continue;
			}
			$label = ( 'unignore' === $action )
				? __( '取消忽略', 'wp-risk-scanner' )
				: __( '忽略', 'wp-risk-scanner' );
			$out[] = sprintf(
				'<span class="wprs-chip">%1$s <button type="button" class="button-link wprs-term-action" data-action="%2$s" data-post="%3$d" data-term="%4$s">%5$s</button></span>',
				esc_html( $term ),
				esc_attr( $action ),
				(int) $post_id,
				esc_attr( $term ),
				esc_html( $label )
			);
		}
		return implode( ' ', $out );
	}

	/**
	 * 渲染扫描日志。
	 *
	 * @return void
	 */
	private static function render_logs() {
		$logs = WPRS_Scanner::recent_logs( 50 );
		?>
		<p class="description"><?php esc_html_e( '保留近 15 天记录。', 'wp-risk-scanner' ); ?></p>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( '时间', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '类型', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '操作人', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '扫描篇数', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '风险数', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '耗时(ms)', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '失败', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '错误', 'wp-risk-scanner' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $logs ) : ?>
					<tr><td colspan="8"><?php esc_html_e( '暂无日志。', 'wp-risk-scanner' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $log->created_at ); ?></td>
							<td><?php echo esc_html( (string) $log->scan_type_label ); ?></td>
							<td><?php echo esc_html( (string) $log->operator ); ?></td>
							<td><?php echo (int) $log->posts_scanned; ?></td>
							<td><?php echo (int) $log->risk_count; ?></td>
							<td><?php echo (int) $log->execution_time_ms; ?></td>
							<td><?php echo (int) $log->failed_posts; ?></td>
							<td><?php echo esc_html( (string) $log->last_error ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}
}
