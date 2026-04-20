<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Простой аудит-логгер для модуля социальной авторизации.
 *
 * Пишет в error_log с префиксом [cashback-social] и JSON-контекстом.
 * Если в плагине появится единый audit-storage — этот класс будет обёрткой над ним.
 *
 * События:
 *  - start                  — начат OAuth flow
 *  - callback_success       — callback успешно обработан
 *  - callback_error         — ошибка в callback (некорректный код, сбой провайдера)
 *  - state_mismatch         — state/cookie не прошли проверку
 *  - rate_limited           — пользователь/IP превысил лимит
 *  - link_created           — новая связка создана
 *  - unlink                 — связка удалена
 *  - pending_created        — создан pending (confirm_link, email_prompt, email_verify)
 *  - pending_consumed       — pending потреблён по ссылке
 *  - email_mismatch         — email в pending не совпал с текущим
 */
class Cashback_Social_Auth_Audit {

    public const EVENT_START            = 'start';
    public const EVENT_CALLBACK_SUCCESS = 'callback_success';
    public const EVENT_CALLBACK_ERROR   = 'callback_error';
    public const EVENT_STATE_MISMATCH   = 'state_mismatch';
    public const EVENT_RATE_LIMITED     = 'rate_limited';
    public const EVENT_LINK_CREATED     = 'link_created';
    public const EVENT_UNLINK           = 'unlink';
    public const EVENT_PENDING_CREATED  = 'pending_created';
    public const EVENT_PENDING_CONSUMED = 'pending_consumed';
    public const EVENT_EMAIL_MISMATCH   = 'email_mismatch';

    /**
     * Записать событие.
     *
     * @param array<string, mixed> $context
     */
    public static function log( string $event, array $context = array() ): void {
        $payload = array(
            'event' => $event,
            'ts'    => gmdate('c'),
        ) + self::redact_context($context);

        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{"event":"' . $event . '","_json_error":1}';
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin audit logging.
        error_log('[cashback-social] ' . $json);
    }

    /**
     * Редактировать чувствительные поля в аудит-контексте перед записью в лог.
     * OAuth-секреты (code/state/токены/cookie), PII (email/recipient/phone,
     * user_agent целиком) заменяются на маркер `[redacted]`. Рекурсивно.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function redact_context( array $context ): array {
        static $sensitive_keys = array(
            'code',
            'state',
            'cookie',
            'token',
            'access_token',
            'refresh_token',
            'id_token',
            'email',
            'recipient',
            'user_email',
            'phone',
            'user_agent',
            'password',
            'secret',
            'api_key',
            'authorization',
            'auth_tag',
            'iv',
            'nonce',
        );

        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitive_keys, true)) {
                $context[ $key ] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $context[ $key ] = self::redact_context($value);
            }
        }

        return $context;
    }
}
