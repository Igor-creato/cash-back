<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Каталог магазинов для мобильного приложения (read-only, public).
 *
 * Источник данных: WC-products + cashback_affiliate_networks (аналогично v1, но расширенный набор полей).
 *
 * Кеш:
 *  - Публичный полный список магазинов кешируется в transient `cashback_mobile_stores_cache`
 *    на {@see self::CACHE_TTL}. Идентичен по структуре инвалидаций v1-кешу (см. `init()`).
 *  - Фильтрация, сортировка, пагинация — in-memory поверх cached-списка.
 *    Для 10-20k магазинов это <5 МБ и <20ms на запрос.
 *
 * Использование:
 *   $page = Cashback_Mobile_Stores_Service::get_instance()->paginate(array(
 *       'page' => 1, 'per_page' => 20, 'search' => 'ali', 'category' => 15, 'sort' => 'popular',
 *   ));
 *
 * @since 1.1.0
 */
class Cashback_Mobile_Stores_Service {

    public const CACHE_KEY      = 'cashback_mobile_stores_cache';
    public const CATEGORIES_KEY = 'cashback_mobile_categories_cache';
    public const CACHE_TTL      = 6 * HOUR_IN_SECONDS;

    /** Версия каталога для ETag (обновляется при flush_cache). */
    public const VERSION_OPTION = 'cashback_mobile_stores_version';

    public const MAX_PER_PAGE = 50;

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function init(): void {
        $instance = self::get_instance();
        add_action('save_post_product', array( $instance, 'flush_cache' ));
        add_action('delete_post', array( $instance, 'flush_cache' ));
        add_action('woocommerce_update_product', array( $instance, 'flush_cache' ));
        add_action('edited_product_cat', array( $instance, 'flush_cache' ));
        add_action('created_product_cat', array( $instance, 'flush_cache' ));
        add_action('delete_product_cat', array( $instance, 'flush_cache' ));
    }

    private function __construct() {}

    /**
     * Сбросить кеш (для hook-ов).
     */
    public function flush_cache(): void {
        delete_transient(self::CACHE_KEY);
        delete_transient(self::CATEGORIES_KEY);
        update_option(self::VERSION_OPTION, (string) time(), false);
    }

    /**
     * Получить текущую версию каталога (используется для ETag).
     */
    public static function get_catalog_version(): string {
        $v = (string) get_option(self::VERSION_OPTION, '');
        if ('' === $v) {
            $v = (string) time();
            update_option(self::VERSION_OPTION, $v, false);
        }
        return $v;
    }

    /**
     * Получить постраничный список магазинов.
     *
     * @param array $args {
     *   @type int    $page     1-based.
     *   @type int    $per_page 1..50.
     *   @type string $search   full-text по name/domain.
     *   @type int    $category product_cat term_id.
     *   @type string $sort     'popular' (default) | 'name' | 'cashback_desc'.
     * }
     * @param int $current_user_id Для заполнения is_favorited (0 = аноним).
     * @return array {
     *   @type array  $items    Магазины текущей страницы.
     *   @type int    $total    Всего после фильтров.
     *   @type int    $page     Текущая страница.
     *   @type int    $per_page Страниц * per_page.
     *   @type int    $pages    Всего страниц.
     * }
     */
    public function paginate( array $args, int $current_user_id = 0 ): array {
        $page     = max(1, (int) ( $args['page'] ?? 1 ));
        $per_page = (int) ( $args['per_page'] ?? 20 );
        $per_page = max(1, min(self::MAX_PER_PAGE, $per_page));
        $search   = strtolower(trim((string) ( $args['search'] ?? '' )));
        $category = (int) ( $args['category'] ?? 0 );
        $sort     = (string) ( $args['sort'] ?? 'popular' );

        $all = $this->get_all_cached();

        // Фильтры.
        if ('' !== $search) {
            $all = array_values(array_filter($all, static function ( array $s ) use ( $search ): bool {
                return false !== stripos((string) $s['name'], $search)
                    || false !== stripos((string) $s['domain'], $search);
            }));
        }

        if ($category > 0) {
            $all = array_values(array_filter($all, static function ( array $s ) use ( $category ): bool {
                return in_array($category, (array) $s['category_ids'], true);
            }));
        }

        // Сортировка.
        switch ($sort) {
            case 'name':
                usort($all, static fn( $a, $b ) => strcasecmp((string) $a['name'], (string) $b['name']));
                break;
            case 'cashback_desc':
                usort($all, static fn( $a, $b ) => ( (float) $b['cashback_numeric'] <=> (float) $a['cashback_numeric'] ));
                break;
            case 'popular':
            default:
                // Popular order = базовая, приходит отсортированной при построении кеша.
                break;
        }

        $total = count($all);
        $pages = max(1, (int) ceil($total / $per_page));
        $slice = array_slice($all, ( $page - 1 ) * $per_page, $per_page);

        // Заполняем is_favorited.
        $favorite_ids = $current_user_id > 0 ? $this->fetch_favorite_ids($current_user_id) : array();
        foreach ($slice as &$row) {
            $row['is_favorited'] = in_array((int) $row['product_id'], $favorite_ids, true);
        }
        unset($row);

        return array(
            'items'    => $slice,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => $pages,
        );
    }

