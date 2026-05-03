<?php

declare(strict_types=1);

// phpcs:ignore PSR12.Files.FileHeader.IncorrectOrder -- WordPress bootstrap guard must precede other statements.
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
class Cashback_Click_Log_Admin {

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
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cashback_click_log';

        add_action('admin_menu', array( $this, 'add_admin_menu' ));
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));
    }

    /**
     * Добавление пункта подменю
     *
     * @return void
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'cashback-overview',
            'Лог кликов',
            'Лог кликов',
            'manage_options',
            'cashback-click-log',
            array( $this, 'render_page' )
        );
    }

    /**
     * Подключение скриптов и стилей для админ-панели
     *
     * @param string $hook Текущая страница админки
     * @return void
     */
    public function enqueue_admin_scripts( string $hook ): void {
        $allowed_hooks = array(
            'cashback-overview_page_cashback-click-log',
            'toplevel_page_cashback-click-log',
            'admin_page_cashback-click-log',
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page-detect, literal compare.
        $is_target_page = in_array($hook, $allowed_hooks, true) || ( isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-click-log' );

        if (!$is_target_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-click-log-css',
            plugins_url('../assets/css/admin.css', __FILE__),
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'cashback-admin-click-log',
            plugins_url('../assets/js/admin-click-log.js', __FILE__),
            array( 'jquery' ),
            '1.1.0',
            true
        );

        wp_localize_script('cashback-admin-click-log', 'cashbackClickLogData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
        ));

        wp_add_inline_style('cashback-admin-click-log-css',
            '.column-url { word-break: break-all; }' .
            '.copyable { cursor: pointer; }' .
            '.copyable:hover { background: #e5f5fa; border-radius: 3px; }' .
            '.copy-ok { color: #00a32a; font-size: 12px; margin-left: 4px; }'
        );
    }

    /**
     * Рендеринг страницы лога кликов — диспетчер табов.
     *
     * Табы:
     *   - all (default) — все клики из cashback_click_log (WC-affiliate + promo).
     *   - promo         — только promo-клики (cashback_promocode_clicks): copy/goto.
     *
     * @return void
     */
    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('У вас нет доступа к этой странице.', 'cashback-plugin'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab switch, sanitized + whitelist.
        $tab_raw = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'all';
        $tab     = in_array($tab_raw, array( 'all', 'promo' ), true) ? $tab_raw : 'all';

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Лог кликов', 'cashback-plugin'); ?></h1>
            <hr class="wp-header-end">

            <h2 class="nav-tab-wrapper" style="margin-top:12px;">
                <a href="<?php echo esc_url(add_query_arg(array( 'page' => 'cashback-click-log', 'tab' => 'all' ), admin_url('admin.php'))); ?>"
                    class="nav-tab <?php echo $tab === 'all' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Все клики', 'cashback-plugin'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array( 'page' => 'cashback-click-log', 'tab' => 'promo' ), admin_url('admin.php'))); ?>"
                    class="nav-tab <?php echo $tab === 'promo' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Промо клики', 'cashback-plugin'); ?>
                </a>
            </h2>

            <?php
            if ($tab === 'promo') {
                $this->render_tab_promo();
            } else {
                $this->render_tab_all();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Таб «Все клики» — таблица cashback_click_log.
     */
    private function render_tab_all(): void {
        global $wpdb;

        // Параметры пагинации
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing pagination, intval-cast.
        $current_page = max(1, intval($_GET['paged'] ?? 1));
        $offset       = ( $current_page - 1 ) * $this->per_page;

        // Фильтры
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing filter, sanitized + used as LIKE arg via esc_like.
        $filter_email = isset($_GET['email']) ? sanitize_text_field(wp_unslash($_GET['email'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing date filter, preg_match+checkdate validated.
        $filter_date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing date filter, preg_match+checkdate validated.
        $filter_date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing filter, compared to literal '1'.
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
        $where_conditions = array();
        $where_params     = array();

        if (!empty($filter_email)) {
            $where_conditions[] = 'u.user_email LIKE %s';
            $where_params[]     = '%' . $wpdb->esc_like($filter_email) . '%';
        }

        if (!empty($filter_date_from)) {
            $where_conditions[] = 'DATE(cl.created_at) >= %s';
            $where_params[]     = $filter_date_from;
        }

        if (!empty($filter_date_to)) {
            $where_conditions[] = 'DATE(cl.created_at) <= %s';
            $where_params[]     = $filter_date_to;
        }

        if ($filter_spam_only) {
            $where_conditions[] = 'cl.spam_click = 1';
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        $users_table = $wpdb->users;

        // Подсчет записей.
        if (!empty($where_params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_clause from allowlist conditions with %s/%d only; sniff can't count spread args.
            $total_items = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i cl LEFT JOIN %i u ON cl.user_id = u.ID {$where_clause}", array_merge( array( $this->table_name, $users_table ), $where_params ) ) );
        } else {
            $total_items = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM %i cl LEFT JOIN %i u ON cl.user_id = u.ID WHERE %d = %d',
                $this->table_name,
                $users_table,
                1,
                1
            ) );
        }

        $total_pages = (int) ceil($total_items / $this->per_page);

        // Получение записей
        $select_query = "SELECT cl.id, cl.click_id, cl.user_id, cl.ip_address, cl.affiliate_url,
                                cl.referer, cl.created_at, cl.spam_click,
                                u.display_name, u.user_email
                         FROM %i cl
                         LEFT JOIN %i u ON cl.user_id = u.ID
                         {$where_clause}
                         ORDER BY cl.created_at DESC
                         LIMIT %d OFFSET %d";

        $query_params = array_merge( array( $this->table_name, $users_table ), $where_params, array( $this->per_page, $offset ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $where_clause from allowlist conditions; $select_query in variable for readability.
        $rows = $wpdb->get_results( $wpdb->prepare( $select_query, $query_params ), ARRAY_A );

        ?>
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
            Cashback_Pagination::render(array(
                'total_items'  => $total_items,
                'per_page'     => $this->per_page,
                'current_page' => $current_page,
                'total_pages'  => $total_pages,
                'page_slug'    => 'cashback-click-log',
                'add_args'     => array_filter(array(
                    'tab'       => 'all',
                    'email'     => $filter_email,
                    'date_from' => $filter_date_from,
                    'date_to'   => $filter_date_to,
                    'spam_only' => $filter_spam_only ? '1' : '',
                )),
            ));
            ?>
        <?php
    }

    /**
     * Таб «Промо клики» — таблица cashback_promocode_clicks с JOIN'ами на
     * users / cashback_promocodes / posts. Покрывает оба источника записей:
     *   - runtime через redirect-handler (success path с click_id),
     *   - safety-backfill cron (тоже с click_id),
     *   - fallback-пути (без click_id, NULL).
     */
    private function render_tab_promo(): void {
        global $wpdb;

        $promo_clicks_table = $wpdb->prefix . 'cashback_promocode_clicks';
        $promocodes_table   = $wpdb->prefix . 'cashback_promocodes';
        $users_table        = $wpdb->users;
        $posts_table        = $wpdb->posts;

        // Пагинация
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing pagination, intval-cast.
        $current_page = max(1, intval($_GET['paged'] ?? 1));
        $offset       = ( $current_page - 1 ) * $this->per_page;

        // Фильтры
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter, sanitized + esc_like.
        $filter_email = isset($_GET['email']) ? sanitize_text_field(wp_unslash($_GET['email'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter, sanitized.
        $filter_date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter, sanitized.
        $filter_date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter, whitelist.
        $filter_action_raw = isset($_GET['promo_action']) ? sanitize_key(wp_unslash($_GET['promo_action'])) : '';
        $filter_action     = in_array($filter_action_raw, array( 'copy', 'goto' ), true) ? $filter_action_raw : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter, intval-cast.
        $filter_promo_id = isset($_GET['promo_id']) ? max(0, intval($_GET['promo_id'])) : 0;

        // Валидация дат
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

        $where_conditions = array();
        $where_params     = array();

        if (!empty($filter_email)) {
            $where_conditions[] = 'u.user_email LIKE %s';
            $where_params[]     = '%' . $wpdb->esc_like($filter_email) . '%';
        }
        if (!empty($filter_date_from)) {
            $where_conditions[] = 'DATE(pc.created_at) >= %s';
            $where_params[]     = $filter_date_from;
        }
        if (!empty($filter_date_to)) {
            $where_conditions[] = 'DATE(pc.created_at) <= %s';
            $where_params[]     = $filter_date_to;
        }
        if ($filter_action !== '') {
            $where_conditions[] = 'pc.action = %s';
            $where_params[]     = $filter_action;
        }
        if ($filter_promo_id > 0) {
            $where_conditions[] = 'pc.promocode_id = %d';
            $where_params[]     = $filter_promo_id;
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Подсчёт.
        if (!empty($where_params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_clause from allowlist conditions with %s/%d only; sniff can't count spread args.
            $total_items = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i pc LEFT JOIN %i u ON pc.user_id = u.ID {$where_clause}", array_merge(array( $promo_clicks_table, $users_table ), $where_params)));
        } else {
            $total_items = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i pc LEFT JOIN %i u ON pc.user_id = u.ID WHERE %d = %d',
                $promo_clicks_table,
                $users_table,
                1,
                1
            ));
        }

        $total_pages = (int) ceil($total_items / $this->per_page);

        // Выборка с JOIN'ами. pc.affiliate_url добавлено в v11 — пишется только
        // на success-пути активации промо-клика; для legacy-строк и copy/fallback
        // остаётся NULL, в UI показывается «—».
        $select_sql = "SELECT pc.id, pc.user_id, pc.promocode_id, pc.product_id, pc.action,
                              pc.ip_hash, pc.ua_family, pc.created_at, pc.click_id,
                              pc.affiliate_url,
                              u.display_name, u.user_email,
                              pr.promocode AS promo_code, pr.name AS promo_name,
                              pr.advcampaign_id, pr.network_id,
                              p.post_title AS product_title
                         FROM %i pc
                         LEFT JOIN %i u  ON pc.user_id = u.ID
                         LEFT JOIN %i pr ON pc.promocode_id = pr.id
                         LEFT JOIN %i p  ON pc.product_id = p.ID
                         {$where_clause}
                         ORDER BY pc.created_at DESC
                         LIMIT %d OFFSET %d";

        $query_params = array_merge(
            array( $promo_clicks_table, $users_table, $promocodes_table, $posts_table ),
            $where_params,
            array( $this->per_page, $offset )
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $where_clause from allowlist; SQL kept in variable for readability.
        $rows = $wpdb->get_results($wpdb->prepare($select_sql, $query_params), ARRAY_A);
        ?>
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <input type="text" id="filter-email" placeholder="E-mail" value="<?php echo esc_attr($filter_email); ?>" style="width:180px;" />
                    <input type="date" id="filter-date-from" value="<?php echo esc_attr($filter_date_from); ?>" />
                    <input type="date" id="filter-date-to" value="<?php echo esc_attr($filter_date_to); ?>" />
                    <select id="filter-promo-action">
                        <option value=""<?php selected($filter_action, ''); ?>><?php esc_html_e('Все действия', 'cashback-plugin'); ?></option>
                        <option value="goto"<?php selected($filter_action, 'goto'); ?>><?php esc_html_e('Переход', 'cashback-plugin'); ?></option>
                        <option value="copy"<?php selected($filter_action, 'copy'); ?>><?php esc_html_e('Копирование', 'cashback-plugin'); ?></option>
                    </select>
                    <input type="number" id="filter-promo-id" placeholder="<?php esc_attr_e('ID промокода', 'cashback-plugin'); ?>" value="<?php echo $filter_promo_id > 0 ? esc_attr((string) $filter_promo_id) : ''; ?>" min="0" style="width:120px;" />
                    <button id="filter-submit" class="button action"><?php esc_html_e('Фильтровать', 'cashback-plugin'); ?></button>
                    <button id="filter-reset" class="button action"><?php esc_html_e('Сбросить', 'cashback-plugin'); ?></button>
                </div>
                <br class="clear">
            </div>

            <div class="wp-list-table-wrap">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:140px;"><?php esc_html_e('Дата/время', 'cashback-plugin'); ?></th>
                            <th style="width:110px;"><?php esc_html_e('Пользователь', 'cashback-plugin'); ?></th>
                            <th style="width:160px;"><?php esc_html_e('E-mail', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Промокод', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Магазин', 'cashback-plugin'); ?></th>
                            <th style="width:90px;"><?php esc_html_e('Действие', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Click ID', 'cashback-plugin'); ?></th>
                            <th style="width:90px;"><?php esc_html_e('IP hash', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Партнёрский URL', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('User-Agent', 'cashback-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="click-log-tbody">
                        <?php if (empty($rows)) : ?>
                            <tr>
                                <td colspan="10"><?php esc_html_e('Записей не найдено.', 'cashback-plugin'); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($rows as $row) : ?>
                                <tr>
                                    <td><?php echo esc_html($row['created_at']); ?></td>
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
                                        <?php
                                        $promo_label = trim((string) ($row['promo_name'] ?? ''));
                                        if (!empty($row['promo_code'])) {
                                            $promo_label .= ' [' . $row['promo_code'] . ']';
                                        }
                                        if ($promo_label === '') {
                                            $promo_label = '#' . (int) $row['promocode_id'];
                                        }
                                        echo esc_html($promo_label);
                                        echo ' <small style="color:#888;">#' . esc_html((string) (int) $row['promocode_id']) . '</small>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $title = (string) ($row['product_title'] ?? '');
                                        if ((int) $row['product_id'] > 0) {
                                            $edit_url = get_edit_post_link((int) $row['product_id']);
                                            $label    = $title !== '' ? $title : ('#' . (int) $row['product_id']);
                                            if ($edit_url) {
                                                printf('<a href="%s">%s</a>', esc_url($edit_url), esc_html($label));
                                            } else {
                                                echo esc_html($label);
                                            }
                                        } else {
                                            echo '&mdash;';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ((string) $row['action'] === 'goto') {
                                            echo '<span style="color:#2271b1;font-weight:bold;">' . esc_html__('Переход', 'cashback-plugin') . '</span>';
                                        } elseif ((string) $row['action'] === 'copy') {
                                            echo esc_html__('Копирование', 'cashback-plugin');
                                        } else {
                                            echo esc_html((string) $row['action']);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['click_id'])) : ?>
                                            <span class="copyable" data-copy="<?php echo esc_attr($row['click_id']); ?>"><code><?php echo esc_html(substr((string) $row['click_id'], 0, 12)); ?>…</code></span>
                                        <?php else : ?>
                                            <span style="color:#999;" title="<?php esc_attr_e('Запись из fallback-пути (без атрибуции)', 'cashback-plugin'); ?>">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['ip_hash'])) : ?>
                                            <code title="<?php echo esc_attr((string) $row['ip_hash']); ?>"><?php echo esc_html(substr((string) $row['ip_hash'], 0, 8)); ?></code>
                                        <?php else : ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-url">
                                        <?php
                                        $aff_url = (string) ($row['affiliate_url'] ?? '');
                                        if ($aff_url !== '') {
                                            $short = mb_strlen($aff_url) > 60 ? mb_substr($aff_url, 0, 60) . '…' : $aff_url;
                                            printf(
                                                '<a href="%1$s" target="_blank" rel="noopener" title="%2$s">%3$s</a>',
                                                esc_url($aff_url),
                                                esc_attr($aff_url),
                                                esc_html($short)
                                            );
                                        } else {
                                            echo '<span style="color:#999;" title="' . esc_attr__('NULL — copy-клик или fallback-путь без активации (до v11)', 'cashback-plugin') . '">&mdash;</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $ua = (string) ($row['ua_family'] ?? '');
                                        echo $ua !== '' ? esc_html($ua) : '&mdash;';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php
            Cashback_Pagination::render(array(
                'total_items'  => $total_items,
                'per_page'     => $this->per_page,
                'current_page' => $current_page,
                'total_pages'  => $total_pages,
                'page_slug'    => 'cashback-click-log',
                'add_args'     => array_filter(array(
                    'tab'          => 'promo',
                    'email'        => $filter_email,
                    'date_from'    => $filter_date_from,
                    'date_to'      => $filter_date_to,
                    'promo_action' => $filter_action,
                    'promo_id'     => $filter_promo_id > 0 ? (string) $filter_promo_id : '',
                )),
            ));
    }
}

// Инициализация
new Cashback_Click_Log_Admin();
