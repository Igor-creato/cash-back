<?php

declare(strict_types=1);

/**
 * Файл для управления способами выплаты в админке WordPress
 */

// Проверяем, что файл вызывается из WordPress
if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Payout_Methods_Admin {


    private string $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cashback_payout_methods';

        // Регистрируем хук для добавления пункта меню
        add_action('admin_menu', array( $this, 'add_admin_menu' ));

        // Обработка AJAX запросов
        add_action('wp_ajax_update_payout_method', array( $this, 'handle_update_payout_method' ));
        add_action('wp_ajax_add_payout_method', array( $this, 'handle_add_payout_method' ));
        add_action('wp_ajax_save_withdrawal_settings', array( $this, 'handle_save_withdrawal_settings' ));

        // Подключение скриптов
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));
    }

    /**
     * Подключение скриптов и стилей для админ-панели
     */
    public function enqueue_admin_scripts( string $hook ): void {
        $allowed_hooks = array(
            'cashback-overview_page_cashback-payout-methods',
            'toplevel_page_cashback-payout-methods',
            'admin_page_cashback-payout-methods',
        );

        $is_methods_page = in_array($hook, $allowed_hooks, true) ||
            ( isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-payout-methods' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page slug detection for asset enqueue, no state change.

        if (!$is_methods_page) {
            return;
        }

        wp_enqueue_script(
            'cashback-admin-payout-methods',
            plugins_url('../assets/js/admin-payout-methods.js', __FILE__),
            array( 'jquery' ),
            '1.0.0',
            true
        );

        wp_localize_script('cashback-admin-payout-methods', 'cashbackPayoutMethodsData', array(
            'updateNonce'       => wp_create_nonce('update_payout_method_nonce'),
            'addNonce'          => wp_create_nonce('add_payout_method_nonce'),
            'saveSettingsNonce' => wp_create_nonce('save_withdrawal_settings_nonce'),
        ));
    }

    /**
     * Добавляем пункт меню в админке
     */
    public function add_admin_menu(): void {
        add_menu_page(
            'Кэшбэк',
            'Кэшбэк',
            'manage_options',
            'cashback-overview',
            array( $this, 'render_overview_page' ),
            'dashicons-money-alt',
            30
        );

        add_submenu_page(
            'cashback-overview',
            'Способы выплаты',
            'Способы выплаты',
            'manage_options',
            'cashback-payout-methods',
            array( $this, 'render_payout_methods_page' )
        );
    }

    /**
     * Отображаем страницу обзора кэшбэка
     */
    public function render_overview_page(): void {
        if (class_exists('Cashback_Statistics_Admin')) {
            Cashback_Statistics_Admin::get_instance()->render_overview_page();
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }
        echo '<div class="wrap"><h1>' . esc_html__('Кэшбэк', 'cashback-plugin') . '</h1>';
        echo '<p>' . esc_html__('Модуль статистики не загружен.', 'cashback-plugin') . '</p></div>';
    }

    /**
     * Отображаем страницу управления способами выплаты
     */
    public function render_payout_methods_page(): void {
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // Фильтр по статусу is_active
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field(wp_unslash($_GET['filter_status'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing filter, no state change.
        $is_filtered   = ( $filter_status !== '' && $filter_status !== 'all' );

        // Формируем WHERE-условие для фильтра
        $where_clause = '';
        if ($is_filtered) {
            $filter_value = intval($filter_status);
            $where_clause = $wpdb->prepare(' WHERE is_active = %d', $filter_value);
        }

        // Получаем все способы выплаты с учётом фильтра.
        if ($is_filtered) {
            $payout_methods = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE is_active = %d ORDER BY sort_order ASC',
                    $this->table_name,
                    $filter_value
                ),
                ARRAY_A
            );
        } else {
            $payout_methods = $wpdb->get_results(
                $wpdb->prepare( 'SELECT * FROM %i WHERE %d = %d ORDER BY sort_order ASC', $this->table_name, 1, 1 ),
                ARRAY_A
            );
        }

        // Выводим сообщения об ошибках или успехе
        $message = '';
        if (isset($_GET['message'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice message type (allowlist below), no state change.
            $msg_type = sanitize_text_field(wp_unslash($_GET['message'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice message type (allowlist below), no state change.
            if ($msg_type === 'added') {
                $message = '<div class="notice notice-success is-dismissible"><p>Способ выплаты успешно добавлен.</p></div>';
            } elseif ($msg_type === 'updated') {
                $message = '<div class="notice notice-success is-dismissible"><p>Способ выплаты успешно обновлен.</p></div>';
            }
        }

        ?>
            <div class="wrap">
                <h1 class="wp-heading-inline">Способы выплаты</h1>
                <hr class="wp-header-end">

                <?php echo wp_kses_post($message); ?>

                <div style="display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap; margin-bottom: 20px;">
                <!-- Форма добавления нового способа выплаты -->
                <div class="card" id="add-payout-method-form" style="margin: 0;">
                    <h2 class="title">Добавить способ выплаты</h2>
                    <form id="add-payout-method" method="post" action="">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="slug">Slug:</label></th>
                                <td><input type="text" id="slug" name="slug" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="name">Название:</label></th>
                                <td><input type="text" id="name" name="name" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="is_active">Активен:</label></th>
                                <td>
                                    <select id="is_active" name="is_active">
                                        <option value="1">Да</option>
                                        <option value="0">Нет</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="sort_order">Порядок сортировки:</label></th>
                                <td><input type="number" id="sort_order" name="sort_order" value="0" min="0" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="bank_required">Банк обязателен:</label></th>
                                <td>
                                    <select id="bank_required" name="bank_required">
                                        <option value="1">Да</option>
                                        <option value="0">Нет</option>
                                    </select>
                                    <p class="description">Если «Нет», пользователю не нужно выбирать банк при выводе через этот способ.</p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <input type="submit" name="add_payout_method" id="add-payout-method-submit" class="button button-primary" value="Добавить способ выплаты" />
                        </p>
                    </form>
                </div>

                <!-- Настройки выплат -->
                <div class="card" style="margin: 0;">
                    <h2 class="title">Настройки выплат</h2>
                    <form id="withdrawal-settings-form">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="max_withdrawal_amount">Максимальная сумма выплаты:</label></th>
                                <td>
                                    <input type="number" id="max_withdrawal_amount" name="max_withdrawal_amount" class="regular-text" value="<?php echo esc_attr(get_option('cashback_max_withdrawal_amount', 50000.00)); ?>" min="1" step="0.01" />
                                    <p class="description">Максимальная сумма, которую пользователь может вывести за одну заявку. По умолчанию: 50 000.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="balance_delay_days">Задержка начисления на баланс (дней):</label></th>
                                <td>
                                    <input type="number" id="balance_delay_days" name="balance_delay_days" class="regular-text" value="<?php echo esc_attr(get_option('cashback_balance_delay_days', 0)); ?>" min="0" max="365" step="1" />
                                    <p class="description">Минимальное количество дней с последнего обновления транзакции до зачисления кешбэка на баланс. 0 — без задержки (зачисление при ближайшей синхронизации).</p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <input type="submit" class="button button-primary" value="Сохранить настройки" />
                        </p>
                    </form>
                    <div id="withdrawal-settings-message"></div>
                </div>

                </div>

                <!-- Таблица существующих способов выплаты -->
                <h2 class="title">Существующие способы выплаты</h2>

                <!-- Фильтр по статусу активности -->
                <div class="search-box" style="margin-bottom: 15px;">
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                        <input type="hidden" name="page" value="cashback-payout-methods" />
                        <label for="filter-status" class="screen-reader-text">Фильтр по статусу:</label>
                        <select id="filter-status" name="filter_status">
                            <option value="all" <?php selected($filter_status, ''); ?><?php selected($filter_status, 'all'); ?>>Все статусы</option>
                            <option value="1" <?php selected($filter_status, '1'); ?>>Активные</option>
                            <option value="0" <?php selected($filter_status, '0'); ?>>Не активные</option>
                        </select>
                        <input type="submit" class="button" value="Фильтровать" />
                        <?php if ($is_filtered) : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-payout-methods')); ?>" class="button">Сбросить</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="wp-list-table-wrapper">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col">Slug</th>
                                <th scope="col">Название</th>
                                <th scope="col">Активен</th>
                                <th scope="col">Порядок сортировки</th>
                                <th scope="col">Банк обязателен</th>
                                <th scope="col">Действия</th>
                            </tr>
                        </thead>
                        <tbody id="payout-methods-tbody">
                            <?php if (!empty($payout_methods)) : ?>
                                <?php foreach ($payout_methods as $method) : ?>
                                    <tr data-id="<?php echo esc_attr($method['id']); ?>">
                                        <td class="edit-field" data-field="slug"><?php echo esc_html($method['slug']); ?></td>
                                        <td class="edit-field" data-field="name"><?php echo esc_html($method['name']); ?></td>
                                        <td class="edit-field" data-field="is_active">
                                            <?php echo $method['is_active'] ? 'Да' : 'Нет'; ?>
                                        </td>
                                        <td class="edit-field" data-field="sort_order"><?php echo esc_html($method['sort_order']); ?></td>
                                        <td class="edit-field" data-field="bank_required">
                                            <?php echo isset($method['bank_required']) && $method['bank_required'] ? 'Да' : 'Нет'; ?>
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
                                    <td colspan="6">Нет доступных способов выплаты.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
    <?php
    }

    /**
     * Обработка AJAX запроса на обновление способа выплаты
     */
    public function handle_update_payout_method(): void {
        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'update_payout_method_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        global $wpdb;

        $id            = intval(wp_unslash($_POST['id'] ?? 0));
        $slug          = sanitize_text_field(wp_unslash($_POST['slug'] ?? ''));
        $name          = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $is_active     = intval(wp_unslash($_POST['is_active'] ?? 0));
        $sort_order    = intval(wp_unslash($_POST['sort_order'] ?? 0));
        $bank_required = intval(wp_unslash($_POST['bank_required'] ?? 1));

        // Валидация данных
        if (empty($slug) || empty($name)) {
            wp_send_json_error(array( 'message' => 'Заполните все обязательные поля.' ));
            return;
        }

        // Обновляем запись в базе данных
        $result = $wpdb->update(
            $this->table_name,
            array(
                'slug'          => $slug,
                'name'          => $name,
                'is_active'     => $is_active,
                'sort_order'    => $sort_order,
                'bank_required' => $bank_required,
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%d', '%d', '%d' ),
            array( '%d' )
        );

        if ($result === false) {
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении способа выплаты в базе данных.' ));
            return;
        }

        // Возвращаем обновленные данные
        wp_send_json_success(array(
            'id'            => $id,
            'slug'          => $slug,
            'name'          => $name,
            'is_active'     => $is_active,
            'sort_order'    => $sort_order,
            'bank_required' => $bank_required,
        ));
    }

    /**
     * Обработка AJAX запроса на добавление способа выплаты
     */
    public function handle_add_payout_method(): void {
        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'add_payout_method_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        global $wpdb;

        $slug          = sanitize_text_field(wp_unslash($_POST['slug'] ?? ''));
        $name          = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $is_active     = intval(wp_unslash($_POST['is_active'] ?? 0));
        $sort_order    = intval(wp_unslash($_POST['sort_order'] ?? 0));
        $bank_required = intval(wp_unslash($_POST['bank_required'] ?? 1));

        // Валидация данных
        if (empty($slug) || empty($name)) {
            wp_send_json_error(array( 'message' => 'Заполните все обязательные поля.' ));
            return;
        }

        // Проверяем, существует ли уже способ выплаты с таким slug.
        $existing = $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE slug = %s', $this->table_name, $slug )
        );

        if ($existing > 0) {
            wp_send_json_error(array( 'message' => 'Способ выплаты с таким slug уже существует.' ));
            return;
        }

        // Добавляем новую запись в базу данных
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'slug'          => $slug,
                'name'          => $name,
                'is_active'     => $is_active,
                'sort_order'    => $sort_order,
                'bank_required' => $bank_required,
            ),
            array( '%s', '%s', '%d', '%d', '%d' )
        );

        if ($result === false) {
            wp_send_json_error(array( 'message' => 'Ошибка при добавлении способа выплаты в базе данных.' ));
            return;
        }

        wp_send_json_success();
    }

    /**
     * Обработка AJAX запроса на сохранение настроек выплат
     */
    public function handle_save_withdrawal_settings(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'save_withdrawal_settings_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        $max_amount = isset($_POST['max_withdrawal_amount']) ? (float) $_POST['max_withdrawal_amount'] : 0;

        if ($max_amount <= 0) {
            wp_send_json_error(array( 'message' => 'Максимальная сумма должна быть больше нуля.' ));
            return;
        }

        update_option('cashback_max_withdrawal_amount', $max_amount);

        $delay_days = isset($_POST['balance_delay_days']) ? absint($_POST['balance_delay_days']) : 0;
        $delay_days = min($delay_days, 365);
        update_option('cashback_balance_delay_days', $delay_days);

        wp_send_json_success(array( 'message' => 'Настройки сохранены.' ));
    }
}

// Инициализируем класс
$payout_methods_admin = new Cashback_Payout_Methods_Admin();
