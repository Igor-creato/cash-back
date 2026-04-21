<?php

/**
 * Read-through cache-декоратор для rate-limit counter (Группа 7 ADR, шаг 4).
 *
 * Оборачивает любой Cashback_Rate_Limit_Counter_Interface (в production — SQL-counter)
 * и кеширует только DENIED-ответы на короткое TTL. При повторном запросе на тот же
 * scope_key внутри окна отказ возвращается из wp_cache_* без SQL-trip'а.
 *
 * Атомарность по-прежнему обеспечивается inner-counter'ом (SQL). Кеш не меняет
 * семантику лимита — только уменьшает нагрузку на БД при retry-спаме после первого
 * denied-ответа (типичный браузерный retry прокси / бот).
 *
 * Для ALLOWED-ответов кеш не используется: каждый allowed должен инкрементить
 * реальный счётчик, иначе лимит обойдётся.
 *
 * @package Cashback
 * @since   1.0.0 (Group 7)
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/interface-cashback-rate-limit-counter.php';

final class Cashback_Rate_Limit_Cached_Counter implements Cashback_Rate_Limit_Counter_Interface {

    public const CACHE_GROUP = 'cashback_rate_limit';
    private const KEY_PREFIX = 'cb_rl_deny_';

    private Cashback_Rate_Limit_Counter_Interface $inner;
    private int $deny_cache_ttl;

    /**
     * @param Cashback_Rate_Limit_Counter_Interface $inner           Базовый counter (SQL).
     * @param int                                   $deny_cache_ttl  Максимальное TTL кеша denied-ответов в секундах.
     *                                                               Реальный TTL = min($deny_cache_ttl, reset_at - now).
     *                                                               Default 5s — достаточно чтобы погасить бот-спам,
     *                                                               но не задержать легитимный retry пользователя.
     */
    public function __construct( Cashback_Rate_Limit_Counter_Interface $inner, int $deny_cache_ttl = 5 ) {
        if ($deny_cache_ttl < 1) {
            throw new \InvalidArgumentException('deny_cache_ttl must be >= 1');
        }

        $this->inner          = $inner;
        $this->deny_cache_ttl = $deny_cache_ttl;
    }

    public function increment( string $scope_key, int $window_seconds, int $limit ): array {
        $cache_key = self::KEY_PREFIX . substr(sha1($scope_key), 0, 32);
        $now       = time();

        // Fast-path: если недавно был denied на этот scope_key — возвращаем без SQL.
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)
            && isset($cached['reset_at'], $cached['hits'], $cached['allowed'])
            && false === $cached['allowed']
            && (int) $cached['reset_at'] > $now
        ) {
            return array(
                'hits'     => (int) $cached['hits'],
                'allowed'  => false,
                'reset_at' => (int) $cached['reset_at'],
            );
        }

        $result = $this->inner->increment($scope_key, $window_seconds, $limit);

        if (false === $result['allowed']) {
            $ttl = min($this->deny_cache_ttl, max(1, (int) $result['reset_at'] - $now));
            // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- Короткое TTL by design: кеш denied-ответов для 1–5с, чтобы погасить retry-спам после первого отказа. Никогда не должен пережить window лимита (reset_at).
            wp_cache_set($cache_key, $result, self::CACHE_GROUP, $ttl);
        }

        return $result;
    }
}
