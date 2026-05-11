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

class Cashback_Affiliate_Frontend {

    /** @var self|null */
    private static ?self $instance = null;

    const PER_PAGE = 10;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!Cashback_Affiliate_DB::is_module_enabled()) {
            return;
        }

        add_action('init', array( $this, 'register_endpoint' ));
        add_filter('query_vars', array( $this, 'add_query_vars' ));
        add_filter('woocommerce_account_menu_items', array( $this, 'add_menu_item' ));
        add_action('woocommerce_account_cashback-affiliate_endpoint', array( $this, 'endpoint_content' ));
        add_action('wp_enqueue_scripts', array( $this, 'enqueue_scripts' ));

        // AJAX пагинация начислений и приглашённых
        add_action('wp_ajax_affiliate_load_accruals', array( $this, 'ajax_load_accruals' ));
        add_action('wp_ajax_affiliate_load_referrals', array( $this, 'ajax_load_referrals' ));
    }

    public function register_endpoint(): void {
        add_rewrite_endpoint('cashback-affiliate', EP_ROOT | EP_PAGES);
    }

    public function add_query_vars( array $vars ): array {
        $vars[] = 'cashback-affiliate';
        return $vars;
    }

    public function add_menu_item( array $items ): array {
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

    public function enqueue_scripts(): void {
        if (!is_user_logged_in() || is_admin()) {
            return;
        }

        if (!function_exists('is_account_page') || !is_account_page()) {
            return;
        }

        wp_enqueue_style(
            'cashback-frontend',
            cashback_asset_url('assets/css/frontend.css'),
            array( 'cashback-account-base' ),
            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version embedded via cashback_asset_url() ?cv=<filemtime>
            null
        );

        wp_enqueue_style(
            'cashback-affiliate-frontend',
            cashback_asset_url('assets/css/affiliate-frontend.css'),
            array( 'cashback-account-base', 'cashback-frontend' ),
            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version embedded via cashback_asset_url() ?cv=<filemtime>
            null
        );

        Cashback_Assets::enqueue_safe_html();

        wp_enqueue_script(
            'cashback-pagination',
            plugins_url('../assets/js/cashback-pagination.js', __FILE__),
            array(),
            '1.0.0',
            true
        );

        wp_enqueue_script(
            'cashback-affiliate-frontend-js',
            plugins_url('../assets/js/affiliate-frontend.js', __FILE__),
            array( 'jquery', 'cashback-pagination', 'cashback-safe-html' ),
            '1.1.0',
            true
        );

        wp_localize_script('cashback-affiliate-frontend-js', 'cashbackAffiliateData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('affiliate_frontend_nonce'),
        ));
    }

    /**
     * Содержимое вкладки «Партнёрская программа».
     */
    public function endpoint_content(): void {
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

        // Legal opt-in gate: до явного акцепта Условий партнёрской программы
        // (consent_type='affiliate_program') скрываем UI и показываем форму
        // активации. После акцепта пользователь увидит обычный экран.
        if (class_exists('Cashback_Affiliate_Activation')
            && !Cashback_Affiliate_Activation::is_activated($user_id)) {
            echo '<div class="cashback-affiliate-page cashback-affiliate-page--activation">';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран в render_activation_form() с esc_*; локальные плейсхолдеры экранируются там же.
            echo Cashback_Affiliate_Activation::render_activation_form($user_id);
            echo '</div>';
            return;
        }

        // Проверяем affiliate_status
        global $wpdb;
        $aff_table  = $wpdb->prefix . 'cashback_affiliate_profiles';
        $aff_status = $wpdb->get_var($wpdb->prepare(
            'SELECT affiliate_status FROM %i
             WHERE user_id = %d LIMIT 1',
            $aff_table,
            $user_id
        ));

        $stats         = Cashback_Affiliate_Service::get_referrer_stats($user_id);
        $referral_link = Cashback_Affiliate_Service::get_referral_link($user_id);
        $rules_url     = Cashback_Affiliate_DB::get_rules_page_url();
        $rate          = Cashback_Affiliate_Service::get_effective_rate($user_id);

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
        $accruals_meta = $this->render_accruals_table($user_id, 1);
        echo '</div>';
        echo '<div id="affiliate-accruals-pagination">';
        if ($accruals_meta['total_pages'] > 1) {
            Cashback_Pagination::render(array(
                'mode'         => 'ajax',
                'current_page' => $accruals_meta['current_page'],
                'total_pages'  => $accruals_meta['total_pages'],
            ));
        }
        echo '</div>';
        echo '</div>';

        // Вкладка: Список приглашённых
        echo '<div class="cashback-tab-content" id="affiliate-tab-referrals">';
        echo '<div id="affiliate-referrals-container">';
        $referrals_meta = $this->render_referrals_table($user_id, 1);
        echo '</div>';
        echo '<div id="affiliate-referrals-pagination">';
        if ($referrals_meta['total_pages'] > 1) {
            Cashback_Pagination::render(array(
                'mode'         => 'ajax',
                'current_page' => $referrals_meta['current_page'],
                'total_pages'  => $referrals_meta['total_pages'],
            ));
        }
        echo '</div>';
        echo '</div>';

        echo '</div>'; // section

        echo '</div>'; // page
    }

    /**
     * Рендер таблицы начислений.
     *
     * @return array{current_page:int,total_pages:int} Meta для отдельного рендера пагинации.
     */
    private function render_accruals_table( int $user_id, int $page ): array {
        global $wpdb;
        $prefix   = $wpdb->prefix;
        $per_page = self::PER_PAGE;

        // Ленивая синхронизация: создать pending-записи если их ещё нет
        if (class_exists('Cashback_Affiliate_Service')) {
            Cashback_Affiliate_Service::sync_pending_accruals();
        }

        $accruals_table = $prefix . 'cashback_affiliate_accruals';
        $total          = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i
             WHERE referrer_id = %d',
            $accruals_table,
            $user_id
        ));

        $total_pages = max(1, (int) ceil($total / $per_page));
        $page        = min($page, $total_pages);
        $offset      = ( $page - 1 ) * $per_page;

        $accruals = $wpdb->get_results($wpdb->prepare(
            'SELECT a.id, a.reference_id, a.commission_amount,
                    a.status AS display_status, a.created_at,
                    u.display_name AS referred_name
             FROM %i a
             LEFT JOIN %i u ON u.ID = a.referred_user_id
             WHERE a.referrer_id = %d
             ORDER BY a.created_at DESC
             LIMIT %d OFFSET %d',
            $accruals_table,
            $wpdb->users,
            $user_id,
            $per_page,
            $offset
        ), ARRAY_A);

        if (empty($accruals)) {
            echo '<p class="cashback-affiliate-empty">'
                . esc_html__('Начислений пока нет.', 'cashback-plugin') . '</p>';
            return array(
                'current_page' => 1,
                'total_pages'  => 0,
            );
        }

        $support_enabled = class_exists('Cashback_Support_DB') && Cashback_Support_DB::is_module_enabled();
        $support_base    = $support_enabled ? wc_get_account_endpoint_url('cashback-support') : '';

        echo '<table class="cashback-affiliate-table cashback-data-table cashback-data-table--mobile-cards">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Дата', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('ID', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Реферал', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Комиссия', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Статус', 'cashback-plugin') . '</th>';
        if ($support_enabled) {
            echo '<th class="col-support-action">' . esc_html__('Поддержка', 'cashback-plugin') . '</th>';
        }
        echo '</tr></thead><tbody>';

        $status_labels = array(
            'available' => __('Зачислен на баланс', 'cashback-plugin'),
            'frozen'    => __('Заморожено', 'cashback-plugin'),
            'paid'      => __('Выплачено', 'cashback-plugin'),
            'pending'   => __('В ожидании', 'cashback-plugin'),
            'declined'  => __('Отклонён', 'cashback-plugin'),
        );

        foreach ($accruals as $row) {
            $status_key      = (string) $row['display_status'];
            $status_class    = 'status-' . $status_key;
            $status_semantic = $this->map_status_semantic($status_key);
            $status_label    = $status_labels[ $status_key ] ?? $status_key;

            echo '<tr>';
            echo '<td data-title="' . esc_attr__('Дата', 'cashback-plugin') . '">' . esc_html(wp_date('d.m.Y', strtotime($row['created_at']))) . '</td>';
            echo '<td data-title="' . esc_attr__('ID', 'cashback-plugin') . '"><code>' . esc_html($row['reference_id']) . '</code></td>';
            echo '<td data-title="' . esc_attr__('Реферал', 'cashback-plugin') . '">' . esc_html($row['referred_name'] ?: '—') . '</td>';
            echo '<td data-title="' . esc_attr__('Комиссия', 'cashback-plugin') . '"><strong>' . esc_html(number_format_i18n((float) $row['commission_amount'], 2)) . ' ₽</strong></td>';
            echo '<td data-title="' . esc_attr__('Статус', 'cashback-plugin') . '"><span class="cashback-affiliate-status ' . esc_attr($status_class) . ' cashback-status--' . esc_attr($status_semantic) . '">'
                . esc_html($status_label) . '</span></td>';
            if ($support_enabled) {
                $support_url = add_query_arg(
                    array(
                        'related_type' => 'affiliate_accrual',
                        'related_id'   => (int) $row['id'],
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

        echo '</tbody></table>';

        return array(
            'current_page' => $page,
            'total_pages'  => $total_pages,
        );
    }

    /**
     * AJAX: загрузка страницы начислений.
     */
    public function ajax_load_accruals(): void {
        if (!check_ajax_referer('affiliate_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Неверный nonce.' ));
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(array( 'message' => 'Не авторизован.' ));
            return;
        }

        $user_id = get_current_user_id();
        $page    = max(1, absint($_POST['page'] ?? 1));

        ob_start();
        $meta = $this->render_accruals_table($user_id, $page);
        $html = ob_get_clean();

        wp_send_json_success(array(
            'html'         => $html,
            'current_page' => $meta['current_page'],
            'total_pages'  => $meta['total_pages'],
        ));
    }

    /**
     * Рендер таблицы приглашённых пользователей.
     *
     * @return array{current_page:int,total_pages:int} Meta для отдельного рендера пагинации.
     */
    private function render_referrals_table( int $user_id, int $page ): array {
        global $wpdb;
        $prefix   = $wpdb->prefix;
        $per_page = self::PER_PAGE;

        $profiles_table = $prefix . 'cashback_affiliate_profiles';
        $accruals_table = $prefix . 'cashback_affiliate_accruals';
        $total          = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i
             WHERE referred_by_user_id = %d',
            $profiles_table,
            $user_id
        ));

        $total_pages = max(1, (int) ceil($total / $per_page));
        $page        = min($page, $total_pages);
        $offset      = ( $page - 1 ) * $per_page;

        $referrals = $wpdb->get_results($wpdb->prepare(
            'SELECT ap.user_id, ap.referred_at, ap.affiliate_status,
                    u.display_name, u.user_registered,
                    COALESCE(SUM(aa.commission_amount), 0) AS total_earned
             FROM %i ap
             LEFT JOIN %i u ON u.ID = ap.user_id
             LEFT JOIN %i aa
                    ON aa.referred_user_id = ap.user_id AND aa.referrer_id = %d
             WHERE ap.referred_by_user_id = %d
             GROUP BY ap.user_id, ap.referred_at, ap.affiliate_status,
                      u.display_name, u.user_registered
             ORDER BY ap.referred_at DESC
             LIMIT %d OFFSET %d',
            $profiles_table,
            $wpdb->users,
            $accruals_table,
            $user_id,
            $user_id,
            $per_page,
            $offset
        ), ARRAY_A);

        if (empty($referrals)) {
            echo '<p class="cashback-affiliate-empty">'
                . esc_html__('Приглашённых пользователей пока нет.', 'cashback-plugin') . '</p>';
            return array(
                'current_page' => 1,
                'total_pages'  => 0,
            );
        }

        echo '<table class="cashback-affiliate-table cashback-data-table cashback-data-table--mobile-cards">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Имя', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Дата регистрации', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Дата привязки', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Заработано', 'cashback-plugin') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($referrals as $row) {
            $registered  = $row['user_registered']
                ? wp_date('d.m.Y', strtotime($row['user_registered']))
                : '—';
            $referred_at = $row['referred_at']
                ? wp_date('d.m.Y', strtotime($row['referred_at']))
                : '—';

            echo '<tr>';
            echo '<td data-title="' . esc_attr__('Имя', 'cashback-plugin') . '">' . esc_html($row['display_name'] ?: '—') . '</td>';
            echo '<td data-title="' . esc_attr__('Дата регистрации', 'cashback-plugin') . '">' . esc_html($registered) . '</td>';
            echo '<td data-title="' . esc_attr__('Дата привязки', 'cashback-plugin') . '">' . esc_html($referred_at) . '</td>';
            echo '<td data-title="' . esc_attr__('Заработано', 'cashback-plugin') . '">' . esc_html(number_format_i18n((float) $row['total_earned'], 2)) . ' ₽</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        return array(
            'current_page' => $page,
            'total_pages'  => $total_pages,
        );
    }

    /**
     * Map affiliate accrual status key → semantic palette name for
     * .cashback-status--{semantic}.
     *
     * Используется в render_accruals_table() для применения единых
     * цветов через base.css. Старый класс status-X сохраняется
     * параллельно как data-маркер.
     *
     * Семантика affiliate (отличается от payouts: тут `paid`=info,
     * т.к. означает «выплачено реферал», а не «зачислено клиенту»):
     *  - available → success
     *  - frozen → warning
     *  - paid → info
     *  - pending → neutral
     *  - declined → danger
     *
     * @param string $status Raw display_status from accrual row.
     * @return string semantic palette name (success|info|warning|danger|neutral).
     */
    private function map_status_semantic( string $status ): string {
        static $map = array(
            'available' => 'success',
            'frozen'    => 'warning',
            'paid'      => 'info',
            'pending'   => 'neutral',
            'declined'  => 'danger',
        );

        return $map[ $status ] ?? 'neutral';
    }

    /**
     * AJAX: загрузка страницы приглашённых.
     */
    public function ajax_load_referrals(): void {
        if (!check_ajax_referer('affiliate_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Неверный nonce.' ));
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(array( 'message' => 'Не авторизован.' ));
            return;
        }

        $user_id = get_current_user_id();
        $page    = max(1, absint($_POST['page'] ?? 1));

        ob_start();
        $meta = $this->render_referrals_table($user_id, $page);
        $html = ob_get_clean();

        wp_send_json_success(array(
            'html'         => $html,
            'current_page' => $meta['current_page'],
            'total_pages'  => $meta['total_pages'],
        ));
    }
}
