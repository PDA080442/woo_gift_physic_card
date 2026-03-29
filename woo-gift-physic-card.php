<?php
/**
 * Plugin Name: Физические подарочные карты (WooCommerce)
 * Description: Привязка номинала подарочной карты к номеру физической карты из пула при покупке. Работает с PW WooCommerce Gift Cards Pro.
 * Version: 1.0.0
 * Author: Popravkin Danil
 * Text Domain: woo-gift-physic-card
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 *
 * Главный файл плагина. Здесь только константы и подключение папок includes/ и admin/.
 * Вся логика — в подпапках, чтобы корень не захламлялся.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Версия плагина и пути. Используются в includes и admin.
define( 'WGPC_VERSION', '1.0.0' );
define( 'WGPC_PLUGIN_FILE', __FILE__ );
define( 'WGPC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/*
 * При активации плагина нужно создать таблицу.
 * Сначала подключаем файл с именем таблицы (wgpc_get_table_name), потом файл установки.
 */
register_activation_hook( WGPC_PLUGIN_FILE, function () {
	require_once WGPC_PLUGIN_DIR . 'includes/wgpc-database.php';
	require_once WGPC_PLUGIN_DIR . 'includes/wgpc-install.php';
	wgpc_install_table();
} );

add_action( 'plugins_loaded', 'wgpc_init' );

/**
 * Инициализация после загрузки WordPress и других плагинов.
 */
function wgpc_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wgpc_woocommerce_missing_notice' );
		return;
	}

	// Функция имени таблицы нужна везде (админка, обработка заказов).
	require_once WGPC_PLUGIN_DIR . 'includes/wgpc-database.php';
	require_once WGPC_PLUGIN_DIR . 'includes/wgpc-install.php';
	wgpc_maybe_add_currency_code_column();
	wgpc_maybe_add_balance_column();
	wgpc_maybe_add_exported_column();

	// Совместимость с PW: если в таблице pimwick_gift_card нет столбца recipient_name — добавить (один раз).
	require_once WGPC_PLUGIN_DIR . 'includes/wgpc-pw-compat.php';
	wgpc_ensure_pw_recipient_name_column();

	// Синхронизация остатка физической карты с балансом из PW Gift Cards.
	require_once WGPC_PLUGIN_DIR . 'includes/wgpc-balance-sync.php';
	wgpc_register_balance_sync_hooks();

	// Обработчик заказа: при «Выполнен» подставляем физическую карту из пула (приоритет 9, до PW).
	require_once WGPC_PLUGIN_DIR . 'includes/class-wgpc-order-handler.php';
	new WGPC_Order_Handler();

	// Классы импорта/экспорта нужны и для REST API (запросы идут не из админки), и для админки.
	require_once WGPC_PLUGIN_DIR . 'includes/class-wgpc-import-1c.php';
	require_once WGPC_PLUGIN_DIR . 'includes/class-wgpc-export-1c.php';

	// REST API для обмена с 1С. Подключаем всегда.
	require_once WGPC_PLUGIN_DIR . 'includes/class-wgpc-rest-api.php';
	new WGPC_REST_API();

	// Админка: пункт меню и страница «Физические карты» — только в бэкенде.
	if ( is_admin() ) {
		require_once WGPC_PLUGIN_DIR . 'admin/class-wgpc-admin.php';
		new WGPC_Admin();
	}
}

/**
 * Сообщение в админке, если WooCommerce не активен.
 */
function wgpc_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e( 'Плагин «Физические подарочные карты» требует WooCommerce. Установите и активируйте WooCommerce.', 'woo-gift-physic-card' );
			?>
		</p>
	</div>
	<?php
}
