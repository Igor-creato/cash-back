<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Группа 12i-2 ADR — activate_cashback logic: session dedup + idempotency + row-lock.
 *
 * Closes F-10-001 logic slice:
 *  - client_request_id param в REST route + Cashback_Idempotency claim/store.
 *  - Resolve merchant policy из БД (click_session_policy, activation_window_seconds)
 *    + fail-safe clamps (allowlist policy, window 60..86400).
 *  - TX + SELECT FOR UPDATE на click_sessions.
 *  - Reuse: tap_count++, last_tap_at=NOW(), вернуть canonical_click_id.
 *  - Create: INSERT session + INSERT click_log с is_session_primary=1.
 *  - Always log tap event в click_log с click_session_id + client_request_id.
 *  - Response: additive поля reused + tap_count.
 *
 * Source-grep тесты (поведение проверяется в 12i-4 через integration).
 */
#[Group('security')]
#[Group('group12')]
#[Group('f-10-001')]
#[Group('activate-dedup')]
class ActivateDedupLogicTest extends TestCase
{
    private string $source = '';
    private string $activate_body = '';

    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $path        = $plugin_root . '/includes/class-cashback-rest-api.php';
        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertNotFalse($content);
        $this->source        = $content;
        $this->activate_body = $this->extract_method('activate_cashback');
    }

    // =====================================================================
    // 1. REST route: client_request_id argument
    // =====================================================================

    public function test_rest_route_accepts_client_request_id(): void
    {
        // В register_rest_route для /activate должен быть зарегистрирован
        // опциональный arg client_request_id (UUID строка).
        self::assertMatchesRegularExpression(
            "/register_rest_route[\s\S]{0,200}\/activate[\s\S]{0,800}['\"]client_request_id['\"]\s*=>\s*array/",
            $this->source,
            '12i-2: REST route /activate должен принимать client_request_id (UUID v4/v7) как опциональный arg.'
        );
    }

    // =====================================================================
    // 2. Idempotency: claim + get_stored_result + store_result
    // =====================================================================

    public function test_activate_normalizes_client_request_id(): void
    {
        self::assertStringContainsString(
            'Cashback_Idempotency::normalize_request_id',
            $this->activate_body,
            '12i-2: client_request_id должен нормализоваться через Cashback_Idempotency::normalize_request_id.'
        );
    }

    public function test_activate_checks_stored_result_before_work(): void
    {
        self::assertStringContainsString(
            'Cashback_Idempotency::get_stored_result',
            $this->activate_body,
            '12i-2: перед DB-работой должна быть проверка get_stored_result для идемпотентности ретраев.'
        );
    }

    public function test_activate_claims_idempotency_slot(): void
    {
        self::assertStringContainsString(
            'Cashback_Idempotency::claim',
            $this->activate_body,
            '12i-2: Cashback_Idempotency::claim обязателен для защиты от параллельных retry того же запроса.'
        );
    }

    public function test_activate_uses_activate_scope(): void
    {
        self::assertMatchesRegularExpression(
            "/['\"]activate['\"]/",
            $this->activate_body,
            '12i-2: idempotency scope должен быть "activate" (как указано в плане).'
        );
    }

    public function test_activate_stores_result_before_return(): void
    {
        self::assertStringContainsString(
            'Cashback_Idempotency::store_result',
            $this->activate_body,
            '12i-2: store_result должен записывать ответ до return для retry-replay.'
        );
    }

    // =====================================================================
    // 3. Merchant policy resolution + fail-safe clamps
    // =====================================================================

    public function test_activate_reads_click_session_policy(): void
    {
        self::assertStringContainsString(
            'click_session_policy',
            $this->activate_body,
            '12i-2: activate_cashback должен читать click_session_policy из merchant config.'
        );
    }

    public function test_activate_reads_activation_window_seconds(): void
    {
        self::assertStringContainsString(
            'activation_window_seconds',
            $this->activate_body,
            '12i-2: activate_cashback должен читать activation_window_seconds из merchant config.'
        );
    }

    public function test_activate_policy_has_allowlist_clamp(): void
    {
        // Policy должен проверяться по allowlist, невалидное значение → reuse_in_window fallback.
        self::assertMatchesRegularExpression(
            "/['\"]reuse_in_window['\"][\s\S]{0,100}['\"]always_new['\"]|['\"]always_new['\"][\s\S]{0,100}['\"]reuse_in_window['\"]/",
            $this->activate_body,
            '12i-2: policy должен быть в allowlist [reuse_in_window, always_new, reuse_per_product] — fail-safe для невалидного значения.'
        );
    }

    public function test_activate_window_has_numeric_clamp(): void
    {
        // Window clamp: 60..86400
        self::assertMatchesRegularExpression(
            '/\b60\b[\s\S]{0,200}\b86400\b|\b86400\b[\s\S]{0,200}\b60\b/',
            $this->activate_body,
            '12i-2: activation_window_seconds должен clamp\'иться в диапазон 60..86400 (защита от misconfig).'
        );
    }

    // =====================================================================
    // 4. TX + SELECT FOR UPDATE + session reuse/create
    // =====================================================================

    public function test_activate_uses_transaction(): void
    {
        self::assertMatchesRegularExpression(
            "/START\s+TRANSACTION/i",
            $this->activate_body,
            '12i-2: activate_cashback должен оборачиваться в TX для атомарного session lookup+insert.'
        );
    }

    public function test_activate_selects_session_for_update(): void
    {
        self::assertStringContainsString(
            'cashback_click_sessions',
            $this->activate_body,
            '12i-2: activate_cashback должен обращаться к cashback_click_sessions.'
        );
        self::assertMatchesRegularExpression(
            '/FOR\s+UPDATE/i',
            $this->activate_body,
            '12i-2: SELECT сессии должен использовать FOR UPDATE — защита от race между параллельными /activate.'
        );
        self::assertMatchesRegularExpression(
            '/\bSELECT\b[\s\S]{0,500}\bFOR\s+UPDATE/i',
            $this->activate_body,
            '12i-2: FOR UPDATE должен идти за SELECT в пределах разумного окна (оба в lookup-запросе).'
        );
    }

    public function test_activate_inserts_session_on_new(): void
    {
        // Достаточно что метод (1) ссылается на cashback_click_sessions и
        // (2) содержит INSERT INTO — два сигнала вместе = сценарий new session.
        self::assertStringContainsString(
            'cashback_click_sessions',
            $this->activate_body
        );
        self::assertMatchesRegularExpression(
            '/INSERT\s+INTO\s+%i/i',
            $this->activate_body,
            '12i-2: должен быть INSERT INTO %i (prepared-паттерн) для создания новой сессии.'
        );
        // canonical_click_id пишется в INSERT сессии (VALUES первый параметр).
        self::assertStringContainsString(
            'canonical_click_id',
            $this->activate_body,
            '12i-2: INSERT сессии должен писать canonical_click_id.'
        );
    }

    public function test_activate_increments_tap_count_on_reuse(): void
    {
        self::assertMatchesRegularExpression(
            '/tap_count\s*=\s*tap_count\s*\+\s*1/i',
            $this->activate_body,
            '12i-2: при reuse сессии tap_count должен инкрементиться (tap_count = tap_count + 1).'
        );
    }

    public function test_activate_updates_last_tap_at_on_reuse(): void
    {
        self::assertStringContainsString(
            'last_tap_at',
            $this->activate_body,
            '12i-2: reuse должен обновлять last_tap_at = NOW().'
        );
    }

    public function test_activate_rolls_back_on_error(): void
    {
        self::assertMatchesRegularExpression(
            "/ROLLBACK/i",
            $this->activate_body,
            '12i-2: при ошибке должен быть ROLLBACK (не оставляем TX открытой).'
        );
    }

    public function test_activate_commits_on_success(): void
    {
        self::assertMatchesRegularExpression(
            "/COMMIT/",
            $this->activate_body,
            '12i-2: успешная ветка должна делать COMMIT.'
        );
    }

    // =====================================================================
    // 5. click_log tap event: session_id + client_request_id + is_session_primary
    // =====================================================================

    public function test_click_log_insert_includes_click_session_id(): void
    {
        self::assertStringContainsString(
            'click_session_id',
            $this->activate_body,
            '12i-2: INSERT в click_log должен включать click_session_id (FK на созданную/переиспользованную сессию).'
        );
    }

    public function test_click_log_insert_includes_client_request_id(): void
    {
        // Подсказка: в теле метода должно быть упоминание client_request_id в контексте log_click/INSERT click_log.
        self::assertStringContainsString(
            'client_request_id',
            $this->activate_body,
            '12i-2: tap event должен записываться с client_request_id (для post-mortem-grep по ретраям).'
        );
    }

    public function test_click_log_insert_includes_is_session_primary(): void
    {
        self::assertStringContainsString(
            'is_session_primary',
            $this->activate_body,
            '12i-2: tap event должен записываться с is_session_primary (1 для первого тапа сессии, 0 для повторов).'
        );
    }

    // =====================================================================
    // 6. Response: additive поля reused + tap_count
    // =====================================================================

    public function test_response_includes_reused_field(): void
    {
        self::assertMatchesRegularExpression(
            "/['\"]reused['\"]\s*=>/",
            $this->activate_body,
            '12i-2: response должен содержать поле "reused" (bool — true если сессия переиспользована).'
        );
    }

    public function test_response_includes_tap_count_field(): void
    {
        self::assertMatchesRegularExpression(
            "/['\"]tap_count['\"]\s*=>/",
            $this->activate_body,
            '12i-2: response должен содержать поле "tap_count" (int — общее число тапов в сессии).'
        );
    }

    // =====================================================================
    // 7. Regression: backward-compat contract
    // =====================================================================

    public function test_response_still_has_click_id(): void
    {
        self::assertMatchesRegularExpression(
            "/['\"]click_id['\"]\s*=>/",
            $this->activate_body,
            'Regression: response должен по-прежнему содержать click_id (canonical_click_id, стабильный для CPA/extension).'
        );
    }

    public function test_response_still_has_redirect_url(): void
    {
        self::assertMatchesRegularExpression(
            "/['\"]redirect_url['\"]\s*=>/",
            $this->activate_body,
            'Regression: redirect_url должен остаться.'
        );
    }

    public function test_response_still_has_expires_at(): void
    {
        self::assertMatchesRegularExpression(
            "/['\"]expires_at['\"]\s*=>/",
            $this->activate_body,
            'Regression: expires_at должен остаться.'
        );
    }

    public function test_response_still_has_activation_page_url(): void
    {
        self::assertMatchesRegularExpression(
            "/['\"]activation_page_url['\"]\s*=>/",
            $this->activate_body
        );
    }

    public function test_existing_rate_limit_preserved(): void
    {
        // Regression: Group 7 rate-limit backend должен оставаться (считает тапы, это OK для антифрода).
        self::assertStringContainsString(
            'get_click_rate_status',
            $this->activate_body,
            'Regression: rate-limiting (Group 7) должен сохраняться — он считает ТАПЫ, не сессии.'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function extract_method( string $name ): string
    {
        $pattern = '/(?:public|private|protected)\s+(?:static\s+)?function\s+' . preg_quote($name, '/')
            . '\s*\([^)]*\)(?:\s*:\s*\??[\w\\\\]+)?\s*\{/';

        if (preg_match($pattern, $this->source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            self::fail('Метод ' . $name . '() не найден в rest-api.');
        }

        $start = (int) $m[0][1];
        $brace = strpos($this->source, '{', $start);
        if ($brace === false) {
            self::fail('Нет открывающей скобки у ' . $name);
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
        self::fail('Нет закрывающей скобки у ' . $name);
    }
}
