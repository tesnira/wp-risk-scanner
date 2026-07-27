<?php
/**
 * 保存拦截：Gutenberg REST / Elementor / Yoast 快照。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 仅在设置开启「保存拦截」时注册钩子。
 */
class WPRS_Interceptor {

	const META_YOAST_SNAPSHOT = '_wprs_risk_yoast_snapshot';

	/**
	 * 注册钩子。
	 *
	 * @return void
	 */
	public function register() {
		// 保存成功后延迟单篇重扫：与拦截开关无关，保证风险列表及时更新。
		add_action( 'save_post', array( $this, 'on_save_post_schedule_scan' ), 99, 2 );
		add_action( 'elementor/document/after_save', array( $this, 'on_elementor_after_save_schedule' ), 20, 1 );
		add_action( 'rest_api_init', array( $this, 'register_rest_schedule_hooks' ), 6 );

		if ( ! WPRS_Settings::is_intercept_enabled() ) {
			return;
		}
		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_action( 'init', array( $this, 'register_rest_filters' ), 25 );
		add_action( 'elementor/document/before_save', array( $this, 'elementor_before_save' ), 10, 2 );
		add_action( 'elementor/document/after_save', array( $this, 'elementor_after_save_sync_whitelist' ), 10, 2 );
		add_action( 'elementor/element/wp-post/document_settings/before_section_end', array( $this, 'elementor_add_whitelist_control' ) );
		add_action( 'elementor/element/wp-page/document_settings/before_section_end', array( $this, 'elementor_add_whitelist_control' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ), 20 );
		add_action( 'add_meta_boxes', array( $this, 'add_classic_metabox' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_classic_metabox' ) );
		add_action( 'updated_post_meta', array( $this, 'on_meta_updated' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'on_meta_updated' ), 10, 4 );
		add_action( 'rest_api_init', array( $this, 'register_rest_after_insert_sync' ), 5 );
		add_action( 'elementor/editor/before_enqueue_scripts', array( $this, 'seed_elementor_whitelist_from_native' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'suppress_elementor_known_rejection' ), 20 );
	}

	/**
	 * 压制 Elementor 拦截时控制台的已知 unhandledrejection（不影响 toast）。
	 *
	 * @return void
	 */
	public function suppress_elementor_known_rejection() {
		$handle = 'elementor-editor';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}
		$js = <<<'JSEOF'
(function(){
	if(typeof window==='undefined'||!window.addEventListener)return;
	window.addEventListener('unhandledrejection',function(event){
		try{
			var r=event&&event.reason;
			var msg='';
			if(typeof r==='string'){msg=r;}
			else if(r&&typeof r.message==='string'){msg=r.message;}
			if(!msg)return;
			if(msg.indexOf('保存失败：')!==-1&&msg.indexOf('本文违禁词白名单')!==-1){
				event.preventDefault();
			}
		}catch(e){}
	});
})();
JSEOF;
		wp_add_inline_script( $handle, $js, 'after' );
	}

	/**
	 * REST 保存后调度单篇扫描。
	 *
	 * @return void
	 */
	public function register_rest_schedule_hooks() {
		foreach ( $this->rest_post_types() as $pt ) {
			add_action( "rest_after_insert_{$pt}", array( $this, 'rest_after_insert_schedule_scan' ), 20, 3 );
		}
	}

	/**
	 * REST 保存结束后调度扫描。
	 *
	 * @param WP_Post         $post     文章。
	 * @param WP_REST_Request $request  请求。
	 * @param bool            $creating 是否新建。
	 * @return void
	 */
	public function rest_after_insert_schedule_scan( $post, $request, $creating ) {
		unset( $request, $creating );
		if ( $post instanceof WP_Post ) {
			self::schedule_single_scan( (int) $post->ID );
		}
	}

	/**
	 * save_post 调度扫描。
	 *
	 * @param int     $post_id 文章 ID。
	 * @param WP_Post $post    文章对象。
	 * @return void
	 */
	public function on_save_post_schedule_scan( $post_id, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( ! in_array( $post->post_type, WPRS_Scanner::eligible_post_types(), true ) ) {
			return;
		}
		self::schedule_single_scan( (int) $post_id );
	}

	/**
	 * Elementor 保存后调度扫描。
	 *
	 * @param mixed $document 文档。
	 * @return void
	 */
	public function on_elementor_after_save_schedule( $document ) {
		if ( $document && method_exists( $document, 'get_main_id' ) ) {
			self::schedule_single_scan( (int) $document->get_main_id() );
		}
	}

	/**
	 * 延迟到 shutdown 再扫单篇（确保 meta 已落库；仅 publish）。
	 *
	 * @param int $post_id 文章 ID。
	 * @return void
	 */
	public static function schedule_single_scan( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'elementor_library' === get_post_type( $post_id ) ) {
			return;
		}

		static $queued = array();
		static $hooked = false;
		$queued[ $post_id ] = $post_id;

		if ( $hooked ) {
			return;
		}
		$hooked = true;
		add_action(
			'shutdown',
			static function () use ( &$queued ) {
				$ids = array_values( array_unique( array_map( 'intval', $queued ) ) );
				$publish_ids = array();
				foreach ( $ids as $id ) {
					if ( 'publish' === get_post_status( $id ) && in_array( get_post_type( $id ), WPRS_Scanner::eligible_post_types(), true ) ) {
						$publish_ids[] = $id;
					}
				}
				if ( $publish_ids ) {
					WPRS_Scanner::scan_posts( $publish_ids );
				}
			}
		);
	}

