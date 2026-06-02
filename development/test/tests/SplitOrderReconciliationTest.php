<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Split-order CPA data-loss fix — `(partner, uniq_id)` identity.
 *
 * Admitad sends ONE postback per order position/tariff: a single purchase
 * produces several independent actions that share the SAME click_id but each
 * carry their own unique admitad_id (stored as uniq_id). The cron backstop
 * `do_background_sync()` previously matched API actions to local transactions
 * by click_id FIRST, with click-keyed maps that keep only one row per click_id
 * — so a sibling action would falsely match the first sibling's tx and never
 * be inserted (commission silently lost; real incident aptekiplus.ru
 * 2026-05-15, order ...720 / 13.53 RUB).
 *
 * Methodology mirrors ApiClientSyncAtomicityTest: source-string + brace-balance
 * body extraction (bootstrap does NOT spin a real DB; see
 * [[feedback_structural_test_body_extraction]]). These assertions FAIL against
 * the pre-fix source and PASS once uniq_id is the sole tx-identity key and the
 * click-keyed identity maps are removed.
 *
 * Webhook-receiver (separate Python repo) is covered by py_compile + the
 * staging integration test (plan Section E / D5 decision), not here — the
 * plugin suite must not depend on a sibling repo's filesystem path.
 */
#[Group('split-order')]
final class SplitOrderReconciliationTest extends TestCase
{
    private const API_CLIENT_FILE   = __DIR__ . '/../../../includes/class-cashback-api-client.php';
    private const RECON_ADMIN_FILE  = __DIR__ . '/../../../admin/class-cashback-balance-reconciliation-admin.php';
    private const CLAIMS_ADMIN_FILE = __DIR__ . '/../../../claims/class-claims-admin.php';
    private const ELIGIBILITY_FILE  = __DIR__ . '/../../../claims/class-claims-eligibility.php';
    private const BALANCE_RECON_FILE = __DIR__ . '/../../../includes/class-cashback-balance-reconciliation.php';
    private const SCHEMA_FILE       = __DIR__ . '/../../../mariadb.php';

    private function read_file(string $path): string
    {
        $src = file_get_contents($path);
        $this->assertIsString($src, 'Source must be readable: ' . $path);
        return $src;
    }

    private function read_source(): string
    {
        return $this->read_file(self::API_CLIENT_FILE);
    }

    /**
     * Brace-balance method-body extraction (robust to body growth between
     * iterations — see [[feedback_structural_test_body_extraction]]).
     */
    private function extract_method_body(string $src, string $method_name): string
    {
        $pos = strpos($src, 'function ' . $method_name . '(');
        $this->assertIsInt($pos, $method_name . '() must exist in source.');

        $brace = strpos($src, '{', $pos);
        $this->assertIsInt($brace, 'Opening brace must follow signature of ' . $method_name);

        $depth = 0;
        $len   = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }

