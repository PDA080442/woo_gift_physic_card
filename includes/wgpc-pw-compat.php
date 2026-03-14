<?php
/**
 * Совместимость с таблицей PW Gift Cards: добавление столбцов, которых нет
 * (recipient_name, from, message и др.), если БД создана старой версией PW.
 *
 * @package Woo_Gift_Physic_Card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Список столбцов таблицы PW, которые могут отсутствовать в старой схеме. Имя => определение для ALTER. */
define(
	'WGPC_PW_TABLE_COLUMNS_COMPAT',
	array(
		'recipient_name' => 'VARCHAR(255) NULL DEFAULT NULL',
		'from'           => 'VARCHAR(255) NULL DEFAULT NULL',
		'message'        => 'TEXT NULL',
		'delivery_date'  => 'DATE NULL DEFAULT NULL',
		'email_design_id' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
		'recipient_email' => 'VARCHAR(255) NULL DEFAULT NULL',
		'pimwick_gift_card_parent' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
		'expiration_date' => 'DATE NULL DEFAULT NULL',
		'product_id'     => 'BIGINT UNSIGNED NULL DEFAULT NULL',
		'variation_id'   => 'BIGINT UNSIGNED NULL DEFAULT NULL',
		'order_item_id'  => 'BIGINT UNSIGNED NULL DEFAULT NULL',
	)
);

/**
 * Проверяет таблицу wp_pimwick_gift_card на наличие нужных PW столбцов
 * и добавляет отсутствующие. Вызывается при каждой загрузке до установки option.
 */
function wgpc_ensure_pw_recipient_name_column() {
	$option_key = 'wgpc_pw_schema_columns_added';
	$done       = get_option( $option_key, array() );
	$columns    = WGPC_PW_TABLE_COLUMNS_COMPAT;

	global $wpdb;
	$table = $wpdb->prefix . 'pimwick_gift_card';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return;
	}

	$existing = $wpdb->get_col( $wpdb->prepare(
		'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
		DB_NAME,
		$table
	) );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}

	$added_any = false;
	foreach ( $columns as $col_name => $col_def ) {
		if ( in_array( $col_name, $existing, true ) || isset( $done[ $col_name ] ) ) {
			continue;
		}
		$sql = "ALTER TABLE `{$table}` ADD COLUMN `{$col_name}` {$col_def}";
		$wpdb->query( $sql );
		if ( ! $wpdb->last_error ) {
			$done[ $col_name ] = 1;
			$added_any        = true;
		}
	}

	if ( $added_any || count( $done ) < count( $columns ) ) {
		update_option( $option_key, $done );
	}
}
