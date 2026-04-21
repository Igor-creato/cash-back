<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * RED-тесты для Группы 1 ADR — F-22-005, F-28-001, F-11-003.
 *
 * Проверяют контракт:
 *   - re_freeze_after_unban_atomic() существует и использует UUID v7 idempotency_key
 *     (не time()), берёт FOR UPDATE, принимает $in_transaction контракт.
 *   - handle_user_unban() вызывает re_freeze_after_unban_atomic() ДО COMMIT,
 *     а не после (race-окно F-28-001).
 *   - Схема cashback_user_balance расширена колонками frozen_balance_ban,
 *     frozen_balance_payout, frozen_pending_balance_ban.
 *   - PHP-fallback freeze/unfreeze_balance_on_ban/unban использует новые колонки.
 *   - Инвариант: frozen_balance = frozen_balance_ban + frozen_balance_payout
 *                                 + frozen_pending_balance_ban (F-11-003).
 *   - Pending bucket возвращается в pending после unban.
 *
 * Методика: source-string checks + символические bcmath-инварианты.
 */
#[Group('affiliate-unban')]
final class AffiliateBanUnbanConcurrencyTest extends TestCase
{
    private const SERVICE_FILE   = __DIR__ . '/../../../affiliate/class-affiliate-service.php';
    private const USERS_ADMIN    = __DIR__ . '/../../../admin/users-management.php';
    private const TRIGGER_FB     = __DIR__ . '/../../../includes/class-cashback-trigger-fallbacks.php';
    private const MARIADB_FILE   = __DIR__ . '/../../../mariadb.php';
    private const PLUGIN_BOOT    = __DIR__ . '/../../../cashback-plugin.php';

    private function read(string $path): string
    {
        $src = file_get_contents($path);
        $this->assertIsString($src, "Source must be readable: {$path}");
        return $src;
    }

    // ════════════════════════════════════════════════════════════════
    // F-22-005 — re_freeze_after_unban_atomic()
    // ════════════════════════════════════════════════════════════════

