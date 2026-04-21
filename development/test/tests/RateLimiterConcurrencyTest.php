<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TDD-red спецификация для атомарного rate-limit counter (Группа 7 ADR, шаг 1).
 *
 * Фиксирует контракт Cashback_Rate_Limit_Counter_Interface и MariaDB-реализацию
 * Cashback_Rate_Limit_SQL_Counter, которые появятся на шаге 2. До тех пор
 * смоук-тесты file_exists падают — это ожидаемый red.
 *
 * API-контракт:
 *   increment(string $scope_key, int $window_seconds, int $limit): array{
 *       hits: int,        // количество обращений в текущем окне (включая этот)
 *       allowed: bool,    // true если hits <= limit
 *       reset_at: int,    // unix timestamp окончания окна
 *   }
 *
 * Гарантии:
 *  - Атомарный INSERT ... ON DUPLICATE KEY UPDATE на PRIMARY KEY(scope_key).
 *  - При истечении окна (NOW > window_started_at + window_seconds) — сброс hits=1.
 *  - При concurrent-interleaving двух PDO-соединений потерь инкрементов нет.
 *
 * Integration-ветка (sequential / concurrent / expiry) требует env-переменных
 * как DedupSchemaIntegrationTest: CASHBACK_TEST_DB_HOST, CASHBACK_TEST_DB_PORT,
 * CASHBACK_TEST_DB_USER, CASHBACK_TEST_DB_PASSWORD, CASHBACK_TEST_DB_NAME.
 * При отсутствии — markTestSkipped (оставим file_exists sentinels как red).
 */
