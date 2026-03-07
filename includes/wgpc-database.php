<?php
/**
 * Работа с именем таблицы физических карт.
 * Один раз задаём слаг таблицы и получаем полное имя с префиксом сайта.
 *
 * @package Woo_Gift_Physic_Card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Возвращает полное имя таблицы физических карт для текущего сайта.
 * Пример: wp_mpgc_physical_cards (префикс wp_ задаётся в настройках WordPress).
 *
 * @return string
 */
function wgpc_get_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'mpgc_physical_cards';
}
