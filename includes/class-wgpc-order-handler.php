<?php
/**
 * Обработчик заказа: при переводе в «Выполнен» назначаем физическую карту из пула
 * и создаём запись в PW Gift Cards с нашим номером (баланс = номинал).
 *
 * @package Woo_Gift_Physic_Card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс вешается на хук woocommerce_order_status_completed (приоритет 9, раньше PW).
 * Для каждой позиции «Подарочная карта»: ищет свободную карту в нашей таблице,
 * помечает sold → создаёт в PW карту с этим номером (create_card + credit) →
 * обновляет нашу запись (activated, order_id, pimwick_gift_card_id) →
 * пишет номер в мету позиции. PW при своём запуске видит номер и не создаёт дубликат.
 */
class WGPC_Order_Handler {

	/**
	 * Мета-ключ номера карты в позиции заказа (совпадает с PW).
	 *
	 * @var string
	 */
	const META_CARD_NUMBER = 'pw_gift_card_number';

	/**
	 * Мета-ключ номинала в позиции заказа (PW).
	 *
	 * @var string
	 */
	const META_AMOUNT = 'pw_gift_card_amount';

	/**
	 * Подписываемся на смену статуса заказа на «Выполнен».
	 * Приоритет 9 — чтобы сработать до PW (у них 11).
	 */
	public function __construct() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'assign_physical_cards' ), 9, 2 );
		add_action( 'woocommerce_payment_complete', array( $this, 'assign_physical_cards' ), 9, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'assign_physical_cards' ), 9, 2 );
	}

	/**
	 * Для заказа с подарочными картами подбирает физические карты и привязывает к позициям.
	 *
	 * @param int      $order_id ID заказа.
	 * @param WC_Order $order    Объект заказа (иногда null, тогда получаем по $order_id).
	 */
	public function assign_physical_cards( $order_id, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		// PW Gift Cards должен быть активен: мы создаём карту через их класс.
		if ( ! class_exists( 'PW_Gift_Card' ) ) {
			return;
		}

		global $wpdb;
		$table_name = wgpc_get_table_name();

		foreach ( $order->get_items( 'line_item' ) as $order_item_id => $order_item ) {
			$product = $order_item->get_product();
			if ( ! $product ) {
				continue;
			}
			// Родительский товар — PW Gift Card; get_product() для вариации возвращает WC_Product_Variation, не WC_Product_PW_Gift_Card.
			$product_to_check = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
			if ( ! $product_to_check || ! is_a( $product_to_check, 'WC_Product_PW_Gift_Card' ) ) {
				continue;
			}

			// Уже есть номер карты (например, обработали ранее или это перезагрузка).
			$existing = $order_item->get_meta( self::META_CARD_NUMBER );
			if ( $existing ) {
				continue;
			}

			// Номинал: из меты PW или из суммы позиции / кол-во.
			$amount = $order_item->get_meta( self::META_AMOUNT );
			if ( ! is_numeric( $amount ) || (float) $amount <= 0 ) {
				$amount = round( $order_item->get_total() / max( 1, $order_item->get_quantity() ), wc_get_price_decimals() );
			}
			$amount = (float) $amount;
			if ( $amount <= 0 ) {
				continue;
			}

			$quantity = (int) $order_item->get_quantity();
			if ( $quantity < 1 ) {
				$quantity = 1;
			}

			// По одной карте на каждую единицу в позиции (например, 2 карты по 5000).
			for ( $i = 0; $i < $quantity; $i++ ) {
				$physical = $this->take_available_card( $table_name, $amount, $wpdb );
				if ( ! $physical ) {
					$order->add_order_note(
						sprintf(
							/* translators: %s: amount */
							__( '[Физические карты] Не найдена свободная карта для номинала %s. Требуется ручная выдача.', 'woo-gift-physic-card' ),
							wc_price( $amount )
						)
					);
					continue;
				}

				$card_number = $physical['card_number'];
				$physical_id = (int) $physical['id'];

				// Создаём карту в PW с нашим номером (второй аргумент = номер).
				$note = sprintf(
					/* translators: 1: order id, 2: order item id */
					__( 'Physical card, order #%1$s, item %2$s', 'woo-gift-physic-card' ),
					$order_id,
					$order_item_id
				);
				$gift_card = PW_Gift_Card::create_card( $note, $card_number );

				if ( ! is_a( $gift_card, 'PW_Gift_Card' ) ) {
					$order->add_order_note( __( '[Физические карты] Ошибка создания карты в PW.', 'woo-gift-physic-card' ) );
					$this->release_card( $table_name, $physical_id, $wpdb );
					continue;
				}

				$gift_card->credit( $amount, $note );
				$actual_balance = wgpc_get_pw_gift_card_balance_by_id( (int) $gift_card->get_id(), $gift_card );
				if ( $actual_balance === null ) {
					$actual_balance = round( (float) $amount, wc_get_price_decimals() );
				}

				// Связываем карту PW с заказом/позицией (как делает PW).
				$gift_card->set_product_id( $order_item->get_product_id() );
				$gift_card->set_variation_id( $order_item->get_variation_id() );
				$gift_card->set_order_item_id( $order_item_id );

				// В нашей таблице сохраняем ID карты PW и привязку к заказу.
				$now = current_time( 'mysql' );
				$wpdb->update(
					$table_name,
					array(
						'status'                 => 'activated',
						'balance'                => $actual_balance,
						'currency_code'          => wgpc_get_default_currency_code(),
						'order_id'               => $order_id,
						'order_item_id'          => $order_item_id,
						'pimwick_gift_card_id'   => $gift_card->get_id(),
						'updated_at'             => $now,
					),
					array( 'id' => $physical_id ),
					array( '%s', '%f', '%s', '%d', '%d', '%d', '%s' ),
					array( '%d' )
				);

				// Номер карты в мету позиции заказа. PW при своём запуске увидит уже заполненное и не создаст дубликат.
				$order_item->add_meta_data( self::META_CARD_NUMBER, $card_number, false );
			}

			$order_item->save_meta_data();
		}
	}

	/**
	 * Выбирает одну свободную карту по номиналу и «забирает» её (status = sold/activated делаем в update после создания в PW).
	 * Сначала ищем с совпадающим номиналом, потом с nominal IS NULL.
	 *
	 * @param string   $table_name Имя таблицы физических карт.
	 * @param float    $amount     Номинал заказа.
	 * @param wpdb     $wpdb       Глобальный объект БД.
	 * @return array|null Строка из БД (id, card_number, ...) или null.
	 */
	private function take_available_card( $table_name, $amount, $wpdb ) {
		// Сначала карта с таким же номиналом, потом с «любая сумма» (nominal IS NULL).
		// (nominal = %f) DESC: точное совпадение даёт 1 (первыми), NULL даёт NULL (в конце при DESC).
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, card_number FROM $table_name 
				WHERE status = 'available' AND ( nominal = %f OR nominal IS NULL ) 
				ORDER BY ( nominal = %f ) DESC, nominal ASC 
				LIMIT 1",
				$amount,
				$amount
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		// Сразу помечаем как занятую, чтобы другой запрос не выдал ту же карту.
		$updated = $wpdb->update(
			$table_name,
			array( 'status' => 'sold', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $row['id'], 'status' => 'available' ),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( $updated !== 1 ) {
			return null;
		}

		return $row;
	}

	/**
	 * Возвращает карту в статус available (если не удалось создать в PW).
	 *
	 * @param string $table_name Имя таблицы.
	 * @param int    $physical_id ID записи в нашей таблице.
	 * @param wpdb  $wpdb        Глобальный объект БД.
	 */
	private function release_card( $table_name, $physical_id, $wpdb ) {
		$wpdb->update(
			$table_name,
			array( 'status' => 'available', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $physical_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
