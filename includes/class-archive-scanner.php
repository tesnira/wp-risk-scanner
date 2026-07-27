<?php
/**
 * 归档页（taxonomy term）扫描引擎，与文章扫描分离。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * taxonomy 归档页文本采集、扫描入库、忽略词与统计。
 */
class WPRS_Archive_Scanner {

	const TERM_META_WHITELIST = '_wprs_risk_whitelist';

	/**
	 * 注册 AJAX。
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_wprs_scan_ignore_archive_term', array( $this, 'ajax_ignore_term' ) );
		add_action( 'wp_ajax_wprs_scan_unignore_archive_term', array( $this, 'ajax_unignore_term' ) );
	}

	/**
	 * 当前应扫描的 taxonomy（设置 ∩ 已注册）。
	 *
	 * @return string[]
	 */
	public static function eligible_taxonomies() {
		if ( ! WPRS_Settings::is_archive_scan_enabled() ) {
			return array();
		}
		$settings = WPRS_Settings::get();
		$configured = isset( $settings['archive_taxonomies'] ) ? (array) $settings['archive_taxonomies'] : array();
		$out        = array();
		foreach ( $configured as $tax ) {
			$tax = sanitize_key( (string) $tax );
			if ( '' !== $tax && taxonomy_exists( $tax ) ) {
				$out[] = $tax;
			}
		}
		/**
		 * 过滤参与归档扫描的 taxonomy。
		 *
		 * @param string[] $out 当前列表。
		 */
		return array_values( array_unique( (array) apply_filters( 'wprs_archive_scan_taxonomies', $out ) ) );
	}

	/**
	 * 可选的公开 taxonomy（设置页勾选）。
	 *
	 * @return array<string, WP_Taxonomy>
	 */
	public static function selectable_taxonomies() {
		$taxes = get_taxonomies(
			array(
				'public' => true,
			),
			'objects'
		);
		unset( $taxes['nav_menu'], $taxes['link_category'], $taxes['post_format'] );
		return is_array( $taxes ) ? $taxes : array();
	}

