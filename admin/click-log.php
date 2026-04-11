<?php

declare(strict_types=1);

/**
 * Класс для просмотра лога кликов по партнерским ссылкам в админ-панели.
 */

// Проверяем, что файл вызывается из WordPress
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс просмотра лога кликов в админ-панели
 */
class Cashback_Click_Log_Admin
{
    use AdminPaginationTrait;

    /**
     * Имя таблицы лога кликов
     *
     * @var string
     */
    private string $table_name;

    /**
     * Количество записей на странице
     *
     * @var int
     */
    private int $per_page = 10;

    /**
     * Конструктор класса
     */
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cashback_click_log';

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Добавление пункта подменю
     *
     * @return void
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'cashback-overview',
            'Лог кликов',
            'Лог кликов',
            'manage_options',
            'cashback-click-log',
            [$this, 'render_page']
        );
    }

    /**
     * Подключение скриптов и стилей для админ-панели
     *
     * @param string $hook Текущая страница админки
     * @return void
     */
    public function enqueue_admin_scripts(string $hook): void
    {
        $allowed_hooks = [
            'cashback-overview_page_cashback-click-log',
            'toplevel_page_cashback-click-log',
            'admin_page_cashback-click-log'
        ];

        $is_target_page = in_array($hook, $allowed_hooks, true) ||
            (isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-click-log');

        if (!$is_target_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-click-log-css',
            plugins_url('../assets/css/admin.css', __FILE__),
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'cashback-admin-click-log',
            plugins_url('../assets/js/admin-click-log.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('cashback-admin-click-log', 'cashbackClickLogData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);

        wp_add_inline_style('cashback-admin-click-log-css',
            '.column-url { word-break: break-all; }' .
            '.copyable { cursor: pointer; }' .
            '.copyable:hover { background: #e5f5fa; border-radius: 3px; }' .
            '.copy-ok { color: #00a32a; font-size: 12px; margin-left: 4px; }'
        );
    }

    /**
     * Рендеринг страницы лога кликов
     *
     * @return void
     */
    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('У вас нет доступа к этой странице.', 'cashback-plugin'));
        }

        global $wpdb;

        // Параметры пагинации
        $current_page = max(1, intval($_GET['paged'] ?? 1));
        $offset = ($current_page - 1) * $this->per_page;

        // Фильтры
        $filter_email = isset($_GET['email']) ? sanitize_text_field(wp_unslash($_GET['email'])) : '';
        $filter_date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $filter_date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        $filter_spam_only = isset($_GET['spam_only']) && sanitize_text_field(wp_unslash($_GET['spam_only'])) === '1';

        // Валидация дат (формат + реальная дата)
        if (!empty($filter_date_from) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
            $filter_date_from = '';
        }
        if (!empty($filter_date_from)) {
            [$y, $m, $d] = array_map('intval', explode('-', $filter_date_from));
            if (!checkdate($m, $d, $y)) {
                $filter_date_from = '';
            }
        }
        if (!empty($filter_date_to) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
            $filter_date_to = '';
        }
        if (!empty($filter_date_to)) {
            [$y, $m, $d] = array_map('intval', explode('-', $filter_date_to));
            if (!checkdate($m, $d, $y)) {
                $filter_date_to = '';
            }
        }

        // Построение WHERE
        $where_conditions = [];
        $where_params = [];

        if (!empty($filter_email)) {
            $where_conditions[] = 'u.user_email LIKE %s';
            $where_params[] = '%' . $wpdb->esc_like($filter_email) . '%';
        }

        if (!empty($filter_date_from)) {
            $where_conditions[] = 'DATE(cl.created_at) >= %s';
            $where_params[] = $filter_date_from;
        }

        if (!empty($filter_date_to)) {
            $where_conditions[] = 'DATE(cl.created_at) <= %s';
            $where_params[] = $filter_date_to;
        }

        if ($filter_spam_only) {
            $where_conditions[] = 'cl.spam_click = 1';
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        $users_table = $wpdb->users;

        // Подсчет записей
        if (!empty($where_params)) {
            $total_items = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} cl LEFT JOIN {$users_table} u ON cl.user_id = u.ID {$where_clause}",
                $where_params
            ));
        } else {
            $total_items = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} cl LEFT JOIN {$users_table} u ON cl.user_id = u.ID WHERE %d = %d",
                1,
                1
            ));
        }

        $total_pages = (int) ceil($total_items / $this->per_page);

        // Получение записей
        $select_query = "SELECT cl.id, cl.click_id, cl.user_id, cl.ip_address, cl.affiliate_url,
                                cl.referer, cl.created_at, cl.spam_click,
                                u.display_name, u.user_email
                         FROM {$this->table_name} cl
                         LEFT JOIN {$users_table} u ON cl.user_id = u.ID
                         {$where_clause}
                         ORDER BY cl.created_at DESC
                         LIMIT %d OFFSET %d";

        $query_params = array_merge($where_params, [$this->per_page, $offset]);

        $rows = $wpdb->get_results($wpdb->prepare($select_query, $query_params), ARRAY_A);

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Лог кликов', 'cashback-plugin'); ?></h1>
            <hr class="wp-header-end">

            <!-- Фильтры -->
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <label for="filter-email" class="screen-reader-text"><?php esc_html_e('E-mail', 'cashback-plugin'); ?></label>
                    <input type="text" id="filter-email" placeholder="E-mail" value="<?php echo esc_attr($filter_email); ?>" style="width:180px;" />

                    <label for="filter-date-from" class="screen-reader-text"><?php esc_html_e('Дата от', 'cashback-plugin'); ?></label>
                    <input type="date" id="filter-date-from" value="<?php echo esc_attr($filter_date_from); ?>" />

                    <label for="filter-date-to" class="screen-reader-text"><?php esc_html_e('Дата до', 'cashback-plugin'); ?></label>
                    <input type="date" id="filter-date-to" value="<?php echo esc_attr($filter_date_to); ?>" />

                    <label for="filter-spam-only" style="margin-left:4px;">
                        <input type="checkbox" id="filter-spam-only" value="1" <?php checked($filter_spam_only); ?> />
                        <?php esc_html_e('Только спам', 'cashback-plugin'); ?>
                    </label>

                    <button id="filter-submit" class="button action"><?php esc_html_e('Фильтровать', 'cashback-plugin'); ?></button>
                    <button id="filter-reset" class="button action"><?php esc_html_e('Сбросить', 'cashback-plugin'); ?></button>
                </div>
                <br class="clear">
            </div>

            <!-- Таблица -->
            <div class="wp-list-table-wrap">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:110px;"><?php esc_html_e('Пользователь', 'cashback-plugin'); ?></th>
                            <th style="width:150px;"><?php esc_html_e('E-mail', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Click ID', 'cashback-plugin'); ?></th>
                            <th style="width:110px;"><?php esc_html_e('IP', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Партнерский URL', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Referer', 'cashback-plugin'); ?></th>
                            <th style="width:150px;"><?php esc_html_e('Дата/время', 'cashback-plugin'); ?></th>
                            <th style="width:50px;"><?php esc_html_e('Спам', 'cashback-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tfoot>
                        <tr>
                            <th><?php esc_html_e('Пользователь', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('E-mail', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Click ID', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('IP', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Партнерский URL', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Referer', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Дата/время', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Спам', 'cashback-plugin'); ?></th>
                        </tr>
                    </tfoot>
                    <tbody id="click-log-tbody">
                        <?php if (empty($rows)) : ?>
                            <tr>
                                <td colspan="8"><?php esc_html_e('Записей не найдено.', 'cashback-plugin'); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($rows as $row) : ?>
                                <tr>
                                    <td>
                                        <?php
                                        if ((int) $row['user_id'] === 0) {
                                            echo esc_html__('Гость', 'cashback-plugin');
                                        } else {
                                            echo esc_html($row['display_name'] ?: __('(без имени)', 'cashback-plugin'));
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ((int) $row['user_id'] === 0 || empty($row['user_email'])) {
                                            echo '&mdash;';
                                        } else {
                                            echo esc_html($row['user_email']);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="copyable" data-copy="<?php echo esc_attr($row['click_id']); ?>"><code><?php echo esc_html($row['click_id']); ?></code></span>
                                    </td>
                                    <td><?php echo esc_html($row['ip_address']); ?></td>
                                    <td class="column-url">
                                        <span class="copyable" data-copy="<?php echo esc_attr($row['affiliate_url']); ?>"><?php echo esc_html($row['affiliate_url']); ?></span>
                                    </td>
                                    <td class="column-url">
                                        <?php if (!empty($row['referer'])) : ?>
                                            <span class="copyable" data-copy="<?php echo esc_attr($row['referer']); ?>"><?php echo esc_html($row['referer']); ?></span>
                                        <?php else : ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($row['created_at']); ?></td>
                                    <td>
                                        <?php if ((int) $row['spam_click'] === 1) : ?>
                                            <span style="color:red;font-weight:bold;"><?php esc_html_e('Да', 'cashback-plugin'); ?></span>
                                        <?php else : ?>
                                            <span style="color:green;"><?php esc_html_e('Нет', 'cashback-plugin'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $this->render_pagination([
                'total_items'  => $total_items,
                'per_page'     => $this->per_page,
                'current_page' => $current_page,
                'total_pages'  => $total_pages,
                'page_slug'    => 'cashback-click-log',
                'add_args'     => array_filter([
                    'email'     => $filter_email,
                    'date_from' => $filter_date_from,
                    'date_to'   => $filter_date_to,
                    'spam_only' => $filter_spam_only ? '1' : '',
                ]),
            ]);
            ?>
        </div>
        <?php
    }
}

// Инициализация
new Cashback_Click_Log_Admin();
