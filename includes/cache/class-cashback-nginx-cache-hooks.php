<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Регистрация WP-хуков для инвалидации nginx fastcgi_cache.
 *
 * Покрытие:
 *  - status change (publish/draft/trash): transition_post_status
 *  - любое обновление товара: save_post_product
 *  - удаление: before_delete_post + wp_trash_post
 *  - изменение мета (ставка/название/URL): added/updated/deleted_post_meta
 *  - смена категорий/меток: set_object_terms
 *  - изменение тарифов в кастомной таблице: cashback_tariffs_changed (do_action)
 *
 * Per-request дедуп: один товар → один purge за request, даже если хуки
 * каскадно сработали 5 раз (save_post → updated_meta × N → set_object_terms × M).
 *
 * @since 4.0.0
 */
class Cashback_Nginx_Cache_Hooks {

    /** Meta-keys, изменения которых требуют инвалидации кэша. */
    private const PURGE_META_KEYS = array(
        '_cashback_display_value',
        '_cashback_display_label',
        '_manual_advertiser_rate',
        '_rate_locked',
        '_product_url',
        '_button_text',
    );

    /** Taxonomy, по которым товар попадает в archive-страницы. */
    private const PURGE_TAXONOMIES = array(
        'product_cat',
        'product_tag',
        'product_type',
    );

    /**
     * Per-request набор уже purged post_ids, для дедупа.
     *
     * @var array<int, true>
     */
    private static $purged_in_request = array();

    /** Регистрация всех хуков. */
    public static function init(): void {
        // status change (publish ↔ draft ↔ trash)
        add_action('transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 100, 3);

        // любое обновление товара
        add_action('save_post_product', array( __CLASS__, 'on_save_post_product' ), 100, 3);

        // удаление (до удаления — get_permalink ещё работает)
        add_action('before_delete_post', array( __CLASS__, 'on_before_delete_post' ), 10, 2);
        add_action('wp_trash_post', array( __CLASS__, 'on_wp_trash_post' ), 10, 1);

        // мета: ставка, название, URL
        add_action('added_post_meta', array( __CLASS__, 'on_post_meta_changed' ), 10, 4);
        add_action('updated_post_meta', array( __CLASS__, 'on_post_meta_changed' ), 10, 4);
        add_action('deleted_post_meta', array( __CLASS__, 'on_post_meta_changed' ), 10, 4);

        // категории / метки
        add_action('set_object_terms', array( __CLASS__, 'on_set_object_terms' ), 10, 6);

        // тарифы (custom DB-таблица cashback_shop_tariffs) — триггерится из shop-importer
        add_action('cashback_tariffs_changed', array( __CLASS__, 'on_tariffs_changed' ), 10, 1);
    }

    /**
     * @param string  $new_status
     * @param string  $old_status
     * @param WP_Post $post
     */
    public static function on_transition_post_status( $new_status, $old_status, $post ): void {
        if (!is_object($post) || !isset($post->post_type) || $post->post_type !== 'product') {
            return;
        }
        if ($new_status === $old_status) {
            return;
        }
        self::dispatch_purge_post((int) $post->ID, "status:{$old_status}->{$new_status}");
    }

    /**
     * @param int     $post_id
     * @param WP_Post $post
     * @param bool    $update Передаётся WP, но семантика purge'а одинакова для insert/update.
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $update диктуется сигнатурой WP-хука save_post_*.
    public static function on_save_post_product( $post_id, $post, $update ): void {
        $post_id = (int) $post_id;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (is_object($post) && isset($post->post_status) && $post->post_status === 'auto-draft') {
            return;
        }
        self::dispatch_purge_post($post_id, 'save_post');
    }

    /**
     * @param int     $post_id
     * @param WP_Post $post
     */
    public static function on_before_delete_post( $post_id, $post = null ): void {
        if (is_object($post) && isset($post->post_type) && $post->post_type !== 'product') {
            return;
        }
        if (!is_object($post) && get_post_type((int) $post_id) !== 'product') {
            return;
        }
        self::dispatch_purge_post((int) $post_id, 'before_delete');
    }

    /** @param int $post_id */
    public static function on_wp_trash_post( $post_id ): void {
        if (get_post_type((int) $post_id) !== 'product') {
            return;
        }
        self::dispatch_purge_post((int) $post_id, 'trash');
    }

    /**
     * @param int    $meta_id    Передаётся WP-хуком, не используется.
     * @param int    $object_id
     * @param string $meta_key
     * @param mixed  $meta_value Передаётся WP-хуком, не используется (нам важно сам факт изменения).
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $meta_id/$meta_value диктуются сигнатурой WP-хука *_post_meta.
    public static function on_post_meta_changed( $meta_id, $object_id, $meta_key, $meta_value ): void {
        $object_id = (int) $object_id;
        if (!in_array((string) $meta_key, self::PURGE_META_KEYS, true)) {
            return;
        }
        if (get_post_type($object_id) !== 'product') {
            return;
        }
        self::dispatch_purge_post($object_id, "meta:{$meta_key}");
    }

    /**
     * @param int    $object_id
     * @param array  $terms
     * @param array  $tt_ids
     * @param string $taxonomy
     * @param bool   $append     Передаётся WP-хуком, не используется.
     * @param array  $old_tt_ids Передаётся WP-хуком, не используется.
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $append/$old_tt_ids диктуются сигнатурой WP-хука set_object_terms.
    public static function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
        $object_id = (int) $object_id;
        if (!in_array((string) $taxonomy, self::PURGE_TAXONOMIES, true)) {
            return;
        }
        if (get_post_type($object_id) !== 'product') {
            return;
        }
        self::dispatch_purge_post($object_id, "term:{$taxonomy}");
    }

    /** @param int $post_id */
    public static function on_tariffs_changed( $post_id ): void {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }
        self::dispatch_purge_post($post_id, 'tariffs_changed');
    }

    /**
     * Точка диспетчеризации purge для одного post_id с per-request дедупом.
     */
    public static function dispatch_purge_post( int $post_id, string $reason ): void {
        if ($post_id <= 0) {
            return;
        }
        if (isset(self::$purged_in_request[ $post_id ])) {
            return;
        }
        self::$purged_in_request[ $post_id ] = true;

        try {
            Cashback_Nginx_Cache_Purger::purge_post($post_id, $reason);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic; never fail save_post.
            error_log('[Cashback Nginx Purger] dispatch failed: ' . $e->getMessage() . ' (post_id=' . $post_id . ', reason=' . $reason . ')');
        }
    }

    /** Reset для unit-тестов. */
    public static function reset_dedup_for_tests(): void {
        self::$purged_in_request = array();
    }
}