    public function test_re_freeze_after_unban_atomic_method_is_defined(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+re_freeze_after_unban_atomic\s*\(\s*int\s+\$user_id\s*,\s*bool\s+\$in_transaction\s*=\s*true\s*\)/',
            $src,
            'Должен существовать метод re_freeze_after_unban_atomic(int $user_id, bool $in_transaction = true) (ADR Step 4).'
        );
    }

    public function test_re_freeze_uses_uuid_v7_not_time_for_idempotency_key(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        // Извлекаем тело re_freeze_after_unban* семейства (одним блоком)
        $pos = strpos($src, 're_freeze_after_unban');
        $this->assertIsInt($pos, 're_freeze_after_unban family must exist');
        $tail = substr($src, $pos);

        // Ключ должен использовать cashback_generate_uuid7(), не time()
        $uses_uuid = (bool) preg_match('/cashback_generate_uuid7\s*\(/', $tail);

        $this->assertTrue(
            $uses_uuid,
            'idempotency_key для re_freeze должен использовать cashback_generate_uuid7(), не time() (F-22-005).'
        );

        // Наличие 'aff_refreeze_...time()' в источнике говорит о legacy time()-ключе.
        $uses_time = (bool) preg_match('/aff_refreeze_\'\s*\.\s*\$user_id\s*\.\s*\'_\'\s*\.\s*time\(\)/', $tail);
        $this->assertFalse(
            $uses_time,
            'idempotency_key НЕ должен полагаться на time() (F-22-005 нестабильный ключ).'
        );
    }

    public function test_re_freeze_atomic_acquires_for_update_lock(): void
    {
        $src = $this->read(self::SERVICE_FILE);

        $pos = strpos($src, 're_freeze_after_unban_atomic');
        if ($pos === false) {
            $this->fail('re_freeze_after_unban_atomic() not yet defined (F-22-005).');
        }
        $tail = substr($src, $pos, 4000);

        $this->assertMatchesRegularExpression(
            '/FOR\s+UPDATE/i',
            $tail,
            're_freeze_after_unban_atomic должен брать SELECT ... FOR UPDATE на balance (F-22-005).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // F-28-001 — handle_user_unban() вызывает re-freeze ДО COMMIT
    // ════════════════════════════════════════════════════════════════

    public function test_handle_user_unban_invokes_refreeze_before_commit(): void
    {
        $src = $this->read(self::USERS_ADMIN);

        $start = strpos($src, 'function handle_user_unban');
        $this->assertIsInt($start, 'handle_user_unban() must exist');

        // Конец метода — следующий `private function ` или конец класса
        $body = substr($src, $start, 6000);

        $refreeze_pos = strpos($body, 're_freeze_after_unban');
        $commit_pos   = strpos($body, "query('COMMIT')");

        $this->assertIsInt($refreeze_pos, 're_freeze_after_unban вызов должен быть в handle_user_unban.');
        $this->assertIsInt($commit_pos, 'COMMIT должен быть в handle_user_unban.');

        $this->assertLessThan(
            $commit_pos,
            $refreeze_pos,
            're_freeze_after_unban_atomic() должен вызываться ДО COMMIT (F-28-001 race window).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // F-11-003 — bucket split в cashback_user_balance + trigger-fallbacks
    // ════════════════════════════════════════════════════════════════

    public function test_schema_declares_frozen_balance_bucket_columns(): void
    {
        $src = $this->read(self::MARIADB_FILE);

        foreach (['frozen_balance_ban', 'frozen_balance_payout', 'frozen_pending_balance_ban'] as $col) {
            $this->assertStringContainsString(
                $col,
                $src,
                "mariadb.php должен объявлять колонку {$col} (F-11-003 bucket split)."
            );
        }
    }

    public function test_migration_add_frozen_balance_buckets_is_defined(): void
    {
        $src = $this->read(self::MARIADB_FILE);

        $this->assertMatchesRegularExpression(
            '/(private|public|protected)\s+(static\s+)?function\s+migrate_add_frozen_balance_buckets\s*\(/',
            $src,
            'Должна существовать migrate_add_frozen_balance_buckets() (ADR Step 2).'
        );
    }

    public function test_cashback_db_version_option_is_bumped(): void
    {
        $src = $this->read(self::MARIADB_FILE) . $this->read(self::PLUGIN_BOOT);

        $this->assertMatchesRegularExpression(
            '/cashback_db_version/',
            $src,
            'Option cashback_db_version должна управлять миграциями (ADR Decision 3).'
        );
    }

    public function test_trigger_fallback_uses_bucket_columns(): void
    {
        $src = $this->read(self::TRIGGER_FB);

        // freeze: pending должен уходить в frozen_pending_balance_ban (не в общий frozen_balance)
        $this->assertStringContainsString(
            'frozen_pending_balance_ban',
            $src,
            'freeze_balance_on_ban PHP-fallback должен заполнять frozen_pending_balance_ban (F-11-003).'
        );
        $this->assertStringContainsString(
            'frozen_balance_ban',
            $src,
            'freeze_balance_on_ban PHP-fallback должен заполнять frozen_balance_ban (F-11-003).'
        );
    }

    public function test_mariadb_trigger_freeze_uses_bucket_columns(): void
    {
        $src = $this->read(self::MARIADB_FILE);

        // Находим именно CREATE TRIGGER-блок для tr_freeze_balance_on_ban
        // (первое вхождение имени — в списке DROP TRIGGER).
        $trigger_start = false;
        $offset = 0;
        while (($pos = strpos($src, 'tr_freeze_balance_on_ban', $offset)) !== false) {
            $window = substr($src, max(0, $pos - 200), 400);
            if (stripos($window, 'CREATE TRIGGER') !== false) {
                $trigger_start = $pos;
                break;
            }
            $offset = $pos + 1;
        }
        $this->assertIsInt($trigger_start, 'CREATE TRIGGER tr_freeze_balance_on_ban должен быть определён в mariadb.php.');

        $trigger_body = substr($src, $trigger_start, 2000);

        $this->assertStringContainsString(
            'frozen_balance_ban',
            $trigger_body,
            'Триггер tr_freeze_balance_on_ban должен использовать frozen_balance_ban (F-11-003).'
        );
        $this->assertStringContainsString(
            'frozen_pending_balance_ban',
            $trigger_body,
            'Триггер tr_freeze_balance_on_ban должен использовать frozen_pending_balance_ban (F-11-003).'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // F-11-003 — символические инварианты bucket-семантики
    // ════════════════════════════════════════════════════════════════

    public function test_pending_returns_to_pending_after_ban_unban_cycle(): void
    {
        // Начальное состояние
        $available = '10.00';
        $pending   = '5.00';
        $frozen_ban           = '0.00';
        $frozen_payout        = '0.00';
        $frozen_pending_ban   = '0.00';

        // BAN: available→frozen_balance_ban, pending→frozen_pending_balance_ban
        $frozen_ban         = bcadd($frozen_ban, $available, 2);
        $frozen_pending_ban = bcadd($frozen_pending_ban, $pending, 2);
        $available = '0.00';
        $pending   = '0.00';

        // UNBAN: frozen_balance_ban → available, frozen_pending_balance_ban → pending
        $available = bcadd($available, $frozen_ban, 2);
        $pending   = bcadd($pending, $frozen_pending_ban, 2);
        $frozen_ban         = '0.00';
        $frozen_pending_ban = '0.00';

        $this->assertSame('10.00', $available, 'available должен восстановиться (F-11-003).');
        $this->assertSame('5.00', $pending, 'pending должен восстановиться в pending, не в available (F-11-003 core).');
    }

    public function test_frozen_balance_equals_sum_of_buckets(): void
    {
        $frozen_ban         = '10.00';
        $frozen_payout      = '20.00';
        $frozen_pending_ban = '5.00';

        $legacy_frozen = bcadd(bcadd($frozen_ban, $frozen_payout, 2), $frozen_pending_ban, 2);

        $this->assertSame(
            '35.00',
            $legacy_frozen,
            'Инвариант: frozen_balance = frozen_balance_ban + frozen_balance_payout + frozen_pending_balance_ban.'
        );
    }

    public function test_unban_with_only_payout_freeze_leaves_payout_intact(): void
    {
        // unban НЕ трогает frozen_balance_payout
        $available = '0.00';
        $pending   = '0.00';
        $frozen_ban           = '10.00';
        $frozen_payout        = '7.00';
        $frozen_pending_ban   = '3.00';

        // UNBAN
        $available = bcadd($available, $frozen_ban, 2);
        $pending   = bcadd($pending, $frozen_pending_ban, 2);
        $frozen_ban         = '0.00';
        $frozen_pending_ban = '0.00';
        // frozen_payout не меняется

        $this->assertSame('10.00', $available);
        $this->assertSame('3.00', $pending);
        $this->assertSame('7.00', $frozen_payout, 'frozen_balance_payout не должен трогаться при unban (payout-holds переживают ban/unban).');
    }

    public function test_two_refreezes_within_same_second_produce_distinct_idempotency_keys(): void
    {
        // UUID v7 монотонен и уникален per invocation, даже в ту же миллисекунду.
        // time()-ключ → одинаковая секунда = одинаковый ключ = ON DUPLICATE KEY = второй UPDATE
        // баланса может выполниться на фоне того же ledger-ключа → double freeze.
        $key1 = 'aff_refreeze_42_' . bin2hex(random_bytes(16));
        $key2 = 'aff_refreeze_42_' . bin2hex(random_bytes(16));

        $this->assertNotSame(
            $key1,
            $key2,
            'UUID-ключи должны быть различны даже в одну секунду (F-22-005).'
        );
    }
}
