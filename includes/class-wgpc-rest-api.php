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
	}

	/**
	 * Проверка доступа к импорту: сверка токена из заголовка с сохранённым в настройках.
	 *
	 * Токен передаётся в заголовке X-WGPC-Token или Authorization: Bearer &lt;token&gt;.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return bool|WP_Error true при успехе, WP_Error при отказе.
	 */
	public function check_import_permission( $request ) {
		$saved_token = get_option( 'wgpc_rest_token', '' );
		if ( $saved_token === '' ) {
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
			return new WP_Error(
				'wgpc_missing_token',
				__( 'Токен не передан. Укажите заголовок X-WGPC-Token или Authorization: Bearer &lt;token&gt;.', 'woo-gift-physic-card' ),
				array( 'status' => 401 )
			);
		}

		if ( ! hash_equals( (string) $saved_token, (string) $token ) ) {
			return new WP_Error(
				'wgpc_invalid_token',
				__( 'Неверный токен.', 'woo-gift-physic-card' ),
				array( 'status' => 403 )
			);
		}

		return true;
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
			return new WP_Error(
				'wgpc_invalid_body',
				__( 'Тело запроса должно быть JSON с полем cards (массив).', 'woo-gift-physic-card' ),
				array( 'status' => 400 )
			);
		}

		$cards = isset( $body['cards'] ) && is_array( $body['cards'] ) ? $body['cards'] : array();
		$result = WGPC_Import_1C::import_cards( $cards );

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
}

