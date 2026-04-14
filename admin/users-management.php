<?php

declare(strict_types=1);

/**
 * Файл для управления пользователями кэшбэка в админке WordPress
 */

// Проверяем, что файл вызывается из WordPress
if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Users_Management_Admin {

    use AdminPaginationTrait;

    private string $table_name;
    private string $profile_table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name         = $wpdb->prefix . 'users';
        $this->profile_table_name = $wpdb->prefix . 'cashback_user_profile';

        // Регистрируем хук для добавления пункта меню
        add_action('admin_menu', array( $this, 'add_admin_menu' ));

        // Обработка AJAX запросов
        add_action('wp_ajax_update_user_profile', array( $this, 'handle_update_user_profile' ));
        add_action('wp_ajax_get_user_profile', array( $this, 'handle_get_user_profile' ));
        add_action('wp_ajax_bulk_update_cashback_rate', array( $this, 'handle_bulk_update_cashback_rate' ));

        // Подключение скриптов
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));
    }

    /**
     * Подключение скриптов и стилей для админ-панели
     */
    public function enqueue_admin_scripts( string $hook ): void {
        $allowed_hooks = array(
            'cashback-overview_page_cashback-users',
            'toplevel_page_cashback-users',
            'admin_page_cashback-users',
        );

        $is_users_page = in_array($hook, $allowed_hooks, true) ||
            ( isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-users' );

        if (!$is_users_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-users-css',
            plugins_url('../assets/css/admin.css', __FILE__),
            array(),
            '1.0.1'
        );

        wp_enqueue_script(
            'cashback-admin-users',
            plugins_url('../assets/js/admin-users-management.js', __FILE__),
            array( 'jquery' ),
            '1.0.0',
            true
        );

        wp_localize_script('cashback-admin-users', 'cashbackUsersData', array(
            'updateNonce'   => wp_create_nonce('update_user_profile_nonce'),
            'getNonce'      => wp_create_nonce('get_user_profile_nonce'),
            'bulkRateNonce' => wp_create_nonce('bulk_update_cashback_rate_nonce'),
        ));
    }

    /**
     * Добавляем подпункт меню в админке
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'cashback-overview',
            'Пользователи',
            'Пользователи',
            'manage_options',
            'cashback-users',
            array( $this, 'render_users_page' )
        );
    }

    /**
     * Отображаем страницу управления пользователями
     */
    public function render_users_page(): void {
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_die(__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // Получаем параметры для пагинации и фильтрации
        $max_allowed_pages = 1000;
        $current_page      = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        if ($current_page > $max_allowed_pages) {
            $current_page = $max_allowed_pages;
        }
        $per_page = 10;
        $offset   = ( $current_page - 1 ) * $per_page;

        // Получаем фильтр статуса с валидацией по допустимому списку
        $filter_status           = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $allowed_filter_statuses = array( 'active', 'noactive', 'banned', 'deleted' );
        if (!empty($filter_status) && !in_array($filter_status, $allowed_filter_statuses, true)) {
            $filter_status = '';
        }

        // Получаем поисковый запрос
        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';

        // Построение WHERE условий
        $where_clauses = array();
        $where_params  = array();

        if (!empty($filter_status)) {
            $where_clauses[] = 'cup.status = %s';
            $where_params[]  = $filter_status;
        }

        if (!empty($search)) {
            $like            = '%' . $wpdb->esc_like($search) . '%';
            $where_clauses[] = '(u.user_email LIKE %s OR u.display_name LIKE %s)';
            $where_params[]  = $like;
            $where_params[]  = $like;
        }

        $where_sql = !empty($where_clauses)
            ? 'WHERE ' . implode(' AND ', $where_clauses)
            : 'WHERE %d = %d';

        if (empty($where_clauses)) {
            $where_params = array( 1, 1 );
        }

        // Подсчет общего количества пользователей
        $count_params = $where_params;
        $total_users  = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                FROM {$this->table_name} u
                LEFT JOIN {$this->profile_table_name} cup ON u.ID = cup.user_id
                {$where_sql}",
                ...$count_params
            )
        );

        // Получаем пользователей с профилями
        $select_params = array_merge($where_params, array( $per_page, $offset ));
        $users         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.ID, u.display_name, u.user_email,
                        cup.cashback_rate, cup.min_payout_amount, cup.status, cup.ban_reason, cup.banned_at
                FROM {$this->table_name} u
                LEFT JOIN {$this->profile_table_name} cup ON u.ID = cup.user_id
                {$where_sql}
                ORDER BY u.ID ASC
                LIMIT %d OFFSET %d",
                ...$select_params
            ),
            'ARRAY_A'
        );

        // Определяем все доступные статусы для фильтра
        $statuses = array( 'active', 'noactive', 'banned', 'deleted' );

        // Выводим сообщения об ошибках или успехе
        $message = '';
        if (isset($_GET['message'])) {
            $message_type = sanitize_text_field(wp_unslash($_GET['message']));
            if ($message_type === 'updated') {
                $message = '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Профиль пользователя успешно обновлен.', 'cashback-plugin') . '</p></div>';
            } elseif ($message_type === 'error') {
                $message = '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Ошибка при обновлении профиля пользователя.', 'cashback-plugin') . '</p></div>';
            }
        }

