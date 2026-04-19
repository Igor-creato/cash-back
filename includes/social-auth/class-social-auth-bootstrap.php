<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bootstrap модуля социальной авторизации.
 *
 * - Подключает зависимые файлы
 * - Регистрирует REST-роутер и админку
 * - Создаёт таблицы при активации плагина
 * - Задаёт default options
 *
 * @since 1.1.0
 */
class Cashback_Social_Auth_Bootstrap {

    private static ?self $instance = null;

    private bool $booted = false;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
    }

    /**
     * Подключение файлов модуля (идемпотентно).
     */
    public static function load_files(): void {
        $base = plugin_dir_path(__FILE__);

        $files = array(
            $base . 'class-social-auth-db.php',
            $base . 'class-social-auth-audit.php',
            $base . 'class-social-auth-session.php',
            $base . 'class-social-auth-token-store.php',
            $base . 'providers/interface-social-provider.php',
            $base . 'providers/abstract-social-provider.php',
            $base . 'providers/class-social-provider-yandex.php',
            $base . 'providers/class-social-provider-vkid.php',
            $base . 'class-social-auth-providers.php',
            $base . 'class-social-auth-account-manager.php',
            $base . 'class-social-auth-renderer.php',
            $base . 'class-social-auth-my-account.php',
            $base . 'class-social-auth-router.php',
        );

        foreach ($files as $file) {
            if (file_exists($file)) {
                require_once $file;
            }
        }

        // Admin-only: файл admin-страницы лежит в корневой папке admin/.
        // __FILE__ находится в <plugin>/includes/social-auth/, откатываем на 2 уровня.
        if (is_admin()) {
            $plugin_root = dirname(__DIR__, 2);
            $admin_file  = $plugin_root . '/admin/class-cashback-social-admin.php';
            if (file_exists($admin_file)) {
                require_once $admin_file;
            }
        }
    }

    /**
     * Инициализация (запускается на plugins_loaded@20 из cashback-plugin.php).
     */
    public function init(): void {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        self::load_files();

        // Регистры/сервисы.
        if (class_exists('Cashback_Social_Auth_Providers')) {
            Cashback_Social_Auth_Providers::instance();
        }

        if (class_exists('Cashback_Social_Auth_Router')) {
            $router = new Cashback_Social_Auth_Router();
            $router->register();
        }

        // Блокировка входа по паролю для юзеров, ожидающих подтверждения email (double opt-in).
        if (class_exists('Cashback_Social_Auth_Account_Manager')) {
            add_filter(
                'authenticate',
                array( Cashback_Social_Auth_Account_Manager::instance(), 'block_pending_login' ),
                30,
                3
            );
        }

        if (is_admin() && class_exists('Cashback_Social_Admin')) {
            new Cashback_Social_Admin();
        }

        // UI renderer + хуки форм.
        if (class_exists('Cashback_Social_Auth_Renderer')) {
            $renderer = Cashback_Social_Auth_Renderer::instance();

            // Enqueue стилей.
            add_action('wp_enqueue_scripts', array( $renderer, 'maybe_enqueue_front' ), 15);
            add_action('login_enqueue_scripts', array( $renderer, 'maybe_enqueue_login' ), 15);

            // Формы входа/регистрации WooCommerce (покрывают Woodmart dropdown и sidebar).
            add_action('woocommerce_login_form_end', array( $renderer, 'print_login_buttons' ), 15);
            add_action('woocommerce_register_form_end', array( $renderer, 'print_register_buttons' ), 15);

            // Чекаут (только если не залогинен — проверка внутри метода).
            add_action('woocommerce_before_checkout_form', array( $renderer, 'print_checkout_buttons' ), 12);

            // wp-login.php.
            add_action('login_form', array( $renderer, 'print_wp_login_buttons' ), 15);
        }

        // Вкладка ЛК «Соцсети».
        if (class_exists('Cashback_Social_Auth_My_Account')) {
            Cashback_Social_Auth_My_Account::instance()->register_hooks();
        }

        // Плановая очистка pending.
        add_action('cashback_social_cleanup_pending_cron', array( $this, 'run_cleanup_pending' ));
        if (!wp_next_scheduled('cashback_social_cleanup_pending_cron')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'cashback_social_cleanup_pending_cron');
        }
    }

    /**
     * Хук активации плагина: создать таблицы и default options.
     */
    public static function activate(): void {
        self::load_files();

        if (class_exists('Cashback_Social_Auth_DB')) {
            Cashback_Social_Auth_DB::create_tables();
        }

        // Глобальные настройки по умолчанию.
        if (get_option('cashback_social_enabled', null) === null) {
            add_option('cashback_social_enabled', 0);
        }

        // Настройки по провайдерам.
        self::ensure_default_provider_options('yandex', array(
            'enabled'                 => 0,
            'client_id'               => '',
            'client_secret_encrypted' => '',
            'phone_scope'             => 0,
            'label_login'             => __('Войти через Яндекс ID', 'cashback-plugin'),
            'label_register'          => __('Зарегистрироваться через Яндекс ID', 'cashback-plugin'),
            'icon_id'                 => 0,
        ));

        self::ensure_default_provider_options('vkid', array(
            'enabled'                 => 0,
            'client_id'               => '',
            'client_secret_encrypted' => '',
            'phone_scope'             => 0,
            'label_login'             => __('Войти через VK ID', 'cashback-plugin'),
            'label_register'          => __('Зарегистрироваться через VK ID', 'cashback-plugin'),
            'icon_id'                 => 0,
        ));
    }

    /**
     * Хук деактивации: снять cron и временные данные (таблицы НЕ удаляем).
     */
    public static function deactivate(): void {
        $ts = wp_next_scheduled('cashback_social_cleanup_pending_cron');
        if ($ts) {
            wp_unschedule_event($ts, 'cashback_social_cleanup_pending_cron');
        }
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private static function ensure_default_provider_options( string $provider_id, array $defaults ): void {
        $key = 'cashback_social_provider_' . $provider_id;
        $cur = get_option($key, null);
        if (!is_array($cur)) {
            add_option($key, $defaults);
            return;
        }
        // Дозаполнить отсутствующие ключи.
        $merged = array_merge($defaults, $cur);
        if ($merged !== $cur) {
            update_option($key, $merged, false);
        }
    }

    /**
     * Cron: очистить истёкшие pending.
     */
    public function run_cleanup_pending(): void {
        if (class_exists('Cashback_Social_Auth_DB')) {
            Cashback_Social_Auth_DB::cleanup_expired_pending();
        }
    }
}
