<?php
/**
 * Фасад WP-опций для функционала Shop Importer + Dynamic Display (v12).
 *
 * Централизует имена опций (constants) и typed getter'ы с sanitize/clamp.
 * Любая запись в эти опции через update_option() запускает соответствующий
 * хук — для cache invalidation см. setting-up в Cashback_Cashback_Display_Calculator.
 *
 * @package CashbackPlugin
 * @since   12.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Shop_Options {

    /**
     * Гостевая ставка кэшбэка для отображения на витрине неавторизованным
     * пользователям (диапазон 0..100, default 60.0).
     */
    public const OPT_GUEST_DISPLAY_RATE = 'cashback_guest_display_rate';

    /**
     * TTL кеша динамического display (сек, default 43200 = 12 часов).
     */
    public const OPT_DISPLAY_CACHE_TTL = 'cashback_display_cache_ttl';

    /**
     * Размер страницы при импорте кампаний (default 100).
     */
    public const OPT_IMPORT_BATCH_SIZE = 'cashback_shop_import_batch_size';

    /**
     * Пауза между HTTP-запросами на детали кампании (миллисекунды, default 200).
     */
    public const OPT_IMPORT_THROTTLE_MS = 'cashback_shop_import_throttle_ms';

    /**
     * Версия rate-конфигурации. Bump-ится при изменении любой ставки
     * (guest/per-user) — используется как часть cache key для lazy invalidation
     * без точечной чистки кеша по ключам.
     */
    public const OPT_DISPLAY_RATE_VERSION = 'cashback_display_rate_version';

    /**
     * Default value для OPT_GUEST_DISPLAY_RATE.
     */
    public const DEFAULT_GUEST_RATE = 60.0;

    /**
     * Default value для OPT_DISPLAY_CACHE_TTL (12 часов в секундах).
     */
    public const DEFAULT_CACHE_TTL = 43200;

    /**
     * Default value для OPT_IMPORT_BATCH_SIZE.
     */
    public const DEFAULT_BATCH_SIZE = 100;

    /**
     * Safe default для Advcake import.
     *
     * На проде Advcake отдаёт страницу 100 офферов с inline bids/images; один
     * PHP-FPM worker раздувался до cgroup OOM до завершения Action Scheduler
     * action. Держим Advcake меньше общего batch, остальные сети не трогаем.
     */
    public const DEFAULT_ADVCAKE_BATCH_SIZE = 20;

    /**
     * Default value для OPT_IMPORT_THROTTLE_MS.
     */
    public const DEFAULT_THROTTLE_MS = 200;

    /**
     * Получить гостевую ставку кэшбэка для отображения.
     *
     * Clamp в диапазоне 0..100, fallback на DEFAULT_GUEST_RATE если значение
     * не задано. Возвращает float — формула расчёта display требует
     * `payment_size × rate / 100`.
     */
    public static function get_guest_display_rate(): float {
        $raw   = get_option(self::OPT_GUEST_DISPLAY_RATE, self::DEFAULT_GUEST_RATE);
        $value = is_numeric($raw) ? (float) $raw : self::DEFAULT_GUEST_RATE;
        return max(0.0, min(100.0, $value));
    }

    /**
     * TTL кеша рендера в секундах.
     *
     * Clamp в [60, 86400] (1 минута .. 24 часа) — слишком короткий TTL
     * перегружает БД на читающих страницах, слишком длинный задерживает
     * применение изменений ставок.
     */
    public static function get_display_cache_ttl(): int {
        $raw   = get_option(self::OPT_DISPLAY_CACHE_TTL, self::DEFAULT_CACHE_TTL);
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_CACHE_TTL;
        return max(60, min(86400, $value));
    }

    /**
     * Размер batch при импорте кампаний.
     *
     * Clamp в [10, 500] — Admitad/EPN limit на /advcampaigns/?limit обычно
     * 500; меньше 10 даёт слишком много AS-action'ов.
     */
    public static function get_import_batch_size(): int {
        $raw   = get_option(self::OPT_IMPORT_BATCH_SIZE, self::DEFAULT_BATCH_SIZE);
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_BATCH_SIZE;
        return max(10, min(500, $value));
    }

    /**
     * Размер batch с учётом конкретной CPA-сети.
     *
     * Общая опция остаётся глобальной, но Advcake дополнительно clamp'ится
     * до 20, чтобы старое значение 100 не могло снова привести к OOM.
     *
     * @param array<string, mixed> $network Row из cashback_affiliate_networks.
     */
    public static function get_import_batch_size_for_network( array $network ): int {
        $slug = strtolower((string) ( $network['slug'] ?? '' ));
        if ($slug !== 'advcake') {
            return self::get_import_batch_size();
        }

        $raw = get_option(self::OPT_IMPORT_BATCH_SIZE, null);
        if ($raw === null || $raw === false || $raw === '') {
            return self::DEFAULT_ADVCAKE_BATCH_SIZE;
        }

        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_ADVCAKE_BATCH_SIZE;
        return max(10, min(self::DEFAULT_ADVCAKE_BATCH_SIZE, $value));
    }

    /**
     * Throttle между HTTP-запросами при импорте (миллисекунды).
     *
     * Clamp в [0, 5000].
     */
    public static function get_import_throttle_ms(): int {
        $raw   = get_option(self::OPT_IMPORT_THROTTLE_MS, self::DEFAULT_THROTTLE_MS);
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_THROTTLE_MS;
        return max(0, min(5000, $value));
    }

    /**
     * Текущая версия rate-конфигурации (для cache key).
     *
     * Возвращает 1 если опция не задана. Не bumps — только read.
     */
    public static function get_display_rate_version(): int {
        $raw = get_option(self::OPT_DISPLAY_RATE_VERSION, 1);
        return is_numeric($raw) ? max(1, (int) $raw) : 1;
    }

    /**
     * Инкремент версии rate-конфига → лениво инвалидирует все cached display.
     *
     * Вызывается при:
     *   - изменении cashback_guest_display_rate;
     *   - изменении cashback_user_profile.cashback_rate какого-либо юзера;
     *   - изменении ставок в shop_tariffs (дополнительно к bust per-product).
     *
     * Возвращает новое значение для логирования.
     */
    public static function bump_display_rate_version(): int {
        $current = self::get_display_rate_version();
        $next    = $current + 1;
        update_option(self::OPT_DISPLAY_RATE_VERSION, $next, false);
        return $next;
    }
}
