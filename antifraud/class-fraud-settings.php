<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Управление настройками антифрод-модуля через wp_options
 *
 * @since 1.2.0
 */
class Cashback_Fraud_Settings {

    /**
     * Дефолтные значения всех настроек
     */
    private const DEFAULTS = array(
        'cashback_fraud_enabled'                       => true,
        'cashback_fraud_max_users_per_ip'              => 3,
        'cashback_fraud_max_users_per_fingerprint'     => 2,
        'cashback_fraud_max_withdrawals_per_day'       => 3,
        'cashback_fraud_max_withdrawals_per_week'      => 7,
        'cashback_fraud_cancellation_rate_threshold'   => 50.0,
        'cashback_fraud_cancellation_min_transactions' => 5,
        'cashback_fraud_amount_anomaly_multiplier'     => 5.0,
        'cashback_fraud_new_account_cooling_days'      => 7,
        'cashback_fraud_auto_hold_amount'              => 5000.00,
        'cashback_fraud_max_accounts_per_details_hash' => 1,
        'cashback_fraud_fingerprint_retention_days'    => 180,
        'cashback_fraud_auto_flag_threshold'           => 70.0,
        'cashback_fraud_email_notification_enabled'    => true,
    );

    /**
     * Дефолтные значения настроек бот-защиты
     */
    private const BOT_DEFAULTS = array(
        'cashback_bot_protection_enabled' => true,
        'cashback_captcha_client_key'     => '',
        'cashback_captcha_server_key'     => '',
        'cashback_bot_grey_threshold'     => 20,
        'cashback_bot_block_threshold'    => 80,
    );

    public static function is_enabled(): bool {
        return (bool) get_option('cashback_fraud_enabled', self::DEFAULTS['cashback_fraud_enabled']);
    }

    public static function get_max_users_per_ip(): int {
        return (int) get_option('cashback_fraud_max_users_per_ip', self::DEFAULTS['cashback_fraud_max_users_per_ip']);
    }

    public static function get_max_users_per_fingerprint(): int {
        return (int) get_option('cashback_fraud_max_users_per_fingerprint', self::DEFAULTS['cashback_fraud_max_users_per_fingerprint']);
    }

    public static function get_max_withdrawals_per_day(): int {
        return (int) get_option('cashback_fraud_max_withdrawals_per_day', self::DEFAULTS['cashback_fraud_max_withdrawals_per_day']);
    }

    public static function get_max_withdrawals_per_week(): int {
        return (int) get_option('cashback_fraud_max_withdrawals_per_week', self::DEFAULTS['cashback_fraud_max_withdrawals_per_week']);
    }

    public static function get_cancellation_rate_threshold(): float {
        return (float) get_option('cashback_fraud_cancellation_rate_threshold', self::DEFAULTS['cashback_fraud_cancellation_rate_threshold']);
    }

    public static function get_cancellation_min_transactions(): int {
        return (int) get_option('cashback_fraud_cancellation_min_transactions', self::DEFAULTS['cashback_fraud_cancellation_min_transactions']);
    }

    public static function get_amount_anomaly_multiplier(): float {
        return (float) get_option('cashback_fraud_amount_anomaly_multiplier', self::DEFAULTS['cashback_fraud_amount_anomaly_multiplier']);
    }

    public static function get_new_account_cooling_days(): int {
        return (int) get_option('cashback_fraud_new_account_cooling_days', self::DEFAULTS['cashback_fraud_new_account_cooling_days']);
    }

    public static function get_auto_hold_amount(): float {
        return (float) get_option('cashback_fraud_auto_hold_amount', self::DEFAULTS['cashback_fraud_auto_hold_amount']);
    }

    public static function get_max_accounts_per_details_hash(): int {
        return (int) get_option('cashback_fraud_max_accounts_per_details_hash', self::DEFAULTS['cashback_fraud_max_accounts_per_details_hash']);
    }

    public static function get_fingerprint_retention_days(): int {
        return (int) get_option('cashback_fraud_fingerprint_retention_days', self::DEFAULTS['cashback_fraud_fingerprint_retention_days']);
    }

    public static function get_auto_flag_threshold(): float {
        return (float) get_option('cashback_fraud_auto_flag_threshold', self::DEFAULTS['cashback_fraud_auto_flag_threshold']);
    }

    public static function is_email_notification_enabled(): bool {
        return (bool) get_option('cashback_fraud_email_notification_enabled', self::DEFAULTS['cashback_fraud_email_notification_enabled']);
    }

    // =========================================================================
    // Бот-защита: геттеры
    // =========================================================================

    public static function is_bot_protection_enabled(): bool {
        return (bool) get_option('cashback_bot_protection_enabled', self::BOT_DEFAULTS['cashback_bot_protection_enabled']);
    }

    public static function get_captcha_client_key(): string {
        return (string) get_option('cashback_captcha_client_key', '');
    }

    public static function get_captcha_server_key(): string {
        return (string) get_option('cashback_captcha_server_key', '');
    }

