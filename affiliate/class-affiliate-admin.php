<?php
/**
 * Affiliate Module — Admin Panel.
 *
 * Меню «Партнёрская программа» под cashback-overview.
 * 3 вкладки: Настройки, Начисления, Партнёры.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Affiliate_Admin {

    const PER_PAGE          = 20;
    const LABEL_PAGE_TITLE  = 'Партнёрская программа';
    const MSG_NO_PERMISSION = 'Недостаточно прав.';
    const MSG_INVALID_NONCE = 'Неверный nonce.';

    public function __construct() {
        add_action('admin_menu', array( $this, 'add_admin_menu' ));
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));

        // AJAX handlers
        add_action('wp_ajax_affiliate_toggle_module', array( $this, 'handle_toggle_module' ));
        add_action('wp_ajax_affiliate_save_settings', array( $this, 'handle_save_settings' ));
        add_action('wp_ajax_affiliate_update_partner', array( $this, 'handle_update_partner' ));
        add_action('wp_ajax_affiliate_get_partner_details', array( $this, 'handle_get_partner_details' ));
        add_action('wp_ajax_affiliate_bulk_update_commission_rate', array( $this, 'handle_bulk_update_commission_rate' ));
        add_action('wp_ajax_affiliate_edit_accrual', array( $this, 'handle_edit_accrual' ));
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            'cashback-overview',
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Class constant holding a single translatable literal.
            __(self::LABEL_PAGE_TITLE, 'cashback-plugin'),
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Class constant holding a single translatable literal.
            __(self::LABEL_PAGE_TITLE, 'cashback-plugin'),
            'manage_options',
            'cashback-affiliate',
            array( $this, 'render_page' )
        );
    }

    public function enqueue_admin_scripts( string $hook ): void {
        $allowed_hooks = array(
            'cashback-overview_page_cashback-affiliate',
            'toplevel_page_cashback-affiliate',
            'admin_page_cashback-affiliate',
        );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin page detection, no state change.
        $is_page = in_array($hook, $allowed_hooks, true)
            || ( isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'cashback-affiliate' );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (!$is_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-admin-affiliate',
            plugins_url('../assets/css/admin-affiliate.css', __FILE__),
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'cashback-admin-affiliate-js',
            plugins_url('../assets/js/admin-affiliate.js', __FILE__),
            array( 'jquery' ),
            '1.0.0',
            true
        );

        wp_localize_script('cashback-admin-affiliate-js', 'cashbackAffiliateAdmin', array(
            'ajaxurl'          => admin_url('admin-ajax.php'),
            'toggleNonce'      => wp_create_nonce('affiliate_toggle_module_nonce'),
            'settingsNonce'    => wp_create_nonce('affiliate_save_settings_nonce'),
            'partnerNonce'     => wp_create_nonce('affiliate_update_partner_nonce'),
            'detailsNonce'     => wp_create_nonce('affiliate_get_partner_details_nonce'),
            'bulkRateNonce'    => wp_create_nonce('affiliate_bulk_update_commission_rate_nonce'),
            'editAccrualNonce' => wp_create_nonce('affiliate_edit_accrual_nonce'),
        ));
    }

    /* ═══════════════════════════════════════
     *  РЕНДЕР СТРАНИЦЫ
     * ═══════════════════════════════════════ */

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Class constant holding a single translatable literal.
            wp_die(esc_html__(self::MSG_NO_PERMISSION, 'cashback-plugin'));
        }

        $enabled = Cashback_Affiliate_DB::is_module_enabled();

        echo '<div class="wrap cashback-affiliate-admin">';
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Class constant holding a single translatable literal.
        echo '<h1>' . esc_html__(self::LABEL_PAGE_TITLE, 'cashback-plugin') . '</h1>';

        // Toggle module
        echo '<div class="cashback-affiliate-toggle">';
        echo '<label class="cashback-toggle-switch">';
        echo '<input type="checkbox" id="affiliate-module-toggle" ' . checked($enabled, true, false) . '>';
        echo '<span class="cashback-toggle-slider"></span>';
        echo '</label>';
        echo '<span class="cashback-toggle-label">'
            . ( $enabled ? esc_html__('Модуль включён', 'cashback-plugin') : esc_html__('Модуль выключен', 'cashback-plugin') )
            . '</span>';
        echo '</div>';

        if (!$enabled) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('Модуль партнёрской программы выключен. Включите его для работы.', 'cashback-plugin')
                . '</p></div>';
            echo '</div>';
            return;
        }

        // Tabs
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab routing, allowlist-validated below.
        $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'settings';
        $tabs        = array(
            'settings' => __('Настройки', 'cashback-plugin'),
            'accruals' => __('Начисления', 'cashback-plugin'),
            'partners' => __('Партнёры', 'cashback-plugin'),
        );

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $url    = add_query_arg(array(
				'page' => 'cashback-affiliate',
				'tab'  => $slug,
			), admin_url('admin.php'));
            $active = $slug === $current_tab ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($active) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        echo '<div class="cashback-affiliate-tab-content">';
        switch ($current_tab) {
            case 'accruals':
                $this->render_accruals_tab();
                break;
            case 'partners':
                $this->render_partners_tab();
                break;
            default:
                $this->render_settings_tab();
        }
        echo '</div>';

        echo '</div>'; // wrap
    }

    /* ═══════════════════════════════════════
     *  ВКЛАДКА: НАСТРОЙКИ
     * ═══════════════════════════════════════ */

    private function render_settings_tab(): void {
        $cookie_ttl        = Cashback_Affiliate_DB::get_cookie_ttl_days();
        $rules_url         = Cashback_Affiliate_DB::get_rules_page_url();
        $antifraud_enabled = Cashback_Affiliate_DB::is_antifraud_enabled();

        echo '<form id="affiliate-settings-form" class="cashback-affiliate-form">';

        echo '<table class="form-table">';

        // Срок cookie
        echo '<tr>';
        echo '<th><label for="aff-cookie-ttl">' . esc_html__('Срок cookie (дни)', 'cashback-plugin') . '</label></th>';
        echo '<td><input type="number" id="aff-cookie-ttl" name="cookie_ttl" value="' . esc_attr($cookie_ttl) . '" min="1" max="365" class="small-text">';
        echo '<p class="description">' . esc_html__('Сколько дней cookie реферальной ссылки будет активна.', 'cashback-plugin') . '</p></td>';
        echo '</tr>';

        // URL правил
        echo '<tr>';
        echo '<th><label for="aff-rules-url">' . esc_html__('Страница правил', 'cashback-plugin') . '</label></th>';
        echo '<td><input type="url" id="aff-rules-url" name="rules_url" value="' . esc_attr($rules_url) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('URL страницы с правилами партнёрской программы.', 'cashback-plugin') . '</p></td>';
        echo '</tr>';

        // Антифрод
        echo '<tr>';
        echo '<th><label for="aff-antifraud-enabled">' . esc_html__('Антифрод-проверка', 'cashback-plugin') . '</label></th>';
        echo '<td><label>';
        echo '<input type="checkbox" id="aff-antifraud-enabled" name="antifraud_enabled" value="1" ' . checked($antifraud_enabled, true, false) . '>';
        echo ' ' . esc_html__('Включить антифрод при привязке рефералов', 'cashback-plugin');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Проверка совпадения IP, подозрительного тайминга. Отключите для тестирования. Проверки self-referral, валидности реферера и дубликатов работают всегда.', 'cashback-plugin') . '</p></td>';
        echo '</tr>';

        echo '</table>';

        echo '<p class="submit"><button type="submit" class="button button-primary" id="affiliate-save-settings">'
            . esc_html__('Сохранить настройки', 'cashback-plugin') . '</button></p>';
        echo '</form>';
    }

    /* ═══════════════════════════════════════
     *  ВКЛАДКА: НАЧИСЛЕНИЯ
     * ═══════════════════════════════════════ */

    private function render_accruals_tab(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin listing filters, validated in query_accruals() via allowlist/esc_like.
        $filter_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $filter_search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $query_result = $this->query_accruals($filter_status, $filter_search);
        $accruals     = $query_result['rows'];
        $current_page = $query_result['current_page'];
        $total        = $query_result['total'];
        $total_pages  = $query_result['total_pages'];

        // Filters UI
        echo '<div class="tablenav top">';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="cashback-affiliate">';
        echo '<input type="hidden" name="tab" value="accruals">';

        echo '<select name="status">';
        echo '<option value="">' . esc_html__('Все статусы', 'cashback-plugin') . '</option>';
        foreach (array(
            'pending'   => 'В ожидании',
            'available' => 'Зачислен на баланс',
            'frozen'    => 'Заморожено',
            'paid'      => 'Выплачено',
            'declined'  => 'Отклонён',
        ) as $val => $lbl) {
            echo '<option value="' . esc_attr($val) . '"' . selected($filter_status, $val, false) . '>' . esc_html($lbl) . '</option>';
        }
        echo '</select>';

        echo '<input type="search" name="s" value="' . esc_attr($filter_search) . '" placeholder="' . esc_attr__('Поиск...', 'cashback-plugin') . '">';
        echo '<button type="submit" class="button">' . esc_html__('Фильтр', 'cashback-plugin') . '</button>';
        if ($filter_status !== '' || $filter_search !== '') {
            $reset_url = add_query_arg(array(
				'page' => 'cashback-affiliate',
				'tab'  => 'accruals',
			), admin_url('admin.php'));
            echo ' <a href="' . esc_url($reset_url) . '" class="button">' . esc_html__('Сбросить фильтры', 'cashback-plugin') . '</a>';
        }
        echo '</form>';
        echo '</div>';

        // Table
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Реферер', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Реферал', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Кешбэк', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Ставка', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Комиссия', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Статус', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Дата', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Действия', 'cashback-plugin') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($accruals)) {
            echo '<tr><td colspan="9">' . esc_html__('Начислений нет.', 'cashback-plugin') . '</td></tr>';
        } else {
            $this->render_accrual_rows($accruals);
        }

        echo '</tbody></table>';

        // Pagination
        Cashback_Pagination::render(array(
            'total_items'  => $total,
            'per_page'     => self::PER_PAGE,
            'current_page' => $current_page,
            'total_pages'  => $total_pages,
            'page_slug'    => 'cashback-affiliate',
            'add_args'     => array(
                'tab'    => 'accruals',
                'status' => $filter_status,
                's'      => $filter_search,
            ),
        ));
    }

    /**
     * Запрос начислений с фильтрацией и пагинацией.
     *
     * @return array{rows: array, total: int, current_page: int, total_pages: int}
     */
    private function query_accruals( string $filter_status, string $filter_search ): array {
        global $wpdb;
        $accruals_table = $wpdb->prefix . 'cashback_affiliate_accruals';
        $per_page       = self::PER_PAGE;

        $where_clauses = array();
        $where_args    = array();

        if ($filter_status && in_array($filter_status, array( 'pending', 'available', 'frozen', 'paid', 'declined' ), true)) {
            $where_clauses[] = 'a.status = %s';
            $where_args[]    = $filter_status;
        }

        if ($filter_search) {
            $where_clauses[] = '(a.reference_id LIKE %s OR u1.display_name LIKE %s OR u2.display_name LIKE %s)';
            $like            = '%' . $wpdb->esc_like($filter_search) . '%';
            $where_args[]    = $like;
            $where_args[]    = $like;
            $where_args[]    = $like;
        }

        $where_sql  = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
        $table_args = array( $accruals_table, $wpdb->users, $wpdb->users );

        if (!empty($where_args)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql from allowlist (status IN array / LIKE %s); values bound via prepare(); sniff can't count array_merge args.
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i a LEFT JOIN %i u1 ON u1.ID = a.referrer_id LEFT JOIN %i u2 ON u2.ID = a.referred_user_id {$where_sql}", array_merge( $table_args, $where_args ) ) );
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i a
                 LEFT JOIN %i u1 ON u1.ID = a.referrer_id
                 LEFT JOIN %i u2 ON u2.ID = a.referred_user_id',
                $accruals_table, $wpdb->users, $wpdb->users
            ));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing pagination (absint + capped by total_pages).
        $current_page = max(1, absint($_GET['paged'] ?? 1));
        $total_pages  = max(1, (int) ceil($total / $per_page));
        $current_page = min($current_page, $total_pages);
        $offset       = ( $current_page - 1 ) * $per_page;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql from allowlist (status IN array / LIKE %s); values bound via prepare(); sniff can't count array_merge args.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT a.*, u1.display_name AS referrer_name, u2.display_name AS referred_name FROM %i a LEFT JOIN %i u1 ON u1.ID = a.referrer_id LEFT JOIN %i u2 ON u2.ID = a.referred_user_id {$where_sql} ORDER BY a.created_at DESC LIMIT %d OFFSET %d", array_merge( $table_args, $where_args, array( $per_page, $offset ) ) ), ARRAY_A );

        return compact('rows', 'total', 'current_page', 'total_pages');
    }

    /**
     * Рендер строк таблицы начислений.
     */
    private function render_accrual_rows( array $accruals ): void {
        $status_labels = array(
            'pending'   => '<span class="aff-status aff-status-pending">В ожидании</span>',
            'available' => '<span class="aff-status aff-status-available">Зачислен на баланс</span>',
            'frozen'    => '<span class="aff-status aff-status-frozen">Заморожено</span>',
            'paid'      => '<span class="aff-status aff-status-paid">Выплачено</span>',
            'declined'  => '<span class="aff-status aff-status-declined">Отклонён</span>',
        );

        foreach ($accruals as $row) {
            $is_editable = $row['status'] !== 'available';

            echo '<tr>';
            echo '<td><code>' . esc_html($row['reference_id']) . '</code></td>';
            echo '<td>' . esc_html($row['referrer_name'] ?: '#' . $row['referrer_id']) . '</td>';
            echo '<td>' . esc_html($row['referred_name'] ?: '#' . $row['referred_user_id']) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row['cashback_amount'], 2, '.', ' ')) . ' ₽</td>';
            echo '<td>' . esc_html($row['commission_rate']) . '%</td>';
            echo '<td><strong>' . esc_html(number_format((float) $row['commission_amount'], 2, '.', ' ')) . ' ₽</strong></td>';
            echo '<td>' . wp_kses_post($status_labels[ $row['status'] ] ?? esc_html($row['status'])) . '</td>'; // $status_labels — статически заданный HTML, fallback экранирован.
            echo '<td>' . esc_html(wp_date('d.m.Y H:i', strtotime($row['created_at']))) . '</td>';
            echo '<td>';
            if ($is_editable) {
                echo '<button type="button" class="button button-small aff-edit-accrual"'
                    . ' data-id="' . esc_attr($row['id']) . '"'
                    . ' data-rate="' . esc_attr($row['commission_rate']) . '"'
                    . ' data-amount="' . esc_attr($row['commission_amount']) . '"'
                    . ' data-cashback="' . esc_attr($row['cashback_amount']) . '"'
                    . ' data-status="' . esc_attr($row['status']) . '"'
                    . '>' . esc_html__('Изменить', 'cashback-plugin') . '</button>';
            } else {
                echo '<span class="description">' . esc_html__('—', 'cashback-plugin') . '</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
    }

    /**
     * Рендер строк таблицы партнёров.
     */
    private function render_partner_rows( array $partners, string $global_rate ): void {
        foreach ($partners as $row) {
            $rate_display = $row['affiliate_rate'] !== null
                ? esc_html($row['affiliate_rate']) . '%'
                : esc_html($global_rate) . '% <em>(' . esc_html__('глоб.', 'cashback-plugin') . ')</em>';

            $is_active   = $row['affiliate_status'] === 'active';
            $status_html = $is_active
                ? '<span class="aff-status aff-status-available">' . esc_html__('Активен', 'cashback-plugin') . '</span>'
                : '<span class="aff-status aff-status-frozen">' . esc_html__('Отключён', 'cashback-plugin') . '</span>';

            echo '<tr data-user-id="' . esc_attr($row['user_id']) . '">';
            echo '<td>' . esc_html($row['display_name']) . ' <small>(#' . esc_html($row['user_id']) . ')</small></td>';
            echo '<td>' . esc_html($row['user_email']) . '</td>';
            echo '<td>' . $rate_display . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<td>' . esc_html($row['referral_count']) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row['total_earned'], 2, '.', ' ')) . ' ₽</td>';
            echo '<td>' . $status_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<td>';

            echo '<button type="button" class="button button-small aff-edit-rate" data-user-id="' . esc_attr($row['user_id']) . '" data-rate="' . esc_attr($row['affiliate_rate'] ?? '') . '">'
                . esc_html__('Ставка', 'cashback-plugin') . '</button> ';

            if ($is_active) {
                echo '<button type="button" class="button button-small aff-disable-partner" data-user-id="' . esc_attr($row['user_id']) . '">'
                    . esc_html__('Отключить', 'cashback-plugin') . '</button>';
            } else {
                echo '<button type="button" class="button button-small button-primary aff-enable-partner" data-user-id="' . esc_attr($row['user_id']) . '">'
                    . esc_html__('Подключить', 'cashback-plugin') . '</button>';
            }

            echo '</td>';
            echo '</tr>';
        }
    }

    /* ═══════════════════════════════════════
     *  ВКЛАДКА: ПАРТНЁРЫ
     * ═══════════════════════════════════════ */

    private function render_partners_tab(): void {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin listing: pagination (absint) + filters validated via allowlist/esc_like below.
        $current_page  = max(1, absint($_GET['paged'] ?? 1));
        $per_page      = self::PER_PAGE;
        $filter_status = isset($_GET['aff_status']) ? sanitize_text_field(wp_unslash($_GET['aff_status'])) : '';
        $filter_search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $where_clauses = array();
        $where_args    = array();

        if ($filter_status && in_array($filter_status, array( 'active', 'disabled' ), true)) {
            $where_clauses[] = 'ap.affiliate_status = %s';
            $where_args[]    = $filter_status;
        }

        if ($filter_search) {
            $where_clauses[] = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
            $like            = '%' . $wpdb->esc_like($filter_search) . '%';
            $where_args[]    = $like;
            $where_args[]    = $like;
        }

        $where_sql      = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
        $profiles_table = $prefix . 'cashback_affiliate_profiles';
        $accruals_table = $prefix . 'cashback_affiliate_accruals';

        if (!empty($where_args)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql from allowlist (affiliate_status IN array / LIKE %s); values bound via prepare(); sniff can't count array_merge args.
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i ap INNER JOIN %i u ON u.ID = ap.user_id {$where_sql}", array_merge( array( $profiles_table, $wpdb->users ), $where_args ) ) );
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*)
                 FROM %i ap
                 INNER JOIN %i u ON u.ID = ap.user_id',
                $profiles_table, $wpdb->users
            ));
        }

        $total_pages  = max(1, (int) ceil($total / $per_page));
        $current_page = min($current_page, $total_pages);
        $offset       = ( $current_page - 1 ) * $per_page;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql from allowlist (affiliate_status IN array / LIKE %s); values bound via prepare(); sniff can't count array_merge args.
        $partners = $wpdb->get_results( $wpdb->prepare( "SELECT ap.*, u.display_name, u.user_email, (SELECT COUNT(*) FROM %i r WHERE r.referred_by_user_id = ap.user_id) AS referral_count, (SELECT COALESCE(SUM(commission_amount), 0) FROM %i WHERE referrer_id = ap.user_id) AS total_earned FROM %i ap INNER JOIN %i u ON u.ID = ap.user_id {$where_sql} ORDER BY ap.created_at DESC LIMIT %d OFFSET %d", array_merge( array( $profiles_table, $accruals_table, $profiles_table, $wpdb->users ), $where_args, array( $per_page, $offset ) ) ), ARRAY_A );

        $global_rate = Cashback_Affiliate_DB::get_global_rate();

        // Массовое изменение ставки комиссии
        echo '<div class="postbox" style="padding: 12px 16px; margin-top: 15px; margin-bottom: 20px;">';
        echo '<h3 style="margin: 0 0 10px;">' . esc_html__('Массовое изменение ставки комиссии', 'cashback-plugin') . '</h3>';
        echo '<div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">';
        echo '<label for="bulk-aff-old-rate">' . esc_html__('Текущая ставка:', 'cashback-plugin') . '</label>';
        echo '<input type="text" id="bulk-aff-old-rate" placeholder="10 или all" style="width: 100px;" />';
        echo '<label for="bulk-aff-new-rate">' . esc_html__('Новая ставка (%):', 'cashback-plugin') . '</label>';
        echo '<input type="number" id="bulk-aff-new-rate" step="0.01" min="0" max="100" placeholder="15" style="width: 100px;" />';
        echo '<button type="button" id="bulk-aff-rate-preview" class="button">' . esc_html__('Предпросмотр', 'cashback-plugin') . '</button>';
        echo '<button type="button" id="bulk-aff-rate-apply" class="button button-primary" disabled>' . esc_html__('Применить', 'cashback-plugin') . '</button>';
        echo '<span id="bulk-aff-rate-info" style="color: #666;"></span>';
        echo '</div>';
        echo '<p class="description" style="margin-top: 10px;">';
        echo esc_html__('В поле «Текущая ставка» укажите процент, который нужно заменить (например, 10), или введите all, чтобы изменить ставку у всех партнёров сразу. В поле «Новая ставка» укажите новый процент комиссии (от 0 до 100). Нажмите «Предпросмотр», чтобы увидеть количество затронутых партнёров, затем «Применить» для подтверждения.', 'cashback-plugin');
        echo '</p>';
        echo '</div>';

        // Filters
        echo '<div class="tablenav top">';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="cashback-affiliate">';
        echo '<input type="hidden" name="tab" value="partners">';

        echo '<select name="aff_status">';
        echo '<option value="">' . esc_html__('Все статусы', 'cashback-plugin') . '</option>';
        echo '<option value="active"' . selected($filter_status, 'active', false) . '>' . esc_html__('Активные', 'cashback-plugin') . '</option>';
        echo '<option value="disabled"' . selected($filter_status, 'disabled', false) . '>' . esc_html__('Отключённые', 'cashback-plugin') . '</option>';
        echo '</select>';

        echo '<input type="search" name="s" value="' . esc_attr($filter_search) . '" placeholder="' . esc_attr__('Поиск...', 'cashback-plugin') . '">';
        echo '<button type="submit" class="button">' . esc_html__('Фильтр', 'cashback-plugin') . '</button>';
        if ($filter_status !== '' || $filter_search !== '') {
            $reset_url = add_query_arg(array(
                'page' => 'cashback-affiliate',
                'tab'  => 'partners',
            ), admin_url('admin.php'));
            echo ' <a href="' . esc_url($reset_url) . '" class="button">' . esc_html__('Сбросить фильтры', 'cashback-plugin') . '</a>';
        }
        echo '</form>';
        echo '</div>';

        // Table
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Пользователь', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Email', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Ставка', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Рефералы', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Заработано', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Статус', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Действия', 'cashback-plugin') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($partners)) {
            echo '<tr><td colspan="7">' . esc_html__('Партнёров нет.', 'cashback-plugin') . '</td></tr>';
        } else {
            $this->render_partner_rows($partners, $global_rate);
        }

        echo '</tbody></table>';

        Cashback_Pagination::render(array(
            'total_items'  => $total,
            'per_page'     => $per_page,
            'current_page' => $current_page,
            'total_pages'  => $total_pages,
            'page_slug'    => 'cashback-affiliate',
            'add_args'     => array(
                'tab'        => 'partners',
                'aff_status' => $filter_status,
                's'          => $filter_search,
            ),
        ));
    }

    /* ═══════════════════════════════════════
     *  AJAX HANDLERS
     * ═══════════════════════════════════════ */

    /**
     * Включение/выключение модуля.
     */
    public function handle_toggle_module(): void {
        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')),
            'affiliate_toggle_module_nonce'
        )) {
            wp_send_json_error(array( 'message' => self::MSG_INVALID_NONCE ));
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => self::MSG_NO_PERMISSION ));
            return;
        }

        $enabled = !empty($_POST['enabled']);
        Cashback_Affiliate_DB::set_module_enabled($enabled);

        wp_send_json_success(array(
            'message' => $enabled
                ? __('Модуль включён.', 'cashback-plugin')
                : __('Модуль выключен.', 'cashback-plugin'),
        ));
    }

    /**
     * Сохранение настроек.
     */
    public function handle_save_settings(): void {
        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')),
            'affiliate_save_settings_nonce'
        )) {
            wp_send_json_error(array( 'message' => self::MSG_INVALID_NONCE ));
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => self::MSG_NO_PERMISSION ));
            return;
        }

        if (isset($_POST['cookie_ttl'])) {
            Cashback_Affiliate_DB::set_cookie_ttl_days((int) $_POST['cookie_ttl']);
        }
        if (isset($_POST['rules_url'])) {
            Cashback_Affiliate_DB::set_rules_page_url(sanitize_text_field(wp_unslash($_POST['rules_url'])));
        }
        // antifraud_enabled: checkbox — если не передан, значит выключен
        Cashback_Affiliate_DB::set_antifraud_enabled(!empty($_POST['antifraud_enabled']));

        wp_send_json_success(array( 'message' => __('Настройки сохранены.', 'cashback-plugin') ));
    }

    /**
     * Обновление партнёра: ставка, включение/отключение.
     */
    public function handle_update_partner(): void {
        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')),
            'affiliate_update_partner_nonce'
        )) {
            wp_send_json_error(array( 'message' => self::MSG_INVALID_NONCE ));
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => self::MSG_NO_PERMISSION ));
            return;
        }

        $user_id = absint($_POST['user_id'] ?? 0);
        $action  = sanitize_text_field(wp_unslash($_POST['partner_action'] ?? ''));

        if ($user_id < 1) {
            wp_send_json_error(array( 'message' => 'Неверный ID пользователя.' ));
            return;
        }

        global $wpdb;

        switch ($action) {
            case 'set_rate':
                $this->update_partner_rate($user_id);
                break;
            case 'disable':
                $this->toggle_partner_status($user_id, false);
                break;
            case 'enable':
                $this->toggle_partner_status($user_id, true);
                break;
            default:
                wp_send_json_error(array( 'message' => 'Неизвестное действие.' ));
        }
    }

    private function update_partner_rate( int $user_id ): void {
        global $wpdb;

        $profiles_table = $wpdb->prefix . 'cashback_affiliate_profiles';

        $old_rate = $wpdb->get_var($wpdb->prepare(
            'SELECT affiliate_rate FROM %i WHERE user_id = %d',
            $profiles_table, $user_id
        ));

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Nonce verified earlier in handle_update_partner() caller.
        $rate = isset($_POST['rate']) && $_POST['rate'] !== ''
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Nonce verified earlier in handle_update_partner() caller.
            ? max(0, min(100, (float) $_POST['rate']))
            : null;

        $wpdb->query('START TRANSACTION');

        try {
            if ($rate !== null) {
                $result = $wpdb->update(
                    $wpdb->prefix . 'cashback_affiliate_profiles',
                    array( 'affiliate_rate' => number_format($rate, 2, '.', '') ),
                    array( 'user_id' => $user_id ),
                    array( '%s' ),
                    array( '%d' )
                );
            } else {
                $result = $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET affiliate_rate = NULL WHERE user_id = %d',
                    $profiles_table, $user_id
                ));
            }

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array( 'message' => 'Ошибка при обновлении ставки.' ));
                return;
            }

            if (class_exists('Cashback_Rate_History_Admin')) {
                $old_rate_val = $old_rate !== null ? (float) $old_rate : null;
                $new_rate_val = $rate ?? 0;
                Cashback_Rate_History_Admin::log_rate_change(
                    'affiliate_commission',
                    $user_id,
                    $old_rate_val,
                    $new_rate_val,
                    1,
                    'manual'
                );
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении ставки.' ));
            return;
        }

        wp_send_json_success(array( 'message' => __('Ставка обновлена.', 'cashback-plugin') ));
    }

    private function toggle_partner_status( int $user_id, bool $enable ): void {
        $admin_id = get_current_user_id();

        $result = $enable
            ? Cashback_Affiliate_Service::unfreeze_affiliate_balance($user_id, $admin_id)
            : Cashback_Affiliate_Service::freeze_affiliate_balance($user_id, $admin_id);

        if ($result) {
            $msg = $enable
                ? __('Партнёр подключён, средства разморожены.', 'cashback-plugin')
                : __('Партнёр отключён, средства заморожены.', 'cashback-plugin');
            wp_send_json_success(array( 'message' => $msg ));
        } else {
            $msg = $enable
                ? __('Не удалось подключить партнёра.', 'cashback-plugin')
                : __('Не удалось отключить партнёра.', 'cashback-plugin');
            wp_send_json_error(array( 'message' => $msg ));
        }
    }

    /**
     * Получение деталей партнёра.
     */
    public function handle_get_partner_details(): void {
        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')),
            'affiliate_get_partner_details_nonce'
        )) {
            wp_send_json_error(array( 'message' => self::MSG_INVALID_NONCE ));
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => self::MSG_NO_PERMISSION ));
            return;
        }

        $user_id = absint($_POST['user_id'] ?? 0);
        if ($user_id < 1) {
            wp_send_json_error(array( 'message' => 'Неверный ID.' ));
            return;
        }

        global $wpdb;
        $profiles_table = $wpdb->prefix . 'cashback_affiliate_profiles';

        $profile = $wpdb->get_row($wpdb->prepare(
            'SELECT ap.*, u.display_name, u.user_email
             FROM %i ap
             INNER JOIN %i u ON u.ID = ap.user_id
             WHERE ap.user_id = %d LIMIT 1',
            $profiles_table, $wpdb->users, $user_id
        ), ARRAY_A);

        if (!$profile) {
            wp_send_json_error(array( 'message' => 'Профиль не найден.' ));
            return;
        }

        $stats = Cashback_Affiliate_Service::get_referrer_stats($user_id);

        wp_send_json_success(array(
            'profile' => $profile,
            'stats'   => $stats,
        ));
    }

    /**
     * Массовое обновление ставки комиссии партнёрской программы.
     */
    public function handle_bulk_update_commission_rate(): void {
        if (!isset($_POST['nonce'])) {
            wp_send_json_error(array( 'message' => 'Отсутствует nonce.' ));
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'affiliate_bulk_update_commission_rate_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный nonce.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав для выполнения этого действия.' ));
            return;
        }

        if (!isset($_POST['old_rate'], $_POST['new_rate'])) {
            wp_send_json_error(array( 'message' => 'Не указаны параметры.' ));
            return;
        }

        $old_rate_raw = trim(sanitize_text_field(wp_unslash($_POST['old_rate'])));
        $new_rate     = sanitize_text_field(wp_unslash($_POST['new_rate']));
        $preview      = !empty($_POST['preview']);

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $new_rate) || bccomp($new_rate, '0', 2) < 0 || bccomp($new_rate, '100', 2) > 0) {
            wp_send_json_error(array( 'message' => 'Новая ставка должна быть числом от 0 до 100.' ));
            return;
        }

        global $wpdb;
        $prefix = $wpdb->prefix;
        $table  = $prefix . 'cashback_affiliate_profiles';

        $is_all = ( strtolower($old_rate_raw) === 'all' );

        if (!$is_all) {
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $old_rate_raw) || bccomp($old_rate_raw, '0', 2) < 0 || bccomp($old_rate_raw, '100', 2) > 0) {
                wp_send_json_error(array( 'message' => 'Текущая ставка должна быть числом от 0 до 100 или "all".' ));
                return;
            }
        }

        // Count affected users (for preview or apply)
        if ($is_all) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE (affiliate_rate IS NULL OR affiliate_rate != %s)',
                $table, $new_rate
            ));
        } else {
            // Users with affiliate_rate = old_rate (including those using global rate when old_rate matches global)
            $global_rate = Cashback_Affiliate_DB::get_global_rate();
            $count       = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE affiliate_rate = %s',
                $table, $old_rate_raw
            ));
            // Also count users using global rate if old_rate matches global
            if (bccomp($old_rate_raw, $global_rate, 2) === 0) {
                $count_null = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE affiliate_rate IS NULL',
                    $table
                ));
                $count     += $count_null;
            }
        }

        if ($preview) {
            wp_send_json_success(array(
                'count'    => $count,
                'old_rate' => $old_rate_raw,
                'new_rate' => $new_rate,
            ));
            return;
        }

        if ($count === 0) {
            wp_send_json_error(array( 'message' => 'Не найдено партнёров для обновления.' ));
            return;
        }

        $wpdb->query('START TRANSACTION');

        try {
            $global_rate = Cashback_Affiliate_DB::get_global_rate();

            if ($is_all) {
                $result = $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET affiliate_rate = %s WHERE (affiliate_rate IS NULL OR affiliate_rate != %s)',
                    $table, $new_rate, $new_rate
                ));
            } else {
                $result = $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET affiliate_rate = %s WHERE affiliate_rate = %s',
                    $table, $new_rate, $old_rate_raw
                ));
                if (bccomp($old_rate_raw, $global_rate, 2) === 0) {
                    $result_null = $wpdb->query($wpdb->prepare(
                        'UPDATE %i SET affiliate_rate = %s WHERE affiliate_rate IS NULL',
                        $table, $new_rate
                    ));
                    if ($result_null !== false) {
                        $result += $result_null;
                    }
                }
            }

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array( 'message' => 'Ошибка при обновлении базы данных.' ));
                return;
            }

            if ($result === 0) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array( 'message' => 'Не найдено партнёров для обновления.' ));
                return;
            }

            // Rate history log — внутри транзакции для атомарности
            if (class_exists('Cashback_Rate_History_Admin')) {
                $rate_type = $is_all ? 'affiliate_global' : 'affiliate_commission';
                Cashback_Rate_History_Admin::log_rate_change(
                    $rate_type,
                    null,
                    $is_all ? null : (float) $old_rate_raw,
                    (float) $new_rate,
                    (int) $result,
                    'bulk',
                    array(
						'scope'    => $is_all ? 'all' : 'by_rate',
						'old_rate' => $old_rate_raw,
					)
                );
            }

            // Audit log — внутри транзакции для атомарности
            if (class_exists('Cashback_Encryption')) {
                Cashback_Encryption::write_audit_log(
                    'bulk_affiliate_commission_rate_update',
                    get_current_user_id(),
                    'cashback_affiliate_profiles',
                    null,
                    array(
                        'old_rate'       => $old_rate_raw,
                        'new_rate'       => $new_rate,
                        'affected_users' => $result,
                    )
                );
            }

            // Обновляем глобальную ставку ПОСЛЕ COMMIT — update_option() не участвует
            // в MySQL-транзакции. Если COMMIT прошёл, профили и логи записаны.
            // Если update_option() упадёт, это не критично — ставка по умолчанию
            // для новых пользователей будет старой до следующего ручного изменения.
            $committed_global = $is_all ? $new_rate : null;

            $wpdb->query('COMMIT');

            if ($committed_global !== null) {
                Cashback_Affiliate_DB::set_global_rate($committed_global);
            }
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении базы данных.' ));
            return;
        }

        wp_send_json_success(array(
            'updated'  => (int) $result,
            'old_rate' => $old_rate_raw,
            'new_rate' => $new_rate,
        ));
    }

    /* ═══════════════════════════════════════
     *  AJAX: РЕДАКТИРОВАНИЕ НАЧИСЛЕНИЯ
     * ═══════════════════════════════════════ */

    public function handle_edit_accrual(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => self::MSG_NO_PERMISSION ));
        }

        if (!check_ajax_referer('affiliate_edit_accrual_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => self::MSG_INVALID_NONCE ));
        }

        global $wpdb;
        $accruals_table = $wpdb->prefix . 'cashback_affiliate_accruals';

        $accrual_id = absint($_POST['accrual_id'] ?? 0);
        if (!$accrual_id) {
            wp_send_json_error(array( 'message' => 'Не указан ID начисления.' ));
        }

        // Получаем текущую запись
        $accrual = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d LIMIT 1',
            $accruals_table, $accrual_id
        ), ARRAY_A);

        if (!$accrual) {
            wp_send_json_error(array( 'message' => 'Начисление не найдено.' ));
        }

        // Запрет редактирования финального статуса
        if ($accrual['status'] === 'available') {
            wp_send_json_error(array( 'message' => 'Начисление со статусом «Зачислен на баланс» нельзя редактировать.' ));
        }

        $update_data    = array();
        $update_formats = array();

        // Ставка
        if (isset($_POST['commission_rate'])) {
            $rate = (float) $_POST['commission_rate'];
            if ($rate < 0 || $rate > 100) {
                wp_send_json_error(array( 'message' => 'Ставка должна быть от 0 до 100.' ));
            }
            $update_data['commission_rate'] = number_format($rate, 2, '.', '');
            $update_formats[]               = '%s';
        }

        // Сумма комиссии
        if (isset($_POST['commission_amount'])) {
            $amount = (float) $_POST['commission_amount'];
            if ($amount < 0) {
                wp_send_json_error(array( 'message' => 'Сумма комиссии не может быть отрицательной.' ));
            }
            $update_data['commission_amount'] = number_format($amount, 2, '.', '');
            $update_formats[]                 = '%s';
        }

        // Статус (только для pending/declined — можно менять друг на друга)
        if (isset($_POST['status'])) {
            $new_status          = sanitize_text_field(wp_unslash($_POST['status']));
            $allowed_transitions = array(
                'pending'  => array( 'pending', 'declined' ),
                'declined' => array( 'pending', 'declined' ),
                'frozen'   => array( 'frozen' ),
                'paid'     => array( 'paid' ),
            );
            $allowed             = $allowed_transitions[ $accrual['status'] ] ?? array();
            if (!in_array($new_status, $allowed, true)) {
                wp_send_json_error(array( 'message' => 'Недопустимый переход статуса.' ));
            }
            if ($new_status !== $accrual['status']) {
                $update_data['status'] = $new_status;
                $update_formats[]      = '%s';
            }
        }

        if (empty($update_data)) {
            wp_send_json_error(array( 'message' => 'Нет данных для обновления.' ));
        }

        $updated = $wpdb->update(
            $accruals_table,
            $update_data,
            array( 'id' => $accrual_id ),
            $update_formats,
            array( '%d' )
        );

        if ($updated === false) {
            wp_send_json_error(array( 'message' => 'Ошибка обновления: ' . $wpdb->last_error ));
        }

        // Аудит-лог
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'affiliate_accrual_edited',
                get_current_user_id(),
                'affiliate_accrual',
                $accrual_id,
                array(
                    'reference_id' => $accrual['reference_id'],
                    'old_values'   => array_intersect_key($accrual, $update_data),
                    'new_values'   => $update_data,
                )
            );
        }

        wp_send_json_success(array( 'message' => 'Начисление обновлено.' ));
    }
}
