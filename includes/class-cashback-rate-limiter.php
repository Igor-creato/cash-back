<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Централизованный rate limiter с grey IP scoring.
 *
 * Тиры лимитов:
 *   critical — 5/мин  (вывод средств, сохранение реквизитов)
 *   write    — 10/мин (тикеты, ответы, заявки)
 *   read     — 30/мин (пагинация, балансы, поиск)
 *   admin    — 60/мин (все админ-обработчики)
 *
 * Grey IP scoring:
 *   0-19  — нормальный IP
 *   20-79 — серый IP → CAPTCHA на critical/write
 *   ≥80   — заблокирован → все AJAX отклоняются
 *
 * @since 2.1.0
 */
class Cashback_Rate_Limiter {

    /**
     * Атомарный backend для инкремента счётчиков (Группа 7 ADR, шаг 5).
     *
     * Lazy-инициализируется в get_backend() как SQL counter, обёрнутый в cache-decorator.
     * В тестах подменяется через set_backend().
     *
     * @var Cashback_Rate_Limit_Counter_Interface|null
     */
    private static ?object $backend = null;

    /**
     * Конфигурация тиров: [лимит, окно в секундах].
     */
    private const TIERS = array(
        'critical' => array( 5, 60 ),
        'write'    => array( 10, 60 ),
        'read'     => array( 30, 60 ),
        'admin'    => array( 60, 60 ),
    );

    /**
     * Реестр AJAX-действий плагина → tier.
     *
     * @var array<string, string>
     */
    private const ACTION_TIERS = array(
        // --- Critical ---
        'process_cashback_withdrawal'               => 'critical',
        'save_payout_settings'                      => 'critical',

        // --- Write ---
        'cashback_contact_submit'                   => 'write',
        'support_create_ticket'                     => 'write',
        'support_user_reply'                        => 'write',
        'support_user_close_ticket'                 => 'write',
        'claims_submit'                             => 'write',
        'cashback_save_notification_prefs'          => 'write',
        'claims_mark_read'                          => 'write',
        'social_start'                              => 'write',
        'social_callback'                           => 'write',
        'social_confirm'                            => 'write',
        // Группа 7 (шаг 9 ADR): пополнение реестра для закрытия F-9-003
        // (ранее оба action'а вызывали check() мимо реестра → fail-open).
        'social_email_prompt'                       => 'write',
        'social_unlink'                             => 'write',

        // --- Read ---
        'get_user_balance'                          => 'read',
        'search_banks'                              => 'read',
        'load_page_transactions'                    => 'read',
        'load_page_payouts'                         => 'read',
        'support_load_ticket'                       => 'read',
        'support_download_file'                     => 'read',
        'support_load_tickets_page'                 => 'read',
        'affiliate_load_accruals'                   => 'read',
        'affiliate_load_referrals'                  => 'read',
        'claims_check_eligibility'                  => 'read',
        'claims_calculate_score'                    => 'read',
        'claims_load_clicks'                        => 'read',
        'claims_load_claims'                        => 'read',
        'cashback_fraud_fingerprint'                => 'read',

        // --- Admin ---
        'update_payout_request'                     => 'admin',
        'get_payout_request'                        => 'admin',
        'decrypt_payout_details'                    => 'admin',
        'verify_payout_balance'                     => 'admin',
        'update_payout_method'                      => 'admin',
        'add_payout_method'                         => 'admin',
        'save_withdrawal_settings'                  => 'admin',
        'update_bank'                               => 'admin',
        'add_bank'                                  => 'admin',
        'update_user_profile'                       => 'admin',
        'get_user_profile'                          => 'admin',
        'bulk_update_cashback_rate'                 => 'admin',
        'update_transaction'                        => 'admin',
        'get_transaction'                           => 'admin',
        'transfer_unregistered_transaction'         => 'admin',
        'cashback_validate_user'                    => 'admin',
        'cashback_save_api_credentials'             => 'admin',
        'cashback_manual_sync'                      => 'admin',
        'cashback_get_sync_log'                     => 'admin',
        'cashback_get_validation_status'            => 'admin',
        'cashback_test_connection'                  => 'admin',
        'cashback_edit_transaction'                 => 'admin',
        'cashback_add_transaction'                  => 'admin',
        'cashback_overwrite_transaction'            => 'admin',
        'cashback_check_campaigns_now'              => 'admin',
        'cashback_reactivate_product'               => 'admin',
        'fraud_review_alert'                        => 'admin',
        'fraud_get_alert_details'                   => 'admin',
        'fraud_save_settings'                       => 'admin',
        'fraud_run_scan_now'                        => 'admin',
        'fraud_ban_user'                            => 'admin',
        'support_toggle_module'                     => 'admin',
        'support_admin_reply'                       => 'admin',
        'support_change_status'                     => 'admin',
        'support_admin_unread_count'                => 'admin',
        'support_save_attachment_settings'          => 'admin',
        'support_admin_download_file'               => 'admin',
        'claims_admin_transition'                   => 'admin',
        'claims_admin_add_note'                     => 'admin',
        'claims_admin_get_detail'                   => 'admin',
        'claims_admin_stats'                        => 'admin',
        'affiliate_toggle_module'                   => 'admin',
        'affiliate_save_settings'                   => 'admin',
        'affiliate_update_partner'                  => 'admin',
        'affiliate_get_partner_details'             => 'admin',
        'affiliate_bulk_update_commission_rate'     => 'admin',
        'affiliate_edit_accrual'                    => 'admin',
        'update_partner'                            => 'admin',
        'add_partner'                               => 'admin',
        'save_network_params'                       => 'admin',
        'get_network_params'                        => 'admin',
        'delete_network_param'                      => 'admin',
        'update_network_param'                      => 'admin',
        'cashback_admin_save_notification_settings' => 'admin',
        'fraud_save_bot_settings'                   => 'admin',
    );

