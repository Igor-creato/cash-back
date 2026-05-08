<?php
/**
 * Cashback_Shop_Tariff_Sync — синхронизация тарифов одной кампании в
 * wp_cashback_shop_tariffs (v12).
 *
 * Получает массив Cashback_Shop_Tariff_DTO от adapter::fetch_shop_tariffs()
 * и upsert'ит каждый. Тарифы, не пришедшие в текущем payload, soft-deleted
 * через is_deleted=1 (не удаляются физически, чтобы сохранить аудит-связь
 * с уже начисленными транзакциями).
 *
 * Транзакционная: либо все тарифы сохранены, либо rollback на ошибке
 * первого UPDATE.
 *
 * @package CashbackPlugin
 * @since   12.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Shop_Tariff_Sync {

    public const TABLE = 'cashback_shop_tariffs';

    /**
     * Синхронизировать тарифы одной кампании.
     *
     * @param int                              $network_id ID сети (FK)
     * @param string                           $offer_id   ID кампании в сети
     * @param array<int, Cashback_Shop_Tariff_DTO> $tariffs Список тарифов из API
     * @return array{success: bool, upserted: int, soft_deleted: int, error: ?string}
     */
    public static function sync( int $network_id, string $offer_id, array $tariffs ): array {
        if ($network_id <= 0 || $offer_id === '') {
            return array(
                'success'      => false,
                'upserted'     => 0,
                'soft_deleted' => 0,
                'error'        => 'sync requires network_id > 0 and non-empty offer_id',
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Собираем активные tariff_id из payload — для NOT IN при soft-delete.
        $active_ids = array();
        foreach ($tariffs as $dto) {
            if ($dto instanceof Cashback_Shop_Tariff_DTO && $dto->tariff_id !== '') {
                $active_ids[] = $dto->tariff_id;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Транзакция оборачивает upsert + soft-delete.
        $wpdb->query('START TRANSACTION');

        try {
            $upserted = 0;

            foreach ($tariffs as $dto) {
                if (! ( $dto instanceof Cashback_Shop_Tariff_DTO )) {
                    continue;
                }
                if ($dto->tariff_id === '') {
                    continue;
                }

                $raw_payload = wp_json_encode($dto->raw);
                if ($raw_payload === false) {
                    $raw_payload = null;
                }

                $now = self::now_mysql();

                // INSERT … ON DUPLICATE KEY UPDATE (UNIQUE по network_id+offer_id+tariff_id).
                // Любой существующий ряд с теми же ключами получает is_deleted=0
                // и обновлённые поля; новый — INSERT.
                // payment_size биндится через '%s' (canonical decimal-string), а
                // НЕ '%f' — sprintf('%f', 5.5) на ru_RU локали даёт "5,500000",
                // и MariaDB читает это как 5.0 (truncate после запятой) → drift тарифа.
                $sql = $wpdb->prepare(
                    'INSERT INTO %i
                       (network_id, offer_id, tariff_id, name, tariff_type,
                        payment_size, payment_min, payment_max, currency,
                        is_default, is_deleted, raw_payload, imported_at, updated_at)
                     VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %d, 0, %s, %s, %s)
                     ON DUPLICATE KEY UPDATE
                        name         = VALUES(name),
                        tariff_type  = VALUES(tariff_type),
                        payment_size = VALUES(payment_size),
                        payment_min  = VALUES(payment_min),
                        payment_max  = VALUES(payment_max),
                        currency    = VALUES(currency),
                        is_default   = VALUES(is_default),
                        is_deleted   = 0,
                        raw_payload  = VALUES(raw_payload),
                        updated_at   = VALUES(updated_at)',
                    $table,
                    $network_id,
                    $offer_id,
                    $dto->tariff_id,
                    $dto->name,
                    $dto->tariff_type,
                    self::decimal_string($dto->payment_size),
                    self::nullable_float($dto->payment_min),
                    self::nullable_float($dto->payment_max),
                    $dto->currency,
                    $dto->is_default ? 1 : 0,
                    $raw_payload,
                    $now,
                    $now
                );

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql собран через wpdb->prepare выше, write-path в TX.
                $r = $wpdb->query($sql);
                if ($r === false) {
                    throw new \RuntimeException('upsert failed: ' . $wpdb->last_error);
                }
                ++$upserted;
            }

            // Soft-delete тарифов, не пришедших в payload.
            $soft_deleted = self::soft_delete_missing($wpdb, $table, $network_id, $offer_id, $active_ids);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- TX commit.
            $wpdb->query('COMMIT');

            return array(
                'success'      => true,
                'upserted'     => $upserted,
                'soft_deleted' => $soft_deleted,
                'error'        => null,
            );
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- TX rollback.
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Shop Tariff Sync] sync failed: ' . $e->getMessage());

            return array(
                'success'      => false,
                'upserted'     => 0,
                'soft_deleted' => 0,
                'error'        => $e->getMessage(),
            );
        }
    }

    /**
     * Получить активные тарифы кампании (is_deleted=0) — для рендера в карточке.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_active( int $network_id, string $offer_id ): array {
        if ($network_id <= 0 || $offer_id === '') {
            return array();
        }
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE network_id = %d AND offer_id = %s AND is_deleted = 0 ORDER BY payment_size DESC',
                $table,
                $network_id,
                $offer_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Soft-delete tariffs не пришедших в payload (is_deleted=1).
     *
     * @param object        $wpdb        WP database handler.
     * @param string        $table       Полное имя таблицы.
     * @param int           $network_id
     * @param string        $offer_id
     * @param array<string> $active_ids  Список tariff_id, которые ДОЛЖНЫ остаться is_deleted=0.
     * @return int Количество soft-deleted row.
     */
    private static function soft_delete_missing(
        $wpdb,
        string $table,
        int $network_id,
        string $offer_id,
        array $active_ids
    ): int {
        if (empty($active_ids)) {
            // Если payload пустой — пометить ВСЕ тарифы как удалённые.
            $sql = $wpdb->prepare(
                'UPDATE %i SET is_deleted = 1, updated_at = %s
                  WHERE network_id = %d AND offer_id = %s AND is_deleted = 0',
                $table,
                self::now_mysql(),
                $network_id,
                $offer_id
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above.
            $r = $wpdb->query($sql);
            return is_numeric($r) ? (int) $r : 0;
        }

        // NOT IN с динамическим списком — собираем prepared placeholder
        // отдельно per id (паттерн работает с любым количеством значений и
        // не вызывает PHPCS WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber).
        $prepared_ids = array();
        foreach ($active_ids as $tariff_id) {
            $prepared_ids[] = $wpdb->prepare('%s', $tariff_id);
        }
        $in_list = implode(', ', $prepared_ids);

        $base_sql = $wpdb->prepare(
            'UPDATE %i SET is_deleted = 1, updated_at = %s
              WHERE network_id = %d AND offer_id = %s
                AND is_deleted = 0
                AND tariff_id NOT IN (',
            $table,
            self::now_mysql(),
            $network_id,
            $offer_id
        );
        $sql = $base_sql . $in_list . ')';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $base_sql и $in_list собраны через wpdb->prepare выше; конкатенация безопасна.
        $r = $wpdb->query($sql);
        return is_numeric($r) ? (int) $r : 0;
    }

    /**
     * Сериализация nullable float для wpdb->prepare. Используется потому что
     * %f не поддерживает NULL — passes NULL через %s даёт '' что превращается
     * в 0.0 при INSERT в DECIMAL колонку. Решение: возвращать NULL → передаём
     * как '%s' с null значением (wpdb prepare сериализует null корючно
     * только при формате %s со строкой '').
     */
    private static function nullable_float( ?float $value ): ?string {
        return $value === null ? null : (string) $value;
    }

    /**
     * Locale-independent сериализация float для wpdb->prepare через '%s'.
     *
     * `(string) 5.5` в PHP 8+ возвращает "5.5" независимо от LC_NUMERIC.
     * `sprintf('%f', 5.5)` на ru_RU вернёт "5,500000" — это и есть баг,
     * который мы избегаем, не используя '%f' для денежных значений.
     */
    private static function decimal_string( float $value ): string {
        return (string) $value;
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
