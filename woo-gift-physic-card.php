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
 * Плагин должен лежать в папке wp-content/plugins/woo_gift_physic_card/
 */

// Запрет прямого вызова: если файл открыли не через WordPress, переменная ABSPATH не определена — выходим.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Заголовок плагина. WordPress по нему находит плагин в списке «Плагины».
 * Plugin Name — обязательное поле, остальное по желанию.
 */
define( 'WGPC_VERSION', '1.0.0' );
define( 'WGPC_PLUGIN_FILE', __FILE__ );
define( 'WGPC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Создание таблицы физических карт при активации плагина.
 * Вызывается один раз при нажатии «Активировать» в админке.
 */
function wgpc_install_table() {
	// Проверка прав: активировать плагины может только администратор.
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	global $wpdb;

	// Имя таблицы: префикс сайта (обычно wp_) + наше имя. Итог: wp_mpgc_physical_cards
	$table_name      = $wpdb->prefix . 'mpgc_physical_cards';
	$charset_collate = $wpdb->get_charset_collate();

	// SQL для создания таблицы. dbDelta() очень требователен к формату:
	// каждое поле с новой строки, два пробела перед PRIMARY KEY, перед KEY.
	$sql = "CREATE TABLE $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		card_number varchar(128) NOT NULL,
		pin varchar(32) DEFAULT NULL,
		status varchar(32) NOT NULL DEFAULT 'available',
		nominal decimal(12,2) DEFAULT NULL,
		order_id bigint(20) unsigned DEFAULT NULL,
		order_item_id bigint(20) unsigned DEFAULT NULL,
		pimwick_gift_card_id int(10) unsigned DEFAULT NULL,
		external_id varchar(64) DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		notes text DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY card_number (card_number),
		KEY status (status),
		KEY order_id (order_id)
	) $charset_collate;";

	// Подключаем функцию dbDelta — она умеет создавать и обновлять таблицы по разнице с текущей БД.
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Сохраняем версию схемы в опциях WordPress. При следующих обновлениях плагина можно проверять
	// версию и при необходимости выполнять миграции (ALTER TABLE).
	update_option( 'mpgc_db_version', WGPC_VERSION );
}

// Регистрируем функцию wgpc_install_table на момент активации плагина.
register_activation_hook( WGPC_PLUGIN_FILE, 'wgpc_install_table' );

/**
 * Загрузка плагина только после инициализации WordPress и проверки WooCommerce.
 */
add_action( 'plugins_loaded', 'wgpc_init' );

function wgpc_init() {
	// WooCommerce нужен для работы с заказами. Если его нет — не грузим плагин и показываем предупреждение.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wgpc_woocommerce_missing_notice' );
		return;
	}

	// Здесь позже: подключение файлов админки, хуков на заказы и т.д.
	// require_once WGPC_PLUGIN_DIR . 'includes/class-wgpc-order-handler.php';
}

/**
 * Сообщение в админке, если WooCommerce не установлен или не активирован.
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
