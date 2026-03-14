<?php
/**
 * Каркас REST API для обмена с 1С.
 * На этом шаге только подключаем модуль и точку расширения под маршруты.
 *
 * @package Woo_Gift_Physic_Card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс REST API плагина.
 *
 * На текущем шаге:
 * - подключается к rest_api_init;
 * - подготавливает место под будущие маршруты;
 * - не регистрирует реальные endpoint'ы до следующего шага.
 */
class WGPC_REST_API {

	/**
	 * Подписываемся на инициализацию WordPress REST API.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Регистрирует REST-маршруты плагина.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'wgpc/v1',
			'/cards/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'check_import_permission' ),
				'callback'            => array( $this, 'handle_cards_import' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			'wgpc/v1',
			'/cards/exports',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this, 'check_import_permission' ),
				'callback'            => array( $this, 'handle_cards_export' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 1000,
						'minimum'           => 1,
						'maximum'           => 5000,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'wgpc/v1',
			'/cards/exports/ack',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'check_import_permission' ),
				'callback'            => array( $this, 'handle_cards_export_ack' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Проверка доступа: ограничение по IP (если задано), сверка токена из заголовка с настройками.
	 *
	 * Токен передаётся в заголовке X-WGPC-Token или Authorization: Bearer &lt;token&gt;.
	 * Неудачные попытки логируются через error_log (попадают в debug.log при WP_DEBUG_LOG).
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return bool|WP_Error true при успехе, WP_Error при отказе.
	 */
	public function check_import_permission( $request ) {
		if ( get_option( 'wgpc_rest_enabled', '1' ) !== '1' && get_option( 'wgpc_rest_enabled', '1' ) !== true ) {
			$this->log_rest_error( $request, 'wgpc_rest_disabled', 'REST-обмен с 1С отключён в настройках.' );
			return new WP_Error(
				'wgpc_rest_disabled',
				__( 'REST-обмен отключён в настройках плагина.', 'woo-gift-physic-card' ),
				array( 'status' => 503 )
			);
		}

		$client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		$allowed_ips_raw = get_option( 'wgpc_rest_allowed_ips', '' );
		if ( $allowed_ips_raw !== '' ) {
			$allowed_ips = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $allowed_ips_raw ) ) );
			if ( ! empty( $allowed_ips ) && ! in_array( $client_ip, $allowed_ips, true ) ) {
				$this->log_rest_error( $request, 'wgpc_ip_not_allowed', sprintf( 'IP не в белом списке: %s', $client_ip ) );
				return new WP_Error(
					'wgpc_ip_not_allowed',
					__( 'Доступ с вашего IP запрещён.', 'woo-gift-physic-card' ),
					array( 'status' => 403 )
				);
			}
		}

		$saved_token = get_option( 'wgpc_rest_token', '' );
		if ( $saved_token === '' ) {
			$this->log_rest_error( $request, 'wgpc_token_not_configured', 'REST-токен не настроен на сайте.' );
			return new WP_Error(
				'wgpc_token_not_configured',
				__( 'REST-токен не настроен. Укажите токен в настройках плагина.', 'woo-gift-physic-card' ),
				array( 'status' => 503 )
			);
		}

		$token = $request->get_header( 'X-WGPC-Token' );
		if ( $token === null || $token === '' ) {
			$auth = $request->get_header( 'Authorization' );
			if ( is_string( $auth ) && preg_match( '/^\s*Bearer\s+(.+)$/i', $auth, $m ) ) {
				$token = trim( $m[1] );
			}
		}
		if ( $token === null || $token === '' ) {
			$this->log_rest_error( $request, 'wgpc_missing_token', 'Токен не передан в запросе.' );
			return new WP_Error(
				'wgpc_missing_token',
				__( 'Токен не передан. Укажите заголовок X-WGPC-Token или Authorization: Bearer &lt;token&gt;.', 'woo-gift-physic-card' ),
				array( 'status' => 401 )
			);
		}

		if ( ! hash_equals( (string) $saved_token, (string) $token ) ) {
			$this->log_rest_error( $request, 'wgpc_invalid_token', 'Неверный токен.' );
			return new WP_Error(
				'wgpc_invalid_token',
				__( 'Неверный токен.', 'woo-gift-physic-card' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Логирует ошибку REST API: в error_log (debug.log при WP_DEBUG_LOG) и в опцию wgpc_rest_log для отображения в админке.
	 *
	 * @param WP_REST_Request $request Запрос (для метода, маршрута, IP).
	 * @param string          $code    Код ошибки.
	 * @param string          $message Сообщение.
	 * @return void
	 */
	private function log_rest_error( $request, $code, $message ) {
		$method = $request->get_method();
		$route  = $request->get_route();
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '-';
		$line   = sprintf( '[WGPC REST] %s %s | IP: %s | %s (%s)', $method, $route, $ip, $message, $code );
		error_log( $line );

		$entries = get_option( 'wgpc_rest_log', array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}
		array_unshift( $entries, array(
			'time'    => current_time( 'Y-m-d H:i:s' ),
			'method'  => $method,
			'route'   => $route,
			'ip'      => $ip,
			'code'    => $code,
			'message' => $message,
		) );
		$entries = array_slice( $entries, 0, 50 );
		update_option( 'wgpc_rest_log', $entries );
	}

	/**
	 * Обработчик POST /wp-json/wgpc/v1/cards/import.
	 *
	 * Ожидаемый JSON: { "cards": [ { "external_id", "card_number", "nominal", "status_1c" }, ... ] }
	 * Ответ: { "success": true, "inserted", "updated", "skipped", "errors": [] }
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_cards_import( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$this->log_rest_error( $request, 'wgpc_invalid_body', __( 'Пустой или некорректный JSON. Ожидается поле cards (массив).', 'woo-gift-physic-card' ) );
			return new WP_Error(
				'wgpc_invalid_body',
				__( 'Тело запроса должно быть JSON с полем cards (массив).', 'woo-gift-physic-card' ),
				array( 'status' => 400 )
			);
		}

		$cards = isset( $body['cards'] ) && is_array( $body['cards'] ) ? $body['cards'] : array();
		$result = WGPC_Import_1C::import_cards( $cards );

		if ( ! empty( $result['errors'] ) ) {
			$err_list = array_values( $result['errors'] );
			$preview  = implode( '; ', array_slice( $err_list, 0, 5 ) );
			if ( count( $err_list ) > 5 ) {
				$preview .= ' … (+' . ( count( $err_list ) - 5 ) . ')';
			}
			$this->log_rest_error( $request, 'wgpc_import_errors', sprintf( __( 'Импорт: ошибки записи — %d шт. %s', 'woo-gift-physic-card' ), count( $err_list ), $preview ) );
		}

		$success = empty( $result['errors'] );

		return new WP_REST_Response(
			array(
				'success'  => $success,
				'inserted' => (int) $result['inserted'],
				'updated'  => (int) $result['updated'],
				'skipped'  => (int) $result['skipped'],
				'errors'   => array_values( $result['errors'] ),
			),
			$success ? 200 : 207
		);
	}

	/**
	 * Обработчик GET /wp-json/wgpc/v1/cards/exports.
	 *
	 * Возвращает карты со статусом sold или activated и непустым external_id.
	 * Ответ: { "cards": [ { "external_id", "card_number", "status", "order_id", "activated_at" }, ... ] }
	 *
	 * @param WP_REST_Request $request Запрос (опционально limit в query).
	 * @return WP_REST_Response
	 */
	public function handle_cards_export( $request ) {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit < 1 ) {
			$limit = 1000;
		}
		if ( $limit > 5000 ) {
			$limit = 5000;
		}

		$only_not_exported = get_option( 'wgpc_rest_export_only_new', '0' ) === '1';
		$rows = WGPC_Export_1C::get_cards_for_export( $limit, $only_not_exported );
		$cards = array();

		foreach ( $rows as $row ) {
			$cards[] = array(
				'external_id'   => isset( $row['external_id'] ) ? (string) $row['external_id'] : '',
				'card_number'   => isset( $row['card_number'] ) ? (string) $row['card_number'] : '',
				'status'        => isset( $row['status'] ) ? (string) $row['status'] : '',
				'order_id'      => isset( $row['order_id'] ) && $row['order_id'] !== null ? (int) $row['order_id'] : null,
				'activated_at'  => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
			);
		}

		return new WP_REST_Response( array( 'cards' => $cards ), 200 );
	}

	/**
	 * Обработчик POST /wp-json/wgpc/v1/cards/exports/ack.
	 *
	 * 1С отправляет список external_id, которые обработала. Сайт помечает карты как выгруженные
	 * (заполняет exported_to_1c_at). Ожидаемый JSON: { "external_ids": ["1c-001", "1c-002"] }
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_cards_export_ack( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$this->log_rest_error( $request, 'wgpc_invalid_body', __( 'Пустой или некорректный JSON. Ожидается поле external_ids (массив).', 'woo-gift-physic-card' ) );
			return new WP_Error(
				'wgpc_invalid_body',
				__( 'Тело запроса должно быть JSON с полем external_ids (массив).', 'woo-gift-physic-card' ),
				array( 'status' => 400 )
			);
		}

		$external_ids = isset( $body['external_ids'] ) && is_array( $body['external_ids'] ) ? $body['external_ids'] : array();
		$external_ids = array_map( 'trim', array_map( 'strval', $external_ids ) );
		$external_ids = array_filter( $external_ids, function ( $id ) {
			return $id !== '' && strlen( $id ) <= 64;
		} );
		$external_ids = array_values( array_unique( $external_ids ) );

		if ( empty( $external_ids ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'updated'  => 0,
					'message'  => __( 'Нет идентификаторов для подтверждения.', 'woo-gift-physic-card' ),
				),
				200
			);
		}

		global $wpdb;
		$table_name   = wgpc_get_table_name();
		$now          = current_time( 'mysql' );
		$placeholders = implode( ', ', array_fill( 0, count( $external_ids ), '%s' ) );
		$sql          = $wpdb->prepare(
			"UPDATE $table_name SET exported_to_1c_at = %s WHERE external_id IN ($placeholders)",
			array_merge( array( $now ), $external_ids )
		);
		$updated = $wpdb->query( $sql );

		if ( $wpdb->last_error ) {
			$this->log_rest_error( $request, 'wgpc_export_ack_db_error', sprintf( __( 'Ошибка подтверждения выгрузки (БД): %s', 'woo-gift-physic-card' ), $wpdb->last_error ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'updated'  => $updated !== false ? (int) $updated : 0,
			),
			200
		);
	}
}

