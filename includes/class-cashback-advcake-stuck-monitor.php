<?php

/**
 * Cashback_Advcake_Stuck_Monitor — cron-мониторинг застрявших Advcake-транзакций.
 *
 * Closes audit-findings F-1, F-2 (Advcake v4.3.4):
 *   - F-1: Advcake вернул `<status>2</status>` без `<payment_status>` →
 *     `funds_ready=0` навсегда → tx висит, баланс не зачисляется.
 *   - F-2: Advcake ввёл новое значение payment_status (не в whitelist
 *     balance/processing/withdrawal) → тот же эффект.
 *
 * Оба сценария → один наблюдаемый признак: `cashback_transactions` row с
 *   partner ∈ {'advcake','Advcake'} AND order_status='completed'
 *   AND api_verified=1 AND funds_ready=0 AND processed_at IS NULL
 *   AND age >= 72h.
 *
 * Cron каждые 4 часа делает SELECT по условию выше; при count > 0:
 *   1) ставит persistent admin-notice (transient, 24h TTL);
 *   2) если в течение 12h email ещё не слался — отправляет email админу
 *      через Cashback_Email_Sender::send_admin().
 *
 * @package CashbackPlugin
 * @since   12.4.0
 */

declare(strict_types=1);

if (!defined('ABSPATH') && !defined('PHPUNIT_RUNNING')) {
    exit;
}

if (class_exists('Cashback_Advcake_Stuck_Monitor', false)) {
    return;
}

final class Cashback_Advcake_Stuck_Monitor {

    public const HOOK_NAME       = 'cashback_advcake_stuck_check';
    public const CRON_GROUP      = 'cashback';
    public const PERIOD_SECONDS  = 14400;  // 4 * HOUR_IN_SECONDS
    public const STUCK_AGE_HOURS = 72;
    public const NOTICE_KEY      = 'cashback_advcake_stuck_notice';
    public const EMAIL_THROTTLE  = 'cashback_advcake_stuck_email_throttle';
    public const EMAIL_PERIOD    = 43200; // 12 * HOUR_IN_SECONDS
    public const SAMPLE_LIMIT    = 100;
    public const EMAIL_SAMPLE    = 10;

    /**
     * Регистрация action-handler'а + ленивое создание recurring AS-задачи.
     * Идемпотентна: повторный вызов не плодит дубли.
     */
    public static function register(): void {
        add_action(self::HOOK_NAME, array( self::class, 'run_hook' ));

        if (!function_exists('as_schedule_recurring_action') || !function_exists('as_has_scheduled_action')) {
            return;
        }
        if (!as_has_scheduled_action(self::HOOK_NAME, array(), self::CRON_GROUP)) {
            as_schedule_recurring_action(time() + 600, self::PERIOD_SECONDS, self::HOOK_NAME, array(), self::CRON_GROUP);
        }
    }

    /**
     * Action Scheduler callback (void). Wrapper над {@see check()} для соблюдения
     * контракта «Action callback returns nothing» (PHPStan rule в плагине).
     */
    public static function run_hook(): void {
        self::check();
    }

