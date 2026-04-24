<?php

declare(strict_types=1);

// phpcs:ignore PSR12.Files.FileHeader.IncorrectOrder -- WordPress bootstrap guard must precede other statements.
/**
 * Файл для управления пользователями кэшбэка в админке WordPress
 */

// Проверяем, что файл вызывается из WordPress
if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Users_Management_Admin {

    /**
     * Минимум символов в reason для ручной корректировки баланса (Группа 15, S2.A).
     * Смысл: короткий reason не помогает в аудите. Длина 20 — компромисс между UX
     * и обязательным пояснением причины списания/зачисления.
     */
    public const MIN_ADJUST_REASON_LENGTH = 20;

    private string $table_name;
    private string $profile_table_name;
    private string $user_balance_table;
    private string $ledger_table;

    public function __construct() {
        global $wpdb;
        $this->table_name         = $wpdb->prefix . 'users';
        $this->profile_table_name = $wpdb->prefix . 'cashback_user_profile';
        $this->user_balance_table = $wpdb->prefix . 'cashback_user_balance';
        $this->ledger_table       = $wpdb->prefix . 'cashback_balance_ledger';

        // Регистрируем хук для добавления пункта меню
        add_action('admin_menu', array( $this, 'add_admin_menu' ));

        // Обработка AJAX запросов
        add_action('wp_ajax_update_user_profile', array( $this, 'handle_update_user_profile' ));
        add_action('wp_ajax_get_user_profile', array( $this, 'handle_get_user_profile' ));
        add_action('wp_ajax_bulk_update_cashback_rate', array( $this, 'handle_bulk_update_cashback_rate' ));

        // Группа 15, S2: ручная корректировка баланса через ledger (type=adjustment).
        add_action('wp_ajax_cashback_adjust_balance', array( $this, 'handle_adjust_balance' ));

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

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page-detect, literal compare.
        $is_users_page = in_array($hook, $allowed_hooks, true) || ( isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-users' );

        if (!$is_users_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-users-css',
            plugins_url('../assets/css/admin.css', __FILE__),
            array(),
            '1.2.0'
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

        // Группа 15, S2.B: модал ручной корректировки баланса (vanilla JS,
        // переиспользуется из S3 через window.CashbackBalanceAdjust.open()).
        $ver = defined('CASHBACK_PLUGIN_VERSION') ? CASHBACK_PLUGIN_VERSION : '1.3.0';
        wp_enqueue_style(
            'cashback-admin-balance-adjust',
            plugins_url('../assets/css/admin-balance-adjust.css', __FILE__),
            array(),
            $ver
        );
        wp_enqueue_script(
            'cashback-admin-balance-adjust',
            plugins_url('../assets/js/admin-balance-adjust.js', __FILE__),
            array(),
            $ver,
            true
        );
        wp_localize_script('cashback-admin-balance-adjust', 'cashbackBalanceAdjust', array(
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('cashback_adjust_balance_nonce'),
            'minReasonLength'  => self::MIN_ADJUST_REASON_LENGTH,
            'amountPlaceholder'=> '+100.00 или -50.25',
            'i18n'             => array(
                'title'          => __('Ручная корректировка баланса', 'cashback-plugin'),
                'forUser'        => __('Пользователь ID:', 'cashback-plugin'),
                'amountLabel'    => __('Сумма корректировки', 'cashback-plugin'),
                'amountHint'     => __('Знак +/- обязателен. Не более 2 знаков после точки.', 'cashback-plugin'),
                'reasonLabel'    => sprintf(
                    /* translators: %d: минимум символов. */
                    __('Причина (минимум %d символов)', 'cashback-plugin'),
                    self::MIN_ADJUST_REASON_LENGTH
                ),
                'confirm'        => __('Я понимаю, что это запись в ledger с немедленным эффектом.', 'cashback-plugin'),
                'cancel'         => __('Отмена', 'cashback-plugin'),
                'apply'          => __('Применить', 'cashback-plugin'),
                'invalidAmount'  => __('Введите сумму в формате +100.00 или -50.25.', 'cashback-plugin'),
                'reasonTooShort' => __('Причина должна быть минимум {n} символов.', 'cashback-plugin'),
                'success'        => __('Корректировка применена. Новый баланс: {b}.', 'cashback-plugin'),
                'genericError'   => __('Ошибка применения корректировки.', 'cashback-plugin'),
                'networkError'   => __('Ошибка сети. Повторите.', 'cashback-plugin'),
            ),
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
            wp_die(esc_html__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // Получаем параметры для пагинации и фильтрации
        $max_allowed_pages = 1000;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing pagination, absint + capped.
        $current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        if ($current_page > $max_allowed_pages) {
            $current_page = $max_allowed_pages;
        }
        $per_page = 10;
        $offset   = ( $current_page - 1 ) * $per_page;

        // Получаем фильтр статуса с валидацией по допустимому списку
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing filter, allowlist-validated below.
        $filter_status           = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $allowed_filter_statuses = array( 'active', 'noactive', 'banned', 'deleted' );
        if (!empty($filter_status) && !in_array($filter_status, $allowed_filter_statuses, true)) {
            $filter_status = '';
        }

        // Получаем поисковый запрос
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing search, sanitized + wpdb->esc_like in WHERE.
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

        // Подсчет общего количества пользователей.
        // Таблицы идут через %i; $where_sql — allowlist условий с %s (+ безопасный fallback 'WHERE %d = %d').
        $count_params = array_merge(
            array( $this->table_name, $this->profile_table_name ),
            $where_params
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql из allowlist условий с %s/%d; таблицы через %i; sniff не считает spread-args.
        $total_users = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i u LEFT JOIN %i cup ON u.ID = cup.user_id {$where_sql}", ...$count_params ) );

        // Получаем пользователей с профилями.
        $select_params = array_merge(
            array( $this->table_name, $this->profile_table_name ),
            $where_params,
            array( $per_page, $offset )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql из allowlist условий с %s/%d; таблицы через %i; sniff не считает spread-args.
        $users = $wpdb->get_results( $wpdb->prepare( "SELECT u.ID, u.display_name, u.user_email, cup.cashback_rate, cup.min_payout_amount, cup.status, cup.ban_reason, cup.banned_at FROM %i u LEFT JOIN %i cup ON u.ID = cup.user_id {$where_sql} ORDER BY u.ID ASC LIMIT %d OFFSET %d", ...$select_params ), 'ARRAY_A' );

        // Определяем все доступные статусы для фильтра
        $statuses = array( 'active', 'noactive', 'banned', 'deleted' );

        // Выводим сообщения об ошибках или успехе
        $message = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice, allowlist-compared below.
        if (isset($_GET['message'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice, allowlist-compared below.
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
                                        <?php echo esc_html($user['banned_at'] ? wp_date('Y-m-d H:i:s', strtotime($user['banned_at'])) : ''); ?>
                                    </td>
                                    <td>
                                        <button class="button button-secondary edit-btn">Редактировать</button>
                                        <button class="button button-primary save-btn" style="display:none;">Сохранить</button>
                                        <button class="button button-default cancel-btn" style="display:none;">Отмена</button>
                                        <button
                                            type="button"
                                            class="button button-secondary cashback-adjust-balance-btn"
                                            data-user-id="<?php echo esc_attr($user['ID']); ?>"
                                            title="<?php esc_attr_e('Ручная корректировка баланса через ledger', 'cashback-plugin'); ?>"
                                        >
                                            <?php esc_html_e('Корректировка', 'cashback-plugin'); ?>
                                        </button>
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

            Cashback_Pagination::render($pagination_args);
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
                'SELECT cashback_rate FROM %i WHERE user_id = %d',
                $this->profile_table_name,
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
            // БЛОКИРУЕМ строку профиля с FOR UPDATE для предотвращения race conditions.
            $profile = $wpdb->get_row($wpdb->prepare(
                'SELECT status, ban_reason, banned_at FROM %i WHERE user_id = %d FOR UPDATE',
                $this->profile_table_name,
                $user_id
            ));

            if (!$profile) {
                throw new Exception('Профиль пользователя не найден');
            }

            $old_status         = $profile->status;
            $old_banned_at_unix = (isset($profile->banned_at) && $profile->banned_at)
                ? (int) strtotime((string) $profile->banned_at)
                : 0;

            // PHP-фолбэк: установка banned_at при бане / очистка при разбане
            if (isset($status)) {
                if ($old_status !== 'banned' && $status === 'banned') {
                    Cashback_Trigger_Fallbacks::set_banned_at($update_data);
                    $update_formats[] = '%s';
                } elseif ($old_status === 'banned' && $status !== 'banned') {
                    Cashback_Trigger_Fallbacks::clear_ban_fields($update_data);
                    $update_formats[] = '%s';
                    $update_formats[] = '%s';
                }
            }

            // Добавляем дату обновления
            $update_data['updated_at'] = current_time('mysql');
            $update_formats[]          = '%s';

            // Группа 14 (ledger-first): пишем ban_unfreeze в ledger ДО UPDATE profile.
            // Триггер tr_unfreeze_balance_on_unban (или PHP-fallback
            // Cashback_Trigger_Fallbacks::unfreeze_balance_on_unban) обнулит ban-бакеты
            // при срабатывании на UPDATE → нужно зафиксировать сумму, пока она ещё в
            // frozen_balance_ban / frozen_pending_balance_ban. Идемпотентно через
            // UNIQUE ledger.idempotency_key = ban_unfreeze_{user_id}_{old_banned_at}.
            if ($old_status === 'banned' && isset($status) && $status !== 'banned'
                && $old_banned_at_unix > 0 && class_exists('Cashback_Ban_Ledger')
            ) {
                Cashback_Ban_Ledger::write_unfreeze_entry($user_id, $old_banned_at_unix);
            }

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

                // Группа 14 (ledger-first): пишем ban_freeze в ledger ПОСЛЕ UPDATE profile
                // и после handle_user_ban (freeze_balance_on_ban fallback + триггер уже
                // переместили available/pending → frozen_*_ban). idempotency_key =
                // ban_freeze_{user_id}_{new_banned_at} детерминирован от set_banned_at().
                if (isset($update_data['banned_at']) && class_exists('Cashback_Ban_Ledger')) {
                    $new_banned_at_unix = (int) strtotime((string) $update_data['banned_at']);
                    if ($new_banned_at_unix > 0) {
                        Cashback_Ban_Ledger::write_freeze_entry($user_id, $new_banned_at_unix);
                    }
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

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Users] Error updating profile for user ' . $user_id . ': ' . $e->getMessage());
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении профиля пользователя.' ));
            return;
        }

        // Освобождаем withdrawal lock если захватывали
        if ($needs_withdrawal_lock) {
            $withdrawal_lock_released = true;
            $wpdb->query($wpdb->prepare('DO RELEASE_LOCK(%s)', $withdrawal_lock_name));
        }

        // Получаем обновленные данные из базы.
        $updated_user_data = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT cashback_rate, min_payout_amount, status, ban_reason, banned_at FROM %i WHERE user_id = %d',
                $this->profile_table_name,
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

        // Получаем данные пользователя из базы данных.
        $user_data = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT cashback_rate, min_payout_amount, status, ban_reason, banned_at FROM %i WHERE user_id = %d',
                $this->profile_table_name,
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

        // Server-side дедуп request_id (Группа 5 ADR, F-34-005).
        // Preview-запросы проходят без claim (read-only); apply-запросы защищены.
        $preview_raw     = !empty($_POST['preview']);
        $idem_scope      = 'admin_users_bulk_cashback_rate';
        $idem_user_id    = get_current_user_id();
        $idem_request_id = '';
        if (!$preview_raw && isset($_POST['request_id']) && is_string($_POST['request_id'])) {
            $idem_request_id = Cashback_Idempotency::normalize_request_id(
                sanitize_text_field(wp_unslash($_POST['request_id']))
            );
        }
        if ($idem_request_id !== '') {
            $idem_stored = Cashback_Idempotency::get_stored_result($idem_scope, $idem_user_id, $idem_request_id);
            if ($idem_stored !== null) {
                wp_send_json_success($idem_stored);
                return;
            }
            if (!Cashback_Idempotency::claim($idem_scope, $idem_user_id, $idem_request_id)) {
                wp_send_json_error(array(
                    'code'    => 'in_progress',
                    'message' => 'Запрос уже обрабатывается. Повторите через несколько секунд.',
                ), 409);
                return;
            }
        }

        if (!isset($_POST['old_rate'], $_POST['new_rate'])) {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => 'Не указаны параметры.' ));
            return;
        }

        $old_rate_raw = trim(sanitize_text_field(wp_unslash($_POST['old_rate'])));
        $new_rate     = sanitize_text_field(wp_unslash($_POST['new_rate']));
        $preview      = !empty($_POST['preview']);

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $new_rate) || bccomp($new_rate, '0', 2) < 0 || bccomp($new_rate, '100', 2) > 0) {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => 'Новая ставка должна быть числом от 0 до 100.' ));
            return;
        }

        global $wpdb;

        $is_all = ( strtolower($old_rate_raw) === 'all' );

        if (!$is_all) {
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $old_rate_raw) || bccomp($old_rate_raw, '0', 2) < 0 || bccomp($old_rate_raw, '100', 2) > 0) {
                if ($idem_request_id !== '') {
                    Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
                }
                wp_send_json_error(array( 'message' => 'Текущая ставка должна быть числом от 0 до 100 или "all".' ));
                return;
            }
        }

        if ($is_all) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE cashback_rate != %s',
                $this->profile_table_name,
                $new_rate
            ));
        } else {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE cashback_rate = %s',
                $this->profile_table_name,
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
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => 'Не найдено пользователей для обновления.' ));
            return;
        }

        $wpdb->query('START TRANSACTION');

        try {
            if ($is_all) {
                $result = $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET cashback_rate = %s, updated_at = %s WHERE cashback_rate != %s',
                    $this->profile_table_name,
                    $new_rate,
                    current_time('mysql'),
                    $new_rate
                ));
            } else {
                $result = $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET cashback_rate = %s, updated_at = %s WHERE cashback_rate = %s',
                    $this->profile_table_name,
                    $new_rate,
                    current_time('mysql'),
                    $old_rate_raw
                ));
            }

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                if ($idem_request_id !== '') {
                    Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
                }
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
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении базы данных.' ));
            return;
        }

        $response_data = array(
            'updated'  => (int) $result,
            'old_rate' => $old_rate_raw,
            'new_rate' => $new_rate,
        );

        if ($idem_request_id !== '') {
            Cashback_Idempotency::store_result($idem_scope, $idem_user_id, $idem_request_id, $response_data);
        }

        wp_send_json_success($response_data);
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
            // 🔒 БЛОКИРУЕМ активные заявки на выплату с FOR UPDATE.
            $active_requests = $wpdb->get_results($wpdb->prepare(
                "SELECT id, total_amount, status FROM %i WHERE user_id = %d AND status NOT IN ('failed', 'paid', 'declined') FOR UPDATE",
                $requests_table,
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
            if ($user && $user->user_email && class_exists('Cashback_Email_Sender') && class_exists('Cashback_Email_Builder')) {
                Cashback_Email_Sender::get_instance()->send_critical(
                    $user->user_email,
                    __('Ваш аккаунт кэшбэк заблокирован', 'cashback-plugin'),
                    Cashback_Email_Builder::banned_account_body(
                        $user->display_name !== '' ? $user->display_name : $user->user_login,
                        $ban_reason !== '' ? $ban_reason : __('Не указана', 'cashback-plugin'),
                        (string) get_option('admin_email')
                    ),
                    (int) $user->ID
                );
            }

            return true;

        } catch (Exception $e) {
            // Откатываем транзакцию если мы ее владельцы
            if (!$in_transaction) {
                $wpdb->query('ROLLBACK');
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
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
            // 🔒 БЛОКИРУЕМ declined заявки пользователя с FOR UPDATE.
            $declined_requests = $wpdb->get_results($wpdb->prepare(
                "SELECT id, fail_reason FROM %i WHERE user_id = %d AND status = 'declined' AND (fail_reason = '(Аккаунт забанен)' OR fail_reason = 'Account banned') FOR UPDATE",
                $requests_table,
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

            // Re-freeze affiliate-части ПОД ТОЙ ЖЕ TX — до COMMIT.
            // F-28-001: post-commit вызов давал race-окно, когда разбан
            // открывал affiliate-средства для вывода до повторной заморозки.
            if (class_exists('Cashback_Affiliate_Service')) {
                Cashback_Affiliate_Service::re_freeze_after_unban_atomic($user_id, true);
            }

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

            return true;

        } catch (Exception $e) {
            // Откатываем транзакцию если мы ее владельцы
            if (!$in_transaction) {
                $wpdb->query('ROLLBACK');
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Unban error for user ' . $user_id . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * AJAX: ручная корректировка баланса пользователя (Группа 15, S2.A).
     *
     * Контракт:
     *  - nonce = cashback_adjust_balance_nonce.
     *  - capability = manage_options.
     *  - server-side дедуп request_id через Cashback_Idempotency::claim
     *    (scope = admin_balance_adjust).
     *  - amount валидируется через Cashback_Money::from_string (Группа 10 ADR),
     *    zero отклоняется.
     *  - reason ≥ MIN_ADJUST_REASON_LENGTH символов.
     *  - INSERT в cashback_balance_ledger (type=adjustment, reference_type=manual)
     *    с UNIQUE idempotency_key; ON DUPLICATE KEY UPDATE id=id.
     *  - UPDATE cashback_user_balance с GREATEST(0.00, ...) clamp для списаний
     *    больше текущего available_balance (защита от отрицательного баланса
     *    при нестыковке кеша с ledger — ledger остаётся source of truth).
     *  - version инкремент (optimistic locking).
     *  - write_audit_log action=balance_manual_adjustment с deталями
     *    (amount, reason, idempotency_key, new_available_balance).
     */
    public function handle_adjust_balance(): void {
        // Nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(
            sanitize_text_field(wp_unslash((string) $_POST['nonce'])),
            'cashback_adjust_balance_nonce'
        )) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ), 403);
            return;
        }

        // Capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав.' ), 403);
            return;
        }

        // Server-side дедуп request_id (scope admin_balance_adjust, паттерн из admin/transactions.php).
        $idem_scope      = 'admin_balance_adjust';
        $idem_user_id    = get_current_user_id();
        $idem_request_id = '';
        if (isset($_POST['request_id']) && is_string($_POST['request_id'])) {
            $idem_request_id = Cashback_Idempotency::normalize_request_id(
                sanitize_text_field(wp_unslash($_POST['request_id']))
            );
        }
        if ($idem_request_id !== '') {
            $idem_stored = Cashback_Idempotency::get_stored_result($idem_scope, $idem_user_id, $idem_request_id);
            if ($idem_stored !== null) {
                wp_send_json_success($idem_stored);
                return;
            }
            if (!Cashback_Idempotency::claim($idem_scope, $idem_user_id, $idem_request_id)) {
                wp_send_json_error(array(
                    'code'    => 'in_progress',
                    'message' => 'Запрос уже обрабатывается. Повторите через несколько секунд.',
                ), 409);
                return;
            }
        }

        // Валидация user_id.
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        if ($user_id <= 0) {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => 'Некорректный ID пользователя.' ));
            return;
        }

        // Валидация amount через Cashback_Money VO (Группа 10 ADR).
        // Money-ввод: sanitize_text_field safe для canonical decimal (только digits/./+/-);
        // финальная валидация формата — strict regex в Cashback_Money::from_string ниже.
        $raw_amount = isset($_POST['amount'])
            ? sanitize_text_field(wp_unslash((string) $_POST['amount']))
            : '';
        $raw_amount = trim($raw_amount);
        // Разрешаем "+" префикс для явного положительного значения — вырезаем до валидатора.
        if ($raw_amount !== '' && $raw_amount[0] === '+') {
            $raw_amount = substr($raw_amount, 1);
        }
        try {
            $amount = Cashback_Money::from_string($raw_amount);
        } catch (\InvalidArgumentException $e) {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => 'Неверный формат суммы. Используйте число с точкой, например +100.00 или -50.25.' ));
            return;
        }
        if ($amount->is_zero()) {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array( 'message' => 'Сумма корректировки не может быть нулевой.' ));
            return;
        }

        // Валидация reason.
        $reason = isset($_POST['reason'])
            ? sanitize_textarea_field(wp_unslash((string) $_POST['reason']))
            : '';
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_ADJUST_REASON_LENGTH) {
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            wp_send_json_error(array(
                'message' => sprintf(
                    'Причина корректировки должна быть не короче %d символов.',
                    self::MIN_ADJUST_REASON_LENGTH
                ),
            ));
            return;
        }

        global $wpdb;
        $admin_id = (int) get_current_user_id();

        // Idempotency key: seed включает user_id, admin_id, reason, amount, time().
        // UNIQUE на level таблицы + ON DUPLICATE KEY UPDATE id=id предотвращает
        // дубликаты при race'ах. Коллизия по time() в одной секунде крайне маловероятна
        // — admin не кликает быстрее; retry в окне 5 мин ловится Idempotency::claim выше.
        $idempotency_key = 'adjust_' . sha1(
            $user_id . '_' . $admin_id . '_' . $reason . '_' . $amount->to_string() . '_' . time()
        );

        $wpdb->query('START TRANSACTION');

        try {
            // Lock-row в кеше баланса перед проверкой и UPDATE.
            $balance_row = $wpdb->get_row($wpdb->prepare(
                'SELECT available_balance, version
                 FROM %i
                 WHERE user_id = %d
                 FOR UPDATE',
                $this->user_balance_table,
                $user_id
            ), ARRAY_A);

            if (!$balance_row) {
                throw new \RuntimeException('Запись баланса пользователя не найдена.');
            }

            $current_available_raw = (string) $balance_row['available_balance'];
            try {
                $current_available = Cashback_Money::from_db_value($current_available_raw);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException('DECIMAL из БД не canonical: ' . $current_available_raw);
            }

            // При списании (amount<0) предупреждаем, если abs(amount) > available_balance.
            // Не блокируем: ledger — source of truth, и отрицательный кэш допустим как
            // временный индикатор (GREATEST clamp в UPDATE не даст уйти в минус в cache).
            // Но если списание больше баланса — мы не отказываем, admin знает, что делает
            // (reason ≥20 символов и audit-log фиксируют намерение).
            $projected = $current_available->add($amount);
            $would_go_negative = $projected->is_negative();

            // INSERT ledger: amount может быть отрицательным (DB DECIMAL(18,2) signed).
            $ledger_result = $wpdb->query($wpdb->prepare(
                'INSERT INTO %i (user_id, type, amount, reference_type, idempotency_key)
                 VALUES (%d, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE id = id',
                $this->ledger_table,
                $user_id,
                'adjustment',
                $amount->to_db_value(),
                'manual',
                $idempotency_key
            ));

            if ($ledger_result === false) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- diagnostic DB error, surfaced to admin only.
                throw new \RuntimeException('Ledger INSERT failed: ' . $wpdb->last_error);
            }

            $ledger_entry_id = (int) $wpdb->insert_id;

            // UPDATE cache: GREATEST(0.00, ...) защищает от ухода кеша в минус при
            // списании больше текущего available (source of truth — ledger).
            $update_result = $wpdb->query($wpdb->prepare(
                'UPDATE %i
                 SET available_balance = GREATEST(0.00, available_balance + %s),
                     version = version + 1
                 WHERE user_id = %d',
                $this->user_balance_table,
                $amount->to_db_value(),
                $user_id
            ));

            if ($update_result === false) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- diagnostic DB error.
                throw new \RuntimeException('Balance cache UPDATE failed: ' . $wpdb->last_error);
            }

            $new_balance_row = $wpdb->get_row($wpdb->prepare(
                'SELECT available_balance FROM %i WHERE user_id = %d',
                $this->user_balance_table,
                $user_id
            ), ARRAY_A);

            $new_available = $new_balance_row ? (string) $new_balance_row['available_balance'] : '0.00';

            // Audit-log: фиксируем admin_id, amount, reason, idempotency_key, новый баланс.
            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'balance_manual_adjustment',
                    $admin_id,
                    'user',
                    $user_id,
                    array(
                        'amount'                => $amount->to_string(),
                        'reason'                => $reason,
                        'idempotency_key'       => $idempotency_key,
                        'ledger_entry_id'       => $ledger_entry_id,
                        'previous_available'    => $current_available->to_string(),
                        'new_available'         => $new_available,
                        'would_go_negative'     => $would_go_negative,
                    )
                );
            }

            $wpdb->query('COMMIT');

            $response = array(
                'new_available_balance' => $new_available,
                'ledger_entry_id'       => $ledger_entry_id,
                'message'               => 'Корректировка применена.',
            );

            if ($idem_request_id !== '') {
                Cashback_Idempotency::store_result($idem_scope, $idem_user_id, $idem_request_id, $response);
            }

            wp_send_json_success($response);
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($idem_request_id !== '') {
                Cashback_Idempotency::forget($idem_scope, $idem_user_id, $idem_request_id);
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic logging.
            error_log('[cashback_adjust_balance] ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'Ошибка применения корректировки: ' . $e->getMessage(),
            ), 500);
        }
    }
}

// Инициализируем класс
$users_management_admin = new Cashback_Users_Management_Admin();