    public static function get_grey_threshold(): int {
        return (int) get_option('cashback_bot_grey_threshold', self::BOT_DEFAULTS['cashback_bot_grey_threshold']);
    }

    public static function get_block_threshold(): int {
        return (int) get_option('cashback_bot_block_threshold', self::BOT_DEFAULTS['cashback_bot_block_threshold']);
    }

    /**
     * Получить все настройки антифрода с текущими значениями
     *
     * @return array<string, mixed>
     */
    public static function get_all(): array {
        $settings = array();
        foreach (self::DEFAULTS as $key => $default) {
            $short_key              = str_replace('cashback_fraud_', '', $key);
            $settings[ $short_key ] = get_option($key, $default);
        }
        return $settings;
    }

    /**
     * Получить все настройки бот-защиты с текущими значениями
     *
     * @return array<string, mixed>
     */
    public static function get_all_bot_settings(): array {
        $settings = array();
        foreach (self::BOT_DEFAULTS as $key => $default) {
            $short_key              = str_replace('cashback_', '', $key);
            $settings[ $short_key ] = get_option($key, $default);
        }
        return $settings;
    }

    /**
     * Получить дефолтные значения
     *
     * @return array<string, mixed>
     */
    public static function get_defaults(): array {
        $defaults = array();
        foreach (self::DEFAULTS as $key => $default) {
            $short_key              = str_replace('cashback_fraud_', '', $key);
            $defaults[ $short_key ] = $default;
        }
        return $defaults;
    }

    /**
     * Сохранить настройки из массива
     *
     * @param array<string, mixed> $settings Массив с short-ключами (без cashback_fraud_ prefix)
     * @return void
     */
    public static function save_settings( array $settings ): void {
        $validation = array(
            'enabled'                       => 'bool',
            'max_users_per_ip'              => 'int_positive',
            'max_users_per_fingerprint'     => 'int_positive',
            'max_withdrawals_per_day'       => 'int_positive',
            'max_withdrawals_per_week'      => 'int_positive',
            'cancellation_rate_threshold'   => 'float_0_100',
            'cancellation_min_transactions' => 'int_positive',
            'amount_anomaly_multiplier'     => 'float_positive',
            'new_account_cooling_days'      => 'int_non_negative',
            'auto_hold_amount'              => 'float_non_negative',
            'max_accounts_per_details_hash' => 'int_positive',
            'fingerprint_retention_days'    => 'int_positive',
            'auto_flag_threshold'           => 'float_0_100',
            'email_notification_enabled'    => 'bool',
        );

        foreach ($settings as $short_key => $value) {
            $full_key = 'cashback_fraud_' . $short_key;

            if (!array_key_exists($full_key, self::DEFAULTS)) {
                continue;
            }

            $type      = $validation[ $short_key ] ?? 'string';
            $sanitized = self::sanitize_value($value, $type);

            if ($sanitized !== null) {
                update_option($full_key, $sanitized);
            }
        }
    }

    /**
     * Санитизация значения по типу
     *
     * @param mixed  $value Значение
     * @param string $type  Тип валидации
     * @return mixed|null Санитизированное значение или null если невалидно
     */
    private static function sanitize_value( $value, string $type ) {
        switch ($type) {
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'int_positive':
                $int = (int) $value;
                return $int > 0 ? $int : null;

            case 'int_non_negative':
                $int = (int) $value;
                return $int >= 0 ? $int : null;

            case 'float_positive':
                $float = (float) $value;
                return $float > 0 ? $float : null;

            case 'float_non_negative':
                $float = (float) $value;
                return $float >= 0 ? $float : null;

            case 'float_0_100':
                $float = (float) $value;
                return ( $float >= 0 && $float <= 100 ) ? $float : null;

            default:
                return sanitize_text_field((string) $value);
        }
    }

    /**
     * Сохранить настройки бот-защиты
     *
     * @param array<string, mixed> $settings Массив с short-ключами (без cashback_ prefix)
     */
    public static function save_bot_settings( array $settings ): void {
        $validation = array(
            'bot_protection_enabled' => 'bool',
            'captcha_client_key'     => 'string',
            'captcha_server_key'     => 'string',
            'bot_grey_threshold'     => 'int_positive',
            'bot_block_threshold'    => 'int_positive',
        );

        foreach ($settings as $short_key => $value) {
            $full_key = 'cashback_' . $short_key;

            if (!array_key_exists($full_key, self::BOT_DEFAULTS)) {
                continue;
            }

            $type      = $validation[ $short_key ] ?? 'string';
            $sanitized = self::sanitize_value($value, $type);

            if ($sanitized !== null) {
                update_option($full_key, $sanitized);
            }
        }
    }

    /**
     * Получить список всех ключей опций (для uninstall)
     *
     * @return string[]
     */
    public static function get_option_keys(): array {
        $keys   = array_keys(self::DEFAULTS);
        $keys[] = 'cashback_fraud_last_run';
        // Бот-защита
        $keys = array_merge($keys, array_keys(self::BOT_DEFAULTS));
        return $keys;
    }
}
