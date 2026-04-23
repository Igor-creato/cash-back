<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration-тесты для 12i-4 ADR — реальная MariaDB через PDO.
 *
 * Проверяют SQL-level инварианты, на которых стоит activate_cashback dedup:
 *   - SELECT FOR UPDATE по (user_id, product_id, status='active', expires_at>NOW())
 *     блокирует параллельный SELECT FOR UPDATE до COMMIT (race protection).
 *   - INSERT в click_sessions с UNIQUE(canonical_click_id) работает идемпотентно
 *     через ON DUPLICATE KEY UPDATE (не-сценарий — просто uk) и бросает Duplicate-ошибку
 *     при дубле canonical_click_id (защита от race между генерацией UUID'а).
 *   - Reuse семантика: UPDATE tap_count = tap_count + 1 инкрементит ровно раз.
 *   - Session lookup с expires_at > NOW(): истёкшая сессия НЕ выбирается.
 *   - Cross-user / cross-product: сессии не пересекаются (правильный композитный индекс).
 *   - Legacy fallback: click_log lookup работает, когда click_sessions не содержит запись.
 *
 * Требует env:
 *   CASHBACK_TEST_DB_HOST (default 127.0.0.1)
 *   CASHBACK_TEST_DB_PORT (default 3306)
 *   CASHBACK_TEST_DB_USER (required)
 *   CASHBACK_TEST_DB_PASSWORD (optional)
 *   CASHBACK_TEST_DB_NAME (required)
 *
 * Без env / недоступной БД → markTestSkipped.
 */
#[Group('group12')]
#[Group('f-10-001')]
#[Group('click-sessions')]
#[Group('integration')]
final class ActivateSessionDedupIntegrationTest extends TestCase
{
    /** @var PDO|null Соединение A — держит лок. */
    private ?PDO $pdo_a = null;

    /** @var PDO|null Соединение B — проверяет блокировку. */
    private ?PDO $pdo_b = null;

    private string $sessions_table = 'tmp_cashback_click_sessions_12i';
    private string $click_log_table = 'tmp_cashback_click_log_12i';
    private string $networks_table = 'tmp_cashback_affiliate_networks_12i';

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('CASHBACK_TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('CASHBACK_TEST_DB_PORT') ?: '3306';
        $user = getenv('CASHBACK_TEST_DB_USER');
        $pass = getenv('CASHBACK_TEST_DB_PASSWORD');
        $name = getenv('CASHBACK_TEST_DB_NAME');

        if (!is_string($user) || $user === '' || !is_string($name) || $name === '') {
            $this->markTestSkipped('CASHBACK_TEST_DB_USER / CASHBACK_TEST_DB_NAME не заданы — integration-тесты пропущены.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            $this->pdo_a = $this->connect($dsn, $user, is_string($pass) ? $pass : '');
            $this->pdo_b = $this->connect($dsn, $user, is_string($pass) ? $pass : '');
        } catch (\PDOException $e) {
            $this->markTestSkipped('MariaDB недоступна: ' . $e->getMessage());
        }

        $this->pdo_a->exec("DROP TABLE IF EXISTS `{$this->sessions_table}`");
        $this->pdo_a->exec("DROP TABLE IF EXISTS `{$this->click_log_table}`");
        $this->pdo_a->exec("DROP TABLE IF EXISTS `{$this->networks_table}`");

        // Схема — копия production CREATE TABLE из mariadb.php.
        $this->pdo_a->exec("
            CREATE TABLE `{$this->sessions_table}` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                canonical_click_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                guest_session_id VARCHAR(128) DEFAULT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                merchant_id BIGINT UNSIGNED NOT NULL,
                affiliate_url TEXT NOT NULL,
                status ENUM('active','expired','converted','invalidated') NOT NULL DEFAULT 'active',
                tap_count INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                expires_at DATETIME(6) NOT NULL,
                last_tap_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                converted_at DATETIME(6) DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_canonical_click_id (canonical_click_id),
                KEY idx_user_product_active (user_id, product_id, status, expires_at),
                KEY idx_guest_product_active (guest_session_id, product_id, status, expires_at),
                KEY idx_expires_status (expires_at, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo_a->exec("
            CREATE TABLE `{$this->click_log_table}` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                click_session_id BIGINT UNSIGNED DEFAULT NULL,
                client_request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                is_session_primary TINYINT(1) NOT NULL DEFAULT 0,
                click_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                product_id BIGINT UNSIGNED NOT NULL,
                affiliate_url TEXT NOT NULL,
                ip_address VARCHAR(45) NOT NULL DEFAULT '127.0.0.1',
                spam_click TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                UNIQUE KEY uk_click_id (click_id),
                KEY idx_click_session_id (click_session_id),
                KEY idx_client_request (user_id, client_request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function connect( string $dsn, string $user, string $pass ): PDO
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
                $this->pdo_a->exec('ROLLBACK');
            } catch (\Throwable $e) {
                unset($e);
            }
            $this->pdo_a->exec("DROP TABLE IF EXISTS `{$this->sessions_table}`");
            $this->pdo_a->exec("DROP TABLE IF EXISTS `{$this->click_log_table}`");
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

    // ─────────────────────────────────────────────────────────────────────
    // Helpers — симуляция SQL-флоу activate_cashback()
    // ─────────────────────────────────────────────────────────────────────

    private function insert_session( PDO $pdo, string $canonical, int $user_id, int $product_id, int $merchant_id, int $window_sec = 1800, string $status = 'active' ): int
    {
        $stmt = $pdo->prepare("
            INSERT INTO `{$this->sessions_table}`
                (canonical_click_id, user_id, product_id, merchant_id, affiliate_url, status, tap_count, expires_at)
            VALUES
                (:cc, :uid, :pid, :mid, :url, :st, 1, DATE_ADD(NOW(6), INTERVAL :win SECOND))
        ");
        $stmt->execute(array(
            ':cc'  => $canonical,
            ':uid' => $user_id,
            ':pid' => $product_id,
            ':mid' => $merchant_id,
            ':url' => 'https://example.com/p/' . $product_id,
            ':st'  => $status,
            ':win' => $window_sec,
        ));
        return (int) $pdo->lastInsertId();
    }

    private function insert_click_log( PDO $pdo, string $click_id, int $session_pk, int $user_id, int $product_id, bool $is_primary, ?string $request_id = null ): int
    {
        $stmt = $pdo->prepare("
            INSERT INTO `{$this->click_log_table}`
                (click_id, click_session_id, client_request_id, is_session_primary, user_id, product_id, affiliate_url)
            VALUES
                (:click, :sid, :req, :primary, :uid, :pid, :url)
        ");
        $stmt->execute(array(
            ':click'   => $click_id,
            ':sid'     => $session_pk,
            ':req'     => $request_id,
            ':primary' => $is_primary ? 1 : 0,
            ':uid'     => $user_id,
            ':pid'     => $product_id,
            ':url'     => 'https://example.com/p/' . $product_id,
        ));
        return (int) $pdo->lastInsertId();
    }

    private function select_active_session( PDO $pdo, int $user_id, int $product_id ): ?array
    {
        $stmt = $pdo->prepare("
            SELECT id, canonical_click_id, tap_count
              FROM `{$this->sessions_table}`
             WHERE user_id = :uid AND product_id = :pid
               AND status = 'active' AND expires_at > NOW(6)
             ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute(array( ':uid' => $user_id, ':pid' => $product_id ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    // ═════════════════════════════════════════════════════════════════════
    // 1. Happy path — INSERT session + primary tap
    // ═════════════════════════════════════════════════════════════════════

    public function test_new_session_insert_creates_session_with_tap_count_1(): void
    {
        $canonical = str_repeat('a', 32);
        $session_pk = $this->insert_session($this->pdo_a, $canonical, 10, 100, 1);
        $this->insert_click_log($this->pdo_a, $canonical, $session_pk, 10, 100, true, 'req_01' . str_repeat('0', 26));

        $session = $this->select_active_session($this->pdo_a, 10, 100);
        $this->assertNotNull($session);
        $this->assertSame($canonical, $session['canonical_click_id']);
        $this->assertSame('1', (string) $session['tap_count']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 2. Reuse in window — UPDATE tap_count инкрементится
    // ═════════════════════════════════════════════════════════════════════

    public function test_reuse_increments_tap_count_preserves_canonical(): void
    {
        $canonical  = str_repeat('b', 32);
        $session_pk = $this->insert_session($this->pdo_a, $canonical, 11, 101, 1);
        $this->insert_click_log($this->pdo_a, $canonical, $session_pk, 11, 101, true);

        // Reuse-флоу: SELECT FOR UPDATE → UPDATE tap_count + 1 → INSERT новый tap с is_session_primary=0.
        $this->pdo_a->beginTransaction();
        $session = $this->select_active_session($this->pdo_a, 11, 101);
        $this->assertNotNull($session);
        $update = $this->pdo_a->prepare("UPDATE `{$this->sessions_table}` SET tap_count = tap_count + 1, last_tap_at = NOW(6) WHERE id = :id");
        $update->execute(array( ':id' => (int) $session['id'] ));
        $this->pdo_a->commit();

        // Второй tap получает новый UUID (не canonical), is_session_primary=0.
        $secondary_click = str_repeat('c', 32);
        $this->insert_click_log($this->pdo_a, $secondary_click, (int) $session['id'], 11, 101, false);

        $fresh = $this->select_active_session($this->pdo_a, 11, 101);
        $this->assertSame('2', (string) $fresh['tap_count'], 'tap_count должен стать 2 после второго тапа.');
        $this->assertSame($canonical, $fresh['canonical_click_id'], 'canonical_click_id не должен меняться при reuse.');

        $log_rows = $this->pdo_a->query("SELECT click_id, is_session_primary FROM `{$this->click_log_table}` WHERE click_session_id = {$session['id']} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $log_rows);
        $this->assertSame(1, (int) $log_rows[0]['is_session_primary']);
        $this->assertSame(0, (int) $log_rows[1]['is_session_primary']);
        $this->assertSame($canonical, $log_rows[0]['click_id']);
        $this->assertNotSame($canonical, $log_rows[1]['click_id']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 3. Outside window — expired session НЕ выбирается
    // ═════════════════════════════════════════════════════════════════════

    public function test_expired_session_not_selected(): void
    {
        $canonical = str_repeat('d', 32);
        // Вставляем сессию с expires_at = NOW + 1 sec, ждём 2 сек — истечёт.
        $this->insert_session($this->pdo_a, $canonical, 12, 102, 1, 1);
        sleep(2);

        $session = $this->select_active_session($this->pdo_a, 12, 102);
        $this->assertNull($session, 'Сессия с expires_at < NOW не должна быть выбрана.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // 4. Cross-user / cross-product — сессии независимы
    // ═════════════════════════════════════════════════════════════════════

    public function test_different_user_same_product_gets_different_session(): void
    {
        $this->insert_session($this->pdo_a, str_repeat('e', 32), 13, 103, 1);

        $other_user_session = $this->select_active_session($this->pdo_a, 99, 103);
        $this->assertNull($other_user_session, 'Сессия user=13 не должна возвращаться для user=99.');
    }

    public function test_different_product_same_user_gets_different_session(): void
    {
        $this->insert_session($this->pdo_a, str_repeat('f', 32), 14, 104, 1);

        $other_product_session = $this->select_active_session($this->pdo_a, 14, 999);
        $this->assertNull($other_product_session, 'Сессия product=104 не должна возвращаться для product=999.');
    }

    // ═════════════════════════════════════════════════════════════════════
    // 5. UNIQUE canonical_click_id — guard против race UUID collision
    // ═════════════════════════════════════════════════════════════════════

    public function test_unique_canonical_click_id_rejects_duplicate(): void
    {
        $canonical = str_repeat('1', 32);
        $this->insert_session($this->pdo_a, $canonical, 15, 105, 1);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/Duplicate|1062/i');
        $this->insert_session($this->pdo_a, $canonical, 15, 105, 1);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 6. FOR UPDATE — блокировка параллельной сессии
    // ═════════════════════════════════════════════════════════════════════

    public function test_for_update_blocks_concurrent_session_lookup(): void
    {
        $canonical  = str_repeat('2', 32);
        $session_pk = $this->insert_session($this->pdo_a, $canonical, 16, 106, 1);

        // Conn A берёт лок.
        $this->pdo_a->beginTransaction();
        $lock_a = $this->pdo_a->prepare("
            SELECT id FROM `{$this->sessions_table}`
             WHERE user_id = :uid AND product_id = :pid AND status = 'active'
             ORDER BY created_at DESC LIMIT 1 FOR UPDATE
        ");
        $lock_a->execute(array( ':uid' => 16, ':pid' => 106 ));
        $lock_a->fetch(PDO::FETCH_ASSOC);

        // Conn B: короткий timeout, пытается взять тот же лок.
        $this->pdo_b->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->pdo_b->beginTransaction();
        $caught = null;
        try {
            $lock_b = $this->pdo_b->prepare("
                SELECT id FROM `{$this->sessions_table}`
                 WHERE user_id = :uid AND product_id = :pid AND status = 'active'
                 ORDER BY created_at DESC LIMIT 1 FOR UPDATE
            ");
            $lock_b->execute(array( ':uid' => 16, ':pid' => 106 ));
            $lock_b->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Conn B должен получить lock wait timeout — FOR UPDATE от Conn A блокирует.');
        $this->assertMatchesRegularExpression('/lock wait|1205/i', $caught->getMessage());

        $this->pdo_b->rollBack();
        $this->pdo_a->commit();
    }

    // ═════════════════════════════════════════════════════════════════════
    // 7. Click_log session_id FK — tap event знает свою сессию
    // ═════════════════════════════════════════════════════════════════════

    public function test_click_log_session_id_links_to_session(): void
    {
        $canonical  = str_repeat('3', 32);
        $session_pk = $this->insert_session($this->pdo_a, $canonical, 17, 107, 1);
        $this->insert_click_log($this->pdo_a, $canonical, $session_pk, 17, 107, true, 'req' . str_repeat('0', 29));

        $join = $this->pdo_a->query("
            SELECT s.canonical_click_id, cl.click_id, cl.is_session_primary, cl.client_request_id
              FROM `{$this->click_log_table}` cl
              JOIN `{$this->sessions_table}` s ON cl.click_session_id = s.id
             WHERE cl.click_session_id = {$session_pk}
        ")->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($join);
        $this->assertSame($canonical, $join['canonical_click_id']);
        $this->assertSame($canonical, $join['click_id'], 'Primary tap click_id == canonical');
        $this->assertSame(1, (int) $join['is_session_primary']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 8. Legacy click_log: session_id IS NULL должен работать через idx_click_id
    // ═════════════════════════════════════════════════════════════════════

    public function test_legacy_click_log_without_session_id_queryable(): void
    {
        // Legacy row: session_id = NULL, is_session_primary = 0 (default).
        $stmt = $this->pdo_a->prepare("
            INSERT INTO `{$this->click_log_table}`
                (click_id, user_id, product_id, affiliate_url)
            VALUES
                (:click, :uid, :pid, :url)
        ");
        $stmt->execute(array(
            ':click' => str_repeat('9', 32),
            ':uid'   => 18,
            ':pid'   => 108,
            ':url'   => 'https://legacy.example.com',
        ));

        $row = $this->pdo_a->query("SELECT click_session_id, is_session_primary FROM `{$this->click_log_table}` WHERE click_id = '" . str_repeat('9', 32) . "'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['click_session_id'], 'Legacy row — session_id NULL.');
        $this->assertSame(0, (int) $row['is_session_primary']);

        // Fallback lookup: ищем по click_id без session фильтра.
        $found = $this->pdo_a->query("SELECT affiliate_url FROM `{$this->click_log_table}` WHERE click_id = '" . str_repeat('9', 32) . "' LIMIT 1")->fetchColumn();
        $this->assertSame('https://legacy.example.com', $found);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 9. Status != 'active' (converted/expired/invalidated) исключается
    // ═════════════════════════════════════════════════════════════════════

    public function test_non_active_session_not_selected(): void
    {
        $this->insert_session($this->pdo_a, str_repeat('x', 32), 19, 109, 1, 1800, 'converted');
        $this->insert_session($this->pdo_a, str_repeat('y', 32), 19, 109, 1, 1800, 'invalidated');

        $session = $this->select_active_session($this->pdo_a, 19, 109);
        $this->assertNull($session, 'Converted/invalidated сессии не должны возвращаться через idx_user_product_active.');
    }
}