    /**
     * Детали магазина по product_id.
     *
     * @return array|null
     */
    public function get_by_id( int $product_id, int $current_user_id = 0 ): ?array {
        if ($product_id <= 0) {
            return null;
        }
        foreach ($this->get_all_cached() as $store) {
            if ((int) $store['product_id'] !== $product_id) {
                continue;
            }
            $store['is_favorited'] = $current_user_id > 0
                && in_array($product_id, $this->fetch_favorite_ids($current_user_id), true);
            return $store;
        }
        return null;
    }

    /**
     * Категории, в которых есть хотя бы один магазин.
     *
     * @return array<int,array{id:int,name:string,slug:string,count:int,parent:int}>
     */
    public function get_categories(): array {
        $cached = get_transient(self::CATEGORIES_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $used_ids = array();
        foreach ($this->get_all_cached() as $store) {
            foreach ((array) $store['category_ids'] as $cid) {
                $used_ids[ (int) $cid ] = true;
            }
        }

        if (empty($used_ids)) {
            set_transient(self::CATEGORIES_KEY, array(), self::CACHE_TTL);
            return array();
        }

        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'include'    => array_keys($used_ids),
            'hide_empty' => false,
        ));

        if (is_wp_error($terms) || !is_array($terms)) {
            return array();
        }

