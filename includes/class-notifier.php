<?php
/**
 * 通知通道：通用 Webhook 与飞书。
 *
 * @package WPRiskScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 扫描完成后按配置发送通知。
 */
class WPRS_Notifier {

	/**
	 * 注册 AJAX。
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_wprs_notify_test', array( $this, 'ajax_test' ) );
	}

	/**
	 * 构建最小固定 JSON 载荷。
	 *
	 * @param int                  $violations 违规文章数。
	 * @param int                  $scanned    扫描篇数。
	 * @param array<string, mixed> $extra      附加字段。
	 * @return array<string, mixed>
	 */
	public static function build_payload( $violations, $scanned = 0, $extra = array() ) {
		$payload = array(
			'event'      => 'risk_scan_completed',
			'site'       => home_url( '/' ),
			'scanned'    => (int) $scanned,
			'violations' => (int) $violations,
			'time'       => gmdate( 'c' ),
			'items'      => array(),
			'admin_url'  => admin_url( 'admin.php?page=wprs-risk-scanner' ),
		);
		if ( is_array( $extra ) ) {
			$payload = array_merge( $payload, $extra );
		}
		return $payload;
	}

	/**
	 * 若开启通知且有违规则发送（定时/全站扫共用）。
	 *
	 * @param int  $violations 违规数。
	 * @param int  $scanned    扫描篇数。
	 * @param bool $force      强制发送（测试用，忽略开关但仍校验 URL）。
	 * @return bool|WP_Error
	 */
	public static function maybe_notify( $violations, $scanned = 0, $force = false ) {
		$settings = WPRS_Settings::get();
		if ( ! $force && empty( $settings['enable_notification'] ) ) {
			return false;
		}
		if ( ! $force && (int) $violations <= 0 ) {
			return false;
		}

		$payload = self::build_payload( $violations, $scanned );
		$channel = $settings['notification_channel'];

		if ( 'feishu' === $channel ) {
			return self::send_feishu( $payload, $settings, $force );
		}
		return self::send_webhook( $payload, $settings );
	}

	/**
	 * 发送通用 Webhook。
	 *
	 * @param array<string, mixed> $payload  载荷。
	 * @param array<string, mixed> $settings 设置。
	 * @return true|WP_Error
	 */
	public static function send_webhook( $payload, $settings ) {
		$url = isset( $settings['webhook_url'] ) ? trim( (string) $settings['webhook_url'] ) : '';
		if ( '' === $url ) {
			return new WP_Error( 'wprs_webhook_empty', __( 'Webhook URL 为空。', 'wp-risk-scanner' ) );
		}
		$resp = wp_remote_post(
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'wprs_webhook_http', sprintf( /* translators: %d: http code */ __( 'Webhook 返回 HTTP %d', 'wp-risk-scanner' ), $code ) );
		}
		return true;
	}

	/**
	 * 发送飞书机器人消息。
	 *
	 * @param array<string, mixed> $payload  通用载荷。
	 * @param array<string, mixed> $settings 设置。
	 * @param bool                 $force    强制。
	 * @return true|WP_Error
	 */
	public static function send_feishu( $payload, $settings, $force = false ) {
		unset( $force );
		$webhook = isset( $settings['feishu_webhook'] ) ? trim( (string) $settings['feishu_webhook'] ) : '';
		$secret  = isset( $settings['feishu_secret'] ) ? (string) $settings['feishu_secret'] : '';
		if ( '' === $webhook ) {
			return new WP_Error( 'wprs_feishu_empty', __( '飞书 Webhook 为空。', 'wp-risk-scanner' ) );
		}

		$violations = (int) $payload['violations'];
		$scan_time  = current_time( 'mysql' );
		$scan_url   = admin_url( 'admin.php?page=wprs-risk-scanner' );

		$body = array(
			'msg_type' => 'interactive',
			'card'     => array(
				'header'   => array(
					'title'    => array(
						'tag'     => 'plain_text',
						'content' => __( '风险词扫描提醒', 'wp-risk-scanner' ),
					),
					'template' => 'red',
				),
				'elements' => array(
					array(
						'tag'  => 'div',
						'text' => array(
							'tag'     => 'lark_md',
							'content' => sprintf(
								"**扫描时间：** %s\n**违规状态：** 发现文章 **%d** 篇包含违禁词，请及时核查。",
								$scan_time,
								$violations
							),
						),
					),
					array(
						'tag'     => 'action',
						'actions' => array(
							array(
								'tag'  => 'button',
								'text' => array(
									'tag'     => 'plain_text',
									'content' => __( '前往后台处理', 'wp-risk-scanner' ),
								),
								'type' => 'primary',
								'url'  => $scan_url,
							),
						),
					),
				),
			),
		);

		if ( '' !== $secret ) {
			$timestamp   = (string) time();
			$sign_string = $timestamp . "\n" . $secret;
			// 与飞书机器人签名算法一致。
			$sign                 = base64_encode( hash_hmac( 'sha256', '', $sign_string, true ) );
			$body['timestamp']    = $timestamp;
			$body['sign']         = $sign;
		}

		$resp = wp_remote_post(
			$webhook,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'wprs_feishu_http', sprintf( /* translators: %d: http code */ __( '飞书返回 HTTP %d', 'wp-risk-scanner' ), $code ) );
		}
		return true;
	}

	/**
	 * 手动测试通知。
	 *
	 * @return void
	 */
	public function ajax_test() {
		if ( ! current_user_can( WPRS_Settings::cap_sensitive() ) ) {
			wp_send_json_error( array( 'message' => __( '无权限', 'wp-risk-scanner' ) ), 403 );
		}
		check_ajax_referer( 'wprs_scan_ajax', 'nonce' );

		$count  = WPRS_Scanner::count_with_risk();
		$result = self::maybe_notify( max( 1, $count ), 0, true );
		if ( true === $result ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %d: violations */
						__( '测试通知已发送（当前违规文章数：%d）。', 'wp-risk-scanner' ),
						$count
					),
				)
			);
		}
		$msg = is_wp_error( $result ) ? $result->get_error_message() : __( '发送失败', 'wp-risk-scanner' );
		wp_send_json_error( array( 'message' => $msg ) );
	}
}
