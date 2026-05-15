<?php
/**
 * Cashback_Shop_Dup_Status_Sync — enforcement post_status для draft-модели
 * дедупа магазинов между CPA-сетями (Этап 2).
 *
 * Инвариант витрины: в группе магазинов-дублей (один домен, разные сети)
 * опубликован ТОЛЬКО preferred (лучшая ставка, см.
 * Cashback_Shop_Group_Resolver::rank_product). Остальные members уходят в
 * `draft` с маркером `_cashback_dup_demoted='1'`.
 *
 * В отличие от Cashback_Catalog_Visibility (которое лишь прячет non-preferred
 * из catalog query через мету, не трогая post_status), этот класс реально
 * меняет post_status — по явному выбору пользователя «не-лучший → черновик».
 *
 * Семантика (зеркалит Cashback_Product_Autopublish):
 *   - ДЕМОУТ (publish → draft) non-preferred — ВСЕГДА (защита «только лучший
 *     виден»), независимо от флага «Автопубликация».
 *   - ПРОМОУТ (draft → publish) preferred — только если ВСЕ условия:
 *       (a) был демоутнут нами (`_cashback_dup_demoted='1'`), т.е. это не
 *           свежий импорт и не ручной admin-черновик;
 *       (b) кампания активна (`_cashback_auto_deactivated !== '1'` —
 *           раздельно с campaign-status логикой Cashback_API_Client);
 *       (c) включён флаг «Автопубликация»
 *           (`_cashback_auto_publish_enabled='1'`).
 *     Первую публикацию свежего импорта делает админ вручную (он получает
 *     уведомление — см. render_dup_group_notice / Cashback_Shop_Dup_Admin_Notice).
 *
 * Sync-точка: action `cashback_group_preferred_changed($group_id)` (фирится
 * Group_Resolver при recompute/pin/split). Priority 15 — после
 * Cashback_Catalog_Visibility (10).
 *
 * Backfill: one-shot self-healing wp-cron (как Cashback_Catalog_Visibility),
 * НО только демоутит (allow_promote=false) — без сюрприз-публикаций на проде.
 *
 * @package CashbackPlugin
 * @since   12.4.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Shop_Dup_Status_Sync {

    /** Маркер campaign-status деактивации (раздельно с _cashback_dup_*). */
    public const META_AUTO_DEACTIVATED = '_cashback_auto_deactivated';

    public const META_DEMOTED        = '_cashback_dup_demoted';
    public const META_DEMOTED_AT     = '_cashback_dup_demoted_at';
    public const META_WINNER_NETWORK = '_cashback_dup_winner_network';
    public const META_WINNER_PRODUCT = '_cashback_dup_winner_product';

    public const BACKFILL_OPTION    = 'cashback_shop_dup_status_backfill_v1';
    public const CRON_BACKFILL_HOOK = 'cashback_shop_dup_status_backfill';

    /**
     * Reentrancy guard: наш wp_update_post фирит transition_post_status,
     * который слушают Catalog_Visibility / Autopublish / наш own clear-listener.
     * Без guard'а возможна рекурсия / двойная обработка.
     */
    private static bool $syncing = false;

    /**
     * Регистрация хуков. Идемпотентно — внутренний static guard.
     */
    public static function register(): void {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        if (! function_exists('add_action')) {
            return;
        }

        // Sync post_status при изменении preferred (priority 15 — после
        // Cashback_Catalog_Visibility=10, чтобы hide-meta уже был синкнут).
        add_action('cashback_group_preferred_changed', array( __CLASS__, 'on_group_preferred_changed' ), 15, 1);

        // Ручная публикация дубля админом → очистить наши маркеры (admin
        // override). Priority 6 — после Autopublish (5, чистит свои маркеры),
        // до Catalog_Visibility (10).
        add_action('transition_post_status', array( __CLASS__, 'on_transition_clear_dup_markers' ), 6, 3);

        // Deferred one-shot backfill (см. ensure_backfilled).
        add_action(self::CRON_BACKFILL_HOOK, array( __CLASS__, 'handle_backfill_cron' ), 10, 0);
    }

    /**
     * Listener на cashback_group_preferred_changed.
     *
     * @param mixed $group_id
     */
    public static function on_group_preferred_changed( $group_id ): void {
        self::sync_group_status((int) $group_id, true);
    }

    /**
     * Привести post_status членов группы к инварианту «опубликован только
     * preferred».
     *
     * @param int  $group_id
     * @param bool $allow_promote false → только демоут (backfill-режим).
     */
    public static function sync_group_status( int $group_id, bool $allow_promote = true ): void {
        if ($group_id <= 0 || self::$syncing) {
            return;
        }
        if (! class_exists('Cashback_Shop_Group_Resolver')) {
            return;
        }

        $group = Cashback_Shop_Group_Resolver::get_group_row($group_id);
        if ($group === null) {
            return;
        }

        // Active = все members кроме trash/auto-draft (включая draft/private).
        $active = Cashback_Shop_Group_Resolver::get_active_members($group_id);
        if (empty($active)) {
            return;
        }

        // post_status (тяжёлый, «липкий» — без авто-возврата при autopublish
        // OFF) меняем ТОЛЬКО при надёжно определённом победителе: pin или
        // scored preferred, физически присутствующий среди active members.
        // Если preferred=0 (recompute не смог — напр. гонка tariff-sync, все
        // rank=null) или указывает на не-active (stale/trashed) — НЕ трогаем
        // статусы. Демоут по fallback-догадке (pick_fallback_member) загнал
        // бы хороший товар в draft без авто-возврата. Single-shop инвариант
        // на витрине в этот момент держит Catalog_Visibility обратимой
        // hide-метой (фин-риск ниже флаппинга publish/draft).
        $pin       = isset($group['pin_product_id']) ? (int) $group['pin_product_id'] : 0;
        $preferred = isset($group['preferred_product_id']) ? (int) $group['preferred_product_id'] : 0;
        $effective = $pin > 0 ? $pin : $preferred;
        if ($effective <= 0 || ! in_array($effective, $active, true)) {
            return;
        }

        // Currently-publish members.
        $publishable = Cashback_Shop_Group_Resolver::get_publishable_members($group_id);

        self::$syncing = true;
        try {
            foreach ($active as $member_id) {
                $member_id = (int) $member_id;
                if ($member_id <= 0 || $member_id === $effective) {
                    continue;
                }
                // Демоут только реально опубликованных не-preferred.
                if (in_array($member_id, $publishable, true)) {
                    self::demote($member_id, $effective);
                }
            }

            // Промоут preferred — только если он сейчас НЕ опубликован.
            if ($allow_promote && ! in_array($effective, $publishable, true)) {
                self::maybe_promote($effective);
            }
        } finally {
            self::$syncing = false;
        }
    }

    /**
     * Effective preferred группы = pin (если задан и жив) иначе preferred,
     * валидированный против ACTIVE members; fallback pick_fallback_member.
     *
     * ВАЖНО: валидация против ACTIVE (не publishable). В draft-модели
     * preferred часто draft (его и нужно промоутить) — валидация против
     * publishable счёла бы draft-preferred невалидным и fallback выбрал бы
     * проигравшего по ставке.
     *
     * @param array<string, mixed> $group
     * @param array<int, int>      $active
     */
    private static function resolve_effective( int $group_id, array $group, array $active ): int {
        $pin       = isset($group['pin_product_id']) ? (int) $group['pin_product_id'] : 0;
        $preferred = isset($group['preferred_product_id']) ? (int) $group['preferred_product_id'] : 0;
        $effective = $pin > 0 ? $pin : $preferred;

        if ($effective <= 0 || ! in_array($effective, $active, true)) {
            $effective = Cashback_Shop_Group_Resolver::pick_fallback_member($group_id);
        }
        return $effective > 0 ? $effective : 0;
    }

    /**
     * Контекст admin-уведомления о дубле для товара (метабокс + баннер).
     *
     * Возвращает `null` если: товар не в группе ИЛИ группа из одного member
     * ИЛИ товар сам является effective preferred (ему уведомление не нужно).
     * Иначе — данные победителя по ставке.
     *
     * @return array{domain: string, winner_id: int, winner_network_id: int}|null
     */
    public static function notice_context( int $product_id ): ?array {
        if ($product_id <= 0 || ! class_exists('Cashback_Shop_Group_Resolver')) {
            return null;
        }

        $group = Cashback_Shop_Group_Resolver::get_group_for_product($product_id);
        if (! is_array($group) || empty($group['id'])) {
            return null;
        }
        $group_id = (int) $group['id'];

        $active = Cashback_Shop_Group_Resolver::get_active_members($group_id);
        if (count($active) <= 1) {
            return null; // одиночный магазин — дубля нет.
        }

        $effective = self::resolve_effective($group_id, $group, $active);
        if ($effective <= 0 || $effective === $product_id) {
            return null; // сам preferred — уведомление не нужно.
        }

        $winner_network = function_exists('get_post_meta')
            ? (int) get_post_meta($effective, '_affiliate_network_id', true)
            : 0;

        return array(
            'domain'            => (string) ( $group['domain'] ?? '' ),
            'winner_id'         => $effective,
            'winner_network_id' => $winner_network,
        );
    }

    /**
     * publish → draft + проставить маркеры дубля.
     */
    private static function demote( int $product_id, int $winner_id ): void {
        if ($product_id <= 0 || ! function_exists('wp_update_post')) {
            return;
        }

        wp_update_post(array(
            'ID'          => $product_id,
            'post_status' => 'draft',
        ));

        if (! function_exists('update_post_meta')) {
            return;
        }
        $winner_network = $winner_id > 0 && function_exists('get_post_meta')
            ? (string) get_post_meta($winner_id, '_affiliate_network_id', true)
            : '';

        update_post_meta($product_id, self::META_DEMOTED, '1');
        update_post_meta($product_id, self::META_DEMOTED_AT, self::now_mysql());
        update_post_meta($product_id, self::META_WINNER_NETWORK, $winner_network);
        update_post_meta($product_id, self::META_WINNER_PRODUCT, (string) $winner_id);
    }

    /**
     * draft → publish, если выполнены ВСЕ условия промоута (см. docblock
     * класса). Иначе — no-op (первую публикацию делает админ вручную).
     */
    private static function maybe_promote( int $product_id ): void {
        if ($product_id <= 0 || ! function_exists('get_post_meta')) {
            return;
        }

        // (a) был демоутнут нами.
        if ((string) get_post_meta($product_id, self::META_DEMOTED, true) !== '1') {
            return;
        }
        // (b) кампания активна (раздельно с campaign-status деактивацией).
        if ((string) get_post_meta($product_id, self::META_AUTO_DEACTIVATED, true) === '1') {
            return;
        }
        // (c) флаг «Автопубликация» включён.
        $flag_key = class_exists('Cashback_Product_Autopublish')
            ? Cashback_Product_Autopublish::META_KEY
            : '_cashback_auto_publish_enabled';
        if ((string) get_post_meta($product_id, $flag_key, true) !== '1') {
            return;
        }

        if (function_exists('wp_update_post')) {
            wp_update_post(array(
                'ID'          => $product_id,
                'post_status' => 'publish',
            ));
        }
        self::clear_markers($product_id);
    }

    /**
     * Listener на transition_post_status: если админ ВРУЧНУЮ опубликовал
     * демоутнутый дубль — очистить наши маркеры (admin override). Аналог
     * Cashback_Product_Autopublish::on_transition_clear_markers.
     *
     * @param string $new_status
     * @param string $old_status
     * @param mixed  $post WP_Post
     */
    public static function on_transition_clear_dup_markers( $new_status, $old_status, $post ): void {
        if ($new_status !== 'publish' || $new_status === $old_status) {
            return;
        }
        // Наш собственный maybe_promote() уже очищает маркеры — не дублируем
        // и не рекурсируем.
        if (self::$syncing) {
            return;
        }
        if (! is_object($post)) {
            return;
        }
        if ((string) ( $post->post_type ?? '' ) !== 'product') {
            return;
        }
        $post_id = (int) ( $post->ID ?? 0 );
        if ($post_id <= 0) {
            return;
        }
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
            return;
        }
        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post_id)) {
            return;
        }
        if (! function_exists('get_post_meta')
            || (string) get_post_meta($post_id, self::META_DEMOTED, true) !== '1'
        ) {
            return;
        }
        self::clear_markers($post_id);
    }

    /**
     * Удалить все 4 маркера дубля.
     */
    private static function clear_markers( int $product_id ): void {
        if ($product_id <= 0 || ! function_exists('delete_post_meta')) {
            return;
        }
        delete_post_meta($product_id, self::META_DEMOTED);
        delete_post_meta($product_id, self::META_DEMOTED_AT);
        delete_post_meta($product_id, self::META_WINNER_NETWORK);
        delete_post_meta($product_id, self::META_WINNER_PRODUCT);
    }

    /**
     * Self-healing gate для one-shot backfill (как
     * Cashback_Catalog_Visibility::ensure_backfilled). Терминал — '1'.
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
            error_log('[Cashback Shop Dup Status Sync] backfill failed: ' . $e->getMessage());
            return;
        }
        if (function_exists('update_option')) {
            update_option(self::BACKFILL_OPTION, '1', false);
        }
    }

    /**
     * One-shot backfill всех групп — ТОЛЬКО демоут (allow_promote=false).
     * Идемпотентно.
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
            self::sync_group_status($gid, false);
            ++$processed;
        }
        return $processed;
    }

    /**
     * Текущее UTC-время в MySQL-формате.
     */
    private static function now_mysql(): string {
        if (class_exists('Cashback_Time') && method_exists('Cashback_Time', 'now_mysql')) {
            return Cashback_Time::now_mysql();
        }
        if (function_exists('current_time')) {
            return (string) current_time('mysql', true);
        }
        return gmdate('Y-m-d H:i:s');
    }
}
