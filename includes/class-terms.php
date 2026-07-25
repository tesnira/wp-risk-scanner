<?php
/**
 * 违禁词表数据访问。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 词条 CRUD、列表查询与 CSV 导入导出。
 */
class WPRS_Terms {

	const OPTION_NEEDS_RESCAN = 'wprs_needs_global_rescan';
	const OPTION_CHANGED_AT   = 'wprs_terms_changed_at';

	/**
	 * 标记词表已变更（提示需全量重扫）。
	 *
	 * @return void
	 */
	public static function mark_changed() {
		update_option( self::OPTION_NEEDS_RESCAN, 1, false );
		update_option( self::OPTION_CHANGED_AT, current_time( 'timestamp' ), false );
	}

	/**
	 * 是否需要全量重扫。
	 *
	 * @return bool
	 */
	public static function needs_global_rescan() {
		return (int) get_option( self::OPTION_NEEDS_RESCAN, 0 ) > 0;
	}

	/**
	 * 清除重扫标记。
	 *
	 * @return void
	 */
	public static function clear_rescan_flag() {
		delete_option( self::OPTION_NEEDS_RESCAN );
	}

	/**
	 * 当前操作用户显示名。
	 *
	 * @return string
	 */
	private static function current_user_label() {
		$user = wp_get_current_user();
		return $user && $user->exists() ? (string) $user->user_login : '';
	}

