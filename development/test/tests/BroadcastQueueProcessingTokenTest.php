<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 12g ADR — processing_token + last_error schema для broadcast-queue.
 *
 * Closes F-23-004/005 (notifications-queue: дубль-claim риск при параллельных
 * воркерах и truncation длинных ошибок в VARCHAR(255)).
 *
 * Контекст (до 12g):
 *  - `cashback_broadcast_queue` имеет status, attempts, error VARCHAR(255), processed_at.
 *  - claim_batch использует TX + SELECT FOR UPDATE + UPDATE status='processing' (iter-24),
 *    CAS на UPDATE финализации: WHERE id=N AND status='processing'.
 *  - Риск 1 (дубли): при replication lag / reader split / gap-lock collisions TX+FOR UPDATE
 *    может не дать гарантию — два воркера могут взять одну строку. CAS status='processing'
 *    позволит обоим сохранить результат (оба видят 'processing').
 *  - Риск 2 (truncation): error VARCHAR(255) обрезает длинные ошибки (SMTP stack trace,
 *    исключения с payload'ом) — теряется post-mortem контекст.
 *
 * Контракт (TDD RED):
 *  1. Schema (create_tables): добавлены колонки `processing_token CHAR(36)` +
 *     `last_error TEXT` в CREATE TABLE cashback_broadcast_queue, KEY на processing_token.
 *  2. Migration helper `migrate_add_processing_token_and_last_error()`:
 *     - SHOW COLUMNS guards + ALTER TABLE ADD COLUMN (pattern из 12f/Claims).
 *     - Использует is_known_ddl_error / report_migration_error (эскалация ошибок).
 *  3. Migration зарегистрирована в cashback-plugin.php bootstrap.
 *  4. broadcast.php claim_batch:
 *     - Генерирует UUID (wp_generate_uuid4) per batch.
 *     - UPDATE-claim пишет processing_token = $uuid.
 *  5. broadcast.php CAS финализации (sent / failed / cancelled):
 *     - WHERE id = N AND processing_token = $token (не status='processing').
 *  6. broadcast.php пишет ошибки в last_error (не в error) — truncation устраняется.
 *
 * Старая колонка `error` остаётся (backward-compat для уже накопленных данных);
 * дроп — отдельный батч когда все воркеры перейдут на last_error.
 */
#[Group('security')]
#[Group('group12')]
#[Group('notifications')]
#[Group('broadcast')]
class BroadcastQueueProcessingTokenTest extends TestCase
{
    private string $notifications_db_source = '';
    private string $broadcast_source        = '';
    private string $plugin_bootstrap_source = '';

    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        $paths = array(
            'notifications_db' => $plugin_root . '/notifications/class-cashback-notifications-db.php',
            'broadcast'        => $plugin_root . '/notifications/class-cashback-broadcast.php',
            'plugin_bootstrap' => $plugin_root . '/cashback-plugin.php',
        );

        foreach ($paths as $key => $path) {
            self::assertFileExists($path, $key . ' должен присутствовать');
            $src = file_get_contents($path);
            self::assertNotFalse($src, 'Не удалось прочитать ' . $key);
            $this->{$key . '_source'} = $src;
        }
    }

    // =====================================================================
    // 1. Schema в create_tables: processing_token + last_error
    // =====================================================================

    public function test_create_tables_includes_processing_token_column(): void
    {
        self::assertMatchesRegularExpression(
            '/processing_token\s+CHAR\s*\(\s*36\s*\)/i',
            $this->notifications_db_source,
            'F-23-004: CREATE TABLE cashback_broadcast_queue должен включать `processing_token CHAR(36)`.'
        );
    }

    public function test_create_tables_includes_last_error_column(): void
    {
        self::assertMatchesRegularExpression(
            '/last_error\s+TEXT/i',
            $this->notifications_db_source,
            'F-23-005: CREATE TABLE cashback_broadcast_queue должен включать `last_error TEXT` — '
            . 'VARCHAR(255) обрезает длинные stack traces.'
        );
    }

    public function test_create_tables_includes_processing_token_key(): void
    {
        self::assertMatchesRegularExpression(
            '/KEY\s+\w*processing_token\w*\s*\(\s*processing_token\s*\)/i',
            $this->notifications_db_source,
            'F-23-004: KEY на processing_token нужен для быстрого claim-lookup воркером.'
        );
    }

    // =====================================================================
    // 2. Migration helper
    // =====================================================================

    public function test_migration_helper_exists(): void
    {
        self::assertMatchesRegularExpression(
            '/public\s+static\s+function\s+migrate_add_processing_token_and_last_error\s*\(\s*\)\s*:\s*void/',
            $this->notifications_db_source,
            'F-23-004/005: должен быть migration helper для существующих установок '
            . '(SHOW COLUMNS + ALTER TABLE ADD COLUMN, pattern из 12f).'
        );
    }

    public function test_migration_uses_ddl_error_classifier(): void
    {
        // Миграция должна использовать паттерн из 12f — is_known_ddl_error / report_migration_error,
        // чтобы непредвиденные ALTER-ошибки эскалировались в admin_notice.
        self::assertMatchesRegularExpression(
            '/is_known_ddl_error\s*\(|report_migration_error\s*\(/',
            $this->notifications_db_source,
            'F-23-004/005: migration helper должен классифицировать DDL-ошибки '
            . '(is_known_ddl_error + report_migration_error из паттерна 12f).'
        );
    }

    public function test_migration_wired_in_plugin_bootstrap(): void
    {
        self::assertMatchesRegularExpression(
            '/Cashback_Notifications_DB::migrate_add_processing_token_and_last_error\s*\(\s*\)/',
            $this->plugin_bootstrap_source,
            'F-23-004/005: migration должна вызываться из cashback-plugin.php bootstrap '
            . 'после Cashback_Notifications_DB::create_tables().'
        );
    }

    // =====================================================================
    // 3. broadcast.php claim_batch — UUID + processing_token
    // =====================================================================

    public function test_claim_batch_generates_uuid_token(): void
    {
        self::assertMatchesRegularExpression(
            '/wp_generate_uuid4\s*\(/',
            $this->broadcast_source,
            'F-23-004: claim_batch должен генерировать UUID через wp_generate_uuid4() '
            . '— per-batch ownership token.'
        );
    }

    public function test_claim_batch_update_sets_processing_token(): void
    {
        // UPDATE-claim должен включать `processing_token = %s` в SET-части.
        self::assertMatchesRegularExpression(
            '/processing_token\s*=\s*%s/i',
            $this->broadcast_source,
            'F-23-004: claim UPDATE должен SET processing_token = %s (prepared-параметр $token), '
            . 'чтобы CAS на финализации мог матчить ownership.'
        );
    }

    // =====================================================================
    // 4. CAS финализации использует processing_token
    // =====================================================================

    public function test_cas_finalize_uses_processing_token(): void
    {
        // В финализации sent/failed — WHERE должен включать processing_token, не только status='processing'.
        // Проверяем, что processing_token упомянут в wpdb->update WHERE-частях после claim.
        //
        // Минимум: где-то в CAS-контексте (в одном окне с 'processing') должна быть привязка к processing_token.
        self::assertMatchesRegularExpression(
            "/'processing_token'\s*=>/",
            $this->broadcast_source,
            'F-23-004: финализация (sent/failed/cancelled) должна матчить по processing_token, '
            . 'а не только status=processing — защита от collision параллельных воркеров.'
        );
    }

    // =====================================================================
    // 5. last_error вместо error в error-writes
    // =====================================================================

    public function test_broadcast_writes_to_last_error(): void
    {
        self::assertMatchesRegularExpression(
            "/'last_error'\s*=>/",
            $this->broadcast_source,
            'F-23-005: error-writes должны идти в `last_error` (TEXT) вместо `error` (VARCHAR 255). '
            . 'Truncation stack traces устраняется.'
        );
    }

    // =====================================================================
    // Regression guards
    // =====================================================================

    public function test_processing_status_not_removed(): void
    {
        // Regression: 'processing' как промежуточный status остаётся (iter-24 F-24-003
        // recovery logic его использует). Token — дополнительный guard, не замена.
        self::assertMatchesRegularExpression(
            "/'processing'/",
            $this->broadcast_source,
            'Regression: intermediate status=processing должен остаться — recovery stale logic его использует.'
        );
    }

    public function test_for_update_claim_pattern_preserved(): void
    {
        // Regression: SELECT FOR UPDATE в claim_batch остаётся — token это defense-in-depth,
        // не замена TX-гварантий.
        self::assertMatchesRegularExpression(
            '/FOR\s+UPDATE/i',
            $this->broadcast_source,
            'Regression: SELECT FOR UPDATE в claim_batch должен остаться — token-based claim '
            . 'это defense-in-depth поверх TX, не замена.'
        );
    }
}
