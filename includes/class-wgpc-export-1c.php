<?php
/**
 * Выгрузка статусов карт для 1С (активированные/проданные).
 * Тестовый вариант: CSV для скачивания из админки. Позже — API или EDI по согласованию.
 *
 * @package Woo_Gift_Physic_Card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс выгрузки данных по картам для 1С.
 */
class WGPC_Export_1C {

	/**
	 * Возвращает карты со статусом sold или activated и непустым external_id (связь с 1С).
	 *
	 * @param int|null $limit              Максимум записей (по умолчанию 1000).
	 * @param bool     $only_not_exported  true — только карты, ещё не подтверждённые выгрузкой в 1С (exported_to_1c_at IS NULL).
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_cards_for_export( $limit = 1000, $only_not_exported = false ) {
		global $wpdb;
		$table_name = wgpc_get_table_name();

		$where_exported = '';
		if ( $only_not_exported ) {
			$where_exported = " AND (exported_to_1c_at IS NULL OR exported_to_1c_at = '')";
		}

		$sql = $wpdb->prepare(
			"SELECT external_id, card_number, status, order_id, updated_at
			FROM $table_name
			WHERE status IN ('sold', 'activated')
			AND external_id IS NOT NULL
			AND TRIM(external_id) != ''
			$where_exported
			ORDER BY updated_at DESC
			LIMIT %d",
			$limit
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Форматирует массив карт в CSV (разделитель ;, UTF-8).
	 * Колонки: external_id; card_number; status; order_id; activated_at
	 *
	 * @param array<int, array<string, mixed>> $rows Результат get_cards_for_export().
	 * @return string
	 */
	public static function format_as_csv( array $rows ) {
		$header = array( 'external_id', 'card_number', 'status', 'order_id', 'activated_at' );
		$lines  = array( implode( ';', $header ) );

		foreach ( $rows as $row ) {
			$order_id    = isset( $row['order_id'] ) && $row['order_id'] !== null ? (int) $row['order_id'] : '';
			$activated_at = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '';
			$line = array(
				self::csv_cell( isset( $row['external_id'] ) ? (string) $row['external_id'] : '' ),
				self::csv_cell( isset( $row['card_number'] ) ? (string) $row['card_number'] : '' ),
				self::csv_cell( isset( $row['status'] ) ? (string) $row['status'] : '' ),
				(string) $order_id,
				self::csv_cell( $activated_at ),
			);
			$lines[] = implode( ';', $line );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Экранирует ячейку для CSV (если есть ; или кавычки — оборачиваем в кавычки).
	 *
	 * @param string $value Значение ячейки.
	 * @return string
	 */
	private static function csv_cell( $value ) {
		if ( strpos( $value, ';' ) !== false || strpos( $value, '"' ) !== false || strpos( $value, "\n" ) !== false ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}
}