	/**
	 * 收集待扫 term 列表。
	 *
	 * @param bool   $incremental    是否增量。
	 * @param string $last_scan_time 上次扫描时间。
	 * @return array<int, array{taxonomy:string,term_id:int}>
	 */
	public static function collect_targets( $incremental = false, $last_scan_time = '1970-01-01 00:00:00' ) {
		global $wpdb;
		$taxonomies = self::eligible_taxonomies();
		if ( ! $taxonomies ) {
			return array();
		}

		$tax_in = "'" . implode( "','", array_map( 'esc_sql', $taxonomies ) ) . "'";
		$terms  = array();

		if ( $incremental && '1970-01-01 00:00:00' !== $last_scan_time ) {
			$post_types = WPRS_Scanner::eligible_post_types();
			if ( $post_types ) {
				$pt_in = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";
				$sql   = "
					SELECT DISTINCT tt.taxonomy, tt.term_id
					FROM {$wpdb->term_taxonomy} tt
					INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
					INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
					WHERE tt.taxonomy IN ({$tax_in})
					  AND p.post_status = 'publish'
					  AND p.post_type IN ({$pt_in})
					  AND p.post_modified > %s
				";
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, $last_scan_time ), ARRAY_A );
				if ( is_array( $rows ) ) {
					foreach ( $rows as $row ) {
						$taxonomy = isset( $row['taxonomy'] ) ? (string) $row['taxonomy'] : '';
						$term_id  = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;
						if ( '' !== $taxonomy && $term_id > 0 ) {
							$terms[] = array(
								'taxonomy' => $taxonomy,
								'term_id'  => $term_id,
							);
						}
					}
				}
			}
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				"SELECT taxonomy, term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ({$tax_in}) AND count > 0",
				ARRAY_A
			);
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$taxonomy = isset( $row['taxonomy'] ) ? (string) $row['taxonomy'] : '';
					$term_id  = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;
					if ( '' !== $taxonomy && $term_id > 0 ) {
						$terms[] = array(
							'taxonomy' => $taxonomy,
							'term_id'  => $term_id,
						);
					}
				}
			}
		}

		$uniq = array();
		foreach ( $terms as $t ) {
			$key          = $t['taxonomy'] . ':' . $t['term_id'];
			$uniq[ $key ] = $t;
		}
		return array_values( $uniq );
	}

	/**
	 * 拉取归档页前台 HTML 并转为纯文本。
	 *
	 * @param string $url 归档 URL。
	 * @return string
	 */
	public static function fetch_page_plaintext( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		$args = array(
			'timeout'     => 25,
			'redirection' => 3,
			'headers'     => array(
				'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
			),
			'user-agent'  => 'WP-RiskScanner/1.0; +' . home_url( '/' ),
		);
		/**
		 * 过滤归档页抓取请求参数。
		 *
		 * @param array  $args wp_remote_get 参数。
		 * @param string $url  请求 URL。
		 */
		$args = apply_filters( 'wprs_archive_page_request_args', $args, $url );

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return '';
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return '';
		}

		$max = (int) apply_filters( 'wprs_archive_page_max_bytes', 2 * 1024 * 1024 );
		if ( $max > 0 && strlen( $body ) > $max ) {
			$body = substr( $body, 0, $max );
		}
		$body = preg_replace( '#<script[^>]*>.*?</script>#is', '', $body );
		$body = preg_replace( '#<style[^>]*>.*?</style>#is', '', $body );
		$body = preg_replace( '#<noscript[^>]*>.*?</noscript>#is', '', $body );
		$text = wp_strip_all_tags( (string) $body );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );

		$max_chars = (int) apply_filters( 'wprs_archive_page_max_chars', 500000 );
		if ( $max_chars > 0 && function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $max_chars ) {
			$text = mb_substr( $text, 0, $max_chars, 'UTF-8' );
		} elseif ( $max_chars > 0 && strlen( $text ) > $max_chars ) {
			$text = substr( $text, 0, $max_chars );
		}

		return $text;
	}

	/**
	 * 收集 term 上 Yoast taxonomy 文案。
	 *
	 * @param int    $term_id  Term ID。
	 * @param string $taxonomy Taxonomy。
	 * @return string
	 */
	public static function collect_yoast_text( $term_id, $taxonomy ) {
		$term_id  = (int) $term_id;
		$taxonomy = (string) $taxonomy;
		if ( $term_id <= 0 || '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		$keys = array(
			'wpseo_title',
			'wpseo_desc',
			'wpseo_focuskw',
			'wpseo_bctitle',
			'wpseo_opengraph-title',
			'wpseo_opengraph-description',
			'wpseo_twitter-title',
			'wpseo_twitter-description',
		);

		$all = null;
		if ( class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
			$all = \WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy );
		}
		if ( ! is_array( $all ) ) {
			$opt = get_option( 'wpseo_taxonomy_meta', array() );
			if ( is_array( $opt ) && isset( $opt[ $taxonomy ][ $term_id ] ) && is_array( $opt[ $taxonomy ][ $term_id ] ) ) {
				$all = $opt[ $taxonomy ][ $term_id ];
			} else {
				return '';
			}
		}

		$parts = array();
		foreach ( $keys as $k ) {
			$sv = isset( $all[ $k ] ) ? $all[ $k ] : '';
			$sv = is_string( $sv ) ? trim( $sv ) : '';
			if ( '' !== $sv ) {
				$parts[] = $sv;
			}
		}
		return implode( "\n", $parts );
	}

	/**
	 * 采集单个归档页「文章内容」维度文本。
	 *
	 * @param string $taxonomy Taxonomy。
	 * @param int    $term_id  Term ID。
	 * @return array{title:string,url:string,article_text:string}
	 */
	public static function collect_archive_text( $taxonomy, $term_id ) {
		$term = get_term( (int) $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return array(
				'title'        => '',
				'url'          => '',
				'article_text' => '',
			);
		}

		$title = is_string( $term->name ) ? (string) $term->name : '';
		$desc  = is_string( $term->description ) ? (string) $term->description : '';
		$url   = get_term_link( $term );
		$url   = is_wp_error( $url ) ? '' : (string) $url;

		$sources = array();
		if ( '' !== trim( $title ) ) {
			$sources['term_name'] = $title;
		}
		if ( '' !== trim( $desc ) ) {
			$sources['term_description'] = $desc;
		}

		$term_meta = get_term_meta( (int) $term_id );
		if ( is_array( $term_meta ) ) {
			foreach ( $term_meta as $k => $v ) {
				if ( self::TERM_META_WHITELIST === $k ) {
					continue;
				}
				$first = is_array( $v ) ? ( isset( $v[0] ) ? $v[0] : '' ) : $v;
				if ( is_scalar( $first ) ) {
					$sv = trim( (string) $first );
					if ( '' !== $sv ) {
						$sources[ 'term_meta:' . (string) $k ] = $sv;
					}
				}
			}
		}

		if ( '' !== $url ) {
			$page_plain = self::fetch_page_plaintext( $url );
			if ( '' !== $page_plain ) {
				$sources['page_rendered'] = $page_plain;
			}
		}

		$article_text = implode(
			"\n",
			array_values(
				array_filter(
					array_map(
						static function ( $x ) {
							return is_string( $x ) ? trim( $x ) : '';
						},
						$sources
					),
					static function ( $x ) {
						return '' !== $x;
					}
				)
			)
		);

		return array(
			'title'        => $title,
			'url'          => $url,
			'article_text' => $article_text,
		);
	}

	/**
	 * 扫描一组归档 term。
	 *
	 * @param array<int, array{taxonomy:string,term_id:int}> $targets 目标。
	 * @return array{failed:int,last_error:string,scanned:int,risk_hits:int}
	 */
	public static function scan_targets( $targets ) {
		$empty = array(
			'failed'     => 0,
			'last_error' => '',
			'scanned'    => 0,
			'risk_hits'  => 0,
		);
		if ( empty( $targets ) || ! is_array( $targets ) ) {
			return $empty;
		}

		global $wpdb;
		$table_terms    = WPRS_Activator::table_terms();
		$table_archives = WPRS_Activator::table_archives();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all_terms = $wpdb->get_col( "SELECT risk_term FROM {$table_terms}" );
		if ( ! is_array( $all_terms ) ) {
			return array(
				'failed'     => count( $targets ),
				'last_error' => (string) $wpdb->last_error,
				'scanned'    => 0,
				'risk_hits'  => 0,
			);
		}

		$failed     = 0;
		$last_error = '';
		$scanned    = 0;
		$risk_hits  = 0;
		$now        = current_time( 'mysql' );

		foreach ( $targets as $target ) {
			try {
				$taxonomy = isset( $target['taxonomy'] ) ? (string) $target['taxonomy'] : '';
				$term_id  = isset( $target['term_id'] ) ? (int) $target['term_id'] : 0;
				if ( '' === $taxonomy || $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}

				$term = get_term( $term_id, $taxonomy );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				if ( (int) $term->count <= 0 ) {
					continue;
				}

				$collected    = self::collect_archive_text( $taxonomy, $term_id );
				$article_text = (string) $collected['article_text'];
				$yoast_text   = self::collect_yoast_text( $term_id, $taxonomy );
				$ignored      = self::get_term_whitelist( $term_id );

				$terms_by_source = array(
					'article' => array(),
					'yoast'   => array(),
				);
				$found_terms = array();
				$snippets    = array();

				foreach ( $all_terms as $risk_term ) {
					$risk_term = (string) $risk_term;
					if ( '' === $risk_term ) {
						continue;
					}
					$in_article = '' !== $article_text && false !== stripos( $article_text, $risk_term );
					$in_yoast   = '' !== $yoast_text && false !== stripos( $yoast_text, $risk_term );
					if ( ! $in_article && ! $in_yoast ) {
						continue;
					}
					if ( ! in_array( $risk_term, $ignored, true ) ) {
						$found_terms[] = $risk_term;
						if ( $in_article ) {
							$terms_by_source['article'][] = $risk_term;
						}
						if ( $in_yoast ) {
							$terms_by_source['yoast'][] = $risk_term;
						}
					}
					$snippet_parts = array();
					if ( $in_article ) {
						$snippet_parts[] = '【文章内容】' . WPRS_Scanner::extract_snippet( $article_text, $risk_term );
					}
					if ( $in_yoast ) {
						$snippet_parts[] = '【Yoast内容】' . WPRS_Scanner::extract_snippet( $yoast_text, $risk_term );
					}
					if ( $snippet_parts ) {
						$snippets[ $risk_term ] = implode( '<br><br>', $snippet_parts );
					}
				}

				$found_terms                = array_values( array_unique( $found_terms ) );
				$terms_by_source['article'] = array_values( array_unique( $terms_by_source['article'] ) );
				$terms_by_source['yoast']   = array_values( array_unique( $terms_by_source['yoast'] ) );

				$row = array(
					'resource_type'        => 'taxonomy_archive',
					'taxonomy'             => $taxonomy,
					'term_id'              => $term_id,
					'title'                => (string) ( $collected['title'] ? $collected['title'] : $term->name ),
					'archive_url'          => (string) $collected['url'],
					'resource_update_time' => $now,
					'risk_terms_found'     => wp_json_encode( $found_terms, JSON_UNESCAPED_UNICODE ),
					'risk_terms_by_source' => wp_json_encode( $terms_by_source, JSON_UNESCAPED_UNICODE ),
					'risk_snippets'        => wp_json_encode( $snippets, JSON_UNESCAPED_UNICODE ),
					'ignored_terms'        => wp_json_encode( $ignored, JSON_UNESCAPED_UNICODE ),
					'scan_time'            => $now,
				);

				$exists = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT id FROM {$table_archives} WHERE taxonomy = %s AND term_id = %d LIMIT 1",
						$taxonomy,
						$term_id
					)
				);
				if ( $exists ) {
					$ok = $wpdb->update( $table_archives, $row, array( 'id' => (int) $exists ) );
				} else {
					$ok = $wpdb->insert( $table_archives, $row );
				}
				if ( false === $ok ) {
					throw new Exception( (string) $wpdb->last_error );
				}

				++$scanned;
				if ( $found_terms ) {
					++$risk_hits;
				}
			} catch ( Throwable $e ) {
				++$failed;
				$last_error = $e->getMessage();
			}
		}

		return array(
			'failed'     => $failed,
			'last_error' => $last_error,
			'scanned'    => $scanned,
			'risk_hits'  => $risk_hits,
		);
	}

	/**
	 * 读取 term 白名单。
	 *
	 * @param int $term_id Term ID。
	 * @return string[]
	 */
	public static function get_term_whitelist( $term_id ) {
		$raw = get_term_meta( (int) $term_id, self::TERM_META_WHITELIST, true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'trim', explode( '、', $raw ) ) ) ) );
	}

	/**
	 * 写入 term 白名单。
	 *
	 * @param int      $term_id Term ID。
	 * @param string[] $terms   词列表。
	 * @return void
	 */
	public static function set_term_whitelist( $term_id, $terms ) {
		$terms = array_values( array_unique( array_filter( array_map( 'trim', (array) $terms ) ) ) );
		if ( ! $terms ) {
			delete_term_meta( (int) $term_id, self::TERM_META_WHITELIST );
			return;
		}
		update_term_meta( (int) $term_id, self::TERM_META_WHITELIST, implode( '、', $terms ) );
	}

	/**
	 * 有风险的归档记录数。
	 *
	 * @return int
	 */
	public static function count_with_risk() {
		global $wpdb;
		$table = WPRS_Activator::table_archives();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE risk_terms_found IS NOT NULL AND risk_terms_found != '' AND risk_terms_found != '[]'"
		);
	}

	/**
	 * AJAX：忽略归档词。
	 *
	 * @return void
	 */
	public function ajax_ignore_term() {
		if ( ! current_user_can( WPRS_Settings::cap_manage() ) ) {
			wp_send_json_error( array( 'message' => __( '无权限', 'wp-risk-scanner' ) ), 403 );
		}
		check_ajax_referer( 'wprs_scan_ajax', 'nonce' );

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$term_id  = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
		$term     = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		if ( '' === $taxonomy || $term_id <= 0 || '' === $term || ! taxonomy_exists( $taxonomy ) ) {
			wp_send_json_error( array( 'message' => __( '缺少参数', 'wp-risk-scanner' ) ) );
		}

		$list = self::get_term_whitelist( $term_id );
		if ( ! in_array( $term, $list, true ) ) {
			$list[] = $term;
			self::set_term_whitelist( $term_id, $list );
		}
		self::scan_targets(
			array(
				array(
					'taxonomy' => $taxonomy,
					'term_id'  => $term_id,
				),
			)
		);
		wp_send_json_success();
	}

	/**
	 * AJAX：取消忽略归档词。
	 *
	 * @return void
	 */
	public function ajax_unignore_term() {
		if ( ! current_user_can( WPRS_Settings::cap_manage() ) ) {
			wp_send_json_error( array( 'message' => __( '无权限', 'wp-risk-scanner' ) ), 403 );
		}
		check_ajax_referer( 'wprs_scan_ajax', 'nonce' );

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$term_id  = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
		$term     = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		if ( '' === $taxonomy || $term_id <= 0 || '' === $term || ! taxonomy_exists( $taxonomy ) ) {
			wp_send_json_error( array( 'message' => __( '缺少参数', 'wp-risk-scanner' ) ) );
		}

		$list = array_values( array_diff( self::get_term_whitelist( $term_id ), array( $term ) ) );
		self::set_term_whitelist( $term_id, $list );
		self::scan_targets(
			array(
				array(
					'taxonomy' => $taxonomy,
					'term_id'  => $term_id,
				),
			)
		);
		wp_send_json_success();
	}
}
