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
        add_action('wp_ajax_cashback_manual_sync_status', array( $this, 'ajax_manual_sync_status' ));
        add_action('wp_ajax_cashback_get_sync_log', array( $this, 'ajax_get_sync_log' ));
        add_action('wp_ajax_cashback_get_validation_status', array( $this, 'ajax_get_validation_status' ));
        add_action('wp_ajax_cashback_save_sync_window', array( $this, 'ajax_save_sync_window' ));
        // P5: push/pull dedup-identity консистентность (универсальный дедуп).
        add_action('wp_ajax_cashback_validate_dedup_config', array( $this, 'ajax_validate_dedup_config' ));
        // Поведенческий self-test дедупликации (read-only): ловит перепутанный
        // крон-маппинг uniq_id, который config-валидация поймать не может.
        add_action('wp_ajax_cashback_dedup_selftest', array( $this, 'ajax_dedup_selftest' ));

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
            'cashback-pagination',
            plugin_dir_url(__DIR__) . 'assets/js/cashback-pagination.js',
            array(),
            '1.0.0',
            true
        );

        wp_enqueue_script(
            'cashback-api-validation',
            plugin_dir_url(__DIR__) . 'admin/js/api-validation.js',
            array( 'jquery', 'cashback-pagination' ),
            '5.4.1',
            true
        );

        wp_localize_script('cashback-api-validation', 'cashbackApiValidation', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('cashback_api_validation'),
            'statusCorrectionNonce' => wp_create_nonce('cashback_correct_transaction_status_nonce'),
            'minStatusCorrectionReasonLength' => 20,
            'i18n'    => array(
                'validating'              => 'Проверка...',
                'validate'                => 'Проверить',
                'match'                   => '✅ Данные совпадают',
                'mismatch'                => '⚠️ Обнаружены расхождения',
                'error'                   => '❌ Ошибка проверки',
                'syncing'                 => 'Синхронизация...',
                'sync_queued'             => 'Синхронизация запущена...',
                'sync_running'            => 'Синхронизация выполняется...',
                'sync_complete'           => 'Синхронизация завершена',
                'sync_status_unavailable' => 'Синхронизация запущена, но статус временно недоступен. Обновите страницу через минуту.',
                'saving'                  => 'Сохранение...',
                'saved'                   => 'Сохранено',
                'confirm_sync'            => 'Запустить синхронизацию статусов?',
                'adding'                  => 'Добавление...',
                'confirm_overwrite'       => 'Перезаписать локальные данные данными из API?',
                'confirm_delete'          => 'Удалить эту строку из результатов?',
                'import_invalid_type'     => 'Неподдерживаемый тип файла. Ожидается JSON-файл настроек сети.',
                'import_invalid_signature' => 'Файл не похож на экспорт настроек сети.',
                'import_invalid_json'     => 'Файл повреждён или это не JSON.',
                'import_file_too_large'   => 'Файл больше 1 МБ. Это не похоже на экспорт настроек.',
                'import_network_not_found' => 'Сеть «%s» не зарегистрирована в плагине, импорт невозможен.',
                'import_success'          => 'Настройки загружены. Проверьте поля и нажмите «Сохранить настройки». Client ID и Client Secret введите заново.',
            ),
        ));

        wp_enqueue_style(
            'cashback-api-validation',
            plugin_dir_url(__DIR__) . 'admin/css/api-validation.css',
            array(),
            '5.4.1'
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
                // get_credentials() гарантированно не кидает (fix в Cashback_API_Client):
                // возвращает null если запись пустая ИЛИ если ciphertext не
                // расшифровывается текущими активными ключами. Отличаем эти два
                // случая через has_undecryptable_credentials() — чтобы показать
                // админу понятный баннер только во второй ситуации.
                $api_client                = Cashback_API_Client::get_instance();
                $saved_credentials         = $api_client->get_credentials((int) $network['id']) ?: array();
                $credentials_undecryptable = $api_client->has_undecryptable_credentials((int) $network['id']);
                $saved_scope               = $saved_credentials['scope'] ?? '';
            ?>
                <div class="cashback-network-card"
                    data-network-id="<?php echo esc_attr($network['id']); ?>"
                    data-network-slug="<?php echo esc_attr($network['slug']); ?>"
                    data-network-name="<?php echo esc_attr($network['name']); ?>"
                    style="display:none">
                    <h2><?php echo esc_html($network['name']); ?>
                        <span class="slug">(<?php echo esc_html($network['slug']); ?>)</span>
                        <?php if ($network['is_active']) : ?>
                            <span class="status-badge active">Активна</span>
                        <?php else : ?>
                            <span class="status-badge inactive">Неактивна</span>
                        <?php endif; ?>
                    </h2>

                    <?php if ($credentials_undecryptable) : ?>
                        <div class="notice notice-warning inline" style="margin:10px 0;padding:10px 12px;">
                            <p style="margin:0;">
                                <strong>Сохранённые credentials этой сети не расшифровываются</strong>
                                текущими активными ключами шифрования (напр. после утери/ротации ключа).
                                Введите API-параметры заново и нажмите «Сохранить» — плагин перешифрует их
                                актуальным ключом. Детали в логах PHP (строка <code>[Cashback API Client] decrypt failed</code>).
                            </p>
                        </div>
                    <?php endif; ?>

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
                                        'comission'      => 'comission (комиссия)',
                                        'sum_order'      => 'sum_order (сумма заказа)',
                                        'uniq_id'        => 'uniq_id (ID действия)',
                                        'order_number'   => 'order_number (номер заказа)',
                                        'offer_id'       => 'offer_id (ID оффера)',
                                        'offer_name'     => 'offer_name (название оффера)',
                                        'currency'       => 'currency (валюта)',
                                        'action_date'    => 'action_date (дата покупки)',
                                        'click_time'     => 'click_time (время клика)',
                                        'action_type'    => 'action_type (тип действия)',
                                        'website_id'     => 'website_id (ID площадки)',
                                        'funds_ready'    => 'funds_ready (готовность к выплате)',
                                        'decline_reason' => 'decline_reason (причина отказа)',
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

                        <!-- Промокоды (v8): 4 public-поля для generic-движка купонов -->
                        <tr>
                            <th colspan="2" style="background:#f6f7f7; padding-top:16px;"><h3 style="margin:0;">Купоны API <small style="font-weight:normal; color:#666;">(generic-движок промокодов)</small></h3></th>
                        </tr>
                        <tr>
                            <th><label for="api_coupons_endpoint_<?php echo esc_attr($network['id']); ?>">Coupons Endpoint</label></th>
                            <td>
                                <input type="text" id="api_coupons_endpoint_<?php echo esc_attr($network['id']); ?>"
                                    name="api_coupons_endpoint" class="api-field regular-text"
                                    value="<?php echo esc_attr($network['api_coupons_endpoint'] ?? ''); ?>"
                                    placeholder="/coupons/website/{website_id}/?campaign={advcampaign_id}&limit={limit}&offset={offset}">
                                <p class="description">URL купонов с placeholder-ами: <code>{website_id}</code>, <code>{advcampaign_id}</code>, <code>{limit}</code>, <code>{offset}</code>, <code>{api_key}</code>. Пустое значение → шаг отключён для этой сети.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="api_coupons_pagination_<?php echo esc_attr($network['id']); ?>">Coupons Pagination</label></th>
                            <td>
                                <select id="api_coupons_pagination_<?php echo esc_attr($network['id']); ?>" name="api_coupons_pagination" class="api-field">
                                    <?php $cur_pag = (string) ( $network['api_coupons_pagination'] ?? 'offset_limit' ); ?>
                                    <option value="offset_limit"<?php selected($cur_pag, 'offset_limit'); ?>>offset_limit (Admitad / CityAds)</option>
                                    <option value="page"<?php selected($cur_pag, 'page'); ?>>page (page=N)</option>
                                    <option value="none"<?php selected($cur_pag, 'none'); ?>>none (одиночный запрос)</option>
                                </select>
                                <p class="description">Тип пагинации API купонов сети.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="api_coupons_field_map_<?php echo esc_attr($network['id']); ?>">Coupons Field Map (JSON)</label></th>
                            <td>
                                <textarea id="api_coupons_field_map_<?php echo esc_attr($network['id']); ?>"
                                    name="api_coupons_field_map" class="api-field large-text code"
                                    rows="6"
                                    placeholder='{"id":"external_id","promocode":"promocode","name":"name","goto_link":"goto_link","date_start":"date_start","date_end":"date_end","status":"status","regions":"regions","type":"species_raw"}'><?php echo esc_textarea($network['api_coupons_field_map'] ?? ''); ?></textarea>
                                <p class="description">JSON-маппинг raw-полей API купонов → канонические DTO-ключи. Канонические ключи: <code>external_id</code>, <code>promocode</code>, <code>name</code>, <code>short_name</code>, <code>description</code>, <code>discount</code>, <code>date_start</code>, <code>date_end</code>, <code>regions</code>, <code>categories</code>, <code>image_url</code>, <code>goto_link</code>, <code>is_exclusive</code>, <code>rating</code>, <code>species_raw</code>, <code>status</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="api_coupons_species_map_<?php echo esc_attr($network['id']); ?>">Coupons Species Map (JSON)</label></th>
                            <td>
                                <textarea id="api_coupons_species_map_<?php echo esc_attr($network['id']); ?>"
                                    name="api_coupons_species_map" class="api-field large-text code"
                                    rows="4"
                                    placeholder='{"promocode":"promocode","promo_code":"promocode","deal":"deal","sale":"deal","discount":"deal"}'><?php echo esc_textarea($network['api_coupons_species_map'] ?? ''); ?></textarea>
                                <p class="description">JSON-маппинг raw <code>type</code>/<code>species</code> сети → канонические значения <code>promocode</code> / <code>deal</code>. Незнакомые типы автоматически становятся <code>other</code>.</p>
                            </td>
                        </tr>
                    </table>

                    <p class="cashback-network-actions">
                        <button type="button" class="button button-primary cashback-save-network-btn"
                            data-network-id="<?php echo esc_attr($network['id']); ?>">
                            Сохранить настройки
                        </button>
                        <button type="button" class="button cashback-test-connection-btn"
                            data-network-id="<?php echo esc_attr($network['id']); ?>">
                            Проверить соединение
                        </button>
                        <button type="button" class="button cashback-export-network-btn"
                            data-network-id="<?php echo esc_attr($network['id']); ?>">
                            Скачать настройки
                        </button>
                        <button type="button" class="button cashback-import-network-btn"
                            data-network-id="<?php echo esc_attr($network['id']); ?>">
                            Загрузить настройки
                        </button>
                        <span class="cashback-save-status"></span>
                    </p>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($networks)) : ?>
                <input type="file" id="cashback-import-network-file" accept=".json,application/json" hidden>
            <?php endif; ?>

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

            <?php $this->render_dedup_config_panel(); ?>

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
                <button type="button" id="cashback-dedup-selftest-btn" class="button button-secondary"
                    title="Поведенческая проверка: на свежей конверсии сверяет, что крон-резолвер даёт тот же uniq_id, что webhook (ловит перепутанный api_field_map). Read-only.">
                    🔬 Self-test дедупликации
                </button>
            </p>

            <div id="cashback-validation-result" style="display:none; margin-top: 20px;">
                <!-- Результат валидации подставляется через JS -->
            </div>
            <div id="cashback-dedup-selftest-result" style="display:none; margin-top: 20px;">
                <!-- Результат self-test подставляется через JS -->
            </div>
        </div>
    <?php
    }

    /**
     * Вкладка «Синхронизация»
     */
    private function render_sync_tab(): void {
        $last_sync        = get_option('cashback_last_sync_result', null);
        $sync_window_days = (int) get_option('cashback_api_sync_window_days', 180);
        if ($sync_window_days < 1 || $sync_window_days > 365) {
            $sync_window_days = 180;
        }
    ?>
        <div id="cashback-sync-tab">
            <div id="cashback-sync-window-settings" style="background:#fff;border:1px solid #c3c4c7;padding:12px 18px;margin-bottom:20px;max-width:600px;">
                <h2 style="margin-top:0;">Настройка окна синхронизации в днях</h2>
                <p>
                    <label for="cashback-sync-window-days">Введите количество дней:</label>
                    <input type="number" id="cashback-sync-window-days" name="cashback_sync_window_days"
                        value="<?php echo esc_attr((string) $sync_window_days); ?>"
                        min="1" max="365" step="1" class="small-text" />
                    <button type="button" id="cashback-save-sync-window" class="button button-primary">Сохранить</button>
                    <span id="cashback-sync-window-status" style="margin-left:10px;"></span>
                </p>
                <p class="description">
                    Применяется ко всем CPA-сетям (Admitad, EPN и любым будущим). По умолчанию: 180. Допустимый диапазон: 1–365.
                    Рекомендуется не больше 180 — широкие окна нестабильны на <code>/statistics/actions/</code> Admitad
                    (HTTP 500 / cURL timeout 60s).
                </p>
            </div>

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

        try {
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
        } catch (Exception $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging (debug only).
                error_log('[cashback] api-validation validate_user: ' . $e->getMessage());
            }
            wp_send_json_error(array( 'message' => 'Ошибка валидации. Подробности записаны в журнал.' ));
        }
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
            'window_limited_local' => array(),
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
            foreach ($result['window_limited_local'] ?? array() as $item) {
                $item['network']                  = $slug;
                $totals['window_limited_local'][] = $item;
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
            // v8 промокоды: 4 public-поля.
            'api_coupons_endpoint'   => isset($_POST['api_coupons_endpoint']) ? sanitize_text_field(wp_unslash($_POST['api_coupons_endpoint'])) : '',
            'api_coupons_pagination' => isset($_POST['api_coupons_pagination']) ? sanitize_key(wp_unslash($_POST['api_coupons_pagination'])) : 'offset_limit',
        );

        // Whitelist для api_coupons_pagination — только известные значения.
        if (!in_array($fields['api_coupons_pagination'], array( 'offset_limit', 'page', 'none' ), true)) {
            $fields['api_coupons_pagination'] = 'offset_limit';
        }

        // Валидация JSON-маппинга полей купонов.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON validated via json_decode + json_last_error.
        $coupons_field_map_raw = wp_unslash($_POST['api_coupons_field_map'] ?? '');
        if (!empty($coupons_field_map_raw)) {
            $decoded_cfm = json_decode($coupons_field_map_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded_cfm)) {
                wp_send_json_error(array( 'message' => 'Coupons Field Map: невалидный JSON — ' . json_last_error_msg() ));
            }
            $fields['api_coupons_field_map'] = wp_json_encode($decoded_cfm);
        } else {
            $fields['api_coupons_field_map'] = null;
        }

        // Валидация JSON-маппинга species купонов.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON validated via json_decode + json_last_error.
        $coupons_species_map_raw = wp_unslash($_POST['api_coupons_species_map'] ?? '');
        if (!empty($coupons_species_map_raw)) {
            $decoded_csm = json_decode($coupons_species_map_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded_csm)) {
                wp_send_json_error(array( 'message' => 'Coupons Species Map: невалидный JSON — ' . json_last_error_msg() ));
            }
            $fields['api_coupons_species_map'] = wp_json_encode($decoded_csm);
        } else {
            $fields['api_coupons_species_map'] = null;
        }

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

        // S2 enforced source-field guard (narrow trigger). Native uniq_id —
        // opaque exact-match токен (НЕ нормализуем: case-fold/zero-strip
        // запрещены). Реальный риск дублей = silent push/pull drift. Cron
        // по умолчанию берёт uniq_id из action_id (DEFAULT_FIELD_MAP merge),
        // поэтому drift ВОЗМОЖЕН только когда оператор ЯВНО ремапит uniq_id
        // на другое API-поле. В этом (и только этом) случае требуем, чтобы
        // push-источник (dedup_identity.receiver_uniq_source) был объявлен —
        // иначе webhook и cron разойдутся. Кросс-имённую авто-сверку сделать
        // нельзя (разные пространства имён, ADR D-5b), поэтому проверяем
        // только присутствие. Корректные сети (дефолтный map / уже
        // объявленный receiver_uniq_source) не затрагиваются.
        if (isset($fields['api_field_map'])) {
            $fm_decoded = json_decode((string) $fields['api_field_map'], true);

            // B-3: drift ВОЗМОЖЕН только когда эффективный API-источник
            // uniq_id ОТЛИЧАЕТСЯ от дефолтного `action_id`. get_field_map()
            // мержит DEFAULT_FIELD_MAP, поэтому явная пара
            // {"action_id":"uniq_id"} функционально == дефолту (cron всё
            // равно берёт action_id) и НЕ является ремапом — иначе ротация
            // несвязанного credential ложно блокировалась бы (lost-tx).
            // КРИТИЧНО: семантика ДОЛЖНА совпадать с runtime
            // Cashback_API_Client::api_field_for() = array_flip($map)
            // (last-wins при дубликатах значений). array_search() вернул бы
            // ПЕРВЫЙ ключ — при `{"action_id":"uniq_id","foo":"uniq_id"}`
            // gate увидел бы action_id (не блок), а cron взял бы foo →
            // рассинхрон и double-credit. array_flip мирроррит runtime.
            $fm_strvals   = is_array($fm_decoded) ? array_map('strval', $fm_decoded) : array();
            $uniq_src_key = array_flip($fm_strvals)['uniq_id'] ?? false;
            $explicit_uniq_remap = ( $uniq_src_key !== false )
                && ( (string) $uniq_src_key !== 'action_id' );

            if ($explicit_uniq_remap) {
                $networks_tbl = $wpdb->prefix . 'cashback_affiliate_networks';
                // wpdb::query() сбрасывает last_error в начале каждого
                // запроса → проверка СРАЗУ после get_var отражает только
                // его (ручной pre-reset не нужен).
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row config read for save-time guard.
                $di_raw = $wpdb->get_var($wpdb->prepare(
                    'SELECT dedup_identity FROM %i WHERE id = %d',
                    $networks_tbl,
                    $network_id
                ));
                // Свежая локалка (PHPStan не моделирует мутацию свойства
                // методами wpdb; прямой if($wpdb->last_error) сузил бы
                // свойство до '' и ломал последующие проверки).
                $guard_db_err = (string) $wpdb->last_error;
                if ($guard_db_err !== '') {
                    // B-1: FAIL-CLOSED. idempotency_key НЕ backstop против
                    // source-drift (разные uniq_id → разные ключи, UNIQUE не
                    // ловит), а v18 fast-path не перепроверит сайт на db>=18.
                    // Блокируем save (оператор повторит) — лучше отказ, чем
                    // тихий double-credit.
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log('[cashback] dedup source-guard read failed (fail-closed): ' . $guard_db_err);
                    wp_send_json_error(array(
                        'message' => 'Не удалось проверить контракт дедупликации (временная ошибка БД). '
                            . 'Сохранение отклонено во избежание рассинхрона webhook/cron. '
                            . 'Повторите сохранение.',
                    ));
                }
                $di      = ( is_string($di_raw) && $di_raw !== '' ) ? json_decode($di_raw, true) : null;
                $di      = is_array($di) ? $di : null;
                $native  = ($di === null) ? true : ( ($di['has_native_action_id'] ?? true) !== false );
                $rcv_src = ($di !== null) ? (string) ( $di['receiver_uniq_source'] ?? '' ) : '';
                if ($native && $rcv_src === '') {
                    wp_send_json_error(array(
                        'message' => 'Вы переопределяете API-поле uniq_id, но не объявлен '
                            . 'push-источник native id (receiver_uniq_source) в контракте '
                            . 'дедупликации этой сети. Без него webhook и cron создадут разные '
                            . 'uniq_id на одну конверсию → дубль и двойное начисление. '
                            . 'Задайте receiver_uniq_source (имя поля native id в постбэке сети) '
                            . 'и повторите сохранение. (Кросс-имённая авто-сверка невозможна — '
                            . 'имена API-поля и постбэк-макроса в разных пространствах имён.)',
                    ));
                }
                // Residual: drift-состояние НЕ должно зависеть от one-shot
                // v18. Save-path авторитетен для app-изменений api_field_map:
                // конфиг прошёл гард (receiver_uniq_source объявлен ИЛИ
                // сеть synthetic) — снимаем slug этой сети из drift-option
                // (self-heal notice независимо от cashback_db_version).
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row slug read for drift self-heal.
                $slug_for_drift = (string) $wpdb->get_var($wpdb->prepare(
                    'SELECT slug FROM %i WHERE id = %d',
                    $networks_tbl,
                    $network_id
                ));
                $slug_db_err = (string) $wpdb->last_error;
                if ($slug_for_drift !== '' && $slug_db_err === '') {
                    $drift_now = get_option('cashback_dedup_source_drift');
                    if (is_array($drift_now) && in_array($slug_for_drift, $drift_now, true)) {
                        $drift_now = array_values(array_filter(
                            $drift_now,
                            static fn ( $s ): bool => (string) $s !== $slug_for_drift
                        ));
                        if ($drift_now === array()) {
                            delete_option('cashback_dedup_source_drift');
                        } else {
                            update_option('cashback_dedup_source_drift', $drift_now, false);
                        }
                    }
                }
            }
        }

        $wpdb->update(
            $wpdb->prefix . 'cashback_affiliate_networks',
            $fields,
            array( 'id' => $network_id )
        );

        if ($wpdb->last_error) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging (debug only).
                error_log('[cashback] api-validation network save: ' . $wpdb->last_error);
            }
            wp_send_json_error(array( 'message' => 'Ошибка сохранения настроек. Подробности записаны в журнал.' ));
        }

        // Сохраняем credentials если указаны новые.
        // get_credentials() теперь возвращает null на невозможность расшифровки
        // (не кидает). Для partial-update'а (масленые поля •••) критично знать,
        // есть ли расшифруемые старые данные. has_undecryptable_credentials()
        // отличает «нет данных вообще» от «данные есть, но ключ ротирован /
        // утерян» — во втором случае требуем полный ввод, потому что
        // подставить старое значение вместо маски мы не можем.
        $client               = Cashback_API_Client::get_instance();
        $existing             = $client->get_credentials($network_id) ?: array();
        $old_credentials_lost = $client->has_undecryptable_credentials($network_id);
        $credentials_changed  = false;

        if ($auth_type === 'api_key') {
            $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
            if ($old_credentials_lost && str_starts_with($api_key, '•')) {
                wp_send_json_error(array( 'message' => 'Старые credentials не расшифровываются текущими ключами. Введите API Key полностью — замаскированное значение не будет принято.' ));
            }
            if (!empty($api_key) && !str_starts_with($api_key, '•')) {
                $existing['api_key'] = $api_key;
                $credentials_changed = true;
            }
        } else {
            $client_id     = isset($_POST['client_id']) ? sanitize_text_field(wp_unslash($_POST['client_id'])) : '';
            $client_secret = isset($_POST['client_secret']) ? sanitize_text_field(wp_unslash($_POST['client_secret'])) : '';

            if ($old_credentials_lost && ( str_starts_with($client_id, '•') || str_starts_with($client_secret, '•') )) {
                wp_send_json_error(array( 'message' => 'Старые credentials не расшифровываются текущими ключами. Введите client_id и client_secret полностью — замаскированные значения не будут приняты.' ));
            }

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

        try {
            $client = Cashback_API_Client::get_instance();
            $result = $client->test_connection($network_id);

            if ($result['success']) {
                wp_send_json_success(array( 'message' => $result['message'] ));
            } else {
                wp_send_json_error(array( 'message' => $result['message'] ));
            }
        } catch (Exception $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging (debug only).
                error_log('[cashback] api-validation test_connection: ' . $e->getMessage());
            }
            wp_send_json_error(array( 'message' => 'Ошибка проверки соединения. Подробности записаны в журнал.' ));
        }
    }

    /**
     * AJAX: Сохранение окна API-синхронизации (опция cashback_api_sync_window_days).
     *
     * Применяется ко всем CPA-сетям через Cashback_API_Client::default_lookback_date_dmy().
     * Диапазон [1, 365] — защита от широких окон, ронящих /statistics/actions/ Admitad.
     */
    public function ajax_save_sync_window(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        $days = absint( wp_unslash( $_POST['days'] ?? 0 ) );
        if ($days < 1 || $days > 365) {
            wp_send_json_error(array(
                'message' => 'Введите целое число от 1 до 365.',
                'code'    => 'invalid_range',
            ));
        }

        update_option('cashback_api_sync_window_days', $days, false);

        wp_send_json_success(array(
            'message' => 'Сохранено: окно ' . $days . ' дн.',
            'days'    => $days,
        ));
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

        try {
            $result = Cashback_API_Cron::start_manual_sync_async();

            if (!empty($result['locked'])) {
                wp_send_json_error(array(
                    'message' => $result['message'] ?? 'Синхронизация уже выполняется. Попробуйте через несколько секунд.',
                ));
            }

            if (!isset($result['async']) || empty($result['run_id'])) {
                $result['async'] = false;
            }

            $this->log_audit('manual_sync', 0, $result);

            wp_send_json_success($result);
        } catch (Exception $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging (debug only).
                error_log('[cashback] api-validation manual_sync: ' . $e->getMessage());
            }
            wp_send_json_error(array( 'message' => 'Ошибка ручной синхронизации. Подробности записаны в журнал.' ));
        }
    }

    /**
     * AJAX: Статус ручной async-синхронизации.
     */
    public function ajax_manual_sync_status(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        $run_id = isset($_POST['run_id'])
            ? sanitize_text_field(wp_unslash($_POST['run_id']))
            : '';

        $status = Cashback_API_Cron::get_manual_sync_status($run_id);

        if (($status['status'] ?? '') === 'unknown') {
            wp_send_json_error($status);
        }

        wp_send_json_success($status);
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
             WHERE sl.synced_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
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
    // P5: Push/pull dedup-identity консистентность
    // =========================================================================

    /**
     * Отчёт по контракту дедупликации для всех активных сетей.
     *
     * Surfaces misconfig вместо тихого дубля: для КАЖДОЙ сети проверяем что
     * (a) контракт идентичности well-formed (native ⇒ есть API-поле → uniq_id;
     * synthetic ⇒ непустой synthetic_fields) и (b) receiver_uniq_source
     * задан оператором (push-источник). Кросс-именная авто-сверка
     * (api_field vs postback-macro) невозможна без receiver-introspection
     * (D-5b отклонён) — поэтому показываем ОБА источника для глазной сверки.
     *
     * @return array<int,array<string,mixed>>
     */
    private function compute_dedup_config_report(): array {
        $client = Cashback_API_Client::get_instance();
        $report = array();

        foreach ($client->get_all_active_networks() as $net) {
            $slug = (string) ( $net['slug'] ?? '' );
            if ($slug === '') {
                continue;
            }
            $cfg = $client->get_network_config($slug);
            if (!is_array($cfg)) {
                continue;
            }

            $field_map = is_array($cfg['field_map'] ?? null) ? $cfg['field_map'] : array();
            $flip      = array_flip($field_map);
            $uniq_src  = (string) ( $flip['uniq_id'] ?? '' );

            $raw_di  = $cfg['dedup_identity'] ?? null;
            $decoded = ( is_string($raw_di) && $raw_di !== '' ) ? json_decode($raw_di, true) : null;
            $decoded = is_array($decoded) ? $decoded : null;

            $has_native = ($decoded === null)
                ? true
                : ( ($decoded['has_native_action_id'] ?? true) !== false );
            $synthetic_fields = ( $decoded !== null && is_array($decoded['synthetic_fields'] ?? null) )
                ? $decoded['synthetic_fields']
                : array();
            $receiver_src = ($decoded !== null) ? (string) ( $decoded['receiver_uniq_source'] ?? '' ) : '';

            $issues = array();
            if ($has_native && $uniq_src === '') {
                $issues[] = 'native-режим, но ни одно API-поле не замаплено в uniq_id';
            }
            if (!$has_native && $synthetic_fields === array()) {
                $issues[] = 'synthetic-режим, но synthetic_fields пуст';
            }
            if ($receiver_src === '') {
                $issues[] = 'receiver_uniq_source не задан — push/pull консистентность не подтверждается';
            }

            $report[] = array(
                'slug'             => $slug,
                'name'             => (string) ( $cfg['name'] ?? $slug ),
                'mode'             => $has_native ? 'native' : 'synthetic',
                'api_uniq_source'  => $uniq_src,
                'receiver_source'  => $receiver_src,
                'synthetic_fields' => $synthetic_fields,
                'status'           => $issues === array() ? 'ok' : 'warn',
                'issues'           => $issues,
            );
        }

        return $report;
    }

    /**
     * AJAX: вернуть dedup-config отчёт (read-only диагностика).
     */
    public function ajax_validate_dedup_config(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        $report = $this->compute_dedup_config_report();
        $all_ok = true;
        foreach ($report as $row) {
            if ($row['status'] !== 'ok') {
                $all_ok = false;
                break;
            }
        }

        wp_send_json_success(array(
            'all_ok' => $all_ok,
            'report' => $report,
        ));
    }

    /**
     * AJAX: поведенческий self-test дедупликации (read-only).
     *
     * Делегирует в Cashback_API_Client::dedup_selftest() — строго без
     * side-effects (SELECT + HTTP-GET). Ловит перепутанный крон-маппинг
     * uniq_id, который config-валидация (разные пространства имён, D-5b)
     * поймать не может.
     */
    public function ajax_dedup_selftest(): void {
        check_ajax_referer('cashback_api_validation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        // СТРОГО read-only: НЕ пишем rate-limit transient (это была бы
        // запись в wp_options/кэш). Эндпоинт admin-only за nonce+cap,
        // dedup_selftest ограничен sample_limit и узким date-окном —
        // ручной клик не DoS-вектор. Отказ от транзиента сохраняет
        // абсолютную zero-write гарантию фичи.
        $network = isset($_POST['network']) ? sanitize_text_field(wp_unslash($_POST['network'])) : '';
        if ($network === '' || $network === '__all__') {
            wp_send_json_error(array( 'message' => 'Выберите конкретную CPA-сеть (не «Все сети»).' ));
        }

        try {
            $client = Cashback_API_Client::get_instance();
            $result = $client->dedup_selftest($network);
            wp_send_json_success($result);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging (debug only).
                error_log('[cashback] dedup_selftest failed: ' . $e->getMessage());
            }
            wp_send_json_error(array( 'message' => 'Ошибка self-test. Подробности в журнале.' ));
        }
    }

    /**
     * Серверный рендер панели dedup-config в таб «API Валидация»
     * (без JS — виден сразу при загрузке таба).
     */
    private function render_dedup_config_panel(): void {
        $report = $this->compute_dedup_config_report();
        if ($report === array()) {
            return;
        }
        ?>
        <h3>Дедупликация: контракт идентичности (push/pull)</h3>
        <p class="description">
            Универсальный exactly-once резолвер. <code>native</code> = у сети
            есть собственный per-action id; <code>synthetic</code> = id
            вычисляется детерминированно из стабильных полей. Сверьте, что
            «API uniq source» и «Receiver uniq source» обозначают ОДИН
            логический идентификатор действия.
        </p>
        <table class="widefat striped" style="max-width:960px">
            <thead>
                <tr>
                    <th>Сеть</th><th>Режим</th><th>API uniq source</th>
                    <th>Receiver uniq source</th><th>synthetic_fields</th><th>Статус</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($report as $row) : ?>
                <tr>
                    <td><?php echo esc_html($row['name']); ?> <code><?php echo esc_html($row['slug']); ?></code></td>
                    <td><?php echo esc_html($row['mode']); ?></td>
                    <td><code><?php echo esc_html($row['api_uniq_source'] !== '' ? $row['api_uniq_source'] : '—'); ?></code></td>
                    <td><code><?php echo esc_html($row['receiver_source'] !== '' ? $row['receiver_source'] : '—'); ?></code></td>
                    <td><code><?php echo esc_html(implode(', ', array_map('strval', $row['synthetic_fields'])) ?: '—'); ?></code></td>
                    <td>
                        <?php if ($row['status'] === 'ok') : ?>
                            <span style="color:#1a7f37;font-weight:600">OK</span>
                        <?php else : ?>
                            <span style="color:#b32d2e;font-weight:600">⚠ <?php echo esc_html(implode('; ', $row['issues'])); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <hr>
        <?php
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

        // 12c ADR (F-4-002): TX + SELECT FOR UPDATE — guards (validate_status_transition)
        // работают на committed-состоянии под локом, чтобы параллельные admin-правки /
        // API-sync не создавали lost update. Паттерн: Группа 8, sync_update_local.
        $wpdb->query('START TRANSACTION');

        // Проверяем существование и текущий статус.
        $current = $wpdb->get_row($wpdb->prepare(
            'SELECT id, order_status, comission, applied_cashback_rate FROM %i WHERE id = %d FOR UPDATE',
            $table,
            $transaction_id
        ));

        if (!$current) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Транзакция не найдена' ));
        }

        // Money-поля пишем как canonical decimal-string (`%s`), чтобы избежать
        // locale-зависимого поведения `%f` (Группа 10 ADR, F-4-004): в локалях с `,`
        // как decimal separator `sprintf('%f', 5.5)` может выдать `"5,500000"`, что
        // MySQL не смог бы привести к DECIMAL.
        //
        // Поле `cashback` НЕ передаём — триггер calculate_cashback_before_update
        // (mariadb.php) автоматически пересчитает его при изменении comission
        // через NEW.cashback = ROUND(NEW.comission * NEW.applied_cashback_rate / 100).
        // Status-transition валидируется триггером cashback_tr_validate_status_transition,
        // который SIGNAL'ит SQLSTATE 45000 с локализованным MESSAGE_TEXT.
        $update_data    = array(
            'order_status' => $order_status,
            'comission'    => number_format($comission, 2, '.', ''),
            'sum_order'    => number_format($sum_order, 2, '.', ''),
        );
        $update_formats = array( '%s', '%s', '%s' );

        $updated = $wpdb->update(
            $table,
            $update_data,
            array( 'id' => $transaction_id ),
            $update_formats,
            array( '%d' )
        );

        if ($updated === false || $wpdb->last_error) {
            $last_error = $wpdb->last_error;
            $wpdb->query('ROLLBACK');
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging (debug only).
                error_log('[cashback] api-validation edit_transaction: ' . $last_error);
            }
            // SIGNAL SQLSTATE '45000' от триггера status-валидации возвращает
            // понятное русскоязычное сообщение в last_error — пробрасываем его
            // пользователю; иначе generic-сообщение.
            $signal_message = self::extract_trigger_signal_message($last_error);
            wp_send_json_error(array( 'message' => $signal_message ?? 'Ошибка обновления транзакции. Подробности записаны в журнал.' ));
        }

        $wpdb->query('COMMIT');

        // audit log — вне TX: failure логирования не должен откатывать успешный UPDATE.
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

        // Канонизация сети (Codex F-1): get_network_config() матчит ТОЛЬКО
        // точный DB-slug. Если admin прислал alias адаптера ('adm') или
        // имя — резолвим через адаптер к каноническому slug. Если сеть не
        // резолвится вообще — ОТКАЗ: вставка с неканоническим
        // partner/idempotency_key создала бы строку, не коллизирующую ни по
        // UNIQUE(uniq_id,partner), ни по idx_idempotency_key с
        // webhook/cron-строкой → потенциальный double-credit.
        $client         = Cashback_API_Client::get_instance();
        $network_config = $client->get_network_config($network);
        if (!$network_config) {
            $adapter = $client->get_adapter($network);
            if ($adapter && $adapter->get_slug() !== $network) {
                $network_config = $client->get_network_config($adapter->get_slug());
            }
        }
        if (!$network_config || empty($network_config['slug'])) {
            wp_send_json_error(array( 'message' => 'Неизвестная или неактивная сеть: ' . $network ));
        }
        // Каноничный slug из резолвленной строки сети — ТОТ ЖЕ, что
        // использует cron (insert_missing_transaction) и Python-receiver.
        $network        = (string) $network_config['slug'];
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

        // Единый код-пас идентичности: прогоняем admin-введённый action_id
        // через ТОТ ЖЕ универсальный резолвер, что cron
        // (resolve_action_identity) и webhook-receiver (app/identity.py).
        // Раньше admin брал $action_id из POST напрямую — при будущем
        // изменении резолвера admin-вставка дрейфанула бы от cron/webhook
        // → double-credit. Native uniq_id — opaque exact-match токен:
        // резолвер только trim'ит (НЕ case-fold / НЕ zero-strip), поэтому
        // для встроенных сетей значение байт-в-байт прежнее.
        $di_raw = $network_config['dedup_identity'] ?? null;
        $di     = ( is_string($di_raw) && $di_raw !== '' ) ? json_decode($di_raw, true) : null;
        $di     = is_array($di) ? $di : null;
        [$resolved_action_id, $resolve_reason] = Cashback_API_Client::resolve_uniq_id(
            $network,
            $action_id,
            array(
                'order_number' => $order_id,
                'offer_id'     => $campaign_id,
                'action_type'  => $action_type,
                'click_id'     => $click_id,
            ),
            $di
        );
        if ($resolve_reason !== null || $resolved_action_id === '') {
            // Нет дедуп-идентичности → вставка создала бы строку, не
            // коллизирующую с webhook/cron → потенциальный double-credit.
            wp_send_json_error(array(
                'message' => 'Не удалось определить идентичность транзакции для дедупликации'
                    . ( $resolve_reason !== null ? ' (' . $resolve_reason . ')' : '' )
                    . '. Проверьте action_id и контракт dedup_identity сети.',
            ));
        }
        $action_id = $resolved_action_id;

        // Канонический cross-path ключ: sha256( lower(slug) | uniq_id ) —
        // та же формула, что в Cashback_API_Client::insert_missing_transaction
        // и webhook-receiver (app/db.py). idx_idempotency_key → настоящий
        // cross-path exactly-once backstop (webhook / cron / manual коллизируют
        // на одном логическом действии). $action_id теперь == выход
        // универсального резолвера (единый код-пас).
        $idempotency_key = hash('sha256', strtolower($network) . '|' . $action_id);

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

        // Money-поля (comission/sum_order) — canonical decimal-string + `%s` (F-4-004).
        $data = array(
            'user_id'         => $is_unregistered ? $user_id : (int) $user_id,
            'uniq_id'         => $action_id,
            'order_number'    => $order_id,
            // Имя сети из конфига (как пишет webhook-receiver: "Admitad"),
            // а не slug. idempotency_key ниже остаётся на strtolower($network)
            // — отдельный cross-path ключ, зеркало Python-receiver.
            'partner'         => $network_config['name'] ?? $network,
            'comission'       => number_format($payment, 2, '.', ''),
            'sum_order'       => number_format($cart, 2, '.', ''),
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
            '%s',  // comission (money → decimal-string)
            '%s',  // sum_order (money → decimal-string)
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

        // applied_cashback_rate и cashback заполняются BEFORE INSERT-триггером
        // calculate_cashback_before_insert (mariadb.php) — он читает rate из
        // user_profile и считает cashback = ROUND(comission * rate / 100).
        // Поля НЕ передаём в $data — БД проставит сама.

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
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging (debug only).
                error_log('[cashback] api-validation add_transaction: ' . $error);
            }
            wp_send_json_error(array( 'message' => 'Ошибка добавления транзакции. Подробности записаны в журнал.' ));
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

        // 12c ADR (F-4-002): TX + SELECT FOR UPDATE — см. комментарий в ajax_edit_transaction.
        $wpdb->query('START TRANSACTION');

        // Проверяем существование и текущий статус.
        $current = $wpdb->get_row($wpdb->prepare(
            'SELECT id, order_status, comission, sum_order, applied_cashback_rate FROM %i WHERE id = %d FOR UPDATE',
            $table,
            $local_id
        ));

        if (!$current) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Транзакция не найдена' ));
        }

        // Маппинг статуса API → локальный
        $client         = Cashback_API_Client::get_instance();
        $network_config = $client->get_network_config($network);
        $status_map     = $network_config['status_map'] ?? array();
        $mapped_status  = $status_map[ strtolower($api_status) ] ?? 'waiting';

        // Money-поля (comission/sum_order) — canonical decimal-string + `%s` (F-4-004).
        // Status-validation и пересчёт cashback выполняют MariaDB-триггеры
        // (cashback_tr_validate_status_transition + calculate_cashback_before_update).
        $update_data    = array(
            'order_status' => $mapped_status,
            'comission'    => number_format($api_payment, 2, '.', ''),
            'sum_order'    => number_format($api_cart, 2, '.', ''),
            'api_verified' => 1,
        );
        $update_formats = array( '%s', '%s', '%s', '%d' );

        $updated = $wpdb->update(
            $table,
            $update_data,
            array( 'id' => $local_id ),
            $update_formats,
            array( '%d' )
        );

        if ($updated === false || $wpdb->last_error) {
            $last_error = $wpdb->last_error;
            $wpdb->query('ROLLBACK');
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging (debug only).
                error_log('[cashback] api-validation overwrite_transaction: ' . $last_error);
            }
            $signal_message = self::extract_trigger_signal_message($last_error);
            wp_send_json_error(array( 'message' => $signal_message ?? 'Ошибка обновления транзакции. Подробности записаны в журнал.' ));
        }

        $wpdb->query('COMMIT');

        // audit log — вне TX (см. ajax_edit_transaction).
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
    /**
     * Распознаёт SIGNAL SQLSTATE '45000' MESSAGE_TEXT от MariaDB-триггеров в
     * тексте `$wpdb->last_error` и возвращает локализованную строку для UI.
     *
     * Триггеры в mariadb.php (cashback_tr_validate_status_transition,
     * cashback_tr_prevent_delete_final_status, etc.) бросают `SIGNAL SQLSTATE
     * '45000' SET MESSAGE_TEXT = '...'` с готовым русскоязычным текстом —
     * пробрасываем его в JSON-ответ как user-facing сообщение.
     *
     * @param string $last_error Содержимое $wpdb->last_error
     * @return string|null Извлечённое user-friendly сообщение либо null если
     *                     ошибка не от status-validation триггера.
     */
    private static function extract_trigger_signal_message( string $last_error ): ?string {
        // Список MESSAGE_TEXT'ов из mariadb.php — должен совпадать с текстами
        // в cashback_tr_validate_status_transition[_unregistered] и
        // cashback_tr_prevent_delete_final_status / _update.
        $known_signals = array(
            'Удаление запрещено: запись с финальным статусом не может быть удалена.',
            'Изменение запрещено: запись с финальным статусом не может быть изменена.',
            'Понижение статуса до waiting запрещено.',
            'Перевод в balance возможен только из completed.',
            'Перевод в hold возможен только из completed.',
            'Из declined возможен переход только в completed.',
        );

        foreach ($known_signals as $signal_text) {
            if (strpos($last_error, $signal_text) !== false) {
                return $signal_text;
            }
        }

        return null;
    }

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

            <?php
            // Последняя проверка. Используем campaign_check_timestamp (пишет
            // ручной запуск), если он есть — иначе fallback на общий
            // timestamp полного cron-прогона. И фильтруем blob по списку
            // slug'ов активных в БД сетей, чтобы stale-записи отключённых
            // сетей (например «tes — No adapter found») не зависали в плашке.
            $last_sync       = get_option('cashback_last_sync_result', array());
            $known_slugs     = array();
            foreach ($networks as $known_net) {
                if (!empty($known_net['slug'])) {
                    $known_slugs[] = (string) $known_net['slug'];
                }
            }
            $campaign_check_blob = (isset($last_sync['campaign_check']) && is_array($last_sync['campaign_check']))
                ? $last_sync['campaign_check']
                : array();
            $campaign_check_blob = array_intersect_key(
                $campaign_check_blob,
                array_flip($known_slugs)
            );
            $last_check_ts = (string) ( $last_sync['campaign_check_timestamp'] ?? $last_sync['timestamp'] ?? '' );
            ?>
            <?php if (!empty($campaign_check_blob)) : ?>
                <div class="notice notice-info inline" style="margin: 10px 0;">
                    <p><strong>Последняя проверка:</strong> <?php echo esc_html($last_check_ts !== '' ? $last_check_ts : '—'); ?></p>
                    <?php foreach ($campaign_check_blob as $net => $cr) : ?>
                        <p>
                            <strong><?php echo esc_html(strtoupper((string) $net)); ?>:</strong>
                            <?php if ($cr['success'] ?? false) : ?>
                                кампаний: <?php echo (int) ( $cr['total_campaigns'] ?? 0 ); ?>,
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

            // Обновляем плашку «Последняя проверка» в админ-UI без ожидания
            // следующего cron-прогона (cashback_api_sync_statuses раз в 2 ч).
            self::persist_campaign_check_results($results, $only_slug);

            wp_send_json_success($results);
        } catch (Exception $e) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging (debug only).
                error_log('[cashback] api-validation check_campaigns: ' . $e->getMessage());
            }
            wp_send_json_error('Ошибка проверки кампаний. Подробности записаны в журнал.');
        }
    }

    /**
     * Merge'ит per-slug результат `check_campaign_statuses()` в опцию
     * `cashback_last_sync_result['campaign_check']`, чтобы верхняя плашка
     * «Последняя проверка» отображала актуальное состояние сразу после
     * ручного запуска, не дожидаясь следующего cron-прогона.
     *
     * Контракт (см. CampaignCheckResultPersistenceTest):
     *  - `$only_slug !== null` — per-slug merge: чужие сети в blob сохраняются.
     *    Если для $only_slug в $results нет записи (например, сеть отключена
     *    в БД), stale-запись по этому slug в blob удаляется.
     *  - `$only_slug === null` — full replace: весь `campaign_check` заменяется.
     *  - Пишем отдельный `campaign_check_timestamp`. Общий `timestamp` blob'а
     *    принадлежит cron'у полной синхронизации — НЕ перезаписываем.
     *  - Defensive: если опция malformed (скаляр, объект, null) — нормализуем
     *    к пустому массиву.
     *
     * @param array<string, array<string, mixed>> $results   `[slug => result]` из check_campaign_statuses()
     * @param string|null                         $only_slug Slug сети, если запуск точечный; null для full sync
     */
    public static function persist_campaign_check_results( array $results, ?string $only_slug ): void {
        $blob = get_option('cashback_last_sync_result', array());
        if (!is_array($blob)) {
            $blob = array();
        }

        $campaign_check = isset($blob['campaign_check']) && is_array($blob['campaign_check'])
            ? $blob['campaign_check']
            : array();

        if ($only_slug === null) {
            // Full replace — все сети, по которым прошёл cron-прогон.
            $campaign_check = $results;
        } elseif (array_key_exists($only_slug, $results)) {
            $campaign_check[ $only_slug ] = $results[ $only_slug ];
        } else {
            // Сеть отключена/удалена → удаляем stale запись из плашки.
            unset($campaign_check[ $only_slug ]);
        }

        $blob['campaign_check']           = $campaign_check;
        $blob['campaign_check_timestamp'] = Cashback_Time::now_mysql();

        update_option('cashback_last_sync_result', $blob, false);
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

        // Явная реактивация админом → отдаёт товар в авто-режим, чтобы
        // последующие циклы «отключили / включили кампанию» обрабатывались
        // автоматически без ручных действий (см. Cashback_Product_Autopublish).
        update_post_meta($product_id, '_cashback_auto_publish_enabled', '1');

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
