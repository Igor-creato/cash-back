<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Consent_Manager
 *
 * Бизнес-логика поверх журнала Cashback_Legal_DB:
 *   - record_consent / withdraw_consent / mark_superseded
 *   - has_active_consent (последний granted без позднейшего revoke/superseded)
 *   - needs_reconsent (текущая active-версия документа > версии последнего granted)
 *   - get_pending_reconsent_types — список типов, по которым у юзера нет
 *     актуального согласия (для re-consent модала)
 *
 * Идемпотентность: каждая запись имеет UNIQUE request_id. Повторный submit
 * формы с тем же request_id не плодит дубликат.
 *
 * @since 1.3.0
 */
class Cashback_Legal_Consent_Manager {

    public const ACTION_GRANTED    = 'granted';
    public const ACTION_REVOKED    = 'revoked';
    public const ACTION_SUPERSEDED = 'superseded';

    public const RECONSENT_CACHE_TTL = 300; // 5 минут

    /**
     * Записать согласие. Вызывается с формы регистрации, выводов, cookie-баннера.
     *
     * @param int|null $user_id     NULL для гостей (cookies-баннер).
     * @param string   $consent_type См. Cashback_Legal_Documents::all_types().
     * @param string   $source      registration|profile|payout|...
     * @param string   $request_id  Идемпотентный uuid (с дефисами или без).
     * @param array<string, mixed> $extra Поля: ip_address, user_agent, document_url, extra_meta.
     * @return int|false ID строки журнала или false (включая случай duplicate request_id).
     */
    public static function record_consent(
        ?int $user_id,
        string $consent_type,
        string $source,
        string $request_id,
        array $extra = array()
    ) {
        if (!self::is_valid_consent_type($consent_type)) {
            return false;
        }
        $request_id = self::normalize_request_id($request_id);
        if ($request_id === '') {
            return false;
        }

        $version = Cashback_Legal_Documents::get_active_version($consent_type);
        $hash    = isset($extra['document_hash']) ? (string) $extra['document_hash']
            : Cashback_Legal_Documents::compute_hash($consent_type);

        $row = array(
            'user_id'          => $user_id,
            'consent_type'     => $consent_type,
            'action'           => self::ACTION_GRANTED,
            'document_version' => $version,
            'document_hash'    => $hash,
            'document_url'     => isset($extra['document_url']) ? (string) $extra['document_url'] : null,
            'ip_address'       => isset($extra['ip_address']) ? self::sanitize_ip((string) $extra['ip_address']) : null,
            'user_agent'       => isset($extra['user_agent']) ? self::sanitize_ua((string) $extra['user_agent']) : null,
            'request_id'       => $request_id,
            'source'           => $source,
            'granted_at'       => gmdate('Y-m-d H:i:s'),
            'revoked_at'       => null,
            'extra_meta'       => isset($extra['extra_meta']) && is_array($extra['extra_meta'])
                ? wp_json_encode($extra['extra_meta'])
                : null,
        );

        $id = Cashback_Legal_DB::insert_log_row($row);

        if ($id !== false && $user_id !== null) {
            self::clear_reconsent_cache($user_id);
        }

        return $id;
    }

    /**
     * Отзыв согласия. Append-only — пишем новую строку с action='revoked',
     * не меняя существующие.
     */
    public static function withdraw_consent(
        int $user_id,
        string $consent_type,
        string $source,
        string $request_id,
        array $extra = array()
    ) {
        if ($user_id <= 0 || !self::is_valid_consent_type($consent_type)) {
            return false;
        }
        $request_id = self::normalize_request_id($request_id);
        if ($request_id === '') {
            return false;
        }

        $version = Cashback_Legal_Documents::get_active_version($consent_type);
        $hash    = Cashback_Legal_Documents::compute_hash($consent_type);
        $now     = gmdate('Y-m-d H:i:s');

        $id = Cashback_Legal_DB::insert_log_row(array(
            'user_id'          => $user_id,
            'consent_type'     => $consent_type,
            'action'           => self::ACTION_REVOKED,
            'document_version' => $version,
            'document_hash'    => $hash,
            'ip_address'       => isset($extra['ip_address']) ? self::sanitize_ip((string) $extra['ip_address']) : null,
            'user_agent'       => isset($extra['user_agent']) ? self::sanitize_ua((string) $extra['user_agent']) : null,
            'request_id'       => $request_id,
            'source'           => $source,
            'granted_at'       => $now,
            'revoked_at'       => $now,
            'extra_meta'       => isset($extra['extra_meta']) && is_array($extra['extra_meta'])
                ? wp_json_encode($extra['extra_meta'])
                : null,
        ));

        if ($id !== false) {
            self::clear_reconsent_cache($user_id);
        }

        return $id;
    }

