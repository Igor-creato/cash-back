<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistent device IDs (evercookie-pattern) + FingerprintJS visitor IDs.
 *
 * Параллельная замена самописному cashback_user_fingerprints. Старая таблица
 * сохраняется для обратной совместимости с check_shared_fingerprint, новая
 * хранит стабильный device_id, который выживает clear-cookies (восстанавливается
 * клиентом из IndexedDB/LocalStorage).
 *
 * @since 1.3.0
 */
class Cashback_Fraud_Device_Id {

    /**
     * Имя таблицы без префикса.
     */
    private const TABLE = 'cashback_fraud_device_ids';

    /**
     * Default retention при отсутствии Cashback_Fraud_Settings.
     */
    private const DEFAULT_RETENTION_DAYS = 180;

    /**
     * Полное имя таблицы с $wpdb->prefix.
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Валидация UUID v4 (RFC 4122 формат с версией 4 + variant 8/9/a/b).
     *
     * @param string $uuid Строка для проверки
     */
    public static function validate_uuid_v4( string $uuid ): bool {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    /**
     * Валидация visitor_id из FingerprintJS — alphanumeric, до 64 символов.
     */
    private static function validate_visitor_id( string $visitor_id ): bool {
        return $visitor_id !== '' && (bool) preg_match('/^[A-Za-z0-9]{1,64}$/', $visitor_id);
    }

    /**
     * Валидация SHA-256 hex.
     */
    private static function is_sha256( ?string $hash ): bool {
        return is_string($hash) && (bool) preg_match('/^[a-f0-9]{64}$/i', $hash);
    }

    /**
     * Запись/обновление device-сессии.
     *
     * UPSERT-семантика: запись с одинаковыми (device_id, visitor_id, ip_address)
     * за сегодня обновляет last_seen вместо вставки новой строки. Всё в транзакции
     * с FOR UPDATE — защита от race между параллельными запросами одного устройства.
     *
     * @param array<string, mixed> $payload     ['device_id', 'visitor_id', 'fingerprint_hash', 'components_hash', 'confidence']
     * @param int|null             $user_id     ID авторизованного юзера или null для guest
     * @param string               $ip          IP клиента
     * @param string               $user_agent  Сырой User-Agent (хешируется внутри)
     * @return int|false ID записи, либо false при ошибке/невалидных данных
     */
    public static function record(
        array $payload,
        ?int $user_id,
        string $ip,
        string $user_agent
    ) {
        global $wpdb;

        $device_id = isset($payload['device_id']) ? (string) $payload['device_id'] : '';
        if (!self::validate_uuid_v4($device_id)) {
            return false;
        }

        $visitor_id = isset($payload['visitor_id']) ? trim((string) $payload['visitor_id']) : '';
        if ($visitor_id !== '' && !self::validate_visitor_id($visitor_id)) {
            $visitor_id = ''; // Невалидный visitor_id — записываем без него, но не reject device.
        }
        $visitor_id_db = $visitor_id !== '' ? $visitor_id : null;

        $fingerprint_hash = isset($payload['fingerprint_hash']) ? (string) $payload['fingerprint_hash'] : null;
        if (!self::is_sha256($fingerprint_hash)) {
            $fingerprint_hash = null;
        }

        $components_hash = isset($payload['components_hash']) ? (string) $payload['components_hash'] : null;
        if (!self::is_sha256($components_hash)) {
            $components_hash = null;
        }

        $confidence = null;
        if (isset($payload['confidence']) && is_numeric($payload['confidence'])) {
            $score = (float) $payload['confidence'];
            if ($score >= 0.0 && $score <= 1.0) {
                $confidence = round($score, 2);
            }
        }

        // IP-валидация: REMOTE_ADDR может прийти "0.0.0.0" из collector — оставляем как есть,
        // поскольку IP-фильтрацию делает upstream-вызов (collector::get_client_ip).
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }
        if (strlen($ip) > 45) {
            return false;
        }

        $user_agent_hash = hash('sha256', $user_agent !== '' ? $user_agent : 'unknown');

        // IP-Intelligence (Этап 2): graceful degradation, если класс ещё не задеплоен.
        $asn             = null;
        $connection_type = null;
        if (class_exists('Cashback_Fraud_Ip_Intelligence')) {
            try {
                $info = Cashback_Fraud_Ip_Intelligence::classify($ip);
                if (is_array($info)) {
                    $asn             = isset($info['asn']) ? (int) $info['asn'] : null;
                    $connection_type = isset($info['type']) ? substr((string) $info['type'], 0, 32) : null;
                }
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback Fraud Device ID: IP intelligence failed — ' . $e->getMessage());
            }
        }

        $table = self::table();
        $now   = current_time('mysql');

        // FOR UPDATE требует транзакции; SELECT-then-UPDATE/INSERT защищён от race.
        // Дедупликация: одна запись на сутки для тройки (device_id, visitor_id, ip_address).
        $wpdb->query('START TRANSACTION');

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i
             WHERE device_id = %s
             AND ((visitor_id = %s) OR (visitor_id IS NULL AND %s = ''))
             AND ip_address = %s
             AND last_seen >= DATE_SUB(NOW(), INTERVAL 1 DAY)
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE",
            $table,
            $device_id,
            $visitor_id,
            $visitor_id,
            $ip
        ));

        if ($existing_id > 0) {
            // UPDATE: обновляем last_seen, user_id (если был null — ставим текущий),
            // и обогащаем мета-полями (visitor_id мог появиться позже первого визита).
            $update_data = array(
                'last_seen'        => $now,
                'user_agent_hash'  => $user_agent_hash,
            );
            $update_format = array('%s', '%s');

            if ($user_id !== null && $user_id > 0) {
                $update_data['user_id'] = $user_id;
                $update_format[]        = '%d';
            }
            if ($fingerprint_hash !== null) {
                $update_data['fingerprint_hash'] = $fingerprint_hash;
                $update_format[]                 = '%s';
            }
            if ($components_hash !== null) {
                $update_data['components_hash'] = $components_hash;
                $update_format[]                = '%s';
            }
            if ($confidence !== null) {
                $update_data['confidence_score'] = $confidence;
                $update_format[]                 = '%f';
            }
            if ($asn !== null) {
                $update_data['asn'] = $asn;
                $update_format[]    = '%d';
            }
            if ($connection_type !== null) {
                $update_data['connection_type'] = $connection_type;
                $update_format[]                = '%s';
            }

            $result = $wpdb->update(
                $table,
                $update_data,
                array('id' => $existing_id),
                $update_format,
                array('%d')
            );

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback Fraud Device ID: UPDATE failed — ' . $wpdb->last_error);
                return false;
            }

            $wpdb->query('COMMIT');
            return $existing_id;
        }

        $wpdb->insert(
            $table,
            array(
                'device_id'        => $device_id,
                'visitor_id'       => $visitor_id_db,
                'user_id'          => ( $user_id !== null && $user_id > 0 ) ? $user_id : null,
                'fingerprint_hash' => $fingerprint_hash,
                'components_hash'  => $components_hash,
                'ip_address'       => $ip,
                'asn'              => $asn,
                'connection_type'  => $connection_type,
                'user_agent_hash'  => $user_agent_hash,
                'confidence_score' => $confidence,
                'first_seen'       => $now,
                'last_seen'        => $now,
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%f', '%s', '%s')
        );

        if ($wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Fraud Device ID: INSERT failed — ' . $wpdb->last_error);
            return false;
        }

        $insert_id = (int) $wpdb->insert_id;
        $wpdb->query('COMMIT');

        return $insert_id > 0 ? $insert_id : false;
    }

    /**
     * Все записи по device_id (для проверки shared device у нескольких юзеров).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function find_by_device( string $device_id ): array {
        global $wpdb;
        if (!self::validate_uuid_v4($device_id)) {
            return array();
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE device_id = %s ORDER BY last_seen DESC',
            self::table(),
            $device_id
        ), ARRAY_A);
    }

    /**
     * Все записи по visitor_id.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function find_by_visitor( string $visitor_id ): array {
        global $wpdb;
        if (!self::validate_visitor_id($visitor_id)) {
            return array();
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE visitor_id = %s ORDER BY last_seen DESC',
            self::table(),
            $visitor_id
        ), ARRAY_A);
    }

    /**
     * Последние N device_ids конкретного юзера (для UI и detector-сигналов).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_user_devices( int $user_id, int $limit = 10 ): array {
        global $wpdb;
        if ($user_id <= 0) {
            return array();
        }
        $limit = max(1, min(100, $limit));
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE user_id = %d ORDER BY last_seen DESC LIMIT %d',
            self::table(),
            $user_id,
            $limit
        ), ARRAY_A);
    }

    /**
     * Сколько разных user_id привязано к одному device_id за окно ($days).
     * Composite signal: >1 → подозрение на multi-account.
     */
    public static function count_users_per_device( string $device_id, int $days = 30 ): int {
        global $wpdb;
        if (!self::validate_uuid_v4($device_id)) {
            return 0;
        }
        $days = max(1, $days);
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM %i
             WHERE device_id = %s
             AND user_id IS NOT NULL
             AND last_seen >= DATE_SUB(NOW(), INTERVAL %d DAY)',
            self::table(),
            $device_id,
            $days
        ));
    }

    /**
     * Distinct IPs одного device за окно ($hours). Сигнал device_multiple_ips:
     * нормальный юзер не меняет 6+ IP за час, бот с прокси-ротацией — да.
     *
     * @return array<int, array{ip: string, asn: int|null, last_seen: string}>
     */
    public static function count_distinct_ips_per_device( string $device_id, int $hours = 24 ): array {
        global $wpdb;
        if (!self::validate_uuid_v4($device_id)) {
            return array();
        }
        $hours = max(1, $hours);

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT ip_address AS ip, MAX(asn) AS asn, MAX(last_seen) AS last_seen
             FROM %i
             WHERE device_id = %s
             AND last_seen >= DATE_SUB(NOW(), INTERVAL %d HOUR)
             GROUP BY ip_address
             ORDER BY last_seen DESC',
            self::table(),
            $device_id,
            $hours
        ), ARRAY_A);

        return array_map(
            static function ( array $r ): array {
                return array(
                    'ip'        => (string) $r['ip'],
                    'asn'       => ( $r['asn'] !== null && $r['asn'] !== '' ) ? (int) $r['asn'] : null,
                    'last_seen' => (string) $r['last_seen'],
                );
            },
            $rows
        );
    }

    /**
     * Удаление старых записей по last_seen — wp-cron очистка.
     * Использует общий retention из Cashback_Fraud_Settings, либо дефолт.
     *
     * @return int Количество удалённых строк
     */
    public static function purge_old( ?int $retention_days = null ): int {
        global $wpdb;

        if ($retention_days === null) {
            $retention_days = class_exists('Cashback_Fraud_Settings')
                ? Cashback_Fraud_Settings::get_fingerprint_retention_days()
                : self::DEFAULT_RETENTION_DAYS;
        }
        $retention_days = max(1, (int) $retention_days);

        $deleted = $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE last_seen < DATE_SUB(NOW(), INTERVAL %d DAY)',
            self::table(),
            $retention_days
        ));

        return is_int($deleted) ? $deleted : 0;
    }
}
