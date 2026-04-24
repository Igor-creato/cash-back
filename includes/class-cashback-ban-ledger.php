<?php
/**
 * Ledger-запись для заморозки/разморозки баланса при бане пользователя.
 *
 * Группа 14 (ledger-first coverage): до этого модуля ban/unban меняли
 * cashback_user_balance через MySQL-триггер или PHP-fallback (см. Cashback_Trigger_Fallbacks),
 * но не писали в cashback_balance_ledger. Consistency-чекер
 * Mariadb_Plugin::validate_user_balance_consistency() поэтому давал false-positive
 * для каждого забаненного пользователя.
 *
 * Этот класс:
 *   - читает баланс пользователя через SELECT ... FOR UPDATE (должен вызываться
 *     внутри START TRANSACTION — например, из handle_user_ban / handle_user_unban
 *     в admin/users-management.php, которые уже открывают TX),
 *   - вычисляет сумму ban-бакетов,
 *   - пишет в cashback_balance_ledger запись типа ban_freeze (отрицательный amount)
 *     или ban_unfreeze (положительный amount) через INSERT ... ON DUPLICATE KEY UPDATE id=id,
 *     что делает операцию идемпотентной по UNIQUE idempotency_key.
 *
 * Предполагается, что cashback_user_balance уже обновлён к моменту вызова —
 * MySQL-триггером tr_freeze_balance_on_ban или PHP-fallback'ом
 * Cashback_Trigger_Fallbacks::freeze_balance_on_ban(). Мы просто фиксируем в ledger
 * уже произошедшее перемещение между бакетами.
 *
 * idempotency_key формат:
 *   ban_freeze_{user_id}_{banned_at_unix}
 *   ban_unfreeze_{user_id}_{banned_at_unix}
 *
 * banned_at берётся из cashback_user_profile.banned_at — для freeze значение
 * только что установлено (set_banned_at), для unfreeze значение бралось ДО очистки
 * (clear_ban_fields обнуляет поле), поэтому вызов должен произойти до UPDATE profile
 * с clear_ban_fields. Caller отвечает за передачу правильного unix-timestamp.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Ban_Ledger {

    /**
     * Пишет запись ban_freeze в ledger, фиксируя заморозку баланса при бане.
     *
     * Должна вызываться ПОСЛЕ того, как срабатывают MySQL-триггер или PHP-fallback,
     * перемещающие available/pending → frozen_balance_ban/frozen_pending_balance_ban.
     * Caller должен держать START TRANSACTION.
     *
     * Идемпотентно: при повторном вызове с тем же $banned_at_unix UNIQUE idempotency_key
     * сработает через ON DUPLICATE KEY UPDATE id=id (без изменения).
     *
     * @param int $user_id        ID пользователя.
     * @param int $banned_at_unix Unix-timestamp из cashback_user_profile.banned_at —
     *                            часть idempotency_key для детерминированности.
     *
     * @return array{written:bool, amount:string, idempotency_key:string}
     *                            written=false если нечего замораживать (оба ban-бакета = 0).
     */
    public static function write_freeze_entry( int $user_id, int $banned_at_unix ): array {
        global $wpdb;

        $balance_table = $wpdb->prefix . 'cashback_user_balance';
        $ledger_table  = $wpdb->prefix . 'cashback_balance_ledger';

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT frozen_balance_ban, frozen_pending_balance_ban
             FROM %i
             WHERE user_id = %d
             FOR UPDATE',
            $balance_table,
            $user_id
        ));

        $frozen_available = $row ? (string) $row->frozen_balance_ban : '0.00';
        $frozen_pending   = $row ? (string) $row->frozen_pending_balance_ban : '0.00';

        // Суммарная сумма ban-бакетов = замороженный фактический объём.
        $total = bcadd(
            self::normalize_money($frozen_available),
            self::normalize_money($frozen_pending),
            2
        );

        $idempotency_key = sprintf('ban_freeze_%d_%d', $user_id, $banned_at_unix);

        if (bccomp($total, '0.00', 2) <= 0) {
            return array(
                'written'         => false,
                'amount'          => '0.00',
                'idempotency_key' => $idempotency_key,
            );
        }

        // amount со знаком: списание из available+pending → отрицательное значение.
        $amount = bcmul($total, '-1', 2);

        $result = $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (user_id, type, amount, reference_type, idempotency_key)
             VALUES (%d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE id = id',
            $ledger_table,
            $user_id,
            'ban_freeze',
            $amount,
            'ban',
            $idempotency_key
        ));

        if ($result === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $wpdb->last_error is diagnostic DB error text surfaced to audit/admin log, not to unsafe HTML.
            throw new \RuntimeException('Ban ledger INSERT (freeze) failed: ' . $wpdb->last_error);
        }

        return array(
            'written'         => ( (int) $wpdb->rows_affected === 1 ),
            'amount'          => $amount,
            'idempotency_key' => $idempotency_key,
        );
    }

    /**
     * Пишет запись ban_unfreeze в ledger при разбане.
     *
     * Вызывается ДО того, как MySQL-триггер tr_unfreeze_balance_on_unban или
     * PHP-fallback Cashback_Trigger_Fallbacks::unfreeze_balance_on_unban()
     * переместят значения обратно — поэтому тут читаем и фиксируем ban-бакеты
     * ПЕРЕД их обнулением. Caller должен держать START TRANSACTION.
     *
     * Идемпотентно через UNIQUE idempotency_key = ban_unfreeze_{user_id}_{banned_at_unix}.
     *
     * @param int $user_id        ID пользователя.
     * @param int $banned_at_unix Unix-timestamp из cashback_user_profile.banned_at —
     *                            тот самый, что использовался при freeze.
     *
     * @return array{written:bool, amount:string, idempotency_key:string}
     */
    public static function write_unfreeze_entry( int $user_id, int $banned_at_unix ): array {
        global $wpdb;

        $balance_table = $wpdb->prefix . 'cashback_user_balance';
        $ledger_table  = $wpdb->prefix . 'cashback_balance_ledger';

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT frozen_balance_ban, frozen_pending_balance_ban
             FROM %i
             WHERE user_id = %d
             FOR UPDATE',
            $balance_table,
            $user_id
        ));

        $frozen_available = $row ? (string) $row->frozen_balance_ban : '0.00';
        $frozen_pending   = $row ? (string) $row->frozen_pending_balance_ban : '0.00';

        $total = bcadd(
            self::normalize_money($frozen_available),
            self::normalize_money($frozen_pending),
            2
        );

        $idempotency_key = sprintf('ban_unfreeze_%d_%d', $user_id, $banned_at_unix);

        if (bccomp($total, '0.00', 2) <= 0) {
            return array(
                'written'         => false,
                'amount'          => '0.00',
                'idempotency_key' => $idempotency_key,
            );
        }

        // amount со знаком: возврат в available+pending → положительное значение.
        $amount = $total;

        $result = $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (user_id, type, amount, reference_type, idempotency_key)
             VALUES (%d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE id = id',
            $ledger_table,
            $user_id,
            'ban_unfreeze',
            $amount,
            'ban',
            $idempotency_key
        ));

        if ($result === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $wpdb->last_error is diagnostic DB error text surfaced to audit/admin log, not to unsafe HTML.
            throw new \RuntimeException('Ban ledger INSERT (unfreeze) failed: ' . $wpdb->last_error);
        }

        return array(
            'written'         => ( (int) $wpdb->rows_affected === 1 ),
            'amount'          => $amount,
            'idempotency_key' => $idempotency_key,
        );
    }

    /**
     * Приводит money-строку к канонической форме "N.NN" для безопасного bccomp/bcadd.
     * Входящие значения могут быть "0", "50", "50.5", "50.50" — нормализуем до двух знаков.
     */
    private static function normalize_money( string $value ): string {
        if ($value === '' || $value === null) {
            return '0.00';
        }

        return bcadd($value, '0.00', 2);
    }
}