    /**
     * Активное согласие = есть granted без последующего revoke/superseded
     * текущей или более поздней версии документа.
     *
     * Для guest user_id=0 → всегда false (журналим только индикативно для cookies).
     */
    public static function has_active_consent( int $user_id, string $consent_type ): bool {
        if ($user_id <= 0 || !self::is_valid_consent_type($consent_type)) {
            return false;
        }
        $row = Cashback_Legal_DB::get_last_active_granted($user_id, $consent_type);
        return is_array($row) && !empty($row);
    }

    /**
     * Возвращает запись последнего granted (или null), если она не отменена/superseded.
     *
     * @return array<string, mixed>|null
     */
    public static function get_last_granted( int $user_id, string $consent_type ): ?array {
        if ($user_id <= 0 || !self::is_valid_consent_type($consent_type)) {
            return null;
        }
        return Cashback_Legal_DB::get_last_active_granted($user_id, $consent_type);
    }

    /**
     * Нужен re-consent: есть granted, но его document_version < active_version.
     * Если active_consent отсутствует совсем — ВОЗВРАЩАЕТ false: это не «нужен
     * re-consent», это «нет согласия» (другой UX-путь, обрабатывается на форме).
     */
    public static function needs_reconsent( int $user_id, string $consent_type ): bool {
        $last = self::get_last_granted($user_id, $consent_type);
        if ($last === null) {
            return false;
        }
        $active_version = Cashback_Legal_Documents::get_active_version($consent_type);
        $granted_version = isset($last['document_version']) ? (string) $last['document_version'] : '0.0.0';
        return version_compare($granted_version, $active_version, '<');
    }

    /**
     * Какие типы требуют re-consent для этого юзера. Используется модалом
     * на template_redirect для авторизованных пользователей.
     *
     * Кешируется в transient на TTL=300с — не проверяем БД на каждый запрос.
     *
     * @return array<int, string>
     */
    public static function get_pending_reconsent_types( int $user_id ): array {
        if ($user_id <= 0) {
            return array();
        }

        $cache_key = self::reconsent_cache_key($user_id);
        $cached    = function_exists('get_transient') ? get_transient($cache_key) : false;
        if (is_array($cached)) {
            return $cached;
        }

        $pending = array();
        // Только обязательные consent-типы — marketing/cookies/contact_form_pd
        // не входят: их отзыв или отсутствие не блокирует пользование сервисом.
        $required = array(
            Cashback_Legal_Documents::TYPE_PD_CONSENT,
            Cashback_Legal_Documents::TYPE_TERMS_OFFER,
        );
        foreach ($required as $type) {
            if (self::needs_reconsent($user_id, $type)) {
                $pending[] = $type;
            }
        }

        if (function_exists('set_transient')) {
            set_transient($cache_key, $pending, self::RECONSENT_CACHE_TTL);
        }

        return $pending;
    }

    /**
     * Сброс кеша pending-types (после record/withdraw/admin-bump).
     */
    public static function clear_reconsent_cache( int $user_id ): void {
        if ($user_id <= 0) {
            return;
        }
        if (function_exists('delete_transient')) {
            delete_transient(self::reconsent_cache_key($user_id));
        }
    }

