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
 * Phase 1: загружает только core классы (DB / Documents / Operator /
 * Consent_Manager). UI/чекбоксы/cookie-баннер/admin добавляются в Phase 2-5.
 *
 * @since 1.3.0
 */
class Cashback_Legal_Bootstrap {

    /**
     * Список core-файлов (Phase 1).
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
    }
}

Cashback_Legal_Bootstrap::init();
