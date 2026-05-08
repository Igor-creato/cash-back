<?php
/**
 * Cashback_Catalog_Visibility — скрытие non-preferred членов групп
 * магазинов из основных catalog query на фронтенде.
 *
 * Реализует изначальное намерение из шапки Cashback_Shop_Group_Resolver:
 * на витрине показывается ОДИН WC-товар (preferred). Один и тот же магазин,
 * импортированный из разных CPA-сетей (Admitad + EPN), не должен дублироваться
 * в каталоге.
 *
 * Хранение: булевый meta `_cashback_hide_in_catalog = '1'` на non-preferred
 * членах группы. На preferred / non-grouped товарах — meta отсутствует.
 * Default state visible — backfill пишет только для скрываемых, не трогает
 * остальных.
 *
 * Sync: action `cashback_group_preferred_changed($group_id)`, который фирится
 * Group_Resolver при изменении preferred (write_preferred / pin_product).
 * Splitted product получает прямой mark_visible (он уже не в группе).
 *
 * Backfill: self-healing wp-cron pattern (см. Cashback_Product_Sort::ensure_backfilled).
 *
 * Не действует:
 *   - в админке (`is_admin()`),
 *   - на single product page (deeplinks из CPA-сессии должны работать),
 *   - на REST API (своя логика отбора магазинов).
 *
 * @package CashbackPlugin
 * @since   12.2.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Catalog_Visibility {

    public const HIDE_META_KEY      = '_cashback_hide_in_catalog';
    public const BACKFILL_OPTION    = 'cashback_catalog_visibility_backfill_v1';
    public const CRON_BACKFILL_HOOK = 'cashback_catalog_visibility_backfill';

    /**
     * Регистрация хуков. Идемпотентно — внутренний static guard.
     */
    public static function register(): void {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        if (function_exists('add_action')) {
            // pre_get_posts фильтрует основные catalog query на фронтенде.
            add_action('pre_get_posts', array( __CLASS__, 'filter_pre_get_posts' ), 20, 1);
            // Sync видимости при изменении preferred в группе.
            add_action('cashback_group_preferred_changed', array( __CLASS__, 'on_group_preferred_changed' ), 10, 1);
            // Deferred one-shot backfill (см. ensure_backfilled).
            add_action(self::CRON_BACKFILL_HOOK, array( __CLASS__, 'handle_backfill_cron' ), 10, 0);
        }
    }

    /**
     * pre_get_posts: исключает товары с `_cashback_hide_in_catalog = '1'`
     * из catalog query.
     *
     * Не действует:
     *   - в админке;
     *   - для не-product post_type;
     *   - на single product page (is_singular('product')).
     *
     * @param mixed $q Передаётся WP как WP_Query, тип ослаблен для PHPStan.
     */
    public static function filter_pre_get_posts( $q ): void {
        if (function_exists('is_admin') && is_admin()) {
            return;
        }
        if (! is_object($q) || ! method_exists($q, 'get') || ! method_exists($q, 'set')) {
            return;
        }
        $post_type = $q->get('post_type');
        if (is_array($post_type)) {
            if (! in_array('product', $post_type, true)) {
                return;
            }
        } elseif ($post_type !== 'product') {
            return;
        }
        // Single product page — не фильтруем (deeplinks из CPA-сессии).
        // ВАЖНО: is_singular() БЕЗ аргумента post_type. С post_type-аргументом
        // WP_Query требует get_queried_object(), который возвращает null
        // в pre_get_posts (объект формируется ПОСЛЕ SQL). Метод тогда всегда
        // отдавал бы false, и meta_query применялся бы к single product →
        // 0 results → 404. Без аргумента читается $this->is_singular свойство,
        // установленное в parse_query до хука.
        if (method_exists($q, 'is_singular') && $q->is_singular()) {
            return;
        }

        $existing = $q->get('meta_query');
        if (! is_array($existing)) {
            $existing = array();
        }

        $existing[] = array(
            'relation' => 'OR',
            array(
                'key'     => self::HIDE_META_KEY,
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => self::HIDE_META_KEY,
                'value'   => '1',
                'compare' => '!=',
            ),
        );

        // phpcs:ignore WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts -- Намеренно фильтруем все product-query (catalog, taxonomy, widgets) — non-preferred дубли магазина не должны появляться нигде на фронте; main_query check здесь сужал бы scope.
        $q->set('meta_query', $existing);
    }

    /**
     * Listener на cashback_group_preferred_changed.
     */
    public static function on_group_preferred_changed( $group_id ): void {
        $group_id = (int) $group_id;
        if ($group_id <= 0) {
            return;
        }
        self::sync_group($group_id);
    }

    /**
     * Перерасчёт видимости для всех members группы.
     *
     * Effective preferred = pin_product_id если задан И жив, иначе preferred_product_id.
     *
     * Семантика fallback (Codex findings #2 и adversarial split-brain закрыты):
     *   - Если effective валиден (>0 И в active members) — нормальный path.
     *   - Иначе: делегируем выбор anchor'а в Cashback_Shop_Group_Resolver::pick_fallback_member.
     *     Тот же helper использует resolve_preferred — один источник правды,
     *     поэтому каталог и страница товара показывают ОДИН и тот же member
     *     при NULL preferred (без него возникал split-brain между display
     *     calculator и catalog visibility для no-tariff групп).
     *     Сохраняет invariant «один магазин на витрине» даже когда:
     *       (a) pin/preferred stale (товар удалён, но запись в БД осталась);
     *       (b) recompute вернул 0 потому что у всех members нет тарифов
     *           (CPA-сеть soft-delete'нула все офферы).
     *     Раньше fallback показывал ВСЕХ members → дубли в каталоге.
     *
     * recompute_preferred при следующем tariff sync восстановит корректный
     * preferred, и fallback больше не сработает.
     */
    public static function sync_group( int $group_id ): void {
        if ($group_id <= 0 || ! class_exists('Cashback_Shop_Group_Resolver')) {
            return;
        }

        $group = Cashback_Shop_Group_Resolver::get_group_row($group_id);
        if ($group === null) {
            return;
        }

        // Loop по active (включая draft/private), чтобы при их будущей
        // публикации hide_meta уже был выставлен. Validation effective —
        // против publishable (см. ниже).
        $active_members = Cashback_Shop_Group_Resolver::get_active_members($group_id);
        if (empty($active_members)) {
            return;
        }

        $pin       = isset($group['pin_product_id']) ? (int) $group['pin_product_id'] : 0;
        $preferred = isset($group['preferred_product_id']) ? (int) $group['preferred_product_id'] : 0;
        $effective = $pin > 0 ? $pin : $preferred;

        // Codex Round 7 (R7-1): валидация effective идёт через PUBLISHABLE
        // members. recompute_preferred может выбрать draft/private как
        // best-by-tariffs (admin pre-publish), и preferred_product_id окажется
        // на draft. Без publishable check sync_group hide'ил бы все publish
        // (доверяя draft preferred), и WC default-фильтр post_status='publish'
        // дополнительно скрыл бы draft → guest catalog пустой.
        // Общий helper pick_fallback_member тоже publishable-first → split-brain
        // с resolve_preferred невозможен.
        $publishable = Cashback_Shop_Group_Resolver::get_publishable_members($group_id);
        if ($effective <= 0 || ! in_array($effective, $publishable, true)) {
            $effective = Cashback_Shop_Group_Resolver::pick_fallback_member($group_id);
            if ($effective <= 0) {
                return; // empty group (race с delete) — нечего синкать.
            }
        }

        foreach ($active_members as $member_id) {
            $member_id = (int) $member_id;
            if ($member_id <= 0) {
                continue;
            }
            if ($member_id === $effective) {
                self::mark_visible($member_id);
            } else {
                self::mark_hidden($member_id);
            }
        }
    }

    /**
     * Помечает товар видимым в каталоге (удаляет hide-meta).
     */
    public static function mark_visible( int $product_id ): void {
        if ($product_id <= 0 || ! function_exists('delete_post_meta')) {
            return;
        }
        delete_post_meta($product_id, self::HIDE_META_KEY);
    }

    /**
     * Помечает товар скрытым из каталога.
     */
    public static function mark_hidden( int $product_id ): void {
        if ($product_id <= 0 || ! function_exists('update_post_meta')) {
            return;
        }
        update_post_meta($product_id, self::HIDE_META_KEY, '1');
    }

    /**
     * One-shot backfill всех групп. Идемпотентно.
     *
     * @return int Количество обработанных групп.
     */
    public static function backfill_all(): int {
        if (! class_exists('Cashback_Shop_Group_Resolver')) {
            return 0;
        }
        global $wpdb;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_col')) {
            return 0;
        }

        $table = $wpdb->prefix . Cashback_Shop_Group_Resolver::TABLE_GROUPS;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot backfill, идемпотентен.
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i ORDER BY id ASC', $table));
        if (! is_array($ids)) {
            return 0;
        }

        $processed = 0;
        foreach ($ids as $gid) {
            $gid = (int) $gid;
            if ($gid <= 0) {
                continue;
            }
            self::sync_group($gid);
            ++$processed;
        }
        return $processed;
    }

    /**
     * Self-healing gate для one-shot backfill (см. Cashback_Product_Sort).
     *
     * Терминальное состояние — только '1' (handle_backfill_cron отработал).
     * Любое другое значение, включая 'scheduled', считается not-done.
     */
    public static function ensure_backfilled(): void {
        if (! function_exists('get_option') || ! function_exists('update_option')) {
            return;
        }
        if ((string) get_option(self::BACKFILL_OPTION, '') === '1') {
            return;
        }
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
            if (wp_next_scheduled(self::CRON_BACKFILL_HOOK)) {
                return;
            }
            wp_schedule_single_event(time() + 30, self::CRON_BACKFILL_HOOK);
            update_option(self::BACKFILL_OPTION, 'scheduled', false);
            return;
        }
        // Fallback (CLI / окружение без wp-cron).
        self::handle_backfill_cron();
    }

    /**
     * wp-cron handler для one-shot backfill.
     */
    public static function handle_backfill_cron(): void {
        try {
            self::backfill_all();
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback Catalog Visibility] backfill failed: ' . $e->getMessage());
            return;
        }
        if (function_exists('update_option')) {
            update_option(self::BACKFILL_OPTION, '1', false);
        }
    }
}
