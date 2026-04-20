<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Система мониторинга целостности данных кэшбэк-системы
 *
 * Проверяет:
 * - Отрицательные балансы
 * - Зависшие заявки на выплату (> 30 дней в статусе waiting)
 * - Несоответствие pending_balance и реальных заявок
 * - Пользователей WordPress без профиля/баланса в кэшбэк-системе
 *
 * @since 1.1.0
 */
class Cashback_Health_Check {

    private const STALE_PAYOUT_DAYS = 30;

    /**
     * Запуск всех проверок и отправка отчёта при обнаружении проблем
     *
     * @return void
     */
    public static function run(): void {
        $issues = array();

        $issues = array_merge($issues, self::check_negative_balances());
        $issues = array_merge($issues, self::check_stale_payouts());
        $issues = array_merge($issues, self::check_balance_payout_mismatch());
        $issues = array_merge($issues, self::check_orphaned_users());

        if (!empty($issues)) {
            self::send_report($issues);
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log('Cashback Health Check: completed, ' . count($issues) . ' issue(s) found');
    }

    /**
     * Проверка отрицательных балансов
     *
     * CHECK constraints должны предотвращать это, но проверяем на случай
     * обхода constraints (прямые SQL-запросы, отключённые constraints).
     *
     * @return array Список найденных проблем
     */
    private static function check_negative_balances(): array {
        global $wpdb;

        $table  = $wpdb->prefix . 'cashback_user_balance';
        $issues = array();

        $negative = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT user_id, available_balance, pending_balance, paid_balance, frozen_balance
                 FROM %i
                 WHERE available_balance < 0 OR pending_balance < 0 OR paid_balance < 0 OR frozen_balance < 0',
                $table
            )
        );

        foreach ($negative as $row) {
            $details  = sprintf(
                'user_id=%d, available=%.2f, pending=%.2f, paid=%.2f, frozen=%.2f',
                $row->user_id,
                $row->available_balance,
                $row->pending_balance,
                $row->paid_balance,
                $row->frozen_balance
            );
            $issues[] = array(
                'severity' => 'CRITICAL',
                'type'     => 'negative_balance',
                'message'  => "Отрицательный баланс: {$details}",
            );
        }

