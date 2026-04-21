<?php

/**
 * SQL-реализация rate-limit counter на MariaDB/MySQL через
 * INSERT ... ON DUPLICATE KEY UPDATE с условным сбросом окна (Группа 7 ADR, шаг 2).
 *
 * Атомарность обеспечивается PRIMARY KEY (scope_key) — MariaDB сериализует запись
 * на уровне строки и гарантирует отсутствие потерь инкрементов при параллельных
 * клиентах (тест: RateLimiterConcurrencyTest::test_concurrent_increments_*).
 *
 * Backend-адаптер:
 *   - PDO       — для интеграционных тестов (RateLimiterConcurrencyTest);
 *   - wpdb      — для production-окружения.
 * Detection производится по instanceof/duck-typing в конструкторе.
 *
 * @package Cashback
 * @since   1.0.0 (Group 7)
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/interface-cashback-rate-limit-counter.php';

final class Cashback_Rate_Limit_SQL_Counter implements Cashback_Rate_Limit_Counter_Interface {

    private const MODE_PDO  = 'pdo';
    private const MODE_WPDB = 'wpdb';

    private string $mode;
    private object $db;
    private string $table;

    /**
     * @param object $db    PDO-instance ИЛИ $wpdb-совместимый объект
     *                      (с методами query/prepare/get_row). Тип проверяется в рантайме.
     * @param string $table Имя таблицы counters (production: {$wpdb->prefix}cashback_rate_limit_counters).
     *
     * @throws \InvalidArgumentException при неподдерживаемом типе $db или пустом $table.
     */
    public function __construct( object $db, string $table ) {
        if ($table === '') {
            throw new \InvalidArgumentException('table name must not be empty');
        }

        if ($db instanceof \PDO) {
            $this->mode = self::MODE_PDO;
        } elseif (method_exists($db, 'query') && method_exists($db, 'prepare') && method_exists($db, 'get_row')) {
            $this->mode = self::MODE_WPDB;
        } else {
            throw new \InvalidArgumentException(
                'Unsupported db object: expected PDO or wpdb-compatible (must have query/prepare/get_row methods)'
            );
        }

        $this->db    = $db;
        $this->table = $table;
    }

    public function increment( string $scope_key, int $window_seconds, int $limit ): array {
        if ($scope_key === '') {
            throw new \InvalidArgumentException('scope_key must not be empty');
        }
        if ($window_seconds <= 0) {
            throw new \InvalidArgumentException('window_seconds must be positive');
        }
        if ($limit < 0) {
            throw new \InvalidArgumentException('limit must be non-negative');
        }
        if (strlen($scope_key) > 64) {
            throw new \InvalidArgumentException('scope_key exceeds 64 chars (VARCHAR(64) PK limit)');
        }

        $now     = time();
        $expires = $now + $window_seconds;
        $table   = $this->quote_identifier($this->table);

        // $window_seconds интерполируется как integer — PDO не переиспользует
        // позиционные плейсхолдеры при ATTR_EMULATE_PREPARES=false, а writer-логика
        // IF(...) требует ту же константу 3 раза.
        $upsert_sql = sprintf(
            'INSERT INTO %s (scope_key, window_started_at, hits, expires_at)
                 VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                 hits              = IF(window_started_at + %d <= VALUES(window_started_at), 1, hits + 1),
                 window_started_at = IF(window_started_at + %d <= VALUES(window_started_at), VALUES(window_started_at), window_started_at),
                 expires_at        = IF(window_started_at + %d <= VALUES(window_started_at), VALUES(expires_at), expires_at)',
            $table,
            $window_seconds,
            $window_seconds,
            $window_seconds
        );

        $select_sql = sprintf(
            'SELECT hits, window_started_at, expires_at FROM %s WHERE scope_key = ?',
            $table
        );

        $row = $this->mode === self::MODE_PDO
            ? $this->run_pdo($upsert_sql, $select_sql, $scope_key, $now, $expires)
            : $this->run_wpdb($upsert_sql, $select_sql, $scope_key, $now, $expires);

        if ($row === null) {
            throw new \RuntimeException('rate-limit counter row not found after upsert');
        }

        $hits = (int) $row['hits'];

        return array(
            'hits'     => $hits,
            'allowed'  => $hits <= $limit,
            'reset_at' => (int) $row['expires_at'],
        );
    }

    /**
     * @return array{hits:int|string, window_started_at:int|string, expires_at:int|string}|null
     */
    private function run_pdo( string $upsert_sql, string $select_sql, string $scope_key, int $now, int $expires ): ?array {
        /** @var \PDO $pdo */
        $pdo = $this->db;

        $stmt = $pdo->prepare($upsert_sql);
        $stmt->execute(array( $scope_key, $now, $expires ));

        $read = $pdo->prepare($select_sql);
        $read->execute(array( $scope_key ));
        $row = $read->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array{hits:int|string, window_started_at:int|string, expires_at:int|string}|null
     */
    private function run_wpdb( string $upsert_sql, string $select_sql, string $scope_key, int $now, int $expires ): ?array {
        $wpdb = $this->db;

        // wpdb->prepare использует sprintf-style плейсхолдеры %s/%d → UPSERT уже
        // содержит `?` в VALUES-секции (для PDO). Конвертируем ? → %s/%d для wpdb.
        $upsert_wp = $this->to_wpdb_placeholders($upsert_sql, array( 's', 'd', 'd' ));
        $select_wp = $this->to_wpdb_placeholders($select_sql, array( 's' ));

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $upsert_wp/$select_wp — prepared-стейтменты, таблица белый-листом через quote_identifier.
        $prepared_upsert = $wpdb->prepare($upsert_wp, $scope_key, $now, $expires);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($prepared_upsert);

        $prepared_select = $wpdb->prepare($select_wp, $scope_key);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($prepared_select, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Конвертирует PDO-style `?` плейсхолдеры в wpdb-style (`%s`/`%d`).
     *
     * @param list<string> $types Тип для каждого `?` по порядку: 's' → %s, 'd' → %d.
     */
    private function to_wpdb_placeholders( string $sql, array $types ): string {
        $i = 0;
        return (string) preg_replace_callback(
            '/\?/',
            static function () use ( &$i, $types ): string {
                $type = $types[ $i ] ?? 's';
                $i++;
                return $type === 'd' ? '%d' : '%s';
            },
            $sql
        );
    }

    /**
     * Обрамляет имя таблицы backtick-ами, убирая любые внутренние backtick'и.
     * $this->table приходит из конфига/кода, но backticks-strip на всякий случай —
     * defense-in-depth против случайных user-controlled путей.
     */
    private function quote_identifier( string $name ): string {
        return '`' . str_replace('`', '', $name) . '`';
    }
}