	/**
	 * 古腾堡 REST 保存后：把原生白名单同步进 Elementor settings。
	 *
	 * @return void
	 */
	public function register_rest_after_insert_sync() {
		foreach ( $this->rest_post_types() as $pt ) {
			add_action( "rest_after_insert_{$pt}", array( $this, 'rest_after_insert_sync_whitelist' ), 10, 3 );
		}
	}

	/**
	 * REST 保存结束后统一对齐双端白名单。
	 *
	 * @param WP_Post         $post     文章。
	 * @param WP_REST_Request $request  请求。
	 * @param bool            $creating 是否新建。
	 * @return void
	 */
	public function rest_after_insert_sync_whitelist( $post, $request, $creating ) {
		unset( $request, $creating );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$post_id = (int) $post->ID;
		if ( $post_id <= 0 ) {
			return;
		}
		$native = get_post_meta( $post_id, WPRS_Scanner::META_WHITELIST, true );
		$native = is_string( $native ) ? trim( $native ) : '';

		$el    = get_post_meta( $post_id, '_elementor_page_settings', true );
		$el_wl = '';
		if ( is_array( $el ) && array_key_exists( WPRS_Scanner::META_WHITELIST, $el ) ) {
			$el_wl = is_string( $el[ WPRS_Scanner::META_WHITELIST ] ) ? trim( $el[ WPRS_Scanner::META_WHITELIST ] ) : '';
		}

		// REST 场景以原生 meta（侧栏）为准；都空则清空对齐。
		$source = ( '' !== $native ) ? $native : $el_wl;
		WPRS_Scanner::sync_whitelist( $post_id, $source );
	}

	/**
	 * 非 REST 场景：meta 变更时双向同步。
	 *
	 * @param int    $meta_id    Meta ID。
	 * @param int    $post_id    文章 ID。
	 * @param string $meta_key   键名。
	 * @param mixed  $meta_value 值。
	 * @return void
	 */
	public function on_meta_updated( $meta_id, $post_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		if ( WPRS_Scanner::META_WHITELIST === $meta_key ) {
			WPRS_Scanner::sync_whitelist( $post_id, is_string( $meta_value ) ? $meta_value : '' );
			return;
		}

		if ( '_elementor_page_settings' === $meta_key && is_array( $meta_value ) && array_key_exists( WPRS_Scanner::META_WHITELIST, $meta_value ) ) {
			$el_wl           = is_string( $meta_value[ WPRS_Scanner::META_WHITELIST ] ) ? trim( $meta_value[ WPRS_Scanner::META_WHITELIST ] ) : '';
			$is_elementor_save = wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'elementor_ajax' === $_REQUEST['action']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// 非 Elementor 保存带来的空白名单，勿覆盖已有原生值。
			if ( '' === $el_wl && ! $is_elementor_save ) {
				$native = get_post_meta( $post_id, WPRS_Scanner::META_WHITELIST, true );
				$native = is_string( $native ) ? trim( $native ) : '';
				if ( '' !== $native ) {
					WPRS_Scanner::sync_whitelist( $post_id, $native );
				}
				return;
			}
			WPRS_Scanner::sync_whitelist( $post_id, $el_wl );
		}
	}

