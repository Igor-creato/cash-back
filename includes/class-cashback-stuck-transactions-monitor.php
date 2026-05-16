<?php

/**
 * Cashback_Stuck_Transactions_Monitor — универсальный (network-agnostic)
 * cron-мониторинг транзакций, тихо НЕ доходящих до зачисления.
 *
 * Зачем (контекст): коммит ef32586 (2026-05-15) убрал click_id-fallback
 * в reconciliation — матчинг API-экшена к локальной строке теперь ТОЛЬКО
 * по uniq_id. Любой рассинхрон uniq_id-паритета сети (как был у Advcake
 * до 8266c33) приводит к МОЛЧАЛИВОМУ незачислению: строка не матчится →
 * order_status не доходит до 'completed', funds_ready не ставится. Раньше
 * это ловил только Advcake-специфичный монитор (F-1/F-2). Этот монитор
 * обобщает наблюдение на ВСЕ сети и добавляет детектор несматчиваемых
 * строк (uniq_id пуст) — чтобы класс отказа ef32586 был ГРОМКИМ.
 *
 * Каждые 6 часов делает READ-ONLY SELECT'ы:
 *   (A) подтверждённые, но не зачисленные:
 *       order_status='completed' AND api_verified=1 AND funds_ready=0
 *       AND processed_at IS NULL AND cashback>0 AND spam_click=0
 *       AND age >= STUCK_AGE_HOURS  — по ВСЕМ сетям (group by partner);
 *   (B) несматчиваемые reconciliation'ом:
 *       order_status<>'declined' AND processed_at IS NULL
 *       AND (uniq_id IS NULL OR uniq_id='') AND created_by_admin=0
 *       AND NOT <synthetic-исключения>  AND age >= STUCK_AGE_HOURS.
 * При count>0: persistent admin-notice (transient 24h) + throttled email
 * (12h, атомарный add_option CAS) через Cashback_Email_Sender::send_admin.
 *
 * Строго READ-ONLY: только SELECT + transient/option throttle. Cтатусы
 * НЕ мутируются (контракт зеркалит dedup_selftest read-only-гарантию).
 *
 * Cashback_Advcake_Stuck_Monitor оставлен НЕТРОНУТЫМ намеренно: у него
 * свой AS-хук/option-ключи/email-тип и зелёный AdvcakeStuckMonitorTest;
 * рефактор-на-месте сломал бы их и переименовал бы живой AS-хук в проде.
 * Двойной алерт по Advcake-строкам приемлем (разные throttle-окна).
 *
 * @package CashbackPlugin
 * @since   4.4.14
 */

declare(strict_types=1);

if (!defined('ABSPATH') && !defined('PHPUNIT_RUNNING')) {
    exit;
}

if (class_exists('Cashback_Stuck_Transactions_Monitor', false)) {
    return;
}

final class Cashback_Stuck_Transactions_Monitor {

