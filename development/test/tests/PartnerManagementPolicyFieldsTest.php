<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Группа 12i-5 ADR — admin-UI для per-network click session policy.
 *
 * Closes F-10-001 admin slice:
 *  - Форма добавления/редактирования партнёра получает dropdown
 *    click_session_policy (reuse_in_window | always_new | reuse_per_product)
 *    и number-input activation_window_seconds (60..86400, default 1800).
 *  - handle_add_partner / handle_update_partner читают/sanitize/clamp и
 *    пишут в cashback_affiliate_networks.
 *  - Idempotency (request_id) на add/update — пара для client-side retries.
 *  - JS inline-edit рендерит select/number, show human-readable label.
 *
 * Source-grep тесты.
 */
#[Group('security')]
#[Group('group12')]
#[Group('f-10-001')]
#[Group('admin-ui')]
class PartnerManagementPolicyFieldsTest extends TestCase
{
    private string $php_source = '';
    private string $js_source  = '';

    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        $php_path = $plugin_root . '/partner/partner-management.php';
        self::assertFileExists($php_path);
        $this->php_source = (string) file_get_contents($php_path);

        $js_path = $plugin_root . '/assets/js/admin-partner-management.js';
        self::assertFileExists($js_path);
        $this->js_source = (string) file_get_contents($js_path);
    }

    // =====================================================================
    // 1. HTML форма добавления — policy dropdown + window input
    // =====================================================================

    public function test_add_form_has_policy_dropdown(): void
    {
        self::assertMatchesRegularExpression(
            '/<select[^>]+name=["\']click_session_policy["\']/i',
            $this->php_source,
            '12i-5: форма добавления должна содержать <select name="click_session_policy">.'
        );
    }

    public function test_add_form_policy_options_complete(): void
    {
        // 3 варианта policy в dropdown.
        self::assertMatchesRegularExpression(
            '/<option[^>]+value=["\']reuse_in_window["\']/i',
            $this->php_source,
            '12i-5: option value="reuse_in_window" обязательна.'
        );
        self::assertMatchesRegularExpression(
            '/<option[^>]+value=["\']always_new["\']/i',
            $this->php_source
        );
        self::assertMatchesRegularExpression(
            '/<option[^>]+value=["\']reuse_per_product["\']/i',
            $this->php_source
        );
    }

    public function test_add_form_has_window_input(): void
    {
        self::assertMatchesRegularExpression(
            '/<input[^>]+type=["\']number["\'][^>]+name=["\']activation_window_seconds["\']/i',
            $this->php_source,
            '12i-5: форма должна содержать <input type="number" name="activation_window_seconds">.'
        );
    }

    public function test_add_form_window_has_numeric_range(): void
    {
        // min=60, max=86400 на input.
        self::assertMatchesRegularExpression(
            '/name=["\']activation_window_seconds["\'][^>]+min=["\']60["\']/i',
            $this->php_source,
            '12i-5: input должен иметь min="60".'
        );
        self::assertMatchesRegularExpression(
            '/name=["\']activation_window_seconds["\'][^>]+max=["\']86400["\']/i',
            $this->php_source,
            '12i-5: input должен иметь max="86400".'
        );
    }

    // =====================================================================
    // 2. Таблица — колонки policy + window + обновлённый colspan
    // =====================================================================

    public function test_table_has_policy_and_window_columns(): void
    {
        // В thead добавлены ячейки (<th>) для политики и окна активации.
        self::assertMatchesRegularExpression(
            '/<th[^>]*scope=["\']col["\'][^>]*>\s*(?:Политика|Policy|Окно|Активация)/iu',
            $this->php_source,
            '12i-5: thead должен содержать новые <th scope="col"> для policy/window.'
        );
    }

    public function test_table_rows_have_policy_and_window_fields(): void
    {
        // В <tbody> каждая строка — <td data-field="click_session_policy"> и активация.
        self::assertStringContainsString(
            'data-field="click_session_policy"',
            $this->php_source,
            '12i-5: таблица должна рендерить <td data-field="click_session_policy">.'
        );
        self::assertStringContainsString(
            'data-field="activation_window_seconds"',
            $this->php_source,
            '12i-5: таблица должна рендерить <td data-field="activation_window_seconds">.'
        );
    }

    public function test_empty_state_colspan_updated(): void
    {
        // Было colspan="6", после добавления 2 колонок должно стать 8.
        self::assertMatchesRegularExpression(
            '/colspan=["\']8["\']/',
            $this->php_source,
            '12i-5: пустое состояние таблицы должно использовать colspan="8" (6 + 2 новых колонки).'
        );
    }

    // =====================================================================
    // 3. handle_add_partner — читает/sanitize/clamp/сохраняет
    // =====================================================================

    public function test_handle_add_reads_policy(): void
    {
        $body = $this->extract_method('handle_add_partner');
        self::assertStringContainsString(
            "\$_POST['click_session_policy']",
            $body,
            '12i-5: handle_add_partner должен читать click_session_policy из $_POST.'
        );
    }

    public function test_handle_add_reads_window(): void
    {
        $body = $this->extract_method('handle_add_partner');
        self::assertStringContainsString(
            "\$_POST['activation_window_seconds']",
            $body,
            '12i-5: handle_add_partner должен читать activation_window_seconds из $_POST.'
        );
    }

    public function test_handle_add_clamps_policy_to_allowlist(): void
    {
        $body = $this->extract_method('handle_add_partner');
        // allowlist + fallback: в теле метода должны упоминаться 3 валидных значения
        // (в массиве или inline) + in_array() проверка.
        self::assertMatchesRegularExpression(
            '/in_array\s*\(/',
            $body,
            '12i-5: handle_add_partner должен использовать in_array для allowlist policy.'
        );
        self::assertStringContainsString("'reuse_in_window'", $body, '12i-5: "reuse_in_window" в allowlist.');
        self::assertStringContainsString("'always_new'", $body, '12i-5: "always_new" в allowlist.');
        self::assertStringContainsString("'reuse_per_product'", $body, '12i-5: "reuse_per_product" в allowlist.');
    }

    public function test_handle_add_clamps_window_range(): void
    {
        $body = $this->extract_method('handle_add_partner');
        // clamp 60..86400.
        self::assertMatchesRegularExpression(
            '/\b60\b[\s\S]{0,200}\b86400\b|\b86400\b[\s\S]{0,200}\b60\b/',
            $body,
            '12i-5: handle_add_partner должен clamp activation_window_seconds в [60..86400].'
        );
    }

    public function test_handle_add_writes_new_fields_to_db(): void
    {
        $body = $this->extract_method('handle_add_partner');
        self::assertStringContainsString(
            "'click_session_policy'",
            $body,
            '12i-5: $wpdb->insert должен включать ключ click_session_policy.'
        );
        self::assertStringContainsString(
            "'activation_window_seconds'",
            $body,
            '12i-5: $wpdb->insert должен включать ключ activation_window_seconds.'
        );
    }

    // =====================================================================
    // 4. handle_update_partner — симметрично
    // =====================================================================

    public function test_handle_update_reads_policy_and_window(): void
    {
        $body = $this->extract_method('handle_update_partner');
        self::assertStringContainsString(
            "\$_POST['click_session_policy']",
            $body,
            '12i-5: handle_update_partner должен читать click_session_policy.'
        );
        self::assertStringContainsString(
            "\$_POST['activation_window_seconds']",
            $body,
            '12i-5: handle_update_partner должен читать activation_window_seconds.'
        );
    }

    public function test_handle_update_clamps_policy_and_window(): void
    {
        $body = $this->extract_method('handle_update_partner');
        self::assertMatchesRegularExpression(
            '/in_array\s*\(/',
            $body,
            '12i-5: handle_update_partner должен использовать in_array для allowlist policy.'
        );
        self::assertStringContainsString("'reuse_in_window'", $body);
        self::assertStringContainsString("'always_new'", $body);
        self::assertStringContainsString("'reuse_per_product'", $body);
        self::assertMatchesRegularExpression(
            '/\b60\b[\s\S]{0,200}\b86400\b|\b86400\b[\s\S]{0,200}\b60\b/',
            $body,
            '12i-5: handle_update_partner должен clamp window в [60..86400].'
        );
    }

    public function test_handle_update_writes_new_fields(): void
    {
        $body = $this->extract_method('handle_update_partner');
        self::assertStringContainsString(
            "'click_session_policy'",
            $body,
            '12i-5: $wpdb->update должен включать ключ click_session_policy.'
        );
        self::assertStringContainsString(
            "'activation_window_seconds'",
            $body,
            '12i-5: $wpdb->update должен включать ключ activation_window_seconds.'
        );
    }

    // =====================================================================
    // 5. Idempotency в add/update
    // =====================================================================

    public function test_handle_add_uses_idempotency(): void
    {
        $body = $this->extract_method('handle_add_partner');
        self::assertStringContainsString(
            'Cashback_Idempotency::normalize_request_id',
            $body,
            '12i-5: handle_add_partner должен нормализовать request_id (защита от client-retry дублей).'
        );
        self::assertStringContainsString(
            'Cashback_Idempotency::get_stored_result',
            $body,
            '12i-5: handle_add_partner должен проверять get_stored_result.'
        );
        self::assertStringContainsString(
            'Cashback_Idempotency::claim',
            $body,
            '12i-5: handle_add_partner должен делать claim.'
        );
        self::assertStringContainsString(
            'Cashback_Idempotency::store_result',
            $body,
            '12i-5: handle_add_partner должен store_result перед успешным ответом.'
        );
    }

    public function test_handle_update_uses_idempotency(): void
    {
        $body = $this->extract_method('handle_update_partner');
        self::assertStringContainsString(
            'Cashback_Idempotency::normalize_request_id',
            $body,
            '12i-5: handle_update_partner должен нормализовать request_id.'
        );
        self::assertStringContainsString(
            'Cashback_Idempotency::claim',
            $body
        );
        self::assertStringContainsString(
            'Cashback_Idempotency::store_result',
            $body
        );
    }

    // =====================================================================
    // 6. Response включает новые поля
    // =====================================================================

    public function test_handle_update_response_includes_new_fields(): void
    {
        $body = $this->extract_method('handle_update_partner');
        self::assertMatchesRegularExpression(
            "/['\"]click_session_policy['\"]\s*=>/",
            $body,
            '12i-5: wp_send_json_success должен включать click_session_policy в response.'
        );
        self::assertMatchesRegularExpression(
            "/['\"]activation_window_seconds['\"]\s*=>/",
            $body
        );
    }

    // =====================================================================
    // 7. JS admin-partner-management.js
    // =====================================================================

    public function test_js_has_make_request_id_helper(): void
    {
        self::assertMatchesRegularExpression(
            '/function\s+makeRequestId\s*\(\s*\)\s*\{[\s\S]{0,300}randomUUID/i',
            $this->js_source,
            '12i-5: JS должен определять makeRequestId() (UUID v4 через window.crypto.randomUUID).'
        );
    }

    public function test_js_add_form_sends_request_id_and_new_fields(): void
    {
        // В обработчике #add-partner submit — должны отправляться 2 новых поля + request_id.
        self::assertStringContainsString(
            'click_session_policy',
            $this->js_source,
            '12i-5: JS form submit должен отправлять click_session_policy.'
        );
        self::assertStringContainsString(
            'activation_window_seconds',
            $this->js_source,
            '12i-5: JS form submit должен отправлять activation_window_seconds.'
        );
        self::assertMatchesRegularExpression(
            "/request_id['\"]?\s*:\s*makeRequestId/",
            $this->js_source,
            '12i-5: JS POST должен включать request_id: makeRequestId() (может быть в кавычках как JSON key).'
        );
    }

    public function test_js_inline_edit_renders_policy_select(): void
    {
        // Inline-редактирование должно генерировать <select> для policy с 3 options.
        self::assertMatchesRegularExpression(
            "/field\s*===\s*['\"]click_session_policy['\"][\s\S]{0,500}reuse_in_window[\s\S]{0,200}always_new[\s\S]{0,200}reuse_per_product/s",
            $this->js_source,
            '12i-5: inline-edit должен рендерить <select> с 3 options для click_session_policy.'
        );
    }

    public function test_js_inline_edit_renders_window_number_input(): void
    {
        self::assertMatchesRegularExpression(
            "/field\s*===\s*['\"]activation_window_seconds['\"]/",
            $this->js_source,
            '12i-5: inline-edit должен обрабатывать activation_window_seconds field (number input).'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function extract_method( string $name ): string
    {
        $pattern = '/(?:public|private|protected)\s+(?:static\s+)?function\s+' . preg_quote($name, '/')
            . '\s*\([^)]*\)(?:\s*:\s*\??[\w\\\\]+)?\s*\{/';

        if (preg_match($pattern, $this->php_source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            self::fail('Метод ' . $name . '() не найден в partner-management.');
        }

        $start = (int) $m[0][1];
        $brace = strpos($this->php_source, '{', $start);
        if ($brace === false) {
            self::fail('Нет открывающей скобки у ' . $name);
        }

        $depth = 0;
        $len   = strlen($this->php_source);
        for ($i = $brace; $i < $len; $i++) {
            if ($this->php_source[$i] === '{') {
                $depth++;
            } elseif ($this->php_source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($this->php_source, $brace, $i - $brace + 1);
                }
            }
        }
        self::fail('Нет закрывающей скобки у ' . $name);
    }
}
