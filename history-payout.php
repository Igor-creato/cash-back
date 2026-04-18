<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class HistoryPayout {

    private const PER_PAGE          = 10;
    private const MAX_ALLOWED_PAGES = 1000; // Защита от DoS (макс. 10 000 записей)

    private static $instance = null;

    private $payout_method_labels = null;

    private $bank_names = null;

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
        add_action('woocommerce_account_history-payout_endpoint', array( $this, 'content' ));
        add_action('wp_ajax_load_page_payouts', array( $this, 'ajax_load_page' ));
        add_action('wp_enqueue_scripts', array( $this, 'enqueue_scripts' ));
    }

    public function register_endpoint() {
        add_rewrite_endpoint('history-payout', EP_ROOT | EP_PAGES);
    }

    public function add_query_vars( $vars ) {
        $vars[] = 'history-payout';
        return $vars;
    }

    public function add_menu_item( $items ) {
        if (isset($items['customer-logout'])) {
            $logout = $items['customer-logout'];
            unset($items['customer-logout']);
            $items['history-payout']  = __('История выплат', 'cashback-plugin');
            $items['customer-logout'] = $logout;
        } else {
            $items['history-payout'] = __('История выплат', 'cashback-plugin');
        }
        return $items;
    }

    public function content() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            echo '<p>' . esc_html__('Вы должны быть авторизованы.', 'cashback-plugin') . '</p>';
            return;
        }

        // Показываем информационное сообщение, но НЕ блокируем просмотр
        if (Cashback_User_Status::is_user_banned($user_id)) {
            echo '<div class="woocommerce-message woocommerce-info" role="alert">';
            echo esc_html__('Ваш аккаунт заблокирован. Вы можете просматривать историю выплат в режиме только для чтения.', 'cashback-plugin');
            echo '</div>';
        }

        $per_page    = self::PER_PAGE;
        $total       = $this->get_total_payouts($user_id);
        $total_pages = $total > 0 ? ceil($total / $per_page) : 1;
        $total_pages = min($total_pages, self::MAX_ALLOWED_PAGES);

        $page   = isset($_GET['page']) ? intval($_GET['page']) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination, no state change
        $page   = max(1, min($page, $total_pages));
        $offset = ( $page - 1 ) * $per_page;

        $payouts = $this->get_payouts($user_id, $per_page, $offset);

        echo '<div class="wd-history-payout">';
        echo '<h2>' . esc_html__('История выплат', 'cashback-plugin') . '</h2>';

        // Фильтры
        echo '<div class="clicks-filters">';
        echo '<div class="clicks-filters-row">';

        echo '<div class="clicks-filter-group">';
        echo '<label for="payout-date-from">' . esc_html__('С', 'cashback-plugin') . '</label>';
        echo '<input type="date" id="payout-date-from" class="clicks-filter-input">';
        echo '</div>';

        echo '<div class="clicks-filter-group">';
        echo '<label for="payout-date-to">' . esc_html__('По', 'cashback-plugin') . '</label>';
        echo '<input type="date" id="payout-date-to" class="clicks-filter-input">';
        echo '</div>';

        echo '<div class="clicks-filter-group">';
        echo '<label for="payout-search">' . esc_html__('Номер заявки', 'cashback-plugin') . '</label>';
        echo '<input type="text" id="payout-search" class="clicks-filter-input" placeholder="' . esc_attr__('WD-XXXXXXXX', 'cashback-plugin') . '">';
        echo '</div>';

        echo '<div class="clicks-filter-group">';
        echo '<label for="payout-status">' . esc_html__('Статус', 'cashback-plugin') . '</label>';
        echo '<select id="payout-status" class="clicks-filter-input">';
        echo '<option value="">' . esc_html__('Все статусы', 'cashback-plugin') . '</option>';
        echo '<option value="waiting">' . esc_html__('В ожидании', 'cashback-plugin') . '</option>';
        echo '<option value="processing">' . esc_html__('В обработке', 'cashback-plugin') . '</option>';
        echo '<option value="paid">' . esc_html__('Выплачен', 'cashback-plugin') . '</option>';
        echo '<option value="failed">' . esc_html__('Возврат в доступный баланс', 'cashback-plugin') . '</option>';
        echo '<option value="declined">' . esc_html__('Выплата заморожена', 'cashback-plugin') . '</option>';
        echo '</select>';
        echo '</div>';

        echo '<div class="clicks-filter-group clicks-filter-buttons">';
        echo '<button type="button" id="payout-filter-apply" class="button">' . esc_html__('Применить', 'cashback-plugin') . '</button>';
        echo '<button type="button" id="payout-filter-reset" class="button clicks-filter-reset">' . esc_html__('Сбросить', 'cashback-plugin') . '</button>';
        echo '</div>';

        echo '</div>'; // clicks-filters-row
        echo '</div>'; // clicks-filters

        echo '<div id="payouts-table-container">';
        if (empty($payouts)) {
            echo '<p>' . esc_html__('У вас нет истории выплат.', 'cashback-plugin') . '</p>';
        } else {
            $this->render_payouts_table($payouts);
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
     * Render payouts table HTML.
     *
     * @param array $payouts Array of payout objects.
     * @return void
     */
    private function render_payouts_table( array $payouts ): void {
        $support_enabled = class_exists('Cashback_Support_DB') && Cashback_Support_DB::is_module_enabled();
        $support_base    = $support_enabled ? wc_get_account_endpoint_url('cashback-support') : '';

        echo '<table id="payouts-table" class="wd-table shop_table_responsive">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('Номер заявки', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Дата', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Сумма', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Способ вывода', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Счет', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Банк', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Статус', 'cashback-plugin') . '</th>';
        if ($support_enabled) {
            echo '<th class="col-support-action">' . esc_html__('Поддержка', 'cashback-plugin') . '</th>';
        }
        echo '</tr>';
        echo '</thead>';
        echo '<tbody id="payouts-body">';

        foreach ($payouts as $payout) {
            echo '<tr>';
            echo '<td data-title="' . esc_attr__('Номер заявки', 'cashback-plugin') . '">' . esc_html(!empty($payout->reference_id) ? $payout->reference_id : '---') . '</td>';
            echo '<td data-title="' . esc_attr__('Дата', 'cashback-plugin') . '">' . $this->format_date($payout->created_at) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format_date() возвращает уже экранированное значение.
            echo '<td data-title="' . esc_attr__('Сумма', 'cashback-plugin') . '">' . esc_html($payout->total_amount ?? '0.00') . '</td>';
            echo '<td data-title="' . esc_attr__('Способ вывода', 'cashback-plugin') . '">' . esc_html($this->get_payout_method_label($payout->payout_method) ?: __('Не указан', 'cashback-plugin')) . '</td>';
            echo '<td data-title="' . esc_attr__('Счет', 'cashback-plugin') . '">' . esc_html($this->get_display_account($payout) ?: __('Не указан', 'cashback-plugin')) . '</td>';
            echo '<td data-title="' . esc_attr__('Банк', 'cashback-plugin') . '">' . esc_html($this->get_bank_name_by_code($payout->provider ?? '') ?: __('Не указан', 'cashback-plugin')) . '</td>';
            echo '<td data-title="' . esc_attr__('Статус', 'cashback-plugin') . '" class="status-' . esc_attr($payout->status) . '">' . esc_html($this->get_status_label($payout->status)) . '</td>';
            if ($support_enabled) {
                $support_url = add_query_arg(
                    array(
                        'related_type' => 'payout',
                        'related_id'   => (int) $payout->id,
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

    private function get_payouts( $user_id, $limit, $offset, array $filters = array() ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_payout_requests';

        $where  = 'WHERE user_id = %d';
        $params = array( $user_id );

        $this->apply_payout_filters($where, $params, $filters);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where from allowlisted conditions with %s/%d placeholders; array_merge hides actual argument count from sniff.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, reference_id, created_at, total_amount, payout_method, payout_account, masked_details, provider, status
             FROM %i
             {$where}
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            array_merge( array( $table_name ), $params, array( $limit, $offset ) )
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    private function get_total_payouts( $user_id, array $filters = array() ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cashback_payout_requests';

        $where  = 'WHERE user_id = %d';
        $params = array( $user_id );

        $this->apply_payout_filters($where, $params, $filters);

        return (int) $wpdb->get_var($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where from allowlisted conditions with %s/%d placeholders.
            "SELECT COUNT(*) FROM %i {$where}",
            array_merge( array( $table_name ), $params )
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
    private function apply_payout_filters( string &$where, array &$params, array $filters ): void {
        if (!empty($filters['date_from'])) {
            $where   .= ' AND created_at >= %s';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where   .= ' AND created_at <= %s';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $where   .= ' AND reference_id LIKE %s';
            $params[] = '%' . $GLOBALS['wpdb']->esc_like($filters['search']) . '%';
        }

        $allowed_statuses = array( 'waiting', 'processing', 'paid', 'failed', 'declined', 'needs_retry' );
        if (!empty($filters['status']) && in_array($filters['status'], $allowed_statuses, true)) {
            $where   .= ' AND status = %s';
            $params[] = $filters['status'];
        }
    }

    public function ajax_load_page() {
        if (!check_ajax_referer('load_page_payouts_nonce', 'nonce', false)) {
            wp_send_json_error(esc_html__('Ошибка безопасности: неверный nonce.', 'cashback-plugin'));
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(esc_html__('Вы должны быть авторизованы.', 'cashback-plugin'));
        }

        // Rate limiting: максимум 30 запросов в минуту
        $rate_key   = 'cb_payout_page_rate_' . $user_id;
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
        $total       = $this->get_total_payouts($user_id, $filters);
        $total_pages = $total > 0 ? ceil($total / $per_page) : 1;
        $total_pages = min($total_pages, self::MAX_ALLOWED_PAGES);

        $page   = intval($_POST['page']);
        $page   = max(1, min($page, $total_pages));
        $offset = ( $page - 1 ) * $per_page;

        $payouts = $this->get_payouts($user_id, $per_page, $offset, $filters);

        ob_start();
        if (empty($payouts)) {
            echo '<p>' . esc_html__('Ничего не найдено.', 'cashback-plugin') . '</p>';
        } else {
            $this->render_payouts_table($payouts);
        }
        $html = ob_get_clean();

        wp_send_json_success(array(
            'html'         => $html,
            'current_page' => $page,
            'total_pages'  => $total_pages,
        ));
    }

    public function enqueue_scripts() {
        if (function_exists('is_account_page') && is_account_page() && $this->is_history_payout_page()) {
            wp_enqueue_style(
                'history-payout-css',
                plugin_dir_url(__FILE__) . 'assets/css/history-payout.css',
                array(),
                '1.5.0'
            );

            wp_enqueue_script(
                'history-payout-ajax',
                plugin_dir_url(__FILE__) . 'assets/js/history-payout.js',
                array( 'jquery' ),
                '1.1.0',
                true
            );
            wp_localize_script('history-payout-ajax', 'payout_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('load_page_payouts_nonce'),
            ));
        }
    }

    private function is_history_payout_page() {
        global $wp;
        return isset($wp->query_vars['history-payout']);
    }

    private function get_status_label( $status ) {
        switch ($status) {
            case 'waiting':
                return __('В ожидании', 'cashback-plugin');
            case 'processing':
                return __('В обработке', 'cashback-plugin');
            case 'paid':
                return __('Выплачен', 'cashback-plugin');
            case 'failed':
                return __('Возврат в доступный баланс', 'cashback-plugin');
            case 'declined':
                return __('Выплата заморожена', 'cashback-plugin');
            case 'needs_retry':
                return __('В обработке', 'cashback-plugin');
            default:
                return esc_html($status ?: __('Неизвестно', 'cashback-plugin'));
        }
    }

    /**
     * Безопасное форматирование даты с защитой от некорректных значений
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

    /**
     * Get payout method label for display
     *
     * @param string $method
     * @return string
     */
    private function get_payout_method_label( $method ) {
        // Load payout method labels from database if not already loaded
        if ($this->payout_method_labels === null) {
            $this->load_payout_method_labels();
        }

        // Return the label from the cached array
        if (isset($this->payout_method_labels[ $method ])) {
            return $this->payout_method_labels[ $method ];
        }

        // Fallback to hardcoded labels for backward compatibility
        $fallback_labels = array(
            'sbp'      => __('Система быстрых платежей (СБП)', 'cashback-plugin'),
            'mir'      => __('Карта МИР', 'cashback-plugin'),
            'yoomoney' => __('ЮMoney', 'cashback-plugin'),
            'ppl'      => __('Paypal', 'cashback-plugin'),
        );

        return isset($fallback_labels[ $method ]) ? $fallback_labels[ $method ] : ucfirst($method);
    }

    /**
     * Load payout method labels from database
     */
    private function load_payout_method_labels() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_payout_methods';
        $methods    = $wpdb->get_results(
            $wpdb->prepare( 'SELECT slug, name FROM %i WHERE is_active = %d', $table_name, 1 ),
            ARRAY_A
        );

        $this->payout_method_labels = array();

        foreach ($methods as $method) {
            $this->payout_method_labels[ $method['slug'] ] = $method['name'];
        }
    }

    /**
     * Получает маскированный номер счёта для отображения.
     * Использует masked_details если доступен, иначе fallback на payout_account.
     */
    private function get_display_account( $payout ): string {
        if (class_exists('Cashback_Encryption')) {
            return Cashback_Encryption::get_masked_account(
                $payout->masked_details ?? null,
                $payout->payout_account ?? null
            );
        }
        return $payout->payout_account ?? '';
    }

    /**
     * Load all bank names into cache (one query instead of N+1).
     */
    private function load_bank_names(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cashback_banks';
        $rows       = $wpdb->get_results(
            $wpdb->prepare( 'SELECT bank_code, name FROM %i', $table_name ),
            ARRAY_A
        );

        $this->bank_names = array();
        if ($rows) {
            foreach ($rows as $row) {
                $this->bank_names[ $row['bank_code'] ] = $row['name'];
            }
        }
    }

    /**
     * Get bank name by bank code (cached).
     *
     * @param string $bank_code Bank code
     * @return string Bank name or empty string
     */
    private function get_bank_name_by_code( $bank_code ) {
        if (empty($bank_code)) {
            return '';
        }

        if ($this->bank_names === null) {
            $this->load_bank_names();
        }

        return $this->bank_names[ $bank_code ] ?? '';
    }
}

// Инициализация будет происходить в основном файле плагина
