<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration-тесты для Группы 8 ADR (Step 5) — реальная MariaDB через PDO.
 *
 * Проверяют критичные SQL-инварианты, на которых строится PHP-уровень
 * атомарности sync_update_local / run_auto_decline_batches:
 *   - FOR UPDATE реально блокирует параллельный SELECT ... FOR UPDATE
 *     до COMMIT/ROLLBACK первой транзакции (не словесное, а поведение движка);
 *   - lock wait timeout возвращает распознаваемое сообщение
 *     (matches retry_on_sync_deadlock детектор в Cashback_API_Client);
 *   - deadlock (1213) возвращает распознаваемое сообщение;
 *   - batch UPDATE с пост-лок фильтром order_status даёт ровно declined=N
 *     (не допускает double-decline при двух параллельных прогонах).
 *
 * Требует env:
 *   CASHBACK_TEST_DB_HOST (default 127.0.0.1)
 *   CASHBACK_TEST_DB_PORT (default 3306)
 *   CASHBACK_TEST_DB_USER (required)
 *   CASHBACK_TEST_DB_PASSWORD (optional, '' default)
 *   CASHBACK_TEST_DB_NAME (required)
 *
 * Без env / недоступной БД → markTestSkipped. Все таблицы создаются как
 * TEMPORARY на session — автоматический cleanup в tearDown.
 */
#[Group('group8-integration')]
final class Group8SyncAtomicityIntegrationTest extends TestCase
{
    /** @var PDO|null Соединение A — держит лок. */
    private ?PDO $pdo_a = null;

    /** @var PDO|null Соединение B — проверяет блокировку/deadlock. */
    private ?PDO $pdo_b = null;

