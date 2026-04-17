<?php

declare(strict_types=1);

/**
 * Файл для управления банками в админке WordPress
 */

// Проверяем, что файл вызывается из WordPress
if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Bank_Management_Admin {


    private string $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cashback_banks';

        // Регистрируем хук для добавления пункта меню
        add_action('admin_menu', array( $this, 'add_admin_menu' ));

        // Обработка AJAX запросов
        add_action('wp_ajax_update_bank', array( $this, 'handle_update_bank' ));
        add_action('wp_ajax_add_bank', array( $this, 'handle_add_bank' ));

        // Подключение скриптов
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));
    }

    /**
     * Подключение скриптов и стилей для админ-панели
     */
    public function enqueue_admin_scripts( string $hook ): void {
        $allowed_hooks = array(
            'cashback-overview_page_cashback-banks',
            'toplevel_page_cashback-banks',
            'admin_page_cashback-banks',
        );

        $is_banks_page = in_array($hook, $allowed_hooks, true) ||
            ( isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-banks' );

        if (!$is_banks_page) {
            return;
        }

        wp_enqueue_script(
            'cashback-admin-banks',
            plugins_url('../assets/js/admin-bank-management.js', __FILE__),
            array( 'jquery' ),
            '1.0.0',
            true
        );

        wp_localize_script('cashback-admin-banks', 'cashbackBanksData', array(
            'updateNonce' => wp_create_nonce('update_bank_nonce'),
            'addNonce'    => wp_create_nonce('add_bank_nonce'),
        ));
    }

    /**
     * Добавляем пункт меню в админке
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'cashback-overview',
            'Банки',
            'Банки',
            'manage_options',
            'cashback-banks',
            array( $this, 'render_banks_page' )
        );
    }

    /**
     * Отображаем страницу управления банками
     */
    public function render_banks_page(): void {
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            wp_die(__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // Поисковый запрос
        $search_query = isset($_GET['bank_search']) ? sanitize_text_field(wp_unslash($_GET['bank_search'])) : '';
        $search_query = mb_substr($search_query, 0, 100);
        $is_search    = !empty($search_query);

        // Фильтр по статусу is_active
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field(wp_unslash($_GET['filter_status'])) : '';
        $is_filtered   = ( $filter_status !== '' && $filter_status !== 'all' );

        // Пагинация: настройки
        $per_page     = 10;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset       = ( $current_page - 1 ) * $per_page;

        // Формируем WHERE-условия
        $where_conditions = array();
        $where_values     = array();

        if ($is_search) {
            $like_pattern       = '%' . $wpdb->esc_like($search_query) . '%';
            $where_conditions[] = '(name LIKE %s OR short_name LIKE %s OR bank_code LIKE %s)';
            $where_values[]     = $like_pattern;
            $where_values[]     = $like_pattern;
            $where_values[]     = $like_pattern;
        }

        if ($is_filtered) {
            $where_conditions[] = 'is_active = %d';
            $where_values[]     = intval($filter_status);
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
        }

        // Общее количество банков
        if (!empty($where_values)) {
            $count_query = "SELECT COUNT(*) FROM %i{$where_clause}";
            $count_args  = array_merge(array( $this->table_name ), $where_values);
            $total_banks = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $where_clause собран из литералов с placeholder'ами %s/%d, имя таблицы экранируется через %i.
                $wpdb->prepare($count_query, ...$count_args)
            );

            // Получаем банки с учётом фильтров и пагинации
            $select_query = "SELECT * FROM %i{$where_clause} ORDER BY sort_order ASC, name ASC LIMIT %d OFFSET %d";
            $select_args  = array_merge(array( $this->table_name ), $where_values, array( $per_page, $offset ));
            $banks        = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $where_clause собран из литералов с placeholder'ами %s/%d, имя таблицы экранируется через %i.
                $wpdb->prepare($select_query, ...$select_args),
                ARRAY_A
            );
        } else {
            $total_banks = (int) $wpdb->get_var(
                $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %d = %d', $this->table_name, 1, 1)
            );

            $banks = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i ORDER BY sort_order ASC, name ASC LIMIT %d OFFSET %d',
                    $this->table_name,
                    $per_page,
                    $offset
                ),
                ARRAY_A
            );
        }

        // Вычисляем общее количество страниц
        $total_pages = (int) ceil($total_banks / $per_page);

        // Выводим сообщения об ошибках или успехе
        $message = '';
        if (isset($_GET['message'])) {
            $msg_type = sanitize_text_field(wp_unslash($_GET['message']));
            if ($msg_type === 'added') {
                $message = '<div class="notice notice-success is-dismissible"><p>Банк успешно добавлен.</p></div>';
            } elseif ($msg_type === 'updated') {
                $message = '<div class="notice notice-success is-dismissible"><p>Банк успешно обновлен.</p></div>';
            }
        }
