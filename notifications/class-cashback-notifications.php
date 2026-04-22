<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Оркестратор email-уведомлений
 *
 * Подписывается на WordPress actions и отправляет email через Cashback_Email_Sender.
 *
 * Типы уведомлений:
 * 1. transaction_new       — Новая транзакция (покупка через партнёра)
 * 2. transaction_status    — Изменение статуса транзакции
 * 3. cashback_credited     — Начисление кэшбэка на баланс
 * 4. user_registered       — Регистрация нового пользователя (welcome email)
 * 5. ticket_reply          — Ответ администратора на тикет → пользователю
 * 6. ticket_admin_alert    — Новый тикет / ответ пользователя → администратору
 * 7. claim_created         — Заявка на кэшбэк создана → пользователю
 * 8. claim_status          — Статус заявки изменён → пользователю
 * 9. claim_admin_alert     — Новая заявка → администратору
 */
class Cashback_Notifications {

    public function __construct() {
        // Транзакции (PHP do_action — для случаев когда INSERT идёт через PHP-код плагина)
        add_action('cashback_notification_transaction_created', array( $this, 'on_transaction_created' ), 10, 2);
        add_action('cashback_notification_transaction_status_changed', array( $this, 'on_transaction_status_changed' ), 10, 4);

        // Обработка очереди из MySQL триггеров (для постбэков, вставленных напрямую в БД)
        add_action('cashback_notification_process_queue', array( $this, 'process_queue' ));

        // Планирование через Action Scheduler (WooCommerce — жёсткая зависимость плагина,
        // AS загружается им автоматически). Даёт защиту от параллельного запуска (claim)
        // и UI мониторинга в WooCommerce → Status → Scheduled Actions.
        add_action('init', function () {
            if (function_exists('as_has_scheduled_action')
                && function_exists('as_schedule_recurring_action')
                && !as_has_scheduled_action('cashback_notification_process_queue')
            ) {
                as_schedule_recurring_action(time(), 60, 'cashback_notification_process_queue', array(), 'cashback');
            }
        });

        // Начисление кэшбэка на баланс
        add_action('cashback_notification_balance_credited', array( $this, 'on_balance_credited' ), 10, 1);

        // Отмена заявки на выплату с возвратом средств на available_balance
        add_action('cashback_notification_payout_refunded', array( $this, 'on_payout_refunded' ), 10, 4);

        // Регистрация пользователя
        add_action('cashback_notification_user_registered', array( $this, 'on_user_registered' ), 10, 1);

        // Поддержка — ответ админа → пользователю
        add_action('cashback_notification_ticket_reply', array( $this, 'on_ticket_reply' ), 10, 4);

        // Поддержка — пользователь → админу
        add_action('cashback_notification_ticket_admin_alert', array( $this, 'on_ticket_admin_alert' ), 10, 3);

        // Заявки (claims) — перехватываем существующие хуки
        add_action('cashback_claim_created', array( $this, 'on_claim_created' ), 10, 3);
        add_action('cashback_claim_created', array( $this, 'on_claim_admin_alert' ), 10, 3);
        add_action('cashback_claim_status_changed', array( $this, 'on_claim_status_changed' ), 10, 6);

        // Партнёрская программа (affiliate)
        add_action('cashback_notification_affiliate_referral', array( $this, 'on_affiliate_referral' ), 10, 2);
        add_action('cashback_notification_affiliate_commission', array( $this, 'on_affiliate_commission' ), 10, 1);
    }

    // =====================================================================
    // Транзакции
    // =====================================================================

