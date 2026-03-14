<?php
/**
 * Импорт списка физических карт из 1С (или из файла в формате 1С).
 * Ядро: на входе массив записей карт, на выходе — счётчики и ошибки.
 * Вызывается из формы загрузки CSV/XML или из API.
 *
 * @package Woo_Gift_Physic_Card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс импорта карт в таблицу wp_mpgc_physical_cards.
 */
class WGPC_Import_1C {

	/**
	 * Маппинг статуса из 1С в статус в таблице.
	 *
	 * @var array<string, string>
	 */
	private static $status_map = array(
		'Свободна'     => 'available',
		'Заблокирована' => 'blocked',
		'available'   => 'available',
		'blocked'     => 'blocked',
		'reserved'    => 'reserved',
		'sold'        => 'sold',
		'activated'   => 'activated',
	);

	/**
	 * Импортирует массив записей карт в таблицу.
	 * Каждая запись — массив с ключами: external_id, card_number, nominal, status_1c (опционально).
	 *
	 * @param array<int, array<string, mixed>> $rows Массив записей (поля после разбора CSV/XML).
	 * @return array{ inserted: int, updated: int, skipped: int, errors: array<int, string> }
	 */
	public static function import_cards( array $rows ) {
		global $wpdb;
		$table_name = wgpc_get_table_name();

		$result = array(
			'inserted' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		$now = current_time( 'mysql' );

		foreach ( $rows as $index => $row ) {
			$card_number = isset( $row['card_number'] ) ? trim( (string) $row['card_number'] ) : '';
			if ( $card_number === '' ) {
				$result['errors'][ $index ] = __( 'Пустой номер карты', 'woo-gift-physic-card' );
				continue;
			}

			$external_id = isset( $row['external_id'] ) ? trim( (string) $row['external_id'] ) : null;
			$nominal     = isset( $row['nominal'] ) && $row['nominal'] !== '' ? (float) $row['nominal'] : null;
			$status_1c   = isset( $row['status_1c'] ) ? trim( (string) $row['status_1c'] ) : '';
			$status      = self::map_status( $status_1c );

			// Ищем существующую запись: сначала по external_id, потом по card_number.
			$existing = null;
			if ( $external_id !== null && $external_id !== '' ) {
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, status FROM $table_name WHERE external_id = %s LIMIT 1",
						$external_id
					),
					ARRAY_A
				);
			}
			if ( ! $existing ) {
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, status FROM $table_name WHERE card_number = %s LIMIT 1",
						$card_number
					),
					ARRAY_A
				);
			}

			if ( $existing ) {
				$existing_status = $existing['status'];
				if ( in_array( $existing_status, array( 'sold', 'activated' ), true ) ) {
					$result['skipped']++;
					continue;
				}
				$wpdb->update(
					$table_name,
					array(
						'nominal'    => $nominal,
						'status'     => $status,
						'updated_at' => $now,
					),
					array( 'id' => (int) $existing['id'] ),
					array( '%f', '%s', '%s' ),
					array( '%d' )
				);
				if ( $wpdb->last_error ) {
					$result['errors'][ $index ] = $wpdb->last_error;
				} else {
					$result['updated']++;
				}
				continue;
			}

			// Новая карта — INSERT.
			$inserted = $wpdb->insert(
				$table_name,
				array(
					'card_number' => $card_number,
					'status'      => $status,
					'nominal'     => $nominal,
					'external_id' => $external_id,
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%s', '%s', '%f', '%s', '%s', '%s' )
			);
			if ( ! $inserted ) {
				if ( $wpdb->last_error && strpos( $wpdb->last_error, 'Duplicate' ) !== false ) {
					$result['skipped']++;
				} else {
					$result['errors'][ $index ] = $wpdb->last_error ?: __( 'Ошибка вставки', 'woo-gift-physic-card' );
				}
			} else {
				$result['inserted']++;
			}
		}

		return $result;
	}

	/**
	 * Преобразует статус из 1С в статус в таблице.
	 *
	 * @param string $status_1c Значение из файла/API (например «Свободна», «Заблокирована»).
	 * @return string available|blocked|reserved|sold|activated
	 */
	public static function map_status( $status_1c ) {
		if ( $status_1c === '' ) {
			return 'available';
		}
		if ( isset( self::$status_map[ $status_1c ] ) ) {
			return self::$status_map[ $status_1c ];
		}
		return 'available';
	}
}
