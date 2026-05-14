<?php
/**
 * Test-only stub для Cashback_Email_Sender, который реальный класс заменяет в
 * тестах stuck-monitor'а. Перехватывает send_admin() в публичный массив для
 * assertion'ов.
 *
 * Подключается явно через require_once из tests, и ТОЛЬКО если реальный класс
 * ещё не загружен (`class_exists('Cashback_Email_Sender', false) === false`).
 *
 * НЕ имеет суффикса Test.php — PHPUnit не auto-discover'ит.
 */

declare(strict_types=1);

if (class_exists('Cashback_Email_Sender', false)) {
    return;
}

class Cashback_Email_Sender
{
    /** @var array<int, array{subject:string, message:string, type:string}> */
    public static array $sent_calls = array();

    /** @var self|null */
    private static $instance = null;

    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function send_admin(string $subject, string $message, string $notification_type, array $extra_headers = array()): void
    {
        self::$sent_calls[] = array(
            'subject' => $subject,
            'message' => $message,
            'type'    => $notification_type,
        );
    }

    public static function reset(): void
    {
        self::$sent_calls = array();
        self::$instance   = null;
    }
}