    /**
     * Новая транзакция создана
     *
     * @param int   $transaction_id ID транзакции
     * @param array $data           Данные транзакции (user_id, partner, sum_order, comission, order_status и т.д.)
     */
    public function on_transaction_created( int $transaction_id, array $data ): void {
        $user_id = (int) ( $data['user_id'] ?? 0 );
        if ($user_id <= 0) {
            return;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        $offer     = $data['offer_name'] ?? '—';
        $sum_order = isset($data['sum_order']) ? number_format((float) $data['sum_order'], 2, ',', ' ') : '—';

        $subject = sprintf(
            /* translators: %s: название магазина (offer). */
            __('Новая покупка в магазине %s', 'cashback-plugin'),
            $offer
        );

        $message = sprintf(
            /* translators: %1$s: имя пользователя, %2$s: магазин, %3$s: сумма заказа, %4$s: URL личного кабинета. */
            __('Здравствуйте, %1$s!

Ваша покупка зафиксирована.

Магазин: %2$s
Сумма заказа: %3$s ₽
Статус: В ожидании

Отслеживайте статус в личном кабинете: %4$s', 'cashback-plugin'),
            $user->display_name,
            $offer,
            $sum_order,
            function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('cashback-history') : ''
        );

        Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $subject,
            $message,
            'transaction_new',
            $user_id
        );
    }

    /**
     * Статус транзакции изменён
     *
     * @param int    $transaction_id ID транзакции
     * @param int    $user_id        ID пользователя
     * @param string $old_status     Старый статус
     * @param string $new_status     Новый статус
     */
    public function on_transaction_status_changed( int $transaction_id, int $user_id, string $old_status, string $new_status ): void {
        if ($user_id <= 0 || $old_status === $new_status) {
            return;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        $status_labels = array(
            'waiting'   => __('В ожидании', 'cashback-plugin'),
            'completed' => __('Подтверждён', 'cashback-plugin'),
            'hold'      => __('На проверке', 'cashback-plugin'),
            'declined'  => __('Отклонён', 'cashback-plugin'),
            'balance'   => __('Зачислен на баланс', 'cashback-plugin'),
        );

        $new_label = $status_labels[ $new_status ] ?? $new_status;

        $subject = sprintf(
            /* translators: %1$d: ID транзакции, %2$s: название нового статуса. */
            __('Статус транзакции #%1$d изменён на «%2$s»', 'cashback-plugin'),
            $transaction_id,
            $new_label
        );

        $message = sprintf(
            /* translators: %1$s: имя пользователя, %2$d: ID транзакции, %3$s: старый статус, %4$s: новый статус, %5$s: URL личного кабинета. */
            __('Здравствуйте, %1$s!

Статус вашей транзакции #%2$d изменён.

Предыдущий статус: %3$s
Новый статус: %4$s

Подробности в личном кабинете: %5$s', 'cashback-plugin'),
            $user->display_name,
            $transaction_id,
            $status_labels[ $old_status ] ?? $old_status,
            $new_label,
            function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('cashback-history') : ''
        );

        Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $subject,
            $message,
            'transaction_status',
            $user_id
        );
    }

    /**
     * Данные транзакции изменены (комиссия, сумма заказа, кэшбэк)
     *
     * @param int    $transaction_id ID транзакции
     * @param int    $user_id        ID пользователя
     * @param string $partner        Название партнёра
     * @param array  $changes        Данные изменений из JSON
     */
    public function on_transaction_data_changed( int $transaction_id, int $user_id, string $partner, array $changes ): void {
        if ($user_id <= 0) {
            return;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        // Формируем описание изменений (без комиссии — она не показывается пользователю)
        $change_lines = array();

        $old_sum = (float) ( $changes['old_sum_order'] ?? 0 );
        $new_sum = (float) ( $changes['new_sum_order'] ?? 0 );
        if (abs($old_sum - $new_sum) >= 0.01) {
            $change_lines[] = sprintf(
                /* translators: %1$s: старая сумма заказа, %2$s: новая сумма заказа. */
                __('Сумма заказа: %1$s → %2$s ₽', 'cashback-plugin'),
                number_format($old_sum, 2, ',', ' '),
                number_format($new_sum, 2, ',', ' ')
            );
        }

        $old_cashback = (float) ( $changes['old_cashback'] ?? 0 );
        $new_cashback = (float) ( $changes['new_cashback'] ?? 0 );
        if (abs($old_cashback - $new_cashback) >= 0.01) {
            $change_lines[] = sprintf(
                /* translators: %1$s: старая сумма кэшбэка, %2$s: новая сумма кэшбэка. */
                __('Кэшбэк: %1$s → %2$s ₽', 'cashback-plugin'),
                number_format($old_cashback, 2, ',', ' '),
                number_format($new_cashback, 2, ',', ' ')
            );
        }

        if (empty($change_lines)) {
            return;
        }

        $subject = sprintf(
            /* translators: %d: ID транзакции. */
            __('Данные транзакции #%d обновлены', 'cashback-plugin'),
            $transaction_id
        );

        $message = sprintf(
            /* translators: %1$s: имя пользователя, %2$d: ID транзакции, %3$s: название партнёра, %4$s: список изменений, %5$s: URL личного кабинета. */
            __('Здравствуйте, %1$s!

Данные вашей транзакции #%2$d обновлены партнёром %3$s.

Изменения:
%4$s

Подробности в личном кабинете: %5$s', 'cashback-plugin'),
            $user->display_name,
            $transaction_id,
            $partner ?: '—',
            implode("\n", $change_lines),
            function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('cashback-history') : ''
        );

        Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $subject,
            $message,
            'transaction_status',
            $user_id
        );
    }

    // =====================================================================
    // Начисление кэшбэка
    // =====================================================================

    /**
     * Кэшбэк начислен на баланс (батчевое начисление)
     *
     * @param array $candidates Массив [['id' => ..., 'user_id' => ..., 'cashback' => ...], ...]
     */
    public function on_balance_credited( array $candidates ): void {
        // Группируем по user_id — один email на пользователя
        $grouped = array();
        foreach ($candidates as $row) {
            $uid = (int) $row['user_id'];
            if ($uid <= 0) {
                continue;
            }
            if (!isset($grouped[ $uid ])) {
                $grouped[ $uid ] = array(
					'total' => 0.0,
					'count' => 0,
				);
            }
            $grouped[ $uid ]['total'] += (float) $row['cashback'];
            ++$grouped[ $uid ]['count'];
        }

        $sender = Cashback_Email_Sender::get_instance();

        foreach ($grouped as $user_id => $info) {
            $user = get_user_by('id', $user_id);
            if (!$user) {
                continue;
            }

            $total_formatted = number_format($info['total'], 2, ',', ' ');

            $subject = sprintf(
                /* translators: %s: сумма кэшбэка. */
                __('Кэшбэк %s ₽ зачислен на ваш баланс', 'cashback-plugin'),
                $total_formatted
            );

            if ($info['count'] === 1) {
                $count_text = __('1 транзакция подтверждена', 'cashback-plugin');
            } else {
                $count_text = sprintf(
                    /* translators: %d: количество подтверждённых транзакций. */
                    __('%d транзакций подтверждено', 'cashback-plugin'),
                    $info['count']
                );
            }

            $message = sprintf(
                /* translators: %1$s: имя пользователя, %2$s: сумма кэшбэка, %3$s: текст о количестве транзакций, %4$s: URL вывода. */
                __('Здравствуйте, %1$s!

На ваш баланс начислен кэшбэк.

Сумма: %2$s ₽
%3$s

Средства доступны для вывода: %4$s', 'cashback-plugin'),
                $user->display_name,
                $total_formatted,
                $count_text,
                function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('cashback-withdrawal') : ''
            );

            $sender->send(
                $user->user_email,
                $subject,
                $message,
                'cashback_credited',
                $user_id
            );
        }
    }

    // =====================================================================
    // Возврат средств по отменённой заявке на выплату
    // =====================================================================

    /**
     * Заявка на выплату отменена, pending → available.
     *
     * Триггерится из двух точек в admin/payouts.php:
     *   - `cancel_payout_with_refund()` (encryption recovery + AJAX-возврат);
     *   - `update_payout_status()` при переходе waiting/processing/needs_retry → failed.
     *
     * @param int    $payout_id  ID заявки
     * @param int    $user_id    ID пользователя
     * @param string $amount     Сумма, возвращённая на баланс (строка с двумя знаками после запятой)
     * @param string $reason     Машинный код причины (encryption_recovery / manual_encryption_recovery / admin_cancel / произвольный fail_reason)
     */
    public function on_payout_refunded( int $payout_id, int $user_id, string $amount, string $reason ): void {
        if ($user_id <= 0 || $payout_id <= 0) {
            return;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        $amount_formatted = number_format((float) $amount, 2, ',', ' ');

        // Человекочитаемая причина.
        $reason_text = $this->format_refund_reason($reason);

        $subject = sprintf(
            /* translators: %d: ID заявки на выплату. */
            __('Заявка на выплату №%d отменена — средства возвращены на баланс', 'cashback-plugin'),
            $payout_id
        );

        $message = sprintf(
            /* translators: %1$s: имя пользователя, %2$d: ID заявки, %3$s: сумма, %4$s: причина отмены, %5$s: URL личного кабинета. */
            __('Здравствуйте, %1$s!

Ваша заявка на выплату №%2$d отменена.

Сумма %3$s ₽ возвращена на ваш доступный баланс — вы можете создать новую заявку на выплату в личном кабинете.

Причина отмены: %4$s

Личный кабинет: %5$s', 'cashback-plugin'),
            $user->display_name,
            $payout_id,
            $amount_formatted,
            $reason_text,
            function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('cashback-withdrawal') : ''
        );

        Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $subject,
            $message,
            'payout_refunded',
            $user_id
        );
    }

    /**
     * Преобразует машинный код reason в текст для пользователя.
     */
    private function format_refund_reason( string $reason ): string {
        $reason = trim($reason);

        if ($reason === 'encryption_recovery' || $reason === 'manual_encryption_recovery') {
            return __('технические работы с системой безопасности. Чтобы ваша заявка не потерялась, мы её отменили и вернули средства на доступный баланс. При подаче новой заявки, пожалуйста, обновите реквизиты для выплаты', 'cashback-plugin');
        }

        if ($reason === '' || $reason === 'admin_cancel') {
            return __('отменено администратором', 'cashback-plugin');
        }

        // Произвольный fail_reason из admin UI — показываем как есть (sanitize_text_field уже прошёл на вставке).
        return $reason;
    }

    // =====================================================================
    // Регистрация
    // =====================================================================

    /**
     * Новый пользователь зарегистрирован в системе кэшбэка
     *
     * @param int $user_id ID пользователя
     */
    public function on_user_registered( int $user_id ): void {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        $subject = __('Добро пожаловать в программу кэшбэка!', 'cashback-plugin');

        $message = sprintf(
            /* translators: %1$s: имя пользователя, %2$s: URL личного кабинета. */
            __('Здравствуйте, %1$s!

Вы успешно зарегистрированы в программе кэшбэка.

Теперь при покупках через наши партнёрские ссылки вы будете получать возврат части стоимости.

Как это работает:
1. Перейдите в магазин через ссылку на нашем сайте
2. Совершите покупку
3. Дождитесь подтверждения — кэшбэк появится в вашем балансе
4. Выведите средства удобным способом

Личный кабинет: %2$s', 'cashback-plugin'),
            $user->display_name,
            function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('cashback-history') : home_url('/my-account/')
        );

        Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $subject,
            $message,
            'user_registered',
            $user_id
        );
    }

    // =====================================================================
    // Поддержка (тикеты)
    // =====================================================================

    /**
     * Ответ администратора на тикет — уведомление пользователю
     *
     * @param int    $ticket_id     ID тикета
     * @param string $subject       Тема тикета
     * @param int    $user_id       ID пользователя-владельца тикета
     * @param string $admin_message Текст ответа
     */
    public function on_ticket_reply( int $ticket_id, string $subject, int $user_id, string $admin_message ): void {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        $ticket_number = '';
        if (class_exists('Cashback_Support_DB')) {
            $ticket_number = Cashback_Support_DB::format_ticket_number($ticket_id);
        } else {
            $ticket_number = '#' . $ticket_id;
        }

        $email_subject = sprintf(
            /* translators: %1$s: номер тикета, %2$s: тема тикета. */
            __('Ответ на тикет %1$s: %2$s', 'cashback-plugin'),
            $ticket_number,
            $subject
        );

        $message = sprintf(
            /* translators: %1$s: имя пользователя, %2$s: номер тикета, %3$s: тема тикета, %4$s: текст ответа, %5$s: URL переписки. */
            __('Здравствуйте, %1$s!

Вы получили ответ на тикет %2$s «%3$s».

Ответ:
%4$s

Просмотреть переписку: %5$s', 'cashback-plugin'),
            $user->display_name,
            $ticket_number,
            $subject,
            wp_trim_words($admin_message, 100, '...'),
            function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('cashback-support') : ''
        );

        Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $email_subject,
            $message,
            'ticket_reply',
            $user_id
        );
    }

    /**
     * Новый тикет или ответ пользователя — уведомление администраторам
     *
     * @param int    $ticket_id  ID тикета
     * @param string $event_type 'new_ticket' или 'user_reply'
     * @param string $subject    Тема тикета
     */
    public function on_ticket_admin_alert( int $ticket_id, string $event_type, string $subject ): void {
        $ticket_number = '';
        if (class_exists('Cashback_Support_DB')) {
            $ticket_number = Cashback_Support_DB::format_ticket_number($ticket_id);
        } else {
            $ticket_number = '#' . $ticket_id;
        }

        $user      = wp_get_current_user();
        $admin_url = admin_url('admin.php?page=cashback-support&action=view&ticket_id=' . $ticket_id);

        if ($event_type === 'new_ticket') {
            $email_subject = sprintf(
                /* translators: %1$s: номер тикета, %2$s: тема тикета. */
                __('Новый тикет %1$s: %2$s', 'cashback-plugin'),
                $ticket_number,
                $subject
            );
        } else {
            $email_subject = sprintf(
                /* translators: %1$s: номер тикета, %2$s: тема тикета. */
                __('Новый ответ в тикете %1$s: %2$s', 'cashback-plugin'),
                $ticket_number,
                $subject
            );
        }

        $message = sprintf(
            /* translators: %1$s: логин пользователя, %2$s: email пользователя, %3$s: тема тикета, %4$s: URL в админке. */
            __('Пользователь: %1$s (%2$s)
Тема: %3$s

Просмотреть в админке: %4$s', 'cashback-plugin'),
            $user->user_login ?? '—',
            $user->user_email ?? '—',
            $subject,
            $admin_url
        );

        Cashback_Email_Sender::get_instance()->send_admin(
            $email_subject,
            $message,
            'ticket_admin_alert'
        );
    }

    // =====================================================================
    // Заявки (Claims) — слушают существующие хуки из claims модуля
    // =====================================================================

    /**
     * Заявка создана — уведомление пользователю
     */
    public function on_claim_created( int $claim_id, int $user_id, array $data ): void {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        $subject = sprintf(
            /* translators: %d: ID заявки. */
            __('Заявка на кэшбэк #%d создана', 'cashback-plugin'),
            $claim_id
        );

        $message = sprintf(
            /* translators: %1$s: имя пользователя, %2$s: магазин, %3$s: номер заказа, %4$d: ID заявки, %5$s: URL личного кабинета. */
            __('Здравствуйте, %1$s!

Ваша заявка на неначисленный кэшбэк принята.

Магазин: %2$s
Номер заказа: %3$s
ID заявки: %4$d

Вы можете отслеживать статус заявки в личном кабинете: %5$s', 'cashback-plugin'),
            $user->display_name,
            $data['product_name'] ?? '—',
            $data['order_id'] ?? '—',
            $claim_id,
            function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('cashback_lost_cashback') : ''
        );

        Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $subject,
            $message,
            'claim_created',
            $user_id
        );
    }

    /**
     * Новая заявка — уведомление администраторам
     */
    public function on_claim_admin_alert( int $claim_id, int $user_id, array $data ): void {
        $user = get_user_by('id', $user_id);

        $subject = sprintf(
            /* translators: %d: ID заявки. */
            __('Новая заявка на кэшбэк #%d', 'cashback-plugin'),
            $claim_id
        );

        $message = sprintf(
            /* translators: %1$s: имя пользователя, %2$s: магазин, %3$s: номер заказа, %4$d: ID заявки. */
            __('Пользователь %1$s подал заявку на неначисленный кэшбэк.

Магазин: %2$s
Номер заказа: %3$s
ID заявки: %4$d

Просмотрите заявку в админ-панели.', 'cashback-plugin'),
            $user ? $user->display_name : '—',
            $data['product_name'] ?? '—',
            $data['order_id'] ?? '—',
            $claim_id
        );

        Cashback_Email_Sender::get_instance()->send_admin(
            $subject,
            $message,
            'claim_admin_alert'
        );
    }

    /**
     * Статус заявки изменён — уведомление пользователю
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by cashback_claim_status_changed action signature.
    public function on_claim_status_changed( int $claim_id, string $old_status, string $new_status, string $note, string $actor_type, ?int $actor_id ): void {
        global $wpdb;

        $claims_table = $wpdb->prefix . 'cashback_claims';
        $claim        = $wpdb->get_row($wpdb->prepare(
            'SELECT user_id FROM %i WHERE claim_id = %d',
            $claims_table,
            $claim_id
        ), ARRAY_A);

        if (!$claim) {
            return;
        }

        $user_id = (int) $claim['user_id'];
        $user    = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        $status_labels = array(
            'submitted'       => __('Отправлена', 'cashback-plugin'),
            'sent_to_network' => __('Отправлена партнёру', 'cashback-plugin'),
            'approved'        => __('Одобрена', 'cashback-plugin'),
            'declined'        => __('Отклонена', 'cashback-plugin'),
        );

        $label = $status_labels[ $new_status ] ?? $new_status;

        $subject = sprintf(
            /* translators: %1$d: ID заявки, %2$s: название нового статуса. */
            __('Статус заявки #%1$d изменён на «%2$s»', 'cashback-plugin'),
            $claim_id,
            $label
        );

        $message = sprintf(
            /* translators: %1$s: имя пользователя, %2$d: ID заявки, %3$s: новый статус, %4$s: комментарий, %5$s: URL заявки. */
            __('Здравствуйте, %1$s!

Статус вашей заявки #%2$d изменён на «%3$s».

%4$s

Просмотреть заявку: %5$s', 'cashback-plugin'),
            $user->display_name,
            $claim_id,
            $label,
            $note ? __('Комментарий: ', 'cashback-plugin') . $note : '',
            function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('cashback_lost_cashback') : ''
        );

        Cashback_Email_Sender::get_instance()->send(
            $user->user_email,
            $subject,
            $message,
            'claim_status',
            $user_id
        );
    }

    // =====================================================================
    // Партнёрская программа (affiliate)
    // =====================================================================

    /**
     * Новый реферал зарегистрирован — уведомление рефереру
     *
     * @param int $referrer_id ID реферера (кто пригласил)
     * @param int $referral_id ID нового реферала (кто зарегистрировался)
     */
    public function on_affiliate_referral( int $referrer_id, int $referral_id ): void {
        if ($referrer_id <= 0) {
            return;
        }

        $referrer = get_user_by('id', $referrer_id);
        if (!$referrer) {
            return;
        }

        $referral      = get_user_by('id', $referral_id);
        $referral_name = $referral ? $referral->display_name : '—';

        $subject = __('Новый реферал в вашей партнёрской программе', 'cashback-plugin');

        $message = sprintf(
            /* translators: %1$s: имя реферера, %2$s: имя нового реферала, %3$s: URL страницы партнёрской программы. */
            __('Здравствуйте, %1$s!

По вашей партнёрской ссылке зарегистрировался новый пользователь: %2$s.

Вы будете получать партнёрское вознаграждение с покупок этого пользователя.

Статистика партнёрской программы: %3$s', 'cashback-plugin'),
            $referrer->display_name,
            $referral_name,
            function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('cashback-affiliate') : home_url('/my-account/')
        );

        Cashback_Email_Sender::get_instance()->send(
            $referrer->user_email,
            $subject,
            $message,
            'affiliate_referral',
            $referrer_id
        );
    }

    /**
     * Партнёрское вознаграждение начислено — уведомление рефереру
     *
     * @param array $accruals Массив начислений, сгруппированных по referrer_id:
     *              [referrer_id => ['total' => float, 'count' => int]]
     */
    public function on_affiliate_commission( array $accruals ): void {
        $sender = Cashback_Email_Sender::get_instance();

        foreach ($accruals as $referrer_id => $info) {
            $referrer = get_user_by('id', $referrer_id);
            if (!$referrer) {
                continue;
            }

            $total_formatted = number_format((float) $info['total'], 2, ',', ' ');

            $subject = sprintf(
                /* translators: %s: сумма партнёрского вознаграждения. */
                __('Партнёрское вознаграждение %s ₽ начислено', 'cashback-plugin'),
                $total_formatted
            );

            if ($info['count'] === 1) {
                $count_text = __('1 покупка реферала', 'cashback-plugin');
            } else {
                $count_text = sprintf(
                    /* translators: %d: количество покупок рефералов. */
                    __('%d покупок рефералов', 'cashback-plugin'),
                    $info['count']
                );
            }

            $message = sprintf(
                /* translators: %1$s: имя реферера, %2$s: сумма вознаграждения, %3$s: текст о количестве покупок, %4$s: URL вывода. */
                __('Здравствуйте, %1$s!

Вам начислено партнёрское вознаграждение.

Сумма: %2$s ₽
%3$s

Средства доступны для вывода: %4$s', 'cashback-plugin'),
                $referrer->display_name,
                $total_formatted,
                $count_text,
                function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('cashback-withdrawal') : ''
            );

            $sender->send(
                $referrer->user_email,
                $subject,
                $message,
                'affiliate_commission',
                (int) $referrer_id
            );
        }
    }

    // =====================================================================
    // Cron-интервал и обработка MySQL-очереди
    // =====================================================================

    /**
     * Обработка очереди уведомлений из MySQL триггеров
     *
     * Вызывается Action Scheduler каждую минуту (группа `cashback`).
     * Читает необработанные записи из cashback_notification_queue,
     * отправляет email и помечает обработанными.
     */
    public function process_queue(): void {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'cashback_notification_queue';
        $tx_table    = $wpdb->prefix . 'cashback_transactions';

        // Берём до 50 необработанных записей
        $items = $wpdb->get_results($wpdb->prepare(
            'SELECT q.*, t.partner, t.offer_name, t.sum_order, t.comission, t.cashback
             FROM %i q
             LEFT JOIN %i t ON t.id = q.transaction_id
             WHERE q.processed = 0
             ORDER BY q.created_at ASC
             LIMIT 50',
            $queue_table,
            $tx_table
        ));

        if (empty($items)) {
            return;
        }

        $processed_ids = array();

        foreach ($items as $item) {
            $tx_id   = (int) $item->transaction_id;
            $user_id = (int) $item->user_id;

            if ($user_id <= 0) {
                $processed_ids[] = (int) $item->id;
                continue;
            }

            switch ($item->event_type) {
                case 'transaction_new':
                    $this->on_transaction_created($tx_id, array(
                        'user_id'    => $user_id,
                        'partner'    => $item->partner ?? '',
                        'offer_name' => $item->offer_name ?? '',
                        'sum_order'  => $item->sum_order ?? 0,
                    ));
                    break;

                case 'transaction_status':
                    $this->on_transaction_status_changed(
                        $tx_id,
                        $user_id,
                        $item->old_status ?? '',
                        $item->new_status ?? ''
                    );
                    break;

                case 'transaction_data_changed':
                    $extra = !empty($item->extra_data) ? json_decode($item->extra_data, true) : array();
                    $this->on_transaction_data_changed(
                        $tx_id,
                        $user_id,
                        $item->partner ?? '',
                        $extra
                    );
                    break;
            }

            $processed_ids[] = (int) $item->id;
        }

        // Помечаем обработанными
        if (!empty($processed_ids)) {
            $placeholders = implode(',', array_fill(0, count($processed_ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a '%d'-list of fixed length, IDs bound via $wpdb->prepare(); sniff can't see %d inside $placeholders.
            $wpdb->query( $wpdb->prepare( "UPDATE %i SET processed = 1 WHERE id IN ({$placeholders})", $queue_table, ...$processed_ids ) );
        }

        // Очистка старых записей (старше 7 дней)
        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE processed = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 500',
            $queue_table
        ));
    }
}
