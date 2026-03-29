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
	 * Результат последнего импорта из 1С (для вывода на странице).
	 *
	 * @var array{ inserted: int, updated: int, skipped: int, errors: array }|null
	 */
	private $import_result = null;

	/**
	 * Подключение к хуку меню WordPress.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		// Выгрузка CSV — до вывода страницы, иначе в ответ попадёт HTML админки.
		add_action( 'load-woocommerce_page_' . self::PAGE_SLUG, array( $this, 'maybe_export_1c' ) );
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
		// Обработка POST — импорт из 1С (без редиректа, результат выводим ниже).
		$this->handle_import_1c();
		// Обработка POST — добавление новой карты. После успеха редирект с сообщением.
		$this->handle_add_card();
		// Обработка POST — удаление карты. После успеха редирект с сообщением.
		$this->handle_delete_card();
		// Обработка POST — настройки REST для обмена с 1С (редирект после сохранения).
		$this->handle_rest_settings_save();
		// Обработка POST — очистка лога ошибок REST.
		$this->handle_rest_log_clear();

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

		$sql = "SELECT id, card_number, status, nominal, currency_code, balance, order_id, created_at FROM $table_name WHERE $where ORDER BY id DESC LIMIT 500";
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
			// Сообщение об успешном удалении (после редиректа с wgpc_deleted=1).
			if ( isset( $_GET['wgpc_deleted'] ) && (int) $_GET['wgpc_deleted'] === 1 ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Карта удалена.', 'woo-gift-physic-card' ) . '</p></div>';
			}
			if ( isset( $_GET['wgpc_error'] ) ) {
				$msg = sanitize_text_field( wp_unslash( $_GET['wgpc_error'] ) );
				echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
			}
			if ( $this->import_result !== null ) {
				$r = $this->import_result;
				$msg = sprintf(
					/* translators: 1: inserted count, 2: updated count, 3: skipped count */
					__( 'Импорт выполнен: добавлено %1$d, обновлено %2$d, пропущено %3$d.', 'woo-gift-physic-card' ),
					$r['inserted'],
					$r['updated'],
					$r['skipped']
				);
				$class = ! empty( $r['errors'] ) ? 'notice-warning' : 'notice-success';
				echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p>';
				if ( ! empty( $r['errors'] ) ) {
					echo '<ul style="margin: 0.5em 0 0 1em;">';
					foreach ( $r['errors'] as $row_index => $err ) {
						echo '<li>' . esc_html( sprintf( __( 'Строка %d: %s', 'woo-gift-physic-card' ), $row_index + 1, $err ) ) . '</li>';
					}
					echo '</ul>';
				}
				echo '</div>';
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
						<th><label for="wgpc_nominal"><?php esc_html_e( 'Номинал (₽)', 'woo-gift-physic-card' ); ?></label></th>
						<td>
							<input type="number" name="nominal" id="wgpc_nominal" min="0" step="0.01" value="" placeholder="<?php esc_attr_e( 'пусто = любая сумма', 'woo-gift-physic-card' ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: store currency code */
									esc_html__( 'Оставьте пустым, если карта под любую сумму. Валюта для новых карт будет установлена автоматически: %s.', 'woo-gift-physic-card' ),
									esc_html( wgpc_get_default_currency_code() )
								);
								?>
							</p>
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

			<h2 style="margin-top: 2em;"><?php esc_html_e( 'Импорт из 1С', 'woo-gift-physic-card' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Загрузите CSV-файл с разделителем «;». Обязательные колонки: external_id; card_number; nominal; status_1c. Дополнительно можно передать: currency_code; balance. Первая строка — заголовок.', 'woo-gift-physic-card' ); ?></p>
			<form method="post" action="" enctype="multipart/form-data" style="max-width: 600px; margin: 1em 0;">
				<?php wp_nonce_field( 'wgpc_import_1c', 'wgpc_import_nonce' ); ?>
				<p>
					<input type="file" name="wgpc_import_file" accept=".csv" required />
					<button type="submit" name="wgpc_import_1c" class="button button-primary" style="margin-left: 8px;"><?php esc_html_e( 'Загрузить и импортировать', 'woo-gift-physic-card' ); ?></button>
				</p>
			</form>

			<h2 style="margin-top: 2em;"><?php esc_html_e( 'Выгрузка для 1С', 'woo-gift-physic-card' ); ?></h2>
			<?php
			$export_count = count( WGPC_Export_1C::get_cards_for_export( 1000 ) );
			?>
			<p class="description">
				<?php esc_html_e( 'Скачать CSV со статусами активированных/проданных карт. В выгрузку попадают только карты с заполненным ИД 1С (external_id) — иначе 1С не сможет сопоставить запись.', 'woo-gift-physic-card' ); ?>
				<br />
				<?php
				printf(
					/* translators: %d: number of cards */
					esc_html__( 'Сейчас таких карт: %d. Колонки CSV: external_id; card_number; status; currency_code; balance; order_id; activated_at.', 'woo-gift-physic-card' ),
					$export_count
				);
				?>
			</p>
			<form method="post" action="" style="margin: 1em 0;">
				<?php wp_nonce_field( 'wgpc_export_1c', 'wgpc_export_nonce' ); ?>
				<button type="submit" name="wgpc_export_1c" class="button"><?php esc_html_e( 'Выгрузить статусы для 1С (CSV)', 'woo-gift-physic-card' ); ?></button>
			</form>

			<h2 style="margin-top: 2em;"><?php esc_html_e( 'Обмен с 1С (REST API)', 'woo-gift-physic-card' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Токен и ограничение по IP для доступа 1С к маршрутам импорта и выгрузки. Неудачные попытки входа пишутся в лог (debug.log при включённом WP_DEBUG_LOG).', 'woo-gift-physic-card' ); ?></p>
			<?php if ( isset( $_GET['wgpc_rest_saved'] ) && (int) $_GET['wgpc_rest_saved'] === 1 ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Настройки REST сохранены.', 'woo-gift-physic-card' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="" style="max-width: 600px; margin: 1em 0;">
				<?php wp_nonce_field( 'wgpc_rest_settings', 'wgpc_rest_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="wgpc_rest_token"><?php esc_html_e( 'Токен для 1С', 'woo-gift-physic-card' ); ?></label></th>
						<td>
							<input type="password" name="wgpc_rest_token" id="wgpc_rest_token" class="regular-text" value="" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Секретный ключ. 1С передаёт его в заголовке X-WGPC-Token. Оставьте пустым, чтобы не менять текущий токен.', 'woo-gift-physic-card' ); ?></p>
							<?php if ( get_option( 'wgpc_rest_token', '' ) !== '' ) : ?>
								<p class="description"><?php esc_html_e( 'Текущий токен задан (отображается как ••••••).', 'woo-gift-physic-card' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="wgpc_rest_allowed_ips"><?php esc_html_e( 'Разрешённые IP', 'woo-gift-physic-card' ); ?></label></th>
						<td>
							<textarea name="wgpc_rest_allowed_ips" id="wgpc_rest_allowed_ips" rows="3" class="large-text" placeholder="192.168.1.1&#10;10.0.0.1"><?php echo esc_textarea( get_option( 'wgpc_rest_allowed_ips', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Один IP на строку или через запятую. Пусто = проверка по IP отключена.', 'woo-gift-physic-card' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'REST-обмен', 'woo-gift-physic-card' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wgpc_rest_enabled" value="1" <?php checked( get_option( 'wgpc_rest_enabled', '1' ), '1' ); ?> />
								<?php esc_html_e( 'Включить REST-обмен с 1С', 'woo-gift-physic-card' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Если снять галочку, все запросы к REST API будут отклоняться (503).', 'woo-gift-physic-card' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Выгрузка статусов', 'woo-gift-physic-card' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wgpc_rest_export_only_new" value="1" <?php checked( get_option( 'wgpc_rest_export_only_new', '0' ), '1' ); ?> />
								<?php esc_html_e( 'Отдавать только ещё не выгруженные в 1С карты', 'woo-gift-physic-card' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'При включении GET /cards/exports вернёт только карты без подтверждения получения (exported_to_1c_at пусто).', 'woo-gift-physic-card' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="wgpc_save_rest_settings" class="button button-primary"><?php esc_html_e( 'Сохранить настройки REST', 'woo-gift-physic-card' ); ?></button>
				</p>
			</form>

			<h3 style="margin-top: 1.5em;"><?php esc_html_e( 'Лог ошибок REST', 'woo-gift-physic-card' ); ?></h3>
			<?php
			$rest_log = get_option( 'wgpc_rest_log', array() );
			if ( ! is_array( $rest_log ) ) {
				$rest_log = array();
			}
			?>
			<?php if ( empty( $rest_log ) ) : ?>
				<p class="description"><?php esc_html_e( 'Ошибок пока не было. Сюда попадают неудачные попытки входа (неверный токен, IP не в списке и т.п.).', 'woo-gift-physic-card' ); ?></p>
			<?php else : ?>
				<form method="post" action="" style="margin-bottom: 0.5em;">
					<?php wp_nonce_field( 'wgpc_clear_rest_log', 'wgpc_clear_log_nonce' ); ?>
					<button type="submit" name="wgpc_clear_rest_log" class="button"><?php esc_html_e( 'Очистить лог', 'woo-gift-physic-card' ); ?></button>
				</form>
				<table class="wp-list-table widefat fixed striped" style="max-width: 900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Время', 'woo-gift-physic-card' ); ?></th>
							<th><?php esc_html_e( 'Метод', 'woo-gift-physic-card' ); ?></th>
							<th><?php esc_html_e( 'Маршрут', 'woo-gift-physic-card' ); ?></th>
							<th><?php esc_html_e( 'IP', 'woo-gift-physic-card' ); ?></th>
							<th><?php esc_html_e( 'Код', 'woo-gift-physic-card' ); ?></th>
							<th><?php esc_html_e( 'Сообщение', 'woo-gift-physic-card' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rest_log as $entry ) : ?>
							<?php
							$e = wp_parse_args( $entry, array( 'time' => '', 'method' => '', 'route' => '', 'ip' => '', 'code' => '', 'message' => '' ) );
							?>
							<tr>
								<td><?php echo esc_html( $e['time'] ); ?></td>
								<td><?php echo esc_html( $e['method'] ); ?></td>
								<td><?php echo esc_html( $e['route'] ); ?></td>
								<td><?php echo esc_html( $e['ip'] ); ?></td>
								<td><?php echo esc_html( $e['code'] ); ?></td>
								<td><?php echo esc_html( $e['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

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
						<th><?php esc_html_e( 'Номинал', 'woo-gift-physic-card' ); ?></th>
						<th><?php esc_html_e( 'Валюта', 'woo-gift-physic-card' ); ?></th>
						<th><?php esc_html_e( 'Баланс', 'woo-gift-physic-card' ); ?></th>
						<th><?php esc_html_e( 'Статус', 'woo-gift-physic-card' ); ?></th>
						<th><?php esc_html_e( 'Заказ', 'woo-gift-physic-card' ); ?></th>
						<th><?php esc_html_e( 'Создана', 'woo-gift-physic-card' ); ?></th>
						<th><?php esc_html_e( 'Действия', 'woo-gift-physic-card' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( empty( $rows ) ) {
						echo '<tr><td colspan="9">' . esc_html__( 'Нет карт.', 'woo-gift-physic-card' ) . '</td></tr>';
					} else {
						foreach ( $rows as $row ) {
							$nominal = $row['nominal'] !== null ? number_format_i18n( (float) $row['nominal'], 2 ) : '—';
							$currency_code = ! empty( $row['currency_code'] ) ? (string) $row['currency_code'] : '—';
							$balance = $row['balance'] !== null ? number_format_i18n( (float) $row['balance'], 2 ) : '—';
							$order_link = '';
							if ( ! empty( $row['order_id'] ) ) {
								$order_link = '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $row['order_id'] . '&action=edit' ) ) . '">#' . (int) $row['order_id'] . '</a>';
							} else {
								$order_link = '—';
							}
							
							// Проверяем, можно ли удалить карту (нельзя удалять проданные/активированные)
							$can_delete = ! in_array( $row['status'], array( 'sold', 'activated' ), true );
							$delete_button = '';
							if ( $can_delete ) {
								$delete_args = array(
									'page' => self::PAGE_SLUG,
									'action' => 'delete',
									'card_id' => (int) $row['id'],
								);
								// Сохраняем фильтр по статусу в URL удаления
								if ( $filter_status !== '' ) {
									$delete_args['status'] = $filter_status;
								}
								$delete_url = wp_nonce_url(
									add_query_arg( $delete_args, admin_url( 'admin.php' ) ),
									'wgpc_delete_card_' . (int) $row['id'],
									'wgpc_delete_nonce'
								);
								$delete_button = '<a href="' . esc_url( $delete_url ) . '" class="button button-link-delete" onclick="return confirm(\'' . esc_js( __( 'Вы уверены, что хотите удалить эту карту?', 'woo-gift-physic-card' ) ) . '\');">' . esc_html__( 'Удалить', 'woo-gift-physic-card' ) . '</a>';
							} else {
								$delete_button = '<span class="description">' . esc_html__( 'Нельзя удалить', 'woo-gift-physic-card' ) . '</span>';
							}
							
							echo '<tr>';
							echo '<td>' . (int) $row['id'] . '</td>';
							echo '<td>' . esc_html( $row['card_number'] ) . '</td>';
							echo '<td>' . esc_html( $nominal ) . '</td>';
							echo '<td>' . esc_html( $currency_code ) . '</td>';
							echo '<td>' . esc_html( $balance ) . '</td>';
							echo '<td>' . esc_html( $row['status'] ) . '</td>';
							echo '<td>' . wp_kses_post( $order_link ) . '</td>';
							echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
							echo '<td>' . wp_kses_post( $delete_button ) . '</td>';
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
	 * Обработка GET: удаление карты по ID. Проверка nonce, статуса, DELETE, редирект.
	 */
	private function handle_delete_card() {
		if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'delete' || ! isset( $_GET['card_id'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'woo-gift-physic-card' ) );
		}

		$card_id = (int) $_GET['card_id'];
		if ( $card_id <= 0 ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wgpc_error' => urlencode( __( 'Неверный ID карты.', 'woo-gift-physic-card' ) ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( ! isset( $_GET['wgpc_delete_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['wgpc_delete_nonce'] ) ), 'wgpc_delete_card_' . $card_id ) ) {
			wp_die( esc_html__( 'Ошибка проверки безопасности. Обновите страницу и попробуйте снова.', 'woo-gift-physic-card' ) );
		}

		global $wpdb;
		$table_name = wgpc_get_table_name();

		// Проверяем существование карты и её статус
		$card = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status, order_id FROM $table_name WHERE id = %d",
			$card_id
		), ARRAY_A );

		if ( ! $card ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wgpc_error' => urlencode( __( 'Карта не найдена.', 'woo-gift-physic-card' ) ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Нельзя удалять проданные или активированные карты
		if ( in_array( $card['status'], array( 'sold', 'activated' ), true ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wgpc_error' => urlencode( __( 'Нельзя удалить карту со статусом «проданная» или «активированная».', 'woo-gift-physic-card' ) ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Удаляем карту
		$deleted = $wpdb->delete(
			$table_name,
			array( 'id' => $card_id ),
			array( '%d' )
		);

		if ( $deleted === false ) {
			$redirect_args = array( 'page' => self::PAGE_SLUG, 'wgpc_error' => urlencode( $wpdb->last_error ?: __( 'Ошибка при удалении карты.', 'woo-gift-physic-card' ) ) );
			// Сохраняем фильтр по статусу, если он был установлен
			if ( isset( $_GET['status'] ) ) {
				$redirect_args['status'] = sanitize_text_field( wp_unslash( $_GET['status'] ) );
			}
			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		$redirect_args = array( 'page' => self::PAGE_SLUG, 'wgpc_deleted' => '1' );
		// Сохраняем фильтр по статусу, если он был установлен
		if ( isset( $_GET['status'] ) ) {
			$redirect_args['status'] = sanitize_text_field( wp_unslash( $_GET['status'] ) );
		}
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
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

		$nominal = isset( $_POST['nominal'] ) ? sanitize_text_field( wp_unslash( $_POST['nominal'] ) ) : null;
		$nominal = $nominal !== '' ? (float) $nominal : null;
		$currency_code = wgpc_get_default_currency_code();
		$balance = $nominal !== null ? round( (float) $nominal, wc_get_price_decimals() ) : null;
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
				'status'      => $status,
				'nominal'     => $nominal,
				'currency_code' => $currency_code,
				'balance'     => $balance,
				'order_id'    => null,
				'order_item_id' => null,
				'pimwick_gift_card_id' => null,
				'external_id' => null,
				'created_at'  => $now,
				'updated_at'  => $now,
				'notes'       => $notes,
			),
			array( '%s', '%s', '%f', '%s', '%f', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( $wpdb->last_error ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wgpc_error' => urlencode( $wpdb->last_error ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wgpc_added' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Обработка POST: сохранение настроек REST (токен, разрешённые IP). Редирект после успеха.
	 *
	 * @return void
	 */
	private function handle_rest_settings_save() {
		if ( ! isset( $_POST['wgpc_save_rest_settings'] ) || ! isset( $_POST['wgpc_rest_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'woo-gift-physic-card' ) );
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgpc_rest_nonce'] ) ), 'wgpc_rest_settings' ) ) {
			wp_die( esc_html__( 'Ошибка проверки безопасности. Обновите страницу и попробуйте снова.', 'woo-gift-physic-card' ) );
		}

		$new_token = isset( $_POST['wgpc_rest_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wgpc_rest_token'] ) ) : '';
		if ( $new_token !== '' ) {
			update_option( 'wgpc_rest_token', $new_token );
		}

		$allowed_ips = isset( $_POST['wgpc_rest_allowed_ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wgpc_rest_allowed_ips'] ) ) : '';
		update_option( 'wgpc_rest_allowed_ips', $allowed_ips );

		update_option( 'wgpc_rest_enabled', isset( $_POST['wgpc_rest_enabled'] ) ? '1' : '0' );
		update_option( 'wgpc_rest_export_only_new', isset( $_POST['wgpc_rest_export_only_new'] ) ? '1' : '0' );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wgpc_rest_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Обработка POST: очистка лога ошибок REST. Редирект после успеха.
	 *
	 * @return void
	 */
	private function handle_rest_log_clear() {
		if ( ! isset( $_POST['wgpc_clear_rest_log'] ) || ! isset( $_POST['wgpc_clear_log_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'woo-gift-physic-card' ) );
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgpc_clear_log_nonce'] ) ), 'wgpc_clear_rest_log' ) ) {
			wp_die( esc_html__( 'Ошибка проверки безопасности. Обновите страницу и попробуйте снова.', 'woo-gift-physic-card' ) );
		}

		delete_option( 'wgpc_rest_log' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wgpc_rest_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Вызывается на load-woocommerce_page_wgpc-physical-cards (до вывода HTML).
	 * Если запрос на выгрузку CSV — отдаёт файл и завершает выполнение.
	 */
	public function maybe_export_1c() {
		if ( ! isset( $_POST['wgpc_export_1c'] ) || ! isset( $_POST['wgpc_export_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'woo-gift-physic-card' ) );
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgpc_export_nonce'] ) ), 'wgpc_export_1c' ) ) {
			wp_die( esc_html__( 'Ошибка проверки безопасности. Обновите страницу и попробуйте снова.', 'woo-gift-physic-card' ) );
		}

		$rows = WGPC_Export_1C::get_cards_for_export( 1000 );
		$csv  = WGPC_Export_1C::format_as_csv( $rows );

		$filename = 'wgpc-cards-export-' . gmdate( 'Y-m-d-His' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		echo "\xEF\xBB\xBF";
		echo $csv;
		exit;
	}

	/**
	 * Обработка POST: загрузка CSV из 1С, разбор, вызов ядра импорта, сохранение результата для вывода.
	 */
	private function handle_import_1c() {
		if ( ! isset( $_POST['wgpc_import_1c'] ) || ! isset( $_POST['wgpc_import_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'woo-gift-physic-card' ) );
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgpc_import_nonce'] ) ), 'wgpc_import_1c' ) ) {
			wp_die( esc_html__( 'Ошибка проверки безопасности. Обновите страницу и попробуйте снова.', 'woo-gift-physic-card' ) );
		}

		if ( empty( $_FILES['wgpc_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['wgpc_import_file']['tmp_name'] ) ) {
			$this->import_result = array( 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array( __( 'Файл не загружен или ошибка загрузки.', 'woo-gift-physic-card' ) ) );
			return;
		}

		$max_size = 2 * 1024 * 1024; // 2 MB
		if ( (int) $_FILES['wgpc_import_file']['size'] > $max_size ) {
			$this->import_result = array( 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array( __( 'Файл слишком большой (макс. 2 МБ).', 'woo-gift-physic-card' ) ) );
			return;
		}

		$rows = $this->parse_csv_import( $_FILES['wgpc_import_file']['tmp_name'] );
		if ( is_string( $rows ) ) {
			$this->import_result = array( 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array( $rows ) );
			return;
		}

		$this->import_result = WGPC_Import_1C::import_cards( $rows );
	}

	/**
	 * Разбирает CSV-файл (разделитель ;, первая строка — заголовок). UTF-8.
	 *
	 * @param string $file_path Путь к загруженному файлу.
	 * @return array<int, array<string, mixed>>|string Массив записей или строка с ошибкой.
	 */
	private function parse_csv_import( $file_path ) {
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return __( 'Не удалось открыть файл.', 'woo-gift-physic-card' );
		}

		$header = fgetcsv( $handle, 0, ';' );
		if ( $header === false || empty( $header ) ) {
			fclose( $handle );
			return __( 'Файл пуст или неверный формат.', 'woo-gift-physic-card' );
		}

		// Убираем BOM из первой колонки заголовка.
		$header[0] = str_replace( "\xEF\xBB\xBF", '', $header[0] );
		$header     = array_map( 'trim', $header );
		$header     = array_map( function ( $h ) {
			return strtolower( $h );
		}, $header );

		$expected = array( 'external_id', 'card_number', 'nominal', 'status_1c' );
		$indexes  = array();
		foreach ( $expected as $col ) {
			$pos = array_search( $col, $header, true );
			if ( $pos === false ) {
				fclose( $handle );
				return sprintf( __( 'В файле должна быть колонка «%s».', 'woo-gift-physic-card' ), $col );
			}
			$indexes[ $col ] = $pos;
		}

		$optional = array( 'currency_code', 'balance' );
		foreach ( $optional as $col ) {
			$pos = array_search( $col, $header, true );
			if ( $pos !== false ) {
				$indexes[ $col ] = $pos;
			}
		}

		$rows = array();
		while ( ( $line = fgetcsv( $handle, 0, ';' ) ) !== false ) {
			if ( count( $line ) < 2 ) {
				continue;
			}
			$row = array(
				'external_id' => isset( $line[ $indexes['external_id'] ] ) ? trim( (string) $line[ $indexes['external_id'] ] ) : '',
				'card_number' => isset( $line[ $indexes['card_number'] ] ) ? trim( (string) $line[ $indexes['card_number'] ] ) : '',
				'nominal'     => isset( $line[ $indexes['nominal'] ] ) ? trim( (string) $line[ $indexes['nominal'] ] ) : '',
				'status_1c'   => isset( $line[ $indexes['status_1c'] ] ) ? trim( (string) $line[ $indexes['status_1c'] ] ) : '',
				'currency_code' => isset( $indexes['currency_code'], $line[ $indexes['currency_code'] ] ) ? trim( (string) $line[ $indexes['currency_code'] ] ) : '',
				'balance'       => isset( $indexes['balance'], $line[ $indexes['balance'] ] ) ? trim( (string) $line[ $indexes['balance'] ] ) : '',
			);
			$rows[] = $row;
		}
		fclose( $handle );

		return $rows;
	}
}
