<?php
/**
 * Cashback_Shop_Dup_Admin_Notice — глобальный admin-баннер о магазинах-дублях
 * с менее выгодной ставкой (Этап 3b draft-модели дедупа).
 *
 * Показывает «N магазинов-дублей с менее выгодной ставкой — разобрать →» со
 * ссылкой на «Кэшбэк → Группы магазинов». Dismiss — per-user: запоминаем
 * счётчик на момент закрытия; баннер появляется снова только если счётчик
 * вырос (новые дубли импортированы).
 *
 * Счётчик кэшируется в transient (TTL 1ч); сбрасывается при изменении
 * preferred (cashback_group_preferred_changed) — тогда состояние дублей
 * меняется и кэш устаревает.
 *
 * @package CashbackPlugin
 * @since   12.4.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Shop_Dup_Admin_Notice {

    public const TRANSIENT      = 'cashback_dup_notice_count';
    public const META_DISMISSED = '_cashback_dup_notice_dismissed_count';
    public const NONCE_ACTION   = 'cashback_dup_notice_dismiss';
    public const QUERY_DISMISS  = 'cashback_dup_notice_dismiss';
    public const CAPABILITY     = 'manage_woocommerce';

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

        add_action('admin_notices', array( __CLASS__, 'maybe_render' ), 10, 0);
        add_action('admin_init', array( __CLASS__, 'handle_dismiss' ), 10, 0);
        // Состояние дублей изменилось → сбросить кэш счётчика.
        add_action('cashback_group_preferred_changed', array( __CLASS__, 'flush_cache' ), 20, 0);
    }

    /**
     * Сбросить кэш счётчика.
     */
    public static function flush_cache(): void {
        if (function_exists('delete_transient')) {
            delete_transient(self::TRANSIENT);
        }
    }

    /**
     * Количество групп-дублей где есть опубликованный НЕ-preferred member
     * (т.е. на витрине виден магазин с менее выгодной ставкой / требует
     * внимания админа). Кэшируется в transient на 1ч.
     */
    public static function count_conflicts( bool $use_cache = true ): int {
        if ($use_cache && function_exists('get_transient')) {
            $cached = get_transient(self::TRANSIENT);
            if ($cached !== false) {
                return (int) $cached;
            }
        }

        global $wpdb;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var')) {
            return 0;
        }

        // Группы с >1 active member, у которых есть publish-member, не
        // совпадающий с pin/preferred (effective preferred). Идентификаторы
        // таблиц через %i (prepare), не интерполяция.
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT g.id
                  FROM %i g
                  JOIN %i m ON m.group_id = g.id AND m.is_excluded = 0
                  JOIN %i p ON p.ID = m.product_id
                        AND p.post_type = 'product'
                        AND p.post_status NOT IN ('trash', 'auto-draft')
                 GROUP BY g.id
                HAVING COUNT(*) > 1
                   -- Только группы с РЕШЁННЫМ effective preferred. Если
                   -- pin/preferred оба NULL — победитель определяется
                   -- PHP-fallback (pick_fallback_member), и опубликованный
                   -- член может уже быть корректным storefront-товаром;
                   -- такой кейс не баннерим (false-positive, Codex HIGH-2).
                   AND COALESCE(g.pin_product_id, g.preferred_product_id, 0) > 0
                   AND SUM(
                        CASE WHEN p.post_status = 'publish'
                              AND m.product_id <> COALESCE(g.pin_product_id, g.preferred_product_id, 0)
                             THEN 1 ELSE 0 END
                       ) > 0
            ) t",
            $wpdb->prefix . 'cashback_shop_groups',
            $wpdb->prefix . 'cashback_shop_group_members',
            $wpdb->posts
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql собран через $wpdb->prepare выше; результат кэшируется в transient.
        $n = (int) $wpdb->get_var($sql);

        if (function_exists('set_transient')) {
            $ttl = defined('HOUR_IN_SECONDS') ? (int) HOUR_IN_SECONDS : 3600;
            set_transient(self::TRANSIENT, $n, $ttl);
        }
        return $n;
    }

    /**
     * Показывать ли баннер пользователю: есть конфликты И счётчик вырос с
     * момента последнего dismiss.
     */
    public static function should_display( int $user_id ): bool {
        $count = self::count_conflicts();
        if ($count <= 0) {
            return false;
        }
        $seen = function_exists('get_user_meta')
            ? (int) get_user_meta($user_id, self::META_DISMISSED, true)
            : 0;
        return $count > $seen;
    }

    /**
     * Запомнить текущий счётчик как «просмотренный» этим пользователем.
     */
    public static function dismiss_for_user( int $user_id ): void {
        if ($user_id <= 0 || ! function_exists('update_user_meta')) {
            return;
        }
        update_user_meta($user_id, self::META_DISMISSED, self::count_conflicts());
    }

    /**
     * Обработчик ссылки «закрыть» (admin_init).
     */
    public static function handle_dismiss(): void {
        if (! isset($_GET[ self::QUERY_DISMISS ])) {
            return;
        }
        $nonce = isset($_GET['_wpnonce'])
            ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce']))
            : '';
        if ($nonce === '' || ! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }
        self::dismiss_for_user(get_current_user_id());
    }

    /**
     * Рендер баннера на admin_notices.
     */
    public static function maybe_render(): void {
        if (! function_exists('current_user_can') || ! current_user_can(self::CAPABILITY)) {
            return;
        }
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user_id <= 0) {
            return;
        }

        // Один вызов count_conflicts (should_display() звал бы его повторно).
        $count = self::count_conflicts();
        if ($count <= 0) {
            return;
        }
        $seen = function_exists('get_user_meta')
            ? (int) get_user_meta($user_id, self::META_DISMISSED, true)
            : 0;
        if ($count <= $seen) {
            return;
        }

        $page_slug = class_exists('Cashback_Shop_Groups_Admin')
            ? Cashback_Shop_Groups_Admin::PAGE_SLUG
            : 'cashback-shop-groups';
        $groups_url  = function_exists('admin_url')
            ? admin_url('admin.php?page=' . $page_slug)
            : '#';
        $dismiss_url = function_exists('wp_nonce_url') && function_exists('admin_url')
            ? wp_nonce_url(
                admin_url('?' . self::QUERY_DISMISS . '=1'),
                self::NONCE_ACTION
            )
            : '';

        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo esc_html(sprintf(
            /* translators: %d: number of duplicate-store groups needing review. */
            _n(
                'Кэшбэк: %d магазин-дубль импортирован с менее выгодной ставкой, чем у конкурента.',
                'Кэшбэк: %d магазинов-дублей импортировано с менее выгодной ставкой, чем у конкурента.',
                $count,
                'cashback'
            ),
            $count
        ));
        echo ' <a href="' . esc_url($groups_url) . '">'
            . esc_html__('Разобрать →', 'cashback') . '</a>';
        if ($dismiss_url !== '') {
            echo ' <a href="' . esc_url($dismiss_url) . '" style="margin-left:8px;">'
                . esc_html__('Скрыть', 'cashback') . '</a>';
        }
        echo '</p></div>';
    }
}
