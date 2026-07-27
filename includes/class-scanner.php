<?php
/**
 * 扫描引擎与相关 AJAX。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 全站/单篇扫描、结果查询、忽略词与扫描日志。
 */
class WPRS_Scanner {

	const META_WHITELIST       = '_wprs_risk_whitelist';
	const OPTION_LAST_SCAN     = 'wprs_last_global_scan_time';
	const OPTION_LAST_FULL_AT  = 'wprs_last_full_scan_terms_changed_at';
	const TRANSIENT_RUN_PREFIX = 'wprs_scan_run_';

	/**
	 * 注册 AJAX 与清理钩子。
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_wprs_scan_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_wprs_scan_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_wprs_scan_complete', array( $this, 'ajax_complete' ) );
		add_action( 'wp_ajax_wprs_scan_ignore_term', array( $this, 'ajax_ignore_term' ) );
		add_action( 'wp_ajax_wprs_scan_unignore_term', array( $this, 'ajax_unignore_term' ) );
		add_action( 'admin_post_wprs_clear_scan_results', array( $this, 'handle_clear_results' ) );
	}

	/**
	 * AJAX 权限与 nonce。
	 *
	 * @return void
	 */
	private function require_ajax() {
		if ( ! current_user_can( WPRS_Settings::cap_manage() ) ) {
			wp_send_json_error( array( 'message' => __( '无权限', 'wp-risk-scanner' ) ), 403 );
		}
		check_ajax_referer( 'wprs_scan_ajax', 'nonce' );
	}

	/**
	 * 参与扫描的文章类型（来自设置，排除附件）。
	 *
	 * @return string[]
	 */
	public static function eligible_post_types() {
		$settings = WPRS_Settings::get();
		$types    = isset( $settings['scan_post_types'] ) ? $settings['scan_post_types'] : array( 'post', 'page' );
		$types    = array_values( array_diff( $types, array( 'attachment' ) ) );
		return $types ? $types : array( 'post', 'page' );
	}

	/**
	 * Yoast 相关 meta 键（软依赖，不强制安装）。
	 *
	 * @return string[]
	 */
	public static function yoast_meta_keys() {
		return array(
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_focuskw',
			'_yoast_wpseo_bctitle',
			'_yoast_wpseo_opengraph-title',
			'_yoast_wpseo_opengraph-description',
			'_yoast_wpseo_twitter-title',
			'_yoast_wpseo_twitter-description',
		);
	}

	/**
	 * 收集 Yoast 文案。
	 *
	 * @param int                  $post_id        文章 ID。
	 * @param array<string, mixed> $meta_overrides 覆盖 meta。
	 * @return string
	 */
	public static function collect_yoast_text( $post_id, $meta_overrides = array() ) {
		$parts = array();
		foreach ( self::yoast_meta_keys() as $key ) {
			$val = '';
			if ( is_array( $meta_overrides ) && array_key_exists( $key, $meta_overrides ) ) {
				$raw = $meta_overrides[ $key ];
				$val = is_string( $raw ) ? $raw : '';
			} else {
				$m   = get_post_meta( (int) $post_id, $key, true );
				$val = is_string( $m ) ? $m : '';
			}
			$val = trim( $val );
			if ( '' !== $val ) {
				$parts[] = $val;
			}
		}
		return implode( "\n", $parts );
	}

	/**
	 * 提取命中片段（带高亮）。
	 *
	 * @param string $content 文本。
	 * @param string $term    违禁词。
	 * @param int    $context 前后文长度。
	 * @return string HTML。
	 */
	public static function extract_snippet( $content, $term, $context = 50 ) {
		if ( '' === (string) $term ) {
			return '';
		}
		$quoted = preg_quote( $term, '/' );
		$plain  = wp_strip_all_tags( (string) $content );
		$pattern = '/(.{0,' . (int) $context . '})(' . $quoted . ')(.{0,' . (int) $context . '})/isu';
		if ( preg_match( $pattern, $plain, $matches ) ) {
			return esc_html( $matches[1] ) . '<mark class="wprs-mark">' . esc_html( $matches[2] ) . '</mark>' . esc_html( $matches[3] );
		}
		if ( preg_match( $pattern, (string) $content, $matches ) ) {
			return esc_html( wp_strip_all_tags( $matches[1] ) ) . '<mark class="wprs-mark">' . esc_html( $matches[2] ) . '</mark>' . esc_html( wp_strip_all_tags( $matches[3] ) );
		}
		return '';
	}

