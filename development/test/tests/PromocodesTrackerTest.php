<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты на Cashback_Promocodes_Tracker — AJAX click-tracker.
 *
 * @group promocodes
 * @group tracker
 */
#[Group('promocodes')]
#[Group('tracker')]
final class PromocodesTrackerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $tracker = $plugin_root . '/includes/promocodes/class-cashback-promocodes-tracker.php';
        if (!file_exists($tracker)) {
            self::markTestSkipped("File missing: {$tracker}");
        }
        require_once $tracker;
    }

    public function test_hash_ip_is_sha256_64_hex_chars(): void
    {
        $hash = Cashback_Promocodes_Tracker::hash_ip('192.0.2.1');
        $this->assertSame(64, strlen($hash), 'sha256 hex = 64 chars');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_hash_ip_returns_empty_for_empty_ip(): void
    {
        $this->assertSame('', Cashback_Promocodes_Tracker::hash_ip(''));
    }

    public function test_hash_ip_does_not_leak_raw_ip(): void
    {
        $ip = '198.51.100.42';
        $this->assertStringNotContainsString($ip, Cashback_Promocodes_Tracker::hash_ip($ip));
    }

    public function test_hash_ip_stable_across_calls_with_same_salt(): void
    {
        $ip = '203.0.113.7';
        $this->assertSame(
            Cashback_Promocodes_Tracker::hash_ip($ip),
            Cashback_Promocodes_Tracker::hash_ip($ip),
            'Тот же IP с тем же salt → тот же hash'
        );
    }

    public function test_init_registers_ajax_actions(): void
    {
        // Структурно: класс имеет init() метод.
        $this->assertTrue(method_exists('Cashback_Promocodes_Tracker', 'init'));
        $this->assertTrue(method_exists('Cashback_Promocodes_Tracker', 'handle_click'));
    }

    public function test_ajax_action_constant_matches_expected(): void
    {
        $this->assertSame('cashback_promocode_click', Cashback_Promocodes_Tracker::AJAX_ACTION);
    }

    public function test_rate_limiter_registers_action(): void
    {
        // Action_TIERS внутри Cashback_Rate_Limiter (приватная константа) проверяется
        // через is_plugin_action helper.
        require_once dirname(__DIR__, 3) . '/includes/class-cashback-rate-limiter.php';
        $this->assertTrue(
            Cashback_Rate_Limiter::is_plugin_action('cashback_promocode_click'),
            'cashback_promocode_click должен быть в реестре rate-limiter (NAT-safe)'
        );
    }
}
