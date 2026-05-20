<?php
/**
 * Cashback_Cashback_Display_Calculator — динамический расчёт строки кэшбэка
 * для карточки товара (v12, Этап 7).
 *
 * Заменяет статическое метаполе `_cashback_display_value` на формулу
 * `payment_size × user_rate / 100` (или `× guest_rate / 100` для гостей).
 * Результат: «6,5%», «65 ₽», «до X%», «до X ₽» в зависимости от тарифов.
 *
 * Поток рендера:
 *   1. preferred_id = Cashback_Shop_Group_Resolver::resolve_preferred() —
 *      если product входит в группу, показываем цифру preferred-магазина.
 *   2. Cache lookup (object cache → transient fallback), TTL 12h.
 *   3. Manual override: если на preferred_id есть _rate_locked=1, рендерим
 *      значение из _manual_advertiser_rate без расчёта.
 *   4. tariffs = Cashback_Shop_Tariff_Sync::get_active(); если пусто —
 *      legacy fallback на _cashback_display_value (для не-импортированных
 *      товаров).
 *   5. effective_rate = user_profile.cashback_rate (logged in) или
 *      cashback_guest_display_rate (гость).
 *   6. Classify: single/multi PERCENT/FIX → format «X%»/«до X ₽».
 *
 * Cache busting:
 *   - bust_cache_for_product() — после tariff sync;
 *   - bump_rate_version() через Cashback_Shop_Options — lazy invalidation
 *     всех кешей сразу (rate_version в ключе).
 *
 * @package CashbackPlugin
 * @since   12.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Cashback_Display_Calculator {

    public const CACHE_GROUP   = 'cashback_display';
    public const CACHE_PREFIX  = 'cb_disp';
    public const FILTER_ENABLE = 'cashback_use_dynamic_display';

    /**
     * Главная точка входа: HTML-строка кэшбэка для карточки товара.
     *
     * Используется как оборачивающая логика поверх legacy
     * WC_Affiliate_URL_Params::get_cashback_html() — в случае пустых
     * тарифов / отключенного feature-flag возвращаем legacy-значение
     * через filter `cashback_display_legacy_fallback`.
     *
     * @param int    $product_id  WC product_id.
     * @param string $context     'loop' | 'single' | 'shortcode' (для логирования).
     * @param bool   $standalone  true если рендер шорткодом — без price-обёртки.
     * @return string HTML или '' если не удалось рассчитать.
     */
    public static function render( int $product_id, string $context = 'loop', bool $standalone = false ): string {
        if ($product_id <= 0) {
            return '';
        }

        // Feature-flag для отката (default true). Внешний фильтр может вернуть
        // false → legacy fallback.
        if (function_exists('apply_filters') && ! apply_filters(self::FILTER_ENABLE, true, $product_id, $context)) {
            return self::legacy_fallback($product_id, $context, $standalone);
        }

        $preferred_id = self::resolve_preferred($product_id);
        if ($preferred_id <= 0) {
            return self::legacy_fallback($product_id, $context, $standalone);
        }

        $user_id   = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $cache_key = self::cache_key($preferred_id, $user_id);

        // Cache lookup.
        $cached = self::cache_get($cache_key);
        if (is_string($cached)) {
            return $cached;
        }

        $html = self::render_uncached($preferred_id, $user_id, $context, $standalone);

        if ($html === '') {
            // Codex Round 6: legacy fallback идёт по anchor'у ($preferred_id),
            // не по исходному $product_id. Иначе catalog (показывает anchor)
            // и direct link на скрытого члена группы рендерят разные
            // _cashback_display_value → split-brain в legacy-метке.
            // Pre-existing комментарий «preferred тарифы ещё не подтянулись»
            // был валиден до Round 1, когда pick_fallback_member ещё не
            // выбирал anchor с предпочтением members с usable display data.
            $html = self::legacy_fallback($preferred_id, $context, $standalone);
        }

        self::cache_set($cache_key, $html);
        return $html;
    }

    /**
     * Рассчитать display value (без HTML-обёртки).
     *
     * Возвращает массив-описание с типом и значением; пустой массив если
     * расчёт невозможен (нет тарифов, нет offer_id и т.д.).
     *
     * @return array{
     *     type: 'percent'|'fix'|'manual',
     *     is_multi: bool,
     *     value: float,
     *     formatted: string,
     *     currency: string
     * }|array{}
     */
    public static function compute( int $product_id, ?int $user_id = null ): array {
        if ($product_id <= 0) {
            return array();
        }
        if ($user_id === null) {
            $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        }

        // Manual override (если задан _manual_advertiser_rate + _rate_locked=1).
        $manual = self::compute_manual_override($product_id);
        if ($manual !== null) {
            return $manual;
        }

        $network_id = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        $offer_id   = (string) get_post_meta($product_id, '_offer_id', true);
        if ($network_id <= 0 || $offer_id === '' || ! class_exists('Cashback_Shop_Tariff_Sync')) {
            return array();
        }

        $tariffs = Cashback_Shop_Tariff_Sync::get_active($network_id, $offer_id);
        if (empty($tariffs)) {
            return array();
        }

        $effective_rate = self::effective_rate($user_id);

        return self::compute_from_tariffs($tariffs, $effective_rate);
    }

    /**
     * Числовое значение кэшбэка для сортировки каталога.
     *
     * Возвращает «ожидаемый» процент кэшбэка для гостя — guest-rate, домноженный
     * на approval_factor (доля заказов, которые CPA-сеть подтверждает). Это
     * sort-ключ для `_cashback_sort_value` (см. Cashback_Product_Sort).
     *
     * Формула: payment_size_max × guest_rate × approval_factor / 100.
     * UI-карточка по-прежнему показывает guest-rate без approval — пользователь
     * видит «6%», но сортируется витрина по «ожидаемому 3,96%» (= 6 × 0.66).
     *
     * Свойство монотонности относительно guest_rate сохраняется (все товары
     * получают тот же множитель), поэтому для сортировки достаточно гостевого
     * варианта; approval_factor различается по товарам и даёт вторичный сигнал.
     *
     * Возвращает 0.0 если расчёт невозможен — caller (Cashback_Product_Sort)
     * сам падает в legacy-fallback по `_cashback_display_value`.
     */
    public static function compute_sort_value( int $product_id ): float {
        if ($product_id <= 0) {
            return 0.0;
        }

        $compute = self::compute($product_id, 0);
        if (empty($compute)) {
            return 0.0;
        }

        $type      = isset($compute['type']) ? (string) $compute['type'] : '';
        $value     = isset($compute['value']) ? (float) $compute['value'] : 0.0;
        $formatted = isset($compute['formatted']) ? (string) $compute['formatted'] : '';

        $base = 0.0;
        if ($type === 'manual') {
            // Manual override приходит как строка ("12.5%" или "65 ₽") —
            // парсим число тем же helper, что и legacy fallback.
            if ($formatted !== '' && class_exists('Cashback_Product_Sort')) {
                $base = Cashback_Product_Sort::parse_legacy_value($formatted);
            }
        } elseif ($value > 0.0) {
            $base = $value;
        }

        if ($base <= 0.0) {
            return 0.0;
        }

        return $base * self::get_approval_factor($product_id);
    }

    /**
     * Approval factor [0..1] для сортировки. Свежий `_cashback_rate_of_approve`
     * (≤ stale_after_days) → rate/100; иначе neutral prior (default 70%). Floor
     * применяется к итогу.
     *
     * Filterable:
     *   - cashback_sort_approval_prior            (default 70.0) — подставляется при missing/stale.
     *   - cashback_sort_approval_floor            (default 0.0)  — мин. effective approval %.
     *   - cashback_sort_approval_stale_after_days (default 7)    — окно свежести meta.
     */
    public static function get_approval_factor( int $product_id ): float {
        if ($product_id <= 0) {
            return 1.0;
        }

        $prior = 70.0;
        $floor = 0.0;
        $stale = 7;
        if (function_exists('apply_filters')) {
            $prior = (float) apply_filters('cashback_sort_approval_prior', 70.0);
            $floor = (float) apply_filters('cashback_sort_approval_floor', 0.0);
            $stale = (int) apply_filters('cashback_sort_approval_stale_after_days', 7);
        }
        $prior = max(0.0, min(100.0, $prior));
        $floor = max(0.0, min(100.0, $floor));
        $stale = max(1, $stale);

        $meta_rate_key       = class_exists('Cashback_Shop_Rate_Of_Approve_Refresher')
            ? Cashback_Shop_Rate_Of_Approve_Refresher::META_RATE
            : '_cashback_rate_of_approve';
        $meta_fetched_at_key = class_exists('Cashback_Shop_Rate_Of_Approve_Refresher')
            ? Cashback_Shop_Rate_Of_Approve_Refresher::META_FETCHED_AT
            : '_cashback_rate_of_approve_fetched_at';

        $rate_raw       = function_exists('get_post_meta') ? get_post_meta($product_id, $meta_rate_key, true) : '';
        $fetched_at_raw = function_exists('get_post_meta') ? get_post_meta($product_id, $meta_fetched_at_key, true) : '';

        $has_value = is_string($rate_raw) ? $rate_raw !== '' : ($rate_raw !== null && $rate_raw !== false);

        if (! $has_value || ! is_numeric($rate_raw)) {
            return max($prior, $floor) / 100.0;
        }

        $fetched_at    = is_numeric($fetched_at_raw) ? (int) $fetched_at_raw : 0;
        $stale_seconds = $stale * (defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400);
        if ($fetched_at > 0 && (time() - $fetched_at) > $stale_seconds) {
            return max($prior, $floor) / 100.0;
        }

        $rate = max(0.0, min(100.0, (float) $rate_raw));
        return max($rate, $floor) / 100.0;
    }

    /**
     * Сбросить кеш для одного product_id (вызывается после tariff sync,
     * после смены _rate_locked / _manual_advertiser_rate).
     *
     * Так как ключ зависит от user_id, точечный сброс возможен только
     * для guest-варианта; для авторизованных юзеров используем bump
     * rate_version (см. Cashback_Shop_Options::bump_display_rate_version).
     */
    public static function bust_cache_for_product( int $product_id ): void {
        if ($product_id <= 0) {
            return;
        }
        // Прямой сброс гостевого кеша (наиболее частый кейс на витрине).
        $guest_key = self::cache_key($product_id, 0);
        self::cache_delete($guest_key);

        // Per-user сбросы — через bump rate_version (lazy invalidation).
        if (class_exists('Cashback_Shop_Options')) {
            Cashback_Shop_Options::bump_display_rate_version();
        }
    }

    /**
     * Резолвер preferred-product для рендера. Делегирует Group_Resolver.
     */
    private static function resolve_preferred( int $product_id ): int {
        if (! class_exists('Cashback_Shop_Group_Resolver')) {
            return $product_id;
        }
        $preferred = Cashback_Shop_Group_Resolver::resolve_preferred($product_id);
        return $preferred > 0 ? $preferred : $product_id;
    }

    /**
     * Manual override: если у product задан _manual_advertiser_rate +
     * _rate_locked=1, рендерим как есть БЕЗ умножения на user_rate.
     */
    private static function compute_manual_override( int $product_id ): ?array {
        $locked = (string) get_post_meta($product_id, '_rate_locked', true);
        if ($locked !== '1') {
            return null;
        }
        $manual = (string) get_post_meta($product_id, '_manual_advertiser_rate', true);
        if ($manual === '') {
            return null;
        }

        // Допускаем формат "12.5%" или "65 RUB" / "65 ₽" — рендерим как есть.
        return array(
            'type'      => 'manual',
            'is_multi'  => false,
            'value'     => 0.0,
            'formatted' => trim($manual),
            'currency'  => '',
        );
    }

    /**
     * effective_rate: ставка авторизованного юзера (cashback_user_profile)
     * либо guest_display_rate (default 60.0).
     */
    public static function effective_rate( int $user_id ): float {
        if ($user_id > 0) {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb)) {
                $user_rate = $wpdb->get_var($wpdb->prepare(
                    'SELECT cashback_rate FROM %i WHERE user_id = %d LIMIT 1',
                    $wpdb->prefix . 'cashback_user_profile',
                    $user_id
                ));
                if ($user_rate !== null && is_numeric($user_rate)) {
                    return max(0.0, min(100.0, (float) $user_rate));
                }
            }
        }
        if (class_exists('Cashback_Shop_Options')) {
            return Cashback_Shop_Options::get_guest_display_rate();
        }
        return 60.0;
    }

    /**
     * Расчёт по массиву тарифов с учётом effective_rate.
     *
     * @param array<int, array<string, mixed>> $tariffs Активные tariff rows.
     * @return array<string, mixed>
     */
    public static function compute_from_tariffs( array $tariffs, float $effective_rate ): array {
        // Группируем по типу (percent / fix). Mixed: берём только percent
        // (FIX-разные суммы / валюты сравнить корректно нельзя без знания
        // цены товара).
        $percent_set = array();
        $fix_set     = array();
        foreach ($tariffs as $row) {
            $type = isset($row['tariff_type']) ? (string) $row['tariff_type'] : '';
            if ($type === 'percent') {
                $percent_set[] = $row;
            } elseif ($type === 'fix') {
                $fix_set[] = $row;
            }
        }

        // Mixed → берём только percent (по плану).
        if (! empty($percent_set) && ! empty($fix_set)) {
            $fix_set = array();
        }

        if (! empty($percent_set)) {
            return self::format_percent_set($percent_set, $effective_rate);
        }
        if (! empty($fix_set)) {
            return self::format_fix_set($fix_set, $effective_rate);
        }
        return array();
    }

    /**
     * @param array<int, array<string, mixed>> $set
     */
    private static function format_percent_set( array $set, float $rate ): array {
        $is_multi = count($set) > 1;
        $max      = self::max_payment_size($set);
        $value    = round($max * $rate / 100, 2);
        $prefix   = $is_multi ? 'до ' : '';

        return array(
            'type'      => 'percent',
            'is_multi'  => $is_multi,
            'value'     => $value,
            'formatted' => $prefix . self::format_percent_value($value),
            'currency'  => '',
        );
    }

    /**
     * @param array<int, array<string, mixed>> $set
     */
    private static function format_fix_set( array $set, float $rate ): array {
        $is_multi = count($set) > 1;
        $max      = self::max_payment_size($set);
        $value    = round($max * $rate / 100, 0);

        // Currency: берём с tariff'а с max payment_size (single — единственный).
        $currency = 'RUB';
        $best     = -1.0;
        foreach ($set as $row) {
            $size = isset($row['payment_size']) ? (float) $row['payment_size'] : 0.0;
            if ($size > $best) {
                $best     = $size;
                $currency = isset($row['currency']) ? strtoupper((string) $row['currency']) : 'RUB';
            }
        }

        $prefix   = $is_multi ? 'до ' : '';
        $symbol   = self::currency_symbol($currency);
        $formatted = $prefix . self::format_int_value($value) . ' ' . $symbol;

        return array(
            'type'      => 'fix',
            'is_multi'  => $is_multi,
            'value'     => $value,
            'formatted' => $formatted,
            'currency'  => $currency,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $set
     */
    private static function max_payment_size( array $set ): float {
        $max = 0.0;
        foreach ($set as $row) {
            $size = isset($row['payment_size']) ? (float) $row['payment_size'] : 0.0;
            if ($size > $max) {
                $max = $size;
            }
        }
        return $max;
    }

    /**
     * «5.50%» / «5%» — без trailing нулей в дробной части.
     */
    private static function format_percent_value( float $value ): string {
        if ((float) (int) $value === $value) {
            return ((int) $value) . '%';
        }
        // 2 знака, убираем trailing zero и точку если осталась без дроби.
        $str = number_format($value, 2, '.', '');
        $str = rtrim(rtrim($str, '0'), '.');
        return $str . '%';
    }

    private static function format_int_value( float $value ): string {
        return (string) (int) round($value);
    }

    private static function currency_symbol( string $currency ): string {
        switch (strtoupper($currency)) {
            case 'RUB':
                return '₽';
            case 'USD':
                return '$';
            case 'EUR':
                return '€';
            case 'BYN':
                return 'Br';
            case 'KZT':
                return '₸';
            default:
                return $currency;
        }
    }

    /**
     * Финальный HTML-рендер (вызывается из render() после resolve+cache miss).
     *
     * $context и $standalone сохраняют интерфейсную совместимость с
     * legacy WC_Affiliate_URL_Params::get_cashback_html() — добавляются
     * как CSS-классы для темы.
     */
    private static function render_uncached( int $preferred_id, int $user_id, string $context, bool $standalone ): string {
        $compute = self::compute($preferred_id, $user_id);
        if (empty($compute)) {
            return '';
        }
        $formatted = $compute['formatted'] ?? '';
        if ($formatted === '') {
            return '';
        }

        $classes = 'cashback-display cashback-display--dynamic';
        if (in_array($context, array( 'loop', 'single', 'shortcode' ), true)) {
            $classes .= ' cashback-display--' . $context;
        }
        if ($standalone) {
            $classes .= ' cashback-display--standalone';
        }

        // Метка из post_meta preferred_id (значение, которое реально показано
        // на карточке). Fallback "Кэшбэк" — единая семантика с legacy
        // get_cashback_html / render_cashback_html / legacy_fallback.
        $label = (string) get_post_meta($preferred_id, '_cashback_display_label', true);
        if ($label === '') {
            $label = 'Кэшбэк';
        }

        $esc_html = function_exists('esc_html')
            ? 'esc_html'
            : static fn( string $s ): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $cls = function_exists('esc_attr') ? esc_attr($classes) : htmlspecialchars($classes, ENT_QUOTES, 'UTF-8');

        return '<span class="' . $cls . '">'
            . '<span class="cashback-display__label">' . $esc_html($label) . '</span> '
            . '<span class="cashback-display__value">' . $esc_html($formatted) . '</span>'
            . '</span>';
    }

    /**
     * Legacy fallback на старое статическое _cashback_display_value
     * для не-импортированных товаров. Применяем фильтр
     * `cashback_display_legacy_fallback` чтобы тема могла override-ить.
     */
    private static function legacy_fallback( int $product_id, string $context, bool $standalone ): string {
        $value = (string) get_post_meta($product_id, '_cashback_display_value', true);
        if ($value === '') {
            return '';
        }
        $label = (string) get_post_meta($product_id, '_cashback_display_label', true);
        if ($label === '') {
            $label = 'Кэшбэк';
        }
        $inner = function_exists('esc_html')
            ? esc_html($label) . ': ' . esc_html($value)
            : htmlspecialchars($label . ': ' . $value, ENT_QUOTES, 'UTF-8');
        $html  = '<span class="cashback-display cashback-display--legacy">' . $inner . '</span>';

        if (function_exists('apply_filters')) {
            $html = (string) apply_filters('cashback_display_legacy_fallback', $html, $product_id, $context, $standalone);
        }
        return $html;
    }

    /**
     * Cache key — учитывает rate_version (lazy invalidation), preferred_id,
     * user или 'g' для гостя.
     */
    private static function cache_key( int $preferred_id, int $user_id ): string {
        $version = class_exists('Cashback_Shop_Options')
            ? Cashback_Shop_Options::get_display_rate_version()
            : 1;
        $bucket  = $user_id > 0 ? ('u' . $user_id) : 'g';
        return self::CACHE_PREFIX . ':v' . $version . ':' . $preferred_id . ':' . $bucket;
    }

    private static function cache_get( string $key ): mixed {
        if (function_exists('wp_cache_get')) {
            $found  = false;
            $cached = wp_cache_get($key, self::CACHE_GROUP, false, $found);
            if ($found && is_string($cached)) {
                return $cached;
            }
        }
        if (function_exists('get_transient')) {
            $t = get_transient($key);
            if (is_string($t)) {
                return $t;
            }
        }
        return null;
    }

    private static function cache_set( string $key, string $html ): void {
        // TTL clamp в Cashback_Shop_Options::get_display_cache_ttl() — [60, 86400].
        $ttl = class_exists('Cashback_Shop_Options')
            ? Cashback_Shop_Options::get_display_cache_ttl()
            : 43200;
        if (function_exists('wp_cache_set')) {
            // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- $ttl clamped в Cashback_Shop_Options::get_display_cache_ttl() в диапазоне [60, 86400].
            wp_cache_set($key, $html, self::CACHE_GROUP, $ttl);
        }
        if (function_exists('set_transient')) {
            set_transient($key, $html, $ttl);
        }
    }

    private static function cache_delete( string $key ): void {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($key, self::CACHE_GROUP);
        }
        if (function_exists('delete_transient')) {
            delete_transient($key);
        }
    }
}
