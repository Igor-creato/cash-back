<?php
/**
 * Cashback → Импорт магазинов — admin страница для запуска и наблюдения за
 * импортом из CPA-сетей (v12).
 *
 * Функционал:
 *   - Список активных сетей с кнопкой «Импортировать сейчас».
 *   - admin_post handler ставит async action в Action Scheduler
 *     (cashback_shops_import_run) — Importer обработает первую страницу
 *     и сам пере-enqueue'ит follow-up.
 *   - Таблица последних запусков из cashback_shop_import_log.
 *
 * @package CashbackPlugin
 * @since   12.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Shop_Import_Admin {

    public const PAGE_SLUG = 'cashback-shop-import';
    public const NONCE_ACTION = 'cashback_shop_import_run';
    public const ADMIN_POST_ACTION = 'cashback_shop_import_trigger';

    public static function init(): void {
        add_action('admin_menu', array( self::class, 'register_menu' ), 31);
        add_action('admin_post_' . self::ADMIN_POST_ACTION, array( self::class, 'handle_trigger' ));
    }

    public static function register_menu(): void {
        add_submenu_page(
            'cashback-overview',
            'Импорт магазинов',
            'Импорт магазинов',
            'manage_options',
            self::PAGE_SLUG,
            array( self::class, 'render_page' )
        );
    }

    /**
     * admin_post handler: проверяет nonce/capability и ставит async action.
     */
    public static function handle_trigger(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback'), '', array( 'response' => 403 ));
        }
        check_admin_referer(self::NONCE_ACTION);

        $network_id = isset($_POST['network_id']) ? (int) $_POST['network_id'] : 0;
        if ($network_id <= 0) {
            self::redirect_with_notice('error', 'Некорректный network_id');
            return;
        }

        if (! class_exists('Cashback_Shop_Importer') || ! class_exists('Cashback_Shop_Import_Log')) {
            self::redirect_with_notice('error', 'Shop Importer не доступен');
            return;
        }

        $run_id = Cashback_Shop_Import_Log::generate_run_id();

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(
                Cashback_Shop_Importer::HOOK_RUN,
                array( $network_id, $run_id, 0 ),
                Cashback_Shop_Importer::AS_GROUP
            );
            self::redirect_with_notice('success', 'Импорт запущен (run_id=' . $run_id . ')');
            return;
        }

        // Fallback: синхронный запуск (когда AS не установлен).
        $result = Cashback_Shop_Importer::run($network_id, $run_id, 0);
        if (! empty($result['success'])) {
            self::redirect_with_notice('success', 'Импорт завершён: fetched=' . (int) $result['fetched']);
        } else {
            self::redirect_with_notice('error', (string) ( $result['error'] ?? 'unknown' ));
        }
    }

    public static function render_page(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback'));
        }

        $networks = self::get_active_networks();
        $logs     = class_exists('Cashback_Shop_Import_Log')
            ? Cashback_Shop_Import_Log::get_recent(null, 30)
            : array();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Импорт магазинов из CPA-сетей', 'cashback'); ?></h1>

            <?php self::render_admin_notice(); ?>

            <h2><?php esc_html_e('Активные сети', 'cashback'); ?></h2>
            <?php if (empty($networks)) : ?>
                <p><?php esc_html_e('Нет активных CPA-сетей. Добавьте сеть в Кэшбэк → Партнёры.', 'cashback'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Название', 'cashback'); ?></th>
                            <th><?php esc_html_e('Slug', 'cashback'); ?></th>
                            <th><?php esc_html_e('Действия', 'cashback'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($networks as $net) : ?>
                            <tr>
                                <td><?php echo esc_html((string) ( $net['name'] ?? '' )); ?></td>
                                <td><code><?php echo esc_html((string) ( $net['slug'] ?? '' )); ?></code></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_POST_ACTION); ?>" />
                                        <input type="hidden" name="network_id" value="<?php echo esc_attr((string) ( $net['id'] ?? 0 )); ?>" />
                                        <?php wp_nonce_field(self::NONCE_ACTION); ?>
                                        <button type="submit" class="button button-primary">
                                            <?php esc_html_e('Импортировать сейчас', 'cashback'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top:32px"><?php esc_html_e('Последние запуски', 'cashback'); ?></h2>
            <?php if (empty($logs)) : ?>
                <p><?php esc_html_e('Запусков ещё не было.', 'cashback'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>run_id</th>
                            <th>network</th>
                            <th>page</th>
                            <th>fetched</th>
                            <th>upserted (new/upd)</th>
                            <th>tariffs</th>
                            <th>started</th>
                            <th>finished</th>
                            <th>errors</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html((string) ( $log['id'] ?? '' )); ?></td>
                                <td><code><?php echo esc_html(substr((string) ( $log['run_id'] ?? '' ), 0, 16)); ?>…</code></td>
                                <td><?php echo esc_html((string) ( $log['network_id'] ?? '' )); ?></td>
                                <td><?php echo esc_html((string) ( $log['page'] ?? '' )); ?></td>
                                <td><?php echo esc_html((string) ( $log['fetched'] ?? '' )); ?></td>
                                <td>
                                    <?php echo esc_html((string) ( $log['upserted_new'] ?? '0' )); ?>/<?php echo esc_html((string) ( $log['upserted_upd'] ?? '0' )); ?>
                                </td>
                                <td><?php echo esc_html((string) ( $log['tariffs_synced'] ?? '' )); ?></td>
                                <td><?php echo esc_html((string) ( $log['started_at'] ?? '' )); ?></td>
                                <td>
                                    <?php
                                    $finished = (string) ( $log['finished_at'] ?? '' );
                                    echo $finished !== '' ? esc_html($finished) : '<em>в процессе…</em>';
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $err = (string) ( $log['errors'] ?? '' );
                                    if ($err !== '') {
                                        echo '<span style="color:#b32d2e">' . esc_html(mb_substr($err, 0, 80)) . '</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function get_active_networks(): array {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return array();
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, name, slug FROM %i WHERE is_active = 1 ORDER BY id ASC',
                $wpdb->prefix . 'cashback_affiliate_networks'
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    private static function redirect_with_notice( string $type, string $message ): void {
        $url = add_query_arg(
            array(
                'page'             => self::PAGE_SLUG,
                'cashback_notice'  => $type,
                'cashback_message' => rawurlencode($message),
            ),
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    private static function render_admin_notice(): void {
        // Read-only admin notice rendering после redirect от admin_post handler'а
        // (handle_trigger уже проверил nonce + capability). Здесь nonce не нужен.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['cashback_notice'])) {
            return;
        }
        $type    = sanitize_key((string) wp_unslash($_GET['cashback_notice']));
        $message = isset($_GET['cashback_message'])
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field применяется ниже после wp_unslash + rawurldecode.
            ? sanitize_text_field(rawurldecode((string) wp_unslash($_GET['cashback_message'])))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $css = $type === 'success' ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($css) . ' is-dismissible"><p>'
            . esc_html($message) . '</p></div>';
    }
}