    /**
     * Получить tier для AJAX-действия.
     *
     * @param string $action Имя AJAX-действия.
     * @return string|null Tier или null если действие не зарегистрировано.
     */
    public static function get_tier( string $action ): ?string {
        return self::ACTION_TIERS[ $action ] ?? null;
    }

    /**
     * Проверить и зарегистрировать действие пользователя.
     *
     * @param string $action  Имя AJAX-действия.
     * @param int    $user_id ID пользователя.
     * @param string $ip      IP-адрес.
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public static function check( string $action, int $user_id, string $ip ): array {
        $tier = self::get_tier($action);

        if ($tier === null) {
            // Группа 7 (шаг 9 ADR): fail-closed для unregistered actions (iter-9 F-9-003).
            // Фильтр cashback_rate_limit_unregistered_policy оставлен для экстренного
            // отката на совместимость: 'deny' (default) | 'allow'. В production-проде
            // не ожидаем активации, но даёт откат без деплоя при непредвиденных action'ах.
            $policy = (string) apply_filters('cashback_rate_limit_unregistered_policy', 'deny', $action);

            if ($policy === 'allow') {
                return array(
                    'allowed'     => true,
                    'remaining'   => 999,
                    'retry_after' => 0,
                );
            }

            return array(
                'allowed'     => false,
                'remaining'   => 0,
                'retry_after' => self::GREY_TTL,
            );
        }

        // Заблокированный IP (grey score >= блок-порог) — отклоняем до истечения GREY_TTL,
        // как задекларировано в комментарии класса (>=80 → все AJAX отклоняются).
        if (self::is_blocked_ip($ip)) {
            return array(
                'allowed'     => false,
                'remaining'   => 0,
                'retry_after' => self::GREY_TTL,
            );
        }

        [$limit, $window] = self::TIERS[ $tier ];

        // Для серых IP — урезаем лимит на critical/write
        if (in_array($tier, array( 'critical', 'write' ), true) && self::is_grey_ip($ip)) {
            $limit = max(1, (int) floor($limit / 2));
        }

        // Группа 7 (шаг 5 ADR): атомарный backend заменяет старый get/set_transient
        // race-паттерн. scope_key детерминистичен, длина 42 <= 64 (VARCHAR(64) PK).
        $scope_key = self::make_scope_key($tier, $action, $user_id, $ip);

        try {
            $result = self::get_backend()->increment($scope_key, $window, $limit);
        } catch (\Throwable $e) {
            // Fail-open при ошибке backend'а: не ломаем availability если БД/cache
            // недоступны. На уровне миграций это временное состояние (таблица создаётся
            // автоматически на plugins_loaded prio=115). Диагностика — в error_log.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Backend failure diagnostic (group 7, step 5).
            error_log('[cashback-rate-limiter] backend error: ' . $e->getMessage());

            return array(
                'allowed'     => true,
                'remaining'   => $limit,
                'retry_after' => 0,
            );
        }

        if (! $result['allowed']) {
            // Записываем нарушение для grey scoring
            self::record_violation($ip, 'rate_limit');

            return array(
                'allowed'     => false,
                'remaining'   => 0,
                'retry_after' => $window,
            );
        }

        return array(
            'allowed'     => true,
            'remaining'   => max(0, $limit - (int) $result['hits']),
            'retry_after' => 0,
        );
    }

    /**
     * Детерминированный ключ для rate-limit counter (Группа 7).
     *
     * Формат: `{tier[0]}_{sha1(action|user_id|ip)[0:40]}` → 42 символа ≤ VARCHAR(64) PK лимита.
     * Префикс tier-buck'а нужен чтобы разные тиры одного action не коллижили
     * (на практике одно действие всегда одного тира, но defense-in-depth).
     */
    private static function make_scope_key( string $tier, string $action, int $user_id, string $ip ): string {
        return sprintf('%s_%s', $tier[0], substr(sha1($action . '|' . $user_id . '|' . $ip), 0, 40));
    }

