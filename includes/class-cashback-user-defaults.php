<?php
/**
 * Фасад WP-опций для глобальных дефолтов нового пользователя.
 *
 * Управляет двумя значениями, которые применяются при создании записи в
 * wp_cashback_user_profile через {@see Mariadb_Plugin::add_user_to_profile()}:
 *   - cashback_default_user_rate  — ставка кэшбэка для нового профиля (0..100, default '60.00')
 *   - cashback_default_min_payout — минимальная сумма выплаты для нового профиля (1..100000, default '100.00')
 *
 * UI редактирования — в admin/users-management.php (две bulk-секции). Существующих
 * пользователей опции не затрагивают — для них есть отдельные bulk-операции.
 *
 * Сохраняем как decimal string (DECIMAL(5,2) / DECIMAL(18,2) совместимо с bccomp
 * валидацией в admin-handler'ах: `preg_match('/^\d+(\.\d{1,2})?$/', $v)`).
 *
 * @package CashbackPlugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Cashback_User_Defaults {

    public const OPT_RATE       = 'cashback_default_user_rate';
    public const OPT_MIN_PAYOUT = 'cashback_default_min_payout';

    public const FALLBACK_RATE       = '60.00';
    public const FALLBACK_MIN_PAYOUT = '100.00';
    private const LOCK_SUFFIX        = 'cashback_user_defaults';

    public const RATE_MIN       = '0';
    public const RATE_MAX       = '100';
    public const MIN_PAYOUT_MIN = '1';
    public const MIN_PAYOUT_MAX = '100000';

    /**
     * Зарегистрировать опции в WP Settings API.
     *
     * Опции редактируются только через AJAX-handler'ы в Cashback_Users_Management_Admin,
     * но register_setting нужен для типобезопасной санитизации и совместимости
     * со стандартным fail-safe WP options pipeline.
     */
    public static function register_settings(): void {
        register_setting(
            'cashback_user_defaults',
            self::OPT_RATE,
            array(
                'type'              => 'string',
                'default'           => self::FALLBACK_RATE,
                'sanitize_callback' => array( __CLASS__, 'sanitize_rate' ),
                'show_in_rest'      => false,
            )
        );

        register_setting(
            'cashback_user_defaults',
            self::OPT_MIN_PAYOUT,
            array(
                'type'              => 'string',
                'default'           => self::FALLBACK_MIN_PAYOUT,
                'sanitize_callback' => array( __CLASS__, 'sanitize_min_payout' ),
                'show_in_rest'      => false,
            )
        );
    }

    /**
     * Текущий глобальный дефолт cashback_rate (string '0.00'..'100.00').
     */
    public static function get_default_rate(): string {
        $raw = get_option(self::OPT_RATE, self::FALLBACK_RATE);
        return self::sanitize_rate($raw);
    }

    /**
     * Текущий глобальный дефолт min_payout_amount (string '1.00'..'100000.00').
     */
    public static function get_default_min_payout(): string {
        $raw = get_option(self::OPT_MIN_PAYOUT, self::FALLBACK_MIN_PAYOUT);
        return self::sanitize_min_payout($raw);
    }

    /**
     * Атомарно записать новое значение default cashback_rate.
     *
     * @return string Нормализованное сохранённое значение.
     * @throws InvalidArgumentException Если значение вне диапазона / нечисловое.
     */
    public static function set_default_rate( string $value ): string {
        $normalized = self::validate_rate($value);
        update_option(self::OPT_RATE, $normalized, true);
        return $normalized;
    }

    /**
     * Атомарно записать новое значение default min_payout_amount.
     *
     * @return string Нормализованное сохранённое значение.
     * @throws InvalidArgumentException Если значение вне диапазона / нечисловое.
     */
    public static function set_default_min_payout( string $value ): string {
        $normalized = self::validate_min_payout($value);
        update_option(self::OPT_MIN_PAYOUT, $normalized, true);
        return $normalized;
    }

    /**
     * Атомарно обновить default cashback_rate под advisory lock.
     *
     * Возвращает точную пару old/new из одного критического участка, чтобы audit-log
     * не терял промежуточные admin-save при параллельных изменениях.
     *
     * @return array{old_value: string, new_value: string}
     * @throws InvalidArgumentException Если значение вне диапазона / нечисловое.
     * @throws RuntimeException Если не удалось получить advisory lock.
     */
    public static function set_default_rate_atomically( string $value ): array {
        $normalized = self::validate_rate($value);

        return self::with_defaults_lock(
            static function () use ( $normalized ): array {
                $old_value = self::get_default_rate();
                update_option(self::OPT_RATE, $normalized, true);

                return array(
                    'old_value' => $old_value,
                    'new_value' => $normalized,
                );
            }
        );
    }

    /**
     * Атомарно обновить default min_payout_amount под advisory lock.
     *
     * @return array{old_value: string, new_value: string}
     * @throws InvalidArgumentException Если значение вне диапазона / нечисловое.
     * @throws RuntimeException Если не удалось получить advisory lock.
     */
    public static function set_default_min_payout_atomically( string $value ): array {
        $normalized = self::validate_min_payout($value);

        return self::with_defaults_lock(
            static function () use ( $normalized ): array {
                $old_value = self::get_default_min_payout();
                update_option(self::OPT_MIN_PAYOUT, $normalized, true);

                return array(
                    'old_value' => $old_value,
                    'new_value' => $normalized,
                );
            }
        );
    }

    /**
     * Возвращает согласованный snapshot дефолтов для INSERT нового профиля.
     *
     * @return array{rate: string, min_payout: string}
     * @throws RuntimeException Если не удалось получить advisory lock.
     */
    public static function get_new_user_profile_defaults(): array {
        return self::with_defaults_lock(
            static function (): array {
                return array(
                    'rate'       => self::get_default_rate(),
                    'min_payout' => self::get_default_min_payout(),
                );
            }
        );
    }

    /**
     * Возвращает fallback min_payout под тем же lock, что и admin-save.
     *
     * @throws RuntimeException Если не удалось получить advisory lock.
     */
    public static function get_default_min_payout_atomically(): string {
        return self::with_defaults_lock(
            static function (): string {
                return self::get_default_min_payout();
            }
        );
    }

    /**
     * Sanitize-callback для register_setting (rate).
     *
     * Возвращает fallback при некорректном входе — register_setting не должен
     * выбрасывать exception в WP options pipeline.
     *
     * @param mixed $value
     */
    public static function sanitize_rate( $value ): string {
        try {
            return self::validate_rate(is_scalar($value) ? (string) $value : '');
        } catch ( InvalidArgumentException $e ) {
            return self::FALLBACK_RATE;
        }
    }

    /**
     * Sanitize-callback для register_setting (min_payout).
     *
     * @param mixed $value
     */
    public static function sanitize_min_payout( $value ): string {
        try {
            return self::validate_min_payout(is_scalar($value) ? (string) $value : '');
        } catch ( InvalidArgumentException $e ) {
            return self::FALLBACK_MIN_PAYOUT;
        }
    }

    /**
     * Жёсткая валидация rate (для set_default_rate).
     *
     * @throws InvalidArgumentException
     */
    private static function validate_rate( string $value ): string {
        $trimmed = trim($value);
        if ( ! preg_match('/^\d+(\.\d{1,2})?$/', $trimmed) ) {
            throw new InvalidArgumentException('Ставка должна быть числом с не более чем 2 знаками после точки.');
        }
        if ( bccomp($trimmed, self::RATE_MIN, 2) < 0 || bccomp($trimmed, self::RATE_MAX, 2) > 0 ) {
            throw new InvalidArgumentException('Ставка должна быть в диапазоне 0..100.');
        }
        return bcadd($trimmed, '0', 2);
    }

    /**
     * Жёсткая валидация min_payout (для set_default_min_payout).
     *
     * @throws InvalidArgumentException
     */
    private static function validate_min_payout( string $value ): string {
        $trimmed = trim($value);
        if ( ! preg_match('/^\d+(\.\d{1,2})?$/', $trimmed) ) {
            throw new InvalidArgumentException('Сумма должна быть числом с не более чем 2 знаками после точки.');
        }
        if ( bccomp($trimmed, self::MIN_PAYOUT_MIN, 2) < 0 || bccomp($trimmed, self::MIN_PAYOUT_MAX, 2) > 0 ) {
            throw new InvalidArgumentException('Сумма должна быть в диапазоне 1..100000.');
        }
        return bcadd($trimmed, '0', 2);
    }

    /**
     * Выполняет callback под advisory lock на уровне MySQL.
     *
     * В production закрывает TOCTOU между admin-save и путями, которые читают
     * дефолты для INSERT/fallback. В тестовом bootstrap, где нет реального $wpdb,
     * просто выполняет callback без lock.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     * @throws RuntimeException
     */
    private static function with_defaults_lock( callable $callback ) {
        global $wpdb;

        if ( ! isset($wpdb) || ! is_object($wpdb) || ! method_exists($wpdb, 'get_var') ) {
            return $callback();
        }

        $prefix   = property_exists($wpdb, 'prefix') ? (string) $wpdb->prefix : 'wp_';
        $lock_key = $prefix . self::LOCK_SUFFIX;
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_key, 5));

        if ( (int) $acquired !== 1 ) {
            throw new RuntimeException('Не удалось получить lock для обновления дефолтов пользователей.');
        }

        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_key));
        }
    }
}
