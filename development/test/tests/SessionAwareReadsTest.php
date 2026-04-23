<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Группа 12i-3 ADR — session-aware reads в /session-status + handle_activation_page.
 *
 * Closes F-10-001 reads slice:
 *  - Primary lookup в cashback_click_sessions по canonical_click_id с status='active' + expires_at > NOW().
 *  - Fallback на cashback_click_log для legacy rows (click_session_id IS NULL).
 *  - Scheme check (12h-1) сохраняется.
 *
 * Source-grep тесты. Полный behavioural integration — в 12i-4.
 */
#[Group('security')]
#[Group('group12')]
#[Group('f-10-001')]
#[Group('session-reads')]
class SessionAwareReadsTest extends TestCase
{
    private string $rest_api_source         = '';
    private string $wc_affiliate_source     = '';
    private string $get_session_status_body = '';
    private string $handle_activation_body  = '';

    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        $rest_path = $plugin_root . '/includes/class-cashback-rest-api.php';
        self::assertFileExists($rest_path);
        $this->rest_api_source         = (string) file_get_contents($rest_path);
        $this->get_session_status_body = $this->extract_method($this->rest_api_source, 'get_session_status');

        $wc_path = $plugin_root . '/wc-affiliate-url-params.php';
        self::assertFileExists($wc_path);
        $this->wc_affiliate_source    = (string) file_get_contents($wc_path);
        $this->handle_activation_body = $this->extract_method($this->wc_affiliate_source, 'handle_activation_page');
    }

    // =====================================================================
    // 1. /session-status primary lookup в cashback_click_sessions
    // =====================================================================

    public function test_session_status_queries_click_sessions_table(): void
    {
        self::assertStringContainsString(
            'cashback_click_sessions',
            $this->get_session_status_body,
            '12i-3: get_session_status должен primary-искать в cashback_click_sessions — там canonical_click_id + status.'
        );
    }

    public function test_session_status_uses_canonical_click_id(): void
    {
        self::assertStringContainsString(
            'canonical_click_id',
            $this->get_session_status_body,
            '12i-3: get_session_status должен выбирать canonical_click_id (клиентский contract сохраняется).'
        );
    }

    public function test_session_status_filters_active_status(): void
    {
        self::assertMatchesRegularExpression(
            "/status\s*=\s*'active'|status\s*=\s*%s[\s\S]{0,80}'active'/i",
            $this->get_session_status_body,
            '12i-3: session lookup должен фильтровать status = active — не показываем expired/converted/invalidated.'
        );
    }

    public function test_session_status_filters_expires_at(): void
    {
        self::assertMatchesRegularExpression(
            '/expires_at\s*>\s*NOW|expires_at\s*>\s*%s/i',
            $this->get_session_status_body,
            '12i-3: session lookup должен фильтровать expires_at > NOW (или threshold) — не возвращаем истёкшие.'
        );
    }

    public function test_session_status_keeps_click_log_fallback(): void
    {
        self::assertStringContainsString(
            'cashback_click_log',
            $this->get_session_status_body,
            '12i-3: click_log fallback должен остаться для legacy rows (session_id IS NULL).'
        );
    }

    // =====================================================================
    // 2. handle_activation_page primary lookup в cashback_click_sessions
    // =====================================================================

    public function test_activation_page_queries_click_sessions_table(): void
    {
        self::assertStringContainsString(
            'cashback_click_sessions',
            $this->handle_activation_body,
            '12i-3: handle_activation_page должен primary-искать affiliate_url в cashback_click_sessions.'
        );
    }

    public function test_activation_page_uses_canonical_click_id(): void
    {
        self::assertStringContainsString(
            'canonical_click_id',
            $this->handle_activation_body,
            '12i-3: handle_activation_page должен искать по canonical_click_id (== click_id из URL query).'
        );
    }

    public function test_activation_page_filters_active_and_expires(): void
    {
        self::assertMatchesRegularExpression(
            "/status\s*=\s*'active'|status\s*=\s*%s[\s\S]{0,80}'active'/i",
            $this->handle_activation_body,
            '12i-3: activation_page должен фильтровать status=active.'
        );
        self::assertMatchesRegularExpression(
            '/expires_at\s*>\s*NOW/i',
            $this->handle_activation_body,
            '12i-3: activation_page должен фильтровать expires_at > NOW.'
        );
    }

    public function test_activation_page_keeps_click_log_fallback(): void
    {
        self::assertStringContainsString(
            'cashback_click_log',
            $this->handle_activation_body,
            '12i-3: click_log fallback должен остаться — legacy rows до 12i-1 migration.'
        );
    }

    // =====================================================================
    // 3. Regression: scheme check (12h-1) preserved
    // =====================================================================

    public function test_activation_page_keeps_scheme_check(): void
    {
        self::assertMatchesRegularExpression(
            '/\$this->is_safe_http_url\s*\(/',
            $this->handle_activation_body,
            'Regression 12h-1: is_safe_http_url на affiliate_url перед wp_redirect должен остаться.'
        );
    }

    public function test_activation_page_still_redirects_to_home_on_empty(): void
    {
        self::assertMatchesRegularExpression(
            '/wp_redirect\s*\(\s*home_url\s*\(\s*\)\s*,\s*302\s*\)/',
            $this->handle_activation_body,
            'Regression: wp_redirect(home_url(), 302) на empty/invalid должен остаться.'
        );
    }

    // =====================================================================
    // 4. Regression: /session-status contract
    // =====================================================================

    public function test_session_status_response_contract_preserved(): void
    {
        // Ответ должен по-прежнему содержать 'activated', 'activated_at', 'expires_at'.
        self::assertMatchesRegularExpression(
            "/['\"]activated['\"]\s*=>/",
            $this->get_session_status_body,
            'Regression: response содержит поле activated.'
        );
        self::assertMatchesRegularExpression(
            "/['\"]expires_at['\"]\s*=>/",
            $this->get_session_status_body,
            'Regression: response содержит поле expires_at.'
        );
    }

    public function test_session_status_click_id_validation_preserved(): void
    {
        // 32 hex validation на входной click_id.
        self::assertMatchesRegularExpression(
            '/ctype_xdigit\s*\(\s*\$click_id\s*\)[\s\S]{0,80}strlen\s*\(\s*\$click_id\s*\)\s*===\s*32|strlen\s*\(\s*\$click_id\s*\)\s*===\s*32[\s\S]{0,80}ctype_xdigit\s*\(\s*\$click_id\s*\)/',
            $this->get_session_status_body,
            'Regression: 32-hex валидация входного click_id должна остаться.'
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