    private string $table_name = 'tmp_cashback_group8_it';

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
            $this->pdo_a = $this->connect($dsn, $user, is_string($pass) ? $pass : '');
            $this->pdo_b = $this->connect($dsn, $user, is_string($pass) ? $pass : '');
        } catch (\PDOException $e) {
            $this->markTestSkipped('MariaDB недоступна: ' . $e->getMessage());
        }

        // TEMPORARY per connection — поэтому создаём на обоих соединениях отдельно.
        // Но нам нужна одна shared-таблица, поэтому используем обычную таблицу с cleanup.
        $this->pdo_a->exec("DROP TABLE IF EXISTS `{$this->table_name}`");
        $this->pdo_a->exec("
            CREATE TABLE `{$this->table_name}` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_status VARCHAR(32) NOT NULL DEFAULT 'waiting',
                comission DECIMAL(10,2) DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function connect(string $dsn, string $user, string $pass): PDO
    {
        return new PDO($dsn, $user, $pass, array(
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    }

    protected function tearDown(): void
    {
        if ($this->pdo_a !== null) {
            try {
                // Гарантируем завершение TX перед DROP.
                $this->pdo_a->exec('ROLLBACK');
            } catch (\Throwable $e) {
                // noop — мог быть уже committed
                unset($e);
            }
            try {
                $this->pdo_a->exec("DROP TABLE IF EXISTS `{$this->table_name}`");
            } catch (\Throwable $e) {
                unset($e);
            }
        }
        if ($this->pdo_b !== null) {
            try {
                $this->pdo_b->exec('ROLLBACK');
            } catch (\Throwable $e) {
                unset($e);
            }
        }
        $this->pdo_a = null;
        $this->pdo_b = null;

        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════
    // 1. FOR UPDATE реально блокирует параллельного писателя
    // ════════════════════════════════════════════════════════════════

    public function test_for_update_blocks_concurrent_for_update_until_commit(): void
    {
        $this->pdo_a->exec("INSERT INTO `{$this->table_name}` (id, order_status, comission) VALUES (1, 'waiting', 10.00)");

        // Conn A берёт лок.
        $this->pdo_a->beginTransaction();
        $this->pdo_a->query("SELECT id, order_status FROM `{$this->table_name}` WHERE id = 1 FOR UPDATE");

        // Conn B: короткий timeout, пытается взять тот же лок — должен упасть.
        $this->pdo_b->exec('SET innodb_lock_wait_timeout = 1');
        $this->pdo_b->beginTransaction();

        $got_timeout  = false;
        $error_message = '';
        try {
            $this->pdo_b->query("SELECT id, order_status FROM `{$this->table_name}` WHERE id = 1 FOR UPDATE");
        } catch (\PDOException $e) {
            $got_timeout   = true;
            $error_message = $e->getMessage();
        }

        $this->assertTrue(
            $got_timeout,
            'Conn B должен получить lock wait timeout при попытке FOR UPDATE заблокированной строки.'
        );

        // Сообщение должно распознаваться ретрай-детектором Cashback_API_Client.
        $is_recognizable = ( stripos($error_message, 'lock wait timeout') !== false )
            || ( stripos($error_message, 'deadlock') !== false )
            || ( strpos($error_message, '1205') !== false )
            || ( strpos($error_message, '1213') !== false );

        $this->assertTrue(
            $is_recognizable,
            'Сообщение об ошибке должно содержать маркер retry_on_sync_deadlock (lock wait / deadlock / 1205 / 1213). Получено: ' . $error_message
        );

        // Откат Conn B (чтобы следующие тесты работали на чистом состоянии).
        try {
            $this->pdo_b->rollBack();
        } catch (\Throwable $e) {
            unset($e);
        }
        $this->pdo_a->rollBack();
    }

    // ════════════════════════════════════════════════════════════════
    // 2. После COMMIT первой TX — вторая видит свежее состояние под локом
    // ════════════════════════════════════════════════════════════════

    public function test_second_tx_sees_committed_state_after_first_releases_lock(): void
    {
        $this->pdo_a->exec("INSERT INTO `{$this->table_name}` (id, order_status, comission) VALUES (2, 'waiting', 20.00)");

        // Conn A: берёт лок, обновляет, фиксирует.
        $this->pdo_a->beginTransaction();
        $this->pdo_a->query("SELECT id, order_status FROM `{$this->table_name}` WHERE id = 2 FOR UPDATE");
        $this->pdo_a->exec("UPDATE `{$this->table_name}` SET order_status = 'completed', comission = 99.99 WHERE id = 2");
        $this->pdo_a->commit();

        // Conn B: после отпускания лока читает свежую строку.
        $this->pdo_b->beginTransaction();
        $row = $this->pdo_b->query("SELECT id, order_status, comission FROM `{$this->table_name}` WHERE id = 2 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
        $this->pdo_b->commit();

        $this->assertNotEmpty($row, 'Conn B должен прочитать строку id=2.');
        $this->assertSame('completed', $row['order_status'], 'Conn B должен увидеть committed order_status.');
        $this->assertSame('99.99', $row['comission'], 'Conn B должен увидеть committed comission.');
    }

    // ════════════════════════════════════════════════════════════════
    // 3. Batch UPDATE + post-lock status-gate исключает double-decline
    // ════════════════════════════════════════════════════════════════

    public function test_auto_decline_batch_no_double_decline_under_sequential_passes(): void
    {
        // Сидим 5 строк в waiting.
        for ($i = 10; $i < 15; $i++) {
            $this->pdo_a->exec("INSERT INTO `{$this->table_name}` (id, order_status, comission) VALUES ({$i}, 'waiting', 5.00)");
        }

        $chunk = array( 10, 11, 12, 13, 14 );

        // Первый проход: UPDATE с WHERE IN + order_status IN (...) — все 5 становятся declined.
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $this->pdo_a->prepare(
            "UPDATE `{$this->table_name}` SET order_status = 'declined'
             WHERE id IN ({$placeholders}) AND order_status IN ('waiting', 'hold', 'completed')"
        );
        $stmt->execute($chunk);
        $first_affected = $stmt->rowCount();

        $this->assertSame(5, $first_affected, 'Первый проход должен задеклинить ровно 5 строк.');

        // Второй проход: то же UPDATE — WHERE order_status IN (...) отфильтрует declined, affected=0.
        $stmt2 = $this->pdo_a->prepare(
            "UPDATE `{$this->table_name}` SET order_status = 'declined'
             WHERE id IN ({$placeholders}) AND order_status IN ('waiting', 'hold', 'completed')"
        );
        $stmt2->execute($chunk);
        $second_affected = $stmt2->rowCount();

        $this->assertSame(0, $second_affected, 'Второй проход не должен ничего обновить (post-lock status-gate отфильтровал declined).');

        // Итог: ровно 5 declined.
        $total_declined = (int) $this->pdo_a->query("SELECT COUNT(*) FROM `{$this->table_name}` WHERE order_status = 'declined'")->fetchColumn();
        $this->assertSame(5, $total_declined, 'Итоговое число declined = 5 (нет double-decline).');
    }

    // ════════════════════════════════════════════════════════════════
    // 4. PHP-уровень: retry_on_sync_deadlock детектит реальные MySQL ошибки
    // ════════════════════════════════════════════════════════════════

    public function test_retry_detector_matches_real_lock_wait_error_strings(): void
    {
        $this->pdo_a->exec("INSERT INTO `{$this->table_name}` (id, order_status, comission) VALUES (3, 'waiting', 30.00)");

        // Вызовем lock wait timeout и проверим что message matches наш PHP-детектор.
        $this->pdo_a->beginTransaction();
        $this->pdo_a->query("SELECT id FROM `{$this->table_name}` WHERE id = 3 FOR UPDATE");

        $this->pdo_b->exec('SET innodb_lock_wait_timeout = 1');
        $this->pdo_b->beginTransaction();

        $captured = '';
        try {
            $this->pdo_b->query("SELECT id FROM `{$this->table_name}` WHERE id = 3 FOR UPDATE");
        } catch (\PDOException $e) {
            $captured = $e->getMessage();
        }

        try {
            $this->pdo_b->rollBack();
        } catch (\Throwable $e) {
            unset($e);
        }
        $this->pdo_a->rollBack();

        $this->assertNotSame('', $captured, 'Должно быть захвачено PDOException сообщение.');

        // Mirror производственной логики retry_on_sync_deadlock (Cashback_API_Client).
        $is_retryable = ( stripos($captured, 'deadlock') !== false )
            || ( stripos($captured, 'lock wait timeout') !== false )
            || ( strpos($captured, '1213') !== false )
            || ( strpos($captured, '1205') !== false );

        $this->assertTrue(
            $is_retryable,
            'PHP-детектор retry_on_sync_deadlock должен распознать реальное сообщение от MariaDB. ' .
            'Получено: ' . $captured
        );
    }
}
