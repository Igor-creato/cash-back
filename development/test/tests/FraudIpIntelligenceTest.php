<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты для Cashback_Fraud_Ip_Intelligence.
 *
 * Покрывает:
 *  - is_excluded() для всех граничных IP диапазонов RFC 1918, RFC 6598, link-local, loopback
 *  - IPv6: fc00::/7, fe80::/10, ::1, 2001:db8::/32
 *  - get_weight_multiplier() для всех типов
 *  - get_type_label() для всех типов
 *  - classify() без MaxMind БД (graceful degradation)
 *  - classify() для CGNAT и приватных IP (без обращения к БД)
 */
#[Group('fraud-ip-intelligence')]
class FraudIpIntelligenceTest extends TestCase
{
    protected function setUp(): void
    {
        // Файл подключаем здесь, чтобы тесты антифрод-IP не зависели от bootstrap.php.
        // Bootstrap уже определил ABSPATH, так что guard не выкинет наc.
        $file = dirname(__DIR__, 3) . '/antifraud/class-fraud-ip-intelligence.php';
        if (!class_exists('Cashback_Fraud_Ip_Intelligence') && file_exists($file)) {
            require_once $file;
        }

        // Минимальные wp_cache_* стабы — bootstrap.php их не определяет.
        if (!function_exists('wp_cache_get')) {
            // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols -- Стабы только для тестов.
            eval('function wp_cache_get(string $key, string $group = "", bool $force = false, ?bool &$found = null): mixed { $found = false; return false; }');
        }
        if (!function_exists('wp_cache_set')) {
            // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols -- Стабы только для тестов.
            eval('function wp_cache_set(string $key, mixed $data, string $group = "", int $expire = 0): bool { return true; }');
        }

        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', dirname(__DIR__, 5));
        }
    }

    // ================================================================
    // is_excluded() — IPv4
    // ================================================================

    public static function excluded_ipv4_provider(): array
    {
        return array(
            // RFC 1918 — 10/8
            'rfc1918 10.0.0.0 lower bound'      => array('10.0.0.0', true),
            'rfc1918 10.255.255.255 upper bound' => array('10.255.255.255', true),
            'public 11.0.0.1 just outside 10/8' => array('11.0.0.1', false),

            // RFC 1918 — 172.16/12
            'rfc1918 172.16.0.0 lower bound'    => array('172.16.0.0', true),
            'rfc1918 172.31.255.255 upper bound' => array('172.31.255.255', true),
            'public 172.15.255.255 just below'  => array('172.15.255.255', false),
            'public 172.32.0.0 just above'      => array('172.32.0.0', false),

            // RFC 1918 — 192.168/16
            'rfc1918 192.168.0.0 lower bound'   => array('192.168.0.0', true),
            'rfc1918 192.168.255.255 upper bound' => array('192.168.255.255', true),
            'public 192.167.255.255 just below' => array('192.167.255.255', false),
            'public 192.169.0.0 just above'     => array('192.169.0.0', false),

            // RFC 6598 CGNAT — 100.64/10
            'cgnat 100.64.0.0 lower bound'      => array('100.64.0.0', true),
            'cgnat 100.127.255.255 upper bound' => array('100.127.255.255', true),
            'public 100.63.255.255 just below'  => array('100.63.255.255', false),
            'public 100.128.0.0 just above'     => array('100.128.0.0', false),

            // link-local / loopback
            'link-local 169.254.0.1'            => array('169.254.0.1', true),
            'loopback 127.0.0.1'                => array('127.0.0.1', true),

            // public Google DNS
            'public 8.8.8.8'                    => array('8.8.8.8', false),
        );
    }

    #[DataProvider('excluded_ipv4_provider')]
    public function test_is_excluded_ipv4(string $ip, bool $expected): void
    {
        $this->assertSame($expected, Cashback_Fraud_Ip_Intelligence::is_excluded($ip));
    }

    // ================================================================
    // is_excluded() — IPv6
    // ================================================================

    public static function excluded_ipv6_provider(): array
    {
        return array(
            'fe80::1 link-local'      => array('fe80::1', true),
            'fc00::1 ULA'             => array('fc00::1', true),
            'fd00::1 ULA range'       => array('fd00::1', true),
            '2001:db8::1 documentation' => array('2001:db8::1', true),
            '2001:4860::1 public Google' => array('2001:4860::1', false),
        );
    }

    #[DataProvider('excluded_ipv6_provider')]
    public function test_is_excluded_ipv6(string $ip, bool $expected): void
    {
        $this->assertSame($expected, Cashback_Fraud_Ip_Intelligence::is_excluded($ip));
    }

    // ================================================================
    // get_weight_multiplier()
    // ================================================================

    public static function weight_provider(): array
    {
        return array(
            'mobile'      => array('mobile', 0.2),
            'cgnat'       => array('cgnat', 0.0),
            'private'     => array('private', 0.0),
            'residential' => array('residential', 1.0),
            'hosting'     => array('hosting', 2.0),
            'vpn'         => array('vpn', 1.8),
            'tor'         => array('tor', 3.0),
            'unknown'     => array('unknown', 1.0),
            'unmapped'    => array('garbage', 1.0),
        );
    }

    #[DataProvider('weight_provider')]
    public function test_get_weight_multiplier(string $type, float $expected): void
    {
        $this->assertSame($expected, Cashback_Fraud_Ip_Intelligence::get_weight_multiplier($type));
    }

    // ================================================================
    // get_type_label()
    // ================================================================

    public static function label_provider(): array
    {
        return array(
            array('mobile',      'MOBILE'),
            array('cgnat',       'CGNAT'),
            array('private',     'PRIVATE'),
            array('residential', 'RESIDENTIAL'),
            array('hosting',     'HOSTING'),
            array('vpn',         'VPN'),
            array('tor',         'TOR'),
            array('unknown',     'UNKNOWN'),
            array('garbage',     'UNKNOWN'),
        );
    }

    #[DataProvider('label_provider')]
    public function test_get_type_label(string $type, string $expected): void
    {
        $this->assertSame($expected, Cashback_Fraud_Ip_Intelligence::get_type_label($type));
    }

    // ================================================================
    // classify()
    // ================================================================

    public function test_classify_public_ip_without_db_returns_unknown(): void
    {
        // Без MaxMind-БД 8.8.8.8 не должен ни падать, ни выдавать confidence.
        $result = Cashback_Fraud_Ip_Intelligence::classify('8.8.8.8');

        $this->assertSame('unknown', $result['type']);
        $this->assertSame(1.0, $result['weight_multiplier']);
        $this->assertFalse($result['is_excluded']);
        $this->assertSame('UNKNOWN', $result['label']);
    }

    public function test_classify_cgnat_ip(): void
    {
        $result = Cashback_Fraud_Ip_Intelligence::classify('100.64.5.10');

        $this->assertSame('cgnat', $result['type']);
        $this->assertTrue($result['is_excluded']);
        $this->assertSame(0.0, $result['weight_multiplier']);
        $this->assertSame('CGNAT', $result['label']);
        $this->assertNull($result['asn']);
    }

    public function test_classify_private_ip(): void
    {
        $result = Cashback_Fraud_Ip_Intelligence::classify('192.168.1.1');

        $this->assertSame('private', $result['type']);
        $this->assertTrue($result['is_excluded']);
        $this->assertSame(0.0, $result['weight_multiplier']);
        $this->assertSame('PRIVATE', $result['label']);
    }

    public function test_classify_invalid_ip_returns_unknown(): void
    {
        $result = Cashback_Fraud_Ip_Intelligence::classify('not-an-ip');

        $this->assertSame('unknown', $result['type']);
        $this->assertFalse($result['is_excluded']);
        $this->assertNull($result['asn']);
    }

    public function test_is_private_or_cgnat_alias(): void
    {
        $this->assertTrue(Cashback_Fraud_Ip_Intelligence::is_private_or_cgnat('100.64.0.1'));
        $this->assertTrue(Cashback_Fraud_Ip_Intelligence::is_private_or_cgnat('192.168.1.1'));
        $this->assertFalse(Cashback_Fraud_Ip_Intelligence::is_private_or_cgnat('8.8.8.8'));
    }

    public function test_is_database_available_false_for_missing_path(): void
    {
        // По умолчанию в тестовой среде MaxMind-БД нет — метод должен честно вернуть false.
        $this->assertFalse(Cashback_Fraud_Ip_Intelligence::is_database_available());
    }
}
