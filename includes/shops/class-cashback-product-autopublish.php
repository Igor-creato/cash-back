<?php
/**
 * Cashback_Product_Autopublish — per-product переключатель автоматической
 * публикации в Publish-метабоксе редактора товара.
 *
 * Семантика:
 *   - ON  → check_campaign_statuses может авто-снять с публикации И авто-вернуть
 *           на витрину при возврате кампании в active.
 *   - OFF → может только авто-снять с публикации (защита от показа неработающих
 *           магазинов сохраняется); вернуть на витрину может только админ.
 *
 * Хранение: булевый meta `_cashback_auto_publish_enabled = '1'` или отсутствие
 * meta. Default state — OFF (свежий импорт через Cashback_Shop_Importer не ставит
 * флаг). Backfill включает существующие опубликованные + автоматически
 * деактивированные товары для backward compat.
 *
 * Дополнительно: hook на transition_post_status (→ publish) очищает 4 маркера
 * деактивации, чтобы повторная авто-деактивация не блокировалась идемпотентностью
 * в Cashback_API_Client::deactivate_product().
 *
 * @package CashbackPlugin
 * @since   12.3.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Product_Autopublish {

    public const META_KEY           = '_cashback_auto_publish_enabled';
    public const NONCE_ACTION       = 'cashback_autopublish_save';
    public const NONCE_FIELD        = 'cashback_autopublish_nonce';
    public const POST_FIELD         = 'cashback_autopublish';
    public const BACKFILL_OPTION    = 'cashback_product_autopublish_backfill_v1';
    public const CRON_BACKFILL_HOOK = 'cashback_product_autopublish_backfill';

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

        // Чекбокс «Автопубликация» в Publish-метабоксе на странице
        // редактирования товара.
        add_action('post_submitbox_misc_actions', array( __CLASS__, 'render_publish_toggle' ), 10, 1);

        // Сохранение значения чекбокса при Update.
        add_action('save_post_product', array( __CLASS__, 'save_meta' ), 10, 2);

        // Очистка маркеров авто-деактивации при ручной публикации через WP-UI.
        // Priority 5 — раньше catalog-visibility (10), чтобы остальные listener'ы
        // видели уже чистый набор meta.
        add_action('transition_post_status', array( __CLASS__, 'on_transition_clear_markers' ), 5, 3);

        // Deferred one-shot backfill (см. ensure_backfilled).
        add_action(self::CRON_BACKFILL_HOOK, array( __CLASS__, 'handle_backfill_cron' ), 10, 0);
    }

    /**
     * Рендер чекбокса «Автопубликация» в Publish-метабоксе.
     *
     * Хук post_submitbox_misc_actions размещает контент в группе
     * .misc-pub-section, которая отрисовывается выше кнопок Опубликовать /
     * Обновить (стандартное расположение для подобных свитчей —
     * Visibility / Status / Date).
     */
    public static function render_publish_toggle( $post ): void {
        if (! is_object($post)) {
            return;
        }
        $post_type = (string) ( $post->post_type ?? '' );
        if ($post_type !== 'product') {
            return;
        }
        $post_id = (int) ( $post->ID ?? 0 );
        if ($post_id <= 0 || ! function_exists('current_user_can')) {
            return;
        }
        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $enabled = function_exists('get_post_meta')
            ? ((string) get_post_meta($post_id, self::META_KEY, true) === '1')
            : false;

        if (function_exists('wp_nonce_field')) {
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        }

        $field = self::POST_FIELD;
        ?>
        <div class="misc-pub-section misc-pub-cashback-autopublish">
            <label for="<?php echo esc_attr($field); ?>">
                <input type="checkbox"
                        name="<?php echo esc_attr($field); ?>"
                        id="<?php echo esc_attr($field); ?>"
                        value="1" <?php checked($enabled, true); ?>>
                <?php esc_html_e('Автопубликация', 'cashback'); ?>
            </label>
            <p class="description" style="margin-top:4px;">
                <?php
                esc_html_e(
                    'Включено: товар автоматически уходит в черновик и возвращается на витрину при проверке статусов кампаний CPA-сети. Выключено: автоматически только снимается с публикации; вернуть на витрину может только админ.',
                    'cashback'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Сохранение значения чекбокса.
     *
     * @param int   $post_id
     * @param mixed $post    WP_Post
     */
    public static function save_meta( $post_id, $post ): void {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        // Skip autosave / revisions.
        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post_id)) {
            return;
        }
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Skip non-product (защита: хук только save_post_product, но cheap).
        $post_type = is_object($post) ? (string) ( $post->post_type ?? '' ) : '';
        if ($post_type !== 'product') {
            return;
        }

        // Capability.
        if (! function_exists('current_user_can') || ! current_user_can('edit_post', $post_id)) {
            return;
        }

        // Nonce. Если nonce нет — это REST/CLI/импорт/Quick Edit, чекбокс не
        // приходил, значение трогать НЕ должны.
        $nonce = isset($_POST[ self::NONCE_FIELD ])
            ? sanitize_text_field(wp_unslash((string) $_POST[ self::NONCE_FIELD ]))
            : '';
        if ($nonce === '' || ! function_exists('wp_verify_nonce') || ! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        $checked = ! empty($_POST[ self::POST_FIELD ]);
        if ($checked) {
            if (function_exists('update_post_meta')) {
                update_post_meta($post_id, self::META_KEY, '1');
            }
        } elseif (function_exists('delete_post_meta')) {
            delete_post_meta($post_id, self::META_KEY);
        }
    }

    /**
     * При переходе post_type=product в publish — очистить 4 маркера
     * автоматической деактивации.
     *
     * Закрывает баг: после ручной публикации через WP-UI маркер
     * `_cashback_auto_deactivated=1` оставался, и проверка идемпотентности в
     * deactivate_product() блокировала повторную авто-деактивацию.
     *
     * Условия срабатывания:
     *   - $new_status === 'publish';
     *   - $old_status !== 'publish' (publish→publish бесмысленно для очистки);
     *   - post_type === 'product';
     *   - не revision / не autosave.
     *
     * Cron-реактивация уже сама чистит маркеры в reactivate_product —
     * этот хук покрывает только ручную публикацию через стандартный WP-UI.
     *
     * @param string $new_status
     * @param string $old_status
     * @param mixed  $post WP_Post
     */
    public static function on_transition_clear_markers( $new_status, $old_status, $post ): void {
        if ($new_status !== 'publish' || $new_status === $old_status) {
            return;
        }
        if (! is_object($post)) {
            return;
        }
        $post_type = (string) ( $post->post_type ?? '' );
        if ($post_type !== 'product') {
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
        if (class_exists('Cashback_Product_Cpa_Status_Service')) {
            Cashback_Product_Cpa_Status_Service::clear_deactivation_markers($post_id);
            return;
        }
        if (! function_exists('delete_post_meta')) {
            return;
        }

        foreach (array(
            '_cashback_auto_deactivated',
            '_cashback_deactivation_reason',
            '_cashback_deactivated_at',
            '_cashback_deactivated_network',
            '_cashback_deactivated_source',
            '_cashback_auto_deactivated_at',
            '_cashback_auto_deactivated_source',
        ) as $key) {
            delete_post_meta($post_id, $key);
        }
    }

    /**
     * Self-healing gate для one-shot backfill (по образцу
     * Cashback_Catalog_Visibility::ensure_backfilled).
     *
     * Терминальное состояние — '1' (handle_backfill_cron отработал).
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
            error_log('[Cashback Product Autopublish] backfill failed: ' . $e->getMessage());
            return;
        }
        if (function_exists('update_option')) {
            update_option(self::BACKFILL_OPTION, '1', false);
        }
    }

    /**
     * One-shot backfill: ставит `_cashback_auto_publish_enabled='1'` для всех
     * существующих товаров CPA-сети, чтобы не сломать backward-compat
     * автоматики при первом релизе.
     *
     * Включаем:
     *   - post_status='publish' AND _offer_id != '' — текущие магазины
     *     на витрине должны продолжать жить в авто-режиме.
     *   - post_status='draft'   AND _cashback_auto_deactivated='1'
     *     AND _offer_id != '' — товары, ранее снятые автоматикой; должны
     *     быть готовы к авто-реактивации, когда сеть вернёт кампанию.
     *
     * Свежеимпортированные draft (без `_cashback_auto_deactivated`) НЕ трогаем —
     * они должны требовать явного включения переключателя админом.
     *
     * @return int Количество обработанных товаров.
     */
    public static function backfill_all(): int {
        global $wpdb;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_col') || ! function_exists('update_post_meta')) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot backfill, идемпотентен.
        $ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_offer ON p.ID = pm_offer.post_id
                AND pm_offer.meta_key = '_offer_id'
                AND pm_offer.meta_value <> ''
             LEFT JOIN {$wpdb->postmeta} pm_autopub ON p.ID = pm_autopub.post_id
                AND pm_autopub.meta_key = '_cashback_auto_publish_enabled'
             LEFT JOIN {$wpdb->postmeta} pm_deact ON p.ID = pm_deact.post_id
                AND pm_deact.meta_key = '_cashback_auto_deactivated'
             WHERE p.post_type = 'product'
               AND pm_autopub.meta_id IS NULL
               AND (
                    p.post_status = 'publish'
                    OR ( p.post_status = 'draft' AND pm_deact.meta_value = '1' )
               )"
        );
        if (! is_array($ids)) {
            return 0;
        }

        $processed = 0;
        $chunks    = array_chunk(array_map('intval', $ids), 500);
        foreach ($chunks as $chunk) {
            foreach ($chunk as $pid) {
                if ($pid <= 0) {
                    continue;
                }
                update_post_meta($pid, self::META_KEY, '1');
                ++$processed;
            }
        }
        return $processed;
    }
}