#[Group('rate-limit')]
#[Group('rate-limit-integration')]
final class RateLimiterConcurrencyTest extends TestCase
{
    private const TABLE_NAME = 'cb_test_rate_counters';

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
        parent::tearDown();
    }

    /**
     * Red-sentinel: файл интерфейса должен быть создан на шаге 2.
     * Не требует БД — падает независимо от окружения.
     */
    public function test_counter_interface_file_is_expected_to_exist(): void
    {
        $file = dirname(__DIR__, 3) . '/includes/rate-limit/interface-cashback-rate-limit-counter.php';

        $this->assertFileExists(
            $file,
            'Cashback_Rate_Limit_Counter_Interface должен появиться на шаге 2 Группы 7.'
        );
    }

    /**
     * Red-sentinel: файл SQL-реализации должен быть создан на шаге 2.
     */
    public function test_sql_counter_file_is_expected_to_exist(): void
    {
        $file = dirname(__DIR__, 3) . '/includes/rate-limit/class-cashback-rate-limit-sql-counter.php';

        $this->assertFileExists(
            $file,
            'Cashback_Rate_Limit_SQL_Counter должен появиться на шаге 2 Группы 7.'
        );
    }

    /**
     * Последовательные increment() внутри одного окна: limit=5 → первые 5 allowed=true,
     * 6-й allowed=false, hits растёт монотонно, reset_at постоянен внутри окна.
     */
    public function test_sequential_increments_enforce_limit_and_monotonic_hits(): void
    {
        $this->require_counter_class();
        $this->require_db();
        $this->create_temporary_table($this->pdo, self::TABLE_NAME);

        $counter   = $this->make_counter($this->pdo, self::TABLE_NAME);
        $scope_key = 'test_seq_' . bin2hex(random_bytes(4));
        $reset_at  = null;

        for ($i = 1; $i <= 5; $i++) {
            $result = $counter->increment($scope_key, 60, 5);
            $this->assertTrue($result['allowed'], "Вызов #{$i}: ожидался allowed=true внутри лимита.");
            $this->assertSame($i, $result['hits'], "Вызов #{$i}: hits должен быть {$i}.");
            $reset_at ??= $result['reset_at'];
            $this->assertSame($reset_at, $result['reset_at'], 'reset_at должен быть стабилен внутри окна.');
        }

        $over = $counter->increment($scope_key, 60, 5);
        $this->assertFalse($over['allowed'], 'Превышение лимита должно дать allowed=false.');
        $this->assertSame(6, $over['hits'], 'hits продолжает расти после превышения (для аудита).');
    }

    /**
     * Атомарность при параллельных INSERT ... ON DUPLICATE KEY UPDATE с двух
     * независимых PDO-соединений. MariaDB сериализует запись на PK → сумма hits
     * на interleaved 2*6 = 12 инкрементах должна быть ровно 12.
     */
    public function test_concurrent_increments_from_two_connections_are_atomic(): void
    {
        $this->require_counter_class();
        $this->require_db();

        // Обычная (не TEMPORARY) таблица — видна между двумя connection'ами.
        $shared_table = self::TABLE_NAME . '_shared_' . bin2hex(random_bytes(3));
        $this->create_persistent_table($this->pdo, $shared_table);

        try {
            $pdo2 = $this->fresh_connection();

            $counter_a = $this->make_counter($this->pdo, $shared_table);
            $counter_b = $this->make_counter($pdo2, $shared_table);

            $scope_key = 'test_concur_' . bin2hex(random_bytes(4));
            $last_b    = 0;

            // Interleaved A,B × 6 = 12 инкрементов.
            for ($i = 0; $i < 6; $i++) {
                $counter_a->increment($scope_key, 60, 100);
                $rb     = $counter_b->increment($scope_key, 60, 100);
                $last_b = $rb['hits'];
            }

            $this->assertSame(
                12,
                $last_b,
                'Атомарный INSERT ... ON DUPLICATE KEY UPDATE должен сохранить все 12 инкрементов.'
            );
        } finally {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $shared_table);
        }
    }

    /**
     * Окно сбрасывается при истечении: после истёкшего window hits=1, reset_at > prev.
     */
    public function test_window_resets_after_expiry(): void
    {
        $this->require_counter_class();
        $this->require_db();
        $this->create_temporary_table($this->pdo, self::TABLE_NAME);

        $counter   = $this->make_counter($this->pdo, self::TABLE_NAME);
        $scope_key = 'test_exp_' . bin2hex(random_bytes(4));

        $first = $counter->increment($scope_key, 1, 5);
        $this->assertSame(1, $first['hits']);

        sleep(2);

        $after = $counter->increment($scope_key, 1, 5);
        $this->assertSame(1, $after['hits'], 'Окно истекло → hits сбрасывается к 1.');
        $this->assertTrue($after['allowed']);
        $this->assertGreaterThan($first['reset_at'], $after['reset_at'], 'Новое окно → новый reset_at.');
    }

    // ──────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────

    private function require_counter_class(): void
    {
        $plugin_root    = dirname(__DIR__, 3);
        $interface_file = $plugin_root . '/includes/rate-limit/interface-cashback-rate-limit-counter.php';
        $impl_file      = $plugin_root . '/includes/rate-limit/class-cashback-rate-limit-sql-counter.php';

        if (!file_exists($interface_file) || !file_exists($impl_file)) {
            $this->fail(
                'Cashback_Rate_Limit_SQL_Counter / Cashback_Rate_Limit_Counter_Interface ещё не реализованы '
                . '(ожидается шаг 2 Группы 7).'
            );
        }

        require_once $interface_file;
        require_once $impl_file;
    }

    private function require_db(): void
    {
        $host = getenv('CASHBACK_TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('CASHBACK_TEST_DB_PORT') ?: '3306';
        $user = getenv('CASHBACK_TEST_DB_USER');
        $pass = getenv('CASHBACK_TEST_DB_PASSWORD');
        $name = getenv('CASHBACK_TEST_DB_NAME');

        if (!is_string($user) || $user === '' || !is_string($name) || $name === '') {
            $this->markTestSkipped('CASHBACK_TEST_DB_USER / CASHBACK_TEST_DB_NAME не заданы.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            $this->pdo = new PDO($dsn, $user, is_string($pass) ? $pass : '', array(
                PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES  => false,
            ));
        } catch (\PDOException $e) {
            $this->markTestSkipped('MariaDB недоступна: ' . $e->getMessage());
        }
    }

    private function fresh_connection(): PDO
    {
        $host = getenv('CASHBACK_TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('CASHBACK_TEST_DB_PORT') ?: '3306';
        $user = getenv('CASHBACK_TEST_DB_USER');
        $pass = getenv('CASHBACK_TEST_DB_PASSWORD');
        $name = getenv('CASHBACK_TEST_DB_NAME');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        return new PDO($dsn, (string) $user, is_string($pass) ? $pass : '', array(
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    }

    private function create_temporary_table(PDO $pdo, string $name): void
    {
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS ' . $name);
        $pdo->exec(
            'CREATE TEMPORARY TABLE ' . $name . ' (
                scope_key VARCHAR(64) NOT NULL,
                window_started_at INT UNSIGNED NOT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 0,
                expires_at INT UNSIGNED NOT NULL,
                PRIMARY KEY (scope_key),
                KEY idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function create_persistent_table(PDO $pdo, string $name): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . $name);
        $pdo->exec(
            'CREATE TABLE ' . $name . ' (
                scope_key VARCHAR(64) NOT NULL,
                window_started_at INT UNSIGNED NOT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 0,
                expires_at INT UNSIGNED NOT NULL,
                PRIMARY KEY (scope_key),
                KEY idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function make_counter(PDO $pdo, string $table): object
    {
        /** @var object $counter */
        $counter = new \Cashback_Rate_Limit_SQL_Counter($pdo, $table);

        return $counter;
    }
}
