<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Rate_History_Admin {

    private static ?self $instance = null;

    private string $rate_history_table;

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->rate_history_table = $wpdb->prefix . 'cashback_rate_history';

        add_action('admin_menu', array( $this, 'add_admin_menu' ));
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            'cashback-overview',
            __('История изменений комиссий', 'cashback-plugin'),
            __('Комиссии', 'cashback-plugin'),
            'manage_options',
            'cashback-rate-history',
            array( $this, 'render_rate_history_page' )
        );
    }

    public function enqueue_admin_scripts( string $hook ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page detection, no state change.
        $is_rate_history = ( isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-rate-history' );

        if (!$is_rate_history) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-rate-history',
            plugins_url('../assets/css/admin-rate-history.css', __FILE__),
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'cashback-admin-rate-history',
            plugins_url('../assets/js/admin-rate-history.js', __FILE__),
            array( 'jquery' ),
            '1.0.0',
            true
        );

        wp_localize_script('cashback-admin-rate-history', 'cashbackRateHistory', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('rate_history_nonce'),
            'resetFilters' => __('Сбросить', 'cashback-plugin'),
        ));
    }

    public function render_rate_history_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        global $wpdb;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin listing filters, validated via allowlist/regex/absint below.
        $filter_rate_type = isset($_GET['rate_type']) ? sanitize_text_field(wp_unslash($_GET['rate_type'])) : '';
        $filter_date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $filter_date_to   = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        $filter_rate      = isset($_GET['filter_rate']) ? sanitize_text_field(wp_unslash($_GET['filter_rate'])) : '';
        $filter_user      = isset($_GET['filter_user']) ? sanitize_text_field(wp_unslash($_GET['filter_user'])) : '';
        $paged            = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $per_page         = 20;

        if (!empty($filter_date_from) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
            $filter_date_from = '';
        }
        if (!empty($filter_date_to) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
            $filter_date_to = '';
        }
        if (!empty($filter_rate) && !preg_match('/^\d+(\.\d{1,2})?$/', $filter_rate)) {
            $filter_rate = '';
        }

        $where_clauses = array();
        $where_values  = array();

        $allowed_rate_types = array( 'cashback', 'cashback_global', 'affiliate_commission', 'affiliate_global' );
        if ($filter_rate_type !== '' && in_array($filter_rate_type, $allowed_rate_types, true)) {
            $where_clauses[] = 'rate_type = %s';
            $where_values[]  = $filter_rate_type;
        }

        if (!empty($filter_date_from)) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[]  = $filter_date_from . ' 00:00:00';
        }

        if (!empty($filter_date_to)) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[]  = $filter_date_to . ' 23:59:59';
        }

        if (!empty($filter_rate)) {
            $where_clauses[] = 'new_rate = %f';
            $where_values[]  = (float) $filter_rate;
        }

        if (!empty($filter_user)) {
            $where_clauses[] = '(user_id = %d OR details LIKE %s)';
            $where_values[]  = absint($filter_user);
            $where_values[]  = '%' . $wpdb->esc_like($filter_user) . '%';
        }

        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        if (!empty($where_values)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $where_sql from allowlist conditions with %s/%d/%f.
            $total_items = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where_sql}", array_merge( array( $this->rate_history_table ), $where_values ) ) );
        } else {
            $total_items = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %d = %d', $this->rate_history_table, 1, 1 ) );
        }

        $offset     = ( $paged - 1 ) * $per_page;
        $all_params = array_merge( array( $this->rate_history_table ), $where_values, array( $per_page, $offset ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql from allowlist conditions with %s/%d/%f; sniff can't count spread args.
        $records = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d", $all_params ) );

        $total_pages = ceil($total_items / $per_page);

        $has_filters = $filter_rate_type !== '' || $filter_date_from !== '' || $filter_date_to !== '' || $filter_rate !== '' || $filter_user !== '';
        $reset_url   = remove_query_arg(array( 'rate_type', 'date_from', 'date_to', 'filter_rate', 'filter_user', 'paged' ));

        ?>
        <div class="wrap cashback-rate-history-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('История изменений комиссий', 'cashback-plugin'); ?></h1>
            <hr class="wp-header-end">

            <div class="cashback-filters">
                <form method="get" action="" class="cashback-filters-form">
                    <input type="hidden" name="page" value="cashback-rate-history">

                    <div class="cashback-filters-row">
                        <div class="cashback-filter-group">
                            <label for="rate_type"><?php esc_html_e('Тип комиссии:', 'cashback-plugin'); ?></label>
                            <select name="rate_type" id="rate_type">
                                <option value=""><?php esc_html_e('Все', 'cashback-plugin'); ?></option>
                                <option value="cashback" <?php selected($filter_rate_type, 'cashback'); ?>><?php esc_html_e('Кэшбэк', 'cashback-plugin'); ?></option>
                                <option value="cashback_global" <?php selected($filter_rate_type, 'cashback_global'); ?>><?php esc_html_e('Кэшбэк глобальная', 'cashback-plugin'); ?></option>
                                <option value="affiliate_commission" <?php selected($filter_rate_type, 'affiliate_commission'); ?>><?php esc_html_e('Партнёрская', 'cashback-plugin'); ?></option>
                                <option value="affiliate_global" <?php selected($filter_rate_type, 'affiliate_global'); ?>><?php esc_html_e('Партнёрская глобальная', 'cashback-plugin'); ?></option>
                            </select>
                        </div>

                        <div class="cashback-filter-group">
                            <label for="date_from"><?php esc_html_e('Дата с:', 'cashback-plugin'); ?></label>
                            <input type="date" name="date_from" id="date_from" value="<?php echo esc_attr($filter_date_from); ?>">
                        </div>

                        <div class="cashback-filter-group">
                            <label for="date_to"><?php esc_html_e('Дата по:', 'cashback-plugin'); ?></label>
                            <input type="date" name="date_to" id="date_to" value="<?php echo esc_attr($filter_date_to); ?>">
                        </div>

                        <div class="cashback-filter-group">
                            <label for="filter_rate"><?php esc_html_e('Размер комиссии:', 'cashback-plugin'); ?></label>
                            <input type="text" name="filter_rate" id="filter_rate" value="<?php echo esc_attr($filter_rate); ?>" placeholder="<?php esc_attr_e('Например: 60.00', 'cashback-plugin'); ?>" class="small-text">
                        </div>

                        <div class="cashback-filter-group">
                            <label for="filter_user"><?php esc_html_e('Пользователь:', 'cashback-plugin'); ?></label>
                            <input type="text" name="filter_user" id="filter_user" value="<?php echo esc_attr($filter_user); ?>" placeholder="<?php esc_attr_e('ID или имя', 'cashback-plugin'); ?>" class="regular-text">
                        </div>

                        <div class="cashback-filter-actions">
                            <button type="submit" class="button button-primary"><?php esc_html_e('Применить', 'cashback-plugin'); ?></button>
                            <?php if ($has_filters) : ?>
                                <a href="<?php echo esc_url($reset_url); ?>" class="button"><?php echo esc_html($this->get_reset_label()); ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped cashback-rate-history-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Дата', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Тип комиссии', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Пользователь', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Старая ставка', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Новая ставка', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Затронуто', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Источник', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Изменил', 'cashback-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)) : ?>
                        <tr>
                            <td colspan="8" class="cashback-no-results"><?php esc_html_e('Записей не найдено.', 'cashback-plugin'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($records as $record) : ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n('d.m.Y H:i:s', strtotime($record->created_at))); ?></td>
                                <td>
                                    <?php
                                    $type_labels = array(
                                        'cashback'         => __('Кэшбэк', 'cashback-plugin'),
                                        'cashback_global'  => __('Кэшбэк глоб.', 'cashback-plugin'),
                                        'affiliate_commission' => __('Партнёрская', 'cashback-plugin'),
                                        'affiliate_global' => __('Партнёрская глоб.', 'cashback-plugin'),
                                    );
                                    $type_label  = $type_labels[ $record->rate_type ] ?? $record->rate_type;
                                    $type_class  = 'rate-type-' . esc_attr($record->rate_type);
                                    ?>
                                    <span class="cashback-rate-type-badge <?php echo esc_attr($type_class); ?>"><?php echo esc_html($type_label); ?></span>
                                </td>
                                <td>
                                    <?php
                                    if ($record->user_id === null) {
                                        if ($record->rate_type === 'affiliate_global' || $record->rate_type === 'cashback_global') {
                                            esc_html_e('Все пользователи', 'cashback-plugin');
                                        } else {
                                            esc_html_e('Часть пользователей', 'cashback-plugin');
                                        }
                                    } else {
                                        $user = get_user_by('id', (int) $record->user_id);
                                        if ($user) {
                                            echo esc_html($user->display_name . ' (ID: ' . $user->ID . ')');
                                        } else {
                                            printf('ID: %d', (int) $record->user_id);
                                        }
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php echo $record->old_rate !== null ? esc_html(number_format_i18n((float) $record->old_rate, 2) . '%') : '—'; ?>
                                </td>
                                <td class="cashback-new-rate">
                                    <?php echo esc_html(number_format_i18n((float) $record->new_rate, 2) . '%'); ?>
                                </td>
                                <td><?php echo esc_html((int) $record->affected_users); ?></td>
                                <td>
                                    <?php
                                    $source_labels = array(
                                        'manual' => __('Вручную', 'cashback-plugin'),
                                        'bulk'   => __('Массовое', 'cashback-plugin'),
                                        'api'    => __('API', 'cashback-plugin'),
                                        'system' => __('Система', 'cashback-plugin'),
                                    );
                                    $source_label  = $source_labels[ $record->change_source ] ?? $record->change_source;
                                    echo esc_html($source_label);
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if ((int) $record->changed_by === 0) {
                                        esc_html_e('Система', 'cashback-plugin');
                                    } else {
                                        $admin = get_user_by('id', (int) $record->changed_by);
                                        if ($admin) {
                                            echo esc_html($admin->display_name);
                                        } else {
                                            printf('ID: %d', (int) $record->changed_by);
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1) : ?>
                <div class="cashback-pagination">
                    <?php
                    $base_url = remove_query_arg(array( 'paged' ));
                    $base_url = add_query_arg(array(
                        'rate_type'   => $filter_rate_type,
                        'date_from'   => $filter_date_from,
                        'date_to'     => $filter_date_to,
                        'filter_rate' => $filter_rate,
                        'filter_user' => $filter_user,
                    ), $base_url);

                    echo wp_kses_post( paginate_links(array(
                        'base'      => $base_url . '%_%',
                        'format'    => '&paged=%#%',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                    )) ); // wp_kses_post: paginate_links returns safe pagination HTML.
                    ?>
                    <span class="cashback-pagination-info">
                        <?php
                        printf(
                            /* translators: %1$d: текущая страница, %2$d: всего страниц, %3$d: всего записей. */
                            esc_html__('Страница %1$d из %2$d (%3$d записей)', 'cashback-plugin'),
                            (int) $paged,
                            (int) $total_pages,
                            (int) $total_items
                        );
                        ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function get_reset_label(): string {
        return __('Сбросить', 'cashback-plugin');
    }

    public static function log_rate_change(
        string $rate_type,
        ?int $user_id,
        ?float $old_rate,
        float $new_rate,
        int $affected_users = 1,
        string $change_source = 'manual',
        ?array $details = null
    ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_rate_history';

        if (!in_array($rate_type, array( 'cashback', 'cashback_global', 'affiliate_commission', 'affiliate_global' ), true)) {
            return false;
        }

        $allowed_sources = array( 'manual', 'bulk', 'api', 'system' );
        if (!in_array($change_source, $allowed_sources, true)) {
            $change_source = 'manual';
        }

        $insert_data = array(
            'rate_type'      => $rate_type,
            'old_rate'       => $old_rate !== null ? number_format($old_rate, 2, '.', '') : null,
            'new_rate'       => number_format($new_rate, 2, '.', ''),
            'affected_users' => $affected_users,
            'changed_by'     => get_current_user_id(),
            'change_source'  => $change_source,
            'details'        => $details !== null ? wp_json_encode($details) : null,
        );

        $insert_formats = array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' );

        if ($user_id !== null) {
            $insert_data['user_id'] = $user_id;
            $insert_formats[]       = '%d';
        }

        $result = $wpdb->insert($table, $insert_data, $insert_formats);

        return $result !== false;
    }
}
