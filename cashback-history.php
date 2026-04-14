<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Cashback History page for WooCommerce My Account.
 *
 * Displays user's cashback transaction history with AJAX pagination.
 */
class CashbackHistory {

    private const PER_PAGE          = 10;
    private const MAX_ALLOWED_PAGES = 1000; // Защита от DoS (макс. 10 000 записей)

    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array( $this, 'register_endpoint' ));
        add_filter('query_vars', array( $this, 'add_query_vars' ));
        add_filter('woocommerce_account_menu_items', array( $this, 'add_menu_item' ));
        add_action('woocommerce_account_cashback-history_endpoint', array( $this, 'content' ));
        add_action('wp_ajax_load_page_transactions', array( $this, 'ajax_load_page' ));
        add_action('wp_enqueue_scripts', array( $this, 'enqueue_scripts' ));
    }

    /**
     * Register WooCommerce account endpoint.
     *
     * @return void
     */
    public function register_endpoint() {
        add_rewrite_endpoint('cashback-history', EP_ROOT | EP_PAGES);
    }

    /**
     * Add query vars for the endpoint.
     *
     * @param array $vars Query vars.
     * @return array Modified query vars.
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'cashback-history';
        return $vars;
    }

    /**
     * Add menu item to WooCommerce account menu.
     *
     * @param array $items Menu items.
     * @return array Modified menu items.
     */
    public function add_menu_item( $items ) {
        if (isset($items['customer-logout'])) {
            $logout = $items['customer-logout'];
            unset($items['customer-logout']);
            $items['cashback-history'] = __('История покупок', 'cashback-plugin');
            $items['customer-logout']  = $logout;
        } else {
            $items['cashback-history'] = __('История покупок', 'cashback-plugin');
        }
        return $items;
    }

    /**
     * Render cashback history content.
     *
     * @return void
     */
    public function content() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            echo '<p>' . esc_html__('Вы должны быть авторизованы.', 'cashback-plugin') . '</p>';
            return;
        }

        // Показываем информационное сообщение для забаненных пользователей
        if (Cashback_User_Status::is_user_banned($user_id)) {
            echo '<div class="woocommerce-message woocommerce-info" role="alert">';
            echo esc_html__('Ваш аккаунт заблокирован. Вы можете просматривать историю покупок в режиме только для чтения.', 'cashback-plugin');
            echo '</div>';
        }

        $per_page    = self::PER_PAGE;
        $total       = $this->get_total_transactions($user_id);
        $total_pages = $total > 0 ? ceil($total / $per_page) : 1;
        $total_pages = min($total_pages, self::MAX_ALLOWED_PAGES);

        $page   = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $page   = max(1, min($page, $total_pages));
        $offset = ( $page - 1 ) * $per_page;

        $transactions = $this->get_transactions($user_id, $per_page, $offset);

        echo '<div class="wd-cashback-history">';
        echo '<h2>' . esc_html__('История покупок', 'cashback-plugin') . '</h2>';

        // Фильтры
        echo '<div class="clicks-filters">';
        echo '<div class="clicks-filters-row">';

        echo '<div class="clicks-filter-group">';
        echo '<label for="history-date-from">' . esc_html__('С', 'cashback-plugin') . '</label>';
        echo '<input type="date" id="history-date-from" class="clicks-filter-input">';
        echo '</div>';

        echo '<div class="clicks-filter-group">';
        echo '<label for="history-date-to">' . esc_html__('По', 'cashback-plugin') . '</label>';
        echo '<input type="date" id="history-date-to" class="clicks-filter-input">';
        echo '</div>';

        echo '<div class="clicks-filter-group">';
        echo '<label for="history-search">' . esc_html__('Магазин', 'cashback-plugin') . '</label>';
        echo '<input type="text" id="history-search" class="clicks-filter-input" placeholder="' . esc_attr__('Поиск по названию...', 'cashback-plugin') . '">';
        echo '</div>';

        echo '<div class="clicks-filter-group">';
        echo '<label for="history-status">' . esc_html__('Статус', 'cashback-plugin') . '</label>';
        echo '<select id="history-status" class="clicks-filter-input">';
        echo '<option value="">' . esc_html__('Все статусы', 'cashback-plugin') . '</option>';
        echo '<option value="waiting">' . esc_html__('В ожидании', 'cashback-plugin') . '</option>';
        echo '<option value="completed">' . esc_html__('Подтвержден', 'cashback-plugin') . '</option>';
        echo '<option value="hold">' . esc_html__('На проверке', 'cashback-plugin') . '</option>';
        echo '<option value="declined">' . esc_html__('Отклонен', 'cashback-plugin') . '</option>';
        echo '<option value="balance">' . esc_html__('Зачислен на баланс', 'cashback-plugin') . '</option>';
        echo '</select>';
        echo '</div>';

        echo '<div class="clicks-filter-group clicks-filter-buttons">';
        echo '<button type="button" id="history-filter-apply" class="button">' . esc_html__('Применить', 'cashback-plugin') . '</button>';
        echo '<button type="button" id="history-filter-reset" class="button clicks-filter-reset">' . esc_html__('Сбросить', 'cashback-plugin') . '</button>';
        echo '</div>';

        echo '</div>'; // clicks-filters-row
        echo '</div>'; // clicks-filters

        echo '<div id="transactions-table-container">';
        if (empty($transactions)) {
            echo '<p>' . esc_html__('У вас нет истории покупок.', 'cashback-plugin') . '</p>';
        } else {
            $this->render_transactions_table($transactions);
        }
        echo '</div>';

        echo '<div id="pagination-container">';
        if ($total_pages > 1) {
            $this->render_pagination($page, $total_pages);
        }
        echo '</div>';

        echo '</div>';
    }

    /**
     * Render transactions table HTML.
     *
     * @param array $transactions Array of transaction objects.
     * @return void
     */
    private function render_transactions_table( array $transactions ): void {
        $support_enabled = class_exists('Cashback_Support_DB') && Cashback_Support_DB::is_module_enabled();
        $support_base    = $support_enabled ? wc_get_account_endpoint_url('cashback-support') : '';

        echo '<table id="transactions-table" class="wd-table shop_table_responsive">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('ID', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Дата', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Магазин', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Номер заказа', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Кэшбэк', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Статус', 'cashback-plugin') . '</th>';
        if ($support_enabled) {
            echo '<th class="col-support-action"><span class="screen-reader-text">' . esc_html__('Поддержка', 'cashback-plugin') . '</span></th>';
        }
        echo '</tr>';
        echo '</thead>';
        echo '<tbody id="transactions-body">';

        foreach ($transactions as $transaction) {
            echo '<tr>';
            echo '<td data-title="' . esc_attr__('ID', 'cashback-plugin') . '"><code>' . esc_html($transaction->reference_id ?? '') . '</code></td>';
            echo '<td data-title="' . esc_attr__('Дата', 'cashback-plugin') . '">' . $this->format_date($transaction->action_date ?? $transaction->created_at) . '</td>';
            echo '<td data-title="' . esc_attr__('Магазин', 'cashback-plugin') . '">' . esc_html($transaction->offer_name ?? __('Н/Д', 'cashback-plugin')) . '</td>';
            echo '<td data-title="' . esc_attr__('Номер заказа', 'cashback-plugin') . '">' . esc_html($transaction->order_number ?? __('Н/Д', 'cashback-plugin')) . '</td>';
            echo '<td data-title="' . esc_attr__('Кэшбэк', 'cashback-plugin') . '">' . esc_html($transaction->cashback ?? '0.00') . '</td>';
            echo '<td data-title="' . esc_attr__('Статус', 'cashback-plugin') . '" class="status-' . esc_attr($transaction->order_status) . '">' . esc_html($this->get_status_label($transaction->order_status)) . '</td>';
            if ($support_enabled) {
                $support_url = add_query_arg(
                    array(
                        'related_type' => 'cashback_tx',
                        'related_id'   => (int) $transaction->id,
                    ),
                    $support_base
                );
                echo '<td data-title="' . esc_attr__('Поддержка', 'cashback-plugin') . '" class="col-support-action">';
                echo '<a href="' . esc_url($support_url) . '" class="support-ask-btn" title="' . esc_attr__('Вопрос в поддержку', 'cashback-plugin') . '" aria-label="' . esc_attr__('Вопрос в поддержку', 'cashback-plugin') . '">';
                echo '<span class="support-ask-btn__icon" aria-hidden="true">?</span>';
                echo '<span class="support-ask-btn__label">' . esc_html__('Вопрос в поддержку', 'cashback-plugin') . '</span>';
                echo '</a>';
                echo '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    /**
     * Render pagination links.
     *
     * @param int $current_page Current page number.
     * @param int $total_pages  Total number of pages.
     * @return void
     */
    private function render_pagination( $current_page, $total_pages ) {
        if ($total_pages <= 1) {
            return;
        }

        $range = 2; // соседние страницы вокруг текущей
        $edge  = 2;  // крайние страницы с каждой стороны

        // Собираем номера страниц для отображения
        $pages = array();
        for ($i = 1; $i <= min($edge, $total_pages); $i++) {
            $pages[] = $i;
        }
        for ($i = max(1, $current_page - $range); $i <= min($total_pages, $current_page + $range); $i++) {
            $pages[] = $i;
        }
        for ($i = max(1, $total_pages - $edge + 1); $i <= $total_pages; $i++) {
            $pages[] = $i;
        }

        $pages = array_unique($pages);
        sort($pages);

        echo '<nav class="woocommerce-pagination">';
        echo '<ul class="page-numbers">';

        // Кнопка «Назад»
        if ($current_page > 1) {
            echo '<li><a href="#" class="page-numbers prev" data-page="' . esc_attr((string) ( $current_page - 1 )) . '">&lsaquo;</a></li>';
        }

        $prev = 0;
        foreach ($pages as $page) {
            if ($prev && $page - $prev > 1) {
                echo '<li><span class="page-numbers dots">&hellip;</span></li>';
            }
            $class = ( $page == $current_page ) ? 'current' : '';
            echo '<li><a href="#" class="page-numbers ' . esc_attr($class) . '" data-page="' . esc_attr((string) $page) . '">' . esc_html((string) $page) . '</a></li>';
            $prev = $page;
        }

        // Кнопка «Вперёд»
        if ($current_page < $total_pages) {
            echo '<li><a href="#" class="page-numbers next" data-page="' . esc_attr((string) ( $current_page + 1 )) . '">&rsaquo;</a></li>';
        }

        echo '</ul>';
        echo '</nav>';
    }

    /**
     * Get transactions for a user with pagination.
     *
     * @param int $user_id User ID.
     * @param int $limit   Number of records per page.
     * @param int $offset  Offset for pagination.
     * @return array Array of transaction objects.
     */
    private function get_transactions( $user_id, $limit, $offset, array $filters = array() ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_transactions';

        $where  = 'WHERE user_id = %d';
        $params = array( $user_id );

        $this->apply_filters($where, $params, $filters);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT reference_id, action_date, created_at, offer_name, order_number, cashback, order_status
             FROM {$table_name}
             {$where}
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            array_merge($params, array( $limit, $offset ))
        ));
    }

    /**
     * Get total number of transactions for a user.
     *
     * @param int   $user_id User ID.
     * @param array $filters Optional filters.
     * @return int Total transaction count.
     */
    private function get_total_transactions( $user_id, array $filters = array() ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_transactions';

        $where  = 'WHERE user_id = %d';
        $params = array( $user_id );

        $this->apply_filters($where, $params, $filters);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} {$where}",
            $params
        ));
    }

    /**
     * Apply filter conditions to WHERE clause.
     *
     * @param string $where  WHERE clause (modified by reference).
     * @param array  $params Query parameters (modified by reference).
     * @param array  $filters Filter values.
     * @return void
     */
    private function apply_filters( string &$where, array &$params, array $filters ): void {
        if (!empty($filters['date_from'])) {
            $where   .= ' AND created_at >= %s';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where   .= ' AND created_at <= %s';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $where   .= ' AND offer_name LIKE %s';
            $params[] = '%' . $GLOBALS['wpdb']->esc_like($filters['search']) . '%';
        }

        $allowed_statuses = array( 'waiting', 'completed', 'hold', 'declined', 'balance' );
        if (!empty($filters['status']) && in_array($filters['status'], $allowed_statuses, true)) {
            $where   .= ' AND order_status = %s';
            $params[] = $filters['status'];
        }
    }

    /**
     * Handle AJAX request to load transactions page.
     *
     * @return void
     */
    public function ajax_load_page(): void {
        // Verify nonce
        if (!isset($_POST['nonce']) || !check_ajax_referer('load_page_transactions_nonce', 'nonce', false)) {
            wp_send_json_error(esc_html__('Ошибка безопасности: неверный nonce.', 'cashback-plugin'));
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(esc_html__('Вы должны быть авторизованы.', 'cashback-plugin'));
        }

        // Rate limiting: максимум 30 запросов в минуту
        $rate_key   = 'cb_hist_page_rate_' . $user_id;
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 30) {
            wp_send_json_error(esc_html__('Слишком много запросов. Попробуйте через минуту.', 'cashback-plugin'));
        }
        set_transient($rate_key, $rate_count + 1, MINUTE_IN_SECONDS);

        if (!isset($_POST['page'])) {
            wp_send_json_error(esc_html__('Некорректный запрос.', 'cashback-plugin'));
        }

        $filters = array(
            'date_from' => sanitize_text_field(wp_unslash($_POST['date_from'] ?? '')),
            'date_to'   => sanitize_text_field(wp_unslash($_POST['date_to'] ?? '')),
            'search'    => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
            'status'    => sanitize_text_field(wp_unslash($_POST['status'] ?? '')),
        );

        $per_page    = self::PER_PAGE;
        $total       = $this->get_total_transactions($user_id, $filters);
        $total_pages = $total > 0 ? ceil($total / $per_page) : 1;
        $total_pages = min($total_pages, self::MAX_ALLOWED_PAGES);

        $page   = intval($_POST['page']);
        $page   = max(1, min($page, $total_pages));
        $offset = ( $page - 1 ) * $per_page;

        $transactions = $this->get_transactions($user_id, $per_page, $offset, $filters);

        ob_start();
        if (empty($transactions)) {
            echo '<p>' . esc_html__('Ничего не найдено.', 'cashback-plugin') . '</p>';
        } else {
            $this->render_transactions_table($transactions);
        }
        $html = ob_get_clean();

        wp_send_json_success(array(
            'html'         => $html,
            'current_page' => $page,
            'total_pages'  => $total_pages,
        ));
    }

    /**
     * Enqueue scripts for cashback history page.
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        $is_account_page  = function_exists('is_account_page') && is_account_page();
        $is_cashback_page = $this->is_cashback_history_page();

        // Load script on account page
        if ($is_account_page) {
            if ($is_cashback_page) {
                wp_enqueue_style(
                    'cashback-history-css',
                    plugin_dir_url(__FILE__) . 'assets/css/cashback-history.css',
                    array(),
                    '1.3.0'
                );
            }

            wp_enqueue_script(
                'cashback-history-ajax',
                plugin_dir_url(__FILE__) . 'assets/js/cashback-history.js',
                array( 'jquery' ),
                '1.1.0',
                true
            );

            // Use unique object name to avoid conflicts with other plugin scripts
            wp_localize_script('cashback-history-ajax', 'cashback_history_ajax', array(
                'ajax_url'         => admin_url('admin-ajax.php'),
                'nonce'            => wp_create_nonce('load_page_transactions_nonce'),
                'is_cashback_page' => $is_cashback_page ? 'true' : 'false',
            ));
        }
    }

    /**
     * Check if current page is cashback history page.
     *
     * @return bool True if on cashback history page, false otherwise.
     */
    private function is_cashback_history_page(): bool {
        global $wp;

        // Check if query var is set (even if empty, it means we're on the endpoint)
        if (isset($wp->query_vars['cashback-history'])) {
            return true;
        }

        return false;
    }

    /**
     * Get human-readable status label.
     *
     * @param string|null $status Order status.
     * @return string Translated status label.
     */
    private function get_status_label( $status ) {
        switch ($status) {
            case 'waiting':
                return __('В ожидании', 'cashback-plugin');
            case 'completed':
                return __('Подтвержден', 'cashback-plugin');
            case 'declined':
                return __('Отклонен', 'cashback-plugin');
            case 'hold':
                return __('На проверке', 'cashback-plugin');
            case 'balance':
                return __('Зачислен на баланс', 'cashback-plugin');
            default:
                return esc_html($status ?: __('Неизвестно', 'cashback-plugin'));
        }
    }

    /**
     * Safely format date with protection against invalid values.
     *
     * @param string|null $date_string Date string to format.
     * @return string Formatted date or fallback text.
     */
    private function format_date( $date_string ) {
        if (empty($date_string) || $date_string === '0000-00-00 00:00:00') {
            return esc_html__('Н/Д', 'cashback-plugin');
        }

        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return esc_html__('Некорректная дата', 'cashback-plugin');
        }

        return esc_html(date_i18n(get_option('date_format'), $timestamp));
    }
}

// Инициализация будет происходить в основном файле плагина
