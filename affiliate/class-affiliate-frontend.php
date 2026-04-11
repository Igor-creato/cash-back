<?php

/**
 * Affiliate Module — Frontend (WooCommerce My Account).
 *
 * Вкладка «Партнёрская программа» в личном кабинете:
 * реферальная ссылка, статистика, таблица начислений.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Affiliate_Frontend
{
    /** @var self|null */
    private static ?self $instance = null;

    const PER_PAGE = 10;

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        if (!Cashback_Affiliate_DB::is_module_enabled()) {
            return;
        }

        add_action('init', [$this, 'register_endpoint']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);
        add_action('woocommerce_account_cashback-affiliate_endpoint', [$this, 'endpoint_content']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // AJAX пагинация начислений и приглашённых
        add_action('wp_ajax_affiliate_load_accruals', [$this, 'ajax_load_accruals']);
        add_action('wp_ajax_affiliate_load_referrals', [$this, 'ajax_load_referrals']);
    }

    public function register_endpoint(): void
    {
        add_rewrite_endpoint('cashback-affiliate', EP_ROOT | EP_PAGES);
    }

    public function add_query_vars(array $vars): array
    {
        $vars[] = 'cashback-affiliate';
        return $vars;
    }

    public function add_menu_item(array $items): array
    {
        // Вставляем перед logout
        if (isset($items['customer-logout'])) {
            $logout = $items['customer-logout'];
            unset($items['customer-logout']);
            $items['cashback-affiliate'] = __('Партнёрская программа', 'cashback-plugin');
            $items['customer-logout']    = $logout;
        } else {
            $items['cashback-affiliate'] = __('Партнёрская программа', 'cashback-plugin');
        }
        return $items;
    }

    public function enqueue_scripts(): void
    {
        if (!is_user_logged_in() || is_admin()) {
            return;
        }

        if (!function_exists('is_account_page') || !is_account_page()) {
            return;
        }

        wp_enqueue_style(
            'cashback-frontend',
            plugins_url('../assets/css/frontend.css', __FILE__),
            [],
            '1.0.0'
        );

        wp_enqueue_style(
            'cashback-affiliate-frontend',
            plugins_url('../assets/css/affiliate-frontend.css', __FILE__),
            ['cashback-frontend'],
            '1.0.0'
        );

        wp_enqueue_script(
            'cashback-affiliate-frontend-js',
            plugins_url('../assets/js/affiliate-frontend.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('cashback-affiliate-frontend-js', 'cashbackAffiliateData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('affiliate_frontend_nonce'),
        ]);
    }

    /**
     * Содержимое вкладки «Партнёрская программа».
     */
    public function endpoint_content(): void
    {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html__('Необходимо войти в аккаунт.', 'cashback-plugin') . '</p>';
            return;
        }

        $user_id = get_current_user_id();

        // Проверяем бан
        if (class_exists('Cashback_User_Status') && Cashback_User_Status::is_user_banned($user_id)) {
            echo '<div class="woocommerce-error">'
                . esc_html__('Ваш аккаунт заблокирован. Партнёрская программа недоступна.', 'cashback-plugin')
                . '</div>';
            return;
        }

        // Убеждаемся что профиль есть
        Cashback_Affiliate_DB::ensure_profile($user_id);

        // Проверяем affiliate_status
        global $wpdb;
        $aff_status = $wpdb->get_var($wpdb->prepare(
            "SELECT affiliate_status FROM `{$wpdb->prefix}cashback_affiliate_profiles`
             WHERE user_id = %d LIMIT 1",
            $user_id
        ));

        $stats        = Cashback_Affiliate_Service::get_referrer_stats($user_id);
        $referral_link = Cashback_Affiliate_Service::get_referral_link($user_id);
        $rules_url    = Cashback_Affiliate_DB::get_rules_page_url();
        $rate         = Cashback_Affiliate_Service::get_effective_rate($user_id);

        echo '<div class="cashback-affiliate-page">';

        // Предупреждение при отключении
        if ($aff_status === 'disabled') {
            echo '<div class="cashback-affiliate-warning">'
                . '<strong>' . esc_html__('Внимание!', 'cashback-plugin') . '</strong> '
                . esc_html__('Вы нарушили условия партнёрской программы. Ваши партнёрские начисления заморожены и не будут производиться в будущем.', 'cashback-plugin')
                . '</div>';
        }

        // Реферальная ссылка
        echo '<div class="cashback-affiliate-section">';
        echo '<h3>' . esc_html__('Ваша реферальная ссылка', 'cashback-plugin') . '</h3>';
        echo '<div class="cashback-affiliate-link-box">';
        echo '<input type="text" readonly value="' . esc_attr($referral_link) . '" class="cashback-affiliate-link-input" id="affiliate-link-input">';
        echo '<button type="button" class="button cashback-affiliate-copy-btn" data-target="affiliate-link-input">'
            . esc_html__('Копировать', 'cashback-plugin') . '</button>';
        echo '</div>';

        if ($rules_url) {
            echo '<p class="cashback-affiliate-rules"><a href="' . esc_url($rules_url) . '" target="_blank">'
                . esc_html__('Правила партнёрской программы', 'cashback-plugin') . '</a></p>';
        }
        echo '</div>';

        // Статистика
        echo '<div class="cashback-affiliate-section cashback-affiliate-stats">';
        echo '<h3>' . esc_html__('Статистика', 'cashback-plugin') . '</h3>';
        echo '<div class="cashback-affiliate-stats-grid">';

        echo '<div class="cashback-affiliate-stat">';
        echo '<span class="stat-value">' . esc_html($stats['total_referrals']) . '</span>';
        echo '<span class="stat-label">' . esc_html__('Рефералы', 'cashback-plugin') . '</span>';
        echo '</div>';

        echo '<div class="cashback-affiliate-stat">';
        echo '<span class="stat-value">' . esc_html(number_format_i18n((float) $stats['total_earned'], 2)) . ' ₽</span>';
        echo '<span class="stat-label">' . esc_html__('Всего начислено', 'cashback-plugin') . '</span>';
        echo '</div>';

        echo '<div class="cashback-affiliate-stat">';
        echo '<span class="stat-value">' . esc_html(number_format_i18n((float) $stats['total_pending'], 2)) . ' ₽</span>';
        echo '<span class="stat-label">' . esc_html__('В ожидании', 'cashback-plugin') . '</span>';
        echo '</div>';

        echo '<div class="cashback-affiliate-stat">';
        echo '<span class="stat-value">' . esc_html(number_format_i18n((float) $stats['total_frozen'], 2)) . ' ₽</span>';
        echo '<span class="stat-label">' . esc_html__('Заморожено', 'cashback-plugin') . '</span>';
        echo '</div>';

        echo '<div class="cashback-affiliate-stat">';
        echo '<span class="stat-value">' . esc_html(number_format_i18n((float) $stats['total_declined'], 2)) . ' ₽</span>';
        echo '<span class="stat-label">' . esc_html__('Отклонено', 'cashback-plugin') . '</span>';
        echo '</div>';

        echo '<div class="cashback-affiliate-stat">';
        echo '<span class="stat-value">' . esc_html($rate) . '%</span>';
        echo '<span class="stat-label">' . esc_html__('Ваша ставка', 'cashback-plugin') . '</span>';
        echo '</div>';

        echo '</div>'; // stats-grid
        echo '</div>'; // section

        // Вкладки: История начислений / Список приглашённых
        echo '<div class="cashback-affiliate-section">';
        echo '<div class="cashback-tabs">';
        echo '<button type="button" class="cashback-tab active" data-tab="affiliate-tab-accruals">'
            . esc_html__('История начислений', 'cashback-plugin') . '</button>';
        echo '<button type="button" class="cashback-tab" data-tab="affiliate-tab-referrals">'
            . esc_html__('Список приглашённых', 'cashback-plugin') . '</button>';
        echo '</div>';

        // Вкладка: История начислений
        echo '<div class="cashback-tab-content active" id="affiliate-tab-accruals">';
        echo '<div id="affiliate-accruals-container">';
        $this->render_accruals_table($user_id, 1);
        echo '</div>';
        echo '</div>';

        // Вкладка: Список приглашённых
        echo '<div class="cashback-tab-content" id="affiliate-tab-referrals">';
        echo '<div id="affiliate-referrals-container">';
        $this->render_referrals_table($user_id, 1);
        echo '</div>';
        echo '</div>';

        echo '</div>'; // section

        echo '</div>'; // page
    }

    /**
     * Рендер таблицы начислений.
     */
    private function render_accruals_table(int $user_id, int $page): void
    {
        global $wpdb;
        $prefix   = $wpdb->prefix;
        $per_page = self::PER_PAGE;

        // Ленивая синхронизация: создать pending-записи если их ещё нет
        if (class_exists('Cashback_Affiliate_Service')) {
            Cashback_Affiliate_Service::sync_pending_accruals();
        }

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$prefix}cashback_affiliate_accruals`
             WHERE referrer_id = %d",
            $user_id
        ));

        $total_pages = max(1, (int) ceil($total / $per_page));
        $page        = min($page, $total_pages);
        $offset      = ($page - 1) * $per_page;

        $accruals = $wpdb->get_results($wpdb->prepare(
            "SELECT a.reference_id, a.commission_amount, a.commission_rate,
                    a.cashback_amount, a.status AS display_status, a.created_at,
                    u.display_name AS referred_name
             FROM `{$prefix}cashback_affiliate_accruals` a
             LEFT JOIN `{$wpdb->users}` u ON u.ID = a.referred_user_id
             WHERE a.referrer_id = %d
             ORDER BY a.created_at DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ), ARRAY_A);

        if (empty($accruals)) {
            echo '<p class="cashback-affiliate-empty">'
                . esc_html__('Начислений пока нет.', 'cashback-plugin') . '</p>';
            return;
        }

        echo '<table class="cashback-affiliate-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Дата', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('ID', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Реферал', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Кешбэк', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Ставка', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Комиссия', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Статус', 'cashback-plugin') . '</th>';
        echo '</tr></thead><tbody>';

        $status_labels = [
            'available' => __('Зачислен на баланс', 'cashback-plugin'),
            'frozen'    => __('Заморожено', 'cashback-plugin'),
            'paid'      => __('Выплачено', 'cashback-plugin'),
            'pending'   => __('В ожидании', 'cashback-plugin'),
            'declined'  => __('Отклонён', 'cashback-plugin'),
        ];

        foreach ($accruals as $row) {
            $status_class = 'status-' . esc_attr($row['display_status']);
            $status_label = $status_labels[$row['display_status']] ?? $row['display_status'];

            echo '<tr>';
            echo '<td>' . esc_html(wp_date('d.m.Y H:i', strtotime($row['created_at']))) . '</td>';
            echo '<td><code>' . esc_html($row['reference_id']) . '</code></td>';
            echo '<td>' . esc_html($row['referred_name'] ?: '—') . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) $row['cashback_amount'], 2)) . ' ₽</td>';
            echo '<td>' . esc_html($row['commission_rate']) . '%</td>';
            echo '<td><strong>' . esc_html(number_format_i18n((float) $row['commission_amount'], 2)) . ' ₽</strong></td>';
            echo '<td><span class="cashback-affiliate-status ' . $status_class . '">'
                . esc_html($status_label) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Пагинация
        if ($total_pages > 1) {
            echo '<div class="cashback-affiliate-pagination" data-total="' . esc_attr($total_pages) . '">';
            for ($i = 1; $i <= $total_pages; $i++) {
                $active = $i === $page ? ' active' : '';
                echo '<button type="button" class="cashback-affiliate-page-btn' . $active . '" data-page="' . esc_attr($i) . '">'
                    . esc_html($i) . '</button>';
            }
            echo '</div>';
        }
    }

    /**
     * AJAX: загрузка страницы начислений.
     */
    public function ajax_load_accruals(): void
    {
        if (!check_ajax_referer('affiliate_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Неверный nonce.']);
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Не авторизован.']);
            return;
        }

        $user_id = get_current_user_id();
        $page    = max(1, absint($_POST['page'] ?? 1));

        ob_start();
        $this->render_accruals_table($user_id, $page);
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Рендер таблицы приглашённых пользователей.
     */
    private function render_referrals_table(int $user_id, int $page): void
    {
        global $wpdb;
        $prefix   = $wpdb->prefix;
        $per_page = self::PER_PAGE;
        $offset   = ($page - 1) * $per_page;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$prefix}cashback_affiliate_profiles`
             WHERE referred_by_user_id = %d",
            $user_id
        ));

        $total_pages = max(1, (int) ceil($total / $per_page));
        $page        = min($page, $total_pages);

        $referrals = $wpdb->get_results($wpdb->prepare(
            "SELECT ap.user_id, ap.referred_at, ap.affiliate_status,
                    u.display_name, u.user_registered,
                    COALESCE(SUM(aa.commission_amount), 0) AS total_earned
             FROM `{$prefix}cashback_affiliate_profiles` ap
             LEFT JOIN `{$wpdb->users}` u ON u.ID = ap.user_id
             LEFT JOIN `{$prefix}cashback_affiliate_accruals` aa
                    ON aa.referred_user_id = ap.user_id AND aa.referrer_id = %d
             WHERE ap.referred_by_user_id = %d
             GROUP BY ap.user_id, ap.referred_at, ap.affiliate_status,
                      u.display_name, u.user_registered
             ORDER BY ap.referred_at DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $user_id,
            $per_page,
            $offset
        ), ARRAY_A);

        if (empty($referrals)) {
            echo '<p class="cashback-affiliate-empty">'
                . esc_html__('Приглашённых пользователей пока нет.', 'cashback-plugin') . '</p>';
            return;
        }

        echo '<table class="cashback-affiliate-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Имя', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Дата регистрации', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Дата привязки', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Заработано', 'cashback-plugin') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($referrals as $row) {
            $registered = $row['user_registered']
                ? wp_date('d.m.Y', strtotime($row['user_registered']))
                : '—';
            $referred_at = $row['referred_at']
                ? wp_date('d.m.Y', strtotime($row['referred_at']))
                : '—';

            echo '<tr>';
            echo '<td>' . esc_html($row['display_name'] ?: '—') . '</td>';
            echo '<td>' . esc_html($registered) . '</td>';
            echo '<td>' . esc_html($referred_at) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) $row['total_earned'], 2)) . ' ₽</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Пагинация
        if ($total_pages > 1) {
            echo '<div class="cashback-affiliate-pagination cashback-affiliate-referrals-pagination" data-total="' . esc_attr($total_pages) . '">';
            for ($i = 1; $i <= $total_pages; $i++) {
                $active = $i === $page ? ' active' : '';
                echo '<button type="button" class="cashback-affiliate-page-btn' . $active . '" data-page="' . esc_attr($i) . '">'
                    . esc_html($i) . '</button>';
            }
            echo '</div>';
        }
    }

    /**
     * AJAX: загрузка страницы приглашённых.
     */
    public function ajax_load_referrals(): void
    {
        if (!check_ajax_referer('affiliate_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Неверный nonce.']);
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Не авторизован.']);
            return;
        }

        $user_id = get_current_user_id();
        $page    = max(1, absint($_POST['page'] ?? 1));

        ob_start();
        $this->render_referrals_table($user_id, $page);
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }
}