    public const HOOK_NAME       = 'cashback_stuck_tx_check';
    public const CRON_GROUP      = 'cashback';
    public const PERIOD_SECONDS  = 21600;  // 6 * HOUR_IN_SECONDS
    public const STUCK_AGE_HOURS = 72;
    public const NOTICE_KEY      = 'cashback_stuck_tx_notice';
    public const EMAIL_THROTTLE  = 'cashback_stuck_tx_email_throttle';
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
     * Cron-tick. Возвращает суммарное число найденных stuck-tx (A+B)
     * для structural-тестов; в проде значение не используется.
     */
    public static function check(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_transactions';

        // ── (A) Подтверждённые API, но не зачисленные — по всем сетям.
        //    Зеркалит authoritative-гейт process_ready_transactions()
        //    (mariadb.php): completed + api_verified=1 + funds_ready=1.
        //    funds_ready=0 при выполненных прочих условиях = stuck.
        $stuck_confirmed = $wpdb->get_results($wpdb->prepare(
            'SELECT id, uniq_id, partner, order_number, comission, currency, created_at,
                    TIMESTAMPDIFF(HOUR, created_at, UTC_TIMESTAMP()) AS age_hours
               FROM %i
              WHERE order_status = %s
                AND api_verified = 1
                AND funds_ready = 0
                AND processed_at IS NULL
                AND cashback IS NOT NULL
                AND cashback > 0
                AND spam_click = 0
                AND TIMESTAMPDIFF(HOUR, created_at, UTC_TIMESTAMP()) >= %d
              ORDER BY id ASC
              LIMIT %d',
            $table,
            'completed',
            self::STUCK_AGE_HOURS,
            self::SAMPLE_LIMIT
        ));
        $stuck_confirmed = is_array($stuck_confirmed) ? $stuck_confirmed : array();

        // ── (B) Несматчиваемые reconciliation'ом: uniq_id пуст. Это и есть
        //    класс отказа ef32586 (нет click_id-fallback → строка не
        //    матчится → навсегда не зачисляется, без алерта).
        //    Синтетику (staging FXD94/lt- load-fixtures) исключаем через
        //    фильтр: НЕ хардкодим staging-маркеры в код плагина.
        $like_patterns = apply_filters('cashback_stuck_monitor_synthetic_like_patterns', array());
        $like_patterns = is_array($like_patterns) ? array_values(array_filter(array_map('strval', $like_patterns))) : array();

        $sql_b = 'SELECT id, uniq_id, partner, order_number, comission, currency, created_at,
                         TIMESTAMPDIFF(HOUR, created_at, UTC_TIMESTAMP()) AS age_hours
                    FROM %i
                   WHERE order_status <> %s
                     AND processed_at IS NULL
                     AND (uniq_id IS NULL OR uniq_id = %s)
                     AND created_by_admin = 0
                     AND TIMESTAMPDIFF(HOUR, created_at, UTC_TIMESTAMP()) >= %d';
        $args_b = array( $table, 'declined', '', self::STUCK_AGE_HOURS );
        foreach ($like_patterns as $pat) {
            $sql_b   .= ' AND order_number NOT LIKE %s';
            $args_b[] = $pat;
        }
        $sql_b   .= ' ORDER BY id ASC LIMIT %d';
        $args_b[] = self::SAMPLE_LIMIT;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql_b composed from static literals + N×' AND order_number NOT LIKE %s'; all values bound via prepare($args_b).
        $unmatchable = $wpdb->get_results($wpdb->prepare($sql_b, ...$args_b));
        $unmatchable = is_array($unmatchable) ? $unmatchable : array();

        $count_a = count($stuck_confirmed);
        $count_b = count($unmatchable);
        $total   = $count_a + $count_b;

        if ($total === 0) {
            delete_transient(self::NOTICE_KEY);
            return 0;
        }

        set_transient(
            self::NOTICE_KEY,
            array(
                'count'             => $total,
                'count_confirmed'   => $count_a,
                'count_unmatchable' => $count_b,
                'sample'            => array_slice(array_merge($stuck_confirmed, $unmatchable), 0, 5),
                'updated'           => time(),
            ),
            DAY_IN_SECONDS
        );

        if (self::claim_email_throttle()) {
            self::send_email_alert($count_a, $stuck_confirmed, $count_b, $unmatchable);
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log(sprintf(
            '[Cashback Stuck Monitor] confirmed_not_credited=%d unmatchable_uniq=%d',
            $count_a,
            $count_b
        ));

        return $total;
    }

    /**
     * Атомарно «захватить» email-throttle на окно EMAIL_PERIOD.
     * Реализация идентична Cashback_Advcake_Stuck_Monitor::claim_email_throttle()
     * (add_option-CAS, без TOCTOU): ровно один worker отправит email.
     *
     * @internal
     */
    private static function claim_email_throttle(): bool {
        global $wpdb;

        $key        = self::EMAIL_THROTTLE;
        $now        = time();
        $expires_at = (string) ( $now + self::EMAIL_PERIOD );

        if (add_option($key, $expires_at, '', false)) {
            return true;
        }

        $current = (string) get_option($key, '0');
        if ((int) $current > $now) {
            return false;
        }

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
     * Email админу через переиспользуемый Cashback_Email_Sender. Группирует
     * по классу (A/B) и по partner. Принимает уже-собранные строки.
     *
     * @param int               $count_a
     * @param array<int,object> $confirmed
     * @param int               $count_b
     * @param array<int,object> $unmatchable
     */
    private static function send_email_alert( int $count_a, array $confirmed, int $count_b, array $unmatchable ): void {
        if (!class_exists('Cashback_Email_Sender')) {
            return;
        }

        $total   = $count_a + $count_b;
        $subject = sprintf('[Cashback] %d транзакций не доходят до зачисления', $total);

        $by_partner = static function ( array $rows ): string {
            $agg = array();
            foreach ($rows as $r) {
                $p         = (string) ( $r->partner ?? '?' );
                $agg[ $p ] = ( $agg[ $p ] ?? 0 ) + 1;
            }
            $out = '';
            foreach ($agg as $p => $n) {
                $out .= sprintf('<li>%s: %d</li>', esc_html($p), (int) $n);
            }
            return $out !== '' ? '<ul>' . $out . '</ul>' : '<p>—</p>';
        };

        $sample_html = static function ( array $rows ): string {
            $html = '';
            foreach (array_slice($rows, 0, self::EMAIL_SAMPLE) as $r) {
                $html .= sprintf(
                    '<li>tx_id=%d, partner=%s, uniq_id=%s, order=%s, %s %s, age=%dh</li>',
                    (int) ( $r->id ?? 0 ),
                    esc_html((string) ( $r->partner ?? '' )),
                    esc_html((string) ( $r->uniq_id ?? '∅' )),
                    esc_html((string) ( $r->order_number ?? '' )),
                    esc_html((string) ( $r->comission ?? '0' )),
                    esc_html((string) ( $r->currency ?? '' )),
                    (int) ( $r->age_hours ?? 0 )
                );
            }
            return $html !== '' ? '<ul>' . $html . '</ul>' : '';
        };

        $body = sprintf(
            '<p>Обнаружены транзакции, тихо НЕ доходящие до зачисления в баланс '
            . '(age &geq; %1$dh).</p>'
            . '<h3>A. Подтверждены API, но funds_ready=0 (%2$d)</h3>'
            . '<p>order_status=completed, api_verified=1, funds_ready=0, не зачислены. '
            . 'Причины: сеть не вернула признак готовности средств, либо новый статус '
            . 'вне whitelist адаптера.</p>'
            . '<p>По сетям:</p>%3$s%4$s'
            . '<h3>B. Несматчиваемые reconciliation\'ом: uniq_id пуст (%5$d)</h3>'
            . '<p>Класс отказа ef32586 (нет click_id-fallback): строки без uniq_id '
            . 'НИКОГДА не сматчатся с API → навсегда не зачислятся. Проверьте '
            . 'uniq_id-паритет сети (receiver_uniq_source ↔ api_field_map) и '
            . 'webhook-receiver mapping.</p>'
            . '<p>По сетям:</p>%6$s%7$s',
            self::STUCK_AGE_HOURS,
            $count_a,
            $by_partner($confirmed),
            $sample_html($confirmed),
            $count_b,
            $by_partner($unmatchable),
            $sample_html($unmatchable)
        );

        Cashback_Email_Sender::get_instance()->send_admin(
            $subject,
            $body,
            'stuck_transactions_universal'
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
            esc_html__('Cashback:', 'cash-back'),
            sprintf(
                /* translators: %1$d total, %2$d confirmed-not-credited, %3$d unmatchable, %4$d threshold hours */
                esc_html__('%1$d транзакций не доходят до зачисления (A: подтверждены но funds_ready=0 — %2$d; B: uniq_id пуст, несматчиваемые — %3$d; age ≥ %4$dh). Проверьте admin email / логи.', 'cash-back'),
                (int) $data['count'],
                (int) ( $data['count_confirmed'] ?? 0 ),
                (int) ( $data['count_unmatchable'] ?? 0 ),
                absint(self::STUCK_AGE_HOURS)
            )
        );
    }
}