?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Пользователи</h1>
            <hr class="wp-header-end">

            <?php echo wp_kses_post($message); ?>

            <!-- Фильтр по статусу -->
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <label for="filter-status" class="screen-reader-text">Фильтр по статусу</label>
                    <select name="filter-status" id="filter-status">
                        <option value="">Все статусы</option>
                        <?php foreach ($statuses as $status) : ?>
                            <option value="<?php echo esc_attr($status); ?>" <?php selected($filter_status, $status); ?>>
                                <?php echo esc_html($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" id="filter-submit" class="button action">Фильтровать</button>
                </div>
                <div class="alignleft actions">
                    <label for="search-input" class="screen-reader-text">Поиск по email или имени</label>
                    <input type="search" id="search-input" name="search" value="<?php echo esc_attr($search); ?>" placeholder="Email или имя пользователя" />
                    <button type="button" id="search-submit" class="button action">Найти</button>
                    <?php if (!empty($filter_status) || !empty($search)) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-users')); ?>" class="button action">Сбросить</a>
                    <?php endif; ?>
                </div>
                <br class="clear">
            </div>

            <!-- Массовое изменение ставки кэшбэка -->
            <div class="postbox" style="padding: 12px 16px; margin-bottom: 20px;">
                <h3 style="margin: 0 0 10px;">Массовое изменение ставки кэшбэка</h3>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <label for="bulk-old-rate">Текущая ставка:</label>
                    <input type="text" id="bulk-old-rate" placeholder="60 или all" style="width: 100px;" />
                    <label for="bulk-new-rate">Новая ставка (%):</label>
                    <input type="number" id="bulk-new-rate" step="0.01" min="0" max="100" placeholder="65" style="width: 100px;" />
                    <button type="button" id="bulk-rate-preview" class="button">Предпросмотр</button>
                    <button type="button" id="bulk-rate-apply" class="button button-primary" disabled>Применить</button>
                    <span id="bulk-rate-info" style="color: #666;"></span>
                </div>
                <p class="description" style="margin-top: 10px;">
                    В поле <strong>«Текущая ставка»</strong> укажите процент, который нужно заменить (например, <code>60</code>),
                    или введите <code>all</code>, чтобы изменить ставку у всех пользователей сразу.<br>
                    В поле <strong>«Новая ставка»</strong> укажите новый процент кэшбэка (от 0 до 100).<br>
                    Нажмите <strong>«Предпросмотр»</strong>, чтобы увидеть количество затронутых пользователей, затем <strong>«Применить»</strong> для подтверждения.
                </p>
            </div>

            <!-- Таблица пользователей -->
            <div class="wp-list-table-wrap">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Имя пользователя</th>
                            <th scope="col">Email</th>
                            <th scope="col">Ставка кэшбэка (%)</th>
                            <th scope="col">Мин. сумма выплаты</th>
                            <th scope="col">Статус</th>
                            <th scope="col">Причина бана</th>
                            <th scope="col">Дата бана</th>
                            <th scope="col">Действия</th>
                        </tr>
                    </thead>
                    <tfoot>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Имя пользователя</th>
                            <th scope="col">Email</th>
                            <th scope="col">Ставка кэшбэка (%)</th>
                            <th scope="col">Мин. сумма выплаты</th>
                            <th scope="col">Статус</th>
                            <th scope="col">Причина бана</th>
                            <th scope="col">Дата бана</th>
                            <th scope="col">Действия</th>
                        </tr>
                    </tfoot>
                    <tbody id="users-tbody">
                        <?php if (!empty($users)) : ?>
                            <?php foreach ($users as $user) : ?>
                                <tr data-user-id="<?php echo esc_attr($user['ID']); ?>">
                                    <td><?php echo esc_html($user['ID']); ?></td>
                                    <td><?php echo esc_html($user['display_name']); ?></td>
                                    <td><?php echo esc_html($user['user_email']); ?></td>
                                    <td class="edit-field" data-field="cashback_rate">
                                        <?php echo esc_html($user['cashback_rate'] ?? '60.00'); ?>
                                    </td>
                                    <td class="edit-field" data-field="min_payout_amount">
                                        <?php echo esc_html($user['min_payout_amount'] ?? '100.00'); ?>
                                    </td>
                                    <td class="edit-field" data-field="status">
                                        <?php echo esc_html($user['status'] ?? 'active'); ?>
                                    </td>
                                    <td class="edit-field" data-field="ban_reason">
                                        <?php echo esc_html($user['ban_reason'] ?? ''); ?>
                                    </td>
                                    <td class="edit-field" data-field="banned_at">
                                        <?php echo esc_html($user['banned_at'] ? date('Y-m-d H:i:s', strtotime($user['banned_at'])) : ''); ?>
                                    </td>
                                    <td>
                                        <button class="button button-secondary edit-btn">Редактировать</button>
                                        <button class="button button-primary save-btn" style="display:none;">Сохранить</button>
                                        <button class="button button-default cancel-btn" style="display:none;">Отмена</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="9">Нет пользователей для отображения.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Пагинация -->
            <?php
            $pagination_args = array(
                'total_items'  => $total_users,
                'per_page'     => $per_page,
                'current_page' => $current_page,
                'total_pages'  => (int) ceil($total_users / $per_page),
                'page_slug'    => 'cashback-users',
                'add_args'     => array_filter(array(
                    'status' => $filter_status ?: null,
                    'search' => $search ?: null,
                )),
            );

            $this->render_pagination($pagination_args);
            ?>

        </div>
<?php
    }

    /**
     * Обработка AJAX запроса на обновление профиля пользователя
     */
    public function handle_update_user_profile(): void {
        // Проверяем наличие nonce
        if (!isset($_POST['nonce'])) {
            wp_send_json_error(array( 'message' => 'Отсутствует nonce.' ));
            return;
        }

        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'update_user_profile_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный nonce.' ));
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        // Проверяем наличие и корректность user_id
        if (!isset($_POST['user_id'])) {
            wp_send_json_error(array( 'message' => 'Отсутствует ID пользователя.' ));
            return;
        }

        global $wpdb;

        $user_id = intval($_POST['user_id']);

        if ($user_id <= 0) {
            wp_send_json_error(array( 'message' => 'Некорректный ID пользователя.' ));
            return;
        }

        $old_cashback_rate = null;
        $new_cashback_rate = null;
        if (isset($_POST['cashback_rate'])) {
            $old_cashback_rate = $wpdb->get_var($wpdb->prepare(
                "SELECT cashback_rate FROM {$this->profile_table_name} WHERE user_id = %d",
                $user_id
            ));
        }

        // Подготовим массив для обновления, включая только те поля, которые были переданы
        $update_data    = array();
        $update_formats = array();

        // Проверяем и добавляем только измененные поля
        if (isset($_POST['cashback_rate'])) {
            $cashback_rate     = sanitize_text_field(wp_unslash($_POST['cashback_rate']));
            $new_cashback_rate = $cashback_rate;

            // Валидация данных
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $cashback_rate) || bccomp($cashback_rate, '0', 2) < 0 || bccomp($cashback_rate, '100', 2) > 0) {
                wp_send_json_error(array( 'message' => 'Ставка кэшбэка должна быть числом от 0 до 100.' ));
                return;
            }

            $update_data['cashback_rate'] = $cashback_rate;
            $update_formats[]             = '%s';
        }

        if (isset($_POST['min_payout_amount'])) {
            $min_payout_amount = sanitize_text_field(wp_unslash($_POST['min_payout_amount']));

            if (!preg_match('/^\d+(\.\d{1,2})?$/', $min_payout_amount) || bccomp($min_payout_amount, '0', 2) <= 0) {
                wp_send_json_error(array( 'message' => 'Минимальная сумма выплаты должна быть положительным числом больше нуля.' ));
                return;
            }

            $update_data['min_payout_amount'] = $min_payout_amount;
            $update_formats[]                 = '%s';
        }

        if (isset($_POST['status'])) {
            $status = sanitize_text_field(wp_unslash($_POST['status']));

            // Проверяем, что статус допустим
            $allowed_statuses = array( 'active', 'noactive', 'banned', 'deleted' );
            if (!in_array($status, $allowed_statuses, true)) {
                wp_send_json_error(array( 'message' => 'Недопустимый статус пользователя.' ));
                return;
            }

            // Если устанавливаем статус "banned", проверяем обязательность причины бана
            if ($status === 'banned') {
                $ban_reason = isset($_POST['ban_reason']) ? trim(sanitize_text_field(wp_unslash($_POST['ban_reason']))) : '';
                if (empty($ban_reason)) {
                    wp_send_json_error(array( 'message' => 'Заполните причину бана пользователя.' ));
                    return;
                }
            }

            $update_data['status'] = $status;
            $update_formats[]      = '%s';
        }

        if (isset($_POST['ban_reason'])) {
            $ban_reason = sanitize_text_field(wp_unslash($_POST['ban_reason']));

            $update_data['ban_reason'] = $ban_reason;
            $update_formats[]          = '%s';
        }

        // 🔒 Если баним пользователя — сначала захватываем withdrawal lock
        // чтобы сериализовать с параллельным выводом средств
        $needs_withdrawal_lock = isset($status) && $status === 'banned';
        $withdrawal_lock_name  = 'user_withdrawal_' . $user_id;

        $withdrawal_lock_released = false;
        if ($needs_withdrawal_lock) {
            $lock_acquired = $wpdb->get_var($wpdb->prepare(
                'SELECT GET_LOCK(%s, 10)',
                $withdrawal_lock_name
            ));

            if (!$lock_acquired) {
                wp_send_json_error(array( 'message' => 'Пользователь в процессе вывода средств. Попробуйте позже.' ));
                return;
            }

            // Гарантированное освобождение блокировки даже при fatal error
            register_shutdown_function(function () use ( $wpdb, $withdrawal_lock_name, &$withdrawal_lock_released ) {
                if (!$withdrawal_lock_released) {
                    $withdrawal_lock_released = true;
                    $wpdb->query($wpdb->prepare('DO RELEASE_LOCK(%s)', $withdrawal_lock_name));
                }
            });
        }

        // 🔒 НАЧИНАЕМ ТРАНЗАКЦИЮ ДО чтения и обновления профиля
        $wpdb->query('START TRANSACTION');

        try {
            // БЛОКИРУЕМ строку профиля с FOR UPDATE для предотвращения race conditions
            $profile = $wpdb->get_row($wpdb->prepare(
                "SELECT status, ban_reason
                 FROM {$this->profile_table_name}
                 WHERE user_id = %d
                 FOR UPDATE",
                $user_id
            ));

            if (!$profile) {
                throw new Exception('Профиль пользователя не найден');
            }

            $old_status = $profile->status;

            // PHP-фолбэк: установка banned_at при бане / очистка при разбане
            if (isset($status)) {
                if ($old_status !== 'banned' && $status === 'banned') {
                    Cashback_Trigger_Fallbacks::set_banned_at($update_data);
                    $update_formats[] = '%s'; // banned_at
                } elseif ($old_status === 'banned' && $status !== 'banned') {
                    Cashback_Trigger_Fallbacks::clear_ban_fields($update_data);
                    $update_formats[] = '%s'; // banned_at (null)
                    $update_formats[] = '%s'; // ban_reason (null)
                }
            }

            // Добавляем дату обновления
            $update_data['updated_at'] = current_time('mysql');
            $update_formats[]          = '%s';

            // Обновляем только те поля, которые были изменены
            $result = $wpdb->update(
                $this->profile_table_name,
                $update_data,
                array( 'user_id' => $user_id ),
                $update_formats,
                array( '%d' )  // Формат условия
            );

            if ($result === false) {
                throw new Exception('Ошибка при обновлении профиля пользователя в базе данных');
            }

            // Если пользователь был забанен - обрабатываем последствия ВНУТРИ транзакции
            if (isset($status) && $status === 'banned') {
                $ban_reason = isset($_POST['ban_reason']) ? sanitize_text_field(wp_unslash($_POST['ban_reason'])) : '';

                // Перехватываем любой вывод, который может сломать JSON-ответ
                ob_start();
                $ban_success = $this->handle_user_ban($user_id, $ban_reason, true);
                ob_end_clean();

                if (!$ban_success) {
                    throw new Exception('Ошибка при обработке бана пользователя');
                }
            }

            // Если пользователь был разбанен - обрабатываем последствия ВНУТРИ транзакции
            if ($old_status === 'banned' && isset($status) && $status !== 'banned') {
                // Перехватываем любой вывод, который может сломать JSON-ответ
                ob_start();
                $unban_success = $this->handle_user_unban($user_id, true);
                ob_end_clean();

                if (!$unban_success) {
                    throw new Exception('Ошибка при обработке разбана пользователя');
                }
            }

            if ($old_cashback_rate !== null && $new_cashback_rate !== null && $old_cashback_rate !== $new_cashback_rate) {
                if (class_exists('Cashback_Rate_History_Admin')) {
                    Cashback_Rate_History_Admin::log_rate_change(
                        'cashback',
                        $user_id,
                        (float) $old_cashback_rate,
                        (float) $new_cashback_rate,
                        1,
                        'manual'
                    );
                }
            }

            // ✅ ФИКСИРУЕМ транзакцию
            $wpdb->query('COMMIT');

        } catch (Exception $e) {
            // ❌ ОТКАТЫВАЕМ транзакцию при любой ошибке
            $wpdb->query('ROLLBACK');

            // Освобождаем withdrawal lock если захватывали
            if ($needs_withdrawal_lock) {
                $withdrawal_lock_released = true;
                $wpdb->query($wpdb->prepare('DO RELEASE_LOCK(%s)', $withdrawal_lock_name));
            }

            error_log('[Cashback Users] Error updating profile for user ' . $user_id . ': ' . $e->getMessage());
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении профиля пользователя.' ));
            return;
        }

        // Освобождаем withdrawal lock если захватывали
        if ($needs_withdrawal_lock) {
            $withdrawal_lock_released = true;
            $wpdb->query($wpdb->prepare('DO RELEASE_LOCK(%s)', $withdrawal_lock_name));
        }

        // Получаем обновленные данные из базы
        $updated_user_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cashback_rate, min_payout_amount, status, ban_reason, banned_at 
                 FROM {$this->profile_table_name} 
                 WHERE user_id = %d",
                $user_id
            ),
            ARRAY_A
        );

        if (!$updated_user_data) {
            wp_send_json_error(array( 'message' => 'Не удалось получить обновленные данные пользователя.' ));
            return;
        }

        // Возвращаем обновленные данные
        wp_send_json_success($updated_user_data);
    }

    /**
     * Обработка AJAX запроса на получение профиля пользователя
     */
    public function handle_get_user_profile(): void {
        // Проверяем наличие nonce
        if (!isset($_POST['nonce'])) {
            wp_send_json_error(array( 'message' => 'Отсутствует nonce.' ));
            return;
        }

        // Проверяем nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'get_user_profile_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный nonce.' ));
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        // Проверяем наличие user_id
        if (!isset($_POST['user_id'])) {
            wp_send_json_error(array( 'message' => 'Отсутствует ID пользователя.' ));
            return;
        }

        global $wpdb;

        $user_id = intval($_POST['user_id']);

        // Получаем данные пользователя из базы данных
        $user_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cashback_rate, min_payout_amount, status, ban_reason, banned_at 
                 FROM {$this->profile_table_name} 
                 WHERE user_id = %d",
                $user_id
            ),
            ARRAY_A
        );

        if (!$user_data) {
            // Если записи нет, возвращаем значения по умолчанию
            $user_data = array(
                'cashback_rate'     => '60.00',
                'min_payout_amount' => '100.00',
                'status'            => 'active',
                'ban_reason'        => '',
                'banned_at'         => null,
            );
        }

        // Возвращаем данные
        wp_send_json_success($user_data);
    }

    /**
     * Обработка AJAX запроса на массовое обновление ставки кэшбэка.
     */
    public function handle_bulk_update_cashback_rate(): void {
        if (!isset($_POST['nonce'])) {
            wp_send_json_error(array( 'message' => 'Отсутствует nonce.' ));
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'bulk_update_cashback_rate_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный nonce.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        if (!isset($_POST['old_rate'], $_POST['new_rate'])) {
            wp_send_json_error(array( 'message' => 'Не указаны параметры.' ));
            return;
        }

        $old_rate_raw = trim(sanitize_text_field(wp_unslash($_POST['old_rate'])));
        $new_rate     = sanitize_text_field(wp_unslash($_POST['new_rate']));
        $preview      = !empty($_POST['preview']);

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $new_rate) || bccomp($new_rate, '0', 2) < 0 || bccomp($new_rate, '100', 2) > 0) {
            wp_send_json_error(array( 'message' => 'Новая ставка должна быть числом от 0 до 100.' ));
            return;
        }

        global $wpdb;

        $is_all = ( strtolower($old_rate_raw) === 'all' );

        if (!$is_all) {
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $old_rate_raw) || bccomp($old_rate_raw, '0', 2) < 0 || bccomp($old_rate_raw, '100', 2) > 0) {
                wp_send_json_error(array( 'message' => 'Текущая ставка должна быть числом от 0 до 100 или "all".' ));
                return;
            }
        }

        if ($is_all) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->profile_table_name} WHERE cashback_rate != %s",
                $new_rate
            ));
        } else {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->profile_table_name} WHERE cashback_rate = %s",
                $old_rate_raw
            ));
        }

        if ($preview) {
            wp_send_json_success(array(
                'count'    => $count,
                'old_rate' => $old_rate_raw,
                'new_rate' => $new_rate,
            ));
            return;
        }

        if ($count === 0) {
            wp_send_json_error(array( 'message' => 'Не найдено пользователей для обновления.' ));
            return;
        }

        $wpdb->query('START TRANSACTION');

        try {
            if ($is_all) {
                $result = $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->profile_table_name} SET cashback_rate = %s, updated_at = %s WHERE cashback_rate != %s",
                    $new_rate,
                    current_time('mysql'),
                    $new_rate
                ));
            } else {
                $result = $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->profile_table_name} SET cashback_rate = %s, updated_at = %s WHERE cashback_rate = %s",
                    $new_rate,
                    current_time('mysql'),
                    $old_rate_raw
                ));
            }

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array( 'message' => 'Ошибка при обновлении базы данных.' ));
                return;
            }

            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'bulk_cashback_rate_update',
                    get_current_user_id(),
                    'cashback_user_profile',
                    null,
                    array(
                        'old_rate'       => $old_rate_raw,
                        'new_rate'       => $new_rate,
                        'affected_users' => $result,
                    )
                );
            }

            if (class_exists('Cashback_Rate_History_Admin')) {
                Cashback_Rate_History_Admin::log_rate_change(
                    $is_all ? 'cashback_global' : 'cashback',
                    null,
                    $is_all ? null : (float) $old_rate_raw,
                    (float) $new_rate,
                    (int) $result,
                    'bulk',
                    array(
						'scope'    => $is_all ? 'all' : 'by_rate',
						'old_rate' => $old_rate_raw,
					)
                );
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении базы данных.' ));
            return;
        }

        wp_send_json_success(array(
            'updated'  => (int) $result,
            'old_rate' => $old_rate_raw,
            'new_rate' => $new_rate,
        ));
    }

    /**
     * Обработка последствий бана пользователя
     *
     * @param int $user_id ID забаненного пользователя
     * @param string $ban_reason Причина бана
     * @param bool $in_transaction Флаг, указывающий что метод вызван внутри транзакции
     * @return bool Успешность операции
     */
    private function handle_user_ban( int $user_id, string $ban_reason, bool $in_transaction = false ): bool {
        global $wpdb;
        $requests_table = $wpdb->prefix . 'cashback_payout_requests';

        // Начинаем транзакцию только если еще не внутри
        if (!$in_transaction) {
            $wpdb->query('START TRANSACTION');
        }

        try {
            // 🔒 БЛОКИРУЕМ активные заявки на выплату с FOR UPDATE
            $active_requests = $wpdb->get_results($wpdb->prepare(
                "SELECT id, total_amount, status
                 FROM {$requests_table}
                 WHERE user_id = %d
                 AND status NOT IN ('failed', 'paid', 'declined')
                 FOR UPDATE",
                $user_id
            ));

            // Обновляем все заявки на declined
            foreach ($active_requests as $request) {
                $result = $wpdb->update(
                    $requests_table,
                    array(
                        'status'      => 'declined',
                        'fail_reason' => '(Аккаунт забанен)',
                        'updated_at'  => current_time('mysql'),
                    ),
                    array( 'id' => $request->id ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );

                if ($result === false) {
                    throw new Exception("Failed to decline payout request {$request->id}");
                }

                // Логируем отмену
                if (class_exists('Cashback_Encryption')) {
                    Cashback_Encryption::write_audit_log(
                        'payout_declined_on_ban',
                        get_current_user_id(),
                        'payout_request',
                        $request->id,
                        array(
							'amount'  => $request->total_amount,
							'user_id' => $user_id,
						)
                    );
                }
            }

            // PHP-фолбэк: заморозка баланса (идемпотентно при наличии триггера)
            Cashback_Trigger_Fallbacks::freeze_balance_on_ban($user_id);

            // Логируем бан пользователя
            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'user_banned',
                    get_current_user_id(),
                    'user',
                    $user_id,
                    array( 'ban_reason' => $ban_reason )
                );
            }

            // Фиксируем транзакцию если мы ее владельцы
            if (!$in_transaction) {
                $wpdb->query('COMMIT');
            }

            // ✉️ Email ПОСЛЕ транзакции (некритичная операция)
            $user = get_userdata($user_id);
            if ($user && $user->user_email) {
                $subject = 'Ваш аккаунт кэшбэк заблокирован';
                $message = sprintf(
                    "Здравствуйте, %s!\n\nВаш аккаунт кэшбэк был заблокирован.\nПричина: %s\n\nВаш баланс был заморожен.\nДля разблокировки обратитесь к администратору: %s",
                    $user->display_name,
                    $ban_reason ?: 'Не указана',
                    get_option('admin_email')
                );
                wp_mail($user->user_email, $subject, $message);
            }

            return true;

        } catch (Exception $e) {
            // Откатываем транзакцию если мы ее владельцы
            if (!$in_transaction) {
                $wpdb->query('ROLLBACK');
            }
            error_log('Ban error for user ' . $user_id . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Обработка последствий разбана пользователя
     *
     * @param int $user_id ID разбаненного пользователя
     * @param bool $in_transaction Флаг, указывающий что метод вызван внутри транзакции
     * @return bool Успешность операции
     */
    private function handle_user_unban( int $user_id, bool $in_transaction = false ): bool {
        global $wpdb;
        $requests_table = $wpdb->prefix . 'cashback_payout_requests';

        // Начинаем транзакцию только если еще не внутри
        if (!$in_transaction) {
            $wpdb->query('START TRANSACTION');
        }

        try {
            // 🔒 БЛОКИРУЕМ declined заявки пользователя с FOR UPDATE
            $declined_requests = $wpdb->get_results($wpdb->prepare(
                "SELECT id, fail_reason
                 FROM {$requests_table}
                 WHERE user_id = %d
                 AND status = 'declined'
                 AND (fail_reason = '(Аккаунт забанен)' OR fail_reason = 'Account banned')
                 FOR UPDATE",
                $user_id
            ));

            // Обновляем fail_reason на прошедшее время
            foreach ($declined_requests as $request) {
                $result = $wpdb->update(
                    $requests_table,
                    array( 'fail_reason' => '(Аккаунт был забанен)' ),
                    array( 'id' => $request->id ),
                    array( '%s' ),
                    array( '%d' )
                );

                if ($result === false) {
                    throw new Exception("Failed to update payout request {$request->id}");
                }
            }

            // PHP-фолбэк: разморозка баланса (идемпотентно при наличии триггера)
            Cashback_Trigger_Fallbacks::unfreeze_balance_on_unban($user_id);

            // Логируем разбан
            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'user_unbanned',
                    get_current_user_id(),
                    'user',
                    $user_id,
                    array()
                );
            }

            // Фиксируем транзакцию если мы ее владельцы
            if (!$in_transaction) {
                $wpdb->query('COMMIT');
            }

            // Post-commit: если affiliate отключён, повторно заморозить affiliate-часть
            // (разбан вернул всё frozen в available, нужно вернуть affiliate frozen)
            if (class_exists('Cashback_Affiliate_Service')) {
                Cashback_Affiliate_Service::re_freeze_after_unban($user_id);
            }

            return true;

        } catch (Exception $e) {
            // Откатываем транзакцию если мы ее владельцы
            if (!$in_transaction) {
                $wpdb->query('ROLLBACK');
            }
            error_log('Unban error for user ' . $user_id . ': ' . $e->getMessage());
            return false;
        }
    }

    // render_pagination() предоставляется через AdminPaginationTrait
}

// Инициализируем класс
$users_management_admin = new Cashback_Users_Management_Admin();
