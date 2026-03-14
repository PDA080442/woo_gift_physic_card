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
	 * На этом этапе метод оставляем пустым:
	 * реальные маршруты импорта и выгрузки добавим на следующем шаге.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Маршруты будут добавлены на следующем шаге разработки.
	}
}

