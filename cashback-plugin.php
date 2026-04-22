<?php

declare(strict_types=1);

// phpcs:ignore PSR12.Files.FileHeader.IncorrectOrder -- WordPress plugin header docblock must precede bootstrap guard.
/**
 * Plugin Name: Cashback Plugin
 * Description: Объединенный плагин для системы кэшбэка и аффилиат-партнерства
 * Version: 1.2.0
 * Author: Cashback
 * Author URI: https://example.com
 * Text Domain: cashback-plugin
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.3
 * WC requires at least: 5.0
 * WC tested up to: 9.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * GitHub Plugin URI: Igor-creato/cash_back_plugin
 * Primary Branch: sekuriti
 */

// Запрет прямого доступа
defined('ABSPATH') || die('No script kiddies please!');

// Минимальные требования к версиям
define('CASHBACK_MIN_PHP_VERSION', '8.3');
define('CASHBACK_MIN_WP_VERSION', '6.2');
define('CASHBACK_MIN_WC_VERSION', '5.0');

/**
 * Генерация UUID v7 (RFC 9562) — time-ordered UUID.
 *
 * Структура (128 бит):
 *  - 48 бит: unix timestamp в миллисекундах
 *  - 4 бита: версия (0111 = 7)
 *  - 12 бит: random
 *  - 2 бита: вариант (10)
 *  - 62 бита: random
 *
 * @param bool $with_dashes true = стандартный формат (36 символов), false = только hex (32 символа)
 * @return string UUID v7
 */