        return $issues;
    }

    /**
     * Проверка зависших заявок на выплату
     *
     * Заявки в статусе waiting более 30 дней могут указывать на проблему в обработке.
     *
     * @return array Список найденных проблем
     */
    private static function check_stale_payouts(): array {
        global $wpdb;

        $table  = $wpdb->prefix . 'cashback_payout_requests';
        $issues = array();

        $stale = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id, total_amount, created_at
             FROM %i
             WHERE status = 'waiting'
             AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $table,
            self::STALE_PAYOUT_DAYS
        ) );

        foreach ($stale as $row) {
            $issues[] = array(
                'severity' => 'WARNING',
                'type'     => 'stale_payout',
                'message'  => sprintf(
                    'Заявка #%d (user_id=%d, сумма=%.2f) в статусе waiting с %s',
                    $row->id,
                    $row->user_id,
                    $row->total_amount,
                    $row->created_at
                ),
            );
        }

        return $issues;
    }

    /**
     * Проверка несоответствия pending_balance и реальных заявок
     *
     * pending_balance > 0, но нет активных заявок (waiting/processing) — значит
     * средства «зависли» и не могут быть ни выплачены, ни возвращены.
     *
     * @return array Список найденных проблем
     */
    private static function check_balance_payout_mismatch(): array {
        global $wpdb;

        $table_balance  = $wpdb->prefix . 'cashback_user_balance';
        $table_requests = $wpdb->prefix . 'cashback_payout_requests';
        $issues         = array();

        // iter-28 F-28-003: сверяем pending_balance пользователя с суммой активных
        // заявок на выплату. Раньше ловили только «pending>0 и заявок нет»; теперь
        // детектируем любой drift (pending != sum(active payouts)), включая случаи
        // pending=1000 при активных заявках на 700 или 1300.
        $mismatched = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.user_id,
                        b.pending_balance,
                        COALESCE(SUM(CASE WHEN r.status IN ('waiting','processing','needs_retry')
                                          THEN r.total_amount ELSE 0 END), 0) AS payout_sum
                 FROM %i b
                 LEFT JOIN %i r ON b.user_id = r.user_id
                 GROUP BY b.user_id, b.pending_balance
                 HAVING CAST(b.pending_balance AS DECIMAL(18,2)) <> CAST(payout_sum AS DECIMAL(18,2))",
                $table_balance,
                $table_requests
            )
        );

        foreach ($mismatched as $row) {
            $pending_f = (float) $row->pending_balance;
            $payout_f  = (float) $row->payout_sum;
            if ($payout_f === 0.0 && $pending_f > 0.0) {
                $msg  = sprintf(
                    'user_id=%d имеет pending_balance=%.2f, но нет активных заявок на выплату',
                    $row->user_id,
                    $pending_f
                );
                $type = 'pending_without_payout';
            } else {
                $msg  = sprintf(
                    'user_id=%d: pending_balance=%.2f, сумма активных заявок=%.2f (расхождение %.2f)',
                    $row->user_id,
                    $pending_f,
                    $payout_f,
                    $pending_f - $payout_f
                );
                $type = 'balance_payout_sum_mismatch';
            }

            $issues[] = array(
                'severity' => 'WARNING',
                'type'     => $type,
                'message'  => $msg,
            );
        }

        return $issues;
    }

    /**
     * Проверка пользователей без профиля/баланса в кэшбэк-системе
     *
     * Все пользователи WordPress должны иметь записи в cashback_user_profile
     * и cashback_user_balance. Отсутствие записей означает, что хук user_register
     * не сработал или произошла ошибка при инициализации.
     *
     * @return array Список найденных проблем
     */
    private static function check_orphaned_users(): array {
        global $wpdb;

        $table_profile = $wpdb->prefix . 'cashback_user_profile';
        $table_balance = $wpdb->prefix . 'cashback_user_balance';
        $issues        = array();

        // Пользователи WordPress без профиля кэшбэка
        // COUNT отдельно, затем LIMIT 20 для примеров — защита от OOM на больших сайтах
        $orphaned_profile_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*)
                 FROM %i u
                 LEFT JOIN %i p ON u.ID = p.user_id
                 WHERE p.user_id IS NULL',
                $wpdb->users,
                $table_profile
            )
        );

        if ($orphaned_profile_count > 0) {
            $without_profile_sample = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT u.ID
                     FROM %i u
                     LEFT JOIN %i p ON u.ID = p.user_id
                     WHERE p.user_id IS NULL
                     LIMIT 20',
                    $wpdb->users,
                    $table_profile
                )
            );
            $issues[]               = array(
                'severity' => 'WARNING',
                'type'     => 'missing_profile',
                'message'  => sprintf(
                    '%d пользователь(ей) без профиля в кэшбэк-системе: ID %s%s',
                    $orphaned_profile_count,
                    implode(', ', $without_profile_sample),
                    $orphaned_profile_count > 20 ? '...' : ''
                ),
            );
        }

        // Пользователи WordPress без баланса кэшбэка
        $orphaned_balance_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*)
                 FROM %i u
                 LEFT JOIN %i b ON u.ID = b.user_id
                 WHERE b.user_id IS NULL',
                $wpdb->users,
                $table_balance
            )
        );

        if ($orphaned_balance_count > 0) {
            $without_balance_sample = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT u.ID
                     FROM %i u
                     LEFT JOIN %i b ON u.ID = b.user_id
                     WHERE b.user_id IS NULL
                     LIMIT 20',
                    $wpdb->users,
                    $table_balance
                )
            );
            $issues[]               = array(
                'severity' => 'WARNING',
                'type'     => 'missing_balance',
                'message'  => sprintf(
                    '%d пользователь(ей) без баланса в кэшбэк-системе: ID %s%s',
                    $orphaned_balance_count,
                    implode(', ', $without_balance_sample),
                    $orphaned_balance_count > 20 ? '...' : ''
                ),
            );
        }

        return $issues;
    }

    /**
     * Отправка отчёта о найденных проблемах администратору
     *
     * @param array $issues Список проблем
     * @return void
     */
    private static function send_report( array $issues ): void {
        $site_name = get_bloginfo('name');

        $critical_count = count(array_filter($issues, fn( $i ) => $i['severity'] === 'CRITICAL'));
        $warning_count  = count(array_filter($issues, fn( $i ) => $i['severity'] === 'WARNING'));

        if ($critical_count > 0) {
            $subject = sprintf(
                /* translators: 1: site name, 2: critical count */
                __('[CRITICAL] %1$s: Cashback — обнаружены критические проблемы (%2$d)', 'cashback-plugin'),
                $site_name,
                $critical_count
            );
        } else {
            $subject = sprintf(
                /* translators: 1: site name, 2: warning count */
                __('[WARNING] %1$s: Cashback — обнаружены предупреждения (%2$d)', 'cashback-plugin'),
                $site_name,
                $warning_count
            );
        }

        $dump  = "Cashback Health Check Report\n";
        $dump .= str_repeat('=', 50) . "\n";
        $dump .= sprintf("Дата: %s\n", current_time('mysql'));
        $dump .= sprintf("Сайт: %s\n", home_url());
        $dump .= sprintf("Критических: %d | Предупреждений: %d\n", $critical_count, $warning_count);
        $dump .= str_repeat('=', 50) . "\n\n";

        foreach ($issues as $issue) {
            $dump .= sprintf("[%s] %s\n  → %s\n\n", $issue['severity'], $issue['type'], $issue['message']);
        }

        // Дублируем в лог независимо от email
        foreach ($issues as $issue) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log(sprintf('Cashback Health Check [%s] %s: %s', $issue['severity'], $issue['type'], $issue['message']));
        }

        if (!class_exists('Cashback_Email_Sender') || !class_exists('Cashback_Email_Builder')) {
            return;
        }

        $body  = Cashback_Email_Builder::definition_list(array(
            __('Сайт', 'cashback-plugin')           => (string) home_url(),
            __('Критических', 'cashback-plugin')    => (string) $critical_count,
            __('Предупреждений', 'cashback-plugin') => (string) $warning_count,
        ));
        $body .= Cashback_Email_Builder::preformatted($dump);

        Cashback_Email_Sender::get_instance()->send_admin($subject, $body, 'health_check_report');
    }
}

// Обработчик WP Cron
add_action('cashback_health_check_cron', function (): void {
    Cashback_Health_Check::run();
});
