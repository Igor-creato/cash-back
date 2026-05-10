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
 * Возвращает URL ассета плагина с встроенной cache-bust меткой (?cv=<filemtime>).
 *
 * Имя query-параметра `cv` (а не стандартного `?ver=`) выбрано намеренно:
 * плагины-оптимизаторы (Clearfy/WP-Rocket/Autoptimize) часто снимают
 * именно `?ver=`. При вызове передавать четвёртым аргументом
 * wp_enqueue_style/script значение null, чтобы WP не дописывал свой `?ver=`.
 *
 * @param string $relative_path относительный путь от корня плагина
 *                              (например 'assets/css/frontend.css').
 * @return string полный URL с cache-bust query
 */
function cashback_asset_url( string $relative_path ): string {
    $relative = ltrim($relative_path, '/');
    $absolute = plugin_dir_path(__FILE__) . $relative;
    $version  = file_exists($absolute) ? (string) filemtime($absolute) : '1';
    $url      = plugins_url($relative, __FILE__);

    return add_query_arg('cv', $version, $url);
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
        // Override WC-шаблона myaccount/dashboard.php — убираем стандартный
        // описательный абзац «From your account dashboard…» (нерелевантен:
        // у сервиса нет реальных WC-заказов, вкладки кабинета свои).
        add_filter('wc_get_template', array( $this, 'override_wc_dashboard_template' ), 10, 5);
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
     * Подменяет стандартный WC-шаблон myaccount/dashboard.php на версию плагина
     * (без описательного абзаца «From your account dashboard…»).
     *
     * Уважает theme override: если активная тема (или плагин с более высоким
     * приоритетом) уже переопределили шаблон, возвращаем $template без изменений.
     *
     * Заметка: $default_path в фильтре wc_get_template — это исходный параметр
     * функции wc_get_template(), он почти всегда пустой. Поэтому сравниваем с
     * фактическим default-путём WC через WC()->plugin_path().
     *
     * @param string $template      Текущий путь к шаблону.
     * @param string $template_name Имя шаблона (например 'myaccount/dashboard.php').
     * @param array  $args          Аргументы шаблона.
     * @param string $template_path Путь шаблона в теме.
     * @param string $default_path  Путь шаблона по умолчанию (внутри WC).
     * @return string
     */
    public function override_wc_dashboard_template(
        string $template,
        string $template_name,
        array $args,
        string $template_path,
        string $default_path
    ): string {
        unset($args, $template_path, $default_path);
        if ('myaccount/dashboard.php' !== $template_name) {
            return $template;
        }
        if (! function_exists('WC')) {
            return $template;
        }
        // Если тема уже переопределила — не трогаем (theme override приоритетнее).
        $wc_default = wp_normalize_path(WC()->plugin_path() . '/templates/' . $template_name);
        if (wp_normalize_path($template) !== $wc_default) {
            return $template;
        }
        $plugin_template = plugin_dir_path(__FILE__) . 'templates/myaccount/dashboard.php';
        return file_exists($plugin_template) ? $plugin_template : $template;
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

        // Подключаем helper времени (ADR utc-everywhere) — используется в миграциях
        // при активации (Cashback_Fraud_Admin::migrate_dismiss_legacy_cgnat_alerts и др.).
        // Должен быть доступен ДО mariadb.php / fraud-db / прочих require_file ниже.
        $this->require_file('includes/class-cashback-time.php');

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
            Cashback_Affiliate_DB::migrate_f22_003_attribution_model();
        }

        // Создание таблиц claims-модуля (неначисленный кэшбэк)
        $this->require_file('claims/class-claims-db.php');
        if (class_exists('Cashback_Claims_DB')) {
            Cashback_Claims_DB::create_tables();
            Cashback_Claims_DB::migrate_add_is_read();
            Cashback_Claims_DB::migrate_add_is_read_admin();
            Cashback_Claims_DB::migrate_add_scoring_breakdown();
        }

        // Создание таблицы уведомлений
        $this->require_file('notifications/class-cashback-notifications-db.php');
        if (class_exists('Cashback_Notifications_DB')) {
            Cashback_Notifications_DB::create_tables();
            Cashback_Notifications_DB::migrate_add_processing_token_and_last_error();
        }

        // Social Auth: создание таблиц и default-опций модуля соц-авторизации.
        $this->require_file('includes/social-auth/class-social-auth-bootstrap.php');
        if (class_exists('Cashback_Social_Auth_Bootstrap')) {
            Cashback_Social_Auth_Bootstrap::activate();
        }

        // SC Auth Pages: идемпотентный upsert WP-страниц /login/ и /register/.
        $this->require_file('includes/sc-auth-pages/class-sc-auth-pages-activator.php');
        if (class_exists('Cashback_SC_Auth_Pages_Activator')) {
            Cashback_SC_Auth_Pages_Activator::activate();
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

        // F-22-003 (Группа 12): ежедневный auto-promote low-confidence
        // рефералов (review_status='pending' → 'auto_approved') после
        // 14-дневного clean-periode. Callback batch-based + idempotent.
        if (!wp_next_scheduled('cashback_affiliate_auto_promote')) {
            wp_schedule_event(time(), 'daily', 'cashback_affiliate_auto_promote');
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
            'cashback_affiliate_auto_promote', // F-22-003
        );

        foreach ($cron_hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
            // Дополнительный safety-clear на случай нескольких запланированных
            // инстансов одного хука (F-22-003 auto-promote и пр.).
            wp_clear_scheduled_hook($hook);
        }

        // F-22-003 (Группа 12): явный clear для auto-promote cron — закрепляет
        // инвариант удаления именно этого hook при деактивации.
        wp_clear_scheduled_hook('cashback_affiliate_auto_promote');

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

        // Блокировка author enumeration (?author=N → /author/<slug>/ раскрывает username админа).
        $this->require_file('includes/class-cashback-author-enum-block.php');

        // Nginx fastcgi_cache purger (CPA-договор: пользователи должны видеть
        // актуальные ставки/данные товаров; default TTL nginx 30m даёт окно
        // несоответствия — закрываем хуками на изменение product/мета/таксономии).
        $this->require_file('includes/cache/class-cashback-nginx-cache-purger.php');
        $this->require_file('includes/cache/class-cashback-nginx-cache-hooks.php');

        // Утилита проверки статуса пользователя (для блокировки забаненных)
        $this->require_file('includes/class-cashback-user-status.php');

        // Запрет логина для забаненных юзеров (OBS-05 E2E run B 2026-04-30).
        // Приоритет 30 — после стандартных проверок (auth_cookie/password/wp_login_failed
        // на 20), чтобы не маскировать «неверный пароль».
        add_filter( 'wp_authenticate_user', array( 'Cashback_User_Status', 'block_banned_login' ), 30, 1 );

        // OBS-05 cosmetic: заменяем WC generic «Неверный логин/пароль» на наше
        // специфичное сообщение «Ваш аккаунт заблокирован...» для cashback_user_banned.
        add_filter( 'woocommerce_login_errors', array( 'Cashback_User_Status', 'override_wc_login_error' ), 20, 1 );

        // OBS-05 cosmetic (anti-mask): clearfy-pro и подобные anti-enumeration
        // плагины через `login_errors` (prio 10 default) затирают всё на generic
        // «Неверный логин/пароль». Hook'аемся на тот же filter с prio 50 (после
        // них) и читаем per-request flag из block_banned_login().
        add_filter( 'login_errors', array( 'Cashback_User_Status', 'override_login_error' ), 50, 1 );

        // Server-side дедуп request_id (Группа 5 ADR) — общий helper для admin-AJAX хендлеров.
        // Подключается рано: используется в разных AJAX-обработчиках (payouts/transactions/claims).
        $this->require_file('includes/class-cashback-idempotency.php');

        // Money VO (Группа 10 ADR, Step 3a) — immutable BCMath-based valueобъект для
        // денежных сумм. Убирает (float)-cast'ы, `%f` в $wpdb->prepare и ad-hoc BCMath
        // на money-путях (payouts, withdrawal, affiliate, ledger).
        $this->require_file('includes/class-cashback-money.php');

        // Time helper (ADR utc-everywhere) — единая точка работы со временем.
        // Все timestamp'ы плагина пишутся/сравниваются в UTC, отображение —
        // через wp_date() в зоне сайта. Замена смешивания current_time('mysql') и gmdate(...).
        $this->require_file('includes/class-cashback-time.php');

        // PHP-фолбэки для логики MySQL-триггеров
        $this->require_file('includes/class-cashback-trigger-fallbacks.php');

        // Ledger-write helper для ban/unban (Группа 14) — парная запись в
        // cashback_balance_ledger при заморозке/разморозке баланса пользователя.
        $this->require_file('includes/class-cashback-ban-ledger.php');

        // Анонимизация пользователя (152-ФЗ ст. 9 ч. 4 vs 115-ФЗ/161-ФЗ/НК ст. 23):
        // PII-скраб с сохранением финансовой первички. Класс используется в
        // pre_delete_user-хуке (ниже в initialize_components) и AJAX-handler'е
        // admin/users-management.php → handle_anonymize_user.
        $this->require_file('includes/class-cashback-user-anonymizer.php');

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

        // OAuth2 helper'ы (используются адаптерами)
        $this->require_file('includes/oauth/class-oauth2-client-credentials-helper.php');

        // API адаптеры CPA-сетей (загружаются перед API-клиентом)
        $this->require_file('includes/adapters/interface-cashback-network-adapter.php');
        $this->require_file('includes/adapters/abstract-cashback-network-adapter.php');
        // DTO для shop importer (v12)
        $this->require_file('includes/adapters/class-cashback-campaign-detail-dto.php');
        $this->require_file('includes/adapters/class-cashback-shop-tariff-dto.php');
        $this->require_file('includes/adapters/class-admitad-adapter.php');
        $this->require_file('includes/adapters/class-epn-adapter.php');

        // Промокоды: контракты, DTO, generic-движок (используется fetcher'ом + admin UI).
        $this->require_file('includes/promocodes/contracts/interface-coupons-adapter.php');
        $this->require_file('includes/promocodes/dto/class-coupon-dto.php');
        $this->require_file('includes/promocodes/class-coupons-field-mapper.php');
        $this->require_file('includes/promocodes/class-network-http-client.php');
        $this->require_file('includes/promocodes/class-coupons-adapter-registry.php');
        $this->require_file('includes/promocodes/adapters/class-generic-json-coupons-adapter.php');
        $this->require_file('includes/promocodes/class-cashback-promocodes-repository.php');
        $this->require_file('includes/promocodes/class-cashback-promocodes-fetcher.php');
        $this->require_file('includes/promocodes/class-cashback-promocodes-shortcode.php');
        $this->require_file('includes/promocodes/class-cashback-coupons-icon-resolver.php');
        $this->require_file('includes/promocodes/class-cashback-coupons-icons-shortcode.php');
        $this->require_file('includes/promocodes/class-cashback-coupons-icons-admin.php');
        $this->require_file('includes/promocodes/class-cashback-promocodes-tracker.php');
        $this->require_file('includes/promocodes/class-cashback-promocodes-redirect.php');
        $this->require_file('includes/promocodes/class-cashback-promocodes-click-backfill.php');
        $this->require_file('includes/promocodes/class-cashback-promocodes-admin.php');
        $this->require_file('includes/promocodes/class-cashback-promocodes-bootstrap.php');

        // Глобальный lock для атомарной синхронизации + начисления
        $this->require_file('includes/class-cashback-lock.php');

        // Единая пагинация (используется и в админке, и во фронтенде)
        $this->require_file('includes/class-cashback-pagination.php');

        // Централизованный enqueue ассетов (Группа 9 ADR): DOMPurify + safe-html wrapper
        // для всех модулей, вставляющих server-generated HTML через jQuery.html().
        $this->require_file('includes/class-cashback-assets.php');

        // Frontend perf: defer cookie-banner и bot-protection CSS (preload-swap),
        // инлайн критичного `.is-hidden { display:none }` в head. Сокращает
        // 2 рендер-блокирующих таблицы стилей на каждой странице — ощутимо для
        // мобильного канала с RTT 100–150 мс.
        $this->require_file('includes/class-cashback-frontend-performance.php');

        // Checkpoint-хранилище cron-прогонов (Group 8 Step 3, F-8-005)
        $this->require_file('includes/class-cashback-cron-state.php');

        // Shop Importer + Dynamic Display: фасад WP-опций (v12)
        $this->require_file('includes/class-cashback-shop-options.php');
        // Shop Importer (v12) — оркестратор + tariff sync + import log + group resolver + display calculator + tab1 renderer
        $this->require_file('includes/shops/class-cashback-shop-import-log.php');
        $this->require_file('includes/shops/class-cashback-shop-tariff-sync.php');
        $this->require_file('includes/shops/class-cashback-shop-group-resolver.php');
        $this->require_file('includes/shops/class-cashback-tab-conditions-renderer.php');
        $this->require_file('includes/shops/class-cashback-shop-importer.php');
        $this->require_file('includes/shops/class-cashback-cashback-display-calculator.php');
        // Сортировка каталога «По возрастанию/убыванию кэшбэка»: фильтры
        // woocommerce_catalog_orderby + woocommerce_get_catalog_ordering_args,
        // meta `_cashback_sort_value` (пересчёт на cashback_tariffs_changed
        // и при сохранении метабокса).
        $this->require_file('includes/shops/class-cashback-product-sort.php');
        // Catalog visibility: скрытие non-preferred членов групп магазинов из
        // основных catalog query. Реализует изначальное намерение «один
        // WC-товар на витрине» — закрывает Codex finding #1 (рассогласование
        // sort vs display). Sync через action cashback_group_preferred_changed.
        $this->require_file('includes/shops/class-cashback-catalog-visibility.php');

        // API клиент и cron (синхронизация работает через WP Cron)
        $this->require_file('includes/class-cashback-api-client.php');
        $this->require_file('includes/class-cashback-api-cron.php');

        // Группа 14: ежедневная сверка ledger vs кэш баланса.
        $this->require_file('includes/class-cashback-balance-reconciliation.php');

        // E2E follow-up A1-1: ежечасная сверка ledger ↔ audit_log
        // (defense-in-depth: ловит ledger-write'ы в обход plugin handler'а).
        $this->require_file('includes/class-cashback-audit-trail-reconciliation.php');

        // CONCERN C1 prod-readiness: ежедневная retention-чистка cashback_audit_log
        // (5 лет = 1825 дней, override через filter cashback_audit_log_retention_days).
        $this->require_file('includes/class-cashback-audit-log-retention.php');

        // Группа 15: UI-помощник для расшифровки результатов проверки
        // (перевод issue + бухгалтерский HTML-блок). Используется в
        // Cashback_Balance_Reconciliation_Admin и admin/payouts.php (DRY).
        $this->require_file('includes/class-cashback-balance-issue-renderer.php');

        // Группа 15: admin-UI поверх сверки (подстраница + Summary + таблицы + ручной запуск).
        $this->require_file('admin/class-cashback-balance-reconciliation-admin.php');

        // Shop Importer admin UI (v12)
        $this->require_file('admin/class-cashback-settings-admin.php');
        $this->require_file('admin/class-cashback-shop-import-admin.php');
        $this->require_file('admin/class-cashback-shop-groups-admin.php');

        // --- Click-session service (12i-2 ADR) — общий сервис для /activate и ?cashback_click= ---
        $this->require_file('includes/class-cashback-click-session-service.php');

        // --- REST API для браузерного расширения ---
        $this->require_file('includes/class-cashback-rest-api.php');

        // Шорткоды (доступны на фронтенде и в превью редактора)
        $this->require_file('includes/class-cashback-shortcodes.php');

        // SC Auth Pages: отдельные страницы /login/ и /register/ (шорткоды [sc_login]/[sc_register]).
        // Заменяет стандартную объединённую WC-форму на /my-account/ для гостей.
        $this->require_file('includes/sc-auth-pages/class-sc-auth-pages-activator.php');
        $this->require_file('includes/sc-auth-pages/class-sc-auth-pages-redirect-helper.php');
        $this->require_file('includes/sc-auth-pages/class-sc-auth-pages-shortcodes.php');
        $this->require_file('includes/sc-auth-pages/class-sc-auth-pages-login.php');
        $this->require_file('includes/sc-auth-pages/class-sc-auth-pages-register.php');
        $this->require_file('includes/sc-auth-pages/class-sc-auth-pages-redirector.php');
        $this->require_file('includes/sc-auth-pages/class-sc-auth-pages-menu-filter.php');
        $this->require_file('includes/sc-auth-pages/class-sc-auth-pages-header-replacer.php');
        $this->require_file('includes/sc-auth-pages/class-sc-auth-pages-bootstrap.php');

        // Defense-in-depth: WoodMart customizer CSS file fallback (если физ.файл
        // отсутствует — подменяет <link> на inline из DB-опции). Лечит race между
        // обновлением Styles_Storage и физическим созданием файла, который ловится
        // nginx fastcgi_cache на 30 мин и выдаёт гостям битый top-bar.
        $this->require_file('includes/class-cashback-woodmart-css-fallback.php');

        // Контактная форма (шорткод, доступен без авторизации)
        $this->require_file('includes/class-cashback-contact-form.php');

        // Affiliate module (реферальная программа)
        $this->require_file('affiliate/class-affiliate-db.php');
        $this->require_file('affiliate/class-affiliate-audit.php');
        $this->require_file('affiliate/class-affiliate-antifraud.php');
        $this->require_file('affiliate/class-affiliate-service.php');
        $this->require_file('affiliate/class-affiliate-frontend.php');
        $this->require_file('affiliate/class-affiliate-activation.php');

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
        $this->require_file('notifications/class-cashback-welcome-email.php');
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

        // Legal module (юр. документы, согласия по 152-ФЗ/38-ФЗ/161-ФЗ/ГК 437) — bootstrap.
        $this->require_file('legal/class-cashback-legal-bootstrap.php');

        // FAQ module — публичная страница «Вопросы и ответы» + шорткод [cashback_faq].
        $this->require_file('faq/class-cashback-faq-bootstrap.php');
        if (class_exists('Cashback_Faq_Bootstrap')) {
            Cashback_Faq_Bootstrap::init();
        }
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
                $instance->migrate_unregistered_reference_id_prefix();
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
                    $instance->migrate_unregistered_reference_id_prefix();
                    $instance->recreate_triggers();
                } catch (\Throwable $e) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log('[Cashback] Auto-migration backfill failed: ' . $e->getMessage());
                }
            } else {
                // Колонка и TX- backfill в порядке, но может быть старый TX- префикс в unregistered-таблице
                // от установок до смены префикса на TU-. Миграция идемпотентна (fast-path при отсутствии TX-*).
                $legacy_tx_in_unreg = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM `{$wpdb->prefix}cashback_unregistered_transactions` WHERE reference_id LIKE %s",
                        'TX-%'
                    )
                );
                if ($legacy_tx_in_unreg > 0) {
                    try {
                        $instance = Mariadb_Plugin::get_instance();
                        $instance->migrate_unregistered_reference_id_prefix();
                        $instance->recreate_triggers();
                    } catch (\Throwable $e) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log('[Cashback] Auto-migration TX->TU failed: ' . $e->getMessage());
                    }
                }
            }
        }

        // F-22-003: attribution model (5 колонок в profiles + accruals, индекс idx_review_queue).
        // Миграция идемпотентна — fast-path через cashback_affiliate_db_version (return на ≥ 1.2),
        // повторный ALTER защищён is_known_ddl_error(). Безопасно вызывать на каждом init.
        if (class_exists('Cashback_Affiliate_DB')) {
            try {
                Cashback_Affiliate_DB::migrate_f22_003_attribution_model();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] F-22-003 auto-migration failed: ' . $e->getMessage());
            }
        }

        // F-20-002 follow-up: scoring_breakdown колонка в cashback_claims.
        // SHOW COLUMNS guard + is_known_ddl_error — идемпотентно для existing installs.
        if (class_exists('Cashback_Claims_DB')) {
            try {
                Cashback_Claims_DB::migrate_add_scoring_breakdown();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] F-20-002 scoring_breakdown auto-migration failed: ' . $e->getMessage());
            }
        }

        // Группа 14: ban_freeze/ban_unfreeze значения в ENUM type таблицы cashback_balance_ledger.
        // Миграция идемпотентна — fast-path через COLUMN_TYPE из information_schema.
        if (class_exists('Mariadb_Plugin')) {
            try {
                Mariadb_Plugin::get_instance()->migrate_ledger_ban_enum();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] Ledger ban-enum auto-migration failed: ' . $e->getMessage());
            }

            // Группа 14 (шаг G): safety-backfill ledger.accrual для старых processed transactions.
            // Fast-path через option-флаг cashback_ledger_accrual_backfill_v1.
            try {
                Mariadb_Plugin::get_instance()->migrate_backfill_ledger_accruals();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] Ledger accrual backfill auto-migration failed: ' . $e->getMessage());
            }

            // E2E 2026-04-29 P2-A1-2: BEFORE INSERT/UPDATE триггеры на cashback_payout_requests
            // требуют fail_reason при status IN ('declined','failed'). Идемпотентно через
            // cashback_db_version >= 5 fast-path. Runtime-вызов нужен на existing installs
            // без re-activation (паттерн F-22-003).
            try {
                Mariadb_Plugin::get_instance()->migrate_payout_require_fail_reason_v5();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] Payout fail_reason trigger auto-migration failed: ' . $e->getMessage());
            }

            // OBS-06 (E2E run B 2026-04-30): split ban_reason на public + admin поля.
            // Идемпотентно через cashback_db_version >= 6 fast-path.
            try {
                Mariadb_Plugin::get_instance()->migrate_split_ban_reason_v6();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] Split ban_reason auto-migration failed: ' . $e->getMessage());
            }

            // F-S9-NEW-UNFREEZE (Session 8, 2026-05-01): frozen_balance_admin bucket +
            // ledger enum 'payout_unfreeze' + backfill для existing declined-выплат.
            // Идемпотентно через cashback_db_version >= 7 fast-path.
            try {
                Mariadb_Plugin::get_instance()->migrate_payout_unfreeze_v7();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] Payout unfreeze v7 auto-migration failed: ' . $e->getMessage());
            }

            // Промокоды (Шаг 1 плана generic-coupons-engine, 2026-05-03): +4 колонки
            // public-конфига в cashback_affiliate_networks + 2 новые таблицы
            // (cashback_promocodes, cashback_promocode_clicks) + seed admitad.
            // Идемпотентно через cashback_db_version >= 8 fast-path.
            try {
                Mariadb_Plugin::get_instance()->migrate_promocodes_v8();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] Promocodes v8 auto-migration failed: ' . $e->getMessage());
            }

            // Промокоды v9: ADD COLUMN click_id в cashback_promocode_clicks
            // (UUIDv7 связь с cashback_click_log для goto-кликов).
            // Идемпотентно через cashback_db_version >= 9 fast-path.
            try {
                Mariadb_Plugin::get_instance()->migrate_promocodes_v9_click_id();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] Promocodes v9 click_id auto-migration failed: ' . $e->getMessage());
            }

            // v10: ADD COLUMN promocode_id в cashback_click_log — для safety-backfill
            // в cashback_promocode_clicks (если runtime stat-INSERT упал, cron через
            // 6 часов допишет по LEFT JOIN). Идемпотентно через cashback_db_version >= 10.
            try {
                Mariadb_Plugin::get_instance()->migrate_click_log_promocode_id_v10();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] click_log promocode_id v10 auto-migration failed: ' . $e->getMessage());
            }

            // v11: разделение click-session между товарным и промо-кликом —
            // ADD promocode_id в cashback_click_sessions (расширяет dedup-key
            // do_activate(), иначе reuse товарной session подменяет купонный
            // goto_link на товарный) + ADD affiliate_url в cashback_promocode_clicks
            // (для self-verification оператором). Идемпотентно через
            // cashback_db_version >= 11 fast-path.
            try {
                Mariadb_Plugin::get_instance()->migrate_promocodes_v11_session_promocode_id();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] Promocodes v11 session promocode_id auto-migration failed: ' . $e->getMessage());
            }

            // v12: Shop Importer — 4 новые таблицы (cashback_shop_tariffs,
            // cashback_shop_groups, cashback_shop_group_members,
            // cashback_shop_import_log) для автоимпорта магазинов из CPA-сетей,
            // хранения партнёрских тарифов и дедупа магазинов между сетями.
            // Идемпотентно через cashback_db_version >= 12 fast-path.
            try {
                Mariadb_Plugin::get_instance()->migrate_shop_import_v12();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] Shop Importer v12 auto-migration failed: ' . $e->getMessage());
            }

            // v13: cleanup ghost member-records (1677 orphan rows из
            // wp_cashback_shop_group_members ссылающихся на удалённые
            // products) + удаление test e2e* networks. См. диагноз в
            // plans/luminous-snacking-gizmo.md. Идемпотентно через
            // cashback_db_version >= 13 fast-path.
            try {
                Mariadb_Plugin::get_instance()->migrate_cleanup_ghost_members_v13();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback] Cleanup ghost members v13 auto-migration failed: ' . $e->getMessage());
            }
        }

        // Legal module (Phase 1): создание таблицы wp_cashback_consent_log и
        // seed версий документов 1.0.0. Идемпотентно — fast-path через
        // cashback_legal_db_version. Безопасно вызывать на каждом init.
        if (class_exists('Cashback_Legal_DB')) {
            try {
                Cashback_Legal_DB::migrate();
                if (class_exists('Cashback_Legal_Documents')) {
                    Cashback_Legal_Documents::seed_versions();
                }
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Legal] auto-migration failed: ' . $e->getMessage());
            }
        }

        // Phase 7 (2026-05-09 plan greedy-soaring-salamander): глобальный
        // bump major для всех 9 типов юр.документов после переработки текстов.
        // Гейт через опцию cashback_legal_rewrite_2026_05_09_done — повторно
        // не выполняется. Существующие consent-записи будут отмечены как
        // superseded на следующем входе пользователя через
        // Cashback_Legal_Reconsent_Modal — re-consent для всех ранее
        // согласовавших.
        if (class_exists('Cashback_Legal_Documents')
            && get_option('cashback_legal_rewrite_2026_05_09_done', '') !== '1') {
            try {
                $bumped = array();
                foreach (Cashback_Legal_Documents::all_types() as $legal_type) {
                    $old              = Cashback_Legal_Documents::get_active_version($legal_type);
                    $new              = Cashback_Legal_Documents::bump_major($legal_type);
                    $bumped[ $legal_type ] = array( 'old' => $old, 'new' => $new );
                }
                update_option('cashback_legal_rewrite_2026_05_09_done', '1', false);

                // Audit best-effort. Не валим миграцию, если encryption ещё не готов.
                if (class_exists('Cashback_Encryption')
                    && method_exists('Cashback_Encryption', 'write_audit_log')) {
                    try {
                        Cashback_Encryption::write_audit_log(
                            'legal_global_bump_2026_05_09',
                            0,
                            'legal',
                            0,
                            array( 'bumped' => $bumped )
                        );
                    } catch (\Throwable $audit_error) {
                        unset($audit_error);
                    }
                }
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Legal] bump_major migration 2026-05-09 failed: ' . $e->getMessage());
            }
        }

        // Legal template storage (UI-редактирование текстов): создание таблицы
        // wp_cashback_legal_template_versions. Идемпотентно — fast-path через
        // cashback_legal_template_db_version.
        if (class_exists('Cashback_Legal_Template_Storage')) {
            try {
                Cashback_Legal_Template_Storage::migrate();
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Legal Template] auto-migration failed: ' . $e->getMessage());
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

        // Frontend perf: ставит фильтр style_loader_tag и инлайн critical-rule
        // ДО любого wp_enqueue_scripts в этом приходе init().
        if (class_exists('Cashback_Frontend_Performance')) {
            Cashback_Frontend_Performance::init();
        }

        // Nginx fastcgi_cache invalidation (см. require_file выше).
        if (class_exists('Cashback_Nginx_Cache_Hooks')) {
            Cashback_Nginx_Cache_Hooks::init();
        }

        // Shop Importer (v12): регистрирует AS-handler cashback_shops_import_run.
        // Recurring schedule + admin-кнопка добавятся в Этапе 9.
        if (class_exists('Cashback_Shop_Importer')) {
            Cashback_Shop_Importer::init();
        }

        // Сортировка каталога по кэшбэку: фильтры orderby + recompute хуки
        // (cashback_tariffs_changed). Идемпотентный one-shot backfill при
        // первом init после релиза — гейтится опцией cashback_product_sort_backfill_v1.
        if (class_exists('Cashback_Product_Sort')) {
            Cashback_Product_Sort::register();
            Cashback_Product_Sort::ensure_backfilled();
        }

        // Catalog visibility: pre_get_posts фильтр + sync на cashback_group_preferred_changed.
        // Idempotent self-healing backfill через wp-cron (опция cashback_catalog_visibility_backfill_v1).
        if (class_exists('Cashback_Catalog_Visibility')) {
            Cashback_Catalog_Visibility::register();
            Cashback_Catalog_Visibility::ensure_backfilled();
        }

        // Shop Group Resolver: cleanup member-record ТОЛЬКО при permanent
        // delete (before_delete_post). На wp_trash_post НЕ навешено намеренно
        // — trash должен быть обратимым через WP UI «Восстановить». Trashed
        // members автоматически отфильтровываются через INNER JOIN wp_posts
        // в get_active_members (post_status NOT IN ('trash','auto-draft')),
        // поэтому visibility/sort работают корректно без destructive cleanup.
        if (class_exists('Cashback_Shop_Group_Resolver')) {
            add_action('before_delete_post', array( 'Cashback_Shop_Group_Resolver', 'on_before_delete_post' ), 10, 2);
            // Закрывает race-condition в shop-importer: после tariff sync
            // group preferred должен пересчитаться (recompute_preferred при
            // reconcile_for_product мог вернуть -1, потому что тарифы ещё не
            // синканы). Приоритет 30 — после nginx purger (10) и product-sort (20).
            add_action('cashback_tariffs_changed', array( 'Cashback_Shop_Group_Resolver', 'on_tariffs_changed' ), 30, 1);
            // Resumable batched backfill для групп с preferred_product_id IS NULL
            // (existing v12-данные где race condition оставил preferred=NULL).
            // Wp-cron handler + ensure-scheduling, аналогично Cashback_Product_Sort.
            add_action(Cashback_Shop_Group_Resolver::PREFERRED_BACKFILL_CRON_HOOK, array( 'Cashback_Shop_Group_Resolver', 'handle_preferred_backfill_cron' ), 10, 0);
            Cashback_Shop_Group_Resolver::ensure_preferred_backfilled();
        }

        // Admin UI Этапа 8: Settings + Import + Groups submenu pages.
        if (is_admin()) {
            if (class_exists('Cashback_Settings_Admin')) {
                Cashback_Settings_Admin::init();
            }
            if (class_exists('Cashback_Shop_Import_Admin')) {
                Cashback_Shop_Import_Admin::init();
            }
            if (class_exists('Cashback_Shop_Groups_Admin')) {
                Cashback_Shop_Groups_Admin::init();
            }
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

        // Анонимизация / soft-delete: pre_delete_user priority 5 — раньше дефолтных 10,
        // чтобы плагиновые DELETE прошли (или wp_die сработал) до того как WP пытается
        // удалить wp_users (FK fk_balance_user).
        if (class_exists('Cashback_User_Anonymizer')) {
            add_action('pre_delete_user', array( 'Cashback_User_Anonymizer', 'on_pre_delete_user' ), 5, 3);
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

        // --- Промокоды CPA-сетей: AS-cron 6ч + manual refresh ---
        if (class_exists('Cashback_Promocodes_Bootstrap')) {
            Cashback_Promocodes_Bootstrap::init();
        }

        // --- Группа 14: ежедневная сверка баланса (AS-job ledger vs cache) ---
        if (class_exists('Cashback_Balance_Reconciliation')) {
            Cashback_Balance_Reconciliation::init();
        }

        // --- E2E A1-1: ежечасная audit-trail сверка ledger ↔ audit_log ---
        if (class_exists('Cashback_Audit_Trail_Reconciliation')) {
            Cashback_Audit_Trail_Reconciliation::init();
        }

        // --- CONCERN C1: ежедневный retention-purge cashback_audit_log (5 лет) ---
        if (class_exists('Cashback_Audit_Log_Retention')) {
            Cashback_Audit_Log_Retention::init();
        }

        // --- Группа 15: admin-UI поверх сверки (подстраница + Summary + manual run) ---
        if (is_admin() && class_exists('Cashback_Balance_Reconciliation_Admin')) {
            Cashback_Balance_Reconciliation_Admin::init();
        }

        // --- REST API для браузерного расширения ---
        if (class_exists('Cashback_REST_API')) {
            Cashback_REST_API::get_instance();
        }

        // Шорткоды
        if (class_exists('Cashback_Shortcodes')) {
            Cashback_Shortcodes::get_instance();
        }

        // SC Auth Pages: bootstrap регистрирует [sc_login]/[sc_register] + handler'ы
        // POST на template_redirect и guest-redirector /my-account/ → /login/.
        if (class_exists('Cashback_SC_Auth_Pages_Bootstrap')) {
            Cashback_SC_Auth_Pages_Bootstrap::init();
        }

        // WoodMart customizer CSS fallback (см. require выше — lazy-init на wp_print_styles).
        if (class_exists('Cashback_Woodmart_Css_Fallback')) {
            Cashback_Woodmart_Css_Fallback::register();
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
        if (class_exists('Cashback_Affiliate_Activation')) {
            Cashback_Affiliate_Activation::init();
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
