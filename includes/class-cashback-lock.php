<?php

declare(strict_types=1);

// phpcs:ignore PSR12.Files.FileHeader.IncorrectOrder -- WordPress bootstrap pattern: declare(strict_types) precedes file docblock, followed by ABSPATH guard.
/**
 * Глобальный механизм блокировки для синхронизации кешбэка.
 *
 * Гарантирует что sync + начисление — одна атомарная операция.
 * Блокирует админские проверки во время синхронизации.
 *
 * Реализация: MySQL GET_LOCK с fallback на wp_options + TTL.
 *
 * @package CashbackPlugin
 * @since   6.0.0
 */

// phpcs:ignore PSR12.Files.FileHeader.IncorrectOrder -- WordPress bootstrap guard must precede other statements.
if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Lock {

    /** @var string Имя MySQL advisory lock */
    const LOCK_NAME = 'cashback_global_lock';

    /** @var string Ключ wp_options для fallback/видимости lock */
    const OPTION_KEY = 'cashback_global_lock_active';

    /** @var int Максимальное время жизни lock в секундах (защита от зависших процессов) */
    const LOCK_TTL = 600; // 10 минут

    /** @var int Таймаут ожидания GET_LOCK в секундах */
    const LOCK_TIMEOUT = 30;

    /** @var bool Флаг: текущий процесс удерживает lock */
    private static bool $lock_held = false;

    /**
     * Захватить глобальный lock.
     *
     * Используется ТОЛЬКО sync-процессом (cron или manual).
     * Если lock уже занят другим процессом — возвращает false.
     *
     * @param int $timeout Таймаут ожидания в секундах (0 = не ждать)
     * @return bool true если lock захвачен
     */
    public static function acquire( int $timeout = 0 ): bool {
        global $wpdb;

        if (self::$lock_held) {
            return true; // Реентерабельность: уже держим
        }

        // Проверяем: нет ли зависшего lock (TTL истёк)
        self::cleanup_stale_lock();

        // MySQL GET_LOCK — основной механизм
        $lock_name = self::get_prefixed_lock_name();
        $result    = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $lock_name,
            $timeout
        ));

        if ((int) $result !== 1) {
            return false;
        }

        // wp_options запись — для видимости из других процессов (IS_FREE_LOCK не всегда надёжен)
        update_option(self::OPTION_KEY, array(
            'pid'        => getmypid(),
            'started_at' => time(),
            'expires_at' => time() + self::LOCK_TTL,
        ), false); // autoload = false

        self::$lock_held = true;

        return true;
    }

    /**
     * Освободить глобальный lock.
     *
     * @return void
     */
    public static function release(): void {
        global $wpdb;

        if (!self::$lock_held) {
            return;
        }

        $lock_name = self::get_prefixed_lock_name();
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));

        delete_option(self::OPTION_KEY);
        self::$lock_held = false;
    }

    /**
     * Проверить, активен ли lock (из любого процесса).
     *
     * Для AJAX-обработчиков: если true — синхронизация в процессе,
     * операции с балансом запрещены.
     *
     * @return bool true если lock активен
     */
    public static function is_lock_active(): bool {
        // MySQL IS_FREE_LOCK — первичный источник правды; wp_options хранит только метаданные видимости.
        // Если бы wp_options проверялось первым, пустая/потерянная запись давала бы false,
        // пока MySQL-lock реально удерживается — это fail-open и пропуск параллельных операций с балансом.
        global $wpdb;
        $lock_name = self::get_prefixed_lock_name();
        $is_free   = $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', $lock_name));

        // IS_FREE_LOCK: 1 = свободен, 0 = занят, NULL = ошибка
        if ((int) $is_free === 1) {
            // MySQL lock свободен — очищаем wp_options если осталась
            delete_option(self::OPTION_KEY);
            return false;
        }

        // MySQL lock занят. Если TTL истёк — пытаем cleanup (для wp_options),
        // но lock всё ещё считаем активным т.к. MySQL его держит
        $lock_data = get_option(self::OPTION_KEY);
        if (is_array($lock_data) && isset($lock_data['expires_at']) && time() > (int) $lock_data['expires_at']) {
            self::cleanup_stale_lock();
        }

        return true;
    }

    /**
     * Проверить, удерживает ли ТЕКУЩИЙ процесс глобальный lock.
     *
     * Используется в process_ready_transactions для гарантии
     * что начисление не происходит вне sync.
     *
     * @return bool
     */
    public static function is_lock_held_by_current_process(): bool {
        return self::$lock_held;
    }

    /**
     * Получить информацию о текущем lock.
     *
     * @return array{active: bool, pid: int|null, started_at: int|null, expires_at: int|null}
     */
    public static function get_lock_info(): array {
        $lock_data = get_option(self::OPTION_KEY);

        if (empty($lock_data) || !is_array($lock_data)) {
            return array(
                'active'     => false,
                'pid'        => null,
                'started_at' => null,
                'expires_at' => null,
            );
        }

        $active = self::is_lock_active();

        return array(
            'active'     => $active,
            'pid'        => $lock_data['pid'] ?? null,
            'started_at' => $lock_data['started_at'] ?? null,
            'expires_at' => $lock_data['expires_at'] ?? null,
        );
    }

    /**
     * Очистка зависшего lock (TTL истёк).
     *
     * @return void
     */
    private static function cleanup_stale_lock(): void {
        $lock_data = get_option(self::OPTION_KEY);

        if (empty($lock_data) || !is_array($lock_data)) {
            return;
        }

        if (isset($lock_data['expires_at']) && time() > (int) $lock_data['expires_at']) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log(sprintf(
                '[Cashback Lock] Cleaning up stale lock (pid=%s, started=%s, expired=%s)',
                $lock_data['pid'] ?? '?',
                isset($lock_data['started_at']) ? gmdate('Y-m-d H:i:s', (int) $lock_data['started_at']) : '?',
                gmdate('Y-m-d H:i:s', (int) $lock_data['expires_at'])
            ));

            // RELEASE_LOCK работает только для текущего соединения.
            // Если lock удерживается другим соединением — вызов ничего не сделает.
            // Удаляем wp_options запись только если MySQL lock реально свободен,
            // иначе оставляем — acquire() не сможет захватить lock в любом случае.
            global $wpdb;
            $lock_name = self::get_prefixed_lock_name();
            $is_free   = $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', $lock_name));

            if ((int) $is_free === 1) {
                // MySQL lock уже свободен (соединение закрылось) — чистим wp_options
                delete_option(self::OPTION_KEY);
            } else {
                // MySQL lock ещё занят другим соединением — логируем, но НЕ удаляем wp_options
                // чтобы is_lock_active() продолжал возвращать true до реального освобождения
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Lock] Stale lock: TTL expired but MySQL lock still held — waiting for connection to close');
            }
        }
    }

    /**
     * Получить имя lock с префиксом (для мультисайта).
     *
     * @return string
     */
    private static function get_prefixed_lock_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::LOCK_NAME;
    }
}