    /**
     * Lazy-init атомарного backend'а (SQL counter + cache decorator).
     * Используется set_backend() для тестов.
     */
    private static function get_backend(): object {
        if (self::$backend !== null) {
            return self::$backend;
        }

        self::require_rate_limit_classes();

        global $wpdb;
        $sql  = new \Cashback_Rate_Limit_SQL_Counter(
            $wpdb,
            $wpdb->prefix . \Cashback_Rate_Limit_Migration::TABLE_SUFFIX
        );
        self::$backend = new \Cashback_Rate_Limit_Cached_Counter($sql);

        return self::$backend;
    }

    /**
     * DI-seam для тестов: подменяет backend либо сбрасывает в null для re-init.
     */
    public static function set_backend( ?object $backend ): void {
        self::$backend = $backend;
    }

    /**
     * Публичный доступ к атомарному backend'у для non-check() callsite'ов
     * (wc-affiliate click-rate, rest-api /activate). Все они разделяют один
     * backend — это даёт единую таблицу счётчиков и единый GC-цикл.
     */
    public static function counter_backend(): object {
        return self::get_backend();
    }

    private static function require_rate_limit_classes(): void {
        if (!interface_exists('Cashback_Rate_Limit_Counter_Interface')) {
            require_once __DIR__ . '/rate-limit/interface-cashback-rate-limit-counter.php';
        }
        if (!class_exists('Cashback_Rate_Limit_SQL_Counter')) {
            require_once __DIR__ . '/rate-limit/class-cashback-rate-limit-sql-counter.php';
        }
        if (!class_exists('Cashback_Rate_Limit_Cached_Counter')) {
            require_once __DIR__ . '/rate-limit/class-cashback-rate-limit-cached-counter.php';
        }
        if (!class_exists('Cashback_Rate_Limit_Migration')) {
            require_once __DIR__ . '/class-cashback-rate-limit-migration.php';
        }
    }

    /**
     * Проверить, является ли действие зарегистрированным в плагине.
     *
     * @param string $action Имя AJAX-действия.
     * @return bool
     */
    public static function is_plugin_action( string $action ): bool {
        return isset(self::ACTION_TIERS[ $action ]);
    }

    // =========================================================================
    // Grey IP scoring
    // =========================================================================

    /**
     * Баллы за типы нарушений.
     */
    private const VIOLATION_SCORES = array(
        'rate_limit' => 10,
        'bot_ua'     => 30,
        'honeypot'   => 40,
        'timing'     => 15,
    );

    /** Grey IP TTL в секундах (1 час, sliding). */
    private const GREY_TTL = 3600;

    /**
     * Записать нарушение для IP.
     *
     * @param string $ip     IP-адрес.
     * @param string $reason Тип нарушения (rate_limit|bot_ua|honeypot|timing).
     */
    public static function record_violation( string $ip, string $reason ): void {
        $score_add = self::VIOLATION_SCORES[ $reason ] ?? 10;
        $key       = self::grey_key($ip);
        $current   = (int) get_transient($key);
        $new_score = min(100, $current + $score_add);

        // Sliding TTL: каждое нарушение продлевает таймер
        set_transient($key, $new_score, self::GREY_TTL);
    }

    /**
     * Является ли IP серым (score >= порога).
     *
     * @param string $ip IP-адрес.
     * @return bool
     */
    public static function is_grey_ip( string $ip ): bool {
        $threshold = (int) get_option('cashback_bot_grey_threshold', 20);
        return self::get_grey_score($ip) >= $threshold;
    }

    /**
     * Является ли IP заблокированным (score >= порога блокировки).
     *
     * @param string $ip IP-адрес.
     * @return bool
     */
    public static function is_blocked_ip( string $ip ): bool {
        $threshold = (int) get_option('cashback_bot_block_threshold', 80);
        return self::get_grey_score($ip) >= $threshold;
    }

    /**
     * Получить grey score для IP.
     *
     * @param string $ip IP-адрес.
     * @return int Score 0-100.
     */
    public static function get_grey_score( string $ip ): int {
        return (int) get_transient(self::grey_key($ip));
    }

    /**
     * Transient ключ для grey score.
     *
     * @param string $ip IP-адрес.
     * @return string
     */
    private static function grey_key( string $ip ): string {
        return 'cb_grey_' . substr(md5($ip), 0, 12);
    }
}
