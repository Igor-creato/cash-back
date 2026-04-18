<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ядро детекции фрода: 7 автоматических проверок + риск-скоринг
 *
 * @since 1.2.0
 */
class Cashback_Fraud_Detector {

    /**
     * Запуск всех проверок. Вызывается из WP Cron (hourly).
     *
     * @return void
     */
    public static function run_all_checks(): void {
        if (!Cashback_Fraud_Settings::is_enabled()) {
            return;
        }

        $new_alert_ids = array();

        // GROUP_CONCAT по умолчанию ограничен 1024 байтами — увеличиваем для корректного
        // формирования списков user_id при большом количестве пользователей на одном IP/fingerprint
        global $wpdb;
        $wpdb->query('SET SESSION group_concat_max_len = 65535');

        $new_alert_ids = array_merge($new_alert_ids, self::check_shared_ip());
        $new_alert_ids = array_merge($new_alert_ids, self::check_shared_fingerprint());
        $new_alert_ids = array_merge($new_alert_ids, self::check_shared_payment_details());
        $new_alert_ids = array_merge($new_alert_ids, self::check_cancellation_rate());
        $new_alert_ids = array_merge($new_alert_ids, self::check_withdrawal_velocity());
        $new_alert_ids = array_merge($new_alert_ids, self::check_amount_anomalies());
        $new_alert_ids = array_merge($new_alert_ids, self::check_new_account_withdrawals());

        if (!empty($new_alert_ids)) {
            self::notify_admin($new_alert_ids);
        }

        update_option('cashback_fraud_last_run', current_time('mysql'));

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log(sprintf(
            'Cashback Fraud Detector: completed, %d new alert(s) created',
            count($new_alert_ids)
        ));
    }

