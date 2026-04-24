<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source-grep тесты F-2-001 hardening на wc-affiliate-url-params.php + REST:
 *   - handle_click_redirect делегирует в Cashback_Click_Session_Service (п.1).
 *   - handle_activation_page биндит lookup по user_id (п.2 — activation IDOR protection).
 *   - handle_click_redirect и REST activate_cashback добавляют HMAC `t` в activation URL (п.3).
 *   - catch fallback в handle_click_redirect идёт на home_url(), а не на unsafe product URL (п.6).
 *   - Cookie cb_activation содержит поле `sig` (п.7 — non-breaking HMAC).
 *   - Dead helpers удалены (log_click_to_db, build_final_affiliate_url, get_click_rate_status).
 *
 * Behavioural тесты подписи HMAC — в ActivationTokenHmacTest.
 */
#[Group('security')]
#[Group('f-2-001')]
#[Group('activation-binding')]
final class ActivationUserBindingTest extends TestCase
{
    private string $wc_source                 = '';
    private string $handle_click_redirect     = '';
    private string $handle_activation_page    = '';
    private string $rest_source               = '';
    private string $activate_cashback         = '';

    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        $wc_path = $plugin_root . '/wc-affiliate-url-params.php';
        self::assertFileExists($wc_path);
        $this->wc_source              = (string) file_get_contents($wc_path);
        $this->handle_click_redirect  = $this->extract_method($this->wc_source, 'handle_click_redirect');
        $this->handle_activation_page = $this->extract_method($this->wc_source, 'handle_activation_page');

