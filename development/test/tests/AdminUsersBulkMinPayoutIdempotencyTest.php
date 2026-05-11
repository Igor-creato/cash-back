<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для `admin/users-management.php::handle_bulk_update_min_payout` —
 * server-side дедуп request_id (по образцу bulk_update_cashback_rate, Группа 5 ADR, F-34-005).
 *
 * Preview-запросы (read-only COUNT) пропускают guard; apply — full cycle защиты от
 * дубля audit-лога. Журнал rate_history здесь не используется (его whitelist — проценты).
 */
#[Group('idempotency')]
#[Group('group-5')]
final class AdminUsersBulkMinPayoutIdempotencyTest extends TestCase
{
    private const HANDLER_FILE = __DIR__ . '/../../../admin/users-management.php';
    private const JS_FILE      = __DIR__ . '/../../../assets/js/admin-users-management.js';

    /**
     * Tokenizer-based brace-balance extractor — feedback_structural_test_body_extraction.
     */
    private function method_body(): string
    {
        $src = file_get_contents(self::HANDLER_FILE);
        $this->assertIsString($src);

        $tokens = token_get_all($src);
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $name = null;
            $j    = $i + 1;
            for (; $j < $count; $j++) {
                if (is_array($tokens[$j])) {
                    if ($tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }
                    if ($tokens[$j][0] === T_STRING) {
                        $name = $tokens[$j][1];
                    }
                    break;
                }
            }
            if ($name !== 'handle_bulk_update_min_payout') {
                continue;
            }

            $depth   = 0;
            $body    = '';
            $started = false;
            for ($k = $j + 1; $k < $count; $k++) {
                $t    = $tokens[$k];
                $text = is_array($t) ? $t[1] : $t;
                if (!$started) {
                    if ($text === '{') {
                        $started = true;
                        $depth   = 1;
                        $body   .= $text;
                    }
                    continue;
                }
                $body .= $text;
                if ($text === '{') {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $body;
                    }
                }
            }
            $this->fail('Closing brace of handle_bulk_update_min_payout() not found');
        }
        $this->fail('handle_bulk_update_min_payout() not found');
    }

    public function test_handler_extracts_request_id_via_helper(): void
    {
        $body = $this->method_body();
        $this->assertStringContainsString('Cashback_Idempotency::normalize_request_id', $body);
    }

    public function test_handler_skips_claim_for_preview(): void
    {
        $body = $this->method_body();

        $this->assertMatchesRegularExpression(
            "/\\\$preview_raw\s*=\s*!empty\(\s*\\\$_POST\[['\"]preview['\"]\]\s*\)/",
            $body
        );
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*!\\\$preview_raw\s*&&\s*isset\s*\(\s*\\\$_POST\[['\"]request_id['\"]\]/",
            $body
        );
    }

    public function test_handler_claims_before_transaction(): void
    {
        $body = $this->method_body();

        $claim_pos = strpos($body, 'Cashback_Idempotency::claim');
        $tx_pos    = strpos($body, "\$wpdb->query('START TRANSACTION'");

        $this->assertNotFalse($claim_pos);
        $this->assertNotFalse($tx_pos);
        $this->assertLessThan($tx_pos, $claim_pos);
    }

    public function test_handler_uses_dedicated_scope(): void
    {
        $this->assertStringContainsString("'admin_users_bulk_min_payout'", $this->method_body());
    }

    public function test_handler_stores_result_before_success(): void
    {
        $body = $this->method_body();

        $store_pos   = strrpos($body, 'Cashback_Idempotency::store_result');
        $success_pos = strrpos($body, 'wp_send_json_success');

        $this->assertNotFalse($store_pos);
        $this->assertLessThan($success_pos, $store_pos);
    }

    public function test_handler_releases_claim_on_all_rollbacks(): void
    {
        $body = $this->method_body();

        // Bad params, invalid new_amount, invalid old_amount, count=0, DB error, catch → 6+.
        $forget_count = substr_count($body, 'Cashback_Idempotency::forget');
        $this->assertGreaterThanOrEqual(6, $forget_count);
    }

    public function test_handler_returns_409_on_concurrent_claim(): void
    {
        $this->assertMatchesRegularExpression(
            "/Cashback_Idempotency::claim[\s\S]{0,800}?'in_progress'[\s\S]{0,300}?\),\s*409\)/",
            $this->method_body()
        );
    }

    public function test_handler_targets_min_payout_amount_column(): void
    {
        $body = $this->method_body();

        // SELECT COUNT и UPDATE — оба должны бить по колонке min_payout_amount.
        $this->assertMatchesRegularExpression(
            "/SELECT COUNT\(\*\) FROM %i WHERE min_payout_amount/",
            $body
        );
        $this->assertMatchesRegularExpression(
            "/UPDATE %i SET min_payout_amount\s*=\s*%s/",
            $body
        );
    }

    public function test_handler_validates_lower_bound_one(): void
    {
        // Нижняя граница: < 1 должно отбиваться (bccomp(...,'1', 2) < 0).
        $this->assertMatchesRegularExpression(
            "/bccomp\s*\(\s*\\\$new_amount\s*,\s*['\"]1['\"]\s*,\s*2\s*\)\s*<\s*0/",
            $this->method_body()
        );
    }

    public function test_handler_validates_upper_bound_100000(): void
    {
        // Верхняя граница: > 100000 должно отбиваться.
        $this->assertMatchesRegularExpression(
            "/bccomp\s*\(\s*\\\$new_amount\s*,\s*['\"]100000['\"]\s*,\s*2\s*\)\s*>\s*0/",
            $this->method_body()
        );
    }

    public function test_handler_writes_audit_log_with_dedicated_action(): void
    {
        $body = $this->method_body();

        $this->assertStringContainsString('Cashback_Encryption::write_audit_log', $body);
        $this->assertStringContainsString("'bulk_min_payout_update'", $body);
    }

    public function test_handler_does_not_log_to_rate_history(): void
    {
        // Журнал процентных ставок не должен трогаться для min_payout (рубли, не %).
        $this->assertStringNotContainsString('Cashback_Rate_History_Admin', $this->method_body());
    }

    public function test_js_sends_request_id_for_apply_branch(): void
    {
        $js = file_get_contents(self::JS_FILE);
        $this->assertIsString($js);

        // В файле два call'а с этим action: первый — preview (без request_id),
        // второй — apply (с request_id). Проверяем наличие apply-ветки с request_id.
        $this->assertMatchesRegularExpression(
            "/action:\s*'bulk_update_min_payout'[\s\S]{0,400}request_id:\s*makeRequestId\(\)/",
            $js
        );
    }

    public function test_handler_registers_ajax_action(): void
    {
        $src = file_get_contents(self::HANDLER_FILE);
        $this->assertIsString($src);
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*'wp_ajax_bulk_update_min_payout'\s*,/",
            $src
        );
    }

    public function test_handler_uses_dedicated_nonce_action(): void
    {
        $this->assertStringContainsString("'bulk_update_min_payout_nonce'", $this->method_body());
    }
}
