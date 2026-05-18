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
    public const AJAX_NONCE = 'cashback_shop_import_ajax';
    public const AJAX_TRIGGER_ACTION = 'cashback_shop_import_trigger_ajax';
    public const AJAX_STATUS_ACTION = 'cashback_shop_import_status';
    public const PER_PAGE = 20;

    public static function init(): void {
        add_action('admin_menu', array( self::class, 'register_menu' ), 31);
        add_action('admin_post_' . self::ADMIN_POST_ACTION, array( self::class, 'handle_trigger' ));
        add_action('wp_ajax_' . self::AJAX_TRIGGER_ACTION, array( self::class, 'ajax_trigger' ));
        add_action('wp_ajax_' . self::AJAX_STATUS_ACTION, array( self::class, 'ajax_status' ));
        add_action('admin_enqueue_scripts', array( self::class, 'enqueue_assets' ));
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

        if (self::trigger_rate_limited()) {
            self::redirect_with_notice('error', 'Слишком часто. Подождите минуту.');
            return;
        }

        $network_id = isset($_POST['network_id']) ? (int) $_POST['network_id'] : 0;
        $res        = self::enqueue_import($network_id);

        if (! $res['success']) {
            self::redirect_with_notice('error', $res['error']);
            return;
        }
        if ($res['queued']) {
            self::redirect_with_notice('success', 'Импорт запущен (run_id=' . $res['run_id'] . ')');
            return;
        }
        // Синхронный fallback завершился.
        $fetched = (int) ( $res['result']['fetched'] ?? 0 );
        self::redirect_with_notice('success', 'Импорт завершён: fetched=' . $fetched);
    }

    /**
     * Анти-спам триггера импорта (≤5 запусков/мин на пользователя).
     * Общий guard для admin_post и AJAX путей — лавина кликов = лавина AS-задач.
     *
     * @return bool true, если лимит превышен (запуск нужно отклонить).
     */
    private static function trigger_rate_limited(): bool {
        $rate_key   = 'cb_shop_import_rate_' . get_current_user_id();
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 5) {
            return true;
        }
        set_transient($rate_key, $rate_count + 1, MINUTE_IN_SECONDS);
        return false;
    }

    /**
     * Общая логика запуска импорта (используется admin_post и AJAX).
     *
     * @return array{success: bool, run_id: string, queued: bool, error: string,
     *               result: ?array<string, mixed>}
     */
    private static function enqueue_import( int $network_id ): array {
        $fail = static function ( string $msg ): array {
            return array(
                'success' => false,
                'run_id'  => '',
                'queued'  => false,
                'error'   => $msg,
                'result'  => null,
            );
        };

        if ($network_id <= 0) {
            return $fail('Некорректный network_id');
        }
        if (! class_exists('Cashback_Shop_Importer') || ! class_exists('Cashback_Shop_Import_Log')) {
            return $fail('Shop Importer не доступен');
        }

        $run_id = Cashback_Shop_Import_Log::generate_run_id();

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(
                Cashback_Shop_Importer::HOOK_RUN,
                array( $network_id, $run_id, 0 ),
                Cashback_Shop_Importer::AS_GROUP
            );
            return array(
                'success' => true,
                'run_id'  => $run_id,
                'queued'  => true,
                'error'   => '',
                'result'  => null,
            );
        }

        // Fallback: синхронный запуск (когда Action Scheduler не установлен).
        $result = Cashback_Shop_Importer::run($network_id, $run_id, 0);
        if (empty($result['success'])) {
            return $fail((string) ( $result['error'] ?? 'unknown' ));
        }
        return array(
            'success' => true,
            'run_id'  => $run_id,
            'queued'  => false,
            'error'   => '',
            'result'  => $result,
        );
    }

    /**
     * AJAX: запустить импорт, вернуть run_id для polling'а.
     */
    public static function ajax_trigger(): void {
        check_ajax_referer(self::AJAX_NONCE, 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }

        if (self::trigger_rate_limited()) {
            wp_send_json_error(array( 'message' => 'Слишком часто. Подождите минуту.' ));
        }

        $network_id = absint(wp_unslash($_POST['network_id'] ?? 0));
        $res        = self::enqueue_import($network_id);

        if (! $res['success']) {
            wp_send_json_error(array( 'message' => $res['error'] ));
        }

        $names = self::get_network_names_map();
        wp_send_json_success(array(
            'run_id'       => $res['run_id'],
            'queued'       => $res['queued'],
            'network_name' => $names[$network_id] ?? (string) $network_id,
        ));
    }

    /**
     * AJAX: вернуть свежие строки лога одного run'а + флаг завершённости.
     */
    public static function ajax_status(): void {
        check_ajax_referer(self::AJAX_NONCE, 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав' ));
        }
        if (! class_exists('Cashback_Shop_Import_Log')) {
            wp_send_json_error(array( 'message' => 'Shop Importer не доступен' ));
        }

        $run_id = isset($_POST['run_id'])
            ? sanitize_text_field(wp_unslash($_POST['run_id']))
            : '';
        if ($run_id === '' || ! preg_match('/^[a-f0-9]{16,64}$/i', $run_id)) {
            wp_send_json_error(array( 'message' => 'Некорректный run_id' ));
        }

        $rows        = Cashback_Shop_Import_Log::get_run($run_id);
        $names       = self::get_network_names_map();
        $has_pending = self::run_has_pending_as($run_id);

        ob_start();
        foreach ($rows as $log) {
            self::render_log_row($log, $names);
        }
        $rows_html = (string) ob_get_clean();

        wp_send_json_success(array(
            'rows_html' => $rows_html,
            'done'      => self::is_run_complete($rows, $has_pending),
            'count'     => count($rows),
            'pending'   => $has_pending,
        ));
    }

    /**
     * Есть ли в Action Scheduler ещё pending follow-up задача этого run'а.
     */
    private static function run_has_pending_as( string $run_id ): bool {
        if (! function_exists('as_get_scheduled_actions') || ! class_exists('Cashback_Shop_Importer')) {
            return false;
        }
        $actions = as_get_scheduled_actions(
            array(
                'hook'     => Cashback_Shop_Importer::HOOK_RUN,
                'group'    => Cashback_Shop_Importer::AS_GROUP,
                'status'   => 'pending',
                'per_page' => 50,
            )
        );
        foreach ((array) $actions as $action) {
            if (! is_object($action) || ! method_exists($action, 'get_args')) {
                continue;
            }
            $args = (array) $action->get_args();
            if (in_array($run_id, array_map('strval', $args), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Enqueue JS/CSS только на странице импорта магазинов.
     */
    public static function enqueue_assets( string $hook ): void {
        $allowed = array(
            'cashback-overview_page_' . self::PAGE_SLUG,
            'toplevel_page_' . self::PAGE_SLUG,
            'admin_page_' . self::PAGE_SLUG,
        );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection for asset enqueue.
        $current = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if (! in_array($hook, $allowed, true) && $current !== self::PAGE_SLUG) {
            return;
        }

        $base = plugin_dir_url(__DIR__);

        wp_enqueue_style(
            'cashback-shop-import',
            $base . 'admin/css/shop-import.css',
            array(),
            '1.0.0'
        );
        wp_enqueue_script(
            'cashback-shop-import',
            $base . 'admin/js/shop-import.js',
            array( 'jquery' ),
            '1.0.0',
            true
        );
        wp_localize_script('cashback-shop-import', 'cashbackShopImport', array(
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce(self::AJAX_NONCE),
            'triggerAction' => self::AJAX_TRIGGER_ACTION,
            'statusAction'  => self::AJAX_STATUS_ACTION,
            'pollInterval'  => 3000,
            'maxPolls'      => 150,
            'settlePolls'   => 3,
            'i18n'          => array(
                'starting'     => 'Запуск…',
                'started'      => 'Импорт запущен (run_id=%s)',
                'processing'   => 'В обработке',
                'queued'       => 'Задача поставлена в очередь Action Scheduler, ожидает воркер…',
                'stillRunning' => 'Импорт ещё выполняется — обновите страницу позже.',
                'netError'     => 'Ошибка сети',
                'noEmptyRuns'  => 'Запусков ещё не было.',
            ),
        ));
    }

    public static function render_page(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback'));
        }

        $networks = self::get_active_networks();

        $logs        = array();
        $logs_total  = 0;
        $logs_pages  = 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing pagination, intval-cast.
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

        if (class_exists('Cashback_Shop_Import_Log')) {
            $logs_total = Cashback_Shop_Import_Log::count_total();
            $logs_pages = $logs_total > 0 ? (int) ceil($logs_total / self::PER_PAGE) : 0;
            if ($logs_pages > 0 && $current_page > $logs_pages) {
                $current_page = $logs_pages;
            }
            $offset = ( $current_page - 1 ) * self::PER_PAGE;
            $logs   = Cashback_Shop_Import_Log::paginate(null, self::PER_PAGE, $offset);
        }

        $network_names = self::get_network_names_map();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Импорт магазинов из CPA-сетей', 'cashback'); ?></h1>

            <?php self::render_admin_notice(); ?>
            <div id="cashback-shop-import-notice" class="notice" style="display:none"><p></p></div>

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
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" class="cashback-shop-import-form">
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
            <table class="widefat striped" id="cashback-shop-import-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('№', 'cashback'); ?></th>
                        <th><?php esc_html_e('ID запуска', 'cashback'); ?></th>
                        <th><?php esc_html_e('Сеть', 'cashback'); ?></th>
                        <th><?php esc_html_e('Страница', 'cashback'); ?></th>
                        <th><?php esc_html_e('Получено', 'cashback'); ?></th>
                        <th><?php esc_html_e('Добавлено / обновлено', 'cashback'); ?></th>
                        <th><?php esc_html_e('Тарифы', 'cashback'); ?></th>
                        <th><?php esc_html_e('Начато', 'cashback'); ?></th>
                        <th><?php esc_html_e('Завершено', 'cashback'); ?></th>
                        <th><?php esc_html_e('Ошибки', 'cashback'); ?></th>
                    </tr>
                </thead>
                <tbody id="cashback-shop-import-rows">
                    <?php if (empty($logs)) : ?>
                        <tr class="cashback-import-empty">
                            <td colspan="10"><?php esc_html_e('Запусков ещё не было.', 'cashback'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php
                        foreach ($logs as $log) {
                            self::render_log_row($log, $network_names);
                        }
                        ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if (! empty($logs)) : ?>
                <?php
                Cashback_Pagination::render(array(
                    'total_items'  => $logs_total,
                    'per_page'     => self::PER_PAGE,
                    'current_page' => $current_page,
                    'total_pages'  => $logs_pages,
                    'page_slug'    => self::PAGE_SLUG,
                ));
                ?>
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

    /**
     * Карта network_id => name по ВСЕМ сетям (без is_active-фильтра),
     * чтобы логи со ссылкой на впоследствии отключённую сеть тоже резолвились.
     *
     * @return array<int, string>
     */
    private static function get_network_names_map(): array {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return array();
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, name FROM %i ORDER BY id ASC',
                $wpdb->prefix . 'cashback_affiliate_networks'
            ),
            ARRAY_A
        );
        $map = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $map[(int) $r['id']] = (string) $r['name'];
            }
        }
        return $map;
    }

    /**
     * Отрисовать одну <tr> строки лога импорта.
     *
     * Единый источник разметки для render_page() и ajax_status() — каждое
     * поле экранируется внутри, наружу выводится только esc-вывод.
     *
     * @param array<string, mixed> $log
     * @param array<int, string>   $network_names network_id => name
     */
    private static function render_log_row( array $log, array $network_names ): void {
        $nid      = (int) ( $log['network_id'] ?? 0 );
        $run_id   = (string) ( $log['run_id'] ?? '' );
        $finished = (string) ( $log['finished_at'] ?? '' );
        $err      = (string) ( $log['errors'] ?? '' );
        ?>
        <tr data-run="<?php echo esc_attr($run_id); ?>">
            <td><?php echo esc_html((string) ( $log['id'] ?? '' )); ?></td>
            <td><code><?php echo esc_html(substr($run_id, 0, 16)); ?>…</code></td>
            <td><?php echo esc_html($network_names[$nid] ?? (string) $nid); ?></td>
            <td><?php echo esc_html((string) ( $log['page'] ?? '' )); ?></td>
            <td><?php echo esc_html((string) ( $log['fetched'] ?? '' )); ?></td>
            <td>
                <?php echo esc_html((string) ( $log['upserted_new'] ?? '0' )); ?>/<?php echo esc_html((string) ( $log['upserted_upd'] ?? '0' )); ?>
            </td>
            <td><?php echo esc_html((string) ( $log['tariffs_synced'] ?? '' )); ?></td>
            <td><?php echo esc_html((string) ( $log['started_at'] ?? '' )); ?></td>
            <td>
                <?php
                if ($finished !== '') {
                    echo esc_html($finished);
                } else {
                    echo '<span class="cashback-import-status--pending">⏳ '
                        . esc_html__('в процессе…', 'cashback') . '</span>';
                }
                ?>
            </td>
            <td>
                <?php
                if ($err !== '') {
                    echo '<span class="cashback-import-error">'
                        . esc_html(mb_substr($err, 0, 80)) . '</span>';
                }
                ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Запуск завершён: строки есть, у КАЖДОЙ выставлен finished_at и в
     * Action Scheduler нет pending follow-up задачи этого run'а.
     *
     * @param array<int, array<string, mixed>> $rows строки одного run_id
     */
    public static function is_run_complete( array $rows, bool $has_pending_as ): bool {
        if (empty($rows) || $has_pending_as) {
            return false;
        }
        foreach ($rows as $row) {
            if ((string) ( $row['finished_at'] ?? '' ) === '') {
                return false;
            }
        }
        return true;
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
