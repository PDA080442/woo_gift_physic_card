<?php
/**
 * Создание таблицы при активации плагина.
 * Подключается только в момент activation hook.
 *
 * @package Woo_Gift_Physic_Card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Создаёт таблицу физических карт в БД.
 * Вызывается из register_activation_hook в главном файле плагина.
 */
function wgpc_install_table() {
	// Только администратор может активировать плагины.
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	global $wpdb;

	// Полное имя таблицы с префиксом (см. includes/wgpc-database.php).
	$table_name      = wgpc_get_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	// dbDelta() требует строгий формат: два пробела перед PRIMARY KEY и KEY.
	$sql = "CREATE TABLE $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		card_number varchar(128) NOT NULL,
		status varchar(32) NOT NULL DEFAULT 'available',
		nominal decimal(12,2) DEFAULT NULL,
		order_id bigint(20) unsigned DEFAULT NULL,
		order_item_id bigint(20) unsigned DEFAULT NULL,
		pimwick_gift_card_id int(10) unsigned DEFAULT NULL,
		external_id varchar(64) DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		exported_to_1c_at datetime DEFAULT NULL,
		notes text DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY card_number (card_number),
		KEY status (status),
		KEY order_id (order_id)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'mpgc_db_version', WGPC_VERSION );
}

/**
 * Добавляет поле exported_to_1c_at в таблицу, если его ещё нет (обновление существующих установок).
 * Вызывается из wgpc_init() при каждой загрузке.
 *
 * @return void
 */
function wgpc_maybe_add_exported_column() {
	global $wpdb;
	$table_name   = wgpc_get_table_name();
	$column       = 'exported_to_1c_at';
	$table_escaped = '`' . str_replace( '`', '``', $table_name ) . '`';
	$row          = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM $table_escaped LIKE %s", $column ), ARRAY_A );
	if ( empty( $row ) ) {
		$wpdb->query( "ALTER TABLE $table_escaped ADD COLUMN `$column` datetime DEFAULT NULL AFTER updated_at" );
	}
}
