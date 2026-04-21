<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Тесты Cashback_Outbound_HTTP_Guard — SSRF-защиты исходящих HTTP-запросов.
 *
 * Покрывает:
 * - scheme (только https)
 * - private-IP литералы (RFC1918, 127/8, 169.254/16, loopback, IPv6 private/link-local)
 * - allowlist baseline (EPN, Admitad, SmartCaptcha)
 * - custom allowlist через wp_option cashback_outbound_allowlist_custom
 * - filter-hook cashback_outbound_allowlist (site-owner override)
 * - CASHBACK_OUTBOUND_ALLOWLIST_RELAX — релаксирует только allowlist, private-IP всегда блок
 * - audit-log на deny
 *
 * См. ADR Группа 3, findings F-12-001, F-4-005.
 */
#[Group('security')]
#[Group('ssrf')]
#[Group('outbound')]
class OutboundHttpGuardTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        $guard_file = self::$plugin_root . '/includes/class-cashback-outbound-http-guard.php';
        if (!file_exists($guard_file)) {
            self::markTestSkipped('Cashback_Outbound_HTTP_Guard file not present yet.');
        }
        if (!class_exists('Cashback_Outbound_HTTP_Guard')) {
            require_once $guard_file;
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options']     = array();
        $GLOBALS['_cb_test_filters']     = array();
        $GLOBALS['_cb_audit_log_calls']  = array();

        // Мок $wpdb для write_audit_log (no-op insert + счётчик).
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function insert(string $table, array $data, $format = null): int
            {
                $GLOBALS['_cb_audit_log_calls'][] = array( 'table' => $table, 'data' => $data );
                return 1;
            }
        };

        Cashback_Outbound_HTTP_Guard::invalidate_cache();
    }

    protected function tearDown(): void
    {
        $GLOBALS['_cb_test_options'] = array();
        $GLOBALS['_cb_test_filters'] = array();
        unset($GLOBALS['wpdb']);
        Cashback_Outbound_HTTP_Guard::invalidate_cache();
    }

    // ================================================================
    // Baseline allowlist — happy path
    // ================================================================

    public function test_allowed_host_passes_admitad(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://api.admitad.com/token/');
        $this->assertTrue($result);
    }

    public function test_allowed_host_passes_epn_oauth(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://oauth2.epn.bz/ssid?v=2&foo=bar');
        $this->assertTrue($result);
    }

    public function test_allowed_host_passes_epn_app(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://app.epn.bz/transactions/user');
        $this->assertTrue($result);
    }

    public function test_allowed_host_passes_smartcaptcha(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://smartcaptcha.yandexcloud.net/validate');
        $this->assertTrue($result);
    }

    public function test_case_insensitive_host(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://API.Admitad.COM/x');
        $this->assertTrue($result);
    }

    // ================================================================
    // Scheme checks
    // ================================================================

    public function test_scheme_http_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('http://api.admitad.com/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('outbound_denied', $result->get_error_code());
        $this->assertSame('scheme', $result->get_error_message());
    }

    public function test_scheme_file_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('file:///etc/passwd');
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function test_scheme_gopher_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('gopher://api.admitad.com/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('scheme', $result->get_error_message());
    }

    // ================================================================
    // Unknown hosts
    // ================================================================

    public function test_unknown_host_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://evil.example.com/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_in_allowlist', $result->get_error_message());
    }

    // ================================================================
    // Private-IP literals — всегда deny
    // ================================================================

    public function test_private_ip_169_254_metadata_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://169.254.169.254/latest/meta-data/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('private_ip', $result->get_error_message());
    }

    public function test_private_ip_rfc1918_10_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://10.0.0.1/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('private_ip', $result->get_error_message());
    }

    public function test_private_ip_rfc1918_192_168_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://192.168.1.1/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('private_ip', $result->get_error_message());
    }

    public function test_private_ip_rfc1918_172_16_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://172.16.0.1/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('private_ip', $result->get_error_message());
    }

    public function test_loopback_ipv4_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://127.0.0.1/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('private_ip', $result->get_error_message());
    }

    public function test_loopback_ipv6_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://[::1]/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('private_ip', $result->get_error_message());
    }

    public function test_ipv6_unique_local_fc00_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://[fc00::1]/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('private_ip', $result->get_error_message());
    }

    public function test_ipv6_link_local_fe80_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://[fe80::1]/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('private_ip', $result->get_error_message());
    }

    public function test_zero_ip_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://0.0.0.0/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('private_ip', $result->get_error_message());
    }

    public function test_ipv6_public_denied_because_not_in_allowlist(): void
    {
        // Публичный IPv6 → не private, но также не в allowlist.
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://[2001:db8::1]/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_in_allowlist', $result->get_error_message());
    }

    public function test_public_ipv4_literal_denied_because_not_in_allowlist(): void
    {
        // 8.8.8.8 — публичный, не private, но не в allowlist → not_in_allowlist.
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://8.8.8.8/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('not_in_allowlist', $result->get_error_message());
    }

    // ================================================================
    // Malformed URLs
    // ================================================================

    public function test_empty_url_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('malformed', $result->get_error_message());
    }

    public function test_scheme_only_url_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('malformed', $result->get_error_message());
    }

    public function test_garbage_url_denied(): void
    {
        $result = Cashback_Outbound_HTTP_Guard::validate_url('not a url');
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    // ================================================================
    // CASHBACK_OUTBOUND_ALLOWLIST_RELAX
    // ================================================================

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_relax_constant_allows_unknown_host(): void
    {
        define('CASHBACK_OUTBOUND_ALLOWLIST_RELAX', true);

        require_once dirname(__DIR__, 3) . '/includes/class-cashback-outbound-http-guard.php';
        Cashback_Outbound_HTTP_Guard::invalidate_cache();

        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://dev.local/');
        $this->assertTrue($result);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_relax_constant_still_blocks_private_ip(): void
    {
        define('CASHBACK_OUTBOUND_ALLOWLIST_RELAX', true);

        require_once dirname(__DIR__, 3) . '/includes/class-cashback-outbound-http-guard.php';
        Cashback_Outbound_HTTP_Guard::invalidate_cache();

        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://169.254.169.254/');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('private_ip', $result->get_error_message());
    }

    // ================================================================
    // Custom allowlist через wp_option
    // ================================================================

    public function test_custom_host_from_wp_option_passes(): void
    {
        update_option('cashback_outbound_allowlist_custom', array(
            array(
                'host'     => 'api.letyshops.com',
                'added_by' => 1,
                'added_at' => '2026-04-21 12:00:00',
                'reason'   => 'Letyshops onboarding',
            ),
        ));
        Cashback_Outbound_HTTP_Guard::invalidate_cache();

        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://api.letyshops.com/api/offers');
        $this->assertTrue($result);
    }

    // ================================================================
    // Filter hook cashback_outbound_allowlist
    // ================================================================

    public function test_filter_hook_can_add_host(): void
    {
        add_filter('cashback_outbound_allowlist', static function (array $config): array {
            $config['hosts'][] = 'api.filter-added.example';
            return $config;
        });
        Cashback_Outbound_HTTP_Guard::invalidate_cache();

        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://api.filter-added.example/');
        $this->assertTrue($result);
    }

    public function test_suffix_entry_matches_subdomain(): void
    {
        add_filter('cashback_outbound_allowlist', static function (array $config): array {
            $config['suffixes'][] = '.test.local';
            return $config;
        });
        Cashback_Outbound_HTTP_Guard::invalidate_cache();

        $ok = Cashback_Outbound_HTTP_Guard::validate_url('https://api.test.local/');
        $this->assertTrue($ok);
    }

    public function test_suffix_does_not_match_non_boundary(): void
    {
        add_filter('cashback_outbound_allowlist', static function (array $config): array {
            $config['suffixes'][] = '.test.local';
            return $config;
        });
        Cashback_Outbound_HTTP_Guard::invalidate_cache();

        // test.local.evil.com не должен сматчиться .test.local как суффикс.
        $result = Cashback_Outbound_HTTP_Guard::validate_url('https://test.local.evil.com/');
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    // ================================================================
    // Audit log
    // ================================================================

    public function test_audit_log_written_on_deny(): void
    {
        Cashback_Outbound_HTTP_Guard::validate_url('https://169.254.169.254/');

        $this->assertNotEmpty($GLOBALS['_cb_audit_log_calls']);
        $entry = $GLOBALS['_cb_audit_log_calls'][0];
        $this->assertSame('outbound_request_denied', $entry['data']['action']);

        $details = json_decode((string) $entry['data']['details'], true);
        $this->assertIsArray($details);
        $this->assertSame('169.254.169.254', $details['host']);
        $this->assertSame('private_ip', $details['reason']);
    }

    public function test_audit_log_not_written_on_allow(): void
    {
        Cashback_Outbound_HTTP_Guard::validate_url('https://api.admitad.com/');

        $this->assertEmpty($GLOBALS['_cb_audit_log_calls']);
    }

    // ================================================================
    // is_private_ip_literal — внутренний helper (для уверенности)
    // ================================================================

    public function test_is_private_ip_literal_ipv4_cases(): void
    {
        $this->assertTrue(Cashback_Outbound_HTTP_Guard::is_private_ip_literal('127.0.0.1'));
        $this->assertTrue(Cashback_Outbound_HTTP_Guard::is_private_ip_literal('10.0.0.1'));
        $this->assertTrue(Cashback_Outbound_HTTP_Guard::is_private_ip_literal('192.168.1.1'));
        $this->assertTrue(Cashback_Outbound_HTTP_Guard::is_private_ip_literal('172.16.0.1'));
        $this->assertTrue(Cashback_Outbound_HTTP_Guard::is_private_ip_literal('169.254.169.254'));
        $this->assertTrue(Cashback_Outbound_HTTP_Guard::is_private_ip_literal('0.0.0.0'));
        $this->assertFalse(Cashback_Outbound_HTTP_Guard::is_private_ip_literal('8.8.8.8'));
        $this->assertFalse(Cashback_Outbound_HTTP_Guard::is_private_ip_literal('api.admitad.com'));
    }

    public function test_is_private_ip_literal_ipv6_cases(): void
    {
        $this->assertTrue(Cashback_Outbound_HTTP_Guard::is_private_ip_literal('::1'));
        $this->assertTrue(Cashback_Outbound_HTTP_Guard::is_private_ip_literal('fc00::1'));
        $this->assertTrue(Cashback_Outbound_HTTP_Guard::is_private_ip_literal('fe80::1'));
        $this->assertFalse(Cashback_Outbound_HTTP_Guard::is_private_ip_literal('2001:db8::1'));
    }
}