	/**
	 * 打开 Elementor 编辑器时：若其白名单为空则用原生 meta 回填。
	 *
	 * @return void
	 */
	public function seed_elementor_whitelist_from_native() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}
		$editor = \Elementor\Plugin::$instance->editor;
		if ( ! $editor || ! method_exists( $editor, 'get_post_id' ) ) {
			return;
		}
		$post_id = (int) $editor->get_post_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$native = get_post_meta( $post_id, WPRS_Scanner::META_WHITELIST, true );
		$native = is_string( $native ) ? trim( $native ) : '';
		$el     = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( ! is_array( $el ) ) {
			$el = array();
		}
		$el_wl = isset( $el[ WPRS_Scanner::META_WHITELIST ] ) ? trim( (string) $el[ WPRS_Scanner::META_WHITELIST ] ) : '';

		if ( '' === $el_wl && '' !== $native ) {
			WPRS_Scanner::sync_whitelist( $post_id, $native );
		} elseif ( '' === $native && '' !== $el_wl ) {
			WPRS_Scanner::sync_whitelist( $post_id, $el_wl );
		}
	}

	/**
	 * 需注册 meta 的文章类型（支持 REST，排除附件）。
	 *
	 * @return string[]
	 */
	private function rest_post_types() {
		$types = get_post_types( array( 'show_in_rest' => true ), 'names' );
		unset( $types['attachment'] );
		$eligible = WPRS_Scanner::eligible_post_types();
		$out      = array();
		foreach ( $types as $pt ) {
			if ( in_array( $pt, $eligible, true ) ) {
				$out[] = $pt;
			}
		}
		return $out ? $out : array( 'post', 'page' );
	}

	/**
	 * 注册 REST 可见 meta（按文章类型）。
	 *
	 * @return void
	 */
	public function register_meta() {
		$auth = static function () {
			return current_user_can( 'edit_posts' );
		};
		foreach ( $this->rest_post_types() as $pt ) {
			register_post_meta(
				$pt,
				self::META_YOAST_SNAPSHOT,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => $auth,
					'sanitize_callback' => 'sanitize_textarea_field',
				)
			);
			register_post_meta(
				$pt,
				WPRS_Scanner::META_WHITELIST,
				array(
					'type'              => 'string',
					'description'       => 'WP Risk Scanner whitelist',
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => $auth,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}
	}

	/**
	 * Elementor 文档设置：白名单控件。
	 *
	 * @param mixed $document Elementor 文档控件对象。
	 * @return void
	 */
	public function elementor_add_whitelist_control( $document ) {
		if ( ! class_exists( '\Elementor\Controls_Manager' ) || ! is_object( $document ) || ! method_exists( $document, 'add_control' ) ) {
			return;
		}
		$document->add_control(
			WPRS_Scanner::META_WHITELIST,
			array(
				'label'       => __( '本文违禁词白名单', 'wp-risk-scanner' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'description' => __( '保存被拦截时，可将确认为误报的词填在此处后再次更新（多个词用顿号、分隔）。', 'wp-risk-scanner' ),
				'label_block' => true,
			)
		);
	}

	/**
	 * 经典编辑器 Meta Box（非区块编辑器时）。
	 *
	 * @param string  $post_type 类型。
	 * @param WP_Post $post      文章。
	 * @return void
	 */
	public function add_classic_metabox( $post_type, $post ) {
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}
		if ( ! in_array( $post_type, WPRS_Scanner::eligible_post_types(), true ) ) {
			return;
		}
		add_meta_box(
			'wprs_risk_whitelist',
			__( '本文违禁词白名单', 'wp-risk-scanner' ),
			array( $this, 'render_classic_metabox' ),
			$post_type,
			'side',
			'low'
		);
	}

	/**
	 * 渲染经典编辑器白名单框。
	 *
	 * @param WP_Post $post 文章。
	 * @return void
	 */
	public function render_classic_metabox( $post ) {
		$value = get_post_meta( $post->ID, WPRS_Scanner::META_WHITELIST, true );
		wp_nonce_field( 'wprs_classic_whitelist', 'wprs_classic_whitelist_nonce' );
		echo '<p>' . esc_html__( '保存被拦截时，可将确认为误报的词填在此处后再次发布（多个词用顿号、分隔）。', 'wp-risk-scanner' ) . '</p>';
		printf(
			'<textarea style="width:100%%;height:80px;" name="wprs_risk_whitelist_field">%s</textarea>',
			esc_textarea( is_string( $value ) ? $value : '' )
		);
	}

	/**
	 * 保存经典编辑器白名单。
	 *
	 * @param int $post_id 文章 ID。
	 * @return void
	 */
	public function save_classic_metabox( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['wprs_classic_whitelist_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wprs_classic_whitelist_nonce'] ) ), 'wprs_classic_whitelist' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['wprs_risk_whitelist_field'] ) ) {
			return;
		}
		$value = sanitize_text_field( wp_unslash( $_POST['wprs_risk_whitelist_field'] ) );
		WPRS_Scanner::sync_whitelist( $post_id, '' === $value ? array() : explode( '、', $value ) );
	}

	/**
	 * 为可扫描文章类型注册 REST 保存前拦截。
	 *
	 * @return void
	 */
	public function register_rest_filters() {
		foreach ( WPRS_Scanner::eligible_post_types() as $pt ) {
			add_filter( "rest_pre_insert_{$pt}", array( $this, 'rest_pre_insert' ), 10, 2 );
		}
	}

	/**
	 * Gutenberg / REST 保存拦截。
	 *
	 * @param WP_Post|stdClass        $prepared_post 待写入对象。
	 * @param WP_REST_Request         $request       请求。
	 * @return WP_Post|stdClass|WP_Error
	 */
	public function rest_pre_insert( $prepared_post, $request ) {
		$post_id = isset( $request['id'] ) ? (int) $request['id'] : 0;

		$title   = isset( $prepared_post->post_title ) ? $prepared_post->post_title : get_the_title( $post_id );
		$content = isset( $prepared_post->post_content ) ? $prepared_post->post_content : get_post_field( 'post_content', $post_id );
		$excerpt = isset( $prepared_post->post_excerpt ) ? $prepared_post->post_excerpt : get_post_field( 'post_excerpt', $post_id );
		$article = $title . "\n" . $content . "\n" . $excerpt;

		$req_meta    = $request->get_param( 'meta' );
		$yoast_extra = '';
		if ( is_array( $req_meta ) && isset( $req_meta[ self::META_YOAST_SNAPSHOT ] ) && is_string( $req_meta[ self::META_YOAST_SNAPSHOT ] ) ) {
			$yoast_extra = trim( $req_meta[ self::META_YOAST_SNAPSHOT ] );
		}
		if ( '' === $yoast_extra ) {
			$yoast_extra = WPRS_Scanner::collect_yoast_text( $post_id, is_array( $req_meta ) ? $req_meta : array() );
		}

		$target_status = '';
		if ( isset( $prepared_post->post_status ) && '' !== (string) $prepared_post->post_status ) {
			$target_status = (string) $prepared_post->post_status;
		}
		if ( '' === $target_status ) {
			$req_status = $request->get_param( 'status' );
			if ( is_string( $req_status ) && '' !== $req_status ) {
				$target_status = $req_status;
			}
		}
		if ( '' === $target_status && $post_id > 0 ) {
			$target_status = (string) get_post_status( $post_id );
		}
		if ( 'publish' !== $target_status ) {
			return $prepared_post;
		}

		$editor_whitelist = null;
		if ( is_array( $req_meta ) && array_key_exists( WPRS_Scanner::META_WHITELIST, $req_meta ) ) {
			$editor_whitelist = sanitize_text_field( (string) $req_meta[ WPRS_Scanner::META_WHITELIST ] );
		}

		$el_data = is_array( $req_meta ) && array_key_exists( '_elementor_data', $req_meta )
			? $req_meta['_elementor_data']
			: get_post_meta( $post_id, '_elementor_data', true );
		$el_plain = $this->elementor_to_text( $el_data );
		if ( '' !== $el_plain ) {
			$article .= "\n" . $el_plain;
		}

		$check = self::check_content(
			$post_id,
			array(
				'article' => $article,
				'yoast'   => $yoast_extra,
			),
			$editor_whitelist
		);
		if ( ! empty( $check['has_error'] ) ) {
			return new WP_Error( 'wprs_risk_forbidden', $check['message'], array( 'status' => 403 ) );
		}
		return $prepared_post;
	}

	/**
	 * Elementor 保存前拦截。
	 *
	 * @param mixed                $document 文档。
	 * @param array<string, mixed> $data     本次保存数据。
	 * @return void
	 * @throws Exception 拦截时抛出。
	 */
	public function elementor_before_save( $document, $data ) {
		if ( ! $document ) {
			return;
		}
		if ( ! wp_doing_ajax() || ! isset( $_REQUEST['action'] ) || 'elementor_ajax' !== $_REQUEST['action'] ) {
			return;
		}

		$post_id = (int) $document->get_main_id();
		$types   = WPRS_Scanner::eligible_post_types();
		$ptype   = get_post_type( $post_id );
		if ( $ptype && ! in_array( $ptype, $types, true ) ) {
			return;
		}

		$target_status = null;
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			if ( isset( $data['settings']['post_status'] ) ) {
				$target_status = (string) $data['settings']['post_status'];
			} elseif ( isset( $data['settings']['postStatus'] ) ) {
				$target_status = (string) $data['settings']['postStatus'];
			}
		}
		if ( null === $target_status ) {
			$target_status = (string) get_post_field( 'post_status', $post_id );
		}
		if ( 'publish' !== $target_status ) {
			return;
		}

		// 对齐主题：before_save 时库未写入，内容只从 $data 取；不读旧 post_content
		//（Elementor 页实际内容在 elements 里，旧 post_content 可能含已删文字导致误拦）。
		$el_json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );

		$post_title = '';
		if ( isset( $data['settings']['post_title'] ) ) {
			$post_title = (string) $data['settings']['post_title'];
		}
		if ( '' === $post_title ) {
			$post_title = get_the_title( $post_id );
		}

		$excerpt = '';
		if ( isset( $data['settings']['post_excerpt'] ) ) {
			$excerpt = (string) $data['settings']['post_excerpt'];
		}
		if ( '' === $excerpt ) {
			$excerpt = (string) get_post_field( 'post_excerpt', $post_id );
		}

		$article = $post_title . "\n" . $excerpt . "\n" . $el_json;

		// 对齐主题语义：string（含空串）= 编辑器权威白名单；null = 未携带，check_content 读库。
		// 主题用 isset；此处用 array_key_exists，并补 Elementor 清空后 omit 默认空值的情况。
		$editor_whitelist = $this->resolve_elementor_editor_whitelist( $data );

		$check = self::check_content(
			$post_id,
			array(
				'article' => $article,
				'yoast'   => '',
			),
			$editor_whitelist
		);
		if ( ! empty( $check['has_error'] ) ) {
			throw new Exception( ! empty( $check['html_message'] ) ? $check['html_message'] : nl2br( esc_html( $check['message'] ) ) );
		}
	}

	/**
	 * Elementor 保存成功后：把本次权威白名单（含空串清空）写回双端 meta。
	 * Elementor 对空控件常 omit，若不主动同步，库里会残留旧白名单。
	 *
	 * @param mixed                $document 文档。
	 * @param array<string, mixed> $data     本次保存数据。
	 * @return void
	 */
	public function elementor_after_save_sync_whitelist( $document, $data ) {
		if ( ! $document || ! is_array( $data ) ) {
			return;
		}
		if ( ! wp_doing_ajax() || ! isset( $_REQUEST['action'] ) || 'elementor_ajax' !== $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$post_id = (int) $document->get_main_id();
		if ( $post_id <= 0 ) {
			return;
		}
		if ( ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return;
		}
		$wl = $this->resolve_elementor_editor_whitelist( $data );
		if ( null === $wl ) {
			return;
		}
		WPRS_Scanner::sync_whitelist( $post_id, $wl );
	}

	/**
	 * 解析 Elementor 本次保存的白名单（对齐主题 interceptor 语义）。
	 *
	 * @param array<string, mixed> $data before_save 数据。
	 * @return string|null string（含 ''）= 编辑器权威值；null = 未携带，允许读库。
	 */
	private function resolve_elementor_editor_whitelist( $data ) {
		$key = WPRS_Scanner::META_WHITELIST;

		// 主题：isset( settings[key] )。空串也算「已提交」。
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) && array_key_exists( $key, $data['settings'] ) ) {
			return sanitize_text_field( (string) $data['settings'][ $key ] );
		}

		// 部分版本清空后 $data['settings'] 不带该键，再从 elementor_ajax actions 取一次。
		$from_request = $this->extract_whitelist_from_elementor_request();
		if ( null !== $from_request ) {
			return $from_request;
		}

		// 补丁（主题未写、但 Elementor removeDefault 会 omit 空控件）：
		// 本次带了 settings 却无白名单键 → 视为空串清空，不得 null 回退读库旧值。
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			return '';
		}

		return null;
	}

	/**
	 * 从 Elementor AJAX 请求体解析白名单字段。
	 *
	 * @return string|null 找到则返回字符串（可为 ''）；未找到返回 null。
	 */
	private function extract_whitelist_from_elementor_request() {
		$key = WPRS_Scanner::META_WHITELIST;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = isset( $_REQUEST['actions'] ) ? wp_unslash( $_REQUEST['actions'] ) : '';
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$actions = json_decode( $raw, true );
		if ( ! is_array( $actions ) ) {
			return null;
		}

		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$data = isset( $action['data'] ) && is_array( $action['data'] ) ? $action['data'] : null;
			if ( ! $data ) {
				continue;
			}
			if ( isset( $data['settings'] ) && is_array( $data['settings'] ) && array_key_exists( $key, $data['settings'] ) ) {
				return sanitize_text_field( (string) $data['settings'][ $key ] );
			}
		}
		return null;
	}

	/**
	 * Elementor 数据转文本。
	 *
	 * @param mixed $el_data 数据。
	 * @return string
	 */
	private function elementor_to_text( $el_data ) {
		if ( empty( $el_data ) ) {
			return '';
		}
		if ( is_array( $el_data ) ) {
			$el_data = wp_json_encode( $el_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} elseif ( is_string( $el_data ) ) {
			$decoded = json_decode( $el_data, true );
			if ( is_array( $decoded ) ) {
				$el_data = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		}
		return wp_strip_all_tags( (string) $el_data );
	}

	/**
	 * 拦截用违禁词检测。
	 *
	 * @param int                       $post_id          文章 ID。
	 * @param string|array<string,string> $content_or_buckets 文本或分区。
	 * @param string|null               $editor_whitelist 编辑器白名单。
	 * @return array{has_error:bool,message?:string,html_message?:string}
	 */
	public static function check_content( $post_id, $content_or_buckets, $editor_whitelist = null ) {
		global $wpdb;
		$table = WPRS_Activator::table_terms();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all_terms = $wpdb->get_col( "SELECT risk_term FROM {$table}" );
		if ( empty( $all_terms ) ) {
			return array( 'has_error' => false );
		}

		$article_text = '';
		$yoast_text   = '';
		if ( is_array( $content_or_buckets ) ) {
			$article_text = isset( $content_or_buckets['article'] ) ? (string) $content_or_buckets['article'] : '';
			$yoast_text   = isset( $content_or_buckets['yoast'] ) ? (string) $content_or_buckets['yoast'] : '';
		} else {
			$article_text = (string) $content_or_buckets;
		}

		$raw_whitelist = array();
		if ( null !== $editor_whitelist ) {
			$wl = trim( (string) $editor_whitelist );
			if ( '' !== $wl ) {
				$raw_whitelist = explode( '、', $wl );
			}
		} else {
			$meta = get_post_meta( $post_id, WPRS_Scanner::META_WHITELIST, true );
			if ( ! empty( $meta ) ) {
				$raw_whitelist = array_merge( $raw_whitelist, explode( '、', (string) $meta ) );
			}
			$el_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
			if ( is_array( $el_settings ) && ! empty( $el_settings[ WPRS_Scanner::META_WHITELIST ] ) ) {
				$raw_whitelist = array_merge( $raw_whitelist, explode( '、', (string) $el_settings[ WPRS_Scanner::META_WHITELIST ] ) );
			}
			$results = WPRS_Activator::table_results();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$scan_row = $wpdb->get_row( $wpdb->prepare( "SELECT ignored_terms FROM {$results} WHERE post_id = %d", $post_id ) );
			if ( $scan_row && ! empty( $scan_row->ignored_terms ) ) {
				$history = json_decode( $scan_row->ignored_terms, true );
				if ( is_array( $history ) ) {
					$raw_whitelist = array_merge( $raw_whitelist, $history );
				}
			}
		}

		$ignored = array();
		foreach ( $raw_whitelist as $w ) {
			$w = trim( (string) $w );
			if ( '' !== $w ) {
				$ignored[] = $w;
			}
		}
		$ignored = array_values( array_unique( $ignored ) );

		$found_article = array();
		$found_yoast   = array();
		foreach ( $all_terms as $term ) {
			$term = (string) $term;
			if ( in_array( $term, $ignored, true ) ) {
				continue;
			}
			if ( '' !== $article_text && false !== stripos( $article_text, $term ) ) {
				$found_article[] = $term;
			}
			if ( '' !== $yoast_text && false !== stripos( $yoast_text, $term ) ) {
				$found_yoast[] = $term;
			}
		}
		$found_article = array_values( array_unique( $found_article ) );
		$found_yoast   = array_values( array_unique( $found_yoast ) );

		if ( ! $found_article && ! $found_yoast ) {
			return array( 'has_error' => false );
		}

		$text_lines = array();
		$html_lines = array();
		if ( $found_article ) {
			$text_lines[] = '【文章内容】存在违禁词：「' . implode( '、', $found_article ) . '」';
			$html_lines[] = '【文章内容】存在违禁词：<b style="color:#d63638;">' . esc_html( implode( '、', $found_article ) ) . '</b>';
		}
		if ( $found_yoast ) {
			$text_lines[] = '【Yoast内容】存在违禁词：「' . implode( '、', $found_yoast ) . '」';
			$html_lines[] = '【Yoast内容】存在违禁词：<b style="color:#d63638;">' . esc_html( implode( '、', $found_yoast ) ) . '</b>';
		}

		$message  = "保存失败：\n" . implode( "\n", $text_lines );
		$message .= "\n请修改后重试，或在右侧「文档」设置中的「本文违禁词白名单」加入误报词（顿号、分隔）后再保存。";

		$html_message  = '<b>保存失败：</b><br>' . implode( '<br>', $html_lines );
		$html_message .= '<br>请修改后重试，或在页面设置中的「本文违禁词白名单」加入误报词后再更新。';

		return array(
			'has_error'    => true,
			'message'      => $message,
			'html_message' => $html_message,
		);
	}

	/**
	 * 古腾堡：Yoast 快照中间件 + 错误通知换行。
	 *
	 * @return void
	 */
	public function enqueue_block_editor_assets() {
		$handle = 'wp-edit-post';
		if ( wp_style_is( $handle, 'registered' ) ) {
			wp_add_inline_style(
				$handle,
				'.components-notice.is-error .components-notice__content,.components-snackbar__content{white-space:pre-line;}'
			);
		}

		wp_enqueue_script(
			'wprs-yoast-bridge',
			WPRS_URL . 'assets/js/yoast-bridge.js',
			array( 'wp-data', 'wp-api-fetch', 'wp-dom-ready', 'wp-edit-post' ),
			WPRS_VERSION,
			true
		);
		wp_localize_script(
			'wprs-yoast-bridge',
			'WPRS_YOAST_BRIDGE',
			array(
				'metaKey'   => self::META_YOAST_SNAPSHOT,
				'yoastKeys' => WPRS_Scanner::yoast_meta_keys(),
			)
		);

		wp_enqueue_script(
			'wprs-whitelist-panel',
			WPRS_URL . 'assets/js/whitelist-panel.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data' ),
			WPRS_VERSION,
			true
		);
		wp_localize_script(
			'wprs-whitelist-panel',
			'WPRS_WHITELIST_PANEL',
			array(
				'metaKey' => WPRS_Scanner::META_WHITELIST,
				'title'   => __( '本文违禁词白名单', 'wp-risk-scanner' ),
				'help'    => __( '保存被拦截时，可将确认为误报的合法词填在此处并再次保存。多个词用顿号（、）分隔。', 'wp-risk-scanner' ),
			)
		);
	}
}
