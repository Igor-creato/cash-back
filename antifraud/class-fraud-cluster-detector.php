<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cluster Detection — периодический union-find проход по графу аккаунтов.
 *
 * Строит связи между user_id по device_id, visitor_id, payment hash, нормализованному
 * email и телефону. Сохраняет кластеры в cashback_fraud_account_clusters для admin-UI
 * и автоматических действий по high-confidence кластерам.
 *
 * @since 1.4.0
 */
class Cashback_Fraud_Cluster_Detector {

    /** Имя таблицы без префикса. */
    private const TABLE = 'cashback_fraud_account_clusters';

    /** Cron hook. */
    public const CRON_HOOK = 'cashback_fraud_cluster_detect_cron';

    /**
     * Сила связей для расчёта score.
     * - device_id / payment_hash — strong: persistent ID или одна банковская карта.
     * - visitor_id / phone — medium-strong: FingerprintJS stable ID / верифицированный телефон.
     * - email_normalized — medium: gmail dot/+ trick.
     * - fingerprint_hash — weak: legacy самописный fingerprint.
     */
    private const REASON_STRENGTH = array(
        'device_id'        => 30,
        'visitor_id'       => 25,
        'payment_hash'     => 30,
        'phone'            => 25,
        'email_normalized' => 15,
        'fingerprint_hash' => 10,
    );

    /** TTL «осиротевших» open-кластеров: если не воспроизводится 30+ дней — удаляем. */
    private const STALE_OPEN_DAYS = 30;

    /** Окно поиска device-связей. Длиннее retention device_ids бессмысленно. */
    private const DEVICE_WINDOW_DAYS = 180;

    /** Полное имя таблицы. */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Регистрация cron-хука. Вызывается из bootstrap плагина.
     */
    public static function register_cron(): void {
        add_action(self::CRON_HOOK, array( self::class, 'detect_cron_handler' ));
    }

    /**
     * Cron-обёртка с try/catch, чтобы один сбой не валил весь wp-cron tick.
     */
    public static function detect_cron_handler(): void {
        try {
            $stats = self::detect();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log(sprintf(
                'Cashback Fraud Cluster Detector: %d found, %d new, %d updated, %d stale removed, %dms',
                $stats['clusters_found'] ?? 0,
                $stats['clusters_new'] ?? 0,
                $stats['clusters_updated'] ?? 0,
                $stats['clusters_stale_removed'] ?? 0,
                $stats['duration_ms'] ?? 0
            ));
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Fraud Cluster Detector: cron failed — ' . $e->getMessage());
        }
    }