?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Банки</h1>
            <hr class="wp-header-end">

            <?php echo wp_kses_post($message); ?>

            <!-- Форма добавления нового банка -->
            <div class="card" id="add-bank-form" style="margin-bottom: 20px;">
                <h2 class="title">Добавить банк</h2>
                <form id="add-bank" method="post" action="">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="bank_code">Код банка:</label></th>
                            <td><input type="text" id="bank_code" name="bank_code" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="name">Полное название:</label></th>
                            <td><input type="text" id="name" name="name" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="short_name">Краткое название:</label></th>
                            <td><input type="text" id="short_name" name="short_name" class="regular-text" /></td>
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
                    </table>
                    <p class="submit">
                        <input type="submit" name="add_bank" id="add-bank-submit" class="button button-primary" value="Добавить банк" />
                    </p>
                </form>
            </div>

            <!-- Таблица существующих банков -->
            <h2 class="title">Существующие банки</h2>

            <!-- Фильтр по статусу и поиск по банкам -->
            <div class="search-box" style="margin-bottom: 15px;">
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                    <input type="hidden" name="page" value="cashback-banks" />
                    <label for="filter-status" class="screen-reader-text">Фильтр по статусу:</label>
                    <select id="filter-status" name="filter_status">
                        <option value="all" <?php selected($filter_status, ''); ?><?php selected($filter_status, 'all'); ?>>Все статусы</option>
                        <option value="1" <?php selected($filter_status, '1'); ?>>Активные</option>
                        <option value="0" <?php selected($filter_status, '0'); ?>>Не активные</option>
                    </select>
                    <label class="screen-reader-text" for="bank-search-input">Поиск банков:</label>
                    <input type="search" id="bank-search-input" name="bank_search"
                        value="<?php echo esc_attr($search_query); ?>"
                        placeholder="Введите название банка или его часть" style="min-width: 300px;" />
                    <input type="submit" id="search-submit" class="button" value="Фильтровать" />
                    <?php if ($is_filtered || $is_search) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-banks')); ?>" class="button">Сбросить</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($is_search) : ?>
                <p>Результаты поиска по запросу: <strong>&laquo;<?php echo esc_html($search_query); ?>&raquo;</strong>
                    — найдено: <?php echo esc_html((string) $total_banks); ?></p>
            <?php endif; ?>

            <div class="wp-list-table-wrapper">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col">Код банка</th>
                            <th scope="col">Полное название</th>
                            <th scope="col">Краткое название</th>
                            <th scope="col">Активен</th>
                            <th scope="col">Порядок сортировки</th>
                            <th scope="col">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="banks-tbody">
                        <?php if (!empty($banks)) : ?>
                            <?php foreach ($banks as $bank) : ?>
                                <tr data-id="<?php echo esc_attr($bank['id']); ?>">
                                    <td class="edit-field" data-field="bank_code"><?php echo esc_html($bank['bank_code']); ?></td>
                                    <td class="edit-field" data-field="name"><?php echo esc_html($bank['name']); ?></td>
                                    <td class="edit-field" data-field="short_name"><?php echo esc_html($bank['short_name'] ?: ''); ?></td>
                                    <td class="edit-field" data-field="is_active">
                                        <?php echo $bank['is_active'] ? 'Да' : 'Нет'; ?>
                                    </td>
                                    <td class="edit-field" data-field="sort_order"><?php echo esc_html($bank['sort_order']); ?></td>
                                    <td>
                                        <button class="button button-secondary edit-btn">Редактировать</button>
                                        <button class="button button-primary save-btn" style="display:none;">Сохранить</button>
                                        <button class="button button-default cancel-btn" style="display:none;">Отмена</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="6">
                                    <?php if ($is_search) : ?>
                                        По запросу &laquo;<?php echo esc_html($search_query); ?>&raquo; банки не найдены.
                                    <?php else : ?>
                                        Нет доступных банков.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_banks > $per_page) : ?>
                <!-- Пагинация -->
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php
                            printf(
                                '%s из %s',
                                esc_html(number_format_i18n(count($banks))),
                                esc_html(number_format_i18n($total_banks))
                            );
                            ?>
                        </span>
                        <?php
                        $pagination_base_args = array(
							'page'  => 'cashback-banks',
							'paged' => '%#%',
						);
                        if ($is_search) {
                            $pagination_base_args['bank_search'] = $search_query;
                        }
                        if ($is_filtered) {
                            $pagination_base_args['filter_status'] = $filter_status;
                        }
                        $pagination_links = paginate_links(array(
                            'base'      => add_query_arg($pagination_base_args, admin_url('admin.php')),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $current_page,
                            'type'      => 'plain',
                        ));

                        if ($pagination_links) {
                            echo wp_kses_post($pagination_links);
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
<?php
    }

    /**
     * Обработка AJAX запроса на обновление банка
     */
    public function handle_update_bank(): void {
        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'update_bank_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        global $wpdb;

        $id         = intval(wp_unslash($_POST['id'] ?? 0));
        $bank_code  = sanitize_text_field(wp_unslash($_POST['bank_code'] ?? ''));
        $name       = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $short_name = sanitize_text_field(wp_unslash($_POST['short_name'] ?? ''));
        $is_active  = intval(wp_unslash($_POST['is_active'] ?? 0));
        $sort_order = intval(wp_unslash($_POST['sort_order'] ?? 0));

        // Валидация данных
        if (empty($bank_code) || empty($name)) {
            wp_send_json_error(array( 'message' => 'Заполните все обязательные поля.' ));
            return;
        }

        // Обновляем запись в базе данных
        $result = $wpdb->update(
            $this->table_name,
            array(
                'bank_code'  => $bank_code,
                'name'       => $name,
                'short_name' => $short_name,
                'is_active'  => $is_active,
                'sort_order' => $sort_order,
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%d', '%d' ),
            array( '%d' )
        );

        if ($result === false) {
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении банка в базе данных.' ));
            return;
        }

        // Возвращаем обновленные данные
        wp_send_json_success(array(
            'id'         => $id,
            'bank_code'  => $bank_code,
            'name'       => $name,
            'short_name' => $short_name,
            'is_active'  => $is_active,
            'sort_order' => $sort_order,
        ));
    }

    /**
     * Обработка AJAX запроса на добавление банка
     */
    public function handle_add_bank(): void {
        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'add_bank_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        // Проверяем права пользователя
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        global $wpdb;

        $bank_code  = sanitize_text_field(wp_unslash($_POST['bank_code'] ?? ''));
        $name       = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $short_name = sanitize_text_field(wp_unslash($_POST['short_name'] ?? ''));
        $is_active  = intval(wp_unslash($_POST['is_active'] ?? 0));
        $sort_order = intval(wp_unslash($_POST['sort_order'] ?? 0));

        // Валидация данных
        if (empty($bank_code) || empty($name)) {
            wp_send_json_error(array( 'message' => 'Заполните все обязательные поля.' ));
            return;
        }

        // Проверяем, существует ли уже банк с таким bank_code, name или short_name
        if (!empty($short_name)) {
            $existing = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE bank_code = %s OR name = %s OR short_name = %s',
                    $this->table_name,
                    $bank_code,
                    $name,
                    $short_name
                )
            );
        } else {
            $existing = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE bank_code = %s OR name = %s',
                    $this->table_name,
                    $bank_code,
                    $name
                )
            );
        }

        if ($existing > 0) {
            wp_send_json_error(array( 'message' => 'Такой банк уже добавлен, добавьте другой банк.' ));
            return;
        }

        // Добавляем новую запись в базу данных
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'bank_code'  => $bank_code,
                'name'       => $name,
                'short_name' => $short_name,
                'is_active'  => $is_active,
                'sort_order' => $sort_order,
            ),
            array( '%s', '%s', '%s', '%d', '%d' )
        );

        if ($result === false) {
            wp_send_json_error(array( 'message' => 'Ошибка при добавлении банка в базе данных.' ));
            return;
        }

        wp_send_json_success();
    }
}

// Инициализируем класс
$bank_management_admin = new Cashback_Bank_Management_Admin();
