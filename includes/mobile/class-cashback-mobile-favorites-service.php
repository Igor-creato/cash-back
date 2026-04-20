<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Избранные магазины пользователя.
 *
 * Операции:
 *   - add(user_id, product_id)    — идемпотентно (через ON DUPLICATE KEY).
 *   - remove(user_id, product_id).
 *   - toggle(user_id, product_id) — для удобства UI (возвращает новое состояние).
 *   - paginate(user_id, page, per_page) — полные карточки магазинов через stores-service кеш.
 *
 * Валидация product_id: должен быть активный post type=product, status=publish, с
 * заполненным `_store_domain` — чтобы нельзя было «избранить» произвольный post_id.
 *
 * @since 1.1.0
 */
class Cashback_Mobile_Favorites_Service {

    public const MAX_PER_PAGE = 50;

    /**
     * @return array|WP_Error ['added' => bool, 'product_id' => int]
     */
    public static function add( int $user_id, int $product_id ) {
        if ($user_id <= 0 || $product_id <= 0) {
            return new WP_Error('rest_invalid_params', __('Invalid parameters.', 'cashback'), array( 'status' => 400 ));
        }
        if (!self::is_valid_store($product_id)) {
            return new WP_Error('store_not_found', __('Store not found.', 'cashback'), array( 'status' => 404 ));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_favorites';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-owned write.
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT 1 FROM %i WHERE user_id = %d AND product_id = %d',
                $table,
                $user_id,
                $product_id
            )
        );

        if ($existing) {
            return array(
				'added'          => false,
				'already_exists' => true,
				'product_id'     => $product_id,
			);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-owned write.
        $ok = $wpdb->insert(
            $table,
            array(
                'user_id'    => $user_id,
                'product_id' => $product_id,
                'created_at' => current_time('mysql', true),
            ),
            array( '%d', '%d', '%s' )
        );

        if (false === $ok) {
            // Гонка с другой вкладкой — PK коллизия, считаем success.
            return array(
				'added'          => false,
				'already_exists' => true,
				'product_id'     => $product_id,
			);
        }

        do_action('cashback_mobile_favorite_added', $user_id, $product_id);

        return array(
			'added'      => true,
			'product_id' => $product_id,
		);
    }

    /**
     * @return array ['removed' => bool]
     */
    public static function remove( int $user_id, int $product_id ): array {
        if ($user_id <= 0 || $product_id <= 0) {
            return array( 'removed' => false );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_favorites';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-owned delete.
        $n = $wpdb->delete(
            $table,
            array(
                'user_id'    => $user_id,
                'product_id' => $product_id,
            ),
            array( '%d', '%d' )
        );

        if ($n > 0) {
            do_action('cashback_mobile_favorite_removed', $user_id, $product_id);
        }

        return array(
			'removed'    => ( (int) $n ) > 0,
			'product_id' => $product_id,
		);
    }

    /**
     * Пагинация избранного — возвращает полные карточки магазинов.
     *
     * @return array {items, total, page, per_page, pages}
     */
    public static function paginate( int $user_id, int $page, int $per_page ): array {
        if ($user_id <= 0) {
            throw new InvalidArgumentException('paginate: user_id must be > 0');
        }
        $page     = max(1, $page);
        $per_page = max(1, min(self::MAX_PER_PAGE, $per_page));
        $offset   = ( $page - 1 ) * $per_page;

        global $wpdb;
        $table = $wpdb->prefix . 'cashback_user_favorites';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped read.
        $total = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE user_id = %d', $table, $user_id)
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- user-scoped read.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT product_id, created_at FROM %i
                 WHERE user_id = %d
                 ORDER BY created_at DESC, product_id DESC
                 LIMIT %d OFFSET %d',
                $table,
                $user_id,
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        $items = array();
        if (!empty($rows) && class_exists('Cashback_Mobile_Stores_Service')) {
            $stores_svc = Cashback_Mobile_Stores_Service::get_instance();
            foreach ($rows as $row) {
                $pid   = (int) $row['product_id'];
                $store = $stores_svc->get_by_id($pid, $user_id);
                if (null === $store) {
                    continue; // магазин снят с публикации — пропускаем (FK CASCADE удалит при следующей синхронизации).
                }
                $store['favorited_at'] = (string) $row['created_at'];
                $items[]               = $store;
            }
        }

        return array(
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => max(1, (int) ceil($total / $per_page)),
        );
    }

    /**
     * Проверить, что product_id — валидный магазин (публикация + наличие _store_domain).
     */
    private static function is_valid_store( int $product_id ): bool {
        if (class_exists('Cashback_Mobile_Stores_Service')) {
            $store = Cashback_Mobile_Stores_Service::get_instance()->get_by_id($product_id);
            if (null !== $store) {
                return true;
            }
        }

        // Fallback, если кеш холодный.
        $post = get_post($product_id);
        if (!$post instanceof WP_Post) {
            return false;
        }
        if ('product' !== $post->post_type || 'publish' !== $post->post_status) {
            return false;
        }
        $domain = (string) get_post_meta($product_id, '_store_domain', true);
        return '' !== $domain;
    }
}
