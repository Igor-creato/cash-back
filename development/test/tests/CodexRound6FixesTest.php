<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression-тесты для Codex adversarial-review round 6 (2026-05-10).
 *
 * Закрывает 2 high-finding'а:
 *   #2 — backfill-миграции дропали status-validation триггер во время live
 *        traffic, окно DROP+UPDATE+CREATE позволяло concurrent admin/API
 *        пробить инвариант (status='balance' нельзя менять). Фикс: LOCK
 *        TABLES WRITE сериализует backfill с любыми writes на таблицу.
 *   #3 — create_triggers() при ошибке делал wp_die. Это допустимо в activation,
 *        но recreate_triggers() вызывается из runtime maybe_run_migrations() —
 *        wp_die там убил бы frontend/cron-запрос. Фикс: throw RuntimeException;
 *        activate() ловит и делает wp_die, runtime path ловит и показывает
 *        admin notice через transient.
 */
#[Group('database')]
#[Group('regression')]
final class CodexRound6FixesTest extends TestCase
{
    private function read(string $rel): string
    {
        $path    = dirname(__DIR__, 3) . '/' . $rel;
        $content = file_get_contents($path);
        $this->assertIsString($content, "{$rel} must be readable");
        return $content;
    }

    // =========================================================================
    // Finding #3: create_triggers throws RuntimeException, not wp_die
    // =========================================================================

    public function test_create_triggers_throws_exception_not_wp_die(): void
    {
        $src   = $this->read('mariadb.php');
        $start = strpos($src, 'private function create_triggers()');
        $this->assertNotFalse($start, 'create_triggers() должен существовать');

        // Ищем тело метода до следующего `private function ` или `public function `.
        $next  = strpos($src, "\n    /**", $start + 10);
        $body  = $next !== false ? substr($src, $start, $next - $start) : substr($src, $start, 12000);

        // Должно быть `throw new \RuntimeException(...)` для trigger-failure.
        $this->assertMatchesRegularExpression(
            "/throw\s+new\s+\\\\RuntimeException\s*\(/",
            $body,
            'create_triggers() должен бросать \\RuntimeException при провале CREATE TRIGGER '
            . '(Codex round 6 #3: wp_die убил бы runtime request, обёртка try/catch '
            . 'её бы не поймала)'
        );

        // Сообщение exception'а должно содержать стабильный prefix, по которому
        // activate() распознаёт trigger-failure от других ошибок.
        $this->assertStringContainsString(
            'Failed to create cashback triggers:',
            $body,
            'RuntimeException должен иметь стабильный message-prefix для распознавания в activate()'
        );
    }

    public function test_activate_catch_block_handles_trigger_failure_with_wp_die(): void
    {
        $src   = $this->read('mariadb.php');
        $start = strpos($src, 'public static function activate()');
        $this->assertNotFalse($start, 'Mariadb_Plugin::activate должен существовать');

        // Берём ~5000 символов — хватит на тело + catch block.
        $body = substr($src, $start, 5000);

        // catch (\Throwable $e) должен распознавать trigger-failure через message-prefix.
        $this->assertStringContainsString(
            "Failed to create cashback triggers:",
            $body,
            'activate() catch должен распознавать message от RuntimeException create_triggers()'
        );

        // И делать wp_die с явным сообщением про SUPER privilege.
        $this->assertMatchesRegularExpression(
            "/wp_die\s*\([\s\S]*?SUPER\s+privilege/",
            $body,
            'activate() catch должен делать wp_die с диагностикой про SUPER privilege'
        );
    }

