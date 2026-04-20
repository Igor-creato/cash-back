<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Диспетчер push-уведомлений через Expo Push API.
 *
 * Интеграция с email-уведомлениями:
 *   - Слушает action `cashback_notification_dispatched`, который диспатчится
 *     в `Cashback_Email_Sender::send()` после успешной отправки email.
 *   - Проверяет push-preferences пользователя (meta `cashback_push_prefs`).
 *   - Ставит задачу в Action Scheduler (`cashback_push_dispatch_batch`) — не шлёт
 *     синхронно, чтобы не блокировать HTTP-ответ email-sender'а.
 *
 * Протокол Expo:
 *   POST https://exp.host/--/api/v2/push/send
 *   [ {"to": "ExponentPushToken[...]", "title": "...", "body": "...", "data": {...}}, ... ]
 *   Batch до 100 сообщений за запрос.
 *
 * Обработка ошибок:
 *   - DeviceNotRegistered / InvalidCredentials → delete_by_expo_token
 *   - MessageRateExceeded → increment_failures (retry позже)
 *
 * Batch-queue реализован через транзиент с массивом задач. Worker забирает до 100
 * сообщений за вызов Action Scheduler (каждую минуту).
 *
 * @since 1.1.0
 */
class Cashback_Push_Dispatcher {

    public const EXPO_ENDPOINT        = 'https://exp.host/--/api/v2/push/send';
    public const BATCH_SIZE           = 100;
    public const QUEUE_OPTION         = 'cashback_push_queue';
    public const AS_ACTION            = 'cashback_push_dispatch_batch';
    public const WORKER_INTERVAL_SECS = 60;

    public static function init(): void {
        add_action('cashback_notification_dispatched', array( __CLASS__, 'on_notification_dispatched' ), 10, 4);
        add_action(self::AS_ACTION, array( __CLASS__, 'dispatch_batch' ));

        // Планируем recurring worker (идемпотентно).
        if (function_exists('as_has_scheduled_action')
            && function_exists('as_schedule_recurring_action')
            && !as_has_scheduled_action(self::AS_ACTION)
        ) {
            as_schedule_recurring_action(time() + self::WORKER_INTERVAL_SECS, self::WORKER_INTERVAL_SECS, self::AS_ACTION);
        }
    }

    /**
     * Callback для hook `cashback_notification_dispatched`.
     *
     * @param int    $user_id Получатель (null допустим — рассылка).
     * @param string $type    slug уведомления.
     * @param string $title   Заголовок email (reuse для push).
     * @param string $body    Текст (будет обрезан под лимит push).
     */
    public static function on_notification_dispatched( $user_id, string $type, string $title, string $body ): void {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }
        if (!self::is_push_enabled_for_user($user_id, $type)) {
            return;
        }

        $tokens = Cashback_Push_Token_Store::get_active_tokens_for_user($user_id);
        if (empty($tokens)) {
            return;
        }

        $push_body = self::truncate_for_push($body);
        foreach ($tokens as $row) {
            self::enqueue(array(
                'to'    => (string) $row['expo_token'],
                'title' => mb_substr($title, 0, 100),
                'body'  => $push_body,
                'data'  => array(
                    'type'    => $type,
                    'user_id' => $user_id,
                ),
                'sound' => 'default',
            ));
        }
    }

    /**
     * Action Scheduler worker: берёт до BATCH_SIZE сообщений из очереди и отправляет.
     */
    public static function dispatch_batch(): void {
        $queue = (array) get_option(self::QUEUE_OPTION, array());
        if (empty($queue)) {
            return;
        }

        $batch = array_slice($queue, 0, self::BATCH_SIZE);
        $rest  = array_slice($queue, self::BATCH_SIZE);
        update_option(self::QUEUE_OPTION, $rest, false);

        $response = wp_remote_post(self::EXPO_ENDPOINT, array(
            // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- ожидаем ответ Expo до 15 секунд при больших батчах; worker работает в Action Scheduler фоном.
            'timeout'     => 15,
            'redirection' => 2,
            'headers'     => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'        => wp_json_encode($batch),
        ));

        if (is_wp_error($response)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Push] Expo request failed: ' . $response->get_error_message());
            // Возвращаем batch обратно — будет ретрай на следующем tick.
            self::requeue_batch($batch);
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if ($code < 200 || $code >= 300 || !is_array($json)) {
            // Не логируем полный body — может содержать push-токены получателей из receipts.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Push] Expo returned HTTP ' . $code);
            self::requeue_batch($batch);
            return;
        }

        $data = isset($json['data']) && is_array($json['data']) ? $json['data'] : array();
        self::process_tickets($batch, $data);
    }

    /**
     * Обработать ticket-ответы от Expo (по индексу соответствия с batch).
     *
     * @param array<int,array<string,mixed>> $batch
     * @param array<int,array<string,mixed>> $tickets
     */
    private static function process_tickets( array $batch, array $tickets ): void {
        foreach ($tickets as $i => $ticket) {
            $to     = (string) ( $batch[ $i ]['to'] ?? '' );
            $status = (string) ( $ticket['status'] ?? 'ok' );
            if ('ok' === $status) {
                continue;
            }
            $details = isset($ticket['details']) && is_array($ticket['details']) ? $ticket['details'] : array();
            $err     = (string) ( $details['error'] ?? '' );

            if (in_array($err, array( 'DeviceNotRegistered', 'InvalidCredentials' ), true)) {
                Cashback_Push_Token_Store::delete_by_expo_token($to);
            } else {
                Cashback_Push_Token_Store::increment_failures($to);
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic.
            error_log('[Cashback][Push] Ticket error (token prefix ' . substr($to, 0, 16) . '...): ' . $err);
        }
    }

    /**
     * Положить сообщение в хвост очереди.
     */
    private static function enqueue( array $message ): void {
        $queue   = (array) get_option(self::QUEUE_OPTION, array());
        $queue[] = $message;
        update_option(self::QUEUE_OPTION, $queue, false);
    }

    /**
     * Вернуть batch обратно в начало очереди для retry.
     */
    private static function requeue_batch( array $batch ): void {
        $queue = (array) get_option(self::QUEUE_OPTION, array());
        update_option(self::QUEUE_OPTION, array_merge($batch, $queue), false);
    }

    private static function truncate_for_push( string $body ): string {
        $body = wp_strip_all_tags($body);
        // iOS показывает до ~178 символов, Android ~50 в свёрнутом виде.
        return mb_strlen($body) > 180 ? mb_substr($body, 0, 177) . '…' : $body;
    }

    /**
     * Проверить user_meta `cashback_push_prefs` (по умолчанию всё включено).
     */
    private static function is_push_enabled_for_user( int $user_id, string $type ): bool {
        $prefs = (array) get_user_meta($user_id, 'cashback_push_prefs', true);
        // Всегда отправлять критические типы.
        if (in_array($type, array( 'social_confirm_link', 'social_verify_email' ), true)) {
            return true;
        }
        return !isset($prefs[ $type ]) || (bool) $prefs[ $type ];
    }
}
