<?php
/**
 * PHP-фолбэки для логики MySQL-триггеров.
 *
 * Используются когда триггеры не могут быть созданы (binary logging + отсутствие SUPER).
 * Все методы идемпотентны — безопасно работают и при наличии триггеров.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Trigger_Fallbacks {

    /**
     * Рассчитывает кешбэк перед INSERT транзакции (замена триггера calculate_cashback_before_insert).
     *
     * @param array $data Данные для вставки (по ссылке)
     * @param bool  $is_registered true для зарегистрированных, false для незарег.
     */
    public static function calculate_cashback( array &$data, bool $is_registered = true ): void {
        if ($is_registered) {
            global $wpdb;
            $rate = 60.00;

            if (!empty($data['user_id'])) {
                $user_rate = $wpdb->get_var($wpdb->prepare(
                    "SELECT cashback_rate FROM {$wpdb->prefix}cashback_user_profile WHERE user_id = %d LIMIT 1",
                    (int) $data['user_id']
                ));
                if ($user_rate !== null) {
                    $rate = (float) $user_rate;
                }
            }

            $data['applied_cashback_rate'] = $rate;

            if (isset($data['comission']) && $data['comission'] !== null) {
                $data['cashback'] = round((float) $data['comission'] * $rate / 100, 2);
            } else {
                $data['cashback'] = 0.00;
            }
        } else {
            // Незарегистрированные — фиксированная ставка 60%
            $data['applied_cashback_rate'] = 60.00;

            if (isset($data['comission']) && $data['comission'] !== null) {
                $data['cashback'] = round((float) $data['comission'] * 0.6, 2);
            } else {
                $data['cashback'] = 0.00;
            }
        }
    }

    /**
     * Пересчитывает кешбэк при UPDATE если comission изменилась
     * (замена триггера calculate_cashback_before_update).
     *
     * @param array  $update_data Данные для обновления (по ссылке)
     * @param object $old_row     Текущая строка из БД
     * @param bool   $is_registered true для зарегистрированных
     */
    public static function recalculate_cashback_on_update( array &$update_data, object $old_row, bool $is_registered = true ): void {
        if (!isset($update_data['comission'])) {
            return;
        }

        $old_comission = isset($old_row->comission) ? (float) $old_row->comission : null;
        $new_comission = (float) $update_data['comission'];

        // Эквивалент SQL: IF NOT (OLD.comission <=> NEW.comission)
        if ($old_comission === $new_comission) {
            return;
        }

        if ($is_registered) {
            $rate = isset($old_row->applied_cashback_rate) ? (float) $old_row->applied_cashback_rate : 60.00;
        } else {
            $rate = 60.00;
        }

        $update_data['cashback'] = round($new_comission * $rate / 100, 2);
    }

    /**
     * Валидация перехода статуса транзакции (замена триггера cashback_tr_validate_status_transition).
     *
     * @param string $old_status Текущий статус
     * @param string $new_status Новый статус
     * @return true|string true если переход допустим, иначе сообщение об ошибке
     */
    public static function validate_status_transition( string $old_status, string $new_status ): string|true {
        if ($old_status === $new_status) {
            return true;
        }

        // 1. balance — финальный статус, блокировка любых изменений
        if ($old_status === 'balance') {
            return 'Изменение запрещено: запись с финальным статусом не может быть изменена.';
        }

        // 2. Возврат в waiting запрещён из любого состояния
        if ($new_status === 'waiting' && $old_status !== 'waiting') {
            return 'Понижение статуса до waiting запрещено.';
        }

        // 3. В balance — только из completed
        if ($new_status === 'balance' && $old_status !== 'completed') {
            return 'Перевод в balance возможен только из completed.';
        }

        // 4. В hold — только из completed
        if ($new_status === 'hold' && $old_status !== 'completed') {
            return 'Перевод в hold возможен только из completed.';
        }

        // 5. Из declined — только в completed
        if ($old_status === 'declined' && $new_status !== 'completed' && $new_status !== 'declined') {
            return 'Из declined возможен переход только в completed.';
        }

        return true;
    }

    /**
     * Проверка защиты выплат от изменения/удаления
     * (замена триггеров tr_prevent_update/delete_paid/failed_payout).
     *
     * @param string $current_status Текущий статус заявки
     * @return true|string true если можно изменять, иначе сообщение об ошибке
     */
    public static function validate_payout_update( string $current_status ): string|true {
        if ($current_status === 'paid') {
            return 'Изменение запрещено: выплаченная заявка не может быть изменена.';
        }

        if ($current_status === 'failed') {
            return 'Изменение запрещено: заявка со статусом failed не может быть изменена.';
        }

        return true;
    }

    /**
     * Устанавливает banned_at при бане (замена триггера tr_banned_user_update_banned_at).
     *
     * @param array $data Данные для UPDATE (по ссылке)
     */
    public static function set_banned_at( array &$data ): void {
        $data['banned_at'] = current_time('mysql');
    }

    /**
     * Очищает поля бана при разбане (замена триггера tr_clear_ban_on_unban).
     *
     * @param array $data Данные для UPDATE (по ссылке)
     */
    public static function clear_ban_fields( array &$data ): void {
        $data['banned_at']  = null;
        $data['ban_reason'] = null;
    }

    /**
     * Замораживает баланс при бане (замена триггера tr_freeze_balance_on_ban).
     * Идемпотентно: если available и pending уже 0, ничего не меняется.
     *
     * @param int $user_id ID пользователя
     */
    public static function freeze_balance_on_ban( int $user_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_balance';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, safe interpolation.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET frozen_balance = frozen_balance + available_balance + pending_balance,
                 available_balance = 0,
                 pending_balance = 0,
                 version = version + 1
             WHERE user_id = %d AND (available_balance > 0 OR pending_balance > 0)",
            $user_id
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Размораживает баланс при разбане (замена триггера tr_unfreeze_balance_on_unban).
     * Идемпотентно: если frozen уже 0, ничего не меняется.
     *
     * @param int $user_id ID пользователя
     */
    public static function unfreeze_balance_on_unban( int $user_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_balance';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, safe interpolation.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET available_balance = available_balance + frozen_balance,
                 frozen_balance = 0,
                 version = version + 1
             WHERE user_id = %d AND frozen_balance > 0",
            $user_id
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Вычисляет payload_hash перед INSERT в cashback_webhooks
     * (замена GENERATED ALWAYS AS (SHA2(payload, 256)) STORED).
     *
     * @param array $data Данные для вставки (по ссылке)
     */
    public static function compute_webhook_payload_hash( array &$data ): void {
        if (empty($data['payload_hash']) && !empty($data['payload'])) {
            $data['payload_hash'] = hash('sha256', $data['payload']);
        }
    }
}