    /**
     * Точка входа cron-tick'а. Возвращает количество найденных stuck-tx
     * (для structural тестов; в проде значение не используется).
     */
    public static function check(): int {
        global $wpdb;

        // Table-name через %i placeholder вместо string-concat $wpdb->prefix —
        // соблюдает конвенцию плагина (partner-status-sync, promocodes-repository,
        // shop-tariff-sync) и закрывает аудит-наблюдение L-1 (defense-in-depth).
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, uniq_id, partner, order_number, comission, currency, created_at,
                    TIMESTAMPDIFF(HOUR, created_at, UTC_TIMESTAMP()) AS age_hours
               FROM %i
              WHERE partner IN (%s, %s)
                AND order_status = %s
                AND api_verified = 1
                AND funds_ready = 0
                AND processed_at IS NULL
                AND TIMESTAMPDIFF(HOUR, created_at, UTC_TIMESTAMP()) >= %d
              ORDER BY id ASC
              LIMIT %d',
            $wpdb->prefix . 'cashback_transactions',
            'advcake',
            'Advcake',
            'completed',
            self::STUCK_AGE_HOURS,
            self::SAMPLE_LIMIT
        ));

        $count = is_array($rows) ? count($rows) : 0;
        if ($count === 0) {
            delete_transient(self::NOTICE_KEY);
            return 0;
        }

        set_transient(
            self::NOTICE_KEY,
            array(
                'count'   => $count,
                'sample'  => array_slice($rows, 0, 5),
                'updated' => time(),
            ),
            DAY_IN_SECONDS
        );

        // Атомарный CAS-throttle через add_option (вместо TOCTOU get_transient→set_transient).
        // Закрывает аудит-наблюдение L-2: при параллельных AS-task'ах ровно один
        // worker получает true и отправляет email; остальные — false.
        if (self::claim_email_throttle()) {
            self::send_email_alert($count, $rows);
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log(sprintf(
            '[Cashback Advcake Monitor] Stuck=%d sample_ids=%s',
            $count,
            implode(',', array_map(static fn( $r ) => (string) $r->id, array_slice($rows, 0, 10)))
        ));

        return $count;
    }

    /**
     * Атомарно «захватить» email-throttle на окно EMAIL_PERIOD.
     *
     * Возвращает:
     *   - true  — мы первые в окне, разрешено отправлять email.
     *   - false — другой worker уже отправляет (или окно ещё активно).
     *
     * Реализация:
     *   1. {@see add_option()} — атомарный INSERT (MySQL UNIQUE на option_name).
     *      Если опции ещё нет — true; если есть — false без перезаписи.
     *   2. Если опция есть и не истекла (> time()) — return false.
     *   3. Если опция есть но истекла — атомарный CAS-UPDATE через wpdb->update
     *      с двойным WHERE (option_name AND option_value=$current). При параллельном
     *      запуске двух worker'ов на истёкшее окно ровно один получит affected=1.
     *
     * Закрывает audit-finding L-2 (Advcake-hardening v4.3.5): TOCTOU race на
     * get_transient → set_transient. Лишних писем больше не будет.
     *
     * @internal
     */
    private static function claim_email_throttle(): bool {
        global $wpdb;

        $key        = self::EMAIL_THROTTLE;
        $now        = time();
        $expires_at = (string) ( $now + self::EMAIL_PERIOD );

        // Попытка 1: атомарный INSERT (опции ещё нет).
        if (add_option($key, $expires_at, '', false)) {
            return true;
        }

        // Опция существует — проверяем не истекла ли.
        $current = (string) get_option($key, '0');
        if ((int) $current > $now) {
            return false;
        }

        // Истекла — атомарный CAS-UPDATE. При параллельном race один worker
        // успеет первым (affected_rows=1), остальные получат 0.
        $r = $wpdb->update(
            $wpdb->options,
            array( 'option_value' => $expires_at ),
            array( 'option_name' => $key, 'option_value' => $current ),
            array( '%s' ),
            array( '%s', '%s' )
        );
        return $r === 1;
    }

    /**
     * Реальная отправка email админу через переиспользуемый Cashback_Email_Sender.
     * Принимает уже-собранный набор строк (без повторного SELECT'а).
     *
     * @param int                  $count
     * @param array<int,object>    $rows
     */
    private static function send_email_alert( int $count, array $rows ): void {
        if (!class_exists('Cashback_Email_Sender')) {
            return;
        }

        $subject = sprintf('[Cashback] Advcake: %d застрявших транзакций', $count);

        $sample_html = '';
        $sample      = array_slice($rows, 0, self::EMAIL_SAMPLE);
        foreach ($sample as $r) {
            $sample_html .= sprintf(
                '<li>tx_id=%d, uniq_id=%s, order=%s, commission=%s %s, age=%dh</li>',
                (int) ( $r->id ?? 0 ),
                esc_html((string) ( $r->uniq_id ?? '' )),
                esc_html((string) ( $r->order_number ?? '' )),
                esc_html((string) ( $r->comission ?? '0' )),
                esc_html((string) ( $r->currency ?? '' )),
                (int) ( $r->age_hours ?? 0 )
            );
        }

        $body = sprintf(
            '<p>Обнаружено <strong>%1$d</strong> транзакций Advcake в состоянии stuck:<br>'
            . 'order_status=completed, api_verified=1, funds_ready=0, age &geq; %2$dh.</p>'
            . '<p>Возможные причины:</p>'
            . '<ol>'
            . '<li>Advcake не вернул <code>&lt;payment_status&gt;</code> для подтверждённой транзакции (пустое поле);</li>'
            . '<li>Advcake ввёл новое значение payment_status, которого нет в whitelist '
            . '<code>balance/processing/withdrawal</code> — нужно расширить '
            . '<code>Cashback_Advcake_Adapter::normalize_xml_item()</code>.</li>'
            . '</ol>'
            . '<p>Действие: открыть <a href="https://my.advcake.ru/">кабинет Advcake</a>, '
            . 'сверить статусы по списку ниже, либо обновить whitelist в адаптере.</p>'
            . '<ul>%3$s</ul>',
            $count,
            self::STUCK_AGE_HOURS,
            $sample_html
        );

        Cashback_Email_Sender::get_instance()->send_admin(
            $subject,
            $body,
            'advcake_stuck_transactions'
        );
    }

    /**
     * Admin-notice handler. Подписывается только если transient выставлен.
     */
    public static function notice(): void {
        $data = get_transient(self::NOTICE_KEY);
        if (!is_array($data) || empty($data['count'])) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
            esc_html__('Cashback Advcake:', 'cash-back'),
            sprintf(
                /* translators: %1$d — count, %2$d — threshold hours */
                esc_html__('обнаружено %1$d застрявших транзакций (age ≥ %2$dh, funds_ready=0). Проверьте логи или admin email.', 'cash-back'),
                (int) $data['count'],
                absint(self::STUCK_AGE_HOURS)
            )
        );
    }
}
