<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Группа 12h-1 ADR — deep-link policy (F-2-001, продуктовое решение получено 2026-04-23).
 *
 * Closes F-2-001: партнёрам/админам нельзя указывать non-http(s) URL как destination
 * в WooCommerce external product URL. Только `http://` / `https://`. Deep-linking в
 * мобильные приложения — будущая фича через отдельный routing layer.
 *
 * Root-cause (до рефактора):
 *   build_final_affiliate_url() проверяет scheme и возвращает null при non-http(s),
 *   но caller в handle_click_redirect() fallback'ится на `$product->get_product_url()`
 *   без повторной проверки и редиректит туда. 3 дубликата scheme-check (build_final +
 *   handle_activation_page×2) — DRY-нарушение, lost-sync риск.
 *
 * Контракт (TDD RED):
 *  1. Приватный helper `WC_Affiliate_URL_Params::is_safe_http_url(string $url): bool`
 *     — single point of truth, true только для http/https.
 *  2. В `handle_click_redirect()` после `$fallback_url = $product->get_product_url()`
 *     — проверка через helper, при fail → `wp_redirect(home_url())` + error_log.
 *  3. Три существующих call-site переведены на helper (DRY-refactor).
 *
 * Behavioural-тесты используют Reflection для вызова private helper
 * (паттерн из ApiClientParseDateTest.php, Группа 12e).
 */