    /**
     * Основной алгоритм — собирает все рёбра, делает union-find, UPSERT кластеров.
     *
     * @return array{clusters_found:int, clusters_new:int, clusters_updated:int, clusters_stale_removed:int, duration_ms:int}
     */
    public static function detect(): array {
        global $wpdb;

        // Тумблер: позволяет отключить cluster-detection без отключения всего антифрода.
        // При выключенном тумблере cron делает no-op, существующие кластеры в БД остаются как есть.
        if (class_exists('Cashback_Fraud_Settings')
            && !Cashback_Fraud_Settings::is_cluster_detection_enabled()) {
            return array(
                'clusters_found'         => 0,
                'clusters_new'           => 0,
                'clusters_updated'       => 0,
                'clusters_stale_removed' => 0,
                'duration_ms'            => 0,
                'skipped'                => true,
            );
        }

        $start_ms = (int) ( microtime(true) * 1000 );

        // GROUP_CONCAT default 1024 байта — мало для крупных кластеров.
        $wpdb->query('SET SESSION group_concat_max_len = 65535');

        $edges = array();
        $edges = array_merge($edges, self::build_edges_by_device());
        $edges = array_merge($edges, self::build_edges_by_visitor());
        $edges = array_merge($edges, self::build_edges_by_payment_hash());
        $edges = array_merge($edges, self::build_edges_by_phone());
        $edges = array_merge($edges, self::build_edges_by_email_normalized());

        $clusters_groups = self::union_find_clusters($edges);

        // reasons по группе: каждый user_id входит в group → reasons этой group = все рёбра,
        // у которых ОБА конца принадлежат одной и той же группе.
        $user_to_group = array();
        foreach ($clusters_groups as $gi => $group_users) {
            foreach ($group_users as $uid) {
                $user_to_group[ $uid ] = $gi;
            }
        }

        $group_reasons = array_fill(0, count($clusters_groups), array());
        foreach ($edges as $e) {
            $g_a = $user_to_group[ $e['user_a'] ] ?? null;
            $g_b = $user_to_group[ $e['user_b'] ] ?? null;
            if ($g_a !== null && $g_a === $g_b) {
                $group_reasons[ $g_a ][] = array(
                    'reason' => $e['reason'],
                    'value'  => $e['value'],
                );
            }
        }

        $now           = Cashback_Time::now_mysql();
        $found_uids    = array();
        $stats_new     = 0;
        $stats_updated = 0;
        $table         = self::table();

        foreach ($clusters_groups as $gi => $group_users) {
            if (count($group_users) < 2) {
                continue;
            }

            sort($group_users);
            $group_users  = array_values(array_unique(array_map('intval', $group_users)));
            $cluster_uid  = self::generate_cluster_uid($group_users);
            $found_uids[] = $cluster_uid;

            // Дедуп reasons + strength для UI и score.
            $raw_reasons = $group_reasons[ $gi ] ?? array();
            $deduped     = self::dedupe_reasons($raw_reasons);
            $score       = self::compute_cluster_score($deduped);
            $primary     = self::determine_primary_reason($deduped);

            $reasons_json = wp_json_encode($deduped);
            $users_json   = wp_json_encode($group_users);

            // UPSERT: INSERT ... ON DUPLICATE KEY. NOT перезаписываем status/review-поля,
            // если admin уже отработал кластер.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, all values bound via prepare placeholders.
            $sql = $wpdb->prepare(
                "INSERT INTO %i
                 (cluster_uid, user_ids, user_count, link_reasons, score, primary_reason, detected_at, updated_at, status)
                 VALUES (%s, %s, %d, %s, %f, %s, %s, %s, 'open')
                 ON DUPLICATE KEY UPDATE
                    user_ids = VALUES(user_ids),
                    user_count = VALUES(user_count),
                    link_reasons = VALUES(link_reasons),
                    score = VALUES(score),
                    primary_reason = VALUES(primary_reason),
                    updated_at = VALUES(updated_at)",
                $table,
                $cluster_uid,
                $users_json,
                count($group_users),
                $reasons_json,
                $score,
                $primary,
                $now,
                $now
            );

            $existed_before = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE cluster_uid = %s LIMIT 1',
                $table,
                $cluster_uid
            ));

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql уже prepared выше.
            $wpdb->query($sql);

