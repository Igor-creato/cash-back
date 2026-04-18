<?php

/**
 * Админ-страница API-валидации кэшбэка
 *
 * Обеспечивает:
 * - Настройка API credentials для каждой CPA-сети
 * - Кнопка «Проверить» при выплате (AJAX-валидация пользователя)
 * - Страница с логом синхронизации
 * - Ручной запуск синхронизации
 *
 * @package CashbackPlugin
 * @since   5.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Admin_API_Validation {

    /** @var self|null */
    private static ?self $instance = null;

    /** @var string Slug страницы */
    const PAGE_SLUG = 'cashback-api-validation';

    /**
     * @return self
     */
    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Подменю в админке
        add_action('admin_menu', array( $this, 'add_admin_menu' ));

        // AJAX обработчики
        add_action('wp_ajax_cashback_validate_user', array( $this, 'ajax_validate_user' ));
        add_action('wp_ajax_cashback_save_api_credentials', array( $this, 'ajax_save_credentials' ));
        add_action('wp_ajax_cashback_manual_sync', array( $this, 'ajax_manual_sync' ));
        add_action('wp_ajax_cashback_get_sync_log', array( $this, 'ajax_get_sync_log' ));
        add_action('wp_ajax_cashback_get_validation_status', array( $this, 'ajax_get_validation_status' ));

        // Тест подключения к API
        add_action('wp_ajax_cashback_test_connection', array( $this, 'ajax_test_connection' ));

        // AJAX обработчики действий из таблиц валидации
        add_action('wp_ajax_cashback_edit_transaction', array( $this, 'ajax_edit_transaction' ));
        add_action('wp_ajax_cashback_add_transaction', array( $this, 'ajax_add_transaction' ));
        add_action('wp_ajax_cashback_overwrite_transaction', array( $this, 'ajax_overwrite_transaction' ));

        // Кампании: ручная проверка и реактивация
        add_action('wp_ajax_cashback_check_campaigns_now', array( $this, 'ajax_check_campaigns_now' ));
        add_action('wp_ajax_cashback_reactivate_product', array( $this, 'ajax_reactivate_product' ));

        // Подключение JS/CSS только на наших страницах
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_assets' ));
    }

    // =========================================================================
    // Admin Menu
    // =========================================================================

    /**
     * Добавить подменю
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'cashback-overview',
            'API Валидация',
            'API Валидация',
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Подключение ассетов
     */
    public function enqueue_assets( string $hook ): void {
        // Подключаем на странице валидации и на странице выплат.
        // Используем $_GET['page'] как надёжный fallback — кириллический
        // заголовок меню «Кэшбэк» даёт непредсказуемый $hook prefix.
        $target_slugs = array( self::PAGE_SLUG, 'cashback-payouts' );

        $allowed_hooks = array();
        foreach ($target_slugs as $slug) {
            $allowed_hooks[] = 'cashback-overview_page_' . $slug;
            $allowed_hooks[] = 'toplevel_page_' . $slug;
            $allowed_hooks[] = 'admin_page_' . $slug;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page slug detection for asset enqueue, no state change.
        $current_page = isset($_GET['page'])
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page slug detection for asset enqueue, no state change.
            ? sanitize_text_field(wp_unslash($_GET['page']))
            : '';

        $is_target_page = in_array($hook, $allowed_hooks, true)
            || in_array($current_page, $target_slugs, true);

        if (!$is_target_page) {
            return;
        }

        wp_enqueue_script(
            'cashback-api-validation',
            plugin_dir_url(__DIR__) . 'admin/js/api-validation.js',
            array( 'jquery' ),
            '5.1.0',
            true
        );

        wp_localize_script('cashback-api-validation', 'cashbackApiValidation', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('cashback_api_validation'),
            'i18n'    => array(
                'validating'        => 'Проверка...',
                'validate'          => 'Проверить',
                'match'             => '✅ Данные совпадают',
                'mismatch'          => '⚠️ Обнаружены расхождения',
                'error'             => '❌ Ошибка проверки',
                'syncing'           => 'Синхронизация...',
                'sync_complete'     => 'Синхронизация завершена',
                'saving'            => 'Сохранение...',
                'saved'             => 'Сохранено',
                'confirm_sync'      => 'Запустить синхронизацию статусов?',
                'adding'            => 'Добавление...',
                'confirm_overwrite' => 'Перезаписать локальные данные данными из API?',
                'confirm_delete'    => 'Удалить эту строку из результатов?',
            ),
        ));

        wp_enqueue_style(
            'cashback-api-validation',
            plugin_dir_url(__DIR__) . 'admin/css/api-validation.css',
            array(),
            '5.1.0'
        );
    }

    // =========================================================================
    // Page render
    // =========================================================================

    /**
     * Рендер страницы настроек API-валидации
     */
    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Доступ запрещён');
        }

        $active_tab = sanitize_text_field(wp_unslash($_GET['tab'] ?? 'settings')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection (allowlist-validated below), no state change.
        if (!in_array($active_tab, array( 'settings', 'validation', 'sync', 'campaigns' ), true)) {
            $active_tab = 'settings';
        }
?>
        <div class="wrap">
            <h1>API Валидация кэшбэка</h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=settings"
                    class="nav-tab <?php echo esc_attr( $active_tab === 'settings' ? 'nav-tab-active' : '' ); ?>">
                    Настройки API
                </a>
                <a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=validation"
                    class="nav-tab <?php echo esc_attr( $active_tab === 'validation' ? 'nav-tab-active' : '' ); ?>">
                    Проверка пользователя
                </a>
                <a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=sync"
                    class="nav-tab <?php echo esc_attr( $active_tab === 'sync' ? 'nav-tab-active' : '' ); ?>">
                    Синхронизация
                </a>
                <a href="?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>&tab=campaigns"
                    class="nav-tab <?php echo esc_attr( $active_tab === 'campaigns' ? 'nav-tab-active' : '' ); ?>">
                    Статус кампаний
                </a>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                switch ($active_tab) {
                    case 'validation':
                        $this->render_validation_tab();
                        break;
                    case 'sync':
                        $this->render_sync_tab();
                        break;
                    case 'campaigns':
                        $this->render_campaigns_tab();
                        break;
                    default:
                        $this->render_settings_tab();
                        break;
                }
                ?>
            </div>
        </div>
    <?php
    }

    /**
     * Вкладка «Настройки API»
     */
    private function render_settings_tab(): void {
        global $wpdb;

        $networks = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}cashback_affiliate_networks ORDER BY sort_order, name",
            ARRAY_A
        );

    ?>
        <div id="cashback-api-settings">
            <?php if (!empty($networks)) : ?>
                <select id="cashback-network-selector">
                    <option value="">— Выберите сеть —</option>
                    <?php foreach ($networks as $network) : ?>
                        <option value="<?php echo esc_attr($network['id']); ?>">
                            <?php echo esc_html($network['name']); ?> (<?php echo esc_html($network['slug']); ?>)<?php echo $network['is_active'] ? '' : ' — неактивна'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <?php
            foreach ($networks as $network) :
                $saved_credentials = Cashback_API_Client::get_instance()->get_credentials((int) $network['id']) ?: array();
                $saved_scope       = $saved_credentials['scope'] ?? '';
            ?>
                <div class="cashback-network-card" data-network-id="<?php echo esc_attr($network['id']); ?>" style="display:none">
                    <h2><?php echo esc_html($network['name']); ?>
                        <span class="slug">(<?php echo esc_html($network['slug']); ?>)</span>
                        <?php if ($network['is_active']) : ?>
                            <span class="status-badge active">Активна</span>
                        <?php else : ?>
                            <span class="status-badge inactive">Неактивна</span>
                        <?php endif; ?>
                    </h2>

                    <table class="form-table">
                        <tr>
                            <th>API Base URL</th>
                            <td>
                                <input type="url" class="regular-text api-field"
                                    name="api_base_url"
                                    value="<?php echo esc_attr($network['api_base_url'] ?? ''); ?>"
                                    placeholder="https://api.admitad.com">
                            </td>
                        </tr>
                        <?php $auth_type = $network['api_auth_type'] ?? 'oauth2'; ?>
                        <tr>
                            <th>Тип авторизации</th>
                            <td>
                                <select class="api-field cashback-auth-type-select" name="api_auth_type">
                                    <option value="oauth2" <?php selected($auth_type, 'oauth2'); ?>>OAuth2 (Client Credentials)</option>
                                    <option value="api_key" <?php selected($auth_type, 'api_key'); ?>>API Key</option>
                                </select>
                            </td>
                        </tr>
                        <tr class="auth-field auth-oauth2" 
                        <?php
                        if ($auth_type === 'api_key') {
echo 'style="display:none"';}
?>
>
                            <th>Token Endpoint</th>
                            <td>
                                <input type="text" class="regular-text api-field"
                                    name="api_token_endpoint"
                                    value="<?php echo esc_attr($network['api_token_endpoint'] ?? ''); ?>"
                                    placeholder="/token/ или полный URL">
                                <p class="description">Относительный путь от Base URL или полный URL (https://...)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Actions Endpoint</th>
                            <td>
                                <input type="text" class="regular-text api-field"
                                    name="api_actions_endpoint"
                                    value="<?php echo esc_attr($network['api_actions_endpoint'] ?? ''); ?>"
                                    placeholder="/statistics/actions/ или полный URL">
                                <p class="description">Если домен отличается от Base URL, укажите полный URL (например https://app.epn.bz/transactions/user)</p>
                            </td>
                        </tr>
                        <tr class="auth-field auth-oauth2" 
                        <?php
                        if ($auth_type === 'api_key') {
echo 'style="display:none"';}
?>
>
                            <th>Client ID</th>
                            <td>
                                <input type="text" class="regular-text api-credential"
                                    name="client_id"
                                    value=""
                                    placeholder="<?php echo !empty($network['api_credentials']) ? '••••••• (сохранён)' : 'Введите Client ID'; ?>"
                                    autocomplete="off">
                                <p class="description">Credentials хранятся зашифрованными (AES-256-GCM)</p>
                            </td>
                        </tr>
                        <tr class="auth-field auth-oauth2" 
                        <?php
                        if ($auth_type === 'api_key') {
echo 'style="display:none"';}
?>
>
                            <th>Client Secret</th>
                            <td>
                                <input type="password" class="regular-text api-credential"
                                    name="client_secret"
                                    value=""
                                    placeholder="<?php echo !empty($network['api_credentials']) ? '••••••• (сохранён)' : 'Введите Client Secret'; ?>"
                                    autocomplete="off">
                            </td>
                        </tr>
                        <tr class="auth-field auth-oauth2" 
                        <?php
                        if ($auth_type === 'api_key') {
echo 'style="display:none"';}
?>
>
                            <th>OAuth2 Scope</th>
                            <td>
                                <input type="text" class="regular-text api-credential"
                                    name="scope"
                                    value="<?php echo esc_attr($saved_scope); ?>"
                                    placeholder="statistics advcampaigns">
                                <p class="description">Admitad: <code>statistics advcampaigns</code>. Все scope через пробел в одном токене.</p>
                            </td>
                        </tr>
                        <tr class="auth-field auth-api-key" 
                        <?php
                        if ($auth_type !== 'api_key') {
echo 'style="display:none"';}
?>
>
                            <th>API Key</th>
                            <td>
                                <input type="password" class="regular-text api-credential"
                                    name="api_key"
                                    value=""
                                    placeholder="<?php echo !empty($network['api_credentials']) ? '••••••• (сохранён)' : 'Введите API Key'; ?>"
                                    autocomplete="off">
                                <p class="description">Credentials хранятся зашифрованными (AES-256-GCM)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Website ID</th>
                            <td>
                                <input type="text" class="regular-text api-field"
                                    name="api_website_id"
                                    value="<?php echo esc_attr($network['api_website_id'] ?? ''); ?>"
                                    placeholder="ID площадки в CPA-сети">
                                <p class="description">Для фильтрации действий по конкретной площадке</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Поле user_id в API</th>
                            <td>
                                <input type="text" class="regular-text api-field"
                                    name="api_user_field"
                                    value="<?php echo esc_attr($network['api_user_field'] ?? ''); ?>"
                                    placeholder="subid">
                                <p class="description">Admitad: <code>subid</code>, EPN: <code>sub</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th>Поле click_id в API</th>
                            <td>
                                <input type="text" class="regular-text api-field"
                                    name="api_click_field"
                                    value="<?php echo esc_attr($network['api_click_field'] ?? ''); ?>"
                                    placeholder="subid1">
                                <p class="description">Admitad: <code>subid1</code>, EPN: <code>click_id</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th>Маппинг статусов</th>
                            <td>
                                <input type="hidden" class="api-field" name="api_status_map"
                                    value="<?php echo esc_attr($network['api_status_map'] ?? ''); ?>">

                                <div class="status-map-header">
                                    <span class="status-map-col-label">Статус CPA-сети</span>
                                    <span class="status-map-arrow-spacer"></span>
                                    <span class="status-map-col-label">Наша система</span>
                                </div>
                                <div class="status-map-editor" data-network-id="<?php echo esc_attr($network['id']); ?>">
                                    <?php
                                    $status_map = json_decode($network['api_status_map'] ?? '', true);
                                    if (!is_array($status_map)) {
                                        $status_map = array();
                                    }
                                    $local_statuses = array( 'waiting', 'hold', 'completed', 'declined' );
                                    foreach ($status_map as $cpa_key => $local_val) :
                                    ?>
                                    <div class="status-map-row">
                                        <input type="text" class="status-map-cpa regular-text"
                                                placeholder="статус CPA" value="<?php echo esc_attr($cpa_key); ?>">
                                        <span class="status-map-arrow">→</span>
                                        <select class="status-map-local">
                                            <?php foreach ($local_statuses as $s) : ?>
                                            <option value="<?php echo esc_attr($s); ?>"<?php selected($local_val, $s); ?>><?php echo esc_html($s); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="status-map-remove button-link">
                                            <span class="dashicons dashicons-no-alt" style="color:#dc3232;"></span>
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <button type="button" class="button status-map-add-btn" style="margin-top:8px;">
                                    + Добавить статус
                                </button>
                                <p class="description">Преобразование статуса заказа из CPA-сети в нашу систему. Допустимые значения: waiting / hold / completed / declined</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Маппинг полей API</th>
                            <td>
                                <input type="hidden" class="api-field" name="api_field_map"
                                    value="<?php echo esc_attr($network['api_field_map'] ?? ''); ?>">

                                <div class="field-map-header">
                                    <span class="field-map-col-label">Поле в API сети</span>
                                    <span class="field-map-arrow-spacer"></span>
                                    <span class="field-map-col-label">Поле в нашей системе</span>
                                </div>
                                <div class="field-map-editor" data-network-id="<?php echo esc_attr($network['id']); ?>">
                                    <?php
                                    $field_map = json_decode($network['api_field_map'] ?? '', true);
                                    if (!is_array($field_map)) {
                                        $field_map = array();
                                    }
                                    $local_columns = array(
                                        'comission'    => 'comission (комиссия)',
                                        'sum_order'    => 'sum_order (сумма заказа)',
                                        'uniq_id'      => 'uniq_id (ID действия)',
                                        'order_number' => 'order_number (номер заказа)',
                                        'offer_id'     => 'offer_id (ID оффера)',
                                        'offer_name'   => 'offer_name (название оффера)',
                                        'currency'     => 'currency (валюта)',
                                        'action_date'  => 'action_date (дата покупки)',
                                        'click_time'   => 'click_time (время клика)',
                                        'action_type'  => 'action_type (тип действия)',
                                        'website_id'   => 'website_id (ID площадки)',
                                        'funds_ready'  => 'funds_ready (готовность к выплате)',
                                    );
                                    $has_rows      = false;
                                    foreach ($field_map as $api_key => $local_col) :
                                        $has_rows = true;
                                    ?>
                                    <div class="field-map-row">
                                        <input type="text" class="field-map-api regular-text"
                                                placeholder="поле API" value="<?php echo esc_attr($api_key); ?>">
                                        <span class="field-map-arrow">→</span>
                                        <select class="field-map-local">
                                            <?php foreach ($local_columns as $col_val => $col_label) : ?>
                                            <option value="<?php echo esc_attr($col_val); ?>"<?php selected($local_col, $col_val); ?>><?php echo esc_html($col_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="field-map-remove button-link">
                                            <span class="dashicons dashicons-no-alt" style="color:#dc3232;"></span>
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if (!$has_rows) : ?>
                                    <div class="field-map-row">
                                        <input type="text" class="field-map-api regular-text" placeholder="поле API" value="">
                                        <span class="field-map-arrow">→</span>
                                        <select class="field-map-local">
                                            <?php foreach ($local_columns as $col_val => $col_label) : ?>
                                            <option value="<?php echo esc_attr($col_val); ?>"><?php echo esc_html($col_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="field-map-remove button-link">
                                            <span class="dashicons dashicons-no-alt" style="color:#dc3232;"></span>
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <button type="button" class="button field-map-add-btn" style="margin-top:8px;">
                                    + Добавить поле
                                </button>
                                <p class="description">Маппинг полей из нормализованного ответа API в колонки таблицы транзакций. Например: <code>payment → comission</code>, <code>cart → sum_order</code></p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="button" class="button button-primary cashback-save-network-btn"
                            data-network-id="<?php echo esc_attr($network['id']); ?>">
                            Сохранить настройки
                        </button>
                        <button type="button" class="button cashback-test-connection-btn"
                            data-network-id="<?php echo esc_attr($network['id']); ?>">
                            Проверить соединение
                        </button>
                        <span class="cashback-save-status"></span>
                    </p>
                </div>
            <?php endforeach; ?>

            <?php if (empty($networks)) : ?>
                <div class="notice notice-warning">
                    <p>Нет партнёрских сетей. Добавьте сети в разделе <a href="?page=cashback-partners">Партнёры</a>.</p>
                </div>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Вкладка «Проверка пользователя»
     */
    private function render_validation_tab(): void {
    ?>
        <div id="cashback-validation-tab">
            <h2>Проверка данных пользователя по API</h2>
            <p class="description">
                Сравнивает транзакции пользователя в локальной БД с данными CPA-сети.
                Инкрементальная проверка — запрашиваются только новые данные с последнего чекпоинта.
            </p>

            <table class="form-table">
                <tr>
                    <th>User ID</th>
                    <td>
                        <input type="number" id="cashback-validate-user-id" class="regular-text"
                            min="0" placeholder="ID пользователя WordPress">
                        <p class="description">0 = проверка незарегистрированных транзакций</p>
                    </td>
                </tr>
                <tr>
                    <th>CPA-сеть</th>
                    <td>
                        <select id="cashback-validate-network">
                            <option value="__all__">— Все сети —</option>
                            <?php
                            $client   = Cashback_API_Client::get_instance();
                            $networks = $client->get_all_active_networks();
                            foreach ($networks as $net) :
                            ?>
                                <option value="<?php echo esc_attr($net['slug']); ?>">
                                    <?php echo esc_html($net['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Полная проверка</th>
                    <td>
                        <label>
                            <input type="checkbox" id="cashback-validate-full">
                            Игнорировать чекпоинт (проверить с самого начала)
                        </label>
                        <p class="description">Медленнее, но перепроверяет все данные</p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" id="cashback-validate-btn" class="button button-primary button-hero">
                    🔍 Проверить пользователя
                </button>
            </p>

            <div id="cashback-validation-result" style="display:none; margin-top: 20px;">
                <!-- Результат валидации подставляется через JS -->
            </div>
        </div>
    <?php
    }

    /**
     * Вкладка «Синхронизация»
     */
    private function render_sync_tab(): void {
        $last_sync = get_option('cashback_last_sync_result', null);
    ?>
        <div id="cashback-sync-tab">
            <h2>Фоновая синхронизация статусов</h2>
            <p class="description">
                Cron каждые 2 часа запрашивает обновлённые статусы транзакций из CPA-сетей
                через <code>status_updated_start</code>. Это страховка от потерянных webhook'ов.
            </p>

            <div class="cashback-sync-info">
                <?php if ($last_sync) : ?>
                    <table class="widefat fixed" style="max-width: 600px;">
                        <thead>
                            <tr>
                                <th colspan="2">Последняя синхронизация: <?php echo esc_html($last_sync['timestamp'] ?? '—'); ?>
                                    (<?php echo esc_html($last_sync['elapsed'] ?? '?'); ?>s)
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (!empty($last_sync['results'])) :
                                foreach ($last_sync['results'] as $net_slug => $res) :
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html(strtoupper($net_slug)); ?></strong></td>
                                        <td>
                                            <?php if (!empty($res['success'])) : ?>
                                                Всего: <?php echo (int) $res['total']; ?>,
                                                обновлено: <strong><?php echo (int) $res['updated']; ?></strong>,
                                                пропущено: <?php echo (int) $res['skipped']; ?>,
                                                не найдено: <?php echo (int) $res['not_found']; ?>
                                            <?php else : ?>
                                                <span style="color:red;">Ошибка: <?php echo esc_html($res['error'] ?? ''); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                            <?php
                            endforeach;
                            endif;
                            ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p>Синхронизация ещё не запускалась.</p>
                <?php endif; ?>
            </div>

            <p style="margin-top: 20px;">
                <button type="button" id="cashback-manual-sync-btn" class="button button-primary">
                    ▶ Запустить синхронизацию сейчас
                </button>
                <span id="cashback-sync-status"></span>
            </p>

            <h3 style="margin-top: 30px;">Лог синхронизации</h3>
            <p>
                <label>Показать за последние:
                    <select id="cashback-sync-log-period">
                        <option value="1">1 день</option>
                        <option value="7" selected>7 дней</option>
                        <option value="30">30 дней</option>
                    </select>
                </label>
                <button type="button" id="cashback-load-sync-log" class="button">Загрузить</button>
            </p>

            <table id="cashback-sync-log-table" class="widefat striped" style="display:none;">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Сеть</th>
                        <th>Транзакция</th>
                        <th>Action ID</th>
                        <th>Статус до</th>
                        <th>Статус после</th>
                        <th>Сумма API</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    <?php
    }

    // =========================================================================
    // AJAX handlers
    // =========================================================================

    /**
     * AJAX: Валидация пользователя
     */
    public function ajax_validate_user(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        // Блокировка во время синхронизации — нельзя проверять пока sync + начисление идут
        if (class_exists('Cashback_Lock') && Cashback_Lock::is_lock_active()) {
            wp_send_json_error(array( 'message' => 'Синхронизация в процессе, повторите позже' ));
        }

        // Rate limiting: максимум 10 запросов валидации в минуту
        $rate_key   = 'cb_api_validate_rate_' . get_current_user_id();
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 10) {
            wp_send_json_error(array( 'message' => 'Слишком много запросов валидации. Подождите минуту.' ));
        }
        set_transient($rate_key, $rate_count + 1, MINUTE_IN_SECONDS);

        $user_id = intval( wp_unslash( $_POST['user_id'] ?? -1 ) );
        $network = isset($_POST['network']) ? sanitize_text_field(wp_unslash($_POST['network'])) : 'admitad';
        $full    = !empty($_POST['full_check']);

        if ($user_id < 0) {
            wp_send_json_error(array( 'message' => 'Укажите корректный User ID' ));
        }

        $client = Cashback_API_Client::get_instance();

        // Проверка по всем сетям
        if ($network === '__all__') {
            if ($user_id > 0) {
                $user = get_user_by('id', $user_id);
                if (!$user) {
                    wp_send_json_error(array( 'message' => "Пользователь #{$user_id} не найден" ));
                }
            }

            $all_networks = $client->get_all_active_networks();
            if (empty($all_networks)) {
                wp_send_json_error(array( 'message' => 'Нет активных сетей с настроенным API' ));
            }

            $result = $this->validate_all_networks($client, $user_id, $all_networks, !$full);

            $this->log_audit('api_validation', $user_id, $result);
            wp_send_json_success($result);
        }

        if ($user_id === 0) {
            // Проверка незарегистрированных транзакций
            $result = $client->validate_unregistered($network, !$full);
        } else {
            // Проверяем существование пользователя
            $user = get_user_by('id', $user_id);
            if (!$user) {
                wp_send_json_error(array( 'message' => "Пользователь #{$user_id} не найден" ));
            }
            $result = $client->validate_user($user_id, $network, !$full);
        }

        // Логируем в аудит
        $this->log_audit('api_validation', $user_id, $result);

        wp_send_json_success($result);
    }

    /**
     * Валидация пользователя по всем активным сетям с агрегацией результатов.
     *
     * @param Cashback_API_Client $client         API-клиент.
     * @param int                 $user_id        ID пользователя (0 = незарегистрированные).
     * @param array               $networks       Массив активных сетей.
     * @param bool                $use_checkpoint  Использовать чекпоинт.
     * @return array Агрегированный результат.
     */
    private function validate_all_networks( Cashback_API_Client $client, int $user_id, array $networks, bool $use_checkpoint ): array {
        $per_network   = array();
        $network_names = array();
        $errors        = array();

        $totals = array(
            'api_total'      => 0,
            'local_total'    => 0,
            'matched_count'  => 0,
            'mismatch_count' => 0,
            'missing_local'  => array(),
            'missing_api'    => array(),
            'mismatched'     => array(),
            'sums'           => array(
                'api_approved'   => 0,
                'api_pending'    => 0,
                'api_declined'   => 0,
                'local_approved' => 0,
                'local_pending'  => 0,
                'local_declined' => 0,
                'discrepancy'    => 0,
            ),
        );

        foreach ($networks as $net) {
            $slug            = $net['slug'];
            $network_names[] = $net['name'];

            if ($user_id === 0) {
                $result = $client->validate_unregistered($slug, $use_checkpoint);
            } else {
                $result = $client->validate_user($user_id, $slug, $use_checkpoint);
            }

            // Пропускаем сети с ошибками (нет credentials и т.д.)
            if (!empty($result['error'])) {
                $errors[ $slug ] = $result['error'];
                continue;
            }

            $per_network[ $slug ] = $result;

            // Агрегация счётчиков
            $totals['api_total']      += $result['api_total'] ?? 0;
            $totals['local_total']    += $result['local_total'] ?? 0;
            $totals['matched_count']  += $result['matched_count'] ?? 0;
            $totals['mismatch_count'] += $result['mismatch_count'] ?? 0;

            // Агрегация сумм
            if (!empty($result['sums'])) {
                $totals['sums']['api_approved']   += $result['sums']['api_approved'] ?? 0;
                $totals['sums']['api_pending']    += $result['sums']['api_pending'] ?? 0;
                $totals['sums']['api_declined']   += $result['sums']['api_declined'] ?? 0;
                $totals['sums']['local_approved'] += $result['sums']['local_approved'] ?? 0;
                $totals['sums']['local_pending']  += $result['sums']['local_pending'] ?? 0;
                $totals['sums']['local_declined'] += $result['sums']['local_declined'] ?? 0;
            }

            // Объединение массивов расхождений с добавлением поля network
            foreach ($result['mismatched'] ?? array() as $item) {
                $item['network']        = $slug;
                $totals['mismatched'][] = $item;
            }
            foreach ($result['missing_local'] ?? array() as $item) {
                $item['network']           = $slug;
                $totals['missing_local'][] = $item;
            }
            foreach ($result['missing_api'] ?? array() as $item) {
                $item['network']         = $slug;
                $totals['missing_api'][] = $item;
            }
        }

        // Итоговое расхождение
        $totals['sums']['discrepancy'] = abs($totals['sums']['api_approved'] - $totals['sums']['local_approved']);

        // Общий статус
        $has_issues = $totals['mismatch_count'] > 0
            || !empty($totals['missing_local'])
            || !empty($totals['missing_api']);

        return array(
            'user_id'       => $user_id,
            'network'       => '__all__',
            'multi_network' => true,
            'network_names' => $network_names,
            'status'        => $has_issues ? 'mismatch' : 'match',
            'networks'      => $per_network,
            'errors'        => $errors,
            'totals'        => $totals,
        );
    }

    /**
     * AJAX: Сохранение API credentials
     */
    public function ajax_save_credentials(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        global $wpdb;

        $network_id = absint( wp_unslash( $_POST['network_id'] ?? 0 ) );
        if ($network_id < 1) {
            wp_send_json_error(array( 'message' => 'Неверный ID сети' ));
        }

        // Обновляем обычные поля
        $auth_type = isset($_POST['api_auth_type']) ? sanitize_text_field(wp_unslash($_POST['api_auth_type'])) : 'oauth2';
        if (!in_array($auth_type, array( 'oauth2', 'api_key' ), true)) {
            $auth_type = 'oauth2';
        }

        $fields = array(
            'api_base_url'         => isset($_POST['api_base_url']) ? sanitize_text_field(wp_unslash($_POST['api_base_url'])) : '',
            'api_auth_type'        => $auth_type,
            'api_token_endpoint'   => isset($_POST['api_token_endpoint']) ? sanitize_text_field(wp_unslash($_POST['api_token_endpoint'])) : '',
            'api_actions_endpoint' => isset($_POST['api_actions_endpoint']) ? sanitize_text_field(wp_unslash($_POST['api_actions_endpoint'])) : '',
            'api_user_field'       => isset($_POST['api_user_field']) ? sanitize_text_field(wp_unslash($_POST['api_user_field'])) : '',
            'api_click_field'      => isset($_POST['api_click_field']) ? sanitize_text_field(wp_unslash($_POST['api_click_field'])) : '',
            'api_website_id'       => isset($_POST['api_website_id']) ? sanitize_text_field(wp_unslash($_POST['api_website_id'])) : '',
        );

        // Валидация маппинга статусов (должен быть валидный JSON)
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload, validated via json_decode + json_last_error check below; sanitize_text_field would corrupt JSON content.
        $status_map_raw = wp_unslash($_POST['api_status_map'] ?? '');
        if (!empty($status_map_raw)) {
            $decoded = json_decode($status_map_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error(array( 'message' => 'Маппинг статусов: невалидный JSON — ' . json_last_error_msg() ));
            }
            $fields['api_status_map'] = wp_json_encode($decoded);
        }

        // Валидация маппинга полей (должен быть валидный JSON)
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload, validated via json_decode + json_last_error check below; sanitize_text_field would corrupt JSON content.
        $field_map_raw = wp_unslash($_POST['api_field_map'] ?? '');
        if (!empty($field_map_raw)) {
            $decoded_fm = json_decode($field_map_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error(array( 'message' => 'Маппинг полей: невалидный JSON — ' . json_last_error_msg() ));
            }
            $fields['api_field_map'] = wp_json_encode($decoded_fm);
        }

        $wpdb->update(
            $wpdb->prefix . 'cashback_affiliate_networks',
            $fields,
            array( 'id' => $network_id )
        );

        if ($wpdb->last_error) {
            wp_send_json_error(array( 'message' => 'Ошибка сохранения: ' . $wpdb->last_error ));
        }

        // Сохраняем credentials если указаны новые
        $client              = Cashback_API_Client::get_instance();
        $existing            = $client->get_credentials($network_id) ?: array();
        $credentials_changed = false;

        if ($auth_type === 'api_key') {
            $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
            if (!empty($api_key) && !str_starts_with($api_key, '•')) {
                $existing['api_key'] = $api_key;
                $credentials_changed = true;
            }
        } else {
            $client_id     = isset($_POST['client_id']) ? sanitize_text_field(wp_unslash($_POST['client_id'])) : '';
            $client_secret = isset($_POST['client_secret']) ? sanitize_text_field(wp_unslash($_POST['client_secret'])) : '';

            if (!empty($client_id) && !str_starts_with($client_id, '•')) {
                $existing['client_id'] = $client_id;
                $credentials_changed   = true;
            }
            if (!empty($client_secret) && !str_starts_with($client_secret, '•')) {
                $existing['client_secret'] = $client_secret;
                $credentials_changed       = true;
            }
            if (!empty($_POST['scope'])) {
                $existing['scope']   = sanitize_text_field(wp_unslash($_POST['scope']));
                $credentials_changed = true;
            }
        }

        if ($credentials_changed) {
            $saved = $client->save_credentials($network_id, $existing);

            if (!$saved) {
                wp_send_json_error(array( 'message' => 'Настройки сохранены, но credentials не удалось зашифровать. Проверьте CB_ENCRYPTION_KEY.' ));
            }

            // Инвалидируем кеш токена, чтобы новый scope/credentials вступили в силу
            $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';
            $slug           = $wpdb->get_var($wpdb->prepare(
                'SELECT slug FROM %i WHERE id = %d',
                $networks_table,
                $network_id
            ));
            if ($slug) {
                $adapter = $client->get_adapter($slug);
                if ($adapter) {
                    $adapter->invalidate_token($existing);
                }
            }
        }

        // Аудит
        $this->log_audit('api_credentials_updated', 0, array( 'network_id' => $network_id ));

        wp_send_json_success(array( 'message' => 'Настройки сохранены' ));
    }

    /**
     * AJAX: Проверка подключения к API CPA-сети (OAuth2 токен)
     */
    public function ajax_test_connection(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        $network_id = absint( wp_unslash( $_POST['network_id'] ?? 0 ) );
        if ($network_id < 1) {
            wp_send_json_error(array( 'message' => 'Неверный ID сети' ));
        }

        $client = Cashback_API_Client::get_instance();
        $result = $client->test_connection($network_id);

        if ($result['success']) {
            wp_send_json_success(array( 'message' => $result['message'] ));
        } else {
            wp_send_json_error(array( 'message' => $result['message'] ));
        }
    }

    /**
     * AJAX: Ручной запуск синхронизации
     */
    public function ajax_manual_sync(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        // Rate limiting: максимум 3 синхронизации за 5 минут
        $rate_key   = 'cb_api_sync_rate_' . get_current_user_id();
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 3) {
            wp_send_json_error(array( 'message' => 'Синхронизация уже выполнялась недавно. Подождите 5 минут.' ));
        }
        set_transient($rate_key, $rate_count + 1, 5 * MINUTE_IN_SECONDS);

        $result = Cashback_API_Cron::manual_sync();

        if (!empty($result['locked'])) {
            wp_send_json_error(array( 'message' => 'Синхронизация уже выполняется. Попробуйте через несколько секунд.' ));
        }

        $this->log_audit('manual_sync', 0, $result);

        wp_send_json_success($result);
    }

    /**
     * AJAX: Получить лог синхронизации
     */
    public function ajax_get_sync_log(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        global $wpdb;

        $days = absint( wp_unslash( $_POST['days'] ?? 7 ) );
        $days = max(1, min($days, 90));

        $sync_log_table = $wpdb->prefix . 'cashback_sync_log';
        $tx_table       = $wpdb->prefix . 'cashback_transactions';
        $rows           = $wpdb->get_results($wpdb->prepare(
            'SELECT sl.*, ct.user_id, ct.order_number
             FROM %i sl
             LEFT JOIN %i ct ON sl.transaction_id = ct.id
             WHERE sl.synced_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             ORDER BY sl.synced_at DESC
             LIMIT 500',
            $sync_log_table,
            $tx_table,
            $days
        ), ARRAY_A);

        wp_send_json_success(array( 'log' => $rows ?: array() ));
    }

    /**
     * AJAX: Получить статус валидации для кнопки на странице выплат
     *
     * Возвращает последний чекпоинт для пользователя.
     */
    public function ajax_get_validation_status(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        $user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );

        if ($user_id < 1) {
            wp_send_json_error(array( 'message' => 'Неверный user_id' ));
        }

        global $wpdb;

        $checkpoints_table = $wpdb->prefix . 'cashback_validation_checkpoints';
        $checkpoints       = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE user_id = %d',
            $checkpoints_table,
            $user_id
        ), ARRAY_A);

        wp_send_json_success(array( 'checkpoints' => $checkpoints ?: array() ));
    }

    // =========================================================================
    // AJAX: Действия из таблиц валидации
    // =========================================================================

    /**
     * AJAX: Редактирование транзакции (таблица «Есть на сайте, нет в API»)
     */
    public function ajax_edit_transaction(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        global $wpdb;

        $transaction_id  = absint($_POST['transaction_id'] ?? 0);
        $order_status    = isset($_POST['order_status']) ? sanitize_text_field(wp_unslash($_POST['order_status'])) : '';
        $comission       = floatval($_POST['comission'] ?? 0);
        $sum_order       = floatval($_POST['sum_order'] ?? 0);
        $is_unregistered = intval( wp_unslash( $_POST['user_id'] ?? -1 ) ) === 0;

        if ($transaction_id < 1) {
            wp_send_json_error(array( 'message' => 'Неверный ID транзакции' ));
        }

        $allowed_statuses = array( 'waiting', 'completed', 'declined', 'hold' );
        if (!in_array($order_status, $allowed_statuses, true)) {
            wp_send_json_error(array( 'message' => 'Недопустимый статус: ' . $order_status ));
        }

        if ($comission < 0) {
            wp_send_json_error(array( 'message' => 'Комиссия не может быть отрицательной' ));
        }

        $table = $wpdb->prefix . ( $is_unregistered ? 'cashback_unregistered_transactions' : 'cashback_transactions' );

        // Проверяем существование и текущий статус.
        $current = $wpdb->get_row($wpdb->prepare(
            'SELECT id, order_status, comission, applied_cashback_rate FROM %i WHERE id = %d',
            $table,
            $transaction_id
        ));

        if (!$current) {
            wp_send_json_error(array( 'message' => 'Транзакция не найдена' ));
        }

        // PHP-фолбэк: валидация перехода статуса
        $validation = Cashback_Trigger_Fallbacks::validate_status_transition($current->order_status, $order_status);
        if ($validation !== true) {
            wp_send_json_error(array( 'message' => $validation ));
        }

        $update_data    = array(
            'order_status' => $order_status,
            'comission'    => $comission,
            'sum_order'    => $sum_order,
        );
        $update_formats = array( '%s', '%f', '%f' );

        // PHP-фолбэк: пересчёт кешбэка при изменении комиссии
        Cashback_Trigger_Fallbacks::recalculate_cashback_on_update($update_data, $current, !$is_unregistered);
        if (isset($update_data['cashback'])) {
            $update_formats[] = '%f';
        }

        $updated = $wpdb->update(
            $table,
            $update_data,
            array( 'id' => $transaction_id ),
            $update_formats,
            array( '%d' )
        );

        if ($updated === false || $wpdb->last_error) {
            wp_send_json_error(array( 'message' => 'Ошибка обновления: ' . $wpdb->last_error ));
        }

        $this->log_audit('edit_transaction', $transaction_id, array(
            'order_status' => $order_status,
            'comission'    => $comission,
            'sum_order'    => $sum_order,
        ));

        wp_send_json_success(array( 'message' => 'Транзакция обновлена' ));
    }

    /**
     * AJAX: Добавление транзакции из API (таблица «Есть в API, нет на сайте»)
     */
    public function ajax_add_transaction(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        global $wpdb;

        $user_id     = isset($_POST['user_id']) ? sanitize_text_field(wp_unslash($_POST['user_id'])) : '';
        $network     = isset($_POST['network']) ? sanitize_text_field(wp_unslash($_POST['network'])) : '';
        $action_id   = isset($_POST['action_id']) ? sanitize_text_field(wp_unslash($_POST['action_id'])) : '';
        $click_id    = isset($_POST['click_id']) ? sanitize_text_field(wp_unslash($_POST['click_id'])) : '';
        $order_id    = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : '';
        $status      = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
        $payment     = floatval($_POST['payment'] ?? 0);
        $cart        = floatval($_POST['cart'] ?? 0);
        $date        = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        $campaign    = isset($_POST['campaign']) ? sanitize_text_field(wp_unslash($_POST['campaign'])) : '';
        $campaign_id = isset($_POST['campaign_id']) ? sanitize_text_field(wp_unslash($_POST['campaign_id'])) : '';
        $currency    = isset($_POST['currency']) ? sanitize_text_field(wp_unslash($_POST['currency'])) : 'RUB';
        $click_time  = isset($_POST['click_time']) ? sanitize_text_field(wp_unslash($_POST['click_time'])) : '';
        $action_type = isset($_POST['action_type']) ? sanitize_text_field(wp_unslash($_POST['action_type'])) : '';
        $website_id  = isset($_POST['website_id']) ? sanitize_text_field(wp_unslash($_POST['website_id'])) : '';
        $funds_ready = absint( wp_unslash( $_POST['funds_ready'] ?? 0 ) );

        if (( $user_id === '' ) || empty($network) || empty($action_id)) {
            wp_send_json_error(array( 'message' => 'Обязательные поля: user_id, network, action_id' ));
        }

        // Маппинг статуса API → локальный через конфиг сети
        $client         = Cashback_API_Client::get_instance();
        $network_config = $client->get_network_config($network);
        $status_map     = $network_config['status_map'] ?? array();
        $mapped_status  = $status_map[ strtolower($status) ] ?? 'waiting';

        // Конвертация дат в MySQL DATETIME формат (Y-m-d H:i:s)
        $action_date_mysql = $this->parse_api_date($date);
        $click_time_mysql  = $this->parse_api_date($click_time);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log(sprintf(
                '[Cashback API Add TX] POST: date=%s, click_time=%s, website_id=%s | Parsed: action_date=%s, click_time=%s',
                isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '(empty)',
                isset($_POST['click_time']) ? sanitize_text_field(wp_unslash($_POST['click_time'])) : '(empty)',
                isset($_POST['website_id']) ? sanitize_text_field(wp_unslash($_POST['website_id'])) : '(empty)',
                $action_date_mysql ?? 'NULL',
                $click_time_mysql ?? 'NULL'
            ));
        }

        // Валидация currency (ISO 4217 — 3 заглавные буквы)
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'RUB';
        }

        // Генерация idempotency_key (детерминистический — один action_id+network = один ключ)
        $idempotency_key = hash('sha256', 'api_add_' . $action_id . '_' . $network);

        // Разрешение partner_token → user_id (subid может содержать токен вместо числового ID)
        if ($user_id !== 'unregistered' && !is_numeric($user_id) && preg_match('/^[0-9a-f]{32}$/', $user_id)) {
            $resolved = Mariadb_Plugin::resolve_partner_token($user_id);
            if ($resolved !== null) {
                $user_id = (string) $resolved;
            }
        }

        // Определение таблицы
        $is_unregistered = $user_id === 'unregistered' || !is_numeric($user_id) || (int) $user_id === 0;
        $table           = $wpdb->prefix . ( $is_unregistered ? 'cashback_unregistered_transactions' : 'cashback_transactions' );

        $data = array(
            'user_id'         => $is_unregistered ? $user_id : (int) $user_id,
            'uniq_id'         => $action_id,
            'order_number'    => $order_id,
            'partner'         => $network_config['name'] ?? $network,
            'comission'       => $payment,
            'sum_order'       => $cart,
            'order_status'    => $mapped_status,
            'offer_id'        => $campaign_id !== '' ? (int) $campaign_id : null,
            'offer_name'      => $campaign,
            'currency'        => $currency,
            'action_date'     => $action_date_mysql,
            'click_time'      => $click_time_mysql,
            'click_id'        => $click_id ?: null,
            'website_id'      => $website_id !== '' ? (int) $website_id : null,
            'action_type'     => $action_type ?: null,
            'api_verified'    => 1,
            'funds_ready'     => $funds_ready,
            'idempotency_key' => $idempotency_key,
        );

        $formats = array(
            $is_unregistered ? '%s' : '%d',  // user_id
            '%s',  // uniq_id
            '%s',  // order_number
            '%s',  // partner
            '%f',  // comission
            '%f',  // sum_order
            '%s',  // order_status
            '%d',  // offer_id
            '%s',  // offer_name
            '%s',  // currency
            '%s',  // action_date
            '%s',  // click_time
            '%s',  // click_id
            '%d',  // website_id
            '%s',  // action_type
            '%d',  // api_verified
            '%d',  // funds_ready
            '%s',  // idempotency_key
        );

        // PHP-фолбэк расчёта кешбэка
        Cashback_Trigger_Fallbacks::calculate_cashback($data, !$is_unregistered);
        $formats[] = '%f'; // applied_cashback_rate
        $formats[] = '%f'; // cashback

        // Удаляем NULL-значения и их форматы, чтобы $wpdb->insert корректно работал
        $clean_data    = array();
        $clean_formats = array();
        $i             = 0;
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $clean_data[ $key ] = $value;
                $clean_formats[]    = $formats[ $i ];
            }
            ++$i;
        }

        $inserted = $wpdb->insert($table, $clean_data, $clean_formats);

        if ($inserted === false || $wpdb->last_error) {
            $error = $wpdb->last_error;
            if (strpos($error, 'Duplicate') !== false) {
                wp_send_json_error(array( 'message' => 'Транзакция уже существует (дубликат uniq_id/partner)' ));
            }
            wp_send_json_error(array( 'message' => 'Ошибка вставки: ' . $error ));
        }

        $insert_id = $wpdb->insert_id;

        $this->log_audit('add_transaction', $insert_id, array(
            'user_id'   => $user_id,
            'network'   => $network,
            'action_id' => $action_id,
            'table'     => $is_unregistered ? 'unregistered' : 'transactions',
        ));

        wp_send_json_success(array(
            'message'   => 'Транзакция добавлена',
            'insert_id' => $insert_id,
        ));
    }

    /**
     * AJAX: Перезапись транзакции данными API (таблица «Расхождения»)
     */
    public function ajax_overwrite_transaction(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        global $wpdb;

        $local_id        = absint($_POST['local_id'] ?? 0);
        $network         = isset($_POST['network']) ? sanitize_text_field(wp_unslash($_POST['network'])) : '';
        $api_status      = isset($_POST['api_status']) ? sanitize_text_field(wp_unslash($_POST['api_status'])) : '';
        $api_payment     = floatval($_POST['api_payment'] ?? 0);
        $api_cart        = floatval($_POST['api_cart'] ?? 0);
        $is_unregistered = intval( wp_unslash( $_POST['user_id'] ?? -1 ) ) === 0;

        if ($local_id < 1 || empty($network)) {
            wp_send_json_error(array( 'message' => 'Неверные параметры' ));
        }

        $table = $wpdb->prefix . ( $is_unregistered ? 'cashback_unregistered_transactions' : 'cashback_transactions' );

        // Проверяем существование и текущий статус.
        $current = $wpdb->get_row($wpdb->prepare(
            'SELECT id, order_status, comission, sum_order, applied_cashback_rate FROM %i WHERE id = %d',
            $table,
            $local_id
        ));

        if (!$current) {
            wp_send_json_error(array( 'message' => 'Транзакция не найдена' ));
        }

        // Маппинг статуса API → локальный
        $client         = Cashback_API_Client::get_instance();
        $network_config = $client->get_network_config($network);
        $status_map     = $network_config['status_map'] ?? array();
        $mapped_status  = $status_map[ strtolower($api_status) ] ?? 'waiting';

        // PHP-фолбэк: валидация перехода статуса
        $validation = Cashback_Trigger_Fallbacks::validate_status_transition($current->order_status, $mapped_status);
        if ($validation !== true) {
            wp_send_json_error(array( 'message' => $validation ));
        }

        $update_data    = array(
            'order_status' => $mapped_status,
            'comission'    => $api_payment,
            'sum_order'    => $api_cart,
            'api_verified' => 1,
        );
        $update_formats = array( '%s', '%f', '%f', '%d' );

        // PHP-фолбэк: пересчёт кешбэка при изменении комиссии
        Cashback_Trigger_Fallbacks::recalculate_cashback_on_update($update_data, $current, !$is_unregistered);
        if (isset($update_data['cashback'])) {
            $update_formats[] = '%f';
        }

        $updated = $wpdb->update(
            $table,
            $update_data,
            array( 'id' => $local_id ),
            $update_formats,
            array( '%d' )
        );

        if ($updated === false || $wpdb->last_error) {
            wp_send_json_error(array( 'message' => 'Ошибка обновления: ' . $wpdb->last_error ));
        }

        $this->log_audit('overwrite_transaction', $local_id, array(
            'old_status'    => $current->order_status,
            'new_status'    => $mapped_status,
            'old_comission' => $current->comission,
            'new_comission' => $api_payment,
            'old_sum_order' => $current->sum_order,
            'new_sum_order' => $api_cart,
        ));

        wp_send_json_success(array( 'message' => 'Транзакция перезаписана данными API' ));
    }

    // =========================================================================
    // Кнопка валидации для страницы выплат (payouts)
    // =========================================================================

    /**
     * HTML кнопки «Проверить» для встраивания в строку заявки на выплату
     *
     * Вызывается из шаблона выплат, например:
     * Cashback_Admin_API_Validation::get_instance()->render_validate_button($user_id);
     *
     * @param int $user_id
     */
    public function render_validate_button( int $user_id ): void {
    ?>
        <button type="button"
            class="button cashback-inline-validate-btn"
            data-user-id="<?php echo esc_attr((string) $user_id); ?>"
            title="Проверить данные через API CPA-сети">
            🔍 Проверить
        </button>
        <span class="cashback-inline-validate-status" data-user-id="<?php echo esc_attr((string) $user_id); ?>"></span>
<?php
    }

    // =========================================================================
    // Audit
    // =========================================================================

    /**
     * Записать в аудит-лог
     */
    private function log_audit( string $action, int $entity_id, $details ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cashback_audit_log',
            array(
                'action'      => 'api_validation.' . $action,
                'actor_id'    => get_current_user_id(),
                'entity_type' => 'user',
                'entity_id'   => $entity_id,
                'ip_address'  => $this->get_client_ip(),
                'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
                'details'     => wp_json_encode($details),
            )
        );
    }

    /**
     * Конвертация даты из API в MySQL DATETIME формат
     *
     * Поддерживает форматы Admitad/EPN:
     * - "2024-01-15T10:30:00"  (ISO 8601)
     * - "2024-01-15 10:30:00"  (MySQL-like)
     * - "15.01.2024 10:30:00"  (RU формат)
     * - "15.01.2024"           (только дата)
     * - "2024-01-15"           (ISO дата)
     *
     * @param string $date_str Дата из API
     * @return string|null MySQL DATETIME или null
     */
    private function parse_api_date( string $date_str ): ?string {
        $date_str = trim($date_str);
        if ($date_str === '') {
            return null;
        }

        // Unix timestamp (10 цифр = секунды, 13 цифр = миллисекунды)
        if (preg_match('/^\d{10,13}$/', $date_str)) {
            $timestamp = (int) $date_str;
            if (strlen($date_str) === 13) {
                $timestamp = (int) ( $timestamp / 1000 );
            }
            $dt = new DateTime();
            $dt->setTimestamp($timestamp);
            $dt->setTimezone(new DateTimeZone(wp_timezone_string()));
            return $dt->format('Y-m-d H:i:s');
        }

        // ISO 8601 с T-разделителем: "2024-01-15T10:30:00"
        $date_str = str_replace('T', ' ', $date_str);

        // Убираем таймзону: "+03:00", " 03:00" (+ → пробел после URL encoding), "Z"
        $date_str = preg_replace('/[+-]\d{2}:\d{2}$/', '', $date_str);
        $date_str = preg_replace('/\s+\d{2}:\d{2}$/', '', $date_str);
        $date_str = rtrim($date_str, 'Z');

        $formats = array(
            'Y-m-d H:i:s',  // 2024-01-15 10:30:00
            'Y-m-d H:i',    // 2024-01-15 10:30
            'Y-m-d',         // 2024-01-15
            'd.m.Y H:i:s',  // 15.01.2024 10:30:00
            'd.m.Y H:i',    // 15.01.2024 10:30
            'd.m.Y',         // 15.01.2024
        );

        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $date_str);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /**
     * IP клиента
     */
    private function get_client_ip(): string {
        $headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
        foreach ($headers as $header) {
            if (!empty($_SERVER[ $header ])) {
                $ip = explode(',', sanitize_text_field(wp_unslash($_SERVER[ $header ])))[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    // =========================================================================
    // Вкладка «Статус кампаний»
    // =========================================================================

    /**
     * Рендер вкладки «Статус кампаний»
     */
    private function render_campaigns_tab(): void {
        global $wpdb;

        $networks = $wpdb->get_results(
            "SELECT id, name, slug, is_active FROM {$wpdb->prefix}cashback_affiliate_networks WHERE is_active = 1 ORDER BY sort_order, name",
            ARRAY_A
        ) ?: array();

        // Деактивированные товары
        $deactivated_products = $wpdb->get_results(
            "SELECT p.ID, p.post_title,
                    pm_reason.meta_value AS reason,
                    pm_at.meta_value AS deactivated_at,
                    pm_net.meta_value AS network_slug,
                    pm_offer.meta_value AS offer_id
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_deact ON p.ID = pm_deact.post_id AND pm_deact.meta_key = '_cashback_auto_deactivated' AND pm_deact.meta_value = '1'
             LEFT JOIN {$wpdb->postmeta} pm_reason ON p.ID = pm_reason.post_id AND pm_reason.meta_key = '_cashback_deactivation_reason'
             LEFT JOIN {$wpdb->postmeta} pm_at ON p.ID = pm_at.post_id AND pm_at.meta_key = '_cashback_deactivated_at'
             LEFT JOIN {$wpdb->postmeta} pm_net ON p.ID = pm_net.post_id AND pm_net.meta_key = '_cashback_deactivated_network'
             LEFT JOIN {$wpdb->postmeta} pm_offer ON p.ID = pm_offer.post_id AND pm_offer.meta_key = '_offer_id'
             WHERE p.post_type = 'product'
             ORDER BY pm_at.meta_value DESC
             LIMIT 100",
            ARRAY_A
        ) ?: array();
        ?>

        <div id="cashback-campaigns-tab">
            <h2>Проверка статусов кампаний</h2>
            <p class="description">
                Автоматическая проверка выполняется каждые 2 часа вместе с синхронизацией транзакций.
                При деактивации кампании в CPA-сети соответствующий товар переводится в черновик.
            </p>

            <p>
                <select id="cashback-check-network-select" style="vertical-align: middle;">
                    <option value="">Все сети</option>
                    <?php foreach ($networks as $net) : ?>
                        <option value="<?php echo esc_attr($net['slug']); ?>">
                            <?php echo esc_html($net['name']); ?> (<?php echo esc_html($net['slug']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="cashback-check-campaigns-btn" class="button button-primary" style="vertical-align: middle;">
                    Проверить сейчас
                </button>
                <span id="cashback-check-campaigns-status" style="margin-left: 10px;"></span>
            </p>

            <?php // Последняя проверка ?>
            <?php $last_sync = get_option('cashback_last_sync_result', array()); ?>
            <?php if (!empty($last_sync['campaign_check'])) : ?>
                <div class="notice notice-info inline" style="margin: 10px 0;">
                    <p><strong>Последняя проверка:</strong> <?php echo esc_html($last_sync['timestamp'] ?? '—'); ?></p>
                    <?php foreach ($last_sync['campaign_check'] as $net => $cr) : ?>
                        <p>
                            <strong><?php echo esc_html(strtoupper($net)); ?>:</strong>
                            <?php if ($cr['success'] ?? false) : ?>
                                кампаний: <?php echo (int) $cr['total_campaigns']; ?>,
                                деактивировано: <?php echo (int) ( $cr['deactivated'] ?? 0 ); ?>,
                                реактивировано: <?php echo (int) ( $cr['reactivated'] ?? 0 ); ?>,
                                пропущено: <?php echo (int) ( $cr['skipped'] ?? 0 ); ?>
                            <?php else : ?>
                                <span style="color: #d63638;">Ошибка: <?php echo esc_html($cr['error'] ?? ''); ?></span>
                            <?php endif; ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php
            // Собираем все кампании со всех сетей в единый массив
            $all_campaigns = array();
            $network_stats = array();
            foreach ($networks as $network) {
                $slug          = $network['slug'];
                $campaign_data = get_option("cashback_campaign_status_{$slug}", array());
                if (empty($campaign_data['campaigns'])) {
                    continue;
                }
                foreach ($campaign_data['campaigns'] as $c) {
                    $c['network_name'] = $network['name'];
                    $c['network_slug'] = $slug;
                    $all_campaigns[]   = $c;
                }
                $network_stats[ $slug ] = array(
                    'name'      => $network['name'],
                    'timestamp' => $campaign_data['timestamp'] ?? '',
                    'total'     => (int) ( $campaign_data['total'] ?? 0 ),
                    'active'    => (int) ( $campaign_data['active'] ?? 0 ),
                    'inactive'  => (int) ( $campaign_data['inactive'] ?? 0 ),
                );
            }
            ?>
            <script>
            window.cashbackCampaignsData       = <?php echo wp_json_encode($all_campaigns); ?>;
            window.cashbackCampaignsNetworkStats = <?php echo wp_json_encode($network_stats); ?>;
            </script>

            <div id="cashback-campaigns-search-row">
                <input type="text" id="cashback-campaigns-search" placeholder="Поиск по названию кампании...">
                <button type="button" id="cashback-campaigns-search-btn" class="button">Найти</button>
                <button type="button" id="cashback-campaigns-reset-btn" class="button">Сбросить</button>
            </div>

            <div id="cashback-campaigns-net-stats"></div>

            <div id="cashback-campaigns-columns">
                <div id="cashback-campaigns-active-col">
                    <h3>Активные кампании <span id="cashback-active-count"></span></h3>
                    <div id="cashback-campaigns-active-table"></div>
                </div>
                <div id="cashback-campaigns-inactive-col">
                    <h3>Неактивные кампании <span id="cashback-inactive-count"></span></h3>
                    <div id="cashback-campaigns-inactive-table"></div>
                </div>
            </div>

            <?php // Деактивированные товары ?>
            <h3>Деактивированные магазины</h3>
            <?php if (empty($deactivated_products)) : ?>
                <p class="description">Нет автоматически деактивированных магазинов.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Магазин</th>
                            <th>Offer ID</th>
                            <th>Сеть</th>
                            <th>Дата</th>
                            <th>Причина</th>
                            <th>Действие</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deactivated_products as $product) : ?>
                            <tr id="deact-row-<?php echo (int) $product['ID']; ?>">
                                <td><?php echo (int) $product['ID']; ?></td>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $product['ID'])); ?>">
                                        <?php echo esc_html($product['post_title']); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($product['offer_id'] ?? '—'); ?></td>
                                <td><?php echo esc_html(strtoupper($product['network_slug'] ?? '')); ?></td>
                                <td><?php echo esc_html($product['deactivated_at'] ?? '—'); ?></td>
                                <td><?php echo esc_html($product['reason'] ?? '—'); ?></td>
                                <td>
                                    <button type="button"
                                            class="button button-small cashback-reactivate-btn"
                                            data-product-id="<?php echo (int) $product['ID']; ?>">
                                        Реактивировать
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($) {
            // ─── Проверить кампании сейчас (с выбором сети) ───
            $('#cashback-check-campaigns-btn').on('click', function() {
                var $btn = $(this);
                var $status = $('#cashback-check-campaigns-status');
                var network = $('#cashback-check-network-select').val();
                $btn.prop('disabled', true);
                $status.text('Проверка...');

                $.post(ajaxurl, {
                    action: 'cashback_check_campaigns_now',
                    nonce: '<?php echo esc_attr( wp_create_nonce('cashback_api_validation') ); ?>',
                    network: network
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        var msg = 'Готово.';
                        var data = response.data;
                        for (var net in data) {
                            if (data[net].success) {
                                msg += ' ' + net.toUpperCase() + ': деакт=' + (data[net].deactivated || 0)
                                    + ', реакт=' + (data[net].reactivated || 0);
                            } else {
                                msg += ' ' + net.toUpperCase() + ': ошибка';
                            }
                        }
                        $status.text(msg);
                        setTimeout(function() { location.reload(); }, 2000);
                    } else {
                        $status.text('Ошибка: ' + (response.data || 'Unknown'));
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $status.text('Ошибка сети');
                });
            });

            // ─── Реактивация товара ───
            $('.cashback-reactivate-btn').on('click', function() {
                var $btn = $(this);
                var productId = $btn.data('product-id');
                if (!confirm('Реактивировать товар #' + productId + '?')) return;

                $btn.prop('disabled', true).text('...');

                $.post(ajaxurl, {
                    action: 'cashback_reactivate_product',
                    nonce: '<?php echo esc_attr( wp_create_nonce('cashback_api_validation') ); ?>',
                    product_id: productId
                }, function(response) {
                    if (response.success) {
                        $('#deact-row-' + productId).fadeOut();
                    } else {
                        alert('Ошибка: ' + (response.data || ''));
                        $btn.prop('disabled', false).text('Реактивировать');
                    }
                }).fail(function() {
                    alert('Ошибка сети');
                    $btn.prop('disabled', false).text('Реактивировать');
                });
            });

            // ─── Инициализация вкладки кампаний ───
            if (typeof window.initCampaignsTab === 'function') {
                window.initCampaignsTab();
            }
        });
        </script>
    <?php
    }

    /**
     * AJAX: Проверить статусы кампаний сейчас
     */
    public function ajax_check_campaigns_now(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Доступ запрещён');
        }

        $network_slug = isset($_POST['network']) ? sanitize_text_field(wp_unslash($_POST['network'])) : '';
        $only_slug    = $network_slug !== '' ? $network_slug : null;

        try {
            $client  = Cashback_API_Client::get_instance();
            $results = $client->check_campaign_statuses($only_slug);
            wp_send_json_success($results);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX: Ручная реактивация товара администратором
     */
    public function ajax_reactivate_product(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Доступ запрещён');
        }

        $product_id = absint( wp_unslash( $_POST['product_id'] ?? 0 ) );
        if ($product_id <= 0) {
            wp_send_json_error('Неверный ID товара');
        }

        $post = get_post($product_id);
        if (!$post || $post->post_type !== 'product') {
            wp_send_json_error('Товар не найден');
        }

        wp_update_post(array(
            'ID'          => $product_id,
            'post_status' => 'publish',
        ));

        update_post_meta($product_id, '_cashback_admin_override', '1');
        delete_post_meta($product_id, '_cashback_auto_deactivated');
        delete_post_meta($product_id, '_cashback_deactivation_reason');
        delete_post_meta($product_id, '_cashback_deactivated_at');
        delete_post_meta($product_id, '_cashback_deactivated_network');

        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'store_manual_reactivated',
                get_current_user_id(),
                'product',
                $product_id,
                array( 'source' => 'admin_override' )
            );
        }

        wp_send_json_success(array( 'message' => 'Товар реактивирован' ));
    }
}