    public function test_runtime_migrations_register_admin_notice_on_trigger_failure(): void
    {
        $src   = $this->read('cashback-plugin.php');
        $start = strpos($src, 'private function maybe_run_migrations');
        $this->assertNotFalse($start, 'maybe_run_migrations должен существовать');
        // 8000 chars — у метода 3 catch-блока, тело длинное.
        $body = substr($src, $start, 8000);

        // Все 3 catch-блока вокруг recreate_triggers() должны вызывать
        // register_trigger_failure_notice — иначе runtime-failure пройдёт молча.
        $catch_count = preg_match_all(
            '/register_trigger_failure_notice\s*\(/',
            $body
        );
        $this->assertGreaterThanOrEqual(
            3,
            $catch_count,
            'maybe_run_migrations должен вызывать register_trigger_failure_notice в каждом catch (3 try/catch блока)'
        );

        // Helper register_trigger_failure_notice должен ставить transient.
        $this->assertMatchesRegularExpression(
            "/register_trigger_failure_notice[\s\S]+?set_transient\s*\(\s*'cashback_trigger_failure_notice'/",
            $src,
            'register_trigger_failure_notice должен ставить transient для persistent admin notice'
        );

        // На init() следующего request'а проверяется transient → admin_notices.
        // 8000 chars — gate-блок + load_dependencies + check'и encryption/trigger.
        $init_start = strpos($src, 'public function init()');
        $this->assertNotFalse($init_start, 'init() должен существовать');
        $init_body = substr($src, $init_start, 8000);
        $this->assertMatchesRegularExpression(
            "/get_transient\s*\(\s*'cashback_trigger_failure_notice'\s*\)/",
            $init_body,
            'init() должен проверять transient cashback_trigger_failure_notice — '
            . 'persistent notice показывается до явного re-activate'
        );
    }

    // =========================================================================
    // Finding #2: LOCK TABLES WRITE around DROP+UPDATE+CREATE TRIGGER window
    // =========================================================================

    public function test_migrate_add_transaction_reference_id_uses_lock_tables(): void
    {
        $src   = $this->read('mariadb.php');
        $start = strpos($src, 'public function migrate_add_transaction_reference_id');
        $this->assertNotFalse($start, 'migrate_add_transaction_reference_id должен существовать');
        $body = substr($src, $start, 6000);

        $this->assertMatchesRegularExpression(
            "/LOCK\s+TABLES\s+%i\s+WRITE/i",
            $body,
            'migrate_add_transaction_reference_id должен делать LOCK TABLES WRITE перед DROP TRIGGER '
            . '(Codex round 6 #2: иначе concurrent UPDATE может пробить status-validation в окне)'
        );

        // UNLOCK TABLES должен быть в finally-блоке для exception-safety.
        $this->assertMatchesRegularExpression(
            "/finally\s*{[\s\S]*?UNLOCK\s+TABLES/i",
            $body,
            'UNLOCK TABLES должен быть в finally — иначе exception между DROP и CREATE '
            . 'оставит таблицу залоченной до конца session\'а'
        );

        // DROP TRIGGER должен идти ПОСЛЕ LOCK TABLES (внутри try-блока).
        $lock_pos = strpos($body, 'LOCK TABLES');
        $drop_pos = strpos($body, 'DROP TRIGGER IF EXISTS');
        $this->assertNotFalse($lock_pos, 'LOCK TABLES должен быть');
        $this->assertNotFalse($drop_pos, 'DROP TRIGGER должен быть');
        $this->assertLessThan(
            $drop_pos,
            $lock_pos,
            'LOCK TABLES должен быть ДО DROP TRIGGER, иначе окно остаётся открытым'
        );
    }

    public function test_migrate_unregistered_reference_id_prefix_uses_lock_tables(): void
    {
        $src   = $this->read('mariadb.php');
        $start = strpos($src, 'public function migrate_unregistered_reference_id_prefix');
        $this->assertNotFalse($start, 'migrate_unregistered_reference_id_prefix должен существовать');
        $body = substr($src, $start, 6000);

        $this->assertMatchesRegularExpression(
            "/LOCK\s+TABLES\s+%i\s+WRITE/i",
            $body,
            'migrate_unregistered_reference_id_prefix должен делать LOCK TABLES WRITE (Codex round 6 #2)'
        );

        $this->assertMatchesRegularExpression(
            "/finally\s*{[\s\S]*?UNLOCK\s+TABLES/i",
            $body,
            'UNLOCK TABLES должен быть в finally — exception-safe unlock'
        );

        $lock_pos = strpos($body, 'LOCK TABLES');
        $drop_pos = strpos($body, 'DROP TRIGGER IF EXISTS');
        $this->assertNotFalse($lock_pos);
        $this->assertNotFalse($drop_pos);
        $this->assertLessThan(
            $drop_pos,
            $lock_pos,
            'LOCK TABLES должен быть ДО DROP TRIGGER'
        );
    }
}
