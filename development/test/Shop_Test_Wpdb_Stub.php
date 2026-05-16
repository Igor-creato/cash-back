<?php
/**
 * Тестовый in-memory $wpdb-stub для Shop Importer / Tariff Sync / Import Log тестов.
 *
 * Файл расположен в development/test/ (не в tests/) и НЕ имеет суффикса Test.php,
 * поэтому PHPUnit его не auto-discover'ит. Подключается явно через require_once
 * из тестов, которые мокают $wpdb.
 *
 * Stub:
 *  - Захватывает inserts / updates / queries в публичные массивы — тест ассертит.
 *  - prepare() заменяет %d, %s, %f, %i (минимальная имитация wpdb для assertion'ов).
 *  - get_var / get_results / get_row возвращают значения из публичных next_*
 *    свойств, которые тест выставляет перед вызовом.
 *  - fail_on_query_substring — если sql содержит substring, query() возвращает
 *    false и устанавливает last_error (для теста rollback-ветки).
 */

declare(strict_types=1);

if (class_exists('Shop_Test_Wpdb_Stub', false)) {
    return;
}

class Shop_Test_Wpdb_Stub
{
    public string $prefix = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public string $posts = 'wp_posts';
    public string $options = 'wp_options';
    public int $insert_id = 0;
    public string $last_error = '';

    /** Сколько rows вернуть из следующего update() (по умолчанию 1). Используется для теста CAS-throttle. */
    public int $next_update_affected_rows = 1;

    /** @var array<int, array<string, mixed>> */
    public array $inserts = array();

    /** @var array<int, array<string, mixed>> */
    public array $updates = array();

    /** @var array<int, array<string, mixed>> */
    public array $queries = array();

    public mixed $next_get_var = null;
    /** @var array<int, mixed> */
    public array $next_get_col = array();
    /** @var array<int, mixed> */
    public array $next_get_results = array();
    /** @var array<string, mixed>|null */
    public ?array $next_get_row = null;

    public ?string $fail_on_query_substring = null;

    private int $next_id = 1000;

    /**
     * Минимальная имитация wpdb::prepare для assertion'ов.
     */
    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $i = 0;
        return (string) preg_replace_callback(
            '/%[dsf]|%i/',
            static function ($m) use ($args, &$i) {
                $val = $args[$i++] ?? '';
                switch ($m[0]) {
                    case '%d':
                        return (string) (int) $val;
                    case '%f':
                        return (string) (float) $val;
                    case '%i':
                        return '`' . str_replace('`', '``', (string) $val) . '`';
                    case '%s':
                    default:
                        if ($val === null) {
                            return 'NULL';
                        }
                        return "'" . str_replace("'", "''", (string) $val) . "'";
                }
            },
            $query
        );
    }

    public function query(mixed $sql): mixed
    {
        $sql_str = is_string($sql) ? $sql : '';
        $this->queries[] = array('sql' => $sql_str);

        if ($this->fail_on_query_substring !== null && str_contains($sql_str, $this->fail_on_query_substring)) {
            $this->last_error = 'forced fail by stub';
            return false;
        }
        return 1;
    }

    public function insert(string $table, array $data, mixed $format = null): int
    {
        $this->inserts[] = array(
            'table'  => $table,
            'data'   => $data,
            'format' => $format,
        );
        $this->insert_id = ++$this->next_id;
        return 1;
    }

    public function update(
        string $table,
        array $data,
        array $where,
        mixed $format = null,
        mixed $where_format = null
    ): int {
        $this->updates[] = array(
            'table'        => $table,
            'data'         => $data,
            'where'        => $where,
            'format'       => $format,
            'where_format' => $where_format,
        );
        return $this->next_update_affected_rows;
    }

    public function delete(string $table, array $where, mixed $where_format = null): int
    {
        $this->updates[] = array(
            'table'        => $table,
            'data'         => array(),
            'where'        => $where,
            'format'       => null,
            'where_format' => $where_format,
            'op'           => 'delete',
        );
        return 1;
    }

    public function get_var(mixed $sql): mixed
    {
        // Advisory lock helper: production-код использует
        //   SELECT GET_LOCK('name', timeout)  / SELECT RELEASE_LOCK('name')
        // через $wpdb->get_var(). По умолчанию stub возвращает 1 (lock acquired)
        // чтобы тесты, не моделирующие конкуренцию, проходили обычный flow.
        // Чтобы протестировать lock_busy — тест задаёт next_get_var=0 явно.
        if ($this->next_get_var === null && is_string($sql)) {
            if (stripos($sql, 'GET_LOCK(') !== false || stripos($sql, 'RELEASE_LOCK(') !== false) {
                return 1;
            }
        }
        return $this->next_get_var;
    }

    public function get_col(mixed $sql, mixed $column_offset = 0): mixed
    {
        return $this->next_get_col;
    }

    public function get_results(mixed $sql, mixed $output = ARRAY_A): mixed
    {
        return $this->next_get_results;
    }

    public function get_row(mixed $sql, mixed $output = ARRAY_A): mixed
    {
        return $this->next_get_row;
    }
}