#[Group('security')]
#[Group('group12')]
#[Group('wc-affiliate')]
#[Group('f-2-001')]
class WcAffiliateUrlSchemeValidationTest extends TestCase
{
    private string $source = '';

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('WC_Affiliate_URL_Params')) {
            require_once dirname(__DIR__, 3) . '/wc-affiliate-url-params.php';
        }
    }

    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $path        = $plugin_root . '/wc-affiliate-url-params.php';

        self::assertFileExists($path);

        $content = file_get_contents($path);
        self::assertNotFalse($content);
        $this->source = $content;
    }

    /**
     * Извлечь тело метода по имени. Паттерн покрывает public/private/protected + void/string/bool.
     */
    private function extract_method( string $method_name ): string
    {
        $pattern = '/(?:public|private|protected)\s+(?:static\s+)?function\s+'
            . preg_quote($method_name, '/')
            . '\s*\([^)]*\)(?:\s*:\s*\??[\w\\\\]+)?\s*\{/';

        if (preg_match($pattern, $this->source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            self::fail('Метод ' . $method_name . '() не найден — структура изменилась?');
        }

        $start = (int) $m[0][1];
        $brace = strpos($this->source, '{', $start);
        if ($brace === false) {
            self::fail('Нет открывающей скобки у ' . $method_name);
        }

        $depth = 0;
        $len   = strlen($this->source);
        for ($i = $brace; $i < $len; $i++) {
            if ($this->source[$i] === '{') {
                $depth++;
            } elseif ($this->source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($this->source, $brace, $i - $brace + 1);
                }
            }
        }

        self::fail('Нет закрывающей скобки у ' . $method_name);
    }

    /**
     * Вызвать private is_safe_http_url через reflection.
     *
     * Используем newInstanceWithoutConstructor, чтобы не вызывать __construct, который
     * делает remove_action() — WP-функции, не замоканной в bootstrap.
     */
    private function invoke_helper( string $url ): bool
    {
        $reflection = new ReflectionClass('WC_Affiliate_URL_Params');
        $instance   = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('is_safe_http_url');
        $method->setAccessible(true);

        return (bool) $method->invoke($instance, $url);
    }

    // =====================================================================
    // 1. Helper is_safe_http_url существует с правильной сигнатурой
    // =====================================================================

    public function test_is_safe_http_url_helper_exists(): void
    {
        self::assertMatchesRegularExpression(
            '/private\s+function\s+is_safe_http_url\s*\(\s*string\s+\$\w+\s*\)\s*:\s*bool/',
            $this->source,
            'F-2-001: должен быть private helper `is_safe_http_url(string $url): bool` — '
            . 'single point of truth для scheme-валидации destination URL.'
        );
    }

    // =====================================================================
    // 2. Behavioural: helper правильно различает схемы
    // =====================================================================

    public function test_http_url_is_safe(): void
    {
        self::assertTrue($this->invoke_helper('http://example.com/product/123'));
    }

    public function test_https_url_is_safe(): void
    {
        self::assertTrue($this->invoke_helper('https://shop.example.com/p/123?a=b'));
    }

    public function test_intent_scheme_is_blocked(): void
    {
        self::assertFalse(
            $this->invoke_helper('intent://shopee.app/product/123#Intent;scheme=intent;end'),
            'F-2-001: intent:// — deep-link в мобильное приложение, должен быть заблокирован.'
        );
    }

    public function test_market_scheme_is_blocked(): void
    {
        self::assertFalse(
            $this->invoke_helper('market://details?id=com.example.app'),
            'F-2-001: market:// — Google Play deep-link, должен быть заблокирован.'
        );
    }

    public function test_javascript_scheme_is_blocked(): void
    {
        self::assertFalse(
            $this->invoke_helper('javascript:alert(1)'),
            'F-2-001: javascript: — classic XSS vector, должен быть заблокирован.'
        );
    }

    public function test_data_scheme_is_blocked(): void
    {
        self::assertFalse(
            $this->invoke_helper('data:text/html,<script>alert(1)</script>'),
            'F-2-001: data: — inline payload, должен быть заблокирован.'
        );
    }

    public function test_file_scheme_is_blocked(): void
    {
        self::assertFalse($this->invoke_helper('file:///etc/passwd'));
    }

    public function test_ftp_scheme_is_blocked(): void
    {
        self::assertFalse($this->invoke_helper('ftp://example.com/file.txt'));
    }

    public function test_ms_windows_store_scheme_is_blocked(): void
    {
        self::assertFalse(
            $this->invoke_helper('ms-windows-store://pdp/?productid=XYZ'),
            'F-2-001: ms-windows-store:// — Windows Store deep-link, должен быть заблокирован.'
        );
    }

    public function test_tg_scheme_is_blocked(): void
    {
        self::assertFalse(
            $this->invoke_helper('tg://resolve?domain=example'),
            'F-2-001: tg:// — Telegram deep-link, должен быть заблокирован.'
        );
    }

    public function test_empty_string_is_blocked(): void
    {
        self::assertFalse($this->invoke_helper(''));
    }

    public function test_garbage_is_blocked(): void
    {
        self::assertFalse($this->invoke_helper('not a url'));
    }

    // =====================================================================
    // 3. handle_click_redirect валидирует $fallback_url
    // =====================================================================

    public function test_handle_click_redirect_validates_fallback_url(): void
    {
        $body = $this->extract_method('handle_click_redirect');

        // $fallback_url инициализируется через get_product_url() и валидируется через helper.
        self::assertMatchesRegularExpression(
            '/\$fallback_url\s*=\s*\$product->get_product_url\s*\(\s*\)/',
            $body,
            'Regression: инициализация $fallback_url через get_product_url() должна сохраниться.'
        );

        self::assertMatchesRegularExpression(
            '/is_safe_http_url\s*\(\s*\$fallback_url\s*\)/',
            $body,
            'F-2-001: handle_click_redirect должен валидировать $fallback_url через is_safe_http_url.'
        );

        // Порядок: get_product_url() → is_safe_http_url($fallback_url).
        $get_pos = strpos($body, '$product->get_product_url()');
        $chk_pos = strpos($body, 'is_safe_http_url($fallback_url)');
        self::assertIsInt($get_pos);
        self::assertIsInt($chk_pos);
        self::assertLessThan(
            $chk_pos,
            $get_pos,
            'F-2-001: is_safe_http_url($fallback_url) должен стоять ПОСЛЕ $fallback_url = get_product_url().'
        );

        // При fail валидации — wp_redirect(home_url(), 302).
        self::assertMatchesRegularExpression(
            '/!\s*\$this->is_safe_http_url\s*\(\s*\$fallback_url\s*\)[\s\S]{0,600}wp_redirect\s*\(\s*home_url\s*\(\s*\)\s*,\s*302/',
            $body,
            'F-2-001: при fail валидации должна быть ветка wp_redirect(home_url(), 302) + exit.'
        );
    }

    public function test_handle_click_redirect_logs_rejected_scheme(): void
    {
        $body = $this->extract_method('handle_click_redirect');

        // Reject-ветка должна писать в error_log с префиксом [wc-affiliate-url-params].
        self::assertMatchesRegularExpression(
            '/error_log\s*\([^)]*\[wc-affiliate-url-params\][^)]*(?:Rejected|unsafe|scheme)/i',
            $body,
            'F-2-001: reject non-http(s) должен логироваться ([wc-affiliate-url-params] + scheme/unsafe/rejected) '
            . 'для post-mortem-grep и идентификации "битых" product_url.'
        );
    }

    // =====================================================================
    // 4. DRY-refactor: 3 call-site используют helper, а не inline in_array
    // =====================================================================

    public function test_build_final_affiliate_url_uses_helper(): void
    {
        $body = $this->extract_method('build_final_affiliate_url');

        self::assertMatchesRegularExpression(
            '/\$this->is_safe_http_url\s*\(/',
            $body,
            'F-2-001 DRY: build_final_affiliate_url должен использовать $this->is_safe_http_url вместо inline in_array(...).'
        );

        // Убеждаемся что inline проверки больше нет.
        self::assertDoesNotMatchRegularExpression(
            "/in_array\s*\(\s*\\\$scheme\s*,\s*array\s*\(\s*['\"]http['\"]\s*,\s*['\"]https['\"]\s*\)/",
            $body,
            'F-2-001 DRY: inline `in_array($scheme, array(http, https))` должен быть вынесен в helper.'
        );
    }

    public function test_handle_activation_page_uses_helper(): void
    {
        $body = $this->extract_method('handle_activation_page');

        // В handle_activation_page два call-site (guest path + authed path) — оба должны использовать helper.
        $helper_calls = preg_match_all('/\$this->is_safe_http_url\s*\(/', $body);
        self::assertGreaterThanOrEqual(
            2,
            $helper_calls,
            'F-2-001 DRY: handle_activation_page должен использовать is_safe_http_url как минимум в 2 местах '
            . '(guest redirect + authed activation page).'
        );

        // Inline проверки убраны.
        self::assertDoesNotMatchRegularExpression(
            "/in_array\s*\(\s*wp_parse_url\s*\([^)]+\)\s*,\s*array\s*\(\s*['\"]http['\"]\s*,\s*['\"]https['\"]\s*\)/",
            $body,
            'F-2-001 DRY: inline `in_array(wp_parse_url(...), array(http, https))` должен быть через helper.'
        );
    }

    // =====================================================================
    // 5. Regression guards
    // =====================================================================

    public function test_build_final_affiliate_url_still_returns_null_for_unsafe(): void
    {
        // Regression: build_final_affiliate_url должен возвращать null при non-http(s) base_url,
        // чтобы не генерировать URL с партнёрскими параметрами для opaque-scheme target.
        $body = $this->extract_method('build_final_affiliate_url');
        self::assertMatchesRegularExpression(
            '/!\s*\$this->is_safe_http_url[\s\S]{0,100}return\s+null/',
            $body,
            'Regression: build_final_affiliate_url должен по-прежнему возвращать null при небезопасной схеме.'
        );
    }

    public function test_existing_http_redirect_path_preserved(): void
    {
        // Regression: wp_redirect($affiliate_url, 302) на валидном URL остаётся.
        $body = $this->extract_method('handle_click_redirect');
        self::assertMatchesRegularExpression(
            '/wp_redirect\s*\(\s*\$affiliate_url\s*,\s*302\s*\)/',
            $body,
            'Regression: основная ветка wp_redirect($affiliate_url, 302) для http(s) должна остаться.'
        );
    }

    // =====================================================================
    // 12h-2: admin save validation (F-2-001, admin-side)
    // =====================================================================
    //
    // Контракт: при admin save external WooCommerce product через wp-admin
    // хук `woocommerce_admin_process_product_object` должен вызывать
    // validate_external_product_url_scheme($product). Если product_url
    // non-http(s) — `$product->set_props(['product_url' => ''])` (безопасный
    // дефолт), WC_Admin_Notices (при наличии класса) получает кастом-notice.

    public function test_validate_external_product_url_scheme_method_exists(): void
    {
        self::assertMatchesRegularExpression(
            '/public\s+function\s+validate_external_product_url_scheme\s*\(\s*[\w\\\\]*\s*\$\w+\s*\)\s*:\s*void/',
            $this->source,
            '12h-2: должен быть публичный метод validate_external_product_url_scheme(WC_Product $product): void.'
        );
    }

    public function test_hook_registered_on_woocommerce_admin_process_product_object(): void
    {
        self::assertMatchesRegularExpression(
            "/add_action\s*\(\s*['\"]woocommerce_admin_process_product_object['\"]\s*,\s*array\s*\(\s*\\\$this\s*,\s*['\"]validate_external_product_url_scheme['\"]\s*\)/",
            $this->source,
            '12h-2: add_action(woocommerce_admin_process_product_object, [$this, validate_external_product_url_scheme]) '
            . 'должен быть зарегистрирован в конструкторе.'
        );
    }

    public function test_validate_clears_url_for_unsafe_scheme(): void
    {
        $reflection = new ReflectionClass('WC_Affiliate_URL_Params');
        $instance   = $reflection->newInstanceWithoutConstructor();
        $method     = $reflection->getMethod('validate_external_product_url_scheme');

        $product = new class {
            public string $url         = 'intent://shopee.app/product/123';
            public array  $props_set   = array();
            public string $type        = 'external';
            public int    $id          = 42;
            public string $name        = 'Test Product';

            public function get_type(): string { return $this->type; }
            public function get_product_url(): string { return $this->url; }
            public function set_props( array $props ): void {
                $this->props_set = $props;
                if (array_key_exists('product_url', $props)) {
                    $this->url = (string) $props['product_url'];
                }
            }
            public function get_id(): int { return $this->id; }
            public function get_name(): string { return $this->name; }
        };

        $method->invoke($instance, $product);

        self::assertArrayHasKey(
            'product_url',
            $product->props_set,
            '12h-2: для non-http(s) URL метод должен вызвать set_props с ключом product_url.'
        );
        self::assertSame(
            '',
            $product->props_set['product_url'],
            '12h-2: product_url должен быть обнулён (пустая строка) для unsafe-схемы.'
        );
        self::assertSame(
            '',
            $product->url,
            '12h-2: после set_props get_product_url должен вернуть пустую строку.'
        );
    }

    public function test_validate_preserves_safe_url(): void
    {
        $reflection = new ReflectionClass('WC_Affiliate_URL_Params');
        $instance   = $reflection->newInstanceWithoutConstructor();
        $method     = $reflection->getMethod('validate_external_product_url_scheme');

        $product = new class {
            public string $url         = 'https://shop.example.com/p/123';
            public array  $props_set   = array();
            public string $type        = 'external';
            public int    $id          = 42;
            public string $name        = 'Test Product';

            public function get_type(): string { return $this->type; }
            public function get_product_url(): string { return $this->url; }
            public function set_props( array $props ): void {
                $this->props_set = $props;
                if (array_key_exists('product_url', $props)) {
                    $this->url = (string) $props['product_url'];
                }
            }
            public function get_id(): int { return $this->id; }
            public function get_name(): string { return $this->name; }
        };

        $method->invoke($instance, $product);

        self::assertSame(
            array(),
            $product->props_set,
            '12h-2 Regression: для валидного https:// URL set_props НЕ должен вызываться.'
        );
        self::assertSame(
            'https://shop.example.com/p/123',
            $product->url,
            '12h-2 Regression: валидный URL должен остаться нетронутым.'
        );
    }

    public function test_validate_skips_non_external_product_type(): void
    {
        $reflection = new ReflectionClass('WC_Affiliate_URL_Params');
        $instance   = $reflection->newInstanceWithoutConstructor();
        $method     = $reflection->getMethod('validate_external_product_url_scheme');

        // Simple product (не external) — валидация product_url не применяется.
        $product = new class {
            public string $url         = 'intent://something';
            public array  $props_set   = array();
            public string $type        = 'simple';
            public int    $id          = 42;
            public string $name        = 'Test Product';

            public function get_type(): string { return $this->type; }
            public function get_product_url(): string { return $this->url; }
            public function set_props( array $props ): void { $this->props_set = $props; }
            public function get_id(): int { return $this->id; }
            public function get_name(): string { return $this->name; }
        };

        $method->invoke($instance, $product);

        self::assertSame(
            array(),
            $product->props_set,
            '12h-2: для non-external product (simple/variable/etc.) валидация product_url не применяется — '
            . 'это поле есть только у WC_Product_External.'
        );
    }

    public function test_validate_skips_empty_url(): void
    {
        // Пустой product_url (даже для external product) — не ошибка, а валидное состояние
        // "ссылка ещё не заполнена". set_props не дёргаем.
        $reflection = new ReflectionClass('WC_Affiliate_URL_Params');
        $instance   = $reflection->newInstanceWithoutConstructor();
        $method     = $reflection->getMethod('validate_external_product_url_scheme');

        $product = new class {
            public string $url         = '';
            public array  $props_set   = array();
            public string $type        = 'external';
            public int    $id          = 42;
            public string $name        = 'Test Product';

            public function get_type(): string { return $this->type; }
            public function get_product_url(): string { return $this->url; }
            public function set_props( array $props ): void { $this->props_set = $props; }
            public function get_id(): int { return $this->id; }
            public function get_name(): string { return $this->name; }
        };

        $method->invoke($instance, $product);

        self::assertSame(array(), $product->props_set);
    }

    public function test_validate_uses_shared_helper(): void
    {
        // DRY: validate_external_product_url_scheme должен использовать тот же
        // is_safe_http_url helper, а не дублировать scheme-check.
        $body = $this->extract_method('validate_external_product_url_scheme');

        self::assertMatchesRegularExpression(
            '/\$this->is_safe_http_url\s*\(/',
            $body,
            '12h-2 DRY: метод должен использовать $this->is_safe_http_url (single point of truth).'
        );
    }
}
