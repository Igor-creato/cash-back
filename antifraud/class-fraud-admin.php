<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Административная страница антифрод-модуля
 * Меню: Кэшбэк → Защита
 * Вкладки: Уведомления, Подозрительные, Настройки
 *
 * @since 1.2.0
 */
class Cashback_Fraud_Admin {

    private const PER_PAGE          = 20;
    private const MAX_ALLOWED_PAGES = 1000;

    public function __construct() {
        add_action('admin_menu', array( $this, 'add_admin_menu' ));

        add_action('wp_ajax_fraud_review_alert', array( $this, 'handle_review_alert' ));
        add_action('wp_ajax_fraud_get_alert_details', array( $this, 'handle_get_alert_details' ));
        add_action('wp_ajax_fraud_save_settings', array( $this, 'handle_save_settings' ));
        add_action('wp_ajax_fraud_run_scan_now', array( $this, 'handle_run_scan_now' ));
        add_action('wp_ajax_fraud_ban_user', array( $this, 'handle_ban_user' ));
        add_action('wp_ajax_fraud_save_bot_settings', array( $this, 'handle_save_bot_settings' ));

        add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));
    }

    /**
     * Регистрация подпункта меню
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'cashback-overview',
            __('Защита', 'cashback-plugin'),
            $this->get_menu_title(),
            'manage_options',
            'cashback-antifraud',
            array( $this, 'render_page' )
        );
    }

    /**
     * Заголовок меню с бейджом количества открытых алертов
     */
    private function get_menu_title(): string {
        // Кеширование COUNT чтобы не запрашивать на каждой admin-странице
        // Используем transient вместо wp_cache — wp_cache без persistent cache не сохраняется между запросами
        $count = get_transient('cashback_fraud_open_count');
        if ($count === false) {
            global $wpdb;
            $table = $wpdb->prefix . 'cashback_fraud_alerts';
            $count = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE status = %s',
                $table,
                'open'
            ));
            set_transient('cashback_fraud_open_count', $count, 300);
        }

        $title = __('Защита', 'cashback-plugin');
        if ($count > 0) {
            $title .= sprintf(' <span class="awaiting-mod">%d</span>', (int) $count);
        }

        return $title;
    }

    /**
     * Подключение скриптов и стилей
     */
    public function enqueue_admin_scripts( string $hook ): void {
        $allowed_hooks = array(
            'cashback-overview_page_cashback-antifraud',
            'toplevel_page_cashback-antifraud',
            'admin_page_cashback-antifraud',
        );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin page detection, no state change.
        $is_page = in_array($hook, $allowed_hooks, true) ||
            ( isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-antifraud' );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (!$is_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-antifraud-css',
            plugins_url('../assets/css/admin.css', __FILE__),
            array(),
            '1.2.0'
        );

        // Дополнительные стили антифрод-UI: network badges, кластеры, warning, devices.
        wp_enqueue_style(
            'cashback-admin-antifraud-extra-css',
            plugins_url('../assets/css/admin-antifraud.css', __FILE__),
            array( 'cashback-admin-antifraud-css' ),
            '1.3.0'
        );

        wp_enqueue_script(
            'cashback-admin-antifraud',
            plugins_url('../assets/js/admin-antifraud.js', __FILE__),
            array( 'jquery' ),
            '1.3.0',
            true
        );

        wp_localize_script('cashback-admin-antifraud', 'cashbackFraudData', array(
            'reviewNonce'      => wp_create_nonce('fraud_review_alert_nonce'),
            'detailsNonce'     => wp_create_nonce('fraud_get_alert_details_nonce'),
            'settingsNonce'    => wp_create_nonce('fraud_save_settings_nonce'),
            'scanNonce'        => wp_create_nonce('fraud_run_scan_now_nonce'),
            'banNonce'         => wp_create_nonce('fraud_ban_user_nonce'),
            'botSettingsNonce' => wp_create_nonce('fraud_save_bot_settings_nonce'),
            'ajaxurl'          => admin_url('admin-ajax.php'),
        ));
    }

    /**
     * Главный рендер страницы с маршрутизацией по вкладкам
     */
    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab routing, allowlist-validated below.
        $active_tab   = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'alerts';
        $allowed_tabs = array( 'alerts', 'users', 'settings', 'spam' );
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = 'alerts';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Антифрод-защита', 'cashback-plugin') . '</h1>';

        // Tabs
        echo '<nav class="nav-tab-wrapper">';
        $tabs = array(
            'alerts'   => __('Уведомления', 'cashback-plugin'),
            'users'    => __('Подозрительные', 'cashback-plugin'),
            'spam'     => __('Спам-клики', 'cashback-plugin'),
            'settings' => __('Настройки', 'cashback-plugin'),
        );
        foreach ($tabs as $slug => $label) {
            $class = ( $slug === $active_tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url   = admin_url('admin.php?page=cashback-antifraud&tab=' . $slug);
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        echo '<div class="cashback-tab-content" style="margin-top:15px;">';

        switch ($active_tab) {
            case 'alerts':
                $this->render_alerts_tab();
                break;
            case 'users':
                $this->render_users_tab();
                break;
            case 'spam':
                $this->render_spam_tab();
                break;
            case 'settings':
                $this->render_settings_tab();
                break;
        }

        echo '</div>';
        echo '</div>';
    }

    // ===========================
    // Tab: Alerts
    // ===========================

    private function render_alerts_tab(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_fraud_alerts';

        // Filters
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin listing filters, allowlist-validated below; pagination via absint + cap.
        $filter_status   = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $filter_severity = isset($_GET['severity']) ? sanitize_text_field(wp_unslash($_GET['severity'])) : '';
        $filter_type     = isset($_GET['alert_type']) ? sanitize_text_field(wp_unslash($_GET['alert_type'])) : '';
        $filter_ip_type  = isset($_GET['ip_type']) ? sanitize_text_field(wp_unslash($_GET['ip_type'])) : '';
        $current_page    = min(isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1, self::MAX_ALLOWED_PAGES);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $offset = ( $current_page - 1 ) * self::PER_PAGE;

        $where  = array( '1=1' );
        $params = array();

        if ($filter_status && in_array($filter_status, array( 'open', 'reviewing', 'confirmed', 'dismissed' ), true)) {
            $where[]  = 'a.status = %s';
            $params[] = $filter_status;
        }
        if ($filter_severity && in_array($filter_severity, array( 'low', 'medium', 'high', 'critical' ), true)) {
            $where[]  = 'a.severity = %s';
            $params[] = $filter_severity;
        }
        $allowed_alert_types = array_keys(self::get_alert_type_labels());
        if ($filter_type && in_array($filter_type, $allowed_alert_types, true)) {
            $where[]  = 'a.alert_type = %s';
            $params[] = $filter_type;
        } else {
            $filter_type = '';
        }

        // Фильтр по типу сети (ip_type) — данные хранятся в JSON details.ip_type.
        // JSON_EXTRACT доступен в MySQL 5.7+ / MariaDB 10.2.3+; при отсутствии —
        // fallback к LIKE по сериализованному JSON-фрагменту.
        $allowed_ip_types = array( 'mobile', 'residential', 'hosting', 'vpn', 'tor', 'cgnat', 'private', 'unknown' );
        if ($filter_ip_type && in_array($filter_ip_type, $allowed_ip_types, true)) {
            if (self::supports_json_extract()) {
                $where[]  = "JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.ip_type')) = %s";
                $params[] = $filter_ip_type;
            } else {
                $where[]  = 'a.details LIKE %s';
                $params[] = '%"ip_type":"' . $wpdb->esc_like($filter_ip_type) . '"%';
            }
        } else {
            $filter_ip_type = '';
        }

        $where_sql = implode(' AND ', $where);

        // Count
        if (empty($params)) {
            $total_items = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i a WHERE %d = %d',
                $table,
                1,
                1
            ));
        } else {
            $count_params = array_merge( array( $table ), $params );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql из allowlist условий со %s (добавляет плейсхолдеры, которые phpcs не видит); таблица через %i, значения через prepare().
            $total_items = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i a WHERE {$where_sql}", ...$count_params ) );
        }

        $total_pages = (int) ceil($total_items / self::PER_PAGE);

        // Data
        $data_params = array_merge( array( $table, $wpdb->users ), $params, array( self::PER_PAGE, $offset ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql из allowlist условий со %s (добавляет плейсхолдеры, которые phpcs не видит); таблицы через %i, значения через prepare().
        $alerts = $wpdb->get_results( $wpdb->prepare( "SELECT a.*, u.user_login, u.user_email FROM %i a LEFT JOIN %i u ON a.user_id = u.ID WHERE {$where_sql} ORDER BY a.created_at DESC LIMIT %d OFFSET %d", ...$data_params ) );

        // Render filter form
        echo '<form method="get" class="cashback-filters" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="cashback-antifraud">';
        echo '<input type="hidden" name="tab" value="alerts">';

        echo '<select name="status">';
        echo '<option value="">' . esc_html__('Все статусы', 'cashback-plugin') . '</option>';
        foreach (array(
			'open'      => 'Открыт',
			'reviewing' => 'На проверке',
			'confirmed' => 'Подтверждён',
			'dismissed' => 'Отклонён',
		) as $val => $label) {
            echo '<option value="' . esc_attr($val) . '"' . selected($filter_status, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';

        echo '<select name="severity">';
        echo '<option value="">' . esc_html__('Все уровни', 'cashback-plugin') . '</option>';
        foreach (array(
			'critical' => 'Critical',
			'high'     => 'High',
			'medium'   => 'Medium',
			'low'      => 'Low',
		) as $val => $label) {
            echo '<option value="' . esc_attr($val) . '"' . selected($filter_severity, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';

        echo '<select name="alert_type">';
        echo '<option value="">' . esc_html__('Все типы', 'cashback-plugin') . '</option>';
        foreach (self::get_alert_type_labels() as $val => $label) {
            echo '<option value="' . esc_attr($val) . '"' . selected($filter_type, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';

        // Filter: тип сети (IP intelligence)
        echo '<select name="ip_type">';
        echo '<option value="">' . esc_html__('Все сети', 'cashback-plugin') . '</option>';
        foreach (self::get_ip_type_filter_options() as $val => $label) {
            echo '<option value="' . esc_attr($val) . '"' . selected($filter_ip_type, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';

        submit_button(__('Фильтровать', 'cashback-plugin'), 'secondary', '', false);
        echo ' <a href="' . esc_url(admin_url('admin.php?page=cashback-antifraud&tab=alerts')) . '" class="button">' . esc_html__('Сбросить', 'cashback-plugin') . '</a>';
        echo ' <button type="button" id="fraud-run-scan" class="button button-primary">' . esc_html__('Запустить проверку', 'cashback-plugin') . '</button>';
        echo '</form>';

        $last_run = get_option('cashback_fraud_last_run', '');
        if ($last_run) {
            /* translators: %s: last fraud check timestamp */
            echo '<p class="description">' . sprintf(esc_html__('Последняя проверка: %s', 'cashback-plugin'), esc_html($last_run)) . '</p>';
        }

        // Table
        if (empty($alerts)) {
            echo '<p>' . esc_html__('Алертов не найдено.', 'cashback-plugin') . '</p>';
            return;
        }

        echo '<table class="widefat striped cashback-table">';
        echo '<thead><tr>';
        echo '<th>#</th><th>' . esc_html__('Пользователь', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Тип', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Сеть', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Уровень', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Скор', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Описание', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Статус', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Дата', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Действия', 'cashback-plugin') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($alerts as $alert) {
            $type_labels    = self::get_alert_type_labels();
            $status_labels  = self::get_status_labels();
            $severity_class = 'cashback-severity-' . esc_attr($alert->severity);

            echo '<tr>';
            echo '<td>' . esc_html($alert->id) . '</td>';
            echo '<td>' . esc_html($alert->user_login ?? 'ID:' . $alert->user_id) . '<br><small>' . esc_html($alert->user_email ?? '') . '</small></td>';
            echo '<td>' . esc_html($type_labels[ $alert->alert_type ] ?? $alert->alert_type) . '</td>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_network_cell_for_alert() возвращает pre-escaped HTML (esc_html/esc_attr внутри render_ip_type_badge).
            echo '<td>' . self::render_network_cell_for_alert($alert) . '</td>';
            echo '<td><span class="' . esc_attr($severity_class) . '">' . esc_html(strtoupper($alert->severity)) . '</span></td>';
            echo '<td>' . esc_html(number_format((float) $alert->risk_score, 0)) . '</td>';
            echo '<td>' . esc_html(mb_strimwidth($alert->summary, 0, 80, '...')) . '</td>';
            echo '<td>' . esc_html($status_labels[ $alert->status ] ?? $alert->status) . '</td>';
            echo '<td>' . esc_html($alert->created_at) . '</td>';
            echo '<td class="cashback-actions">';

            echo '<button class="button button-small fraud-view-details" data-id="' . esc_attr($alert->id) . '">' . esc_html__('Детали', 'cashback-plugin') . '</button> ';

            if ($alert->status === 'open' || $alert->status === 'reviewing') {
                echo '<button class="button button-small fraud-review-btn" data-id="' . esc_attr($alert->id) . '" data-action="confirmed">' . esc_html__('Подтвердить', 'cashback-plugin') . '</button> ';
                echo '<button class="button button-small fraud-review-btn" data-id="' . esc_attr($alert->id) . '" data-action="dismissed">' . esc_html__('Отклонить', 'cashback-plugin') . '</button>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Pagination
        $add_args = array_filter(array(
            'status'     => $filter_status,
            'severity'   => $filter_severity,
            'alert_type' => $filter_type,
            'ip_type'    => $filter_ip_type,
            'tab'        => 'alerts',
        ));

        Cashback_Pagination::render(array(
            'total_items'  => $total_items,
            'per_page'     => self::PER_PAGE,
            'current_page' => $current_page,
            'total_pages'  => $total_pages,
            'page_slug'    => 'cashback-antifraud',
            'add_args'     => $add_args,
        ));

        // Modal placeholder
        echo '<div id="fraud-alert-modal" class="cashback-modal" style="display:none;">';
        echo '<div class="cashback-modal-content">';
        echo '<span class="cashback-modal-close">&times;</span>';
        echo '<div id="fraud-alert-modal-body"></div>';
        echo '</div>';
        echo '</div>';
    }

    // ===========================
    // Tab: Suspicious Users
    // ===========================

    private function render_users_tab(): void {
        global $wpdb;
        $alerts_table  = $wpdb->prefix . 'cashback_fraud_alerts';
        $profile_table = $wpdb->prefix . 'cashback_user_profile';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing pagination (absint + cap).
        $current_page = min(isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1, self::MAX_ALLOWED_PAGES);
        $offset       = ( $current_page - 1 ) * self::PER_PAGE;

        // Users with open/reviewing alerts, sorted by risk score
        $total_items = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(DISTINCT a.user_id)
             FROM %i a
             WHERE a.status IN (%s, %s)',
            $alerts_table,
            'open',
            'reviewing'
        ));
        $total_pages = (int) ceil($total_items / self::PER_PAGE);

        $users = $wpdb->get_results($wpdb->prepare(
            "SELECT a.user_id,
                    u.user_login, u.user_email,
                    LEAST(100, SUM(a.risk_score)) as total_risk_score,
                    COUNT(a.id) as open_alerts,
                    p.status as profile_status
             FROM %i a
             LEFT JOIN %i u ON a.user_id = u.ID
             LEFT JOIN %i p ON a.user_id = p.user_id
             WHERE a.status IN ('open', 'reviewing')
             GROUP BY a.user_id
             ORDER BY total_risk_score DESC
             LIMIT %d OFFSET %d",
            $alerts_table,
            $wpdb->users,
            $profile_table,
            self::PER_PAGE,
            $offset
        ));

        if (empty($users)) {
            echo '<p>' . esc_html__('Подозрительных пользователей не найдено.', 'cashback-plugin') . '</p>';
            return;
        }

        echo '<table class="widefat striped cashback-table">';
        echo '<thead><tr>';
        echo '<th>ID</th>';
        echo '<th>' . esc_html__('Логин', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Email', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Риск-скор', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Откр. алертов', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Статус', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Действия', 'cashback-plugin') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($users as $user) {
            $risk_class = '';
            $score      = (float) $user->total_risk_score;
            if ($score >= 76) {
                $risk_class = 'cashback-severity-critical';
            } elseif ($score >= 51) {
                $risk_class = 'cashback-severity-high';
            } elseif ($score >= 26) {
                $risk_class = 'cashback-severity-medium';
            } else {
                $risk_class = 'cashback-severity-low';
            }

            echo '<tr>';
            echo '<td>' . esc_html($user->user_id) . '</td>';
            echo '<td>' . esc_html($user->user_login ?? 'N/A') . '</td>';
            echo '<td>' . esc_html($user->user_email ?? 'N/A') . '</td>';
            echo '<td><span class="' . esc_attr($risk_class) . '">' . esc_html(number_format($score, 0)) . '</span></td>';
            echo '<td>' . esc_html($user->open_alerts) . '</td>';
            echo '<td>' . esc_html($user->profile_status ?? 'N/A') . '</td>';
            echo '<td>';

            $alerts_url = admin_url('admin.php?page=cashback-antifraud&tab=alerts&status=open');
            echo '<a href="' . esc_url($alerts_url) . '" class="button button-small">' . esc_html__('Алерты', 'cashback-plugin') . '</a> ';

            if ($user->profile_status !== 'banned') {
                echo '<button class="button button-small fraud-ban-user-btn" data-user-id="' . esc_attr($user->user_id) . '">' . esc_html__('Забанить', 'cashback-plugin') . '</button>';
            } else {
                echo '<em>' . esc_html__('Забанен', 'cashback-plugin') . '</em>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        Cashback_Pagination::render(array(
            'total_items'  => $total_items,
            'per_page'     => self::PER_PAGE,
            'current_page' => $current_page,
            'total_pages'  => $total_pages,
            'page_slug'    => 'cashback-antifraud',
            'add_args'     => array( 'tab' => 'users' ),
        ));
    }

    // ===========================
    // Tab: Spam Analytics
    // ===========================

    /**
     * Вкладка аналитики спам-кликов.
     *
     * Данные из WC_Affiliate_URL_Params::get_spam_stats() + детальная таблица.
     *
     * @since 4.3.0
     */
    private function render_spam_tab(): void {
        // Период: 1ч, 6ч, 24ч, 7д
        $allowed_hours = array( 1, 6, 24, 168 );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter, allowlist-validated below.
        $hours = isset($_GET['hours']) ? absint($_GET['hours']) : 24;
        if (!in_array($hours, $allowed_hours, true)) {
            $hours = 24;
        }

        $stats = class_exists('WC_Affiliate_URL_Params')
            ? WC_Affiliate_URL_Params::get_spam_stats($hours)
            : array(
				'total_clicks' => 0,
				'total_spam'   => 0,
				'spam_rate'    => 0,
				'top_ips'      => array(),
				'top_products' => array(),
			);

        // Период
        $period_labels = array(
            1   => __('1 час', 'cashback-plugin'),
            6   => __('6 часов', 'cashback-plugin'),
            24  => __('24 часа', 'cashback-plugin'),
            168 => __('7 дней', 'cashback-plugin'),
        );

        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="cashback-antifraud">';
        echo '<input type="hidden" name="tab" value="spam">';
        echo '<label for="hours"><strong>' . esc_html__('Период:', 'cashback-plugin') . '</strong> </label>';
        echo '<select name="hours" id="hours" onchange="this.form.submit()">';
        foreach ($period_labels as $val => $label) {
            echo '<option value="' . esc_attr((string) $val) . '"' . selected($hours, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</form>';

        // Summary cards
        $total = (int) $stats['total_clicks'];
        $spam  = (int) $stats['total_spam'];
        $rate  = (float) $stats['spam_rate'];

        $rate_class = 'cashback-severity-low';
        if ($rate >= 50) {
            $rate_class = 'cashback-severity-critical';
        } elseif ($rate >= 25) {
            $rate_class = 'cashback-severity-high';
        } elseif ($rate >= 10) {
            $rate_class = 'cashback-severity-medium';
        }

        echo '<div style="display:flex; gap:20px; margin-bottom:20px; flex-wrap:wrap;">';

        echo '<div style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #2271b1; padding:15px 20px; min-width:180px;">';
        echo '<div style="font-size:28px; font-weight:600; line-height:1.2;">' . esc_html(number_format($total)) . '</div>';
        echo '<div style="color:#646970; margin-top:4px;">' . esc_html__('Всего кликов', 'cashback-plugin') . '</div>';
        echo '</div>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inline color literal selected by ternary, no user input.
        echo '<div style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid ' . ( $spam > 0 ? '#d63638' : '#00a32a' ) . '; padding:15px 20px; min-width:180px;">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inline color literal selected by ternary, no user input.
        echo '<div style="font-size:28px; font-weight:600; line-height:1.2; color:' . ( $spam > 0 ? '#d63638' : '#1d2327' ) . ';">' . esc_html(number_format($spam)) . '</div>';
        echo '<div style="color:#646970; margin-top:4px;">' . esc_html__('Спам-кликов', 'cashback-plugin') . '</div>';
        echo '</div>';

        echo '<div style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #dba617; padding:15px 20px; min-width:180px;">';
        echo '<div style="font-size:28px; font-weight:600; line-height:1.2;">';
        echo '<span class="' . esc_attr($rate_class) . '" style="font-size:28px; padding:2px 10px;">' . esc_html(number_format($rate, 1)) . '%</span>';
        echo '</div>';
        echo '<div style="color:#646970; margin-top:4px;">' . esc_html__('Доля спама', 'cashback-plugin') . '</div>';
        echo '</div>';

        echo '</div>';

        // Top IPs and Top Products side by side
        echo '<div style="display:flex; gap:20px; margin-bottom:20px; flex-wrap:wrap;">';

        // --- Top IPs ---
        echo '<div style="flex:1; min-width:400px;">';
        echo '<h3>' . esc_html__('Топ IP по спам-кликам', 'cashback-plugin') . '</h3>';

        if (empty($stats['top_ips'])) {
            echo '<p style="color:#646970;">' . esc_html__('Спам-кликов не обнаружено за выбранный период.', 'cashback-plugin') . '</p>';
        } else {
            echo '<table class="widefat striped cashback-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('IP-адрес', 'cashback-plugin') . '</th>';
            echo '<th>' . esc_html__('Всего', 'cashback-plugin') . '</th>';
            echo '<th>' . esc_html__('Спам', 'cashback-plugin') . '</th>';
            echo '<th>' . esc_html__('Доля', 'cashback-plugin') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($stats['top_ips'] as $row) {
                $ip_total = (int) $row['total'];
                $ip_spam  = (int) $row['spam_count'];
                $ip_rate  = $ip_total > 0 ? round($ip_spam / $ip_total * 100, 1) : 0;

                $row_class = '';
                if ($ip_rate >= 80) {
                    $row_class = 'cashback-severity-critical';
                } elseif ($ip_rate >= 50) {
                    $row_class = 'cashback-severity-high';
                }

                echo '<tr>';
                echo '<td><code>' . esc_html($row['ip_address']) . '</code></td>';
                echo '<td>' . esc_html(number_format($ip_total)) . '</td>';
                echo '<td>' . esc_html(number_format($ip_spam)) . '</td>';
                echo '<td>';
                if ($row_class) {
                    echo '<span class="' . esc_attr($row_class) . '">' . esc_html((string) $ip_rate) . '%</span>';
                } else {
                    echo esc_html((string) $ip_rate) . '%';
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
        echo '</div>';

        // --- Top Products ---
        echo '<div style="flex:1; min-width:400px;">';
        echo '<h3>' . esc_html__('Топ товаров по спам-кликам', 'cashback-plugin') . '</h3>';

        if (empty($stats['top_products'])) {
            echo '<p style="color:#646970;">' . esc_html__('Спам-кликов не обнаружено за выбранный период.', 'cashback-plugin') . '</p>';
        } else {
            echo '<table class="widefat striped cashback-table">';
            echo '<thead><tr>';
            echo '<th>ID</th>';
            echo '<th>' . esc_html__('Товар', 'cashback-plugin') . '</th>';
            echo '<th>' . esc_html__('Всего', 'cashback-plugin') . '</th>';
            echo '<th>' . esc_html__('Спам', 'cashback-plugin') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($stats['top_products'] as $row) {
                $pid   = (int) $row['product_id'];
                $title = get_the_title($pid);
                if (empty($title)) {
                    $title = '#' . $pid;
                }

                echo '<tr>';
                echo '<td>' . esc_html((string) $pid) . '</td>';
                echo '<td>';
                $edit_link = get_edit_post_link($pid);
                if ($edit_link) {
                    echo '<a href="' . esc_url($edit_link) . '" target="_blank">' . esc_html(mb_strimwidth($title, 0, 50, '...')) . '</a>';
                } else {
                    echo esc_html(mb_strimwidth($title, 0, 50, '...'));
                }
                echo '</td>';
                echo '<td>' . esc_html(number_format((int) $row['total'])) . '</td>';
                echo '<td>' . esc_html(number_format((int) $row['spam_count'])) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
        echo '</div>';

        echo '</div>'; // flex container

        // --- Recent spam clicks ---
        echo '<h3>' . esc_html__('Последние спам-клики', 'cashback-plugin') . '</h3>';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing; pagination via absint + cap.
        $recent_page  = min(isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1, self::MAX_ALLOWED_PAGES);
        $recent_total = $this->count_recent_spam_clicks();
        $recent_pages = (int) ceil($recent_total / self::PER_PAGE);
        if ($recent_pages > 0 && $recent_page > $recent_pages) {
            $recent_page = $recent_pages;
        }
        $recent_offset = ( $recent_page - 1 ) * self::PER_PAGE;
        $recent        = $recent_total > 0 ? $this->get_recent_spam_clicks(self::PER_PAGE, $recent_offset) : array();

        if (empty($recent)) {
            echo '<p style="color:#646970;">' . esc_html__('Спам-кликов пока не было.', 'cashback-plugin') . '</p>';
        } else {
            echo '<table class="widefat striped cashback-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Дата (UTC)', 'cashback-plugin') . '</th>';
            echo '<th>' . esc_html__('IP', 'cashback-plugin') . '</th>';
            echo '<th>' . esc_html__('Товар', 'cashback-plugin') . '</th>';
            echo '<th>' . esc_html__('User-Agent', 'cashback-plugin') . '</th>';
            echo '<th>' . esc_html__('User ID', 'cashback-plugin') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($recent as $click) {
                $click_pid   = (int) $click->product_id;
                $click_title = get_the_title($click_pid);
                if (empty($click_title)) {
                    $click_title = '#' . $click_pid;
                }

                $ua_short = $click->user_agent
                    ? mb_strimwidth($click->user_agent, 0, 60, '...')
                    : '—';

                echo '<tr>';
                echo '<td style="white-space:nowrap;">' . esc_html($click->created_at) . '</td>';
                echo '<td><code>' . esc_html($click->ip_address) . '</code></td>';
                echo '<td>' . esc_html(mb_strimwidth($click_title, 0, 40, '...')) . '</td>';
                echo '<td><small title="' . esc_attr($click->user_agent ?? '') . '">' . esc_html($ua_short) . '</small></td>';
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Branches are esc_html() output or static '<em>guest</em>' literal.
                echo '<td>' . ( $click->user_id > 0 ? esc_html($click->user_id) : '<em>guest</em>' ) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            Cashback_Pagination::render(array(
                'total_items'  => $recent_total,
                'per_page'     => self::PER_PAGE,
                'current_page' => $recent_page,
                'total_pages'  => $recent_pages,
                'page_slug'    => 'cashback-antifraud',
                'add_args'     => array(
                    'tab'   => 'spam',
                    'hours' => $hours,
                ),
            ));
        }
    }

    /**
     * Последние N спам-кликов из cashback_click_log.
     *
     * @since 4.3.0
     *
     * @param int $limit  Количество записей.
     * @param int $offset Смещение для пагинации.
     *
     * @return array|object[] Массив объектов.
     */
    private function get_recent_spam_clicks( int $limit = 50, int $offset = 0 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_click_log';

        // Проверяем существование таблицы
        $table_exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));

        if (!$table_exists) {
            return array();
        }

        return $wpdb->get_results($wpdb->prepare(
            'SELECT ip_address, product_id, user_agent, user_id, created_at
             FROM %i
             WHERE spam_click = 1
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d',
            $table,
            $limit,
            $offset
        )) ?: array();
    }

    /**
     * Общее число спам-кликов в cashback_click_log (для пагинации).
     *
     * @since 5.1.0
     *
     * @return int
     */
    private function count_recent_spam_clicks(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_click_log';

        $table_exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));

        if (!$table_exists) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE spam_click = 1',
            $table
        ));
    }

    // ===========================
    // Tab: Settings
    // ===========================

    private function render_settings_tab(): void {
        $settings = Cashback_Fraud_Settings::get_all();
        $defaults = Cashback_Fraud_Settings::get_defaults();

        echo '<form id="fraud-settings-form">';

        // General
        echo '<h2>' . esc_html__('Общие', 'cashback-plugin') . '</h2>';
        echo '<table class="form-table">';
        $this->render_checkbox_field('enabled', __('Модуль включён', 'cashback-plugin'), $settings);
        $this->render_checkbox_field('email_notification_enabled', __('Email-уведомления', 'cashback-plugin'), $settings);
        echo '</table>';

        // Multi-Account Detection
        echo '<h2>' . esc_html__('Детекция мультиаккаунтов', 'cashback-plugin') . '</h2>';
        echo '<table class="form-table">';
        $this->render_checkbox_field('shared_ip_check_enabled', __('Проверять shared-IP (легаси-сигнал)', 'cashback-plugin'), $settings);
        $this->render_number_field('max_users_per_ip', __('Макс. пользователей с одного IP', 'cashback-plugin'), $settings, $defaults);
        $this->render_number_field('max_users_per_fingerprint', __('Макс. пользователей с одного fingerprint', 'cashback-plugin'), $settings, $defaults);
        $this->render_number_field('max_accounts_per_details_hash', __('Макс. аккаунтов на одни реквизиты', 'cashback-plugin'), $settings, $defaults);
        echo '</table>';

        // Withdrawal Limits
        echo '<h2>' . esc_html__('Лимиты вывода', 'cashback-plugin') . '</h2>';
        echo '<table class="form-table">';
        $this->render_number_field('max_withdrawals_per_day', __('Макс. заявок на вывод / день', 'cashback-plugin'), $settings, $defaults);
        $this->render_number_field('max_withdrawals_per_week', __('Макс. заявок на вывод / неделя', 'cashback-plugin'), $settings, $defaults);
        $this->render_number_field('new_account_cooling_days', __('Дней до первого вывода (0 = выкл)', 'cashback-plugin'), $settings, $defaults);
        $this->render_number_field('auto_hold_amount', __('Авто-холд для сумм выше (0 = выкл)', 'cashback-plugin'), $settings, $defaults, 'step="0.01"');
        echo '</table>';

        // Transaction Analysis
        echo '<h2>' . esc_html__('Анализ транзакций', 'cashback-plugin') . '</h2>';
        echo '<table class="form-table">';
        $this->render_number_field('cancellation_rate_threshold', __('Порог % отклонений', 'cashback-plugin'), $settings, $defaults, 'step="0.1" min="0" max="100"');
        $this->render_number_field('cancellation_min_transactions', __('Мин. кол-во транзакций для проверки', 'cashback-plugin'), $settings, $defaults);
        $this->render_number_field('amount_anomaly_multiplier', __('Множитель аномальной суммы', 'cashback-plugin'), $settings, $defaults, 'step="0.1"');
        echo '</table>';

        // Risk & Retention
        echo '<h2>' . esc_html__('Риск-скоринг и хранение', 'cashback-plugin') . '</h2>';
        echo '<table class="form-table">';
        $this->render_number_field('auto_flag_threshold', __('Порог риск-скора для автофлага (0-100)', 'cashback-plugin'), $settings, $defaults, 'step="1" min="0" max="100"');
        $this->render_number_field('fingerprint_retention_days', __('Хранение fingerprints (дней)', 'cashback-plugin'), $settings, $defaults);
        echo '</table>';

        // New-generation antifraud subsystems (toggles + thresholds)
        echo '<h2>' . esc_html__('Подсистемы антифрод-нового-поколения', 'cashback-plugin') . '</h2>';
        echo '<p class="description">' . esc_html__('Тумблеры независимых подсистем. Безопасные дефолты — все включены. Документация: obsidian/knowledge/patterns/антифрод тумблеры подсистем.md', 'cashback-plugin') . '</p>';
        echo '<table class="form-table">';
        $this->render_checkbox_field('ip_intelligence_enabled', __('IP Intelligence (MaxMind ASN classify, CGNAT/mobile/hosting/VPN)', 'cashback-plugin'), $settings);
        $this->render_checkbox_field('skip_alert_for_mobile_ip', __('Пропускать alert для mobile/CGNAT IP', 'cashback-plugin'), $settings);
        $this->render_checkbox_field('device_id_enabled', __('Persistent device_id и композитные сигналы', 'cashback-plugin'), $settings);
        $this->render_number_field('max_users_per_device', __('Макс. пользователей с одного device', 'cashback-plugin'), $settings, $defaults);
        $this->render_number_field('max_ips_per_device_24h', __('Макс. IP с одного device за 24 часа', 'cashback-plugin'), $settings, $defaults);
        $this->render_checkbox_field('cluster_detection_enabled', __('Cluster detection cron (граф связанных аккаунтов)', 'cashback-plugin'), $settings);
        $this->render_checkbox_field('consent_required', __('152-ФЗ: требовать согласие на обработку технических данных при регистрации', 'cashback-plugin'), $settings);
        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="submit" id="fraud-save-settings" class="button button-primary">' . esc_html__('Сохранить настройки', 'cashback-plugin') . '</button>';
        echo '</p>';

        echo '</form>';
        echo '<div id="fraud-settings-message" style="display:none;"></div>';

        // --- Секция бот-защиты ---
        $this->render_bot_protection_settings();
    }

    /**
     * Рендер секции настроек бот-защиты и CAPTCHA
     */
    private function render_bot_protection_settings(): void {
        $bot_settings = Cashback_Fraud_Settings::get_all_bot_settings();

        echo '<hr style="margin: 32px 0;">';
        echo '<h1>' . esc_html__('Защита от ботов', 'cashback-plugin') . '</h1>';
        echo '<p class="description">' . esc_html__('Централизованная защита от ботов и брутфорса на всех AJAX-эндпоинтах. Для серых IP показывается Яндекс SmartCaptcha.', 'cashback-plugin') . '</p>';

        echo '<form id="bot-protection-settings-form">';

        // General
        echo '<h2>' . esc_html__('Общие', 'cashback-plugin') . '</h2>';
        echo '<table class="form-table">';
        $this->render_bot_checkbox_field('bot_protection_enabled', __('Бот-защита включена', 'cashback-plugin'), $bot_settings);
        echo '</table>';

        // SmartCaptcha
        echo '<h2>' . esc_html__('Яндекс SmartCaptcha', 'cashback-plugin') . '</h2>';
        echo '<p class="description">' . esc_html__('Ключи можно получить в Яндекс Cloud Console → SmartCaptcha. CAPTCHA показывается только для подозрительных (серых) IP.', 'cashback-plugin') . '</p>';
        echo '<table class="form-table">';

        // Client Key
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Ключ клиента (Client Key)', 'cashback-plugin') . '</th>';
        echo '<td><input type="text" name="captcha_client_key" value="' . esc_attr($bot_settings['captcha_client_key'] ?? '') . '" class="regular-text" autocomplete="off"></td>';
        echo '</tr>';

        // Server Key
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Ключ сервера (Server Key)', 'cashback-plugin') . '</th>';
        echo '<td><input type="password" name="captcha_server_key" value="' . esc_attr($bot_settings['captcha_server_key'] ?? '') . '" class="regular-text" autocomplete="off"></td>';
        echo '</tr>';

        echo '</table>';

        // Thresholds
        echo '<h2>' . esc_html__('Пороги серого/заблокированного IP', 'cashback-plugin') . '</h2>';
        echo '<p class="description">' . esc_html__('Grey score IP копится при нарушениях (rate limit +10, бот UA +30, honeypot +40, быстрая отправка +15). Сбрасывается через 1 час.', 'cashback-plugin') . '</p>';
        echo '<table class="form-table">';

        // Grey threshold
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Порог серого IP (CAPTCHA)', 'cashback-plugin') . '</th>';
        echo '<td><input type="number" name="bot_grey_threshold" value="' . esc_attr($bot_settings['bot_grey_threshold'] ?? '20') . '" class="small-text" min="1" max="100">';
        echo ' <span class="description">' . esc_html__('По умолчанию: 20', 'cashback-plugin') . '</span></td>';
        echo '</tr>';

        // Block threshold
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Порог блокировки IP', 'cashback-plugin') . '</th>';
        echo '<td><input type="number" name="bot_block_threshold" value="' . esc_attr($bot_settings['bot_block_threshold'] ?? '80') . '" class="small-text" min="1" max="100">';
        echo ' <span class="description">' . esc_html__('По умолчанию: 80', 'cashback-plugin') . '</span></td>';
        echo '</tr>';

        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="submit" id="bot-protection-save-settings" class="button button-primary">' . esc_html__('Сохранить настройки бот-защиты', 'cashback-plugin') . '</button>';
        echo '</p>';

        echo '</form>';
        echo '<div id="bot-protection-settings-message" style="display:none;"></div>';
    }

    /**
     * Рендер checkbox поля для бот-защиты
     */
    private function render_bot_checkbox_field( string $key, string $label, array $settings ): void {
        $checked = !empty($settings[ $key ]) ? 'checked' : '';
        echo '<tr>';
        echo '<th scope="row">' . esc_html($label) . '</th>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $checked is a literal 'checked' or '' string.
        echo '<td><label><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . $checked . '> ' . esc_html__('Да', 'cashback-plugin') . '</label></td>';
        echo '</tr>';
    }

    /**
     * Рендер checkbox поля для формы настроек
     */
    private function render_checkbox_field( string $key, string $label, array $settings ): void {
        $checked = !empty($settings[ $key ]) ? 'checked' : '';
        echo '<tr>';
        echo '<th scope="row">' . esc_html($label) . '</th>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $checked is a literal 'checked' or '' string.
        echo '<td><label><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . $checked . '> ' . esc_html__('Да', 'cashback-plugin') . '</label></td>';
        echo '</tr>';
    }

    /**
     * Рендер числового поля для формы настроек
     */
    private function render_number_field( string $key, string $label, array $settings, array $defaults, string $extra_attrs = '' ): void {
        $value   = $settings[ $key ] ?? '';
        $default = $defaults[ $key ] ?? '';

        // Экранируем extra_attrs: парсим key="value" пары и пересобираем безопасно
        $safe_attrs = '';
        if (!empty($extra_attrs)) {
            $allowed_attrs = array( 'step', 'min', 'max', 'placeholder' );
            if (preg_match_all('/(\w+)=["\']([^"\']*)["\']/', $extra_attrs, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (in_array($match[1], $allowed_attrs, true)) {
                        $safe_attrs .= ' ' . esc_attr($match[1]) . '="' . esc_attr($match[2]) . '"';
                    }
                }
            }
        }

        echo '<tr>';
        echo '<th scope="row">' . esc_html($label) . '</th>';
        echo '<td>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $safe_attrs is rebuilt above from allowlisted keys via esc_attr().
        echo '<input type="number" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" class="small-text"' . $safe_attrs . '>';
        /* translators: %s: default field value */
        echo ' <span class="description">' . sprintf(esc_html__('По умолчанию: %s', 'cashback-plugin'), esc_html((string) $default)) . '</span>';
        echo '</td>';
        echo '</tr>';
    }

    // ===========================
    // AJAX handlers
    // ===========================

    /**
     * Подтверждение/отклонение алерта
     */
    public function handle_review_alert(): void {
        if (!check_ajax_referer('fraud_review_alert_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Invalid nonce' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
            return;
        }

        $alert_id    = isset($_POST['alert_id']) ? absint($_POST['alert_id']) : 0;
        $new_status  = isset($_POST['new_status']) ? sanitize_text_field(wp_unslash($_POST['new_status'])) : '';
        $review_note = isset($_POST['review_note']) ? sanitize_textarea_field(wp_unslash($_POST['review_note'])) : '';

        if (!$alert_id || !in_array($new_status, array( 'reviewing', 'confirmed', 'dismissed' ), true)) {
            wp_send_json_error(array( 'message' => 'Невалидные параметры' ));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_fraud_alerts';

        $alert = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            $table,
            $alert_id
        ));

        if (!$alert) {
            wp_send_json_error(array( 'message' => 'Алерт не найден' ));
            return;
        }

        if (in_array($alert->status, array( 'confirmed', 'dismissed' ), true)) {
            wp_send_json_error(array( 'message' => 'Алерт уже обработан' ));
            return;
        }

        $wpdb->update(
            $table,
            array(
                'status'      => $new_status,
                'reviewed_by' => get_current_user_id(),
                'reviewed_at' => current_time('mysql'),
                'review_note' => $review_note,
            ),
            array( 'id' => $alert_id ),
            array( '%s', '%d', '%s', '%s' ),
            array( '%d' )
        );

        // Audit log
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'fraud_alert_reviewed',
                get_current_user_id(),
                'fraud_alert',
                $alert_id,
                array(
					'new_status' => $new_status,
					'user_id'    => $alert->user_id,
				)
            );
        }

        wp_send_json_success(array(
			'message'    => 'Алерт обновлён',
			'new_status' => $new_status,
		));
    }

    /**
     * Получение деталей алерта с сигналами
     */
    public function handle_get_alert_details(): void {
        if (!check_ajax_referer('fraud_get_alert_details_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Invalid nonce' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
            return;
        }

        $alert_id = isset($_POST['alert_id']) ? absint($_POST['alert_id']) : 0;
        if (!$alert_id) {
            wp_send_json_error(array( 'message' => 'Нет alert_id' ));
            return;
        }

        global $wpdb;
        $alerts_table  = $wpdb->prefix . 'cashback_fraud_alerts';
        $signals_table = $wpdb->prefix . 'cashback_fraud_signals';

        $alert = $wpdb->get_row($wpdb->prepare(
            'SELECT a.*, u.user_login, u.user_email, u.user_registered
             FROM %i a
             LEFT JOIN %i u ON a.user_id = u.ID
             WHERE a.id = %d',
            $alerts_table,
            $wpdb->users,
            $alert_id
        ), ARRAY_A);

        if (!$alert) {
            wp_send_json_error(array( 'message' => 'Алерт не найден' ));
            return;
        }

        $signals = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE alert_id = %d ORDER BY weight DESC',
            $signals_table,
            $alert_id
        ), ARRAY_A);

        // Balance info
        $balance_table = $wpdb->prefix . 'cashback_user_balance';
        $balance       = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE user_id = %d',
            $balance_table,
            $alert['user_id']
        ), ARRAY_A);

        $alert['details'] = json_decode($alert['details'] ?? '{}', true);
        if (!is_array($alert['details'])) {
            $alert['details'] = array();
        }
        foreach ($signals as &$s) {
            $s['evidence'] = json_decode($s['evidence'] ?? '{}', true);
        }
        unset($s);

        // Network info: подтягиваем из details, либо доклассифицируем через IP Intelligence.
        $network = self::build_network_info_for_alert($alert);

        // Devices: последние 5 device_id юзера (с graceful class_exists).
        $devices = array();
        if (class_exists('Cashback_Fraud_Device_Id') && (int) $alert['user_id'] > 0) {
            $devices = Cashback_Fraud_Device_Id::get_user_devices((int) $alert['user_id'], 5);
        }

        // Cluster: связанные аккаунты (graceful — модуль может отсутствовать).
        $cluster = null;
        if (class_exists('Cashback_Fraud_Cluster_Detector') && (int) $alert['user_id'] > 0) {
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- false positive: это вызов метода, не комментарий.
            $raw_clusters = Cashback_Fraud_Cluster_Detector::find_clusters_for_user((int) $alert['user_id']);
            $cluster      = array();
            foreach ((array) $raw_clusters as $c) {
                $c = (array) $c;
                // user_ids / link_reasons могут прийти JSON-строкой — нормализуем.
                if (isset($c['user_ids']) && is_string($c['user_ids'])) {
                    $decoded       = json_decode($c['user_ids'], true);
                    $c['user_ids'] = is_array($decoded) ? $decoded : array();
                }
                if (isset($c['link_reasons']) && is_string($c['link_reasons'])) {
                    $decoded           = json_decode($c['link_reasons'], true);
                    $c['link_reasons'] = is_array($decoded) ? $decoded : array();
                }
                $cluster[] = $c;
            }
        }

        // Attach extras to alert object (JS читает alert.network / alert.devices / alert.cluster).
        $alert['network'] = $network;
        $alert['devices'] = $devices;
        $alert['cluster'] = $cluster;

        wp_send_json_success(array(
            'alert'   => $alert,
            'signals' => $signals,
            'balance' => $balance,
        ));
    }

    /**
     * Сохранение настроек
     */
    public function handle_save_settings(): void {
        if (!check_ajax_referer('fraud_save_settings_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Invalid nonce' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
            return;
        }

        $settings = isset($_POST['settings']) ? (array) map_deep(wp_unslash($_POST['settings']), 'sanitize_text_field') : array();

        // Sanitize each value
        $sanitized = array();
        foreach ($settings as $key => $value) {
            $sanitized[ sanitize_text_field($key) ] = sanitize_text_field((string) $value);
        }

        Cashback_Fraud_Settings::save_settings($sanitized);

        // Audit log
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'fraud_settings_updated',
                get_current_user_id(),
                'system',
                null,
                array( 'changed_keys' => array_keys($sanitized) )
            );
        }

        wp_send_json_success(array( 'message' => 'Настройки сохранены' ));
    }

    /**
     * Сохранение настроек бот-защиты
     */
    public function handle_save_bot_settings(): void {
        if (!check_ajax_referer('fraud_save_bot_settings_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Invalid nonce' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
            return;
        }

        $settings = isset($_POST['settings']) ? (array) map_deep(wp_unslash($_POST['settings']), 'sanitize_text_field') : array();

        $sanitized = array();
        foreach ($settings as $key => $value) {
            $sanitized[ sanitize_text_field($key) ] = sanitize_text_field((string) $value);
        }

        Cashback_Fraud_Settings::save_bot_settings($sanitized);

        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'bot_protection_settings_updated',
                get_current_user_id(),
                'system',
                null,
                array( 'changed_keys' => array_keys($sanitized) )
            );
        }

        wp_send_json_success(array( 'message' => 'Настройки бот-защиты сохранены' ));
    }

    /**
     * Ручной запуск сканирования
     */
    public function handle_run_scan_now(): void {
        if (!check_ajax_referer('fraud_run_scan_now_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Invalid nonce' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
            return;
        }

        // Rate limiting: максимум 2 сканирования за 10 минут
        $rate_key   = 'cb_fraud_scan_rate_' . get_current_user_id();
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 2) {
            wp_send_json_error(array( 'message' => 'Сканирование уже запускалось недавно. Подождите 10 минут.' ));
            return;
        }
        set_transient($rate_key, $rate_count + 1, 10 * MINUTE_IN_SECONDS);

        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'fraud_scan_manual',
                get_current_user_id(),
                'system',
                null,
                array()
            );
        }

        Cashback_Fraud_Detector::run_all_checks();

        wp_send_json_success(array(
			'message'  => 'Проверка завершена',
			'last_run' => current_time('mysql'),
		));
    }

    /**
     * Бан пользователя через антифрод-модуль
     * Переиспользует логику бана из users-management.php
     */
    public function handle_ban_user(): void {
        if (!check_ajax_referer('fraud_ban_user_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Invalid nonce' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
            return;
        }

        $user_id    = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $ban_reason = isset($_POST['ban_reason']) ? sanitize_text_field(wp_unslash($_POST['ban_reason'])) : '';

        if (!$user_id) {
            wp_send_json_error(array( 'message' => 'Нет user_id' ));
            return;
        }

        if (empty($ban_reason)) {
            $ban_reason = 'Заблокирован антифрод-системой';
        }

        global $wpdb;
        $profile_table  = $wpdb->prefix . 'cashback_user_profile';
        $requests_table = $wpdb->prefix . 'cashback_payout_requests';

        $wpdb->query('START TRANSACTION');

        try {
            // Lock and update profile
            $profile = $wpdb->get_row($wpdb->prepare(
                'SELECT status FROM %i WHERE user_id = %d FOR UPDATE',
                $profile_table,
                $user_id
            ));

            if (!$profile) {
                throw new \Exception('Профиль не найден');
            }

            if ($profile->status === 'banned') {
                throw new \Exception('Пользователь уже забанен');
            }

            $wpdb->update(
                $profile_table,
                array(
                    'status'     => 'banned',
                    'ban_reason' => $ban_reason,
                    'banned_at'  => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ),
                array( 'user_id' => $user_id ),
                array( '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );

            // PHP-фолбэк: заморозка баланса (идемпотентно при наличии триггера)
            Cashback_Trigger_Fallbacks::freeze_balance_on_ban($user_id);

            // Decline active payout requests
            $active_requests = $wpdb->get_results($wpdb->prepare(
                "SELECT id, total_amount FROM %i
                 WHERE user_id = %d AND status NOT IN ('failed', 'paid', 'declined')
                 FOR UPDATE",
                $requests_table,
                $user_id
            ));

            foreach ($active_requests as $request) {
                $wpdb->update(
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

                if (class_exists('Cashback_Encryption')) {
                    Cashback_Encryption::write_audit_log(
                        'payout_declined_on_ban',
                        get_current_user_id(),
                        'payout_request',
                        (int) $request->id,
                        array(
							'amount'  => $request->total_amount,
							'user_id' => $user_id,
						)
                    );
                }
            }

            // Confirm all open alerts for this user
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$wpdb->prefix}cashback_fraud_alerts`
                 SET status = 'confirmed',
                     reviewed_by = %d,
                     reviewed_at = %s,
                     review_note = %s
                 WHERE user_id = %d AND status IN ('open', 'reviewing')",
                get_current_user_id(),
                current_time('mysql'),
                'Автоматически подтверждён при бане',
                $user_id
            ));

            // Audit
            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'fraud_user_banned',
                    get_current_user_id(),
                    'user_profile',
                    $user_id,
                    array( 'ban_reason' => $ban_reason )
                );
            }

            $wpdb->query('COMMIT');

            // Email notification (non-critical delivery, after commit — но содержимое security)
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

            wp_send_json_success(array( 'message' => 'Пользователь забанен' ));

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => $e->getMessage() ));
        }
    }

    // ===========================
    // Helpers
    // ===========================

    /**
     * Рендер цветного бейджа типа сети.
     *
     * @param string      $type  Тип IP (mobile/residential/hosting/vpn/tor/cgnat/private/unknown/device).
     * @param string|null $label Опциональный текст; если null — берётся из карты.
     */
    private static function render_ip_type_badge( string $type, ?string $label = null ): string {
        $map = array(
            'mobile'      => array(
                'class' => 'mobile',
                'text'  => __('Mobile', 'cashback-plugin'),
            ),
            'residential' => array(
                'class' => 'residential',
                'text'  => __('Home', 'cashback-plugin'),
            ),
            'hosting'     => array(
                'class' => 'hosting',
                'text'  => __('Datacenter', 'cashback-plugin'),
            ),
            'vpn'         => array(
                'class' => 'vpn',
                'text'  => __('VPN', 'cashback-plugin'),
            ),
            'tor'         => array(
                'class' => 'tor',
                'text'  => __('Tor', 'cashback-plugin'),
            ),
            'cgnat'       => array(
                'class' => 'cgnat',
                'text'  => __('CGNAT', 'cashback-plugin'),
            ),
            'private'     => array(
                'class' => 'private',
                'text'  => __('Private', 'cashback-plugin'),
            ),
            'device'      => array(
                'class' => 'device',
                'text'  => __('Device', 'cashback-plugin'),
            ),
            'unknown'     => array(
                'class' => 'unknown',
                'text'  => __('Unknown', 'cashback-plugin'),
            ),
        );

        $info = $map[ $type ] ?? $map['unknown'];
        $text = ( $label !== null && $label !== '' ) ? $label : $info['text'];

        return sprintf(
            '<span class="cashback-fraud-network-badge cashback-fraud-network-badge--%s">%s</span>',
            esc_attr($info['class']),
            esc_html($text)
        );
    }

    /**
     * Рендер ячейки «Сеть» в строке таблицы алертов.
     *
     * Для legacy-алертов без ip_type в JSON details — на лету классифицирует ip_address
     * через Cashback_Fraud_Ip_Intelligence (если класс доступен).
     * Для алертов device-типов (shared_device_id, device_multiple_ips) — нейтральный бейдж DEVICE.
     */
    private static function render_network_cell_for_alert( object $alert ): string {
        // device-related алерты — IP может отсутствовать.
        if (in_array($alert->alert_type, array( 'shared_device_id', 'device_multiple_ips' ), true)) {
            return self::render_ip_type_badge('device');
        }

        $details = array();
        if (!empty($alert->details)) {
            $decoded = json_decode((string) $alert->details, true);
            if (is_array($decoded)) {
                $details = $decoded;
            }
        }

        // 1. Берём из details (Этап 3 пишет ip_type/ip_label).
        if (!empty($details['ip_type']) && is_string($details['ip_type'])) {
            $label = isset($details['ip_label']) && is_string($details['ip_label']) ? $details['ip_label'] : null;
            return self::render_ip_type_badge($details['ip_type'], $label);
        }

        // 2. Legacy-fallback: классифицируем ip_address на лету.
        $ip = '';
        if (!empty($details['ip_address']) && is_string($details['ip_address'])) {
            $ip = $details['ip_address'];
        }

        if ($ip !== '' && class_exists('Cashback_Fraud_Ip_Intelligence')) {
            $info = Cashback_Fraud_Ip_Intelligence::classify($ip);
            return self::render_ip_type_badge((string) ( $info['type'] ?? 'unknown' ), (string) ( $info['label'] ?? '' ));
        }

        return '<span class="cashback-fraud-secondary-id">—</span>';
    }

    /**
     * Собирает структуру network для AJAX-ответа detail-модала.
     *
     * @param array<string, mixed> $alert ARRAY_A алерта (с уже распарсенным details).
     * @return array<string, mixed>|null
     */
    private static function build_network_info_for_alert( array $alert ): ?array {
        $alert_type = (string) ( $alert['alert_type'] ?? '' );
        $details    = is_array($alert['details'] ?? null) ? $alert['details'] : array();

        // device-related алерты — отдаём нейтральный network-блок.
        if (in_array($alert_type, array( 'shared_device_id', 'device_multiple_ips' ), true)) {
            return array(
                'type'       => 'device',
                'label'      => __('Device', 'cashback-plugin'),
                'asn'        => null,
                'org'        => null,
                'multiplier' => null,
            );
        }

        // 1. Из details.
        if (!empty($details['ip_type']) && is_string($details['ip_type'])) {
            return array(
                'type'       => $details['ip_type'],
                'label'      => (string) ( $details['ip_label'] ?? $details['ip_type'] ),
                'asn'        => isset($details['ip_asn']) ? (string) $details['ip_asn'] : null,
                'org'        => isset($details['ip_org']) ? (string) $details['ip_org'] : null,
                'multiplier' => isset($details['weight_multiplier']) ? (float) $details['weight_multiplier'] : null,
            );
        }

        // 2. Legacy-fallback: классифицируем ip_address.
        $ip = (string) ( $details['ip_address'] ?? '' );
        if ($ip !== '' && class_exists('Cashback_Fraud_Ip_Intelligence')) {
            $info = Cashback_Fraud_Ip_Intelligence::classify($ip);
            return array(
                'type'       => (string) ( $info['type'] ?? 'unknown' ),
                'label'      => (string) ( $info['label'] ?? '' ),
                'asn'        => isset($info['asn']) && $info['asn'] !== null ? (string) $info['asn'] : null,
                'org'        => isset($info['org']) && $info['org'] !== null ? (string) $info['org'] : null,
                'multiplier' => isset($info['weight_multiplier']) ? (float) $info['weight_multiplier'] : null,
            );
        }

        return null;
    }

    /**
     * Опции для dropdown-фильтра «Сеть».
     *
     * @return array<string, string>
     */
    private static function get_ip_type_filter_options(): array {
        return array(
            'mobile'      => __('Mobile', 'cashback-plugin'),
            'residential' => __('Home', 'cashback-plugin'),
            'hosting'     => __('Datacenter', 'cashback-plugin'),
            'vpn'         => __('VPN', 'cashback-plugin'),
            'tor'         => __('Tor', 'cashback-plugin'),
            'cgnat'       => __('CGNAT', 'cashback-plugin'),
            'private'     => __('Private', 'cashback-plugin'),
            'unknown'     => __('Unknown', 'cashback-plugin'),
        );
    }

    /**
     * Поддерживает ли сервер JSON_EXTRACT (MySQL 5.7+, MariaDB 10.2.3+).
     * Кэшируется в transient на сутки.
     */
    private static function supports_json_extract(): bool {
        $cached = get_transient('cashback_fraud_json_extract_supported');
        if ($cached !== false) {
            return $cached === '1';
        }

        global $wpdb;
        $supported = '0';
        $version   = $wpdb->db_version();
        // MariaDB не репортит свою версию через db_version() однозначно: на $wpdb->use_mysqli
        // db_version() парсит mysqli_get_server_info(). Простейший runtime-чек —
        // попробовать вызвать JSON_EXTRACT. suppress_errors на время теста.
        $prev_show = $wpdb->show_errors(false);
        $prev_supp = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cap-check, кэшируем сами в transient.
        $probe = $wpdb->get_var('SELECT JSON_EXTRACT(\'{"a":1}\', \'$.a\')');
        $wpdb->suppress_errors($prev_supp);
        $wpdb->show_errors($prev_show);
        if ($probe !== null && empty($wpdb->last_error)) {
            $supported = '1';
        }
        // Подавим ошибку из last_error, чтобы не всплывала позже.
        $wpdb->last_error = '';
        unset($version);

        // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- сутки = 86400с.
        set_transient('cashback_fraud_json_extract_supported', $supported, DAY_IN_SECONDS);
        return $supported === '1';
    }

    /**
     * Одноразовая миграция: автоматически dismiss-нуть открытые алерты multi_account_ip
     * за последние 90 дней, у которых IP попадает в mobile/CGNAT/private диапазоны.
     *
     * Идемпотентность: флаг в wp_options 'cashback_fraud_legacy_cgnat_dismissed_at'.
     *
     * @return int Количество dismissed-алертов (0 при повторном вызове или отсутствии IP-Intelligence).
     */
    public static function migrate_dismiss_legacy_cgnat_alerts(): int {
        if (get_option('cashback_fraud_legacy_cgnat_dismissed_at')) {
            return 0;
        }

        if (!class_exists('Cashback_Fraud_Ip_Intelligence')) {
            // IP-Intelligence модуль отсутствует — миграция бессмысленна, но флаг ставим,
            // чтобы не пытаться снова на каждом activation.
            update_option('cashback_fraud_legacy_cgnat_dismissed_at', current_time('mysql'), false);
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_fraud_alerts';

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, details FROM %i
             WHERE alert_type = %s
             AND status = %s
             AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)',
            $table,
            'multi_account_ip',
            'open'
        ));

        $dismissed = 0;
        $excluded  = array( 'mobile', 'cgnat', 'private' );

        foreach ($rows as $row) {
            $details = json_decode((string) $row->details, true);
            if (!is_array($details)) {
                continue;
            }
            $ip = (string) ( $details['ip_address'] ?? '' );
            if ($ip === '') {
                continue;
            }
            $info = Cashback_Fraud_Ip_Intelligence::classify($ip);
            $type = (string) ( $info['type'] ?? 'unknown' );
            if (!in_array($type, $excluded, true)) {
                continue;
            }

            $wpdb->update(
                $table,
                array(
                    'status'      => 'dismissed',
                    'reviewed_by' => 0,
                    'reviewed_at' => current_time('mysql'),
                    'review_note' => 'auto_dismissed_cgnat_migration',
                ),
                array( 'id' => (int) $row->id ),
                array( '%s', '%d', '%s', '%s' ),
                array( '%d' )
            );
            ++$dismissed;
        }

        update_option('cashback_fraud_legacy_cgnat_dismissed_at', current_time('mysql'), false);

        if ($dismissed > 0 && class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'fraud_legacy_cgnat_migration',
                0,
                'system',
                null,
                array( 'dismissed' => $dismissed )
            );
        }

        return $dismissed;
    }

    /**
     * @return array<string, string>
     */
    private static function get_alert_type_labels(): array {
        return array(
            'multi_account_ip'    => 'Общий IP',
            'multi_account_fp'    => 'Общий fingerprint',
            'shared_device_id'    => 'Общий device ID',
            'device_multiple_ips' => 'Устройство со множеством IP',
            'shared_details'      => 'Общие реквизиты',
            'cancellation_rate'   => 'Высокий % отклонений',
            'velocity'            => 'Высокая частота выводов',
            'amount_anomaly'      => 'Аномальная сумма',
            'new_account_risk'    => 'Новый аккаунт',
        );
    }

    /**
     * @return array<string, string>
     */
    private static function get_status_labels(): array {
        return array(
            'open'      => 'Открыт',
            'reviewing' => 'На проверке',
            'confirmed' => 'Подтверждён',
            'dismissed' => 'Отклонён',
        );
    }
}
