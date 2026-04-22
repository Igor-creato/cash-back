<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration-тесты для Группы 8 Step 3 (F-8-005) — схема и SQL-паттерны
 * таблицы cashback_cron_state на реальной MariaDB через PDO.
 *
 * Отражает операции Cashback_Cron_State::begin_stage / finish_stage /
 * get_recent_runs (класс использует global $wpdb, который в test-среде
 * не bootstrap'ится; проверяем SQL-инварианты напрямую через PDO).
 *
 * Требует env (см. Group8SyncAtomicityIntegrationTest). Без env → skip.
 */
#[Group('group8-integration')]
final class CronStateIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;

    private string $table_name = 'tmp_cashback_cron_state_it';

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('CASHBACK_TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('CASHBACK_TEST_DB_PORT') ?: '3306';
        $user = getenv('CASHBACK_TEST_DB_USER');
        $pass = getenv('CASHBACK_TEST_DB_PASSWORD');
        $name = getenv('CASHBACK_TEST_DB_NAME');

        if (! is_string($user) || $user === '' || ! is_string($name) || $name === '') {
            $this->markTestSkipped('CASHBACK_TEST_DB_USER / CASHBACK_TEST_DB_NAME не заданы — integration-тесты пропущены.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            $this->pdo = new PDO($dsn, $user, is_string($pass) ? $pass : '', array(
                PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ));
        } catch (\PDOException $e) {
            $this->markTestSkipped('MariaDB недоступна: ' . $e->getMessage());
        }

        // Воссоздаём схему строго как в mariadb.php::create_tables() (Group 8 Step 3).
        $this->pdo->exec("DROP TABLE IF EXISTS `{$this->table_name}`");
        $this->pdo->exec("
            CREATE TABLE `{$this->table_name}` (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                stage VARCHAR(64) NOT NULL,
                status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
                started_at DATETIME NOT NULL,
                finished_at DATETIME DEFAULT NULL,
                duration_ms INT(11) UNSIGNED DEFAULT NULL,
                metrics_json LONGTEXT DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_run_id (run_id),
                KEY idx_started_at (started_at),
                KEY idx_stage_status (stage, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            try {
                $this->pdo->exec("DROP TABLE IF EXISTS `{$this->table_name}`");
            } catch (\Throwable $e) {
                unset($e);
            }
        }
        $this->pdo = null;
        parent::tearDown();
    }

    private function generate_run_id(): string
    {
        // Локальный stand-in для cashback_generate_uuid7 — 32 hex.
        return bin2hex(random_bytes(16));
    }

    // ════════════════════════════════════════════════════════════════
    // 1. begin_stage-паттерн: INSERT status=running + started_at=NOW
    // ════════════════════════════════════════════════════════════════

    public function test_begin_stage_insert_creates_running_row_with_started_at(): void
    {
        $run_id = $this->generate_run_id();
        $stmt   = $this->pdo->prepare("
            INSERT INTO `{$this->table_name}` (run_id, stage, status, started_at)
            VALUES (?, ?, 'running', NOW())
        ");
        $stmt->execute(array( $run_id, 'background_sync' ));
        $id = (int) $this->pdo->lastInsertId();

        $this->assertGreaterThan(0, $id, 'INSERT должен вернуть положительный insert_id.');

        $row = $this->pdo->query("SELECT * FROM `{$this->table_name}` WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);

        $this->assertSame($run_id, $row['run_id']);
        $this->assertSame('background_sync', $row['stage']);
        $this->assertSame('running', $row['status']);
        $this->assertNotEmpty($row['started_at']);
        $this->assertNull($row['finished_at'], 'finished_at должен быть NULL в running-состоянии.');
        $this->assertNull($row['duration_ms']);
        $this->assertNull($row['metrics_json']);
        $this->assertNull($row['error_message']);
    }

    // ════════════════════════════════════════════════════════════════
    // 2. finish_stage-паттерн: UPDATE со success + duration + metrics
    // ════════════════════════════════════════════════════════════════

    public function test_finish_stage_update_sets_success_with_metrics(): void
    {
        $run_id = $this->generate_run_id();
        $this->pdo->prepare("INSERT INTO `{$this->table_name}` (run_id, stage, status, started_at) VALUES (?, ?, 'running', NOW())")
            ->execute(array( $run_id, 'auto_transfer' ));
        $id = (int) $this->pdo->lastInsertId();

        // Имитируем finish_stage-UPDATE: status + finished_at + duration_ms + metrics_json.
        $metrics = json_encode(array( 'transferred' => 7, 'errors' => 0, 'checked' => 50 ));
        $stmt = $this->pdo->prepare("
            UPDATE `{$this->table_name}`
               SET status = 'success',
                   finished_at = NOW(),
                   duration_ms = 1234,
                   metrics_json = ?
             WHERE id = ?
        ");
        $stmt->execute(array( $metrics, $id ));

        $row = $this->pdo->query("SELECT * FROM `{$this->table_name}` WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('success', $row['status']);
        $this->assertNotEmpty($row['finished_at']);
        $this->assertSame('1234', $row['duration_ms']);

        $parsed = json_decode((string) $row['metrics_json'], true);
        $this->assertIsArray($parsed);
        $this->assertSame(7, $parsed['transferred']);
        $this->assertSame(0, $parsed['errors']);
    }

    // ════════════════════════════════════════════════════════════════
    // 3. finish_stage failed: UPDATE с error_message
    // ════════════════════════════════════════════════════════════════

    public function test_finish_stage_update_sets_failed_with_error_message(): void
    {
        $run_id = $this->generate_run_id();
        $this->pdo->prepare("INSERT INTO `{$this->table_name}` (run_id, stage, status, started_at) VALUES (?, ?, 'running', NOW())")
            ->execute(array( $run_id, 'process_ready' ));
        $id = (int) $this->pdo->lastInsertId();

        $error = 'Deadlock found when trying to get lock; try restarting transaction';
        $stmt  = $this->pdo->prepare("
            UPDATE `{$this->table_name}`
               SET status = 'failed',
                   finished_at = NOW(),
                   error_message = ?
             WHERE id = ?
        ");
        $stmt->execute(array( $error, $id ));

        $row = $this->pdo->query("SELECT * FROM `{$this->table_name}` WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('failed', $row['status']);
        $this->assertSame($error, $row['error_message']);
    }

    // ════════════════════════════════════════════════════════════════
    // 4. Все 5 этапов одного run_id группируются через idx_run_id
    // ════════════════════════════════════════════════════════════════

    public function test_multiple_stages_share_run_id_and_are_retrievable_by_run_id(): void
    {
        $run_id = $this->generate_run_id();
        $stages = array( 'background_sync', 'auto_transfer', 'process_ready', 'affiliate_pending', 'check_campaigns' );

        foreach ($stages as $stage) {
            $this->pdo->prepare("INSERT INTO `{$this->table_name}` (run_id, stage, status, started_at) VALUES (?, ?, 'running', NOW())")
                ->execute(array( $run_id, $stage ));
        }

        // Все 5 строк должны быть видны по run_id.
        $stmt = $this->pdo->prepare("SELECT stage FROM `{$this->table_name}` WHERE run_id = ? ORDER BY id ASC");
        $stmt->execute(array( $run_id ));
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(5, $rows, 'Все 5 этапов должны быть записаны под одним run_id.');
        $this->assertSame($stages, $rows, 'Порядок этапов должен совпадать с порядком INSERT.');
    }

    // ════════════════════════════════════════════════════════════════
    // 5. get_recent_runs ordering: started_at DESC, id DESC
    // ════════════════════════════════════════════════════════════════

    public function test_get_recent_runs_orders_by_started_at_desc(): void
    {
        // 3 прогона с разными started_at.
        for ($i = 0; $i < 3; $i++) {
            $run_id = $this->generate_run_id();
            $this->pdo->prepare("
                INSERT INTO `{$this->table_name}` (run_id, stage, status, started_at)
                VALUES (?, 'background_sync', 'success', DATE_SUB(NOW(), INTERVAL ? SECOND))
            ")->execute(array( $run_id, ( 2 - $i ) * 10 ));
            // i=0: started_at = NOW() - 20s (самый старый)
            // i=1: started_at = NOW() - 10s
            // i=2: started_at = NOW() (самый новый)
        }

        $stmt = $this->pdo->prepare("
            SELECT id, started_at FROM `{$this->table_name}`
            ORDER BY started_at DESC, id DESC
            LIMIT 10
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(3, $rows);
        $this->assertTrue(
            strtotime($rows[0]['started_at']) >= strtotime($rows[1]['started_at']),
            'Первая строка (ORDER BY started_at DESC) должна быть новее второй.'
        );
        $this->assertTrue(
            strtotime($rows[1]['started_at']) >= strtotime($rows[2]['started_at']),
            'Вторая строка должна быть новее или равна третьей.'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // 6. ENUM status — отклоняет невалидные значения (strict-mode gated)
    // ════════════════════════════════════════════════════════════════

    public function test_status_enum_is_restricted_to_three_values(): void
    {
        // Проверяем, что валидные значения принимаются.
        foreach (array( 'running', 'success', 'failed' ) as $status) {
            $this->pdo->prepare("INSERT INTO `{$this->table_name}` (run_id, stage, status, started_at) VALUES (?, 'x', ?, NOW())")
                ->execute(array( $this->generate_run_id(), $status ));
        }

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$this->table_name}`")->fetchColumn();
        $this->assertSame(3, $count);

        // Невалидный статус — при STRICT_TRANS_TABLES это fail; иначе silent convert.
        // Проверяем оба случая: либо исключение, либо статус не-'invalid'.
        $caught = false;
        try {
            $this->pdo->prepare("INSERT INTO `{$this->table_name}` (run_id, stage, status, started_at) VALUES (?, 'x', 'invalid_xxx', NOW())")
                ->execute(array( $this->generate_run_id() ));
        } catch (\PDOException $e) {
            $caught = true;
        }

        if (! $caught) {
            // SQL-mode не strict — MariaDB конвертит в '' (пустой ENUM).
            $stored = $this->pdo->query("SELECT status FROM `{$this->table_name}` WHERE stage = 'x' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $this->assertNotSame('invalid_xxx', $stored, 'Невалидный ENUM должен быть отклонён либо silently конвертирован.');
        }
    }
}