        $items = array();
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            $items[] = array(
                'id'     => (int) $term->term_id,
                'name'   => (string) $term->name,
                'slug'   => (string) $term->slug,
                'parent' => (int) $term->parent,
                'count'  => (int) $term->count,
            );
        }

        set_transient(self::CATEGORIES_KEY, $items, self::CACHE_TTL);
        return $items;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * @return array<int,array<string,mixed>>
     */
    private function get_all_cached(): array {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $fresh = $this->build_all_stores();
        set_transient(self::CACHE_KEY, $fresh, self::CACHE_TTL);
        return $fresh;
    }

    /**
     * Построить полный список магазинов из БД (1 запрос + добор меты и категорий).
     *
     * @return array<int,array<string,mixed>>
     */
    private function build_all_stores(): array {
        global $wpdb;

        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- full list query, results cached via transient.
        $products = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT p.ID, p.post_title, p.menu_order,
                        pm_domain.meta_value AS store_domain,
                        pm_label.meta_value AS cashback_label,
                        pm_value.meta_value AS cashback_value,
                        pm_popup.meta_value AS popup_mode,
                        pm_thumb.meta_value AS thumbnail_id,
                        n.name AS network_name,
                        n.slug AS network_slug
                 FROM %i p
                 INNER JOIN %i pm_net ON p.ID = pm_net.post_id AND pm_net.meta_key = %s
                 INNER JOIN %i pm_domain ON p.ID = pm_domain.post_id AND pm_domain.meta_key = %s
                 LEFT JOIN %i pm_label ON p.ID = pm_label.post_id AND pm_label.meta_key = %s
                 LEFT JOIN %i pm_value ON p.ID = pm_value.post_id AND pm_value.meta_key = %s
                 LEFT JOIN %i pm_popup ON p.ID = pm_popup.post_id AND pm_popup.meta_key = %s
                 LEFT JOIN %i pm_thumb ON p.ID = pm_thumb.post_id AND pm_thumb.meta_key = %s
                 LEFT JOIN %i n ON n.id = pm_net.meta_value AND n.is_active = 1
                 WHERE p.post_type = %s
                   AND p.post_status = %s
                   AND pm_net.meta_value > 0
                   AND pm_domain.meta_value != %s
                 ORDER BY p.menu_order ASC, p.post_title ASC',
                $wpdb->posts,
                $wpdb->postmeta,
                '_affiliate_network_id',
                $wpdb->postmeta,
                '_store_domain',
                $wpdb->postmeta,
                '_cashback_display_label',
                $wpdb->postmeta,
                '_cashback_display_value',
                $wpdb->postmeta,
                '_store_popup_mode',
                $wpdb->postmeta,
                '_thumbnail_id',
                $networks_table,
                'product',
                'publish',
                ''
            ),
            ARRAY_A
        );

        if (empty($products)) {
            return array();
        }

        // Собираем product_id → term_id карту для категорий.
        $ids = array_map('intval', array_column($products, 'ID'));
        /** @var array<int,array<int,int>> $cats_by_product */
        $cats_by_product = $this->fetch_categories_for_products($ids);

        // Предзагружаем thumbnails в attachment_id → URL map (batch-friendly).
        $thumb_ids = array_values(array_filter(array_map('intval', array_column($products, 'thumbnail_id'))));
        $thumbs    = $this->fetch_thumbnail_urls($thumb_ids);

        $stores = array();
        foreach ($products as $product) {
            $domain = (string) ( $product['store_domain'] ?? '' );
            if ('' === $domain) {
                continue;
            }
            $domain = preg_replace('#^https?://#i', '', $domain);
            $domain = preg_replace('#^www\.#i', '', (string) $domain);
            $domain = strtolower(explode('/', (string) $domain)[0]);
            if ('' === $domain) {
                continue;
            }

            $product_id   = (int) $product['ID'];
            $thumb_id     = (int) ( $product['thumbnail_id'] ?? 0 );
            $logo_url     = $thumb_id > 0 && isset($thumbs[ $thumb_id ]) ? $thumbs[ $thumb_id ] : '';
            $cb_value     = (string) ( $product['cashback_value'] ?? '' );
            $cb_numeric   = $this->extract_cashback_numeric($cb_value);
            $category_ids = $cats_by_product[ $product_id ] ?? array();

            $stores[] = array(
                'product_id'       => $product_id,
                'domain'           => $domain,
                'name'             => $product['post_title'] ?: ( $product['network_name'] ?: $domain ),
                'logo_url'         => $logo_url,
                'cashback_label'   => $product['cashback_label'] ?: __('Cashback', 'cashback'),
                'cashback_value'   => $cb_value,
                'cashback_numeric' => $cb_numeric,
                'network_slug'     => (string) ( $product['network_slug'] ?? '' ),
                'popup_mode'       => (string) ( $product['popup_mode'] ?? 'show' ),
                'category_ids'     => $category_ids,
            );
        }

        return $stores;
    }

    /**
     * @param array<int,int> $product_ids
     * @return array<int,array<int,int>> product_id → [term_id,...]
     */
    private function fetch_categories_for_products( array $product_ids ): array {
        if (empty($product_ids)) {
            return array();
        }
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $params       = array_merge(array( $wpdb->term_relationships, $wpdb->term_taxonomy, 'product_cat' ), $product_ids);

        $sql = 'SELECT tr.object_id, tt.term_id FROM %i tr
             INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tt.taxonomy = %s AND tr.object_id IN (' . $placeholders . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a fixed `%d,%d,...` list derived from count($product_ids); values still bound via prepare() splat.

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dynamic IN(...) expansion; replacements count matches $placeholders + 3 leading %i/%s args.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        $map = array();
        foreach ((array) $rows as $row) {
            $pid           = (int) $row['object_id'];
            $tid           = (int) $row['term_id'];
            $map[ $pid ]   = $map[ $pid ] ?? array();
            $map[ $pid ][] = $tid;
        }
        return $map;
    }

    /**
     * @param array<int,int> $attachment_ids
     * @return array<int,string>
     */
    private function fetch_thumbnail_urls( array $attachment_ids ): array {
        $unique = array_values(array_unique($attachment_ids));
        $out    = array();
        foreach ($unique as $aid) {
            $url = wp_get_attachment_image_url($aid, 'medium');
            if (is_string($url) && '' !== $url) {
                $out[ $aid ] = $url;
            }
        }
        return $out;
    }

    /**
     * @return array<int,int> product_ids
     */
    private function fetch_favorite_ids( int $user_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_user_favorites';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read path, per-user subset.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array(); // Таблица появится в Phase 6 — до того is_favorited всегда false.
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped read.
        $ids = $wpdb->get_col(
            $wpdb->prepare('SELECT product_id FROM %i WHERE user_id = %d', $table, $user_id)
        );
        return array_map('intval', (array) $ids);
    }

    /**
     * Из строки вида "up to 5%", "до 10%", "388р.", "2.5%" извлечь числовое значение для сортировки.
     * При неудаче — 0.
     */
    private function extract_cashback_numeric( string $value ): float {
        if ('' === $value) {
            return 0.0;
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)/', $value, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }
        return 0.0;
    }
}
