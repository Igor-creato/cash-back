<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_User_Anonymizer
 *
 * Анонимизация пользователя (152-ФЗ ст. 9 ч. 4) с одновременным сохранением
 * финансовой первички (115-ФЗ + ФЗ «О бухучёте» + НК ст. 23 + 161-ФЗ — хранение
 * ≥ 5 лет). PII скрабится, числа и статусы в финансовых таблицах сохраняются.
 *
 * См. obsidian/knowledge/patterns/user-anonymization.md.
 *
 * @since 1.4.0
 */
class Cashback_User_Anonymizer {

    public const STATUS_DELETED = 'deleted';
    public const ANON_IP        = '0.0.0.0';
    public const ANON_UA_HASH   = 'anonymized';

    /**
     * Per-run buffer ошибок (table+error) для error collection (P0.4).
     * Сбрасывается в начале anonymize(), читается в конце для summary+audit.
     *
     * @var array<int, array{table:string, error:string}>
     */
    private static array $step_errors = array();

    /** PII keys в wp_usermeta (точное совпадение). */
    private const PII_USERMETA_KEYS = array(
        'first_name',
        'last_name',
        'description',
        'nickname',
        'session_tokens',
    );

    /** PII prefixes в wp_usermeta (LIKE). */
    private const PII_USERMETA_PREFIXES = array(
        'billing_',
        'shipping_',
    );

    /** Финансовые таблицы (slug без префикса) с колонкой user_id. */
    private const FINANCIAL_TABLES_USER_ID = array(
        'cashback_balance_ledger',
        'cashback_transactions',
        'cashback_payout_requests',
        'cashback_claims',
    );

    /**
     * Колонки cashback_user_balance, любая ненулевая = есть фин-история.
     */
    private const BALANCE_COLUMNS = array(
        'available_balance',
        'pending_balance',
        'paid_balance',
        'frozen_balance',
        'frozen_balance_ban',
        'frozen_balance_payout',
        'frozen_pending_balance_ban',
    );

