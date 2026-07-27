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
					'starting'      => __( '正在准备扫描…', 'wp-risk-scanner' ),
					'scanning'      => __( '正在扫描', 'wp-risk-scanner' ),
					'done'          => __( '全站扫描完成', 'wp-risk-scanner' ),
					'fail'          => __( '扫描失败', 'wp-risk-scanner' ),
					'empty'         => __( '没有可扫描的已发布文章（请检查设置中的文章类型）。', 'wp-risk-scanner' ),
					'reload'        => __( '即将刷新列表…', 'wp-risk-scanner' ),
					'confirmRun'    => __( '开始全站重新扫描？', 'wp-risk-scanner' ),
					'showSnippets'  => __( '查看内容片段 ▾', 'wp-risk-scanner' ),
					'hideSnippets'  => __( '收起内容片段 ▴', 'wp-risk-scanner' ),
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
		$status      = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'risk';
		$post_type   = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$search_type = isset( $_GET['search_type'] ) ? sanitize_key( wp_unslash( $_GET['search_type'] ) ) : 'title';
		$paged       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		if ( 'compliant' === $status ) {
			$status = 'clean';
		}
		if ( ! in_array( $status, array( 'risk', 'clean', 'all' ), true ) ) {
			$status = 'risk';
		}
		if ( ! in_array( $search_type, array( 'title', 'risk_term' ), true ) ) {
			$search_type = 'title';
		}

		$per_page = 20;
		$result   = WPRS_Scanner::query_results(
			array(
				'status'      => $status,
				'post_type'   => $post_type,
				's'           => $search,
				'search_type' => $search_type,
				'paged'       => $paged,
				'per_page'    => $per_page,
			)
		);
		$items  = $result['items'];
		$total  = $result['total'];
		$pages  = $result['pages'];
		$offset = isset( $result['offset'] ) ? (int) $result['offset'] : ( ( $paged - 1 ) * $per_page );
		$types  = WPRS_Scanner::eligible_post_types();

		$empty_text = __( '暂无扫描记录。', 'wp-risk-scanner' );
		if ( 'risk' === $status ) {
			$empty_text = __( '暂无违规记录。', 'wp-risk-scanner' );
		} elseif ( 'clean' === $status ) {
			$empty_text = __( '暂无合规记录。', 'wp-risk-scanner' );
		}

		$status_label_map = array(
			'publish' => __( '已发布', 'wp-risk-scanner' ),
			'draft'   => __( '草稿', 'wp-risk-scanner' ),
			'pending' => __( '待审', 'wp-risk-scanner' ),
		);
		?>
		<form id="wprs-scan-list-filter" method="get">
			<input type="hidden" name="page" value="wprs-risk-scanner" />
			<input type="hidden" name="tab" value="results" />

			<div class="tablenav top wprs-results-tablenav">
				<div class="alignleft actions">
					<select name="post_type">
						<option value=""><?php esc_html_e( '-- 所有类型 --', 'wp-risk-scanner' ); ?></option>
						<?php foreach ( $types as $pt ) : ?>
							<option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $post_type, $pt ); ?>><?php echo esc_html( $pt ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="status">
						<option value="risk" <?php selected( $status, 'risk' ); ?>><?php esc_html_e( '违规文章', 'wp-risk-scanner' ); ?></option>
						<option value="clean" <?php selected( $status, 'clean' ); ?>><?php esc_html_e( '合规文章', 'wp-risk-scanner' ); ?></option>
						<option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( '所有文章', 'wp-risk-scanner' ); ?></option>
					</select>
					<input type="submit" class="button" value="<?php esc_attr_e( '筛选', 'wp-risk-scanner' ); ?>" />
				</div>
				<div class="search-box wprs-results-search">
					<select name="search_type">
						<option value="title" <?php selected( $search_type, 'title' ); ?>><?php esc_html_e( '搜文章名', 'wp-risk-scanner' ); ?></option>
						<option value="risk_term" <?php selected( $search_type, 'risk_term' ); ?>><?php esc_html_e( '搜包含违禁词', 'wp-risk-scanner' ); ?></option>
					</select>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( '输入关键词…', 'wp-risk-scanner' ); ?>" />
					<input type="submit" class="button" value="<?php esc_attr_e( '搜索', 'wp-risk-scanner' ); ?>" />
				</div>
			</div>
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

		<table class="wp-list-table widefat fixed striped wprs-results-table">
			<thead>
				<tr>
					<th style="width:50px;"><?php esc_html_e( '序号', 'wp-risk-scanner' ); ?></th>
					<th style="width:15%;"><?php esc_html_e( '文章名称', 'wp-risk-scanner' ); ?></th>
					<th style="width:10%;"><?php esc_html_e( '文章作者', 'wp-risk-scanner' ); ?></th>
					<th style="width:12%;"><?php esc_html_e( '违禁词白名单', 'wp-risk-scanner' ); ?></th>
					<th style="width:12%;"><?php esc_html_e( '包含的违禁词', 'wp-risk-scanner' ); ?></th>
					<th style="width:25%;">
						<?php esc_html_e( '违规内容片段', 'wp-risk-scanner' ); ?>
						<br />
						<span class="description"><?php esc_html_e( '每个违禁词只抓取第一次出现的上下文片段', 'wp-risk-scanner' ); ?></span>
					</th>
					<th><?php esc_html_e( '最新修改时间', 'wp-risk-scanner' ); ?></th>
					<th><?php esc_html_e( '扫描时间', 'wp-risk-scanner' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( '操作', 'wp-risk-scanner' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $items ) : ?>
					<tr><td colspan="9"><?php echo esc_html( $empty_text ); ?></td></tr>
				<?php else : ?>
					<?php
					$index = $offset + 1;
					foreach ( $items as $row ) :
						$post_id = (int) $row->post_id;
						$found   = json_decode( (string) $row->risk_terms_found, true );
						$by_src  = json_decode( (string) $row->risk_terms_by_source, true );
						$snips   = json_decode( (string) $row->risk_snippets, true );
						if ( ! is_array( $found ) ) {
							$found = array();
						}
						if ( ! is_array( $by_src ) ) {
							$by_src = array();
						}
						if ( ! is_array( $snips ) ) {
							$snips = array();
						}

						$author_id   = (int) get_post_field( 'post_author', $post_id );
						$author_name = $author_id ? (string) get_the_author_meta( 'display_name', $author_id ) : '';
						if ( '' === $author_name ) {
							$author_name = __( '未知', 'wp-risk-scanner' );
						}

						$post_status = (string) get_post_status( $post_id );
						if ( '' === $post_status ) {
							$post_status = (string) get_post_field( 'post_status', $post_id );
						}
						$status_label = isset( $status_label_map[ $post_status ] ) ? $status_label_map[ $post_status ] : ( $post_status ? $post_status : '-' );

						$view_url = get_permalink( $post_id );
						$edit_url = get_edit_post_link( $post_id );
						$whitelist = self::collect_row_whitelist( $post_id, $row );
						?>
						<tr id="wprs-result-row-<?php echo (int) $row->id; ?>">
							<td><?php echo (int) $index++; ?></td>
							<td>
								<?php if ( $view_url ) : ?>
									<strong><a href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $row->post_title ); ?></a></strong>
								<?php else : ?>
									<strong><?php echo esc_html( (string) $row->post_title ); ?></strong>
								<?php endif; ?>
								<br />
								<span class="wprs-row-meta">
									Type: <?php echo esc_html( (string) $row->post_type ); ?>
									/ Status: <?php echo esc_html( $status_label ); ?>
									/ Resource: <?php esc_html_e( '文章扫描', 'wp-risk-scanner' ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $author_name ); ?></td>
							<td class="wprs-whitelist-cell">
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已 esc_html/esc_attr。
								echo self::format_whitelist_cell( $whitelist, $post_id );
								?>
							</td>
							<td class="wprs-terms-cell">
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已 esc_html/esc_attr。
								echo self::format_terms_by_source( $by_src, $found, $post_id );
								?>
							</td>
							<td class="wprs-snip-cell">
								<?php if ( $found && $snips ) : ?>
									<a href="#" class="wprs-snip-toggle"><?php esc_html_e( '查看内容片段 ▾', 'wp-risk-scanner' ); ?></a>
									<div class="wprs-snippets-box" hidden>
										<?php foreach ( $found as $term ) : ?>
											<?php if ( empty( $snips[ $term ] ) ) { continue; } ?>
											<div class="wprs-snip">
												<strong><?php echo esc_html( (string) $term ); ?>：</strong>
												<div class="wprs-snip__content">
													<?php
													echo wp_kses(
														$snips[ $term ],
														array(
															'mark' => array( 'class' => true, 'style' => true ),
															'br'   => array(),
															'strong' => array(),
														)
													);
													?>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								<?php else : ?>
									<span class="wprs-empty">- <?php esc_html_e( '无', 'wp-risk-scanner' ); ?> -</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $row->post_update_time ); ?></td>
							<td><?php echo esc_html( (string) $row->scan_time ); ?></td>
							<td>
								<?php if ( $edit_url ) : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small"><?php esc_html_e( '去修改', 'wp-risk-scanner' ); ?></a>
								<?php else : ?>
									<span class="wprs-empty">-</span>
								<?php endif; ?>
							</td>
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
										'page'        => 'wprs-risk-scanner',
										'tab'         => 'results',
										'status'      => $status,
										'post_type'   => $post_type,
										'search_type' => $search_type,
										's'           => $search,
										'paged'       => '%#%',
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
	 * 汇总本行白名单（原生 meta + Elementor + 扫描表 ignored_terms）。
	 *
	 * @param int    $post_id 文章 ID。
	 * @param object $row     扫描结果行。
	 * @return string[]
	 */
	private static function collect_row_whitelist( $post_id, $row ) {
		$raw = array();

		$meta = get_post_meta( $post_id, WPRS_Scanner::META_WHITELIST, true );
		if ( ! empty( $meta ) ) {
			$raw = array_merge( $raw, explode( '、', (string) $meta ) );
		}

		$el = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( is_array( $el ) && ! empty( $el[ WPRS_Scanner::META_WHITELIST ] ) ) {
			$raw = array_merge( $raw, explode( '、', (string) $el[ WPRS_Scanner::META_WHITELIST ] ) );
		}

		$history = json_decode( (string) $row->ignored_terms, true );
		if ( is_array( $history ) ) {
			$raw = array_merge( $raw, $history );
		}

		$out = array();
		foreach ( $raw as $w ) {
			$w = trim( (string) $w );
			if ( '' !== $w ) {
				$out[] = $w;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * 白名单列 HTML。
	 *
	 * @param string[] $terms   白名单词。
	 * @param int      $post_id 文章 ID。
	 * @return string
	 */
	private static function format_whitelist_cell( $terms, $post_id ) {
		if ( ! $terms ) {
			return '<span class="wprs-empty">- ' . esc_html__( '无', 'wp-risk-scanner' ) . ' -</span>';
		}
		return self::term_rows( $terms, $post_id, 'unignore' );
	}

	/**
	 * 按来源格式化违禁词，并附忽略按钮。
	 *
	 * @param array<string, mixed> $by_src  来源分组。
	 * @param string[]             $found   命中词。
	 * @param int                  $post_id 文章 ID。
	 * @return string
	 */
	private static function format_terms_by_source( $by_src, $found, $post_id ) {
		if ( ! $found ) {
			return '<span class="wprs-empty">- ' . esc_html__( '无', 'wp-risk-scanner' ) . ' -</span>';
		}

		$split_ok = is_array( $by_src ) && array_key_exists( 'article', $by_src ) && array_key_exists( 'yoast', $by_src );
		$html     = '';

		if ( $split_ok ) {
			$article_ts = array();
			foreach ( (array) $by_src['article'] as $t ) {
				if ( in_array( $t, $found, true ) ) {
					$article_ts[] = $t;
				}
			}
			$article_ts = array_values( array_unique( $article_ts ) );

			$yoast_ts = array();
			foreach ( (array) $by_src['yoast'] as $t ) {
				if ( in_array( $t, $found, true ) ) {
					$yoast_ts[] = $t;
				}
			}
			$yoast_ts = array_values( array_unique( $yoast_ts ) );
			$orphan   = array_values( array_diff( $found, array_unique( array_merge( $article_ts, $yoast_ts ) ) ) );

			if ( $article_ts ) {
				$html .= '<div class="wprs-src-group"><span class="wprs-src-label">' . esc_html__( '【文章内容】', 'wp-risk-scanner' ) . '</span>';
				$html .= self::term_rows( $article_ts, $post_id, 'ignore' );
				$html .= '</div>';
			}
			if ( $yoast_ts ) {
				$html .= '<div class="wprs-src-group"><span class="wprs-src-label">' . esc_html__( '【Yoast内容】', 'wp-risk-scanner' ) . '</span>';
				$html .= self::term_rows( $yoast_ts, $post_id, 'ignore' );
				$html .= '</div>';
			}
			if ( $orphan ) {
				$html .= '<div class="wprs-src-group"><span class="wprs-src-label">' . esc_html__( '【未分类】', 'wp-risk-scanner' ) . '</span>';
				$html .= self::term_rows( $orphan, $post_id, 'ignore' );
				$html .= '</div>';
			}
		} else {
			$html .= self::term_rows( $found, $post_id, 'ignore' );
			$html .= '<p class="wprs-src-hint">' . esc_html__( '提示：未记录来源细分时，将违禁词列在同一组；执行一次全站重新扫描后可区分【文章内容】与【Yoast内容】。', 'wp-risk-scanner' ) . '</p>';
		}

		return $html ? $html : '<span class="wprs-empty">- ' . esc_html__( '无', 'wp-risk-scanner' ) . ' -</span>';
	}

	/**
	 * 词行 HTML（徽章 + 操作链接，对齐主题列表样式）。
	 *
	 * @param string[] $terms   词列表。
	 * @param int      $post_id 文章 ID。
	 * @param string   $action  ignore|unignore。
	 * @return string
	 */
	private static function term_rows( $terms, $post_id, $action ) {
		$out = array();
		foreach ( $terms as $term ) {
			$term = (string) $term;
			if ( '' === $term ) {
				continue;
			}
			$badge_class = ( 'unignore' === $action ) ? 'wprs-term-badge wprs-term-badge--ignored' : 'wprs-term-badge wprs-term-badge--risk';
			$label       = ( 'unignore' === $action )
				? __( '取消忽略', 'wp-risk-scanner' )
				: __( '忽略/不处理', 'wp-risk-scanner' );
			$link_class  = ( 'unignore' === $action ) ? 'wprs-term-action wprs-term-action--unignore' : 'wprs-term-action wprs-term-action--ignore';

			$out[] = sprintf(
				'<div class="wprs-term-row"><span class="%1$s">%2$s</span> <button type="button" class="button-link %3$s" data-action="%4$s" data-post="%5$d" data-term="%6$s">%7$s</button></div>',
				esc_attr( $badge_class ),
				esc_html( $term ),
				esc_attr( $link_class ),
				esc_attr( $action ),
				(int) $post_id,
				esc_attr( $term ),
				esc_html( $label )
			);
		}
		return implode( '', $out );
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