        $this->fail('Could not find closing brace for ' . $method_name);
    }

    // ════════════════════════════════════════════════════════════════
    // B1 — cron: uniq_id is the SOLE tx-identity key (no click_id match)
    // ════════════════════════════════════════════════════════════════

    public function test_cron_match_does_not_use_click_id_as_identity(): void
    {
        $body = $this->extract_method_body($this->read_source(), 'do_background_sync');

        // The data-loss source: matching a local/unregistered tx by click_id.
        // click_id is many-actions→one-click; it must NOT key tx identity.
        $this->assertDoesNotMatchRegularExpression(
            '/\$local_map_by_click\s*\[\s*\$api_click_id\s*\]/',
            $body,
            'cron must NOT match cashback_transactions by click_id ' .
            '(split-order siblings share one click_id — identity is (partner, uniq_id)).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$unreg_map_by_click\s*\[\s*\$api_click_id\s*\]/',
            $body,
            'cron must NOT match cashback_unregistered_transactions by click_id.'
        );
    }

    public function test_cron_does_not_build_click_keyed_identity_maps(): void
    {
        $body = $this->extract_method_body($this->read_source(), 'do_background_sync');

        // The overwrite-on-collision builders that lose siblings.
        $this->assertDoesNotMatchRegularExpression(
            '/\$local_map_by_click\s*\[\s*\$row\[\s*[\'"]click_id[\'"]\s*\]\s*\]\s*=/',
            $body,
            'cron must not build a click_id-keyed cashback_transactions map ' .
            '(overwrites split-order siblings — keeps only one row per click_id).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$unreg_map_by_click\s*\[\s*\$row\[\s*[\'"]click_id[\'"]\s*\]\s*\]\s*=/',
            $body,
            'cron must not build a click_id-keyed unregistered map.'
        );
    }

    public function test_cron_match_uses_uniq_id_maps(): void
    {
        $body = $this->extract_method_body($this->read_source(), 'do_background_sync');

        // uniq_id (= Admitad admitad_id/action_id) is the identity key, matching
        // the DB UNIQUE(uniq_id, partner) constraint.
        $this->assertMatchesRegularExpression(
            '/\$local_map_by_uniq\s*\[/',
            $body,
            'cron must match cashback_transactions by uniq_id (per-action identity).'
        );
        $this->assertMatchesRegularExpression(
            '/\$unreg_map_by_uniq\s*\[/',
            $body,
            'cron must match cashback_unregistered_transactions by uniq_id.'
        );
        // The cross-table UNIQUE(uniq_id, partner) guard + insert path stay —
        // after B1 a true sibling falls through to insert_missing_transaction().
        $this->assertStringContainsString(
            'insert_missing_transaction(',
            $body,
            'cron must still INSERT a genuinely-missing sibling action (backstop).'
        );
    }

    public function test_would_match_user_collection_is_uniq_only(): void
    {
        $body = $this->extract_method_body($this->read_source(), 'do_background_sync');

        // $would_match decides whether to collect an action's user_id for the
        // batch existence check (needed for INSERT). It must not consult the
        // click-keyed maps, or a genuine sibling's user is skipped pre-insert.
        $pos = strpos($body, '$would_match');
        $this->assertIsInt($pos, '$would_match existence-precheck must exist in cron.');
        // Examine the assignment expression (up to the terminating semicolon).
        $semi = strpos($body, ';', $pos);
        $this->assertIsInt($semi);
        $would_expr = substr($body, $pos, $semi - $pos);
        $this->assertStringNotContainsString(
            'local_map_by_click',
            $would_expr,
            '$would_match must not consult the removed click-keyed maps.'
        );
        $this->assertStringNotContainsString(
            'unreg_map_by_click',
            $would_expr,
            '$would_match must not consult the removed click-keyed maps.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // B2 — unregistered→registered migration: deterministic user
    // ════════════════════════════════════════════════════════════════

    public function test_migration_real_user_id_is_deterministic(): void
    {
        $src = $this->read_source();

        // With split-order siblings sharing one click_id the JOIN can match
        // many registered tx; an un-aggregated t.user_id under GROUP BY u.id
        // is non-deterministic. Must be MAX(t.user_id) + t.user_id > 0 guard.
        $this->assertMatchesRegularExpression(
            '/MAX\(t\.user_id\)\s+AS real_user_id/',
            $src,
            'migration must select MAX(t.user_id) AS real_user_id (deterministic under GROUP BY).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\bt\.user_id\s+AS real_user_id/',
            $src,
            'migration must NOT select a bare non-aggregated t.user_id (split-order non-determinism).'
        );
        $this->assertMatchesRegularExpression(
            '/AND\s+t\.user_id\s*>\s*0/',
            $src,
            'migration JOIN must keep the t.user_id > 0 guard.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // B3 — audit report maps: uniq_id-only, no click_id identity key
    // ════════════════════════════════════════════════════════════════

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function auditMethodProvider(): iterable
    {
        yield 'validate_user'         => ['validate_user'];
        yield 'validate_unregistered' => ['validate_unregistered'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('auditMethodProvider')]
    public function test_audit_match_is_uniq_id_only(string $method): void
    {
        $body = $this->extract_method_body($this->read_source(), $method);

        $this->assertStringNotContainsString(
            'local_by_click_id',
            $body,
            "{$method}() must not key the audit match map by click_id " .
            '(split-order siblings collapse → false missing_local/mismatched).'
        );
        $this->assertStringContainsString(
            'local_by_uniq_id',
            $body,
            "{$method}() must match audit by uniq_id (per-action identity)."
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('auditMethodProvider')]
    public function test_advcake_window_limited_rows_are_not_missing_api_issues(string $method): void
    {
        $body = $this->extract_method_body($this->read_source(), $method);

        $this->assertStringContainsString(
            '$window_limited_local',
            $body,
            "{$method}() должен выносить локальные строки вне доказуемого окна API в отдельный массив."
        );
        $this->assertStringContainsString(
            "'window_limited_local'",
            $body,
            "{$method}() должен возвращать window_limited_local в JSON-ответе админки."
        );

        $has_issues_pos = strpos($body, '$has_issues');
        $window_pos     = strpos($body, '$window_limited_local');
        $this->assertIsInt($has_issues_pos, "{$method}() должен вычислять общий статус проверки.");
        $this->assertIsInt($window_pos, "{$method}() должен иметь window_limited_local.");

        $has_issues_expr = substr($body, $has_issues_pos, 220);
        $this->assertStringNotContainsString(
            'window_limited_local',
            $has_issues_expr,
            "{$method}() не должен делать status=mismatch только из-за window-limited Advcake строк."
        );
    }

    // ════════════════════════════════════════════════════════════════
    // C1 — admin claim→tx guard: ignores declined siblings
    // ════════════════════════════════════════════════════════════════

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function claimGuardFileProvider(): iterable
    {
        yield 'balance-reconciliation-admin' => [self::RECON_ADMIN_FILE];
        yield 'claims-admin'                 => [self::CLAIMS_ADMIN_FILE];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('claimGuardFileProvider')]
    public function test_claim_tx_guard_filters_active_status(string $file): void
    {
        $src = $this->read_file($file);

        // The pre-flight "tx already exists for this click_id" guard must NOT
        // be the bare click_id check (a declined sibling would falsely block a
        // legit manual cashback). It must filter to active statuses only.
        $this->assertDoesNotMatchRegularExpression(
            '/WHERE user_id = %d AND click_id = %s\s+LIMIT 1/',
            $src,
            'guard must not be the bare (user_id, click_id) LIMIT 1 check.'
        );
        $this->assertMatchesRegularExpression(
            "/click_id = %s\s+AND order_status IN \(\s*'waiting'\s*,\s*'hold'\s*,\s*'completed'\s*,\s*'balance'\s*\)/",
            $src,
            'guard must restrict the existing-tx check to active statuses (declined sibling ignored).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // C2 — claims click-history: existence subquery, no row multiplication
    // ════════════════════════════════════════════════════════════════

    public function test_claims_history_uses_grouped_existence_subquery(): void
    {
        $src = $this->read_file(self::ELIGIBILITY_FILE);

        // A direct LEFT JOIN on the tx table multiplies a click_log row by the
        // number of split-order siblings. History must join a derived table
        // grouped by (click_id, user_id) so each click yields exactly one row.
        $this->assertMatchesRegularExpression(
            '/LEFT JOIN \(\s*SELECT click_id, user_id, MAX\(order_status\) AS cashback_status\s+FROM %i\s+WHERE order_status IN[^)]*\)\s+GROUP BY click_id, user_id\s*\) t/s',
            $src,
            'click-history must LEFT JOIN a (click_id,user_id)-grouped tx existence subquery.'
        );
        $this->assertStringNotContainsString(
            "LEFT JOIN %i t\n                 ON t.click_id = cl.click_id AND t.user_id = cl.user_id\n                 AND t.order_status IN",
            $src,
            'the row-multiplying direct tx LEFT JOIN must be gone.'
        );
        // Existence is now keyed by the subquery's click_id, not t.id.
        $this->assertStringContainsString(
            'CASE WHEN t.click_id IS NOT NULL THEN 1 ELSE 0 END AS has_cashback',
            $src,
            'has_cashback must test subquery existence (t.click_id), not t.id.'
        );
        $this->assertStringContainsString(
            " AND t.click_id IS NULL",
            $src,
            'can_claim=yes filter must use t.click_id IS NULL (subquery existence).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // C3 — balance anti-join: NOT EXISTS, not row-multiplying LEFT JOIN
    // ════════════════════════════════════════════════════════════════

    public function test_stale_approved_claims_uses_not_exists(): void
    {
        $body = $this->extract_method_body(
            $this->read_file(self::BALANCE_RECON_FILE),
            'check_stale_approved_claims'
        );

        $this->assertMatchesRegularExpression(
            '/NOT EXISTS\s*\(\s*SELECT 1 FROM %i t\s+WHERE t\.user_id = c\.user_id AND t\.click_id = c\.click_id\s*\)/s',
            $body,
            'stale-approved-claims must anti-semi-join via NOT EXISTS (split-order LIMIT-safe).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/LEFT JOIN %i t ON t\.user_id = c\.user_id AND t\.click_id = c\.click_id/',
            $body,
            'the row-multiplying LEFT JOIN + t.id IS NULL anti-join must be gone.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // C4 (SAFE invariants) — regression guards for left-as-is callsites
    // ════════════════════════════════════════════════════════════════

    public function test_ledger_accrual_stays_keyed_by_transaction_id(): void
    {
        $src = $this->read_file(self::SCHEMA_FILE);

        // Ledger accrual is the reason split siblings are SAFE for balance:
        // one ledger row per transaction id (idempotency 'accrual_{tx_id}').
        // If this ever changes to a click-scoped key, siblings double/under-count.
        $this->assertMatchesRegularExpression(
            "/'accrual_'\s*\.\s*\\\$row\[\s*'id'\s*\]/",
            $src,
            "ledger accrual idempotency must remain 'accrual_' . <transaction id> " .
            '(per-tx — split-order siblings each accrue independently).'
        );
    }

    public function test_click_log_click_id_uniqueness_invariant(): void
    {
        $src = $this->read_file(self::SCHEMA_FILE);

        // Click→user attribution stays correct for split orders precisely
        // because click_log is UNIQUE on click_id (one owner per click).
        $this->assertMatchesRegularExpression(
            '/UNIQUE KEY `uk_click_id` \(`click_id`\)/',
            $src,
            'cashback_click_log must keep UNIQUE(click_id) — single owner per click.'
        );
        // cashback_transactions must keep click_id as a PLAIN index, never
        // UNIQUE — a UNIQUE(click_id) there would re-introduce the split-order
        // data loss at the DB layer (only one sibling per click could insert).
        $this->assertMatchesRegularExpression(
            '/\bKEY `idx_click_id` \(`click_id`\)/',
            $src,
            'transactions tables must index click_id as a non-UNIQUE KEY (many tx per click).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/UNIQUE KEY `idx_click_id`/',
            $src,
            'idx_click_id must never be promoted to UNIQUE (split orders need many tx per click).'
        );
    }
}