    /**
     * Проверяет наличие финансовой истории у пользователя.
     *
     * Если есть хотя бы одна строка в финансовых таблицах ИЛИ ненулевой остаток
     * на cashback_user_balance — юзер не может быть hard-deleted (FK + закон).
     */
    public static function has_financial_history( int $user_id ): bool {
        global $wpdb;
        if ($user_id <= 0 || !is_object($wpdb)) {
            return false;
        }

        // 1) Таблицы с user_id.
        foreach (self::FINANCIAL_TABLES_USER_ID as $slug) {
            $table = $wpdb->prefix . $slug;
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only EXISTS-check via %i; non-cached by design.
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT EXISTS(SELECT 1 FROM %i WHERE user_id = %d LIMIT 1)',
                $table,
                $user_id
            ));
            if ($exists > 0) {
                return true;
            }
        }

        // 2) Партнёрские начисления — реальные колонки (см. affiliate-db.php
        // CREATE TABLE и F-22-003): `referrer_id` (кому начислена комиссия) и
        // `referred_user_id` (чья покупка). Ловим юзера в любой роли.
        $accruals = $wpdb->prefix . 'cashback_affiliate_accruals';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only EXISTS-check via %i.
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT EXISTS(SELECT 1 FROM %i WHERE referrer_id = %d OR referred_user_id = %d LIMIT 1)',
            $accruals,
            $user_id,
            $user_id
        ));
        if ($exists > 0) {
            return true;
        }

        // 3) Балансы (кэш).
        $balance_table = $wpdb->prefix . 'cashback_user_balance';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only via %i.
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT available_balance, pending_balance, paid_balance, frozen_balance,
                    frozen_balance_ban, frozen_balance_payout, frozen_pending_balance_ban
             FROM %i
             WHERE user_id = %d',
            $balance_table,
            $user_id
        ), ARRAY_A);
        if (is_array($row)) {
            foreach (self::BALANCE_COLUMNS as $col) {
                $val = $row[ $col ] ?? '0';
                if ((float) $val !== 0.0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Анонимизирует пользователя: PII скрабится, финансы сохраняются.
     *
     * Idempotent: повторный вызов для уже обезличенного юзера вернёт
     * `idempotent_noop=true` без записи в audit/consent_log.
     *
     * Если часть таблиц упала на schema mismatch / SQL error — возврат
     * `ok=true, partial=true, errors=[{table,error}, ...]`. Транзакция при
     * partial-fail коммитится (PII всё равно частично стёрт; rollback вернул
     * бы PII обратно).
     *
     * @return array{ok:bool, tables_scrubbed:int, consents_revoked:int, errors:array<int,array{table:string,error:string}>, idempotent_noop?:bool, partial?:bool}
     */
    public static function anonymize( int $user_id, int $admin_id, string $reason ): array {
        if ($user_id <= 0) {
            return array(
                'ok'               => false,
                'tables_scrubbed'  => 0,
                'consents_revoked' => 0,
                'errors'           => array( array( 'table' => 'precondition', 'error' => 'invalid_user_id' ) ),
            );
        }

        // Guard: нельзя анонимизировать админа. WP и так не даст удалить
        // last super-admin, но это дополнительный safety-net на UI-уровне.
        if (function_exists('user_can') && user_can($user_id, 'manage_options')) {
            return array(
                'ok'               => false,
                'tables_scrubbed'  => 0,
                'consents_revoked' => 0,
                'errors'           => array( array( 'table' => 'precondition', 'error' => 'cannot_anonymize_admin' ) ),
            );
        }

        global $wpdb;
        if (!is_object($wpdb)) {
            return array(
                'ok'               => false,
                'tables_scrubbed'  => 0,
                'consents_revoked' => 0,
                'errors'           => array( array( 'table' => 'precondition', 'error' => 'wpdb_not_initialized' ) ),
            );
        }

        // Idempotency guard: повторный вызов anonymize() для уже обезличенного
        // юзера не должен дублировать audit_log и consent_log (compliance issue).
        // Признак — cashback_user_profile.status = 'deleted'.
        if (self::is_already_anonymized($user_id)) {
            return array(
                'ok'               => true,
                'tables_scrubbed'  => 0,
                'consents_revoked' => 0,
                'errors'           => array(),
                'idempotent_noop'  => true,
            );
        }

        $tables_scrubbed = 0;
        // Сбрасываем буфер ошибок step'ов (P0.4) — собираем актуальный run.
        self::$step_errors = array();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional admin-only path.
        $wpdb->query('START TRANSACTION');
        try {
            $tables_scrubbed += self::scrub_wp_users($user_id);
            $tables_scrubbed += self::scrub_wp_usermeta($user_id);
            $tables_scrubbed += self::scrub_cashback_profile($user_id);
            $tables_scrubbed += self::scrub_payout_requests_pii($user_id);
            $tables_scrubbed += self::scrub_user_fingerprints($user_id);
            $tables_scrubbed += self::scrub_click_log($user_id);
            // cashback_click_sessions: PII (IP/UA) в схеме отсутствует — там
            // только user_id, canonical_click_id, product/merchant, status и
            // timestamps. Скраб не требуется. См. obsidian/knowledge/patterns/
            // user-anonymization.md, баг #5 E2E 2026-04-29.
            $tables_scrubbed += self::scrub_audit_log($user_id);
            $tables_scrubbed += self::scrub_consent_log_meta($user_id);
            $tables_scrubbed += self::scrub_support_messages($user_id);
            $tables_scrubbed += self::delete_support_attachments($user_id);
            $tables_scrubbed += self::delete_social_auth_rows($user_id);

            // WooCommerce (graceful если WC не активен). Вызов через массив-callable
            // чтобы PHPStan не падал на отсутствующем классе сторонней зависимости.
            //
            // P1.1: WC_Privacy_Erasers::customer_data_erase агрессивно сносит
            // usermeta, включая wp_capabilities/wp_user_level — а спека требует
            // их keep (валидность роли в WP). Backup-restore вокруг WC erasers.
            if (class_exists('WC_Privacy_Erasers')) {
                $caps_key  = $wpdb->prefix . 'capabilities';
                $level_key = $wpdb->prefix . 'user_level';
                $caps_backup  = function_exists('get_user_meta') ? get_user_meta($user_id, $caps_key, true) : '';
                $level_backup = function_exists('get_user_meta') ? get_user_meta($user_id, $level_key, true) : '';

                try {
                    $email = self::anon_email_for($user_id);
                    foreach (array( 'customer_data_erase', 'order_data_erase' ) as $method) {
                        $callable = array( 'WC_Privacy_Erasers', $method );
                        if (is_callable($callable)) {
                            call_user_func($callable, $email);
                        }
                    }
                } catch (\Throwable $e) {
                    self::$step_errors[] = array( 'table' => 'wc_erasers', 'error' => $e->getMessage() );
                }

                // Restore роль: WP-юзер без capability теряет валидность для
                // последующего отображения в users-list и cron-cleanup.
                if (function_exists('update_user_meta')) {
                    if ($caps_backup !== '' && $caps_backup !== false) {
                        update_user_meta($user_id, $caps_key, $caps_backup);
                    }
                    if ($level_backup !== '' && $level_backup !== false) {
                        update_user_meta($user_id, $level_key, $level_backup);
                    }
                }
            }

            $consents_revoked = self::revoke_all_consents($user_id);

            // Снимок ошибок step'ов (P0.4): включается в summary, audit details
            // и используется UI для показа warning-баннера. Partial scrub (когда
            // часть таблиц упала на schema mismatch) НЕ откатывается — PII всё
            // равно частично стёрт, rollback вернул бы PII обратно в открытый вид.
            $errors  = self::$step_errors;
            $partial = !empty($errors);

            if (class_exists('Cashback_Encryption')) {
                $details = array(
                    'reason'           => $reason,
                    'tables_scrubbed'  => $tables_scrubbed,
                    'consents_revoked' => $consents_revoked,
                );
                if ($partial) {
                    $details['partial'] = true;
                    $details['errors']  = $errors;
                }
                \Cashback_Encryption::write_audit_log(
                    'user_anonymized',
                    $admin_id,
                    'user',
                    $user_id,
                    $details
                );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional admin-only path.
            $wpdb->query('COMMIT');

            return array(
                'ok'               => true,
                'tables_scrubbed'  => $tables_scrubbed,
                'consents_revoked' => $consents_revoked,
                'errors'           => $errors,
                'partial'          => $partial,
            );
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional admin-only path.
            $wpdb->query('ROLLBACK');
            $errors   = self::$step_errors;
            $errors[] = array( 'table' => 'exception', 'error' => $e->getMessage() );
            return array(
                'ok'               => false,
                'tables_scrubbed'  => 0,
                'consents_revoked' => 0,
                'errors'           => $errors,
            );
        }
    }

    /**
     * Hard-delete плагиновых строк для empty user (без фин-истории).
     * Вызывается из pre_delete_user (priority 5), чтобы FK не блокировали
     * штатный wp_delete_user.
     *
     * @return array{ok:bool, tables_cleaned:int}
     */
    public static function hard_delete_plugin_rows( int $user_id ): array {
        global $wpdb;
        if ($user_id <= 0 || !is_object($wpdb)) {
            return array( 'ok' => false, 'tables_cleaned' => 0 );
        }

        // Таблицы с user_id напрямую (DELETE WHERE user_id = X).
        // social_tokens (link_id, без user_id) и social_pending (без user_id)
        // обработаны отдельными блоками ниже. См. E2E 2026-04-29 баги #3-#4.
        $tables = array(
            'cashback_user_profile',
            'cashback_user_balance',
            'cashback_user_fingerprints',
            'cashback_click_log',
            'cashback_click_sessions',
            'cashback_consent_log',
        );

        $cleaned = 0;
        foreach ($tables as $slug) {
            $table = $wpdb->prefix . $slug;
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE с %i, идемпотентно.
            $wpdb->query($wpdb->prepare(
                'DELETE FROM %i WHERE user_id = %d',
                $table,
                $user_id
            ));
            ++$cleaned;
        }

        // social_tokens (link_id) → social_links (user_id). Порядок важен:
        // tokens сначала через JOIN, потом links — иначе подзапрос пустой.
        $links_table  = $wpdb->prefix . 'cashback_social_links';
        $tokens_table = $wpdb->prefix . 'cashback_social_tokens';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE %i + JOIN.
        $wpdb->query($wpdb->prepare(
            'DELETE t FROM %i AS t INNER JOIN %i AS l ON l.id = t.link_id WHERE l.user_id = %d',
            $tokens_table,
            $links_table,
            $user_id
        ));
        ++$cleaned;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE %i.
        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE user_id = %d',
            $links_table,
            $user_id
        ));
        ++$cleaned;

        // social_pending — НЕТ user_id (короткоживущие заявки с TTL). Чистится
        // по expires_at; для конкретного юзера ничего не делаем.

        if (class_exists('Cashback_Encryption')) {
            $actor_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            \Cashback_Encryption::write_audit_log(
                'user_hard_deleted',
                $actor_id,
                'user',
                $user_id,
                array( 'tables_cleaned' => $cleaned )
            );
        }

        return array( 'ok' => true, 'tables_cleaned' => $cleaned );
    }

    /**
     * WP-хук pre_delete_user (priority 5).
     *
     * - Админ → early return (WP сам не разрешит удалить super-admin).
     * - Юзер с фин-историей → wp_die с инструкцией использовать «Анонимизировать».
     * - Empty user → hard_delete_plugin_rows + дальше по штатной цепочке WP.
     *
     * @param int           $user_id  WP user ID, который WP пытается удалить.
     * @param int|null      $reassign User ID для переноса контента (не используется здесь — нужен по сигнатуре хука).
     * @param mixed         $user     WP_User объект (не используется здесь — нужен по сигнатуре хука).
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-хук pre_delete_user передаёт 3 аргумента.
    public static function on_pre_delete_user( int $user_id, ?int $reassign = null, mixed $user = null ): void {
        if ($user_id <= 0) {
            return;
        }
        if (function_exists('user_can') && user_can($user_id, 'manage_options')) {
            return;
        }
        if (self::has_financial_history($user_id)) {
            wp_die(
                esc_html__(
                    'Удаление пользователя с финансовой историей запрещено законодательством РФ (115-ФЗ, 161-ФЗ, ФЗ «О бухучёте», НК ст. 23). Используйте Cashback › Пользователи → «Анонимизировать».',
                    'cashback-plugin'
                ),
                esc_html__('Удаление недоступно', 'cashback-plugin'),
                array( 'response' => 409 )
            );
        }
        self::hard_delete_plugin_rows($user_id);
    }

    // ────────────────────────────────────────────────────────────────────
    // Внутренние helper'ы скраба отдельных таблиц.
    // Каждый возвращает 1 (таблица обработана) — для подсчёта tables_scrubbed.
    // ────────────────────────────────────────────────────────────────────

    /**
     * Проверяет $wpdb->last_error и при non-empty пушит запись в self::$step_errors.
     * Возвращает true если успех, false если ошибка. Сбрасывает last_error.
     */
    private static function record_query( string $label ): bool {
        global $wpdb;
        if (!is_object($wpdb)) {
            return false;
        }
        $err = (string) ($wpdb->last_error ?? '');
        if ($err !== '') {
            self::$step_errors[] = array( 'table' => $label, 'error' => $err );
            $wpdb->last_error = '';
            return false;
        }
        return true;
    }

    /**
     * Проверяет — пользователь уже анонимизирован (status='deleted' в profile).
     * Используется как idempotency guard в начале anonymize().
     */
    private static function is_already_anonymized( int $user_id ): bool {
        global $wpdb;
        if ($user_id <= 0 || !is_object($wpdb)) {
            return false;
        }
        $table = $wpdb->prefix . 'cashback_user_profile';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only check via %i.
        $status = $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM %i WHERE user_id = %d',
            $table,
            $user_id
        ));
        return is_string($status) && $status === self::STATUS_DELETED;
    }

    private static function scrub_wp_users( int $user_id ): int {
        global $wpdb;

        $hashed_pass = function_exists('wp_hash_password') && function_exists('wp_generate_password')
            ? wp_hash_password(wp_generate_password(64, true, true))
            : md5(uniqid('anon-', true));

        $table = isset($wpdb->users) && is_string($wpdb->users) && $wpdb->users !== ''
            ? $wpdb->users
            : ($wpdb->prefix . 'users');

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- скраб PII в core-таблице через %i.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET user_login = %s, user_email = %s, display_name = %s, user_url = %s, user_pass = %s, user_nicename = %s WHERE ID = %d',
            $table,
            'deleted_user_' . $user_id,
            self::anon_email_for($user_id),
            'Deleted User #' . $user_id,
            '',
            $hashed_pass,
            'deleted-user-' . $user_id,
            $user_id
        ));

        // КРИТИЧНО: сбросить WP object cache (Redis на нашем стеке).
        // Прямой UPDATE wp_users не инвалидирует cache, и `useremail[old_email]`
        // продолжает указывать на этого user_id. Соц-авторизация через тот же
        // email (Branch B в social-auth) по stale-cache попадёт в Branch B и
        // создаст новую связку для удалённого юзера, фактически «оживляя» его.
        // clean_user_cache() сбрасывает users[$id]; WP self-heal'ит useremail
        // mapping при следующем чтении (проверяет, что cached email совпадает
        // с user_email в свежей записи).
        if (function_exists('clean_user_cache')) {
            clean_user_cache($user_id);
        }

        return self::record_query('wp_users') ? 1 : 0;
    }

    private static function scrub_wp_usermeta( int $user_id ): int {
        global $wpdb;

        $table = isset($wpdb->usermeta) && is_string($wpdb->usermeta) && $wpdb->usermeta !== ''
            ? $wpdb->usermeta
            : ($wpdb->prefix . 'usermeta');

        $ok = true;

        foreach (self::PII_USERMETA_KEYS as $key) {
            $wpdb->last_error = '';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- скраб PII в usermeta через %i.
            $wpdb->query($wpdb->prepare(
                'DELETE FROM %i WHERE user_id = %d AND meta_key = %s',
                $table,
                $user_id,
                $key
            ));
            if (!self::record_query('wp_usermeta')) {
                $ok = false;
            }
        }

        foreach (self::PII_USERMETA_PREFIXES as $prefix) {
            $like = $wpdb->esc_like($prefix) . '%';
            $wpdb->last_error = '';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- LIKE с esc_like + %i.
            $wpdb->query($wpdb->prepare(
                'DELETE FROM %i WHERE user_id = %d AND meta_key LIKE %s',
                $table,
                $user_id,
                $like
            ));
            if (!self::record_query('wp_usermeta')) {
                $ok = false;
            }
        }

        return $ok ? 1 : 0;
    }

    private static function scrub_cashback_profile( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_profile';

        $wpdb->last_error = '';
        // status='deleted', PII NULL, banned_at = UTC now (для журнала).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE через %i.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET encrypted_details = NULL, masked_details = NULL,
                            payout_account = NULL, payout_full_name = NULL,
                            details_hash = NULL, status = %s, banned_at = UTC_TIMESTAMP()
             WHERE user_id = %d',
            $table,
            self::STATUS_DELETED,
            $user_id
        ));
        return self::record_query('cashback_user_profile') ? 1 : 0;
    }

    private static function scrub_payout_requests_pii( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_payout_requests';

        $wpdb->last_error = '';
        // ВАЖНО: total_amount, status, masked_details (последние 4 цифры) сохраняются
        // для финаудита — только PII-поля стираются.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE через %i.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET encrypted_details = NULL, payout_account = NULL WHERE user_id = %d',
            $table,
            $user_id
        ));
        return self::record_query('cashback_payout_requests') ? 1 : 0;
    }

    private static function scrub_user_fingerprints( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_fingerprints';
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE через %i.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET ip_address = %s, user_agent_hash = %s WHERE user_id = %d',
            $table,
            self::ANON_IP,
            self::ANON_UA_HASH,
            $user_id
        ));
        return self::record_query('cashback_user_fingerprints') ? 1 : 0;
    }

    private static function scrub_click_log( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_click_log';
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE через %i.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET ip_address = %s, user_agent = %s WHERE user_id = %d',
            $table,
            self::ANON_IP,
            '',
            $user_id
        ));
        return self::record_query('cashback_click_log') ? 1 : 0;
    }

    private static function scrub_audit_log( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_audit_log';
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE через %i; details уже редактирован через redact_audit_details на запись.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET ip_address = %s, user_agent = %s WHERE actor_id = %d',
            $table,
            self::ANON_IP,
            '',
            $user_id
        ));
        return self::record_query('cashback_audit_log') ? 1 : 0;
    }

    private static function scrub_consent_log_meta( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_consent_log';
        $wpdb->last_error = '';
        // ВАЖНО: append-only журнал — мы НЕ удаляем записи, только обнуляем
        // ip/ua-метаданные (PII), сохраняя сам факт согласия (доказательная база).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE через %i.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET ip_address = %s, user_agent = %s WHERE user_id = %d',
            $table,
            self::ANON_IP,
            '',
            $user_id
        ));
        return self::record_query('cashback_consent_log') ? 1 : 0;
    }

    private static function scrub_support_messages( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_support_messages';
        $wpdb->last_error = '';
        // Реальная схема (support-db.php) — `user_id` (не sender_id) и `is_admin`.
        // is_admin=0 — чтобы не затирать admin-ответы (это не PII скрабимого юзера).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE через %i.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET message = %s WHERE user_id = %d AND is_admin = 0',
            $table,
            '[anonymized]',
            $user_id
        ));
        return self::record_query('cashback_support_messages') ? 1 : 0;
    }

    private static function delete_support_attachments( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_support_attachments';

        // Реальная схема (support-db.php) — `user_id` (не uploader_id) и
        // `stored_name` (32-hex имя файла) + `ticket_id`. Путь строится через
        // Cashback_Support_DB::get_upload_dir($ticket_id) . '/' . $stored_name.
        // Best-effort unlink — БД-запись удаляется в любом случае ниже.
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- SELECT через %i.
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ticket_id, stored_name FROM %i WHERE user_id = %d',
            $table,
            $user_id
        ), ARRAY_A);
        $select_ok = self::record_query('cashback_support_attachments');

        if ($select_ok && is_array($rows) && class_exists('Cashback_Support_DB')) {
            foreach ($rows as $row) {
                $stored = (string) ($row['stored_name'] ?? '');
                $ticket = (int) ($row['ticket_id'] ?? 0);
                // Defence-in-depth: stored_name должен быть hex-строкой 32 символа
                // (см. Cashback_Support_DB::create_attachment).
                if ($ticket > 0 && $stored !== '' && preg_match('/^[a-f0-9]{32}$/', $stored) === 1) {
                    $path = \Cashback_Support_DB::get_upload_dir($ticket) . '/' . $stored;
                    if (file_exists($path) && is_file($path)) {
                        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup, БД-запись удаляется ниже в любом случае.
                        @unlink($path);
                    }
                }
            }
        }

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE через %i.
        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE user_id = %d',
            $table,
            $user_id
        ));
        $delete_ok = self::record_query('cashback_support_attachments');

        return ($select_ok && $delete_ok) ? 1 : 0;
    }

    private static function delete_social_auth_rows( int $user_id ): int {
        global $wpdb;

        // Реальная схема (includes/social-auth/class-social-auth-db.php):
        //   - social_links:  user_id ✅
        //   - social_tokens: link_id (FK на social_links.id), без user_id
        //   - social_pending: token, kind, payload_json, expires_at — БЕЗ user_id
        //                    (короткоживущие заявки с TTL, чистятся сами).
        //
        // Порядок важен: tokens сначала (иначе после DELETE links подзапрос пустой).
        $links_table  = $wpdb->prefix . 'cashback_social_links';
        $tokens_table = $wpdb->prefix . 'cashback_social_tokens';

        $ok = true;

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE через %i + JOIN.
        $wpdb->query($wpdb->prepare(
            'DELETE t FROM %i AS t INNER JOIN %i AS l ON l.id = t.link_id WHERE l.user_id = %d',
            $tokens_table,
            $links_table,
            $user_id
        ));
        if (!self::record_query('cashback_social_tokens')) {
            $ok = false;
        }

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE через %i.
        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE user_id = %d',
            $links_table,
            $user_id
        ));
        if (!self::record_query('cashback_social_links')) {
            $ok = false;
        }

        return $ok ? 1 : 0;
    }

    /**
     * Записывает revoke в cashback_consent_log для всех типов согласий.
     *
     * 152-ФЗ ст. 9 ч. 4 — оператор обязан подтвердить факт прекращения обработки
     * ПД. Append-only: не апдейтим существующие granted-строки, а добавляем новую
     * со статусом revoked.
     */
    private static function revoke_all_consents( int $user_id ): int {
        if (!class_exists('Cashback_Legal_Documents') || !class_exists('Cashback_Legal_DB')) {
            return 0;
        }

        $count = 0;
        foreach (\Cashback_Legal_Documents::consent_types() as $type) {
            $row = array(
                'user_id'          => $user_id,
                'consent_type'     => (string) $type,
                'action'           => 'revoked',
                'document_version' => \Cashback_Legal_Documents::DEFAULT_VERSION,
                'document_hash'    => str_repeat('0', 64),
                'request_id'       => self::generate_revoke_request_id(),
                'source'           => 'admin_anonymize',
                'ip_address'       => self::ANON_IP,
                'user_agent'       => null,
                'granted_at'       => gmdate('Y-m-d H:i:s'),
                'revoked_at'       => gmdate('Y-m-d H:i:s'),
            );
            $result = \Cashback_Legal_DB::insert_log_row($row);
            if ($result !== false) {
                ++$count;
            }
        }
        return $count;
    }

    private static function generate_revoke_request_id(): string {
        if (function_exists('cashback_generate_uuid7')) {
            return cashback_generate_uuid7(false);
        }
        // Fallback: 32-hex случайная строка (без дефисов).
        return bin2hex(random_bytes(16));
    }

    private static function anon_email_for( int $user_id ): string {
        return 'deleted_' . $user_id . '@anon.local';
    }
}