    /**
     * Batch-обновление: пишет строки action='superseded' для всех ранее
     * granted-юзеров по типу $type с document_version < $bumped_to_version.
     * Каждая superseded-строка получает уникальный request_id (UNIQUE constraint).
     *
     * Идемпотентно: повторный вызов после bump'а не плодит дубликаты —
     * мы выбираем юзеров с last granted < bumped_version, и после первого
     * прохода у них last granted либо остался прежний (но появилась
     * следующая запись superseded), либо нет — повторный SELECT пропустит
     * их через нашу проверку «нет superseded после granted».
     *
     * @return int количество вставленных superseded-строк
     */
    public static function mark_superseded_for_type( string $type, string $bumped_to_version ): int {
        if (!self::is_valid_consent_type($type)) {
            return 0;
        }
        global $wpdb;
        $table = Cashback_Legal_DB::table_name();

        // Берём id юзеров, у которых последний granted имеет version < bumped.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $candidate_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT user_id FROM %i
                 WHERE consent_type = %s AND action = 'granted' AND user_id IS NOT NULL",
                $table,
                $type
            )
        );

        if (!is_array($candidate_ids) || empty($candidate_ids)) {
            return 0;
        }

        $count = 0;
        foreach ($candidate_ids as $uid_raw) {
            $uid = (int) $uid_raw;
            if ($uid <= 0) {
                continue;
            }
            $last = self::get_last_granted($uid, $type);
            if ($last === null) {
                continue;
            }
            $granted_version = isset($last['document_version']) ? (string) $last['document_version'] : '0.0.0';
            if (version_compare($granted_version, $bumped_to_version, '>=')) {
                continue;
            }

            $rid = self::generate_request_id();
            $now = gmdate('Y-m-d H:i:s');
            $row = array(
                'user_id'          => $uid,
                'consent_type'     => $type,
                'action'           => self::ACTION_SUPERSEDED,
                'document_version' => $granted_version,
                'document_hash'    => isset($last['document_hash']) ? (string) $last['document_hash'] : '',
                'document_url'     => isset($last['document_url']) ? (string) $last['document_url'] : null,
                'ip_address'       => null,
                'user_agent'       => null,
                'request_id'       => $rid,
                'source'           => 'admin_bump',
                'granted_at'       => $now,
                'revoked_at'       => null,
                'extra_meta'       => wp_json_encode(array(
                    'bumped_to' => $bumped_to_version,
                )),
            );
            $inserted = Cashback_Legal_DB::insert_log_row($row);
            if ($inserted !== false) {
                ++$count;
                self::clear_reconsent_cache($uid);
            }
        }
        return $count;
    }

    /**
     * Проверка известного типа согласия (защита от инъекции через POST).
     */
    public static function is_valid_consent_type( string $type ): bool {
        return in_array($type, Cashback_Legal_Documents::all_types(), true);
    }

    /**
     * Нормализация request_id: lowercase, без дефисов, только hex 32-64 символа.
     */
    public static function normalize_request_id( string $raw ): string {
        $clean = strtolower(str_replace('-', '', trim($raw)));
        if ($clean === '' || strlen($clean) < 16 || strlen($clean) > 64) {
            return '';
        }
        if (preg_match('/[^0-9a-f]/', $clean)) {
            return '';
        }
        return $clean;
    }

    /**
     * Генерация request_id (uuid v4-стиля без дефисов) — для серверных вызовов
     * (admin-bump, social-auth callback), где у нас нет form-hidden поля.
     */
    public static function generate_request_id(): string {
        if (function_exists('cashback_generate_uuid7')) {
            return (string) cashback_generate_uuid7(false);
        }
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return md5(uniqid('cashback_legal_', true));
        }
    }

    /**
     * Cache-key для transient. Не кладём user_id в plain — sha1 короче и стабильнее.
     */
    private static function reconsent_cache_key( int $user_id ): string {
        return 'cashback_legal_pending_' . sha1((string) $user_id);
    }

    private static function sanitize_ip( string $ip ): ?string {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }
        $filtered = filter_var($ip, FILTER_VALIDATE_IP);
        return is_string($filtered) ? $filtered : null;
    }

    private static function sanitize_ua( string $ua ): ?string {
        $ua = trim($ua);
        if ($ua === '') {
            return null;
        }
        // Хранится TEXT, но обрезаем сразу до разумного — защита от мегабайтных payload'ов.
        if (strlen($ua) > 1024) {
            $ua = substr($ua, 0, 1024);
        }
        return function_exists('sanitize_text_field') ? sanitize_text_field($ua) : $ua;
    }
}