    /**
     * CHECK 1: Множественные аккаунты по IP
     *
     * @return int[] Массив ID созданных алертов
     */
    private static function check_shared_ip(): array {
        global $wpdb;
        $threshold = Cashback_Fraud_Settings::get_max_users_per_ip();
        $fp_table  = $wpdb->prefix . 'cashback_user_fingerprints';
        $alert_ids = array();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ip_address, GROUP_CONCAT(DISTINCT user_id ORDER BY user_id) as user_ids,
                    COUNT(DISTINCT user_id) as user_count
             FROM %i
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
               AND ip_address NOT IN ('127.0.0.1', '::1', '0.0.0.0')
               AND ip_address NOT LIKE %s
               AND ip_address NOT LIKE %s
               AND ip_address NOT BETWEEN '172.16.0.0' AND '172.31.255.255'
             GROUP BY ip_address
             HAVING user_count > %d",
            $fp_table,
            '10.%',
            '192.168.%',
            $threshold
        ));

        foreach ($results as $row) {
            if (empty($row->user_ids)) {
                continue;
            }
            $user_ids = array_map('intval', explode(',', $row->user_ids));

            foreach ($user_ids as $user_id) {
                if (self::alert_exists($user_id, 'multi_account_ip')) {
                    continue;
                }

                $alert_id = self::create_alert(
                    $user_id,
                    'multi_account_ip',
                    self::score_to_severity(15.0),
                    15.0,
                    sprintf(
                        'IP %s используется %d аккаунтами: %s',
                        $row->ip_address,
                        $row->user_count,
                        $row->user_ids
                    ),
                    array(
                        'ip_address'      => $row->ip_address,
                        'shared_user_ids' => $user_ids,
                        'user_count'      => (int) $row->user_count,
                    ),
                    array(
                        array(
                            'signal_type' => 'ip_match',
                            'weight'      => 15.0,
                            'evidence'    => array(
                                'ip'       => $row->ip_address,
                                'user_ids' => $user_ids,
                            ),
                        ),
                    )
                );

                if ($alert_id) {
                    $alert_ids[] = $alert_id;
                }
            }
        }

        return $alert_ids;
    }

    /**
     * CHECK 2: Множественные аккаунты по fingerprint
     *
     * @return int[]
     */
    private static function check_shared_fingerprint(): array {
        global $wpdb;
        $threshold = Cashback_Fraud_Settings::get_max_users_per_fingerprint();
        $fp_table  = $wpdb->prefix . 'cashback_user_fingerprints';
        $alert_ids = array();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT fingerprint_hash, GROUP_CONCAT(DISTINCT user_id ORDER BY user_id) as user_ids,
                    COUNT(DISTINCT user_id) as user_count
             FROM %i
             WHERE fingerprint_hash IS NOT NULL
               AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY fingerprint_hash
             HAVING user_count > %d",
            $fp_table,
            $threshold
        ));

        foreach ($results as $row) {
            if (empty($row->user_ids)) {
                continue;
            }
            $user_ids = array_map('intval', explode(',', $row->user_ids));

            foreach ($user_ids as $user_id) {
                if (self::alert_exists($user_id, 'multi_account_fp')) {
                    continue;
                }

                $alert_id = self::create_alert(
                    $user_id,
                    'multi_account_fp',
                    self::score_to_severity(30.0),
                    30.0,
                    sprintf(
                        'Fingerprint совпадает у %d аккаунтов: %s',
                        $row->user_count,
                        $row->user_ids
                    ),
                    array(
                        'fingerprint_hash' => $row->fingerprint_hash,
                        'shared_user_ids'  => $user_ids,
                        'user_count'       => (int) $row->user_count,
                    ),
                    array(
                        array(
                            'signal_type' => 'fingerprint_match',
                            'weight'      => 30.0,
                            'evidence'    => array(
                                'fingerprint' => $row->fingerprint_hash,
                                'user_ids'    => $user_ids,
                            ),
                        ),
                    )
                );

                if ($alert_id) {
                    $alert_ids[] = $alert_id;
                }
            }
        }

        return $alert_ids;
    }

    /**
     * CHECK 3: Общие платёжные реквизиты (через details_hash, без расшифровки)
     *
     * @return int[]
     */
    private static function check_shared_payment_details(): array {
        global $wpdb;
        $threshold     = Cashback_Fraud_Settings::get_max_accounts_per_details_hash();
        $profile_table = $wpdb->prefix . 'cashback_user_profile';
        $alert_ids     = array();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT details_hash, GROUP_CONCAT(user_id ORDER BY user_id) as user_ids,
                    COUNT(*) as user_count
             FROM %i
             WHERE details_hash IS NOT NULL
               AND details_hash != ''
               AND status != 'deleted'
             GROUP BY details_hash
             HAVING user_count > %d",
            $profile_table,
            $threshold
        ));

        foreach ($results as $row) {
            if (empty($row->user_ids)) {
                continue;
            }
            $user_ids = array_map('intval', explode(',', $row->user_ids));

            foreach ($user_ids as $user_id) {
                if (self::alert_exists($user_id, 'shared_details')) {
                    continue;
                }

                $alert_id = self::create_alert(
                    $user_id,
                    'shared_details',
                    self::score_to_severity(40.0),
                    40.0,
                    sprintf(
                        'Одинаковые реквизиты у %d аккаунтов: %s',
                        $row->user_count,
                        $row->user_ids
                    ),
                    array(
                        'details_hash'    => $row->details_hash,
                        'shared_user_ids' => $user_ids,
                        'user_count'      => (int) $row->user_count,
                    ),
                    array(
                        array(
                            'signal_type' => 'shared_details',
                            'weight'      => 40.0,
                            'evidence'    => array(
                                'details_hash' => $row->details_hash,
                                'user_ids'     => $user_ids,
                            ),
                        ),
                    )
                );

                if ($alert_id) {
                    $alert_ids[] = $alert_id;
                }
            }
        }

        return $alert_ids;
    }

    /**
     * CHECK 4: Высокий процент отклонённых транзакций
     *
     * @return int[]
     */
    private static function check_cancellation_rate(): array {
        global $wpdb;
        $threshold = Cashback_Fraud_Settings::get_cancellation_rate_threshold();
        $min_tx    = Cashback_Fraud_Settings::get_cancellation_min_transactions();
        $tx_table  = $wpdb->prefix . 'cashback_transactions';
        $alert_ids = array();

        // Ограничиваем анализ 90 днями — достаточно для выявления актуальных паттернов,
        // при этом не сканирует всю историю (важно при 1M+ транзакций)
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id,
                    COUNT(*) as total,
                    SUM(CASE WHEN order_status = 'declined' THEN 1 ELSE 0 END) as declined,
                    ROUND(SUM(CASE WHEN order_status = 'declined' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as decline_rate
             FROM %i
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)
             GROUP BY user_id
             HAVING total >= %d AND decline_rate > %f",
            $tx_table,
            $min_tx,
            $threshold
        ));

        foreach ($results as $row) {
            if (self::alert_exists((int) $row->user_id, 'cancellation_rate')) {
                continue;
            }

            $score = min(25.0, (float) $row->decline_rate / 100.0 * 25.0);

            $alert_id = self::create_alert(
                (int) $row->user_id,
                'cancellation_rate',
                self::score_to_severity($score),
                $score,
                sprintf(
                    'Высокий %% отклонений: %.1f%% (%d из %d транзакций)',
                    $row->decline_rate,
                    $row->declined,
                    $row->total
                ),
                array(
                    'total_transactions'    => (int) $row->total,
                    'declined_transactions' => (int) $row->declined,
                    'decline_rate'          => (float) $row->decline_rate,
                ),
                array(
                    array(
                        'signal_type' => 'cancellation_rate',
                        'weight'      => $score,
                        'evidence'    => array(
                            'total'    => (int) $row->total,
                            'declined' => (int) $row->declined,
                            'rate'     => (float) $row->decline_rate,
                        ),
                    ),
                )
            );

            if ($alert_id) {
                $alert_ids[] = $alert_id;
            }
        }

        return $alert_ids;
    }

    /**
     * CHECK 5: Высокая частота выводов (слишком много заявок)
     *
     * @return int[]
     */
    private static function check_withdrawal_velocity(): array {
        global $wpdb;
        $max_day   = Cashback_Fraud_Settings::get_max_withdrawals_per_day();
        $max_week  = Cashback_Fraud_Settings::get_max_withdrawals_per_week();
        $req_table = $wpdb->prefix . 'cashback_payout_requests';
        $alert_ids = array();

        // Per day
        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, DATE(created_at) as req_date, COUNT(*) as req_count
             FROM %i
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY user_id, DATE(created_at)
             HAVING req_count > %d",
            $req_table,
            $max_day
        ));

        foreach ($daily as $row) {
            if (self::alert_exists((int) $row->user_id, 'velocity')) {
                continue;
            }

            $alert_id = self::create_alert(
                (int) $row->user_id,
                'velocity',
                self::score_to_severity(20.0),
                20.0,
                sprintf(
                    '%d заявок на вывод за %s (лимит: %d/день)',
                    $row->req_count,
                    $row->req_date,
                    $max_day
                ),
                array(
                    'date'          => $row->req_date,
                    'request_count' => (int) $row->req_count,
                    'limit_day'     => $max_day,
                    'type'          => 'daily',
                ),
                array(
                    array(
                        'signal_type' => 'velocity',
                        'weight'      => 20.0,
                        'evidence'    => array(
                            'period' => 'day',
                            'date'   => $row->req_date,
                            'count'  => (int) $row->req_count,
                            'limit'  => $max_day,
                        ),
                    ),
                )
            );

            if ($alert_id) {
                $alert_ids[] = $alert_id;
            }
        }

        // Per week
        $weekly = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, YEARWEEK(created_at, 1) as req_week, COUNT(*) as req_count
             FROM %i
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY user_id, YEARWEEK(created_at, 1)
             HAVING req_count > %d",
            $req_table,
            $max_week
        ));

        foreach ($weekly as $row) {
            if (self::alert_exists((int) $row->user_id, 'velocity')) {
                continue;
            }

            $alert_id = self::create_alert(
                (int) $row->user_id,
                'velocity',
                self::score_to_severity(20.0),
                20.0,
                sprintf(
                    '%d заявок на вывод за неделю %s (лимит: %d/неделя)',
                    $row->req_count,
                    $row->req_week,
                    $max_week
                ),
                array(
                    'week'          => $row->req_week,
                    'request_count' => (int) $row->req_count,
                    'limit_week'    => $max_week,
                    'type'          => 'weekly',
                ),
                array(
                    array(
                        'signal_type' => 'velocity',
                        'weight'      => 20.0,
                        'evidence'    => array(
                            'period' => 'week',
                            'week'   => $row->req_week,
                            'count'  => (int) $row->req_count,
                            'limit'  => $max_week,
                        ),
                    ),
                )
            );

            if ($alert_id) {
                $alert_ids[] = $alert_id;
            }
        }

        return $alert_ids;
    }

    /**
     * CHECK 6: Аномальные суммы транзакций
     *
     * @return int[]
     */
    private static function check_amount_anomalies(): array {
        global $wpdb;
        $multiplier = Cashback_Fraud_Settings::get_amount_anomaly_multiplier();
        $tx_table   = $wpdb->prefix . 'cashback_transactions';
        $alert_ids  = array();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT t.user_id, t.id as transaction_id, t.cashback as current_amount,
                    stats.avg_cashback, stats.tx_count
             FROM %i t
             INNER JOIN (
                 SELECT user_id, AVG(cashback) as avg_cashback, COUNT(*) as tx_count
                 FROM %i
                 WHERE order_status IN ('completed', 'balance', 'hold')
                   AND cashback > 0
                   AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY user_id
                 HAVING tx_count >= 3 AND avg_cashback > 0
             ) stats ON t.user_id = stats.user_id
             WHERE t.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
               AND t.cashback > stats.avg_cashback * %f
               AND t.cashback > 0",
            $tx_table,
            $tx_table,
            $multiplier
        ));

        foreach ($results as $row) {
            if (self::alert_exists((int) $row->user_id, 'amount_anomaly')) {
                continue;
            }

            $alert_id = self::create_alert(
                (int) $row->user_id,
                'amount_anomaly',
                self::score_to_severity(20.0),
                20.0,
                sprintf(
                    'Аномальная сумма кэшбэка: %.2f (средняя: %.2f, x%.1f)',
                    $row->current_amount,
                    $row->avg_cashback,
                    $row->current_amount / max(0.01, (float) $row->avg_cashback)
                ),
                array(
                    'transaction_id' => (int) $row->transaction_id,
                    'current_amount' => (float) $row->current_amount,
                    'avg_amount'     => (float) $row->avg_cashback,
                    'multiplier'     => $row->current_amount / max(0.01, (float) $row->avg_cashback),
                    'history_count'  => (int) $row->tx_count,
                ),
                array(
                    array(
                        'signal_type' => 'amount_spike',
                        'weight'      => 20.0,
                        'evidence'    => array(
                            'transaction_id' => (int) $row->transaction_id,
                            'amount'         => (float) $row->current_amount,
                            'average'        => (float) $row->avg_cashback,
                        ),
                    ),
                )
            );

            if ($alert_id) {
                $alert_ids[] = $alert_id;
            }
        }

        return $alert_ids;
    }

    /**
     * CHECK 7: Новые аккаунты с ранним выводом
     *
     * @return int[]
     */
    private static function check_new_account_withdrawals(): array {
        global $wpdb;
        $cooling_days = Cashback_Fraud_Settings::get_new_account_cooling_days();
        if ($cooling_days <= 0) {
            return array();
        }

        $req_table = $wpdb->prefix . 'cashback_payout_requests';
        $alert_ids = array();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT r.user_id, u.user_registered, r.id as request_id, r.total_amount,
                    DATEDIFF(r.created_at, u.user_registered) as days_since_reg
             FROM %i r
             INNER JOIN %i u ON r.user_id = u.ID
             WHERE u.user_registered > DATE_SUB(NOW(), INTERVAL %d DAY)
               AND r.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
            $req_table,
            $wpdb->users,
            $cooling_days
        ));

        foreach ($results as $row) {
            if (self::alert_exists((int) $row->user_id, 'new_account_risk')) {
                continue;
            }

            $alert_id = self::create_alert(
                (int) $row->user_id,
                'new_account_risk',
                self::score_to_severity(15.0),
                15.0,
                sprintf(
                    'Попытка вывода %.2f через %d дн. после регистрации (лимит: %d дн.)',
                    $row->total_amount,
                    $row->days_since_reg,
                    $cooling_days
                ),
                array(
                    'user_registered'         => $row->user_registered,
                    'days_since_registration' => (int) $row->days_since_reg,
                    'cooling_days'            => $cooling_days,
                    'request_id'              => (int) $row->request_id,
                    'amount'                  => (float) $row->total_amount,
                ),
                array(
                    array(
                        'signal_type' => 'new_account_withdrawal',
                        'weight'      => 15.0,
                        'evidence'    => array(
                            'registered' => $row->user_registered,
                            'days'       => (int) $row->days_since_reg,
                            'amount'     => (float) $row->total_amount,
                        ),
                    ),
                )
            );

            if ($alert_id) {
                $alert_ids[] = $alert_id;
            }
        }

        return $alert_ids;
    }

    // ===========================
    // Вспомогательные методы
    // ===========================

    /**
     * Расчёт композитного риск-скора пользователя
     *
     * @param int $user_id ID пользователя
     * @return float Скор 0-100
     */
    public static function calculate_user_risk_score( int $user_id ): float {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_fraud_alerts';

        $score = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(risk_score), 0)
             FROM %i
             WHERE user_id = %d AND status IN ('open', 'reviewing')",
            $table,
            $user_id
        ));

        return min(100.0, (float) $score);
    }

    /**
     * Проверка: существует ли активный алерт данного типа для пользователя
     *
     * @param int    $user_id    ID пользователя
     * @param string $alert_type Тип алерта
     * @return bool
     */
    private static function alert_exists( int $user_id, string $alert_type ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_fraud_alerts';

        // Дедупликация: проверяем open/reviewing + confirmed/dismissed за последние 30 дней
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i
             WHERE user_id = %d AND alert_type = %s
             AND (
                 status IN ('open', 'reviewing')
                 OR (status IN ('confirmed', 'dismissed') AND updated_at > DATE_SUB(NOW(), INTERVAL 30 DAY))
             )",
            $table,
            $user_id,
            $alert_type
        ));
    }

    /**
     * Создание алерта с сигналами
     *
     * @param int         $user_id    ID пользователя
     * @param string      $alert_type Тип алерта
     * @param string      $severity   low|medium|high|critical
     * @param float       $risk_score Скор 0-100
     * @param string      $summary    Описание
     * @param array       $details    JSON-данные
     * @param array       $signals    Массив сигналов
     * @return int|null   ID алерта или null
     */
    private static function create_alert(
        int $user_id,
        string $alert_type,
        string $severity,
        float $risk_score,
        string $summary,
        array $details,
        array $signals
    ): ?int {
        global $wpdb;
        $alerts_table  = $wpdb->prefix . 'cashback_fraud_alerts';
        $signals_table = $wpdb->prefix . 'cashback_fraud_signals';

        // Атомарная проверка дубликатов: транзакция + FOR UPDATE предотвращает
        // race condition между параллельными cron-запусками
        $wpdb->query('START TRANSACTION');

        $exists = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i
             WHERE user_id = %d AND alert_type = %s
             AND (
                 status IN ('open', 'reviewing')
                 OR (status IN ('confirmed', 'dismissed') AND updated_at > DATE_SUB(NOW(), INTERVAL 30 DAY))
             )
             FOR UPDATE",
            $alerts_table,
            $user_id,
            $alert_type
        ));

        if ($exists) {
            $wpdb->query('COMMIT');
            return null;
        }

        $wpdb->insert(
            $alerts_table,
            array(
                'user_id'    => $user_id,
                'alert_type' => $alert_type,
                'severity'   => $severity,
                'risk_score' => $risk_score,
                'status'     => 'open',
                'summary'    => $summary,
                'details'    => wp_json_encode($details, JSON_UNESCAPED_UNICODE),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' )
        );

        if ($wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback Fraud Detector: Failed to create alert — ' . $wpdb->last_error);
            return null;
        }

        $alert_id = (int) $wpdb->insert_id;

        // Создаём сигналы
        foreach ($signals as $signal) {
            $wpdb->insert(
                $signals_table,
                array(
                    'alert_id'    => $alert_id,
                    'signal_type' => $signal['signal_type'],
                    'weight'      => $signal['weight'],
                    'evidence'    => wp_json_encode($signal['evidence'], JSON_UNESCAPED_UNICODE),
                    'created_at'  => current_time('mysql'),
                ),
                array( '%d', '%s', '%f', '%s', '%s' )
            );
        }

        $wpdb->query('COMMIT');

        // Аудит-лог (после коммита, чтобы не блокировать транзакцию)
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'fraud_alert_created',
                0, // system actor
                'fraud_alert',
                $alert_id,
                array(
					'alert_type' => $alert_type,
					'user_id'    => $user_id,
					'severity'   => $severity,
				)
            );
        }

        return $alert_id;
    }

    /**
     * Конвертация скора в severity
     *
     * @param float $score Скор
     * @return string low|medium|high|critical
     */
    private static function score_to_severity( float $score ): string {
        if ($score >= 76) {
            return 'critical';
        }
        if ($score >= 51) {
            return 'high';
        }
        if ($score >= 26) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Уведомление админа о новых алертах по email
     *
     * @param int[] $alert_ids Массив ID новых алертов
     * @return void
     */
    private static function notify_admin( array $alert_ids ): void {
        if (!Cashback_Fraud_Settings::is_email_notification_enabled()) {
            return;
        }

        global $wpdb;
        $table        = $wpdb->prefix . 'cashback_fraud_alerts';
        $placeholders = implode(',', array_fill(0, count($alert_ids), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders — строка из array_fill(count($alert_ids), '%d'); таблицы через %i, значения через prepare(); sniff не видит %d внутри $placeholders.
        $alerts = $wpdb->get_results($wpdb->prepare( "SELECT a.*, u.user_login, u.user_email FROM %i a LEFT JOIN %i u ON a.user_id = u.ID WHERE a.id IN ({$placeholders})", $table, $wpdb->users, ...$alert_ids ));

        if (empty($alerts)) {
            return;
        }

        $admin_email = get_option('admin_email');
        $site_name   = get_bloginfo('name');
        $admin_url   = admin_url('admin.php?page=cashback-antifraud');

        $critical_count = count(array_filter($alerts, fn( $a ) => $a->severity === 'critical' || $a->severity === 'high'));
        $subject        = $critical_count > 0
            ? "[FRAUD] {$site_name}: {$critical_count} критических алертов"
            : "[FRAUD] {$site_name}: " . count($alerts) . ' новых алертов';

        $body  = "Cashback Antifraud Report\n";
        $body .= str_repeat('=', 50) . "\n";
        $body .= sprintf("Дата: %s\n", current_time('mysql'));
        $body .= sprintf("Новых алертов: %d\n", count($alerts));
        $body .= str_repeat('=', 50) . "\n\n";

        foreach ($alerts as $alert) {
            $body .= sprintf(
                "[%s] %s\n  User: %s (%s)\n  Score: %.0f | Type: %s\n  %s\n\n",
                strtoupper($alert->severity),
                $alert->alert_type,
                $alert->user_login ?? 'N/A',
                $alert->user_email ?? 'N/A',
                $alert->risk_score,
                $alert->alert_type,
                $alert->summary
            );
        }

        $body .= str_repeat('-', 50) . "\n";
        $body .= "Просмотр: {$admin_url}\n";

        wp_mail($admin_email, $subject, $body);
    }
}

// WP Cron handler
add_action('cashback_fraud_detection_cron', function (): void {
    Cashback_Fraud_Detector::run_all_checks();
});