            if ($existed_before > 0) {
                ++$stats_updated;
            } else {
                ++$stats_new;
            }
        }

        // Удаление stale open-кластеров: есть в БД, но НЕ в текущем результате,
        // status='open', обнаружены давно — значит больше не воспроизводятся.
        $stale_removed = 0;
        if (!empty($found_uids)) {
            $placeholders = implode(',', array_fill(0, count($found_uids), '%s'));
            $params       = array_merge(array( $table ), $found_uids, array( self::STALE_OPEN_DAYS ));
            // $placeholders содержит только %s токены, $params покрывает все позиции (1 + N + 1).
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $sql_stale    = $wpdb->prepare(
                "DELETE FROM %i
                 WHERE status = 'open'
                 AND cluster_uid NOT IN ($placeholders)
                 AND detected_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $params
            );
            $deleted_rows = $wpdb->query($sql_stale);
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $stale_removed = is_int($deleted_rows) ? $deleted_rows : 0;
        } else {
            // Нет ни одного кластера в этом прогоне — чистим только давно-устаревшие open.
            $deleted_rows  = $wpdb->query($wpdb->prepare(
                "DELETE FROM %i
                 WHERE status = 'open'
                 AND detected_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $table,
                self::STALE_OPEN_DAYS
            ));
            $stale_removed = is_int($deleted_rows) ? $deleted_rows : 0;
        }

        $duration_ms = max(0, (int) ( microtime(true) * 1000 ) - $start_ms);

        return array(
            'clusters_found'         => count($found_uids),
            'clusters_new'           => $stats_new,
            'clusters_updated'       => $stats_updated,
            'clusters_stale_removed' => $stale_removed,
            'duration_ms'            => $duration_ms,
        );
    }

    /**
     * Нормализация email под gmail dot/+ trick и +suffix у других провайдеров.
     */
    public static function normalize_email( string $email ): string {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $email;
        }
        list($local, $domain) = $parts;

        $gmail_domains = array( 'gmail.com', 'googlemail.com' );

        // Все домены — обрезаем +suffix.
        $local = (string) preg_replace('/\+.*$/', '', $local);

        if (in_array($domain, $gmail_domains, true)) {
            $local  = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local . '@' . $domain;
    }

    /**
     * Нормализация телефона в E.164. Для 11-значных RU без + — добавляем +7
     * (перезаписывая ведущую 8 на 7).
     */
    public static function normalize_phone( string $phone ): string {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        $has_plus = ( $phone[0] ?? '' ) === '+';
        $digits   = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if ($has_plus) {
            return '+' . $digits;
        }
        // RU: 11 цифр, начинается с 7 или 8 → +7XXXXXXXXXX.
        if (strlen($digits) === 11 && ( $digits[0] === '7' || $digits[0] === '8' )) {
            return '+7' . substr($digits, 1);
        }
        // Прочее — отдаём как есть, пусть совпадает только с самим собой.
        return $digits;
    }

    /**
     * Получить кластеры для отображения в admin UI (Этап 6).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_clusters( int $limit = 50, int $offset = 0, ?string $status = null ): array {
        global $wpdb;
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        if ($status !== null && $status !== '') {
            return (array) $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM %i WHERE status = %s ORDER BY score DESC, updated_at DESC LIMIT %d OFFSET %d',
                self::table(),
                $status,
                $limit,
                $offset
            ), ARRAY_A);
        }

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i ORDER BY score DESC, updated_at DESC LIMIT %d OFFSET %d',
            self::table(),
            $limit,
            $offset
        ), ARRAY_A);
    }

    /**
     * Найти все кластеры, в которые входит данный user_id (для карточки юзера в админке).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function find_clusters_for_user( int $user_id ): array {
        global $wpdb;
        if ($user_id <= 0) {
            return array();
        }
        // user_ids — JSON массив. Для in-array поиска используем JSON_CONTAINS, что есть в MariaDB 10.2+.
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE JSON_CONTAINS(user_ids, %s) ORDER BY score DESC, updated_at DESC',
            self::table(),
            (string) $user_id
        ), ARRAY_A);
    }

    /**
     * Статистика для KPI dashboard.
     *
     * @return array{total_clusters:int, open:int, reviewing:int, confirmed:int, dismissed:int}
     */
    public static function get_stats(): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT status, COUNT(*) AS cnt FROM %i GROUP BY status',
            self::table()
        ), ARRAY_A);

        $stats = array(
            'total_clusters' => 0,
            'open'           => 0,
            'reviewing'      => 0,
            'confirmed'      => 0,
            'dismissed'      => 0,
        );
        foreach ($rows as $r) {
            $status                   = isset($r['status']) ? (string) $r['status'] : '';
            $cnt                      = isset($r['cnt']) ? (int) $r['cnt'] : 0;
            $stats['total_clusters'] += $cnt;
            if (isset($stats[ $status ])) {
                $stats[ $status ] = $cnt;
            }
        }
        return $stats;
    }

    /**
     * Изменение статуса кластера (admin action).
     */
    public static function update_status( int $cluster_id, string $status, int $reviewer_id, string $note = '' ): bool {
        global $wpdb;
        $allowed = array( 'open', 'reviewing', 'confirmed', 'dismissed' );
        if (!in_array($status, $allowed, true) || $cluster_id <= 0 || $reviewer_id <= 0) {
            return false;
        }
        $result = $wpdb->update(
            self::table(),
            array(
                'status'      => $status,
                'review_note' => $note !== '' ? $note : null,
                'reviewed_by' => $reviewer_id,
                'reviewed_at' => Cashback_Time::now_mysql(),
            ),
            array( 'id' => $cluster_id ),
            array( '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );
        return $result !== false && $result > 0;
    }

    // ================================================================
    // Edge builders
    // ================================================================

    /**
     * @return array<int, array{user_a:int, user_b:int, reason:string, value:string}>
     */
    private static function build_edges_by_device(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_fraud_device_ids';

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT device_id, GROUP_CONCAT(DISTINCT user_id ORDER BY user_id) AS user_ids
             FROM %i
             WHERE user_id IS NOT NULL
               AND last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
             GROUP BY device_id
             HAVING COUNT(DISTINCT user_id) > 1',
            $table,
            self::DEVICE_WINDOW_DAYS
        ), ARRAY_A);

        return self::expand_group_concat_to_edges($rows, 'device_id', 'device_id', 'user_ids');
    }

    /**
     * @return array<int, array{user_a:int, user_b:int, reason:string, value:string}>
     */
    private static function build_edges_by_visitor(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_fraud_device_ids';

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT visitor_id, GROUP_CONCAT(DISTINCT user_id ORDER BY user_id) AS user_ids
             FROM %i
             WHERE visitor_id IS NOT NULL
               AND visitor_id != %s
               AND user_id IS NOT NULL
               AND last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
             GROUP BY visitor_id
             HAVING COUNT(DISTINCT user_id) > 1',
            $table,
            '',
            self::DEVICE_WINDOW_DAYS
        ), ARRAY_A);

        return self::expand_group_concat_to_edges($rows, 'visitor_id', 'visitor_id', 'user_ids');
    }

    /**
     * @return array<int, array{user_a:int, user_b:int, reason:string, value:string}>
     */
    private static function build_edges_by_payment_hash(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_profile';

        // Защита: если таблицы вдруг нет (например, в окружении без основных миграций) —
        // пропускаем без падения.
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));
        if (!$exists) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Fraud Cluster Detector: payment hash edges skipped — table not present');
            return array();
        }

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT details_hash, GROUP_CONCAT(DISTINCT user_id ORDER BY user_id) AS user_ids
             FROM %i
             WHERE details_hash IS NOT NULL
               AND details_hash != ''
               AND status != 'deleted'
             GROUP BY details_hash
             HAVING COUNT(DISTINCT user_id) > 1",
            $table
        ), ARRAY_A);

        return self::expand_group_concat_to_edges($rows, 'payment_hash', 'details_hash', 'user_ids');
    }

    /**
     * @return array<int, array{user_a:int, user_b:int, reason:string, value:string}>
     */
    private static function build_edges_by_phone(): array {
        global $wpdb;
        $usermeta = $wpdb->usermeta ?? ( $wpdb->prefix . 'usermeta' );

        // Не нормализуем в SQL — нужно унификацировать через PHP normalize_phone().
        // Загружаем raw billing_phone, потом группируем по нормализованному значению.
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT user_id, meta_value FROM %i WHERE meta_key = %s AND meta_value != %s',
            $usermeta,
            'billing_phone',
            ''
        ), ARRAY_A);

        $by_phone = array();
        foreach ($rows as $r) {
            $uid   = isset($r['user_id']) ? (int) $r['user_id'] : 0;
            $phone = isset($r['meta_value']) ? self::normalize_phone((string) $r['meta_value']) : '';
            if ($uid <= 0 || $phone === '') {
                continue;
            }
            $by_phone[ $phone ][ $uid ] = true;
        }

        $edges = array();
        foreach ($by_phone as $phone => $uids_set) {
            $uids = array_keys($uids_set);
            if (count($uids) < 2) {
                continue;
            }
            sort($uids);
            // Star-pattern: связываем первого со всеми остальными — этого достаточно для union-find.
            $anchor = $uids[0];
            for ($i = 1, $n = count($uids); $i < $n; $i++) {
                $edges[] = array(
                    'user_a' => $anchor,
                    'user_b' => $uids[ $i ],
                    'reason' => 'phone',
                    'value'  => $phone,
                );
            }
        }
        return $edges;
    }

    /**
     * @return array<int, array{user_a:int, user_b:int, reason:string, value:string}>
     */
    private static function build_edges_by_email_normalized(): array {
        global $wpdb;
        $users = $wpdb->users ?? ( $wpdb->prefix . 'users' );

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT ID, user_email FROM %i WHERE user_email != %s',
            $users,
            ''
        ), ARRAY_A);

        $by_email = array();
        foreach ($rows as $r) {
            $uid = isset($r['ID']) ? (int) $r['ID'] : 0;
            $em  = isset($r['user_email']) ? self::normalize_email((string) $r['user_email']) : '';
            if ($uid <= 0 || $em === '') {
                continue;
            }
            $by_email[ $em ][ $uid ] = true;
        }

        $edges = array();
        foreach ($by_email as $em => $uids_set) {
            $uids = array_keys($uids_set);
            if (count($uids) < 2) {
                continue;
            }
            sort($uids);
            $anchor = $uids[0];
            for ($i = 1, $n = count($uids); $i < $n; $i++) {
                $edges[] = array(
                    'user_a' => $anchor,
                    'user_b' => $uids[ $i ],
                    'reason' => 'email_normalized',
                    'value'  => $em,
                );
            }
        }
        return $edges;
    }

    /**
     * Хелпер: GROUP BY <key> + GROUP_CONCAT(user_id) → массив рёбер по star-pattern.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{user_a:int, user_b:int, reason:string, value:string}>
     */
    private static function expand_group_concat_to_edges( array $rows, string $reason, string $value_key, string $users_key ): array {
        $edges = array();
        foreach ($rows as $row) {
            $value     = isset($row[ $value_key ]) ? (string) $row[ $value_key ] : '';
            $users_csv = isset($row[ $users_key ]) ? (string) $row[ $users_key ] : '';
            if ($value === '' || $users_csv === '') {
                continue;
            }
            $uids = array_values(array_unique(array_filter(array_map('intval', explode(',', $users_csv)))));
            if (count($uids) < 2) {
                continue;
            }
            sort($uids);
            $anchor = $uids[0];
            for ($i = 1, $n = count($uids); $i < $n; $i++) {
                $edges[] = array(
                    'user_a' => $anchor,
                    'user_b' => $uids[ $i ],
                    'reason' => $reason,
                    'value'  => $value,
                );
            }
        }
        return $edges;
    }

    // ================================================================
    // Union-find
    // ================================================================

    /**
     * Классический union-find с path compression и union by rank.
     *
     * @param array<int, array{user_a:int, user_b:int, reason:string, value:string}> $edges
     * @return array<int, array<int, int>>  Массив групп (каждая — отсортированный uniq array of user_ids), только size >= 2.
     */
    public static function union_find_clusters( array $edges ): array {
        $parent = array();
        $rank   = array();

        $find = function ( int $x ) use ( &$parent ): int {
            $root = $x;
            while ($parent[ $root ] !== $root) {
                $root = $parent[ $root ];
            }
            // Path compression
            while ($parent[ $x ] !== $root) {
                $next         = $parent[ $x ];
                $parent[ $x ] = $root;
                $x            = $next;
            }
            return $root;
        };

        $union = function ( int $a, int $b ) use ( &$parent, &$rank, $find ): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra === $rb) {
                return;
            }
            if ($rank[ $ra ] < $rank[ $rb ]) {
                $parent[ $ra ] = $rb;
            } elseif ($rank[ $ra ] > $rank[ $rb ]) {
                $parent[ $rb ] = $ra;
            } else {
                $parent[ $rb ] = $ra;
                ++$rank[ $ra ];
            }
        };

        foreach ($edges as $e) {
            $a = (int) $e['user_a'];
            $b = (int) $e['user_b'];
            if ($a <= 0 || $b <= 0 || $a === $b) {
                continue;
            }
            if (!isset($parent[ $a ])) {
                $parent[ $a ] = $a;
                $rank[ $a ]   = 0;
            }
            if (!isset($parent[ $b ])) {
                $parent[ $b ] = $b;
                $rank[ $b ]   = 0;
            }
            $union($a, $b);
        }

        $groups = array();
        foreach ($parent as $node => $_) {
            $root              = $find($node);
            $groups[ $root ][] = $node;
        }

        $result = array();
        foreach ($groups as $members) {
            $members = array_values(array_unique($members));
            if (count($members) < 2) {
                continue;
            }
            sort($members);
            $result[] = $members;
        }
        return $result;
    }

    // ================================================================
    // Score / reasons / UID
    // ================================================================

    /**
     * Дедуп reasons для хранения и score.
     * - Каждый distinct value одного типа → отдельная запись.
     * - Strength помечает «сильную»/«среднюю»/«слабую» связь.
     *
     * @param array<int, array{reason:string, value:string}> $raw
     * @return array<int, array{reason:string, value:string, strength:string}>
     */
    private static function dedupe_reasons( array $raw ): array {
        $seen = array();
        $out  = array();
        foreach ($raw as $r) {
            $key = $r['reason'] . '|' . $r['value'];
            if (isset($seen[ $key ])) {
                continue;
            }
            $seen[ $key ] = true;
            $out[]        = array(
                'reason'   => $r['reason'],
                'value'    => $r['value'],
                'strength' => self::strength_label($r['reason']),
            );
        }
        return $out;
    }

    private static function strength_label( string $reason ): string {
        $w = self::REASON_STRENGTH[ $reason ] ?? 5;
        if ($w >= 25) {
            return 'strong';
        }
        if ($w >= 15) {
            return 'medium';
        }
        return 'weak';
    }

    /**
     * Score: суммируем strength reasons. Каждое distinct value одного типа — полный
     * вес; повторное value того же reason — половинный (для случаев когда group_concat
     * объединил несколько одинаковых полей через UPSERT).
     *
     * @param array<int, array{reason:string, value:string, strength?:string}> $reasons
     */
    public static function compute_cluster_score( array $reasons ): float {
        $by_reason_seen = array();
        $score          = 0.0;
        foreach ($reasons as $r) {
            $reason   = $r['reason'] ?? '';
            $strength = self::REASON_STRENGTH[ $reason ] ?? 0;
            if ($strength <= 0) {
                continue;
            }
            $count = $by_reason_seen[ $reason ] ?? 0;
            // 1-е value полная сила, 2+ — половина.
            $score                    += $count === 0 ? $strength : ( $strength / 2 );
            $by_reason_seen[ $reason ] = $count + 1;
        }
        return (float) min(100.0, round($score, 2));
    }

    /**
     * Primary reason — тот, чья суммарная strength максимальна.
     *
     * @param array<int, array{reason:string, value:string, strength?:string}> $reasons
     */
    private static function determine_primary_reason( array $reasons ): string {
        $sum = array();
        foreach ($reasons as $r) {
            $reason         = $r['reason'] ?? '';
            $sum[ $reason ] = ( $sum[ $reason ] ?? 0 ) + ( self::REASON_STRENGTH[ $reason ] ?? 0 );
        }
        if (empty($sum)) {
            return 'unknown';
        }
        arsort($sum);
        return (string) array_key_first($sum);
    }

    /**
     * Детерминистический cluster_uid из sorted user_ids.
     * sha1 → формат UUID v5-style (используем version-bit 5 + variant 8).
     *
     * @param array<int, int> $user_ids
     */
    public static function generate_cluster_uid( array $user_ids ): string {
        $clean = array_values(array_unique(array_map('intval', $user_ids)));
        sort($clean);
        $hash = sha1('cashback-cluster:' . implode('-', $clean));
        // Формат UUID 8-4-4-4-12. Version=5, variant=8.
        return sprintf(
            '%s-%s-5%s-8%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 17, 3),
            substr($hash, 20, 12)
        );
    }
}
