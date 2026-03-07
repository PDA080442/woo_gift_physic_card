<?php
/**
 * Админка: пункт меню и страница «Физические карты».
 * Список карт, фильтр по статусу, форма добавления одной карты.
 *
 * @package Woo_Gift_Physic_Card
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс одной «страницы» админки. Регистрирует меню и выводит HTML.
 *
 * В PHP класс — это шаблон объекта. new WGPC_Admin() создаёт экземпляр;
 * конструктор __construct() вешает на admin_menu нашу функцию register_menu,
 * и WordPress при отрисовке меню вызовет render_page() при переходе на «Физические карты».
 */
class WGPC_Admin {

	/**
	 * Слаг страницы в URL (admin.php?page=...).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wgpc-physical-cards';

	/**
	 * Подключение к хуку меню WordPress.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Добавляет пункт «Физические карты» в подменю WooCommerce.
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',                              // Родитель: меню WooCommerce.
			__( 'Физические карты', 'woo-gift-physic-card' ),
			__( 'Физические карты', 'woo-gift-physic-card' ),
			'manage_woocommerce',                       // Права: как у WooCommerce.
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Обрабатывает отправку формы «Добавить карту» и выводит страницу (список + форма).
	 */
	public function render_page() {
		// Обработка POST — добавление новой карты. После успеха редирект с сообщением.
		$this->handle_add_card();

		$table_name = wgpc_get_table_name();
		global $wpdb;

		// Фильтр по статусу: из URL (например ?page=wgpc-physical-cards&status=available).
		$filter_status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$allowed       = array( 'available', 'reserved', 'sold', 'activated', 'blocked' );
		if ( $filter_status && ! in_array( $filter_status, $allowed, true ) ) {
			$filter_status = '';
		}

		// Собираем WHERE для запроса. Все подстановки — через prepare, чтобы не было SQL-инъекций.
		$where = '1=1';
		$params = array();
		if ( $filter_status !== '' ) {
			$where   .= ' AND status = %s';
			$params[] = $filter_status;
		}

		$sql = "SELECT id, card_number, pin, status, nominal, order_id, created_at FROM $table_name WHERE $where ORDER BY id DESC LIMIT 500";
		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		// Ниже — HTML страницы. esc_html() и esc_attr() защищают от XSS при выводе.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Физические подарочные карты', 'woo-gift-physic-card' ); ?></h1>

			<?php
			// Сообщение об успешном добавлении (после редиректа с wgpc_added=1).
			if ( isset( $_GET['wgpc_added'] ) && (int) $_GET['wgpc_added'] === 1 ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Карта добавлена.', 'woo-gift-physic-card' ) . '</p></div>';
			}
			if ( isset( $_GET['wgpc_error'] ) ) {
				$msg = sanitize_text_field( wp_unslash( $_GET['wgpc_error'] ) );
				echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
			}
			?>

			<h2 class="nav-tab-wrapper">
				<?php esc_html_e( 'Добавить карту', 'woo-gift-physic-card' ); ?>
			</h2>

			<form method="post" action="" style="max-width: 600px; margin: 1em 0;">
				<?php wp_nonce_field( 'wgpc_add_card', 'wgpc_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th><label for="wgpc_card_number"><?php esc_html_e( 'Номер карты', 'woo-gift-physic-card' ); ?> *</label></th>
						<td><input type="text" name="card_number" id="wgpc_card_number" class="regular-text" required value="" /></td>
					</tr>
					<tr>
						<th><label for="wgpc_pin"><?php esc_html_e( 'PIN', 'woo-gift-physic-card' ); ?></label></th>
						<td><input type="text" name="pin" id="wgpc_pin" class="regular-text" value="" /></td>
					</tr>
					<tr>
						<th><label for="wgpc_nominal"><?php esc_html_e( 'Номинал (₽)', 'woo-gift-physic-card' ); ?></label></th>
						<td>
							<input type="number" name="nominal" id="wgpc_nominal" min="0" step="0.01" value="" placeholder="<?php esc_attr_e( 'пусто = любая сумма', 'woo-gift-physic-card' ); ?>" />
							<p class="description"><?php esc_html_e( 'Оставьте пустым, если карта под любую сумму.', 'woo-gift-physic-card' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="wgpc_status"><?php esc_html_e( 'Статус', 'woo-gift-physic-card' ); ?></label></th>
						<td>
							<select name="status" id="wgpc_status">
								<option value="available"><?php esc_html_e( 'Доступна', 'woo-gift-physic-card' ); ?></option>
								<option value="blocked"><?php esc_html_e( 'Заблокирована', 'woo-gift-physic-card' ); ?></option>
								<option value="reserved"><?php esc_html_e( 'Зарезервирована', 'woo-gift-physic-card' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="wgpc_notes"><?php esc_html_e( 'Заметки', 'woo-gift-physic-card' ); ?></label></th>
						<td><textarea name="notes" id="wgpc_notes" rows="2" class="large-text"></textarea></td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="wgpc_add_card" class="button button-primary"><?php esc_html_e( 'Добавить карту', 'woo-gift-physic-card' ); ?></button>
				</p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Список карт', 'woo-gift-physic-card' ); ?></h2>

			<form method="get" action="">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<label for="filter_status"><?php esc_html_e( 'Статус:', 'woo-gift-physic-card' ); ?></label>
				<select name="status" id="filter_status">
					<option value=""><?php esc_html_e( 'Все', 'woo-gift-physic-card' ); ?></option>
					<?php
					foreach ( $allowed as $s ) {
						echo '<option value="' . esc_attr( $s ) . '" ' . selected( $filter_status, $s, false ) . '>' . esc_html( $s ) . '</option>';
					}
					?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Фильтровать', 'woo-gift-physic-card' ); ?></button>
			</form>

			<table class="wp-list-table widefat fixed striped" style="margin-top: 1em;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'woo-gift-physic-card' ); ?></th>
						<th><?php esc_html_e( 'Номер карты', 'woo-gift-physic-card' ); ?></th>
						<th><?php esc_html_e( 'PIN', 'woo-gift-physic-card' ); ?></th>
						<th><?php esc_html_e( 'Номинал', 'woo-gift-physic-card' ); ?></th>
						<th><?php esc_html_e( 'Статус', 'woo-gift-physic-card' ); ?></th>
						<th><?php esc_html_e( 'Заказ', 'woo-gift-physic-card' ); ?></th>
						<th><?php esc_html_e( 'Создана', 'woo-gift-physic-card' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( empty( $rows ) ) {
						echo '<tr><td colspan="7">' . esc_html__( 'Нет карт.', 'woo-gift-physic-card' ) . '</td></tr>';
					} else {
						foreach ( $rows as $row ) {
							$nominal = $row['nominal'] !== null ? number_format_i18n( (float) $row['nominal'], 2 ) : '—';
							$order_link = '';
							if ( ! empty( $row['order_id'] ) ) {
								$order_link = '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $row['order_id'] . '&action=edit' ) ) . '">#' . (int) $row['order_id'] . '</a>';
							} else {
								$order_link = '—';
							}
							echo '<tr>';
							echo '<td>' . (int) $row['id'] . '</td>';
							echo '<td>' . esc_html( $row['card_number'] ) . '</td>';
							echo '<td>' . esc_html( $row['pin'] !== null ? $row['pin'] : '—' ) . '</td>';
							echo '<td>' . esc_html( $nominal ) . '</td>';
							echo '<td>' . esc_html( $row['status'] ) . '</td>';
							echo '<td>' . wp_kses_post( $order_link ) . '</td>';
							echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
							echo '</tr>';
						}
					}
					?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Обработка POST: проверка nonce, валидация, INSERT в таблицу, редирект.
	 */
	private function handle_add_card() {
		if ( ! isset( $_POST['wgpc_add_card'] ) || ! isset( $_POST['wgpc_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'woo-gift-physic-card' ) );
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgpc_nonce'] ) ), 'wgpc_add_card' ) ) {
			wp_die( esc_html__( 'Ошибка проверки безопасности. Обновите страницу и попробуйте снова.', 'woo-gift-physic-card' ) );
		}

		$card_number = isset( $_POST['card_number'] ) ? sanitize_text_field( wp_unslash( $_POST['card_number'] ) ) : '';
		$card_number = trim( $card_number );
		if ( $card_number === '' ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wgpc_error' => urlencode( __( 'Номер карты обязателен.', 'woo-gift-physic-card' ) ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$pin    = isset( $_POST['pin'] ) ? sanitize_text_field( wp_unslash( $_POST['pin'] ) ) : null;
		$pin    = $pin !== '' ? $pin : null;
		$nominal = isset( $_POST['nominal'] ) ? sanitize_text_field( wp_unslash( $_POST['nominal'] ) ) : null;
		$nominal = $nominal !== '' ? (float) $nominal : null;
		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'available';
		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : null;
		$notes  = $notes !== '' ? $notes : null;

		$allowed_status = array( 'available', 'blocked', 'reserved' );
		if ( ! in_array( $status, $allowed_status, true ) ) {
			$status = 'available';
		}

		global $wpdb;
		$table_name = wgpc_get_table_name();

		// Проверка уникальности номера.
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table_name WHERE card_number = %s",
			$card_number
		) );
		if ( $exists ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wgpc_error' => urlencode( __( 'Карта с таким номером уже есть.', 'woo-gift-physic-card' ) ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$table_name,
			array(
				'card_number' => $card_number,
				'pin'         => $pin,
				'status'      => $status,
				'nominal'     => $nominal,
				'order_id'    => null,
				'order_item_id' => null,
				'pimwick_gift_card_id' => null,
				'external_id' => null,
				'created_at'  => $now,
				'updated_at'  => $now,
				'notes'       => $notes,
			),
			array( '%s', '%s', '%s', '%f', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( $wpdb->last_error ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wgpc_error' => urlencode( $wpdb->last_error ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wgpc_added' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
