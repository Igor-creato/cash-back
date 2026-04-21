<?php

/**
 * Cashback Idempotency helper — server-side дедуп `request_id` для admin-AJAX.
 *
 * Группа 5 ADR (security-refactor-plan-2026-04-21.md): закрывает разрыв «клиент
 * шлёт UUID v4/v7 в request_id, сервер игнорирует». Первый вызов с данным
 * request_id бронирует слот (claim), сохраняет результат (store_result);
 * повторные вызовы получают тот же результат (get_stored_result) без повторной
 * обработки.
 *
 * Backend-приоритет:
 *   1. wp_cache_add() — атомарный compare-and-set при persistent object cache
 *      (Redis/Memcached). Единственный безопасный путь от parallel race.
 *   2. set_transient() — durable fallback для eviction кеша и окружений без
 *      object-cache. На чистом transient остаётся миллисекундное race-окно
 *      между get_transient() и set_transient() — приемлемо для admin-тира
 *      (mission-critical пути закрывает Группа 6 через schema-level UNIQUE).
 *
 * Ключи: `cb_idem_{scope}_{user_id}_{sha1(request_id)[0:16]}`. Cache group
 * — `cashback_idempotency`. TTL по умолчанию 300 секунд (5 минут — хватает
 * на retry прокси без удержания лишних entries).
 *
 * @package Cashback
 * @since   1.0.0 (Group 5)
 */

defined('ABSPATH') || exit;

final class Cashback_Idempotency {

    public const DEFAULT_TTL  = 300;
    public const CACHE_GROUP  = 'cashback_idempotency';
    private const KEY_PREFIX  = 'cb_idem_';
    private const STATE_DONE  = 'done';
    private const STATE_CLAIM = 'processing';

    /**
     * Нормализует сырой `request_id` до canonical lowercase-hex без дефисов.
     *
     * Принимает UUID v4/v7 с дефисами или без. Возвращает '' если вход
     * не UUID — вызывающий хендлер работает в legacy-режиме без дедупликации.
     */
    public static function normalize_request_id( string $raw ): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // Тот же regex, что в includes/class-cashback-contact-form.php:292 —
        // reference-паттерн Группы 5.
        if (!(bool) preg_match(
            '/^[a-f0-9]{8}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{4}-?[a-f0-9]{12}$/i',
            $raw
        )) {
            return '';
        }

        return strtolower(str_replace('-', '', $raw));
    }

    /**
     * Атомарный claim слота под `(scope, user_id, request_id)`.
     *
     * TRUE — слот свободен, первый вызов; хендлер обрабатывает запрос и
     * затем пишет результат через store_result().
     * FALSE — уже занят (обрабатывается параллельно или завершён и есть
     * stored result, который вызывающий должен вернуть).
     *
     * Возвращает FALSE для пустого `$request_id` — вызывающий должен
     * сначала применить normalize_request_id().
     */
    public static function claim(
        string $scope,
        int $user_id,
        string $request_id,
        int $ttl = self::DEFAULT_TTL
    ): bool {
        if ($request_id === '') {
            return false;
        }

        $key = self::build_key($scope, $user_id, $request_id);

        $entry = array(
            'state'      => self::STATE_CLAIM,
            'created_at' => time(),
        );

        // 1) Object cache: wp_cache_add — атомарный в Redis/Memcached.
        // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- DEFAULT_TTL = 300 (вход $ttl у вызывающего ≥ DEFAULT_TTL).
        $cache_claimed = (bool) wp_cache_add($key, $entry, self::CACHE_GROUP, $ttl);

        if (!$cache_claimed) {
            // Уже в кеше — слот занят без необходимости fallback.
            return false;
        }

        // 2) Transient — durable backing на случай eviction кеша.
        // Если transient уже есть, значит параллельный процесс без общего
        // object cache уже занял слот — освобождаем только-что выставленную
        // cache-запись, чтобы не возникло split-brain.
        if (get_transient($key) !== false) {
            wp_cache_delete($key, self::CACHE_GROUP);
            return false;
        }

        set_transient($key, $entry, $ttl);

        return true;
    }

    /**
     * Сохранить результат обработки для идемпотентного retry.
     *
     * Вызывать непосредственно перед wp_send_json_success($result). При
     * неудачной операции (catch/rollback) — вместо store_result() вызывать
     * forget(), чтобы retry мог корректно переобработать.
     */
    public static function store_result(
        string $scope,
        int $user_id,
        string $request_id,
        array $result,
        int $ttl = self::DEFAULT_TTL
    ): void {
        if ($request_id === '') {
            return;
        }

        $key = self::build_key($scope, $user_id, $request_id);

        $entry = array(
            'state'      => self::STATE_DONE,
            'result'     => $result,
            'created_at' => time(),
        );

        // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- DEFAULT_TTL = 300 (вход $ttl у вызывающего ≥ DEFAULT_TTL).
        wp_cache_set($key, $entry, self::CACHE_GROUP, $ttl);
        set_transient($key, $entry, $ttl);
    }

    /**
     * Получить ранее сохранённый результат.
     *
     * Возвращает null если слот не был claim'лен / не был store_result'ен
     * / TTL истёк.
     */
    public static function get_stored_result(
        string $scope,
        int $user_id,
        string $request_id
    ): ?array {
        if ($request_id === '') {
            return null;
        }

        $key = self::build_key($scope, $user_id, $request_id);

        $entry = wp_cache_get($key, self::CACHE_GROUP);
        if ($entry === false || !is_array($entry)) {
            $entry = get_transient($key);
        }

        if (!is_array($entry)) {
            return null;
        }

        if (( $entry['state'] ?? '' ) !== self::STATE_DONE) {
            return null;
        }

        $result = $entry['result'] ?? null;
        return is_array($result) ? $result : null;
    }

    /**
     * Полный сброс слота. Используется в catch/rollback, чтобы retry мог
     * пройти заново.
     */
    public static function forget( string $scope, int $user_id, string $request_id ): void {
        if ($request_id === '') {
            return;
        }

        $key = self::build_key($scope, $user_id, $request_id);

        wp_cache_delete($key, self::CACHE_GROUP);
        delete_transient($key);
    }

    /**
     * Сборка ключа. sha1 даёт фиксированную длину и избегает опасных
     * символов для transient (long / charset).
     */
    private static function build_key( string $scope, int $user_id, string $request_id ): string {
        $safe_scope = preg_replace('/[^a-z0-9_]/i', '', $scope) ?? '';
        $digest     = substr(sha1($request_id), 0, 16);

        return self::KEY_PREFIX . $safe_scope . '_' . $user_id . '_' . $digest;
    }
}
