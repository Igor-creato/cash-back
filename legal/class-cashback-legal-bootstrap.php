<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Bootstrap
 *
 * Точка входа модуля «Юр. документы и согласия». Подключается из
 * CashbackPlugin::load_dependencies() (cashback-plugin.php) — по паттерну
 * Cashback_Social_Auth_Bootstrap.
 *
 * Phase 1: core классы (DB / Documents / Operator / Consent_Manager).
 * Phase 2: Pages_Installer, Shortcodes, Admin (реквизиты), WP Privacy Policy hook.
 *
 * @since 1.3.0
 */
class Cashback_Legal_Bootstrap {

    /**
     * Phase 1 core-файлы (без UI/admin).
     *
     * @return array<int, string>
     */
    private static function core_files(): array {
        return array(
            'class-cashback-legal-db.php',
            'class-cashback-legal-documents.php',
            'class-cashback-legal-operator.php',
            'class-cashback-legal-consent-manager.php',
        );
    }

    /**
     * Phase 2 файлы (UI на фронтенде + admin).
     *
     * @return array<int, string>
     */
    private static function ui_files(): array {
        return array(
            'class-cashback-legal-pages-installer.php',
            'class-cashback-legal-shortcodes.php',
            'class-cashback-legal-registration-checkboxes.php',
            'class-cashback-legal-payout-consent.php',
        );
    }

    /**
     * Phase 2 admin-файлы (грузятся только в is_admin()).
     *
     * @return array<int, string>
     */
    private static function admin_files(): array {
        return array(
            'admin/class-cashback-legal-admin.php',
        );
    }

    /**
     * Подключение модуля. Idempotent: повторный вызов — noop.
     */
    public static function init(): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $base = __DIR__;

        foreach (self::core_files() as $file) {
            $path = $base . '/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }

        foreach (self::ui_files() as $file) {
            $path = $base . '/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }

        if (function_exists('is_admin') && is_admin()) {
            foreach (self::admin_files() as $file) {
                $path = $base . '/' . $file;
                if (file_exists($path)) {
                    require_once $path;
                }
            }
        }

        // Phase 2: регистрация хуков UI-компонентов.
        if (class_exists('Cashback_Legal_Pages_Installer')) {
            Cashback_Legal_Pages_Installer::init();
        }
        if (class_exists('Cashback_Legal_Shortcodes')) {
            Cashback_Legal_Shortcodes::init();
        }
        if (class_exists('Cashback_Legal_Registration_Checkboxes')) {
            Cashback_Legal_Registration_Checkboxes::init();
        }
        if (class_exists('Cashback_Legal_Admin')) {
            Cashback_Legal_Admin::init();
        }

        // WP Privacy Policy интеграция: контент о плагине публикуется
        // через wp_add_privacy_policy_content в admin_init.
        if (function_exists('add_action')) {
            add_action('admin_init', array( __CLASS__, 'add_privacy_policy_content' ));
        }
    }

    /**
     * Подкладывает контент о плагине в WP-нативную «Политика конфиденциальности».
     * Вызывается на admin_init.
     */
    public static function add_privacy_policy_content(): void {
        if (!function_exists('wp_add_privacy_policy_content') || !class_exists('Cashback_Legal_Documents')) {
            return;
        }
        $rendered = Cashback_Legal_Documents::get_rendered(Cashback_Legal_Documents::TYPE_PD_POLICY);
        if ($rendered === '') {
            return;
        }
        wp_add_privacy_policy_content('Cashback Plugin', wp_kses_post($rendered));
    }
}

Cashback_Legal_Bootstrap::init();
