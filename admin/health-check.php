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
class Cashback_Health_Check
{
    private const STALE_PAYOUT_DAYS = 30;

    /**
     * Запуск всех проверок и отправка отчёта при обнаружении проблем
     *
     * @return void
     */
    public static function run(): void
    {
        $issues = [];

        $issues = array_merge($issues, self::check_negative_balances());
        $issues = array_merge($issues, self::check_stale_payouts());
        $issues = array_merge($issues, self::check_balance_payout_mismatch());
        $issues = array_merge($issues, self::check_orphaned_users());

        if (!empty($issues)) {
            self::send_report($issues);
        }

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
    private static function check_negative_balances(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_user_balance';
        $issues = [];

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, no user input
        $negative = $wpdb->get_results(
            "SELECT user_id, available_balance, pending_balance, paid_balance, frozen_balance
             FROM `{$table}`
             WHERE available_balance < 0 OR pending_balance < 0 OR paid_balance < 0 OR frozen_balance < 0"
        );

        foreach ($negative as $row) {
            $details = sprintf(
                'user_id=%d, available=%.2f, pending=%.2f, paid=%.2f, frozen=%.2f',
                $row->user_id,
                $row->available_balance,
                $row->pending_balance,
                $row->paid_balance,
                $row->frozen_balance
            );
            $issues[] = [
                'severity' => 'CRITICAL',
                'type' => 'negative_balance',
                'message' => "Отрицательный баланс: {$details}"
            ];
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
    private static function check_stale_payouts(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_payout_requests';
        $issues = [];

        $stale = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, total_amount, created_at
             FROM `{$table}`
             WHERE status = 'waiting'
             AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            self::STALE_PAYOUT_DAYS
        ));

        foreach ($stale as $row) {
            $issues[] = [
                'severity' => 'WARNING',
                'type' => 'stale_payout',
                'message' => sprintf(
                    'Заявка #%d (user_id=%d, сумма=%.2f) в статусе waiting с %s',
                    $row->id,
                    $row->user_id,
                    $row->total_amount,
                    $row->created_at
                )
            ];
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
    private static function check_balance_payout_mismatch(): array
    {
        global $wpdb;

        $table_balance = $wpdb->prefix . 'cashback_user_balance';
        $table_requests = $wpdb->prefix . 'cashback_payout_requests';
        $issues = [];

        // Пользователи с pending_balance > 0, но без активных заявок
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix, no user input
        $mismatched = $wpdb->get_results(
            "SELECT b.user_id, b.pending_balance
             FROM `{$table_balance}` b
             LEFT JOIN `{$table_requests}` r
                ON b.user_id = r.user_id AND r.status IN ('waiting', 'processing', 'needs_retry')
             WHERE b.pending_balance > 0
             AND r.id IS NULL"
        );

        foreach ($mismatched as $row) {
            $issues[] = [
                'severity' => 'WARNING',
                'type' => 'pending_without_payout',
                'message' => sprintf(
                    'user_id=%d имеет pending_balance=%.2f, но нет активных заявок на выплату',
                    $row->user_id,
                    $row->pending_balance
                )
            ];
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
    private static function check_orphaned_users(): array
    {
        global $wpdb;

        $table_profile = $wpdb->prefix . 'cashback_user_profile';
        $table_balance = $wpdb->prefix . 'cashback_user_balance';
        $issues = [];

        // Пользователи WordPress без профиля кэшбэка
        // COUNT отдельно, затем LIMIT 20 для примеров — защита от OOM на больших сайтах
        $orphaned_profile_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM `{$wpdb->users}` u
             LEFT JOIN `{$table_profile}` p ON u.ID = p.user_id
             WHERE p.user_id IS NULL"
        );

        if ($orphaned_profile_count > 0) {
            $without_profile_sample = $wpdb->get_col(
                "SELECT u.ID
                 FROM `{$wpdb->users}` u
                 LEFT JOIN `{$table_profile}` p ON u.ID = p.user_id
                 WHERE p.user_id IS NULL
                 LIMIT 20"
            );
            $issues[] = [
                'severity' => 'WARNING',
                'type' => 'missing_profile',
                'message' => sprintf(
                    '%d пользователь(ей) без профиля в кэшбэк-системе: ID %s%s',
                    $orphaned_profile_count,
                    implode(', ', $without_profile_sample),
                    $orphaned_profile_count > 20 ? '...' : ''
                )
            ];
        }

        // Пользователи WordPress без баланса кэшбэка
        $orphaned_balance_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM `{$wpdb->users}` u
             LEFT JOIN `{$table_balance}` b ON u.ID = b.user_id
             WHERE b.user_id IS NULL"
        );

        if ($orphaned_balance_count > 0) {
            $without_balance_sample = $wpdb->get_col(
                "SELECT u.ID
                 FROM `{$wpdb->users}` u
                 LEFT JOIN `{$table_balance}` b ON u.ID = b.user_id
                 WHERE b.user_id IS NULL
                 LIMIT 20"
            );
            $issues[] = [
                'severity' => 'WARNING',
                'type' => 'missing_balance',
                'message' => sprintf(
                    '%d пользователь(ей) без баланса в кэшбэк-системе: ID %s%s',
                    $orphaned_balance_count,
                    implode(', ', $without_balance_sample),
                    $orphaned_balance_count > 20 ? '...' : ''
                )
            ];
        }

        return $issues;
    }

    /**
     * Отправка отчёта о найденных проблемах администратору
     *
     * @param array $issues Список проблем
     * @return void
     */
    private static function send_report(array $issues): void
    {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');

        $critical_count = count(array_filter($issues, fn($i) => $i['severity'] === 'CRITICAL'));
        $warning_count = count(array_filter($issues, fn($i) => $i['severity'] === 'WARNING'));

        // Определяем тему письма по серьёзности
        if ($critical_count > 0) {
            $subject = "[CRITICAL] {$site_name}: Cashback — обнаружены критические проблемы ({$critical_count})";
        } else {
            $subject = "[WARNING] {$site_name}: Cashback — обнаружены предупреждения ({$warning_count})";
        }

        // Формируем тело письма
        $body = "Cashback Health Check Report\n";
        $body .= str_repeat('=', 50) . "\n";
        $body .= sprintf("Дата: %s\n", current_time('mysql'));
        $body .= sprintf("Сайт: %s\n", home_url()) ;
        $body .= sprintf("Критических: %d | Предупреждений: %d\n", $critical_count, $warning_count);
        $body .= str_repeat('=', 50) . "\n\n";

        foreach ($issues as $issue) {
            $body .= sprintf("[%s] %s\n  → %s\n\n", $issue['severity'], $issue['type'], $issue['message']);
        }

        $body .= str_repeat('-', 50) . "\n";
        $body .= "Автоматическая проверка Cashback Plugin\n";

        wp_mail($admin_email, $subject, $body);

        // Дублируем в лог
        foreach ($issues as $issue) {
            error_log(sprintf('Cashback Health Check [%s] %s: %s', $issue['severity'], $issue['type'], $issue['message']));
        }
    }
}

// Обработчик WP Cron
add_action('cashback_health_check_cron', function (): void {
    Cashback_Health_Check::run();
});
