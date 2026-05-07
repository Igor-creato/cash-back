<?php
/**
 * Репозиторий лога импорта магазинов (v12).
 *
 * Каждый запуск Cashback_Shop_Importer::run() пишет одну row на страницу
 * (run_id группирует все страницы одного запуска для admin UI прогресса).
 *
 * Жизненный цикл записи:
 *   start_page()    → INSERT (started_at = now, finished_at = NULL)
 *   update_progress() → UPDATE счётчиков fetched/upserted/tariffs_synced
 *   finish_page()   → UPDATE finished_at = now [+ errors при failure]
 *
 * Retention: gc_old() удаляет log-rows старше N дней (default 30).
 *
 * @package CashbackPlugin
 * @since   12.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Shop_Import_Log {

    public const TABLE = 'cashback_shop_import_log';

    /**
     * Сгенерировать новый run_id (UUIDv7 без дефисов).
     *
     * Один run_id на всё многостраничное запускание импорта; каждая страница
     * пишет отдельную row, но связывается через run_id.
     */
    public static function generate_run_id(): string {
        if (function_exists('cashback_generate_uuid7')) {
            return cashback_generate_uuid7(false);
        }
        // Fallback на uuid4 без дефисов — менее монотонно, но валидно.
        if (function_exists('wp_generate_uuid4')) {
            return str_replace('-', '', wp_generate_uuid4());
        }
        return bin2hex(random_bytes(16));
    }

    /**
     * Открыть запись страницы импорта (status=running, started_at=NOW).
     *
     * @return int log_id (для update_progress / finish_page); 0 при ошибке INSERT.
     */
    public static function start_page( string $run_id, int $network_id, int $page ): int {
        global $wpdb;

        if ($run_id === '' || $network_id <= 0) {
            return 0;
        }

        $table = $wpdb->prefix . self::TABLE;

        $ok = $wpdb->insert(
            $table,
            array(
                'run_id'         => $run_id,
                'network_id'     => $network_id,
                'page'           => max(0, $page),
                'fetched'        => 0,
                'upserted_new'   => 0,
                'upserted_upd'   => 0,
                'tariffs_synced' => 0,
                'errors'         => null,
                'started_at'     => self::now_mysql(),
                'finished_at'    => null,
            ),
            array( '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
        );

        if ($ok === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Shop Import Log] start_page INSERT failed: ' . $wpdb->last_error);
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Обновить счётчики прогресса страницы. Безопасно вызывать многократно
     * (UPDATE по PK).
     */
    public static function update_progress(
        int $log_id,
        int $fetched,
        int $upserted_new,
        int $upserted_upd,
        int $tariffs_synced
    ): void {
        if ($log_id <= 0) {
            return;
        }
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        $wpdb->update(
            $table,
            array(
                'fetched'        => max(0, $fetched),
                'upserted_new'   => max(0, $upserted_new),
                'upserted_upd'   => max(0, $upserted_upd),
                'tariffs_synced' => max(0, $tariffs_synced),
            ),
            array( 'id' => $log_id ),
            array( '%d', '%d', '%d', '%d' ),
            array( '%d' )
        );
    }

    /**
     * Закрыть страницу импорта.
     *
     * @param string|null $errors Сообщение об ошибке или null при успехе.
     */
    public static function finish_page( int $log_id, ?string $errors = null ): void {
        if ($log_id <= 0) {
            return;
        }
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        $wpdb->update(
            $table,
            array(
                'finished_at' => self::now_mysql(),
                'errors'      => $errors,
            ),
            array( 'id' => $log_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Получить последние N row для admin UI (фильтр по network_id опционален).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent( ?int $network_id = null, int $limit = 50 ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;
        $limit = max(1, min(500, $limit));

        if ($network_id !== null && $network_id > 0) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE network_id = %d ORDER BY started_at DESC LIMIT %d',
                    $table,
                    $network_id,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i ORDER BY started_at DESC LIMIT %d',
                    $table,
                    $limit
                ),
                ARRAY_A
            );
        }

        return is_array($rows) ? $rows : array();
    }

    /**
     * Общее количество row (опционально по network_id) — для пагинации admin UI.
     */
    public static function count_total( ?int $network_id = null ): int {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        if ($network_id !== null && $network_id > 0) {
            return (int) $wpdb->get_var(
                $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE network_id = %d', $table, $network_id)
            );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i', $table)
        );
    }

    /**
     * Постраничная выборка row (для admin UI пагинации).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paginate( ?int $network_id, int $per_page, int $offset ): array {
        global $wpdb;

        $table    = $wpdb->prefix . self::TABLE;
        $per_page = max(1, min(200, $per_page));
        $offset   = max(0, $offset);

        if ($network_id !== null && $network_id > 0) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE network_id = %d ORDER BY started_at DESC LIMIT %d OFFSET %d',
                    $table,
                    $network_id,
                    $per_page,
                    $offset
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i ORDER BY started_at DESC LIMIT %d OFFSET %d',
                    $table,
                    $per_page,
                    $offset
                ),
                ARRAY_A
            );
        }

        return is_array($rows) ? $rows : array();
    }

    /**
     * Получить все row одного run_id (для admin UI прогресса конкретного запуска).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_run( string $run_id ): array {
        global $wpdb;

        if ($run_id === '') {
            return array();
        }

        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE run_id = %s ORDER BY page ASC',
                $table,
                $run_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Удалить лог-row старше N дней. Возвращает количество удалённых.
     */
    public static function gc_old( int $days = 30 ): int {
        global $wpdb;

        $days   = max(1, $days);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $table  = $wpdb->prefix . self::TABLE;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE started_at < %s',
                $table,
                $cutoff
            )
        );

        return is_numeric($deleted) ? (int) $deleted : 0;
    }

    /**
     * Текущее UTC-время в MySQL-формате.
     */
    private static function now_mysql(): string {
        if (class_exists('Cashback_Time') && method_exists('Cashback_Time', 'now_mysql')) {
            return Cashback_Time::now_mysql();
        }
        return gmdate('Y-m-d H:i:s');
    }
}
