<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты шифрования опции cashback_epn_refresh_<md5(client_id)>.
 *
 * Покрывает:
 * - store_refresh_token() приватный метод → в wp_options ciphertext (ENC:v1:...)
 * - load_refresh_token() приватный метод → plaintext на выходе
 * - Backward-compat для plaintext refresh_token из старых инсталляций
 *
 * См. ADR Группа 2, finding F-13-001.
 */
#[Group('encryption')]
#[Group('option_encryption')]
#[Group('epn')]
class EpnRefreshTokenEncryptionTest extends TestCase
{
    private const OPTION_KEY = 'cashback_epn_refresh_testkey';

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options'] = array();
    }

    protected function tearDown(): void
    {
        $GLOBALS['_cb_test_options'] = array();
    }

    /**
     * Загружаем adapter-классы только здесь, т.к. они зависят от WP-функций
     * (wp_remote_get/post) которые стабятся уже в bootstrap.
     */
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        self::require_if_missing($plugin_root . '/includes/adapters/interface-cashback-network-adapter.php', null);
        self::require_if_missing($plugin_root . '/includes/adapters/abstract-cashback-network-adapter.php', 'Cashback_Network_Adapter_Base');
        self::require_if_missing($plugin_root . '/includes/adapters/class-epn-adapter.php', 'Cashback_Epn_Adapter');
    }

    private static function require_if_missing(string $file, ?string $class): void
    {
        if ($class !== null && class_exists($class)) {
            return;
        }
        if (!file_exists($file)) {
            self::markTestSkipped("Adapter file missing: {$file}");
        }
        require_once $file;
    }

    // ================================================================
    // store_refresh_token() — шифрование при записи
    // ================================================================

    public function test_store_refresh_token_writes_ciphertext_to_wp_options(): void
    {
        $adapter = new Cashback_Epn_Adapter();
        $method  = new ReflectionMethod($adapter, 'store_refresh_token');
        $method->setAccessible(true);

        $method->invoke($adapter, self::OPTION_KEY, 'epn-refresh-plain-abc');

        $stored = get_option(self::OPTION_KEY, '');
        $this->assertNotSame('epn-refresh-plain-abc', $stored, 'В wp_options должен быть НЕ plaintext');
        $this->assertStringStartsWith('ENC:v1:', (string) $stored);
    }

    public function test_store_refresh_token_empty_string_still_stores_empty(): void
    {
        // Защитная инварианта: пустая строка — валидный edge-case (сброс)
        $adapter = new Cashback_Epn_Adapter();
        $method  = new ReflectionMethod($adapter, 'store_refresh_token');
        $method->setAccessible(true);

        $method->invoke($adapter, self::OPTION_KEY, '');

        $this->assertSame('', get_option(self::OPTION_KEY, null));
    }

    // ================================================================
    // load_refresh_token() — расшифровка при чтении
    // ================================================================

    public function test_load_refresh_token_roundtrip(): void
    {
        $adapter = new Cashback_Epn_Adapter();
        $store   = new ReflectionMethod($adapter, 'store_refresh_token');
        $load    = new ReflectionMethod($adapter, 'load_refresh_token');
        $store->setAccessible(true);
        $load->setAccessible(true);

        $store->invoke($adapter, self::OPTION_KEY, 'rotating-refresh-token');
        $this->assertSame('rotating-refresh-token', $load->invoke($adapter, self::OPTION_KEY));
    }

    public function test_load_refresh_token_backward_compat_reads_plaintext(): void
    {
        // Старая инсталляция — в БД plaintext без префикса
        update_option(self::OPTION_KEY, 'legacy-refresh-token-xyz');

        $adapter = new Cashback_Epn_Adapter();
        $load    = new ReflectionMethod($adapter, 'load_refresh_token');
        $load->setAccessible(true);

        $this->assertSame('legacy-refresh-token-xyz', $load->invoke($adapter, self::OPTION_KEY));
    }

    public function test_load_refresh_token_returns_empty_when_missing(): void
    {
        $adapter = new Cashback_Epn_Adapter();
        $load    = new ReflectionMethod($adapter, 'load_refresh_token');
        $load->setAccessible(true);

        $this->assertSame('', $load->invoke($adapter, 'cashback_epn_refresh_nope'));
    }

    // ================================================================
    // Sanity: старые direct get_option/update_option callsite заменены
    // ================================================================

    public function test_epn_adapter_source_uses_helpers_not_direct_option_api_for_refresh(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/includes/adapters/class-epn-adapter.php'
        );
        $this->assertNotFalse($source);

        // Рефреш-токен больше не читается/пишется напрямую через *_option
        // (оставляем delete_option для сброса — он plaintext-agnostic).
        $this->assertStringNotContainsString(
            "get_option(\$refresh_key",
            $source,
            'Прямой get_option для refresh_key должен быть заменён на load_refresh_token()'
        );
        $this->assertStringNotContainsString(
            "update_option(\$refresh_key",
            $source,
            'Прямой update_option для refresh_key должен быть заменён на store_refresh_token()'
        );
        $this->assertStringContainsString('load_refresh_token', $source);
        $this->assertStringContainsString('store_refresh_token', $source);
    }
}
