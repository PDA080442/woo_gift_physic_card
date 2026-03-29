<?php
/**
 * Синхронизация баланса физических карт с PW Gift Cards.
 *
 * @package Woo_Gift_Physic_Card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Регистрирует хуки синхронизации баланса.
 *
 * @return void
 */
function wgpc_register_balance_sync_hooks() {
	add_action( 'pwgc_activity_transaction', 'wgpc_handle_pwgc_activity_transaction', 10, 4 );
}

/**
 * Возвращает код валюты магазина по умолчанию.
 *
 * @return string
 */
function wgpc_get_default_currency_code() {
	$currency = get_option( 'woocommerce_currency', 'RUB' );
	$currency = is_string( $currency ) ? strtoupper( trim( $currency ) ) : '';

	return $currency !== '' ? $currency : 'RUB';
}

/**
 * Обработчик движений по карте в PW Gift Cards.
 * При любом списании или возврате синхронизирует актуальный остаток в нашей таблице.
 *
 * @param mixed $gift_card              Объект PW_Gift_Card.
 * @param mixed $amount                 Сумма операции.
 * @param mixed $note                   Примечание операции.
 * @param mixed $reference_activity_id  Ссылка на активность.
 * @return void
 */
function wgpc_handle_pwgc_activity_transaction( $gift_card, $amount, $note, $reference_activity_id ) {
	unset( $amount, $note, $reference_activity_id );

	if ( ! is_a( $gift_card, 'PW_Gift_Card' ) ) {
		return;
	}

	$gift_card_id = (int) $gift_card->get_id();
	if ( $gift_card_id <= 0 ) {
		return;
	}

	wgpc_sync_physical_card_balance_by_pw_id( $gift_card_id, $gift_card );
}

/**
 * Возвращает актуальный баланс карты PW по её ID.
 *
 * @param int                $gift_card_id ID карты PW.
 * @param PW_Gift_Card|mixed $gift_card    Уже загруженный объект карты, если есть.
 * @return float|null
 */
function wgpc_get_pw_gift_card_balance_by_id( $gift_card_id, $gift_card = null ) {
	$gift_card_id = (int) $gift_card_id;
	if ( $gift_card_id <= 0 || ! class_exists( 'PW_Gift_Card' ) ) {
		return null;
	}

	if ( ! is_a( $gift_card, 'PW_Gift_Card' ) || (int) $gift_card->get_id() !== $gift_card_id ) {
		$gift_card = PW_Gift_Card::get_by_id( $gift_card_id );
	}

	if ( ! is_a( $gift_card, 'PW_Gift_Card' ) ) {
		return null;
	}

	$balance = $gift_card->get_balance( true );
	if ( ! is_numeric( $balance ) ) {
		return null;
	}

	return round( (float) $balance, wc_get_price_decimals() );
}

/**
 * Синхронизирует баланс физической карты по ID карты в PW Gift Cards.
 *
 * @param int                $gift_card_id ID карты PW.
 * @param PW_Gift_Card|mixed $gift_card    Уже загруженный объект карты, если есть.
 * @return bool
 */
function wgpc_sync_physical_card_balance_by_pw_id( $gift_card_id, $gift_card = null ) {
	global $wpdb;

	$gift_card_id = (int) $gift_card_id;
	if ( $gift_card_id <= 0 ) {
		return false;
	}

	$table_name = wgpc_get_table_name();
	$row        = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id FROM $table_name WHERE pimwick_gift_card_id = %d LIMIT 1",
			$gift_card_id
		),
		ARRAY_A
	);

	if ( empty( $row['id'] ) ) {
		return false;
	}

	$balance = wgpc_get_pw_gift_card_balance_by_id( $gift_card_id, $gift_card );
	if ( $balance === null ) {
		return false;
	}

	$updated = $wpdb->update(
		$table_name,
		array(
			'balance'       => $balance,
			'currency_code' => wgpc_get_default_currency_code(),
			'updated_at'    => current_time( 'mysql' ),
		),
		array( 'id' => (int) $row['id'] ),
		array( '%f', '%s', '%s' ),
		array( '%d' )
	);

	return $updated !== false;
}

/**
 * Синхронизирует баланс физической карты по номеру карты.
 * Если связь с PW ещё не была сохранена, пытается восстановить её по номеру.
 *
 * @param string             $card_number Номер подарочной карты.
 * @param PW_Gift_Card|mixed $gift_card   Уже загруженный объект карты, если есть.
 * @return bool
 */
function wgpc_sync_physical_card_balance_by_number( $card_number, $gift_card = null ) {
	global $wpdb;

	$card_number = trim( (string) $card_number );
	if ( $card_number === '' ) {
		return false;
	}

	$table_name = wgpc_get_table_name();
	$row        = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, pimwick_gift_card_id FROM $table_name WHERE card_number = %s LIMIT 1",
			$card_number
		),
		ARRAY_A
	);

	if ( empty( $row['id'] ) ) {
		return false;
	}

	$gift_card_id = ! empty( $row['pimwick_gift_card_id'] ) ? (int) $row['pimwick_gift_card_id'] : 0;

	if ( $gift_card_id > 0 ) {
		return wgpc_sync_physical_card_balance_by_pw_id( $gift_card_id, $gift_card );
	}

	if ( ! class_exists( 'PW_Gift_Card' ) ) {
		return false;
	}

	if ( ! is_a( $gift_card, 'PW_Gift_Card' ) || (string) $gift_card->get_number() !== $card_number ) {
		$gift_card = new PW_Gift_Card( $card_number );
	}

	if ( ! is_a( $gift_card, 'PW_Gift_Card' ) || (int) $gift_card->get_id() <= 0 ) {
		return false;
	}

	$balance = $gift_card->get_balance( true );
	if ( ! is_numeric( $balance ) ) {
		return false;
	}

	$updated = $wpdb->update(
		$table_name,
		array(
			'pimwick_gift_card_id' => (int) $gift_card->get_id(),
			'balance'              => round( (float) $balance, wc_get_price_decimals() ),
			'currency_code'        => wgpc_get_default_currency_code(),
			'updated_at'           => current_time( 'mysql' ),
		),
		array( 'id' => (int) $row['id'] ),
		array( '%d', '%f', '%s', '%s' ),
		array( '%d' )
	);

	return $updated !== false;
}
