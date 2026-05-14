<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Покрытие фикса mobile-wishlist stale fastcgi_cache (2026-05-14).
 *
 * Cashback_Wishlist_Ux::send_no_store_headers() отправляет
 * `Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0`
 * + стандартный nocache_headers() на странице WoodMart wishlist
 * (id = woodmart_get_opt('wishlist_page')). Это отключает nginx
 * fastcgi_cache (бэкенд-Cache-Control: no-store уважается дефолтом),
 * HTTP-кэш браузера и bfcache в Chromium-браузерах (Chrome Mobile,
 * Яндекс), которые строго следуют no-store при инвалидации bfcache.
 *
 * Тестовая стратегия:
 *  - НЕ объявляем глобальные WP-функции `is_page`/`woodmart_get_opt` —
 *    их отсутствие — контракт для LegalAuditTest, который чекает
 *    function_exists('woodmart_get_opt')===false.
 *  - Вместо этого используем test-only override через статические
 *    setter'ы (`set_is_wishlist_page_override_for_tests`,
 *    `set_header_emitter_for_tests`).
 *  - В PHP CLI native `header()` no-op + `headers_list()` пустой, поэтому
 *    перехватываем emitter'ом, который пишет в массив текущего теста.
 *
 * Структурный тест в конце фиксирует, что register() добавляет
 * template_redirect с приоритетом 0 — поломка приоритета или имени
 * метода (например ребрендинг) уронит тест.
 */
if (!class_exists('Cashback_Wishlist_Ux', false)) {
    require_once dirname(__DIR__, 3) . '/includes/class-cashback-wishlist-ux.php';
}

#[Group('wishlist')]
#[Group('cache')]
final class CashbackWishlistNoStoreHeadersTest extends TestCase
{
    /** @var array<int,array{name:string,value:string}> Заголовки, перехваченные текущим тестом. */
    private array $captured_headers = array();

    protected function setUp(): void
    {
        parent::setUp();
        $this->captured_headers = array();

        Cashback_Wishlist_Ux::set_header_emitter_for_tests(
            function (string $name, string $value): void {
                $this->captured_headers[] = array( 'name' => $name, 'value' => $value );
            }
        );
        Cashback_Wishlist_Ux::set_is_wishlist_page_override_for_tests(null);
    }

    protected function tearDown(): void
    {
        Cashback_Wishlist_Ux::set_header_emitter_for_tests(null);
        Cashback_Wishlist_Ux::set_is_wishlist_page_override_for_tests(null);
        parent::tearDown();
    }

    public function test_no_store_header_sent_on_wishlist_page(): void
    {
        Cashback_Wishlist_Ux::set_is_wishlist_page_override_for_tests(true);

        Cashback_Wishlist_Ux::send_no_store_headers();

        $cc = $this->find_header('Cache-Control');
        $this->assertNotNull($cc, 'Cache-Control header must be sent on wishlist page');
        $this->assertStringContainsString('no-store', $cc, 'Cache-Control must contain no-store (отключает nginx fastcgi_cache + bfcache)');
        $this->assertStringContainsString('private', $cc, 'Cache-Control must contain private (защита от shared caches)');
        $this->assertStringContainsString('no-cache', $cc, 'Cache-Control must contain no-cache');
        $this->assertStringContainsString('must-revalidate', $cc, 'Cache-Control must contain must-revalidate');
    }

    public function test_expires_header_sent_on_wishlist_page(): void
    {
        Cashback_Wishlist_Ux::set_is_wishlist_page_override_for_tests(true);

        Cashback_Wishlist_Ux::send_no_store_headers();

        $expires = $this->find_header('Expires');
        $this->assertNotNull($expires, 'Expires header must be sent (часть nocache_headers WP-стандарта)');
        $this->assertStringContainsString('1984', $expires, 'Expires должен быть прошедшей датой (WP convention)');
    }

    public function test_no_headers_on_non_wishlist_page(): void
    {
        Cashback_Wishlist_Ux::set_is_wishlist_page_override_for_tests(false);

        Cashback_Wishlist_Ux::send_no_store_headers();

        $this->assertNull(
            $this->find_header('Cache-Control'),
            'Cache-Control НЕ должен отправляться вне страницы wishlist (иначе ломаем fastcgi_cache главной/каталога)'
        );
        $this->assertNull(
            $this->find_header('Expires'),
            'Expires НЕ должен отправляться вне страницы wishlist'
        );
    }

    public function test_no_headers_when_theme_inactive_default(): void
    {
        // Override = null → используется реальная is_wishlist_page(), которая
        // вернёт false если woodmart_get_opt не определена (тема не активна).
        Cashback_Wishlist_Ux::set_is_wishlist_page_override_for_tests(null);

        Cashback_Wishlist_Ux::send_no_store_headers();

        $this->assertNull($this->find_header('Cache-Control'));
    }

    /* ----------------------------------------------------------------
       Структурный тест: register() подключает хук правильным образом.
       Защищает от ребрендинга метода / смены приоритета.
       ---------------------------------------------------------------- */

    public function test_register_hooks_template_redirect_with_send_no_store_headers_at_priority_zero(): void
    {
        $rm   = new \ReflectionMethod(Cashback_Wishlist_Ux::class, 'register');
        $body = $this->extract_method_body($rm);

        $this->assertMatchesRegularExpression(
            "/add_action\\(\\s*['\"]template_redirect['\"]\\s*,\\s*array\\(\\s*self::class\\s*,\\s*['\"]send_no_store_headers['\"]\\s*\\)\\s*,\\s*0\\s*\\)/s",
            $body,
            "register() должен делать add_action('template_redirect', [self::class, 'send_no_store_headers'], 0) — приоритет 0 критичен для срабатывания до возможных кэш-плагинов"
        );
    }

    public function test_send_no_store_headers_method_is_public_static(): void
    {
        $rm = new \ReflectionMethod(Cashback_Wishlist_Ux::class, 'send_no_store_headers');
        $this->assertTrue($rm->isPublic(), 'send_no_store_headers должен быть public (вызывается WP-хуком)');
        $this->assertTrue($rm->isStatic(), 'send_no_store_headers должен быть static (consistency с остальным API класса)');
    }

    /* ---------------- helpers ---------------- */

    private function find_header(string $name): ?string
    {
        $needle = strtolower($name);
        foreach ($this->captured_headers as $h) {
            if (strtolower($h['name']) === $needle) {
                return $h['value'];
            }
        }
        return null;
    }

    private function extract_method_body(\ReflectionMethod $rm): string
    {
        $file = $rm->getFileName();
        $this->assertIsString($file, 'ReflectionMethod must resolve source file');
        $start = $rm->getStartLine();
        $end   = $rm->getEndLine();
        $lines = file($file);
        $this->assertIsArray($lines, 'file() must read class source');
        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