	/**
	 * 统计词条数。
	 *
	 * @param string $search 按违禁词模糊搜索。
	 * @return int
	 */
	public static function count( $search = '' ) {
		global $wpdb;
		$table = WPRS_Activator::table_terms();
		$search = trim( (string) $search );
		if ( '' === $search ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE risk_term LIKE %s",
				'%' . $wpdb->esc_like( $search ) . '%'
			)
		);
	}

	/**
	 * 分页查询词条。
	 *
	 * @param string $search   搜索词。
	 * @param int    $page     页码（从 1 起）。
	 * @param int    $per_page 每页条数。
	 * @return array{items: array<int, object>, total: int, pages: int}
	 */
	public static function query( $search = '', $page = 1, $per_page = 50 ) {
		global $wpdb;
		$table    = WPRS_Activator::table_terms();
		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 200, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$total    = self::count( $search );
		$search   = trim( (string) $search );

		if ( '' === $search ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE risk_term LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d",
					'%' . $wpdb->esc_like( $search ) . '%',
					$per_page,
					$offset
				)
			);
		}

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * 按 ID 读取词条。
	 *
	 * @param int $id 词条 ID。
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		$table = WPRS_Activator::table_terms();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		return $row ? $row : null;
	}

	/**
	 * 按违禁词精确查找（合并导入用）。
	 *
	 * @param string $term 违禁词。
	 * @return object|null
	 */
	public static function find_by_term( $term ) {
		global $wpdb;
		$term = trim( (string) $term );
		if ( '' === $term ) {
			return null;
		}
		$table = WPRS_Activator::table_terms();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE risk_term = %s LIMIT 1", $term ) );
		return $row ? $row : null;
	}

	/**
	 * 清洗单条词条字段。
	 *
	 * @param array<string, mixed> $input 原始输入。
	 * @return array{risk_term:string,recommended_term:string,guidelines:string}|WP_Error
	 */
	public static function sanitize_row( $input ) {
		$risk = isset( $input['risk_term'] ) ? sanitize_text_field( (string) $input['risk_term'] ) : '';
		$risk = trim( $risk );
		if ( '' === $risk ) {
			return new WP_Error( 'wprs_empty_term', __( '违禁词为必填项。', 'wp-risk-scanner' ) );
		}
		return array(
			'risk_term'         => $risk,
			'recommended_term'  => isset( $input['recommended_term'] )
				? sanitize_text_field( (string) $input['recommended_term'] )
				: '',
			'guidelines'        => isset( $input['guidelines'] )
				? sanitize_textarea_field( (string) $input['guidelines'] )
				: '',
		);
	}

	/**
	 * 新增词条。
	 *
	 * @param array<string, mixed> $input 字段。
	 * @return int|WP_Error 新 ID。
	 */
	public static function insert( $input ) {
		global $wpdb;
		$row = self::sanitize_row( $input );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$ok = $wpdb->insert(
			WPRS_Activator::table_terms(),
			array(
				'risk_term'        => $row['risk_term'],
				'recommended_term' => $row['recommended_term'],
				'guidelines'       => $row['guidelines'],
				'create_time'      => current_time( 'mysql' ),
				'update_time'      => current_time( 'mysql' ),
				'update_user'      => self::current_user_label(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'wprs_insert_failed', __( '添加失败。', 'wp-risk-scanner' ) );
		}
		self::mark_changed();
		return (int) $wpdb->insert_id;
	}

	/**
	 * 更新词条。
	 *
	 * @param int                  $id    词条 ID。
	 * @param array<string, mixed> $input 字段。
	 * @return true|WP_Error
	 */
	public static function update( $id, $input ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 || ! self::get( $id ) ) {
			return new WP_Error( 'wprs_not_found', __( '词条不存在。', 'wp-risk-scanner' ) );
		}
		$row = self::sanitize_row( $input );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$ok = $wpdb->update(
			WPRS_Activator::table_terms(),
			array(
				'risk_term'        => $row['risk_term'],
				'recommended_term' => $row['recommended_term'],
				'guidelines'       => $row['guidelines'],
				'update_user'      => self::current_user_label(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'wprs_update_failed', __( '更新失败。', 'wp-risk-scanner' ) );
		}
		self::mark_changed();
		return true;
	}

	/**
	 * 删除单条。
	 *
	 * @param int $id 词条 ID。
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return new WP_Error( 'wprs_invalid_id', __( '无效的词条 ID。', 'wp-risk-scanner' ) );
		}
		$ok = $wpdb->delete( WPRS_Activator::table_terms(), array( 'id' => $id ), array( '%d' ) );
		if ( false === $ok ) {
			return new WP_Error( 'wprs_delete_failed', __( '删除失败。', 'wp-risk-scanner' ) );
		}
		if ( $ok > 0 ) {
			self::mark_changed();
		}
		return true;
	}

	/**
	 * 批量删除。
	 *
	 * @param int[] $ids ID 列表。
	 * @return int|WP_Error 删除条数。
	 */
	public static function delete_many( $ids ) {
		global $wpdb;
		if ( ! is_array( $ids ) ) {
			return new WP_Error( 'wprs_no_ids', __( '请先选择要删除的项。', 'wp-risk-scanner' ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return new WP_Error( 'wprs_no_ids', __( '请先选择要删除的项。', 'wp-risk-scanner' ) );
		}
		$table        = WPRS_Activator::table_terms();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"DELETE FROM {$table} WHERE id IN ({$placeholders})",
			...$ids
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $wpdb->query( $sql );
		if ( false === $deleted ) {
			return new WP_Error( 'wprs_delete_failed', __( '批量删除失败。', 'wp-risk-scanner' ) );
		}
		if ( $deleted > 0 ) {
			self::mark_changed();
		}
		return (int) $deleted;
	}

	/**
	 * 清空词表。
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;
		$table = WPRS_Activator::table_terms();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * 解析上传的 CSV 为行数据。
	 *
	 * @param string $tmp_path 临时文件路径。
	 * @return array<int, array{risk_term:string,recommended_term:string,guidelines:string}>|WP_Error
	 */
	public static function parse_csv_file( $tmp_path ) {
		if ( ! is_readable( $tmp_path ) ) {
			return new WP_Error( 'wprs_csv_unreadable', __( '无法读取上传的文件。', 'wp-risk-scanner' ) );
		}
		$handle = fopen( $tmp_path, 'rb' );
		if ( false === $handle ) {
			return new WP_Error( 'wprs_csv_unreadable', __( '无法读取上传的文件。', 'wp-risk-scanner' ) );
		}

		$rows = array();
		$line = 0;
		while ( ( $data = fgetcsv( $handle, 10000, ',' ) ) !== false ) {
			++$line;
			if ( 1 === $line ) {
				// 跳过表头；若首行不像表头且第一列非空，也当作数据。
				$first = isset( $data[0] ) ? trim( (string) $data[0] ) : '';
				$first = self::strip_bom( $first );
				if ( self::looks_like_header( $first ) ) {
					continue;
				}
				$data[0] = $first;
			} else {
				if ( isset( $data[0] ) ) {
					$data[0] = self::strip_bom( (string) $data[0] );
				}
			}
			$risk = isset( $data[0] ) ? sanitize_text_field( (string) $data[0] ) : '';
			$risk = trim( $risk );
			if ( '' === $risk ) {
				continue;
			}
			$rows[] = array(
				'risk_term'        => $risk,
				'recommended_term' => isset( $data[1] ) ? sanitize_text_field( (string) $data[1] ) : '',
				'guidelines'       => isset( $data[2] ) ? sanitize_textarea_field( (string) $data[2] ) : '',
			);
		}
		fclose( $handle );

		if ( ! $rows ) {
			return new WP_Error( 'wprs_csv_empty', __( 'CSV 中没有可导入的词条。', 'wp-risk-scanner' ) );
		}
		return $rows;
	}

	/**
	 * 去掉 UTF-8 BOM。
	 *
	 * @param string $text 文本。
	 * @return string
	 */
	private static function strip_bom( $text ) {
		if ( strncmp( $text, "\xEF\xBB\xBF", 3 ) === 0 ) {
			return substr( $text, 3 );
		}
		return $text;
	}

	/**
	 * 判断首行是否为表头。
	 *
	 * @param string $first 第一列。
	 * @return bool
	 */
	private static function looks_like_header( $first ) {
		$first = strtolower( trim( $first ) );
		return in_array(
			$first,
			array( 'risk_term', '违禁词', '风险词', 'term', 'word' ),
			true
		);
	}

	/**
	 * 导入词条。
	 *
	 * @param array<int, array{risk_term:string,recommended_term:string,guidelines:string}> $rows 行数据。
	 * @param string                                                                         $mode merge|replace。
	 * @return array{inserted:int,updated:int,total:int}|WP_Error
	 */
	public static function import_rows( $rows, $mode = 'merge' ) {
		if ( ! is_array( $rows ) || ! $rows ) {
			return new WP_Error( 'wprs_csv_empty', __( 'CSV 中没有可导入的词条。', 'wp-risk-scanner' ) );
		}
		$mode = ( 'replace' === $mode ) ? 'replace' : 'merge';

		if ( 'replace' === $mode ) {
			self::truncate();
		}

		$inserted = 0;
		$updated  = 0;
		foreach ( $rows as $row ) {
			$clean = self::sanitize_row( $row );
			if ( is_wp_error( $clean ) ) {
				continue;
			}
			if ( 'replace' === $mode ) {
				$result = self::insert_raw( $clean );
				if ( ! is_wp_error( $result ) ) {
					++$inserted;
				}
				continue;
			}
			$existing = self::find_by_term( $clean['risk_term'] );
			if ( $existing ) {
				$result = self::update_raw( (int) $existing->id, $clean );
				if ( ! is_wp_error( $result ) ) {
					++$updated;
				}
			} else {
				$result = self::insert_raw( $clean );
				if ( ! is_wp_error( $result ) ) {
					++$inserted;
				}
			}
		}

		if ( $inserted + $updated > 0 ) {
			self::mark_changed();
		}

		return array(
			'inserted' => $inserted,
			'updated'  => $updated,
			'total'    => $inserted + $updated,
		);
	}

	/**
	 * 内部新增（不单独打重扫标记）。
	 *
	 * @param array{risk_term:string,recommended_term:string,guidelines:string} $row 已清洗字段。
	 * @return int|WP_Error
	 */
	private static function insert_raw( $row ) {
		global $wpdb;
		$ok = $wpdb->insert(
			WPRS_Activator::table_terms(),
			array(
				'risk_term'        => $row['risk_term'],
				'recommended_term' => $row['recommended_term'],
				'guidelines'       => $row['guidelines'],
				'create_time'      => current_time( 'mysql' ),
				'update_time'      => current_time( 'mysql' ),
				'update_user'      => self::current_user_label(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'wprs_insert_failed', __( '添加失败。', 'wp-risk-scanner' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * 内部更新（不单独打重扫标记）。
	 *
	 * @param int                                                               $id  ID。
	 * @param array{risk_term:string,recommended_term:string,guidelines:string} $row 已清洗字段。
	 * @return true|WP_Error
	 */
	private static function update_raw( $id, $row ) {
		global $wpdb;
		$ok = $wpdb->update(
			WPRS_Activator::table_terms(),
			array(
				'risk_term'        => $row['risk_term'],
				'recommended_term' => $row['recommended_term'],
				'guidelines'       => $row['guidelines'],
				'update_user'      => self::current_user_label(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'wprs_update_failed', __( '更新失败。', 'wp-risk-scanner' ) );
		}
		return true;
	}

	/**
	 * 输出全部词条为 CSV 并结束请求。
	 *
	 * @return void
	 */
	public static function stream_export_csv() {
		global $wpdb;
		$table = WPRS_Activator::table_terms();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results( "SELECT risk_term, recommended_term, guidelines FROM {$table} ORDER BY id ASC", ARRAY_A );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$filename = 'wprs-risk-terms-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( '无法导出。', 'wp-risk-scanner' ) );
		}
		// Excel 友好的 UTF-8 BOM。
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'risk_term', 'recommended_term', 'guidelines' ) );
		foreach ( $items as $item ) {
			fputcsv(
				$out,
				array(
					isset( $item['risk_term'] ) ? $item['risk_term'] : '',
					isset( $item['recommended_term'] ) ? $item['recommended_term'] : '',
					isset( $item['guidelines'] ) ? $item['guidelines'] : '',
				)
			);
		}
		fclose( $out );
		exit;
	}
}