function cashback_generate_uuid7( bool $with_dashes = true ): string {
    $time  = (int) ( microtime(true) * 1000 );
    $bytes = random_bytes(16);

    // 48-bit timestamp (bytes 0-5)
    $bytes[0] = chr(( $time >> 40 ) & 0xFF);
    $bytes[1] = chr(( $time >> 32 ) & 0xFF);
    $bytes[2] = chr(( $time >> 24 ) & 0xFF);
    $bytes[3] = chr(( $time >> 16 ) & 0xFF);
    $bytes[4] = chr(( $time >> 8 ) & 0xFF);
    $bytes[5] = chr($time & 0xFF);

    // Version 7 (bits 48-51)
    $bytes[6] = chr(( ord($bytes[6]) & 0x0F ) | 0x70);

    // Variant 10xx (bits 64-65)
    $bytes[8] = chr(( ord($bytes[8]) & 0x3F ) | 0x80);

    $hex = bin2hex($bytes);

    if (!$with_dashes) {
        return $hex;
    }

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

/**
 * Проверка совместимости с текущими версиями PHP и WordPress
 *
 * @return void
 */
function cashback_check_requirements() {
    $errors = array();

    // Проверка версии PHP
    if (version_compare(PHP_VERSION, CASHBACK_MIN_PHP_VERSION, '<')) {
        $errors[] = sprintf(
            /* translators: 1: Current PHP version, 2: Required PHP version */
            __('Cashback Plugin requires PHP %2$s or higher. You are running PHP %1$s.', 'cashback-plugin'),
            PHP_VERSION,
            CASHBACK_MIN_PHP_VERSION
        );
    }

    // Проверка версии WordPress
    if (version_compare(get_bloginfo('version'), CASHBACK_MIN_WP_VERSION, '<')) {
        $errors[] = sprintf(
            /* translators: 1: Current WordPress version, 2: Required WordPress version */
            __('Cashback Plugin requires WordPress %2$s or higher. You are running WordPress %1$s.', 'cashback-plugin'),
            get_bloginfo('version'),
            CASHBACK_MIN_WP_VERSION
        );
    }

    // Проверка версии WooCommerce (если установлен)
    if (defined('WC_VERSION') && version_compare(WC_VERSION, CASHBACK_MIN_WC_VERSION, '<')) {
        $errors[] = sprintf(
            /* translators: 1: Current WooCommerce version, 2: Required WooCommerce version */
            __('Cashback Plugin requires WooCommerce %2$s or higher. You are running WooCommerce %1$s.', 'cashback-plugin'),
            WC_VERSION,
            CASHBACK_MIN_WC_VERSION
        );
    }

    // Если есть ошибки, деактивируем плагин и показываем сообщение
    if (!empty($errors)) {
        deactivate_plugins(plugin_basename(__FILE__));

        $error_message  = '<h1>' . esc_html__('Plugin Activation Error', 'cashback-plugin') . '</h1>';
        $error_message .= '<p><strong>' . esc_html__('Cashback Plugin', 'cashback-plugin') . '</strong></p>';
        $error_message .= '<ul>';
        foreach ($errors as $error) {
            $error_message .= '<li>' . esc_html($error) . '</li>';
        }
        $error_message .= '</ul>';

        wp_die(
            wp_kses_post($error_message),
            esc_html__('Plugin Activation Error', 'cashback-plugin'),
            array( 'back_link' => true )
        );
    }
}

// Проверяем требования при активации плагина
register_activation_hook(__FILE__, 'cashback_check_requirements');

/**
 * Основной класс плагина Cashback
 */
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- Main plugin bootstrap file mixes class definitions with helper functions by design.
class CashbackPlugin {

    private const ACTIVATION_ERROR_TITLE = 'Ошибка активации плагина';

    /**
     * Конструктор класса
     */
    public function __construct() {
        register_activation_hook(__FILE__, array( $this, 'activate' ));
        register_deactivation_hook(__FILE__, array( $this, 'deactivate' ));
        add_action('plugins_loaded', array( $this, 'init' ));
        add_action('init', array( $this, 'load_textdomain' ));
        // One-time миграция plaintext-секретов в wp_options → ENC:v1:ciphertext.
        // Запуск после load_dependencies (prio=10 в init), идемпотентна (guard по флагу).
        add_action('plugins_loaded', array( 'Cashback_Encryption', 'migrate_plaintext_options' ), 100);
        // Миграция группы 6 (шаг 2 ADR): schema-level idempotency. Идемпотентна (guard по флагу),
        // auto-run на plugins_loaded чтобы подхватить upgrade без re-activation плагина.
        add_action('plugins_loaded', array( 'CashbackPlugin', 'migrate_schema_idempotency_v1' ), 110);
        add_action('admin_notices', array( 'CashbackPlugin', 'schema_idempotency_blocked_notice' ));
        // Миграция группы 7 (шаг 3 ADR): создание таблицы cashback_rate_limit_counters
        // для атомарного INSERT ... ON DUPLICATE KEY UPDATE (SQL rate-limit backend).
        add_action('plugins_loaded', array( 'CashbackPlugin', 'migrate_rate_limit_v1' ), 115);
        // Миграция группы 8 (шаг 3 ADR): создание таблицы cashback_cron_state
        // для checkpoint-истории прогонов cashback_api_sync cron.
        add_action('plugins_loaded', array( 'Cashback_Cron_State', 'migrate_v1' ), 118);
        // GC группы 7 (шаг 10 ADR): hourly очистка expired rate-limit counters.
        add_action('cashback_rate_limit_gc_cron', array( 'CashbackPlugin', 'rate_limit_gc_cron_handler' ));
        add_action('before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ));
        // Единая пагинация — CSS для админки (используется на всех cashback-* страницах).
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_pagination_admin_assets' ));
        // WooCommerce транзакционные письма используют собственный фильтр woocommerce_email_from_name
        add_filter('woocommerce_email_from_name', function ( string $name ): string {
            $custom = (string) get_option('cashback_email_sender_name', '');
            return trim($custom) !== '' ? $custom : $name;
        });
        // WordPress core и прочие письма — приоритет 20 перекрывает WC_Emails (приоритет 10)
        add_filter('wp_mail_from_name', function ( string $name ): string {
            $custom = (string) get_option('cashback_email_sender_name', '');
            return trim($custom) !== '' ? $custom : $name;
        }, 20);
    }

    /**
     * Загрузка текстового домена для переводов
     *
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'cashback-plugin',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Подключение CSS единой пагинации на cashback-страницах админки.
     *
     * Срабатывает на admin_enqueue_scripts и загружает pagination-admin.css только
     * на страницах плагина (hook 'toplevel_page_cashback-overview' и sub-hook'ах
     * '*_page_cashback-*'), чтобы не нагружать остальные экраны админки.
     *
     * @param string $hook Hook-идентификатор текущей админ-страницы.
     * @return void
     */
    public function enqueue_pagination_admin_assets( string $hook ): void {
        if (strpos($hook, 'cashback-') === false) {
            return;
        }
        wp_enqueue_style(
            'cashback-pagination-admin',
            plugins_url('assets/css/pagination-admin.css', __FILE__),
            array(),
            '1.0.0'
        );
    }

    /**
     * Объявление совместимости с функциями WooCommerce
     *
     * @return void
     */
    public function declare_woocommerce_compatibility() {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }

    /**
     * Метод активации плагина
     */
    public function activate() {
        // Проверка обязательного расширения BCMath (используется для точных вычислений с балансами)
        if (!extension_loaded('bcmath')) {
            wp_die(
                wp_kses_post( '<h1>' . self::ACTIVATION_ERROR_TITLE . '</h1>' .
                    '<p><strong>Cashback Plugin:</strong> Требуется PHP-расширение <code>bcmath</code>. ' .
                    'Установите его и повторите активацию.</p>' ),
                esc_html( self::ACTIVATION_ERROR_TITLE ),
                array( 'back_link' => true )
            );
        }

        // Подключаем утилиту шифрования (используется в миграции при активации)
        $this->require_file('includes/class-cashback-encryption.php');

        // Автоматически генерируем ключ шифрования (wp-content/.cashback-encryption-key.php)
        $this->maybe_generate_encryption_key();

        // Подключаем файл mariadb.php для активации
        $this->require_file('mariadb.php');
        // Активация основного функционала (таблицы, триггеры, события)
        if (class_exists('Mariadb_Plugin')) {
            try {
                Mariadb_Plugin::activate();
            } catch (Exception $e) {
                // Логируем детальную ошибку
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback Plugin Activation Error: ' . $e->getMessage());
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Stack trace: ' . $e->getTraceAsString());
                // Показываем пользователю
                wp_die(
                    wp_kses_post( '<h1>' . self::ACTIVATION_ERROR_TITLE . '</h1>' .
                        '<p><strong>Cashback Plugin:</strong> ' . esc_html($e->getMessage()) . '</p>' .
                        '<p>Проверьте логи ошибок для получения дополнительной информации.</p>' ),
                    esc_html( self::ACTIVATION_ERROR_TITLE ),
                    array( 'back_link' => true )
                );
            }
        } else {
            wp_die(
                wp_kses_post( '<h1>' . self::ACTIVATION_ERROR_TITLE . '</h1>' .
                    '<p><strong>Cashback Plugin:</strong> Класс Mariadb_Plugin не найден.</p>' ),
                esc_html( self::ACTIVATION_ERROR_TITLE ),
                array( 'back_link' => true )
            );
        }

        // Создание таблиц поддержки и директории для вложений
        $this->require_file('support/support-db.php');
        if (class_exists('Cashback_Support_DB')) {
            Cashback_Support_DB::create_tables();
            Cashback_Support_DB::ensure_upload_dir();
        }

        // Планируем cron для автоудаления закрытых тикетов (через 1 месяц)
        if (!wp_next_scheduled('cashback_support_auto_delete_cron')) {
            wp_schedule_event(time(), 'daily', 'cashback_support_auto_delete_cron');
        }

        // Планируем cron для мониторинга целостности данных
        if (!wp_next_scheduled('cashback_health_check_cron')) {
            wp_schedule_event(time(), 'daily', 'cashback_health_check_cron');
        }

        // Группа 7 (шаг 10 ADR): hourly GC для cashback_rate_limit_counters.
        if (!wp_next_scheduled('cashback_rate_limit_gc_cron')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'cashback_rate_limit_gc_cron');
        }

        // Создание таблиц антифрод-модуля
        $this->require_file('antifraud/class-fraud-db.php');
        if (class_exists('Cashback_Fraud_DB')) {
            Cashback_Fraud_DB::create_tables();
        }

        // Одноразовая миграция: автоматически dismiss-нуть legacy multi_account_ip
        // алерты для mobile/CGNAT/private IP (после внедрения IP Intelligence в Этапе 2).
        // Идемпотентна — флаг 'cashback_fraud_legacy_cgnat_dismissed_at'.
        $this->require_file('antifraud/class-fraud-ip-intelligence.php');
        $this->require_file('antifraud/class-fraud-admin.php');
        if (class_exists('Cashback_Fraud_Admin')) {
            Cashback_Fraud_Admin::migrate_dismiss_legacy_cgnat_alerts();
        }

        // Опция-флаг для legacy-согласий (юзеры до этой даты освобождены от
        // явного opt-in; см. Cashback_Fraud_Consent::has_consent).
        $this->require_file('includes/class-cashback-fraud-consent.php');
        if (class_exists('Cashback_Fraud_Consent')) {
            Cashback_Fraud_Consent::ensure_required_after_option();
        }

        // Создание таблиц affiliate-модуля (реферальная программа)
        $this->require_file('affiliate/class-affiliate-db.php');
        if (class_exists('Cashback_Affiliate_DB')) {
            Cashback_Affiliate_DB::create_tables();
            Cashback_Affiliate_DB::migrate_accruals_pending_statuses();
        }

        // Создание таблиц claims-модуля (неначисленный кэшбэк)
        $this->require_file('claims/class-claims-db.php');
        if (class_exists('Cashback_Claims_DB')) {
            Cashback_Claims_DB::create_tables();
            Cashback_Claims_DB::migrate_add_is_read();
            Cashback_Claims_DB::migrate_add_is_read_admin();
        }

        // Создание таблицы уведомлений
        $this->require_file('notifications/class-cashback-notifications-db.php');
        if (class_exists('Cashback_Notifications_DB')) {
            Cashback_Notifications_DB::create_tables();
        }

        // Social Auth: создание таблиц и default-опций модуля соц-авторизации.
        $this->require_file('includes/social-auth/class-social-auth-bootstrap.php');
        if (class_exists('Cashback_Social_Auth_Bootstrap')) {
            Cashback_Social_Auth_Bootstrap::activate();
        }

        // Планируем cron для антифрод-детекции (ежечасно)
        if (!wp_next_scheduled('cashback_fraud_detection_cron')) {
            wp_schedule_event(time(), 'hourly', 'cashback_fraud_detection_cron');
        }

        // Планируем cron для очистки старых fingerprints (ежедневно)
        if (!wp_next_scheduled('cashback_fraud_cleanup_cron')) {
            wp_schedule_event(time(), 'daily', 'cashback_fraud_cleanup_cron');
        }

        // Планируем cron для cluster detection (ежечасно).
        if (!wp_next_scheduled('cashback_fraud_cluster_detect_cron')) {
            wp_schedule_event(time(), 'hourly', 'cashback_fraud_cluster_detect_cron');
        }

        // Миграция планировщика: снимаем устаревшие WP-Cron события для задач,
        // переведённых на Action Scheduler. Повторная постановка AS-actions
        // произойдёт автоматически на init (см. Cashback_Broadcast::__construct,
        // Cashback_Notifications::__construct, Cashback_API_Cron::maybe_schedule).
        $as_migrated_hooks = array(
            'cashback_broadcast_process',
            'cashback_notification_process_queue',
            'cashback_api_sync_statuses',
        );
        foreach ($as_migrated_hooks as $legacy_hook) {
            wp_clear_scheduled_hook($legacy_hook);
        }

        // Регистрируем endpoints перед flush, т.к. init хук ещё не сработал
        add_rewrite_endpoint('cashback-withdrawal', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('cashback-history', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('history-payout', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('cashback-support', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('cashback-affiliate', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('cashback_lost_cashback', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('cashback-notifications', EP_ROOT | EP_PAGES);

        // Сбрасываем переписывание URL
        flush_rewrite_rules();
    }

    /**
     * Метод деактивации плагина
     */
    public function deactivate() {
        // WP-Cron хуки (остаются на нативном планировщике)
        $cron_hooks = array(
            'cashback_support_auto_delete_cron',
            'cashback_health_check_cron',
            'cashback_fraud_detection_cron',
            'cashback_fraud_cleanup_cron',
            'cashback_fraud_cluster_detect_cron',
            'cashback_rate_limit_gc_cron',
        );

        foreach ($cron_hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }

        // Action Scheduler хуки (переведены на AS в рамках миграции планировщика)
        $as_hooks = array(
            'cashback_api_sync_statuses',          // API Валидация: фоновая синхронизация
            'cashback_notification_process_queue', // Обработка очереди уведомлений
            'cashback_broadcast_process',          // Обработка очереди массовых рассылок
        );

        foreach ($as_hooks as $hook) {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions($hook, array(), 'cashback');
            }
            // Legacy cleanup: возможны устаревшие WP-Cron события от старых версий плагина
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * Инициализация основного функционала плагина
     */
    public function init() {
        // Проверяем, что WooCommerce активирован
        if (class_exists('WooCommerce')) {
            $this->load_dependencies();

            // Автомиграция ПЕРЕД initialize_components — колонки должны существовать до регистрации хуков
            $this->maybe_run_migrations();

            $this->initialize_components();

            // Одноразовый сброс rewrite rules после обновления кода
            add_action('init', function () {
                if (get_transient('cashback_flush_rewrite_rules')) {
                    delete_transient('cashback_flush_rewrite_rules');
                    flush_rewrite_rules();
                }
            }, 999);

            // Предупреждение если ключ шифрования не настроен
            if (class_exists('Cashback_Encryption') && !Cashback_Encryption::is_configured()) {
                add_action('admin_notices', array( $this, 'encryption_key_missing_notice' ));
            }

            // Предупреждение если триггеры не созданы
            if (get_option('cashback_triggers_active') === false) {
                add_action('admin_notices', array( $this, 'triggers_unavailable_notice' ));
            }
        } else {
            add_action('admin_notices', array( $this, 'woocommerce_required_notice' ));
        }
    }

    /**
     * Загрузка зависимостей плагина
     */
    public function load_dependencies() {
        // Подключаем ключ шифрования из wp-content/.cashback-encryption-key.php
        $this->load_encryption_key();

        // Утилита шифрования (загружаем первой, т.к. используется в других компонентах)
        $this->require_file('includes/class-cashback-encryption.php');

        // Recovery-флоу при утере ключа: admin-страница + Action Scheduler hook.
        // Загружается всегда (не только в админке), чтобы AS-хендлер был доступен при обработке cron-очереди.
        $this->require_file('admin/class-cashback-encryption-recovery.php');

        // Ротация ключа шифрования (dual-key online): admin-страница + AS hooks
        // (migrate/sanity/rollback/cleanup). Загружается всегда — AS-хендлеры должны
        // быть доступны в cron-контексте независимо от is_admin().
        $this->require_file('admin/class-cashback-key-rotation.php');

        // SSRF-guard для исходящих HTTP-запросов (использует Cashback_Encryption::write_audit_log)
        $this->require_file('includes/class-cashback-outbound-http-guard.php');

        // Бот-защита: rate limiter + CAPTCHA + guard (загружаем рано, до компонентов с AJAX)
        $this->require_file('includes/class-cashback-rate-limiter.php');
        $this->require_file('includes/class-cashback-captcha.php');
        $this->require_file('includes/class-cashback-bot-protection.php');

        // Утилита проверки статуса пользователя (для блокировки забаненных)
        $this->require_file('includes/class-cashback-user-status.php');

        // Server-side дедуп request_id (Группа 5 ADR) — общий helper для admin-AJAX хендлеров.
        // Подключается рано: используется в разных AJAX-обработчиках (payouts/transactions/claims).
        $this->require_file('includes/class-cashback-idempotency.php');

        // PHP-фолбэки для логики MySQL-триггеров
        $this->require_file('includes/class-cashback-trigger-fallbacks.php');

        // Подключение зависимых файлов (общие — нужны на фронтенде и в админке)
        $this->require_file('mariadb.php');
        $this->require_file('cashback-history.php');
        $this->require_file('cashback-withdrawal.php');
        $this->require_file('history-payout.php');
        $this->require_file('wc-affiliate-url-params.php');

        // Модуль поддержки (support-db и user-support нужны на фронтенде)
        $this->require_file('support/support-db.php');
        $this->require_file('support/user-support.php');

        // Антифрод: collector нужен на фронтенде (fingerprint), detector — для WP Cron
        $this->require_file('antifraud/class-fraud-db.php');
        $this->require_file('antifraud/class-fraud-settings.php');
        // IP Intelligence — резолв IP в ASN/connection_type. Подключается ДО collector/detector,
        // потому что Cashback_Fraud_Device_Id::record() и detector используют его для обогащения.
        $this->require_file('antifraud/class-fraud-ip-intelligence.php');
        // Persistent device IDs (UUID v4 + FingerprintJS visitor IDs). Должен загружаться ДО
        // collector — collector::handle_fingerprint_ajax() вызывает Cashback_Fraud_Device_Id::record().
        $this->require_file('antifraud/class-fraud-device-id.php');
        $this->require_file('antifraud/class-fraud-collector.php');
        $this->require_file('antifraud/class-fraud-detector.php');
        // Cluster Detector (Этап 5): периодический union-find по device/payment/email связям.
        $this->require_file('antifraud/class-fraud-cluster-detector.php');
        if (class_exists('Cashback_Fraud_Cluster_Detector')) {
            Cashback_Fraud_Cluster_Detector::register_cron();
        }

        // Согласие 152-ФЗ ст. 9 на сбор технических данных устройства
        // (чекбокс на форме регистрации WC + хранение consent_at в user_meta).
        $this->require_file('includes/class-cashback-fraud-consent.php');

        // Health-check cron обработчик (WP Cron работает через фронтенд-запросы)
        $this->require_file('admin/health-check.php');

        // API адаптеры CPA-сетей (загружаются перед API-клиентом)
        $this->require_file('includes/adapters/interface-cashback-network-adapter.php');
        $this->require_file('includes/adapters/abstract-cashback-network-adapter.php');
        $this->require_file('includes/adapters/class-admitad-adapter.php');
        $this->require_file('includes/adapters/class-epn-adapter.php');

        // Глобальный lock для атомарной синхронизации + начисления
        $this->require_file('includes/class-cashback-lock.php');

        // Единая пагинация (используется и в админке, и во фронтенде)
        $this->require_file('includes/class-cashback-pagination.php');

        // Checkpoint-хранилище cron-прогонов (Group 8 Step 3, F-8-005)
        $this->require_file('includes/class-cashback-cron-state.php');

        // API клиент и cron (синхронизация работает через WP Cron)
        $this->require_file('includes/class-cashback-api-client.php');
        $this->require_file('includes/class-cashback-api-cron.php');

        // --- REST API для браузерного расширения ---
        $this->require_file('includes/class-cashback-rest-api.php');

        // Шорткоды (доступны на фронтенде и в превью редактора)
        $this->require_file('includes/class-cashback-shortcodes.php');

        // Контактная форма (шорткод, доступен без авторизации)
        $this->require_file('includes/class-cashback-contact-form.php');

        // Affiliate module (реферальная программа)
        $this->require_file('affiliate/class-affiliate-db.php');
        $this->require_file('affiliate/class-affiliate-antifraud.php');
        $this->require_file('affiliate/class-affiliate-service.php');
        $this->require_file('affiliate/class-affiliate-frontend.php');

        // Claims module (неначисленный кэшбэк) — загружается везде (фронт + админ + AJAX)
        $this->require_file('claims/class-claims-db.php');
        $this->require_file('claims/class-claims-eligibility.php');
        $this->require_file('claims/class-claims-scoring.php');
        $this->require_file('claims/class-claims-antifraud.php');
        $this->require_file('claims/class-claims-manager.php');
        $this->require_file('claims/class-claims-notifications.php');
        $this->require_file('claims/class-claims-frontend.php');

        // Notifications module (email-уведомления) — загружается везде (фронт + админ + AJAX)
        $this->require_file('includes/class-cashback-theme-color.php');
        $this->require_file('notifications/class-cashback-notifications-db.php');
        $this->require_file('notifications/class-cashback-email-sender.php');
        $this->require_file('notifications/class-cashback-email-builder.php');
        $this->require_file('notifications/class-cashback-password-reset-email.php');
        $this->require_file('notifications/class-cashback-notifications.php');
        $this->require_file('notifications/class-cashback-notifications-frontend.php');
        $this->require_file('notifications/class-cashback-broadcast.php');

        // Admin-only файлы (is_admin() = true для admin pages, admin-ajax.php, REST через admin)
        if (is_admin()) {
            $this->require_file('admin/payout-methods.php');
            $this->require_file('admin/users-management.php');
            $this->require_file('admin/payouts.php');
            $this->require_file('admin/bank-management.php');
            $this->require_file('admin/click-log.php');
            $this->require_file('admin/transactions.php');
            $this->require_file('admin/statistics.php');
            $this->require_file('admin/rate-history.php');
            $this->require_file('admin/cron-history.php');
            $this->require_file('partner/partner-management.php');
            $this->require_file('support/admin-support.php');
            $this->require_file('antifraud/class-fraud-admin.php');
            $this->require_file('admin/class-cashback-admin-api-validation.php');
            $this->require_file('admin/class-cashback-admin-outbound-allowlist.php');
            $this->require_file('affiliate/class-affiliate-admin.php');
            $this->require_file('claims/class-claims-admin.php');
            $this->require_file('notifications/class-cashback-notifications-admin.php');
        }

        // Social Auth module (Яндекс ID + VK ID) — подключаем bootstrap, он загружает остальное.
        $this->require_file('includes/social-auth/class-social-auth-bootstrap.php');
    }

    /**
     * Запуск одноразовых миграций без реактивации плагина.
     * Каждая миграция защищена опцией-флагом (идемпотентно).
     */
    private function maybe_run_migrations(): void {
        if (!class_exists('Mariadb_Plugin')) {
            return;
        }

        // Миграция: reference_id для таблиц транзакций.
        // Проверяем фактическое наличие колонки, а не флаг в wp_options
        // (флаг мог установиться при провалившейся миграции).
        global $wpdb;
        $col = $wpdb->get_results("SHOW COLUMNS FROM `{$wpdb->prefix}cashback_transactions` LIKE 'reference_id'");

        if (empty($col)) {
            try {
                $instance = Mariadb_Plugin::get_instance();
                $instance->migrate_add_transaction_reference_id();
                $instance->recreate_triggers();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] Auto-migration failed: ' . $e->getMessage());
            }
        } else {
            // Колонка есть, но бэкфилл мог не отработать (например, при предыдущей неудачной миграции).
            $empty_count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$wpdb->prefix}cashback_transactions` WHERE reference_id = ''"
            );
            if ($empty_count > 0) {
                try {
                    $instance = Mariadb_Plugin::get_instance();
                    $instance->migrate_add_transaction_reference_id();
                    $instance->recreate_triggers();
                } catch (\Throwable $e) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log('[Cashback] Auto-migration backfill failed: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Подключение файла
     *
     * @param string $filename Имя файла для подключения
     */
    private function require_file( $filename ) {
        $filepath = plugin_dir_path(__FILE__) . $filename;
        if (file_exists($filepath)) {
            require_once $filepath;
        } else {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log(sprintf('[Cashback Plugin] Required file not found: %s', $filepath));
        }
    }

    /**
     * Инициализация компонентов плагина
     */
    private function initialize_components() {
        // Бот-защита: инициализация guard (до других компонентов)
        if (class_exists('Cashback_Bot_Protection')) {
            Cashback_Bot_Protection::init();
        }

        // Механизм аварийного восстановления шифрования: admin-страница + AS-action.
        // init() идемпотентен — регистрирует хуки, включая admin_init/admin_notices
        // (срабатывают только в админке) и AS-hook (срабатывает в WP-cron).
        if (class_exists('Cashback_Encryption_Recovery')) {
            Cashback_Encryption_Recovery::init();
        }

        // Ротация ключа шифрования (online dual-key): submenu в админке +
        // admin_post_* хендлеры + AS-hooks (migrate/sanity/rollback/cleanup).
        if (class_exists('Cashback_Key_Rotation')) {
            Cashback_Key_Rotation::init();
        }

        // Инициализация Mariadb_Plugin (регистрирует user_register хук)
        // mariadb.php загружается в load_dependencies(), но его add_action('plugins_loaded', ...)
        // не срабатывает, т.к. plugins_loaded уже выполнен к этому моменту
        if (class_exists('Mariadb_Plugin')) {
            Mariadb_Plugin::get_instance();
        }

        // Инициализация компонентов
        if (class_exists('CashbackHistory')) {
            CashbackHistory::get_instance();
        }

        if (class_exists('CashbackWithdrawal')) {
            CashbackWithdrawal::get_instance();
        }

        if (class_exists('HistoryPayout')) {
            HistoryPayout::get_instance();
        }

        // Инициализация WC_Affiliate_URL_Params
        if (class_exists('WC_Affiliate_URL_Params')) {
            new WC_Affiliate_URL_Params();
        }

        // Инициализация модуля поддержки (кабинет пользователя)
        if (class_exists('Cashback_User_Support')) {
            Cashback_User_Support::get_instance();
        }

        // Инициализация антифрод-модуля
        if (class_exists('Cashback_Fraud_Collector')) {
            Cashback_Fraud_Collector::get_instance();
        }

        // Согласие 152-ФЗ: чекбокс регистрации + хуки сохранения consent.
        if (class_exists('Cashback_Fraud_Consent')) {
            Cashback_Fraud_Consent::init();
        }

        if (is_admin() && class_exists('Cashback_Fraud_Admin')) {
            new Cashback_Fraud_Admin();
        }

        if (is_admin() && class_exists('Cashback_Rate_History_Admin')) {
            Cashback_Rate_History_Admin::get_instance();
        }

        // --- API Валидация: админ-страница + AJAX (только в админке) ---
        if (is_admin() && class_exists('Cashback_Admin_API_Validation')) {
            Cashback_Admin_API_Validation::get_instance();
        }

        // --- API Валидация: cron фоновой синхронизации (фронт + админка) ---
        if (class_exists('Cashback_API_Cron')) {
            Cashback_API_Cron::init();
        }

        // --- REST API для браузерного расширения ---
        if (class_exists('Cashback_REST_API')) {
            Cashback_REST_API::get_instance();
        }

        // Шорткоды
        if (class_exists('Cashback_Shortcodes')) {
            Cashback_Shortcodes::get_instance();
        }

        // Контактная форма
        if (class_exists('Cashback_Contact_Form')) {
            Cashback_Contact_Form::get_instance();
        }

        // Affiliate module (реферальная программа)
        if (class_exists('Cashback_Affiliate_Service')) {
            Cashback_Affiliate_Service::get_instance();
        }
        if (class_exists('Cashback_Affiliate_Frontend')) {
            Cashback_Affiliate_Frontend::get_instance();
        }
        if (is_admin() && class_exists('Cashback_Affiliate_Admin')) {
            new Cashback_Affiliate_Admin();
        }

        // Claims module (неначисленный кэшбэк)
        if (class_exists('Cashback_Claims_Frontend')) {
            new Cashback_Claims_Frontend();
        }
        if (is_admin() && class_exists('Cashback_Claims_Admin')) {
            new Cashback_Claims_Admin();
        }

        // Notifications module (email-уведомления)
        // Заменяет Cashback_Claims_Notifications — все уведомления через единый модуль
        if (class_exists('Cashback_Notifications')) {
            new Cashback_Notifications();
        } elseif (class_exists('Cashback_Claims_Notifications')) {
            // Fallback: если модуль уведомлений не загружен — используем старый
            new Cashback_Claims_Notifications();
        }
        if (class_exists('Cashback_Email_Sender')) {
            Cashback_Email_Sender::get_instance();
        }
        if (class_exists('Cashback_Broadcast')) {
            Cashback_Broadcast::get_instance();
        }
        if (class_exists('Cashback_Notifications_Frontend')) {
            Cashback_Notifications_Frontend::get_instance();
        }
        if (is_admin() && class_exists('Cashback_Notifications_Admin')) {
            new Cashback_Notifications_Admin();
        }

        // Social Auth module — init через bootstrap (регистрирует REST-роуты и admin-страницу).
        if (class_exists('Cashback_Social_Auth_Bootstrap')) {
            Cashback_Social_Auth_Bootstrap::instance()->init();
        }
    }

    /**
     * Путь к файлу с основным ключом шифрования (CB_ENCRYPTION_KEY).
     */
    private function get_encryption_key_path(): string {
        return WP_CONTENT_DIR . '/.cashback-encryption-key.php';
    }

    /**
     * Путь к staging-файлу нового ключа (CB_ENCRYPTION_KEY_NEW).
     * Существует только в фазах ротации staging/migrating/migrated.
     */
    private function get_encryption_key_new_path(): string {
        return WP_CONTENT_DIR . '/.cashback-encryption-key.new.php';
    }

    /**
     * Путь к файлу предыдущего ключа (CB_ENCRYPTION_KEY_PREVIOUS).
     * Существует только в окне отката после finalize (7 дней по умолчанию).
     */
    private function get_encryption_key_previous_path(): string {
        return WP_CONTENT_DIR . '/.cashback-encryption-key.previous.php';
    }

    /**
     * Подключает файлы с ключами шифрования, если они существуют.
     *
     * Порядок:
     *  1. CB_ENCRYPTION_KEY — основной. Если уже определён в wp-config.php — не трогаем файл.
     *  2. CB_ENCRYPTION_KEY_NEW — staging-ключ во время dual-key ротации.
     *  3. CB_ENCRYPTION_KEY_PREVIOUS — предыдущий ключ в окне отката.
     *
     * Файлы NEW и PREVIOUS живут только на время ротации / окна отката,
     * их отсутствие — штатное состояние. См. Cashback_Key_Rotation.
     */
    private function load_encryption_key(): void {
        if (!defined('CB_ENCRYPTION_KEY')) {
            $primary = $this->get_encryption_key_path();
            if (file_exists($primary)) {
                require_once $primary;
            }
        }

        if (!defined('CB_ENCRYPTION_KEY_NEW')) {
            $new_file = $this->get_encryption_key_new_path();
            if (file_exists($new_file)) {
                require_once $new_file;
            }
        }

        if (!defined('CB_ENCRYPTION_KEY_PREVIOUS')) {
            $previous_file = $this->get_encryption_key_previous_path();
            if (file_exists($previous_file)) {
                require_once $previous_file;
            }
        }
    }

    /**
     * Генерирует ключ шифрования и сохраняет в отдельный файл wp-content/.cashback-encryption-key.php
     *
     * @return bool true если ключ уже существует или был успешно создан
     */
    private function maybe_generate_encryption_key(): bool {
        // Ключ уже определён (из файла или wp-config.php) — ничего не делаем
        if (defined('CB_ENCRYPTION_KEY')) {
            return true;
        }

        // Пробуем подключить существующий файл ключа
        $this->load_encryption_key();
        if (defined('CB_ENCRYPTION_KEY')) {
            return true;
        }

        $key_file = $this->get_encryption_key_path();
        $key_dir  = dirname($key_file);

        if (!is_writable($key_dir)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Plugin: Directory not writable for encryption key: ' . $key_dir);
            return false;
        }

        // Генерируем криптографически стойкий ключ
        $key = bin2hex(random_bytes(32));

        // Defence-in-depth: проверяем длину ключа
        if (strlen($key) !== 64) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Plugin: Generated encryption key has unexpected length: ' . strlen($key));
            return false;
        }

        $content = "<?php\n"
            . "/**\n"
            . " * Cashback Plugin — Encryption Key (auto-generated)\n"
            . " *\n"
            . " * WARNING: Do not share, commit to VCS, or delete this file.\n"
            . " * Loss of this key = loss of access to encrypted user payment details.\n"
            . " */\n"
            . "if (!defined('ABSPATH')) { exit; }\n"
            . "define('CB_ENCRYPTION_KEY', '{$key}');\n";

        $result = file_put_contents($key_file, $content, LOCK_EX);

        if ($result === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Plugin: Failed to write encryption key file: ' . $key_file);
            return false;
        }

        // Ограничиваем права доступа к файлу ключа (owner read/write only).
        if (function_exists('chmod')) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- chmod может выдавать warning на ФС без поддержки прав (Windows); сбой некритичен, файл уже создан.
            @chmod($key_file, 0600);
        }

        // Определяем константу для текущего запроса
        define('CB_ENCRYPTION_KEY', $key);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log('Cashback Plugin: Encryption key generated and saved to ' . $key_file);
        return true;
    }

    /**
     * Уведомление об отсутствии ключа шифрования.
     * Сообщает админу, что сохранение и чтение реквизитов выплат отключены
     * до восстановления ключа (fail-closed guard). См. ADR Группа 4 (F-1-001).
     */
    public function encryption_key_missing_notice() {
        $key_file = $this->get_encryption_key_path();
        printf(
            '<div class="notice notice-error"><p><strong>%s:</strong> %s</p><p>%s</p><p><strong>%s</strong> %s <code>%s</code></p></div>',
            esc_html__('Cashback Plugin: ключ шифрования не настроен', 'cashback-plugin'),
            esc_html__('Сохранение и чтение реквизитов выплат пользователей временно отключены.', 'cashback-plugin'),
            esc_html__('Возможные причины: каталог wp-content/ не доступен для записи, файл ключа удалён или повреждён, либо константа CB_ENCRYPTION_KEY задана некорректно.', 'cashback-plugin'),
            esc_html__('Внимание:', 'cashback-plugin'),
            esc_html__('если файл ключа был удалён, ранее зашифрованные реквизиты не могут быть расшифрованы — восстановите исходный файл из резервной копии. Ожидаемый путь:', 'cashback-plugin'),
            esc_html($key_file)
        );
    }

    public function triggers_unavailable_notice() {
        printf(
            '<div class="notice notice-warning"><p><strong>%s:</strong> %s</p></div>',
            esc_html__('Cashback Plugin', 'cashback-plugin'),
            esc_html__('MySQL-триггеры не были созданы (binary logging без SUPER привилегии). Плагин работает в режиме PHP-фолбэков. Для полной защиты данных на уровне БД обратитесь к хостинг-провайдеру.', 'cashback-plugin')
        );
    }

    /**
     * Группа 6 (шаг 2 ADR): schema-level idempotency миграция.
     * Static — чтобы вызываться из plugins_loaded без инстанса плагина.
     * Идемпотентна (внутренний guard по option cashback_schema_idempotency_v1_applied).
     */
    public static function migrate_schema_idempotency_v1(): void {
        global $wpdb;

        if (!class_exists('Cashback_Schema_Idempotency_Migration')) {
            require_once __DIR__ . '/includes/class-cashback-schema-idempotency-migration.php';
        }

        ( new Cashback_Schema_Idempotency_Migration($wpdb) )->run();
    }

    /**
     * Группа 7 (шаг 3 ADR): rate-limit counters schema миграция.
     * Создаёт {$wpdb->prefix}cashback_rate_limit_counters — хранилище для атомарного
     * INSERT ... ON DUPLICATE KEY UPDATE (Cashback_Rate_Limit_SQL_Counter).
     * Идемпотентна (guard по option cashback_rate_limit_v1_applied + CREATE TABLE IF NOT EXISTS).
     */
    public static function migrate_rate_limit_v1(): void {
        global $wpdb;

        if (!class_exists('Cashback_Rate_Limit_Migration')) {
            require_once __DIR__ . '/includes/class-cashback-rate-limit-migration.php';
        }

        ( new Cashback_Rate_Limit_Migration($wpdb) )->run();
    }

    /**
     * Группа 7 (шаг 10 ADR): hourly GC для cashback_rate_limit_counters.
     * Удаляет expired rows, batch-лимит 5000 (защита от OLTP-лока).
     */
    public static function rate_limit_gc_cron_handler(): void {
        if (!class_exists('Cashback_Rate_Limit_GC')) {
            require_once __DIR__ . '/includes/class-cashback-rate-limit-gc.php';
        }

        \Cashback_Rate_Limit_GC::cron_handler();
    }

    /**
     * Admin-notice: миграция группы 6 заблокирована из-за найденных дублей.
     * Сообщает админу, что нужно запустить tools/dedup-rows-*.php перед применением UNIQUE.
     */
    public static function schema_idempotency_blocked_notice(): void {
        $blocked = get_option('cashback_schema_idempotency_v1_blocked');
        if (!is_array($blocked) || empty($blocked['duplicate_checks'])) {
            return;
        }

        $parts = array();
        foreach ((array) $blocked['duplicate_checks'] as $scope => $count) {
            if ((int) $count > 0) {
                $parts[] = sprintf('%s: %d', (string) $scope, (int) $count);
            }
        }
        if (empty($parts)) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s:</strong> %s</p><p>%s</p><p><code>%s</code></p></div>',
            esc_html__('Cashback Plugin — миграция схемы заблокирована', 'cashback-plugin'),
            esc_html__('Обнаружены дубликаты, мешающие наложению UNIQUE-ключей (группа 6 ADR).', 'cashback-plugin'),
            /* translators: %s: comma-separated list of "scope: duplicate_groups_count" pairs */
            esc_html(sprintf(__('Группы дубликатов: %s. Запустите dedup-скрипты и повторите активацию плагина.', 'cashback-plugin'), implode(', ', $parts))),
            'wp eval-file wp-content/plugins/cash-back/tools/dedup-rows-&lt;table&gt;.php --confirm=yes'
        );
    }

    /**
     * Уведомление о необходимости установки WooCommerce
     */
    public function woocommerce_required_notice() {
        $message = sprintf(
            '<strong>%s</strong> %s',
            esc_html__('Cashback Plugin', 'cashback-plugin'),
            esc_html__('requires WooCommerce to be installed and active.', 'cashback-plugin')
        );
        printf('<div class="notice notice-error"><p>%s</p></div>', wp_kses_post($message));
    }
}

// Скрытие боковой навигации "Мой аккаунт" на мобильных устройствах
add_action('wp_head', static function (): void {
    if (!function_exists('is_account_page') || !is_account_page()) {
        return;
    }
    echo '<style id="cashback-hide-myaccount-nav-mobile">@media (max-width: 768px){.woocommerce-MyAccount-navigation{display:none!important;}}</style>';
}, 99);

// Инициализация плагина
new CashbackPlugin();