        $rest_path = $plugin_root . '/includes/class-cashback-rest-api.php';
        self::assertFileExists($rest_path);
        $this->rest_source       = (string) file_get_contents($rest_path);
        $this->activate_cashback = $this->extract_method($this->rest_source, 'activate_cashback');
    }

    // =====================================================================
    // 1. Legacy dedup — handle_click_redirect делегирует в сервис
    // =====================================================================

    public function test_handle_click_redirect_calls_session_service(): void
    {
        self::assertStringContainsString(
            'Cashback_Click_Session_Service::activate',
            $this->handle_click_redirect,
            'П.1: handle_click_redirect должен делегировать dedup/session работу в shared сервис, а не делать сам UUID+INSERT.'
        );
    }

    public function test_handle_click_redirect_passes_force_spam_hint(): void
    {
        self::assertStringContainsString(
            'force_spam',
            $this->handle_click_redirect,
            'Bot detection (is_bot_user_agent) должен пробрасываться в сервис как force_spam hint.'
        );
    }

    public function test_legacy_log_click_to_db_is_removed(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/function\s+log_click_to_db\s*\(/',
            $this->wc_source,
            'П.1: log_click_to_db должен быть удалён — сервис теперь единственный источник INSERT в click_log.'
        );
    }

    public function test_legacy_rate_limit_helper_is_removed(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/function\s+get_click_rate_status\s*\(/',
            $this->wc_source,
            'П.1: get_click_rate_status должен быть удалён — rate-limit теперь внутри сервиса.'
        );
    }

    public function test_legacy_build_affiliate_url_is_removed(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/function\s+build_final_affiliate_url\s*\(/',
            $this->wc_source,
            'П.1: build_final_affiliate_url должен быть удалён — affiliate URL строится в сервисе.'
        );
    }

    // =====================================================================
    // 2. Activation page binding — user_id WHERE filter
    // =====================================================================

    public function test_activation_page_reads_current_user_id(): void
    {
        self::assertStringContainsString(
            'get_current_user_id',
            $this->handle_activation_page,
            'П.2: handle_activation_page должен читать user_id текущего пользователя для биндинга.'
        );
    }

    public function test_activation_page_sessions_lookup_filters_by_user_id(): void
    {
        // AND user_id = %d должен появиться в SELECT по canonical_click_id.
        self::assertMatchesRegularExpression(
            '/canonical_click_id\s*=\s*%s[\s\S]{0,200}AND\s+user_id\s*=\s*%d|AND\s+user_id\s*=\s*%d[\s\S]{0,200}canonical_click_id\s*=\s*%s/i',
            $this->handle_activation_page,
            'П.2: SELECT из cashback_click_sessions должен содержать AND user_id = %d — защита от reuse чужого click_id.'
        );
    }

    public function test_activation_page_guest_branch_filters_user_id_zero(): void
    {
        // Для гостей тоже user_id=0 фильтр, иначе гость может подать click_id чужой сессии logged-in пользователя.
        self::assertMatchesRegularExpression(
            "/canonical_click_id\s*=\s*%s[\s\S]{0,200}user_id\s*=\s*0|user_id\s*=\s*0[\s\S]{0,200}canonical_click_id\s*=\s*%s/i",
            $this->handle_activation_page,
            'П.2: guest-ветка также должна ограничивать lookup по user_id = 0 — не возвращаем чужие залогиненные сессии.'
        );
    }

    public function test_activation_page_click_log_fallback_removed(): void
    {
        self::assertStringNotContainsString(
            'cashback_click_log',
            $this->handle_activation_page,
            'Migration 12i-3 завершена — fallback на legacy click_log удалён из activation page.'
        );
    }

    // =====================================================================
    // 3. HMAC token — sign in both producers, verify in consumer
    // =====================================================================

    public function test_legacy_builds_activation_url_with_token(): void
    {
        self::assertStringContainsString(
            'sign_activation_token',
            $this->handle_click_redirect,
            'П.3: handle_click_redirect должен подписывать activation URL через sign_activation_token.'
        );
        self::assertMatchesRegularExpression(
            "/['\"]t['\"]\s*=>/",
            $this->handle_click_redirect,
            'П.3: activation URL должен содержать query-параметр t=<token>.'
        );
    }

    public function test_rest_builds_activation_url_with_token(): void
    {
        // REST тоже должен подписывать activation URL — иначе URLs от /activate и от legacy неразличимы.
        self::assertStringContainsString(
            'sign_activation_token',
            $this->activate_cashback,
            'П.3: REST activate_cashback должен добавлять HMAC token в activation_page_url.'
        );
        self::assertMatchesRegularExpression(
            "/['\"]t['\"]\s*=>/",
            $this->activate_cashback
        );
    }

    public function test_activation_page_verifies_token(): void
    {
        self::assertStringContainsString(
            'verify_activation_token',
            $this->handle_activation_page,
            'П.3: handle_activation_page должен проверять HMAC token до lookup\'а в БД.'
        );
    }

    // =====================================================================
    // 6. Safe catch fallback — home_url, не product URL
    // =====================================================================

    public function test_handle_click_redirect_catch_fallback_is_home_url(): void
    {
        // В catch-блоке не должно быть вызова $product->get_product_url() как fallback.
        // F-2-001 п.6: unsafe scheme из _product_url мог бы проскочить через catch.
        $catch_block_pattern = '/catch\s*\([\s\S]{0,50}\\\\Throwable[\s\S]{0,3000}?\}/';
        if (preg_match($catch_block_pattern, $this->handle_click_redirect, $m) === 1) {
            self::assertStringNotContainsString(
                '->get_product_url()',
                $m[0],
                'П.6: catch-fallback не должен редиректить на $product->get_product_url() без scheme-check.'
            );
        } else {
            self::fail('Не удалось найти catch(Throwable) блок в handle_click_redirect для проверки.');
        }
    }

    // =====================================================================
    // 7. Cookie signature — non-breaking HMAC
    // =====================================================================

    public function test_cookie_payload_signed(): void
    {
        self::assertStringContainsString(
            'sign_cookie_payload',
            $this->handle_click_redirect,
            'П.7: cookie cb_activation должен содержать подпись sig через sign_cookie_payload.'
        );
        self::assertMatchesRegularExpression(
            "/['\"]sig['\"]\s*=>/",
            $this->handle_click_redirect,
            'П.7: в JSON cookie должно быть поле sig (additive, non-breaking для расширения).'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function extract_method( string $source, string $name ): string
    {
        $pattern = '/(?:public|private|protected)\s+(?:static\s+)?function\s+' . preg_quote($name, '/')
            . '\s*\([^)]*\)(?:\s*:\s*\??[\w\\\\]+)?\s*\{/';

        if (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            self::fail('Метод ' . $name . '() не найден.');
        }

        $start = (int) $m[0][1];
        $brace = strpos($source, '{', $start);
        if ($brace === false) {
            self::fail('Нет открывающей скобки у ' . $name);
        }

        $depth = 0;
        $len   = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }
        self::fail('Нет закрывающей скобки у ' . $name);
    }
}