	/**
	 * 规范化 Elementor 数据为可扫描文本。
	 *
	 * @param mixed $el_data meta 值。
	 * @return string
	 */
	private static function elementor_plaintext( $el_data ) {
		if ( empty( $el_data ) ) {
			return '';
		}
		if ( is_array( $el_data ) ) {
			$el_data = wp_json_encode( $el_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} elseif ( is_string( $el_data ) ) {
			$decoded = json_decode( $el_data, true );
			if ( is_array( $decoded ) ) {
				$el_data = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			} else {
				$el_data = preg_replace_callback(
					'/\\\\u([0-9a-fA-F]{4})/i',
					static function ( $match ) {
						return mb_convert_encoding( pack( 'H*', $match[1] ), 'UTF-8', 'UCS-2BE' );
					},
					$el_data
				);
			}
		} else {
			return '';
		}
		return wp_strip_all_tags( (string) $el_data );
	}

	/**
	 * 读取文章白名单（post meta + Elementor + 历史 ignored）。
	 *
	 * @param int         $post_id     文章 ID。
	 * @param object|null $old_record  既有结果行。
	 * @return string[]
	 */
	private static function collect_ignored_terms( $post_id, $old_record = null ) {
		$raw = array();
		$meta = get_post_meta( $post_id, self::META_WHITELIST, true );
		if ( ! empty( $meta ) && is_string( $meta ) ) {
			$raw = array_merge( $raw, explode( '、', $meta ) );
		}
		$el_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( is_array( $el_settings ) && ! empty( $el_settings[ self::META_WHITELIST ] ) ) {
			$raw = array_merge( $raw, explode( '、', (string) $el_settings[ self::META_WHITELIST ] ) );
		}
		if ( $old_record && ! empty( $old_record->ignored_terms ) ) {
			$history = json_decode( $old_record->ignored_terms, true );
			if ( is_array( $history ) ) {
				$raw = array_merge( $raw, $history );
			}
		}
		$ignored = array();
		foreach ( $raw as $w ) {
			$w = trim( (string) $w );
			if ( '' !== $w ) {
				$ignored[] = $w;
			}
		}
		return array_values( array_unique( $ignored ) );
	}

	/**
	 * 同步白名单到 post meta 与 Elementor page settings（防递归）。
	 *
	 * @param int          $post_id 文章 ID。
	 * @param string[]|string $terms 忽略词列表或顿号分隔字符串。
	 * @return string 规范化后的白名单字符串。
	 */
	public static function sync_whitelist( $post_id, $terms ) {
		static $syncing = false;
		if ( $syncing ) {
			return is_string( $terms ) ? $terms : implode( '、', (array) $terms );
		}

		$post_id = (int) $post_id;
		if ( is_string( $terms ) ) {
			$terms = explode( '、', $terms );
		}
		$terms = array_values( array_unique( array_filter( array_map( 'trim', (array) $terms ) ) ) );
		$clean = implode( '、', $terms );

		$syncing = true;
		try {
			$current = get_post_meta( $post_id, self::META_WHITELIST, true );
			$current = is_string( $current ) ? $current : '';
			if ( $current !== $clean ) {
				if ( '' === $clean ) {
					delete_post_meta( $post_id, self::META_WHITELIST );
				} else {
					update_post_meta( $post_id, self::META_WHITELIST, $clean );
				}
			}

			$el_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
			if ( ! is_array( $el_settings ) ) {
				$el_settings = array();
			}
			$el_current = isset( $el_settings[ self::META_WHITELIST ] ) ? (string) $el_settings[ self::META_WHITELIST ] : '';
			if ( $el_current !== $clean ) {
				$el_settings[ self::META_WHITELIST ] = $clean;
				update_post_meta( $post_id, '_elementor_page_settings', $el_settings );
			}
		} finally {
			$syncing = false;
		}

		return $clean;
	}

	/**
	 * 扫描指定文章 ID。
	 *
	 * @param int[] $post_ids 文章 ID。
	 * @return array{failed_posts:int,last_error:string,scanned:int,risk_hits:int}
	 */
	public static function scan_posts( $post_ids ) {
		$empty = array(
			'failed_posts' => 0,
			'last_error'   => '',
			'scanned'      => 0,
			'risk_hits'    => 0,
		);
		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
			return $empty;
		}

		global $wpdb;
		$table_terms   = WPRS_Activator::table_terms();
		$table_results = WPRS_Activator::table_results();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all_terms = $wpdb->get_col( "SELECT risk_term FROM {$table_terms}" );
		if ( ! is_array( $all_terms ) ) {
			return array(
				'failed_posts' => count( $post_ids ),
				'last_error'   => (string) $wpdb->last_error,
				'scanned'      => 0,
				'risk_hits'    => 0,
			);
		}

		$now          = current_time( 'mysql' );
		$failed_posts = 0;
		$last_error   = '';
		$scanned      = 0;
		$risk_hits    = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			try {
				$post = get_post( $post_id );
				if ( ! $post || 'publish' !== (string) $post->post_status ) {
					continue;
				}

				$article_text = $post->post_title . "\n" . $post->post_content . "\n" . $post->post_excerpt;
				$yoast_text   = self::collect_yoast_text( $post_id );
				$el_plain     = self::elementor_plaintext( get_post_meta( $post_id, '_elementor_data', true ) );
				if ( '' !== $el_plain ) {
					$article_text .= "\n" . $el_plain;
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$old_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_results} WHERE post_id = %d", $post_id ) );
				$ignored    = self::collect_ignored_terms( $post_id, $old_record );

				$found_terms     = array();
				$terms_by_source = array(
					'article' => array(),
					'yoast'   => array(),
				);
				$snippets = array();

				foreach ( $all_terms as $term ) {
					$term = (string) $term;
					if ( '' === $term ) {
						continue;
					}
					$in_article = '' !== $article_text && false !== stripos( $article_text, $term );
					$in_yoast   = '' !== $yoast_text && false !== stripos( $yoast_text, $term );
					if ( ! $in_article && ! $in_yoast ) {
						continue;
					}
					if ( ! in_array( $term, $ignored, true ) ) {
						$found_terms[] = $term;
						if ( $in_article ) {
							$terms_by_source['article'][] = $term;
						}
						if ( $in_yoast ) {
							$terms_by_source['yoast'][] = $term;
						}
					}
					$snippet_parts = array();
					if ( $in_article ) {
						$snippet_parts[] = '【文章内容】' . self::extract_snippet( $article_text, $term );
					}
					if ( $in_yoast ) {
						$snippet_parts[] = '【Yoast内容】' . self::extract_snippet( $yoast_text, $term );
					}
					if ( $snippet_parts ) {
						$snippets[ $term ] = implode( '<br><br>', $snippet_parts );
					}
				}

				$found_terms                = array_values( array_unique( $found_terms ) );
				$terms_by_source['article'] = array_values( array_unique( $terms_by_source['article'] ) );
				$terms_by_source['yoast']   = array_values( array_unique( $terms_by_source['yoast'] ) );

				$data = array(
					'post_id'              => $post_id,
					'post_title'           => $post->post_title,
					'post_type'            => $post->post_type,
					'post_update_time'     => $post->post_modified,
					'risk_terms_found'     => wp_json_encode( $found_terms, JSON_UNESCAPED_UNICODE ),
					'risk_snippets'        => wp_json_encode( $snippets, JSON_UNESCAPED_UNICODE ),
					'risk_terms_by_source' => wp_json_encode( $terms_by_source, JSON_UNESCAPED_UNICODE ),
					'ignored_terms'        => wp_json_encode( $ignored, JSON_UNESCAPED_UNICODE ),
					'scan_time'            => $now,
				);

				if ( $old_record ) {
					$ok = $wpdb->update( $table_results, $data, array( 'id' => (int) $old_record->id ) );
				} else {
					$ok = $wpdb->insert( $table_results, $data );
				}
				if ( false === $ok ) {
					throw new Exception( (string) $wpdb->last_error );
				}

				++$scanned;
				if ( $found_terms ) {
					++$risk_hits;
				}
			} catch ( Throwable $e ) {
				++$failed_posts;
				$last_error = $e->getMessage();
			}
		}

