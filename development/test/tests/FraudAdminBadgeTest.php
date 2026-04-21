<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты для расширения admin UI антифрод-модуля (Этап 6).
 *
 * Покрывает:
 *  - Cashback_Fraud_Admin::render_ip_type_badge() для всех типов сети
 *  - Идемпотентность Cashback_Fraud_Admin::migrate_dismiss_legacy_cgnat_alerts()
 *
 * Helper-методы class-fraud-admin вызываются через Reflection (private static).
 */
#[Group('fraud-admin-badge')]
class FraudAdminBadgeTest extends TestCase
{
    protected function setUp(): void
    {
        // Мокаем минимальный набор WP-API, нужный методам бейджа/миграции.
        if (!function_exists('wp_cache_get')) {
            // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols
            eval('function wp_cache_get(string $key, string $group = "", bool $force = false, ?bool &$found = null): mixed { $found = false; return false; }');
        }
        if (!function_exists('wp_cache_set')) {
            // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols
            eval('function wp_cache_set(string $key, mixed $data, string $group = "", int $expire = 0): bool { return true; }');
        }
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', dirname(__DIR__, 5));
        }

        // Загружаем только сам Cashback_Fraud_Admin — IP Intelligence не нужен:
        // render_ip_type_badge() самодостаточен, миграционный тест работает через Reflection
        // на исходник, не вызывая метод. Загрузка IP-Intelligence ломает изоляцию
        // FraudDeviceIdTest, который проверяет fallback-поведение «без IP Intelligence».
        $admin_file = dirname(__DIR__, 3) . '/antifraud/class-fraud-admin.php';
        if (!class_exists('Cashback_Fraud_Admin') && file_exists($admin_file)) {
            require_once $admin_file;
        }
    }

    // ================================================================
    // render_ip_type_badge()
    // ================================================================

    public static function badge_provider(): array
    {
        return array(
            'mobile'      => array('mobile', 'cashback-fraud-network-badge--mobile', 'Mobile'),
            'residential' => array('residential', 'cashback-fraud-network-badge--residential', 'Home'),
            'hosting'     => array('hosting', 'cashback-fraud-network-badge--hosting', 'Datacenter'),
            'vpn'         => array('vpn', 'cashback-fraud-network-badge--vpn', 'VPN'),
            'tor'         => array('tor', 'cashback-fraud-network-badge--tor', 'Tor'),
            'cgnat'       => array('cgnat', 'cashback-fraud-network-badge--cgnat', 'CGNAT'),
            'private'     => array('private', 'cashback-fraud-network-badge--private', 'Private'),
            'device'      => array('device', 'cashback-fraud-network-badge--device', 'Device'),
            'unknown'     => array('unknown', 'cashback-fraud-network-badge--unknown', 'Unknown'),
        );
    }

    #[DataProvider('badge_provider')]
    public function test_render_ip_type_badge_returns_class_and_text(string $type, string $expected_class, string $expected_text): void
    {
        $html = $this->invoke_private_static('render_ip_type_badge', array($type, null));

        self::assertStringContainsString($expected_class, $html, 'Бейдж должен содержать CSS-класс типа');
        self::assertStringContainsString('cashback-fraud-network-badge', $html, 'Базовый CSS-класс бейджа');
        self::assertStringContainsString('>' . $expected_text . '<', $html, 'Текст бейджа должен соответствовать карте');
    }

    public function test_render_ip_type_badge_unknown_fallback(): void
    {
        // Произвольный неизвестный тип → fallback к 'unknown'.
        $html = $this->invoke_private_static('render_ip_type_badge', array('martian-network', null));

        self::assertStringContainsString('cashback-fraud-network-badge--unknown', $html);
        self::assertStringContainsString('>Unknown<', $html);
    }

    public function test_render_ip_type_badge_custom_label_override(): void
    {
        // Передан label — он показывается вместо дефолтного текста, но CSS-класс остаётся от type.
        $html = $this->invoke_private_static('render_ip_type_badge', array('mobile', 'МТС RU'));

        self::assertStringContainsString('cashback-fraud-network-badge--mobile', $html);
        self::assertStringContainsString('>МТС RU<', $html);
        self::assertStringNotContainsString('>Mobile<', $html);
    }

    public function test_render_ip_type_badge_escapes_label(): void
    {
        $html = $this->invoke_private_static('render_ip_type_badge', array('mobile', '<script>x</script>'));

        self::assertStringNotContainsString('<script>', $html, 'Label должен быть экранирован');
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    // ================================================================
    // migrate_dismiss_legacy_cgnat_alerts() — структура и сигнатура
    // ================================================================

    public function test_migration_method_exists_and_is_idempotent_via_option(): void
    {
        // Метод существует, public static, возвращает int — нужно для безопасного вызова на activation.
        self::assertTrue(method_exists('Cashback_Fraud_Admin', 'migrate_dismiss_legacy_cgnat_alerts'));

        $ref = new ReflectionMethod('Cashback_Fraud_Admin', 'migrate_dismiss_legacy_cgnat_alerts');
        self::assertTrue($ref->isPublic(), 'Метод должен быть public — вызывается из cashback-plugin.php');
        self::assertTrue($ref->isStatic(), 'Метод должен быть static — нет инстанса на activation hook');

        $return_type = $ref->getReturnType();
        self::assertNotNull($return_type, 'Должен иметь return-type declaration');
        self::assertSame('int', (string) $return_type, 'Возвращает количество dismissed-алертов');

        // Идемпотентность: код проверяет get_option('cashback_fraud_legacy_cgnat_dismissed_at')
        // первой строкой и возвращает 0 если флаг есть. Доказываем через статический анализ
        // тела метода: это критическая защита — её нельзя случайно удалить рефакторингом.
        $file   = (string) $ref->getFileName();
        $start  = $ref->getStartLine();
        $end    = $ref->getEndLine();
        $lines  = file($file);
        self::assertNotFalse($lines);
        $body   = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        self::assertStringContainsString('cashback_fraud_legacy_cgnat_dismissed_at', $body, 'Имя option-флага должно присутствовать');
        self::assertStringContainsString('get_option(', $body, 'Должен использовать get_option для guard');
        self::assertStringContainsString('update_option(', $body, 'Должен записывать option после миграции');
        self::assertStringContainsString("'multi_account_ip'", $body, 'Должен фильтровать по типу алерта multi_account_ip');
        self::assertStringContainsString("'mobile'", $body, 'Должен включать mobile в excluded типы');
        self::assertStringContainsString("'cgnat'", $body, 'Должен включать cgnat в excluded типы');
        self::assertStringContainsString("'private'", $body, 'Должен включать private в excluded типы');
        self::assertStringContainsString("'auto_dismissed_cgnat_migration'", $body, 'review_note должен помечать запись миграции');
    }

    // ================================================================
    // helpers
    // ================================================================

    /**
     * @param array<int, mixed> $args
     */
    private function invoke_private_static(string $method, array $args): mixed
    {
        $ref = new ReflectionMethod('Cashback_Fraud_Admin', $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }
}
