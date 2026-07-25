<?php
/**
 * 违禁词表后台页。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 词表列表、增删改、CSV 导入导出。
 */
class WPRS_Terms_Page {

	const PAGE_SLUG = 'wprs-risk-terms';

	/**
	 * 注册钩子。
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_post_wprs_terms_export', array( $this, 'handle_export' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * 加载本页脚本。
	 *
	 * @param string $hook 页面钩子。
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_script(
			'wprs-terms',
			WPRS_URL . 'assets/js/terms.js',
			array(),
			WPRS_VERSION,
			true
		);
		wp_localize_script(
			'wprs-terms',
			'WPRS_TERMS',
			array(
				'selectOne'          => __( '请先选择要删除的项。', 'wp-risk-scanner' ),
				'confirmBatch'       => __( '确定删除选中的违禁词吗？', 'wp-risk-scanner' ),
				'confirmReplace'     => __( '替换模式将清空现有词表后再导入。确定继续吗？', 'wp-risk-scanner' ),
				'needReplaceConfirm' => __( '请勾选「我确认替换全部词表」后再导入。', 'wp-risk-scanner' ),
			)
		);
	}

	/**
	 * 导出 CSV。
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! current_user_can( WPRS_Settings::cap_manage() ) ) {
			wp_die( esc_html__( '您没有权限执行此操作。', 'wp-risk-scanner' ) );
		}
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wprs_terms_export' ) ) {
			wp_die( esc_html__( '安全验证失败。', 'wp-risk-scanner' ) );
		}
		WPRS_Terms::stream_export_csv();
	}

	/**
	 * 处理表单 POST。
	 *
	 * @return void
	 */
	public function handle_post() {
		if ( ! isset( $_POST['wprs_terms_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( WPRS_Settings::cap_manage() ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wprs_terms_nonce'] ) ), 'wprs_terms_action' ) ) {
			$this->redirect_with_notice( 'error', __( '安全验证失败。', 'wp-risk-scanner' ) );
		}

		$action = isset( $_POST['wprs_action'] ) ? sanitize_key( wp_unslash( $_POST['wprs_action'] ) ) : '';

		switch ( $action ) {
			case 'add':
				$result = WPRS_Terms::insert(
					array(
						'risk_term'        => isset( $_POST['risk_term'] ) ? wp_unslash( $_POST['risk_term'] ) : '',
						'recommended_term' => isset( $_POST['recommended_term'] ) ? wp_unslash( $_POST['recommended_term'] ) : '',
						'guidelines'       => isset( $_POST['guidelines'] ) ? wp_unslash( $_POST['guidelines'] ) : '',
					)
				);
				if ( is_wp_error( $result ) ) {
					$this->redirect_with_notice( 'error', $result->get_error_message() );
				}
				$this->redirect_with_notice( 'success', __( '违禁词添加成功。', 'wp-risk-scanner' ) );
				break;

			case 'edit':
				$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
				$result = WPRS_Terms::update(
					$id,
					array(
						'risk_term'        => isset( $_POST['risk_term'] ) ? wp_unslash( $_POST['risk_term'] ) : '',
						'recommended_term' => isset( $_POST['recommended_term'] ) ? wp_unslash( $_POST['recommended_term'] ) : '',
						'guidelines'       => isset( $_POST['guidelines'] ) ? wp_unslash( $_POST['guidelines'] ) : '',
					)
				);
				if ( is_wp_error( $result ) ) {
					$this->redirect_with_notice( 'error', $result->get_error_message() );
				}
				$this->redirect_with_notice( 'success', __( '违禁词更新成功。', 'wp-risk-scanner' ) );
				break;

			case 'delete':
				$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
				$result = WPRS_Terms::delete( $id );
				if ( is_wp_error( $result ) ) {
					$this->redirect_with_notice( 'error', $result->get_error_message() );
				}
				$this->redirect_with_notice( 'success', __( '违禁词已删除。', 'wp-risk-scanner' ) );
				break;

			case 'batch_delete':
				$ids    = isset( $_POST['term_ids'] ) ? (array) wp_unslash( $_POST['term_ids'] ) : array();
				$result = WPRS_Terms::delete_many( $ids );
				if ( is_wp_error( $result ) ) {
					$this->redirect_with_notice( 'error', $result->get_error_message() );
				}
				$this->redirect_with_notice(
					'success',
					sprintf(
						/* translators: %d: deleted count */
						__( '已删除 %d 条违禁词。', 'wp-risk-scanner' ),
						(int) $result
					)
				);
				break;

			case 'import_csv':
				$this->handle_import();
				break;

			default:
				break;
		}
	}

	/**
	 * 处理 CSV 导入。
	 *
	 * @return void
	 */
	private function handle_import() {
		if ( empty( $_FILES['csv_file'] ) || ! isset( $_FILES['csv_file']['error'] ) ) {
			$this->redirect_with_notice( 'error', __( '未选择文件或上传失败。', 'wp-risk-scanner' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file = $_FILES['csv_file'];
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$this->redirect_with_notice( 'error', __( '未选择文件或上传失败。', 'wp-risk-scanner' ) );
		}

		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			$this->redirect_with_notice( 'error', __( '请上传以 .csv 结尾的文件。', 'wp-risk-scanner' ) );
		}

		$mode = ( isset( $_POST['import_mode'] ) && 'replace' === $_POST['import_mode'] ) ? 'replace' : 'merge';
		if ( 'replace' === $mode ) {
			$confirmed = ! empty( $_POST['confirm_replace'] );
			if ( ! $confirmed ) {
				$this->redirect_with_notice( 'error', __( '替换全部词表需要勾选确认。', 'wp-risk-scanner' ) );
			}
		}

		$rows = WPRS_Terms::parse_csv_file( (string) $file['tmp_name'] );
		if ( is_wp_error( $rows ) ) {
			$this->redirect_with_notice( 'error', $rows->get_error_message() );
		}

		$result = WPRS_Terms::import_rows( $rows, $mode );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message() );
		}

		if ( 'replace' === $mode ) {
			$msg = sprintf(
				/* translators: %d: imported count */
				__( '替换导入完成：共写入 %d 条。原词表已清空。', 'wp-risk-scanner' ),
				(int) $result['inserted']
			);
		} else {
			$msg = sprintf(
				/* translators: 1: inserted, 2: updated */
				__( '合并导入完成：新增 %1$d 条，更新 %2$d 条。', 'wp-risk-scanner' ),
				(int) $result['inserted'],
				(int) $result['updated']
			);
		}
		$this->redirect_with_notice( 'success', $msg );
	}

	/**
	 * PRG：带提示重定向回词表页。
	 *
	 * @param string $type success|error。
	 * @param string $message 文案。
	 * @return void
	 */
	private function redirect_with_notice( $type, $message ) {
		set_transient(
			'wprs_terms_notice_' . get_current_user_id(),
			array(
				'type'    => ( 'error' === $type ) ? 'error' : 'success',
				'message' => $message,
			),
			60
		);
		$args = array( 'page' => self::PAGE_SLUG );
		if ( isset( $_REQUEST['s'] ) ) {
			$args['s'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		}
		if ( isset( $_REQUEST['paged'] ) ) {
			$args['paged'] = max( 1, (int) $_REQUEST['paged'] );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
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

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$result = WPRS_Terms::query( $search, $paged, 50 );
		$items  = $result['items'];
		$total  = $result['total'];
		$pages  = $result['pages'];

		$notice = get_transient( 'wprs_terms_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'wprs_terms_notice_' . get_current_user_id() );
		}

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wprs_terms_export' ),
			'wprs_terms_export',
			'nonce'
		);
		?>
		<div class="wrap wprs-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( '违禁词表', 'wp-risk-scanner' ); ?></h1>
			<hr class="wp-header-end" />

			<?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> is-dismissible">
					<p><?php echo esc_html( (string) $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( WPRS_Terms::needs_global_rescan() ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( '词表已变更，建议前往「风险扫描」执行一次全站扫描。', 'wp-risk-scanner' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wprs-risk-scanner' ) ); ?>">
							<?php esc_html_e( '前往风险扫描', 'wp-risk-scanner' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<div class="wprs-terms-toolbar">
				<div class="wprs-terms-toolbar__actions">
					<button type="button" class="button button-primary" data-wprs-open="wprs-modal-add"><?php esc_html_e( '新增违禁词', 'wp-risk-scanner' ); ?></button>
					<button type="button" class="button" data-wprs-open="wprs-modal-import"><?php esc_html_e( '导入 CSV', 'wp-risk-scanner' ); ?></button>
					<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( '导出 CSV', 'wp-risk-scanner' ); ?></a>
					<button type="button" class="button" id="wprs-batch-delete"><?php esc_html_e( '批量删除', 'wp-risk-scanner' ); ?></button>
				</div>
				<form method="get" class="wprs-terms-search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
					<label class="screen-reader-text" for="wprs-terms-s"><?php esc_html_e( '搜索违禁词', 'wp-risk-scanner' ); ?></label>
					<input type="search" id="wprs-terms-s" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( '搜索违禁词…', 'wp-risk-scanner' ); ?>" />
					<?php submit_button( __( '搜索', 'wp-risk-scanner' ), 'secondary', '', false ); ?>
				</form>
			</div>

			<form method="post" id="wprs-single-delete-form" style="display:none;">
				<?php wp_nonce_field( 'wprs_terms_action', 'wprs_terms_nonce' ); ?>
				<input type="hidden" name="wprs_action" value="delete" />
				<input type="hidden" name="id" id="wprs-single-delete-id" value="" />
			</form>

			<form method="post" id="wprs-terms-list-form">
				<?php wp_nonce_field( 'wprs_terms_action', 'wprs_terms_nonce' ); ?>
				<input type="hidden" name="wprs_action" id="wprs-list-action" value="" />
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
							<td class="manage-column column-cb check-column">
								<input type="checkbox" id="wprs-check-all" />
							</td>
							<th scope="col"><?php esc_html_e( '违禁词', 'wp-risk-scanner' ); ?></th>
							<th scope="col"><?php esc_html_e( '推荐表达', 'wp-risk-scanner' ); ?></th>
							<th scope="col"><?php esc_html_e( '说明', 'wp-risk-scanner' ); ?></th>
							<th scope="col"><?php esc_html_e( '更新人', 'wp-risk-scanner' ); ?></th>
							<th scope="col"><?php esc_html_e( '操作', 'wp-risk-scanner' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $items ) : ?>
							<tr>
								<td colspan="6"><?php esc_html_e( '暂无违禁词。可手动新增或导入 CSV。', 'wp-risk-scanner' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $items as $item ) : ?>
								<tr>
									<th scope="row" class="check-column">
										<input type="checkbox" name="term_ids[]" value="<?php echo esc_attr( (string) $item->id ); ?>" class="wprs-term-cb" />
									</th>
									<td><strong><?php echo esc_html( (string) $item->risk_term ); ?></strong></td>
									<td><?php echo esc_html( (string) $item->recommended_term ); ?></td>
									<td><?php echo esc_html( wp_html_excerpt( (string) $item->guidelines, 80, '…' ) ); ?></td>
									<td><?php echo esc_html( (string) $item->update_user ); ?></td>
									<td>
										<button
											type="button"
											class="button-link wprs-edit-term"
											data-id="<?php echo esc_attr( (string) $item->id ); ?>"
											data-risk="<?php echo esc_attr( (string) $item->risk_term ); ?>"
											data-recommended="<?php echo esc_attr( (string) $item->recommended_term ); ?>"
											data-guidelines="<?php echo esc_attr( (string) $item->guidelines ); ?>"
										><?php esc_html_e( '编辑', 'wp-risk-scanner' ); ?></button>
										|
										<button
											type="button"
											class="button-link button-link-delete wprs-delete-term"
											data-id="<?php echo esc_attr( (string) $item->id ); ?>"
											data-confirm="<?php echo esc_attr( __( '确定删除该违禁词吗？', 'wp-risk-scanner' ) ); ?>"
										><?php esc_html_e( '删除', 'wp-risk-scanner' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</form>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg(
										array(
											'page'  => self::PAGE_SLUG,
											's'     => $search,
											'paged' => '%#%',
										),
										admin_url( 'admin.php' )
									),
									'format'    => '',
									'current'   => $paged,
									'total'     => $pages,
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>

			<!-- 新增 -->
			<div id="wprs-modal-add" class="wprs-modal" hidden>
				<div class="wprs-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wprs-modal-add-title">
					<button type="button" class="wprs-modal__close" data-wprs-close>&times;</button>
					<h2 id="wprs-modal-add-title"><?php esc_html_e( '新增违禁词', 'wp-risk-scanner' ); ?></h2>
					<form method="post">
						<?php wp_nonce_field( 'wprs_terms_action', 'wprs_terms_nonce' ); ?>
						<input type="hidden" name="wprs_action" value="add" />
						<p>
							<label for="wprs-add-risk"><?php esc_html_e( '违禁词', 'wp-risk-scanner' ); ?> <span class="required">*</span></label><br />
							<input type="text" class="regular-text" id="wprs-add-risk" name="risk_term" required />
						</p>
						<p>
							<label for="wprs-add-rec"><?php esc_html_e( '推荐表达', 'wp-risk-scanner' ); ?></label><br />
							<input type="text" class="regular-text" id="wprs-add-rec" name="recommended_term" />
						</p>
						<p>
							<label for="wprs-add-guide"><?php esc_html_e( '说明', 'wp-risk-scanner' ); ?></label><br />
							<textarea class="large-text" rows="4" id="wprs-add-guide" name="guidelines"></textarea>
						</p>
						<p>
							<button type="submit" class="button button-primary"><?php esc_html_e( '保存', 'wp-risk-scanner' ); ?></button>
							<button type="button" class="button" data-wprs-close><?php esc_html_e( '取消', 'wp-risk-scanner' ); ?></button>
						</p>
					</form>
				</div>
			</div>

			<!-- 编辑 -->
			<div id="wprs-modal-edit" class="wprs-modal" hidden>
				<div class="wprs-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wprs-modal-edit-title">
					<button type="button" class="wprs-modal__close" data-wprs-close>&times;</button>
					<h2 id="wprs-modal-edit-title"><?php esc_html_e( '编辑违禁词', 'wp-risk-scanner' ); ?></h2>
					<form method="post">
						<?php wp_nonce_field( 'wprs_terms_action', 'wprs_terms_nonce' ); ?>
						<input type="hidden" name="wprs_action" value="edit" />
						<input type="hidden" name="id" id="wprs-edit-id" value="" />
						<p>
							<label for="wprs-edit-risk"><?php esc_html_e( '违禁词', 'wp-risk-scanner' ); ?> <span class="required">*</span></label><br />
							<input type="text" class="regular-text" id="wprs-edit-risk" name="risk_term" required />
						</p>
						<p>
							<label for="wprs-edit-rec"><?php esc_html_e( '推荐表达', 'wp-risk-scanner' ); ?></label><br />
							<input type="text" class="regular-text" id="wprs-edit-rec" name="recommended_term" />
						</p>
						<p>
							<label for="wprs-edit-guide"><?php esc_html_e( '说明', 'wp-risk-scanner' ); ?></label><br />
							<textarea class="large-text" rows="4" id="wprs-edit-guide" name="guidelines"></textarea>
						</p>
						<p>
							<button type="submit" class="button button-primary"><?php esc_html_e( '更新', 'wp-risk-scanner' ); ?></button>
							<button type="button" class="button" data-wprs-close><?php esc_html_e( '取消', 'wp-risk-scanner' ); ?></button>
						</p>
					</form>
				</div>
			</div>

			<!-- 导入 -->
			<div id="wprs-modal-import" class="wprs-modal" hidden>
				<div class="wprs-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wprs-modal-import-title">
					<button type="button" class="wprs-modal__close" data-wprs-close>&times;</button>
					<h2 id="wprs-modal-import-title"><?php esc_html_e( '导入 CSV', 'wp-risk-scanner' ); ?></h2>
					<form method="post" enctype="multipart/form-data" id="wprs-import-form">
						<?php wp_nonce_field( 'wprs_terms_action', 'wprs_terms_nonce' ); ?>
						<input type="hidden" name="wprs_action" value="import_csv" />
						<p class="description">
							<?php esc_html_e( '列顺序：违禁词, 推荐表达, 说明。首行可为表头（risk_term / 违禁词）。', 'wp-risk-scanner' ); ?>
						</p>
						<p>
							<input type="file" name="csv_file" accept=".csv,text/csv" required />
						</p>
						<p>
							<label>
								<input type="radio" name="import_mode" value="merge" checked />
								<?php esc_html_e( '合并（默认）：同词更新，新词追加，不删除未出现的词', 'wp-risk-scanner' ); ?>
							</label>
						</p>
						<p>
							<label>
								<input type="radio" name="import_mode" value="replace" id="wprs-import-replace" />
								<?php esc_html_e( '替换全部：先清空词表再导入', 'wp-risk-scanner' ); ?>
							</label>
						</p>
						<p id="wprs-replace-confirm-wrap" hidden>
							<label>
								<input type="checkbox" name="confirm_replace" value="1" id="wprs-confirm-replace" />
								<?php esc_html_e( '我确认替换全部词表（不可恢复）', 'wp-risk-scanner' ); ?>
							</label>
						</p>
						<p>
							<button type="submit" class="button button-primary"><?php esc_html_e( '开始导入', 'wp-risk-scanner' ); ?></button>
							<button type="button" class="button" data-wprs-close><?php esc_html_e( '取消', 'wp-risk-scanner' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