		return array(
			'failed_posts' => $failed_posts,
			'last_error'   => $last_error,
			'scanned'      => $scanned,
			'risk_hits'    => $risk_hits,
		);
	}

	/**
	 * 统计有风险的已发布结果数。
	 *
	 * @return int
	 */
	public static function count_with_risk() {
		global $wpdb;
		$table = WPRS_Activator::table_results();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE risk_terms_found IS NOT NULL AND risk_terms_found != '' AND risk_terms_found != '[]'"
		);
	}

	/**
	 * 写入扫描日志，并清理 15 天前记录。
	 *
	 * @param string $type 类型键。
	 * @param string $label 展示文案。
	 * @param int    $posts_scanned 扫描篇数。
	 * @param int    $risk_count 风险数。
	 * @param int    $duration_ms 耗时。
	 * @param int    $failed_posts 失败数。
	 * @param string $last_error 最后错误。
	 * @return void
	 */
	public static function write_log( $type, $label, $posts_scanned, $risk_count, $duration_ms, $failed_posts, $last_error ) {
		global $wpdb;
		$user = wp_get_current_user();
		$wpdb->insert(
			WPRS_Activator::table_log(),
			array(
				'scan_type'            => sanitize_key( $type ),
				'scan_type_label'      => sanitize_text_field( $label ),
				'posts_scanned'        => (int) $posts_scanned,
				'risk_count'           => (int) $risk_count,
				'archives_scanned'     => 0,
				'archives_risk_count'  => 0,
				'execution_time_ms'    => (int) $duration_ms,
				'failed_posts'         => (int) $failed_posts,
				'last_error'           => (string) $last_error,
				'operator'             => $user && $user->exists() ? (string) $user->user_login : '',
				'created_at'           => current_time( 'mysql' ),
			)
		);
		$log = WPRS_Activator::table_log();
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - 15 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * 查询风险结果列表。
	 *
	 * @param array<string, mixed> $args 筛选参数。
	 * @return array{items: array<int, object>, total: int, pages: int, offset: int}
	 */
	public static function query_results( $args = array() ) {
		global $wpdb;
		$table       = WPRS_Activator::table_results();
		$page        = max( 1, isset( $args['paged'] ) ? (int) $args['paged'] : 1 );
		$per_page    = max( 1, min( 100, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );
		$offset      = ( $page - 1 ) * $per_page;
		$status      = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'risk';
		$post_type   = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : '';
		$search      = isset( $args['s'] ) ? sanitize_text_field( (string) $args['s'] ) : '';
		$search_type = isset( $args['search_type'] ) ? sanitize_key( $args['search_type'] ) : 'title';
		if ( ! in_array( $search_type, array( 'title', 'risk_term' ), true ) ) {
			$search_type = 'title';
		}

		$where  = array( '1=1' );
		$params = array();

		if ( 'risk' === $status ) {
			$where[] = "risk_terms_found IS NOT NULL AND risk_terms_found != '' AND risk_terms_found != '[]'";
		} elseif ( 'clean' === $status || 'compliant' === $status ) {
			$where[] = "(risk_terms_found IS NULL OR risk_terms_found = '' OR risk_terms_found = '[]')";
		}
		if ( '' !== $post_type ) {
			$where[]  = 'post_type = %s';
			$params[] = $post_type;
		}
		if ( '' !== $search ) {
			if ( 'risk_term' === $search_type ) {
				$where[]  = 'risk_terms_found LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			} else {
				$where[]  = 'post_title LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			}
		}

		$where_sql = implode( ' AND ', $where );
		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$params ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY scan_time DESC, id DESC LIMIT %d OFFSET %d",
					...array_merge( $params, array( $per_page, $offset ) )
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY scan_time DESC, id DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
		}

		return array(
			'items'  => is_array( $items ) ? $items : array(),
			'total'  => $total,
			'pages'  => (int) ceil( $total / $per_page ),
			'offset' => $offset,
		);
	}

	/**
	 * 最近扫描日志。
	 *
	 * @param int $limit 条数。
	 * @return array<int, object>
	 */
	public static function recent_logs( $limit = 50 ) {
		global $wpdb;
		$table = WPRS_Activator::table_log();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d",
				max( 1, (int) $limit )
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * AJAX：开始全站扫描。
	 *
	 * @return void
	 */
	public function ajax_start() {
		$this->require_ajax();
		global $wpdb;

		$types = self::eligible_post_types();
		$in    = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in})"
		);
		$post_ids = array_map( 'intval', is_array( $post_ids ) ? $post_ids : array() );

		$scan_run_id = wp_generate_password( 24, false, false );
		set_transient(
			self::TRANSIENT_RUN_PREFIX . $scan_run_id,
			array(
				'user_id'      => get_current_user_id(),
				'started_at'   => microtime( true ),
				'failed_posts' => 0,
				'last_error'   => '',
			),
			2 * HOUR_IN_SECONDS
		);

		wp_send_json_success(
			array(
				'post_ids'    => $post_ids,
				'scan_run_id' => $scan_run_id,
				'total'       => count( $post_ids ),
			)
		);
	}

	/**
	 * AJAX：处理一批文章。
	 *
	 * @return void
	 */
	public function ajax_process_batch() {
		$this->require_ajax();

		$raw = isset( $_POST['batch'] ) ? wp_unslash( $_POST['batch'] ) : '[]';
		if ( is_string( $raw ) ) {
			$batch = json_decode( $raw, true );
		} else {
			$batch = $raw;
		}
		if ( ! is_array( $batch ) || ! $batch ) {
			wp_send_json_success( array( 'message' => 'empty' ) );
		}
		$batch = array_map( 'intval', $batch );

		$scan_run_id = isset( $_POST['scan_run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_run_id'] ) ) : '';
		$attempt     = isset( $_POST['attempt'] ) ? max( 1, (int) $_POST['attempt'] ) : 1;

		$result           = self::scan_posts( $batch );
		$batch_failed     = (int) $result['failed_posts'];
		$batch_last_error = (string) $result['last_error'];

		if ( $batch_failed > 0 ) {
			if ( '' !== $scan_run_id && $attempt >= 2 ) {
				$key = self::TRANSIENT_RUN_PREFIX . $scan_run_id;
				$run = get_transient( $key );
				if ( is_array( $run ) && (int) $run['user_id'] === get_current_user_id() ) {
					$run['failed_posts'] = (int) $run['failed_posts'] + $batch_failed;
					if ( '' !== $batch_last_error ) {
						$run['last_error'] = $batch_last_error;
					}
					set_transient( $key, $run, 2 * HOUR_IN_SECONDS );
				}
			}
			wp_send_json_error(
				array(
					'message' => $batch_last_error ? $batch_last_error : __( '批次扫描失败', 'wp-risk-scanner' ),
				)
			);
		}

		update_option( self::OPTION_LAST_SCAN, current_time( 'mysql' ), false );
		wp_send_json_success(
			array(
				'scanned'   => (int) $result['scanned'],
				'risk_hits' => (int) $result['risk_hits'],
			)
		);
	}

	/**
	 * AJAX：全站扫描完成，写日志并清重扫标记。
	 *
	 * @return void
	 */
	public function ajax_complete() {
		$this->require_ajax();

		$total_posts  = isset( $_POST['total_posts'] ) ? (int) $_POST['total_posts'] : 0;
		$scan_run_id  = isset( $_POST['scan_run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_run_id'] ) ) : '';
		$failed_posts = 0;
		$last_error   = '';
		$duration_ms  = 0;

		if ( '' !== $scan_run_id ) {
			$key = self::TRANSIENT_RUN_PREFIX . $scan_run_id;
			$run = get_transient( $key );
			if ( is_array( $run ) && (int) $run['user_id'] === get_current_user_id() ) {
				$started_at   = isset( $run['started_at'] ) ? (float) $run['started_at'] : 0;
				$duration_ms  = $started_at > 0 ? (int) round( ( microtime( true ) - $started_at ) * 1000 ) : 0;
				$failed_posts = (int) $run['failed_posts'];
				$last_error   = (string) $run['last_error'];
			}
			delete_transient( $key );
		}

		$risk_count = self::count_with_risk();
		self::write_log( 'full', __( '全局扫描', 'wp-risk-scanner' ), $total_posts, $risk_count, $duration_ms, $failed_posts, $last_error );

		if ( 0 === $failed_posts && '' === $last_error ) {
			$terms_changed_at = (int) get_option( WPRS_Terms::OPTION_CHANGED_AT, 0 );
			$t                = $terms_changed_at > 0 ? $terms_changed_at : (int) current_time( 'timestamp' );
			update_option( self::OPTION_LAST_FULL_AT, $t, false );
			WPRS_Terms::clear_rescan_flag();
		}

		WPRS_Notifier::maybe_notify( $risk_count, $total_posts, false );

		wp_send_json_success(
			array(
				'risk_count'   => $risk_count,
				'failed_posts' => $failed_posts,
				'duration_ms'  => $duration_ms,
			)
		);
	}

	/**
	 * AJAX：忽略词。
	 *
	 * @return void
	 */
	public function ajax_ignore_term() {
		$this->require_ajax();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$term    = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		if ( $post_id <= 0 || '' === $term ) {
			wp_send_json_error( array( 'message' => __( '缺少参数', 'wp-risk-scanner' ) ) );
		}

		$meta = get_post_meta( $post_id, self::META_WHITELIST, true );
		$list = ! empty( $meta ) ? explode( '、', (string) $meta ) : array();
		$list = array_values( array_filter( array_map( 'trim', $list ) ) );
		if ( ! in_array( $term, $list, true ) ) {
			$list[] = $term;
			self::sync_whitelist( $post_id, $list );
		}
		self::scan_posts( array( $post_id ) );
		wp_send_json_success();
	}

	/**
	 * AJAX：取消忽略。
	 *
	 * @return void
	 */
	public function ajax_unignore_term() {
		$this->require_ajax();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$term    = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		if ( $post_id <= 0 || '' === $term ) {
			wp_send_json_error( array( 'message' => __( '缺少参数', 'wp-risk-scanner' ) ) );
		}

		$meta = get_post_meta( $post_id, self::META_WHITELIST, true );
		$list = ! empty( $meta ) ? explode( '、', (string) $meta ) : array();
		$list = array_values( array_diff( array_filter( array_map( 'trim', $list ) ), array( $term ) ) );
		self::sync_whitelist( $post_id, $list );
		self::scan_posts( array( $post_id ) );
		wp_send_json_success();
	}

	/**
	 * 清空扫描结果（管理员）。
	 *
	 * @return void
	 */
	public function handle_clear_results() {
		if ( ! current_user_can( WPRS_Settings::cap_sensitive() ) ) {
			wp_die( esc_html__( '您没有权限执行此操作。', 'wp-risk-scanner' ) );
		}
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wprs_clear_scan_results' ) ) {
			wp_die( esc_html__( '安全验证失败。', 'wp-risk-scanner' ) );
		}
		global $wpdb;
		$table = WPRS_Activator::table_results();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		set_transient(
			'wprs_scan_notice_' . get_current_user_id(),
			array(
				'type'    => 'success',
				'message' => __( '已清空全部扫描结果。', 'wp-risk-scanner' ),
			),
			60
		);
		wp_safe_redirect( admin_url( 'admin.php?page=wprs-risk-scanner' ) );
		exit;
	}
}
