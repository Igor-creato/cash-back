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
        // Composite device-based сигналы (Этап 3 антифрод-рефакторинга)
        'cashback_fraud_max_users_per_device'          => 2,
        'cashback_fraud_max_ips_per_device_24h'        => 5,
        // CGNAT-aware: skip create_alert для check_shared_ip когда IP — mobile/cgnat/private.
        // Mobile carrier (МТС/Билайн/МегаФон/Tele2/Yota и др.) — общий публичный IP у тысяч абонентов,
        // совпадение «N юзеров с одного IP» не несёт сигнальной нагрузки. См. RFC 6598.
        'cashback_fraud_skip_alert_for_mobile_ip'      => true,
        // Тумблеры подсистем антифрод-нового-поколения.
        // Позволяют точечно отключить компонент без отключения всего антифрода.
        // Shared-IP signal: легаси-чек check_shared_ip (>N юзеров с одного residential IP).
        // Если выключен — check_shared_ip делает early return, alert'ы multi_account_ip не создаются.
        // Используйте если хотите перейти на чистую device-first парадигму (Stripe Radar / Sift подход):
        // полагаться только на device_id + cluster detection, IP игнорировать.
        'cashback_fraud_shared_ip_check_enabled'       => true,
        // IP Intelligence: classify() через MaxMind GeoLite2-ASN + хардкод RU операторов.
        // Если выключен — check_shared_ip работает по старой логике (фиксированный weight=15, без skip mobile).
        'cashback_fraud_ip_intelligence_enabled'       => true,
        // Persistent device_id (LocalStorage+IndexedDB+Cookie) + сигналы shared_device_id и device_multiple_ips.
        // Если выключен — collector не пишет в wp_cb_fraud_device_ids, оба device-сигнала пропускаются.
        'cashback_fraud_device_id_enabled'             => true,
        // Cluster detection cron (union-find по device/visitor/payment/email/phone).
        // Если выключен — hourly cron делает early return, кластеры не обновляются.
        'cashback_fraud_cluster_detection_enabled'     => true,
        // 152-ФЗ requirement: чекбокс согласия на форме регистрации + блокировка fraud-fingerprint endpoint без consent.
        // Если выключен — чекбокс не показывается, валидация не выполняется, REST принимает данные без проверки.
        // Используйте с осторожностью: только для legacy-миграции или dev-окружения.
        'cashback_fraud_consent_required'              => true,
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

    public static function get_max_users_per_device(): int {
        return (int) get_option('cashback_fraud_max_users_per_device', self::DEFAULTS['cashback_fraud_max_users_per_device']);
    }

    public static function get_max_ips_per_device_24h(): int {
        return (int) get_option('cashback_fraud_max_ips_per_device_24h', self::DEFAULTS['cashback_fraud_max_ips_per_device_24h']);
    }

    public static function should_skip_alert_for_mobile_ip(): bool {
        return (bool) get_option('cashback_fraud_skip_alert_for_mobile_ip', self::DEFAULTS['cashback_fraud_skip_alert_for_mobile_ip']);
    }

    public static function is_shared_ip_check_enabled(): bool {
        return (bool) get_option('cashback_fraud_shared_ip_check_enabled', self::DEFAULTS['cashback_fraud_shared_ip_check_enabled']);
    }

    public static function is_ip_intelligence_enabled(): bool {
        return (bool) get_option('cashback_fraud_ip_intelligence_enabled', self::DEFAULTS['cashback_fraud_ip_intelligence_enabled']);
    }

    public static function is_device_id_enabled(): bool {
        return (bool) get_option('cashback_fraud_device_id_enabled', self::DEFAULTS['cashback_fraud_device_id_enabled']);
    }

    public static function is_cluster_detection_enabled(): bool {
        return (bool) get_option('cashback_fraud_cluster_detection_enabled', self::DEFAULTS['cashback_fraud_cluster_detection_enabled']);
    }

    public static function is_consent_required(): bool {
        return (bool) get_option('cashback_fraud_consent_required', self::DEFAULTS['cashback_fraud_consent_required']);
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
        $stored = (string) get_option('cashback_captcha_server_key', '');
        return Cashback_Encryption::decrypt_if_ciphertext($stored);
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
     * Получить все настройки бот-защиты с текущими значениями.
     * captcha_server_key расшифровывается прозрачно — админ-форма редактирует plaintext.
     *
     * @return array<string, mixed>
     */
    public static function get_all_bot_settings(): array {
        $settings = array();
        foreach (self::BOT_DEFAULTS as $key => $default) {
            $short_key = str_replace('cashback_', '', $key);
            $value     = get_option($key, $default);

            if ($key === 'cashback_captcha_server_key') {
                $value = Cashback_Encryption::decrypt_if_ciphertext((string) $value);
            }

            $settings[ $short_key ] = $value;
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
            'max_users_per_device'          => 'int_positive',
            'max_ips_per_device_24h'        => 'int_positive',
            'skip_alert_for_mobile_ip'      => 'bool',
            'shared_ip_check_enabled'       => 'bool',
            'ip_intelligence_enabled'       => 'bool',
            'device_id_enabled'             => 'bool',
            'cluster_detection_enabled'     => 'bool',
            'consent_required'              => 'bool',
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

            case 'secret':
                // Plaintext-секрет: санитизация + шифрование перед записью в wp_options.
                // encrypt_if_needed graceful — при неконфигурированном ключе вернёт
                // plaintext (жёсткий fail-closed — область Группы 4 ADR).
                $plaintext = sanitize_text_field((string) $value);
                return Cashback_Encryption::encrypt_if_needed($plaintext);

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
            'captcha_server_key'     => 'secret',
            'bot_grey_threshold'     => 'int_positive',
            'bot_block_threshold'    => 'int_positive',
        );

        foreach ($settings as $short_key => $value) {
            $full_key = 'cashback_' . $short_key;

            if (!array_key_exists($full_key, self::BOT_DEFAULTS)) {
                continue;
            }

            $type = $validation[ $short_key ] ?? 'string';

            // 'secret' идёт через атомарный путь с row-lock'ом на wp_options:
            // SELECT FOR UPDATE → encrypt → UPDATE → COMMIT. Закрывает TOCTOU-race
            // с batch-job'ом ротации (фаза options_captcha).
            if ($type === 'secret') {
                $plaintext = sanitize_text_field((string) $value);
                self::update_encrypted_option_atomic($full_key, $plaintext);
                continue;
            }

            $sanitized = self::sanitize_value($value, $type);

            if ($sanitized !== null) {
                update_option($full_key, $sanitized);
            }
        }
    }

    /**
     * Атомарно обновляет зашифрованный wp_option под удержанием row-lock'а:
     * START TRANSACTION → SELECT ... FOR UPDATE → encrypt → UPDATE → COMMIT → cache_delete.
     *
     * Закрывает TOCTOU-race с batch-job'ом ротации ключа (фаза options_captcha):
     * write-key для encrypt() выбирается под удержанием lock'а, batch не может
     * одновременно перешифровать эту же опцию.
     *
     * Если $wpdb недоступен (тестовый bootstrap без DB) — fallback на update_option,
     * чтобы не ломать unit-тесты с in-memory option-стабом.
     */
    private static function update_encrypted_option_atomic( string $option_name, string $plaintext ): void {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            // Test-friendly fallback: просто шифруем и пишем через update_option.
            update_option($option_name, Cashback_Encryption::encrypt_if_needed($plaintext));
            return;
        }

        $options_table = property_exists($wpdb, 'options') ? (string) $wpdb->options : 'wp_options';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Row-lock TX begin.
        $wpdb->query('START TRANSACTION');
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SELECT FOR UPDATE inside TX.
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE',
                    $options_table,
                    $option_name
                )
            );

            // Encrypt происходит ПОД row-lock'ом: write-key выбирается по актуальному
            // состоянию ротации, batch не может одновременно перешифровать эту опцию.
            $ciphertext = Cashback_Encryption::encrypt_if_needed($plaintext);

            if ($existing === null) {
                // Опция не существует — выходим из TX и используем стандартный update_option,
                // который корректно проставит autoload и зарегистрирует в alloptions cache.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit empty.
                $wpdb->query('COMMIT');
                update_option($option_name, $ciphertext);
                return;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE locked row.
            $wpdb->update(
                $options_table,
                array( 'option_value' => $ciphertext ),
                array( 'option_name' => $option_name ),
                array( '%s' ),
                array( '%s' )
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit.
            $wpdb->query('COMMIT');

            wp_cache_delete($option_name, 'options');
            wp_cache_delete('alloptions', 'options');
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback on error.
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging.
            error_log('[Cashback Fraud Settings] Failed to update encrypted option ' . $option_name . ': ' . $e->getMessage());
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
