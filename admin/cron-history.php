<?php
/**
 * Admin-страница «Кешбэк → Cron History» (Group 8 Step 3, F-8-005).
 *
 * Читает cashback_cron_state — checkpoint-историю прогонов cashback_api_sync cron.
 * Группирует по run_id, показывает status / duration / metrics / error по каждому
 * из 5 этапов.
 *
 * Read-only; никаких AJAX/записей/деструктивных действий. Авторизация manage_options.
 *
 * @package CashbackPlugin
 * @since   5.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Cron_History_Admin {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array( $this, 'add_admin_menu' ));
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            'cashback-overview',
            __('Cron History', 'cashback-plugin'),
            __('Cron History', 'cashback-plugin'),
            'manage_options',
            'cashback-cron-history',
            array( $this, 'render_page' )
        );
    }

    public function render_page(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('У вас недостаточно прав для просмотра этой страницы.', 'cashback-plugin'));
        }

        if (! class_exists('Cashback_Cron_State')) {
            require_once plugin_dir_path(__FILE__) . '../includes/class-cashback-cron-state.php';
        }

        global $wpdb;
        $table = $wpdb->prefix . Cashback_Cron_State::TABLE;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page pagination; validated via absint.
        $current_page = isset($_GET['paged']) ? max(1, min(absint($_GET['paged']), 500)) : 1;
        $per_page     = 50;
        $offset       = ( $current_page - 1 ) * $per_page;

        $total_items = Cashback_Cron_State::count_total();
        $total_pages = (int) max(1, ceil($total_items / $per_page));

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, run_id, stage, status, started_at, finished_at, duration_ms, metrics_json, error_message
             FROM %i
             ORDER BY started_at DESC, id DESC
             LIMIT %d OFFSET %d',
            $table,
            $per_page,
            $offset
        ), ARRAY_A);

        $rows = is_array($rows) ? $rows : array();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Cron History (cashback_api_sync)', 'cashback-plugin'); ?></h1>
            <p class="description">
                <?php echo esc_html__('Checkpoint-история прогонов фонового sync-cron. Один run_id = один прогон, 5 этапов: background_sync, auto_transfer, process_ready, affiliate_pending, check_campaigns. Таблица cashback_cron_state.', 'cashback-plugin'); ?>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:90px"><?php echo esc_html__('Run', 'cashback-plugin'); ?></th>
                        <th style="width:170px"><?php echo esc_html__('Этап', 'cashback-plugin'); ?></th>
                        <th style="width:90px"><?php echo esc_html__('Статус', 'cashback-plugin'); ?></th>
                        <th style="width:160px"><?php echo esc_html__('Начат', 'cashback-plugin'); ?></th>
                        <th style="width:100px"><?php echo esc_html__('Длительность', 'cashback-plugin'); ?></th>
                        <th><?php echo esc_html__('Метрики / ошибка', 'cashback-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="6"><?php echo esc_html__('Пока нет ни одного прогона cron.', 'cashback-plugin'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <?php
                        $status       = (string) ( $row['status'] ?? '' );
                        $run_id_short = substr((string) $row['run_id'], 0, 8);
                        $duration     = $row['duration_ms'] !== null ? (int) $row['duration_ms'] . ' мс' : '—';
                        $status_style = 'background:#eee;color:#333';
                        if ($status === 'success') {
                            $status_style = 'background:#d4edda;color:#155724';
                        } elseif ($status === 'failed') {
                            $status_style = 'background:#f8d7da;color:#721c24';
                        } elseif ($status === 'running') {
                            $status_style = 'background:#fff3cd;color:#856404';
                        }

                        $details = '';
                        if (! empty($row['error_message'])) {
                            $details = (string) $row['error_message'];
                        } elseif (! empty($row['metrics_json'])) {
                            $details = (string) $row['metrics_json'];
                            if (mb_strlen($details) > 400) {
                                $details = mb_substr($details, 0, 400) . '…';
                            }
                        }
                        ?>
                        <tr>
                            <td><code title="<?php echo esc_attr((string) $row['run_id']); ?>"><?php echo esc_html($run_id_short); ?></code></td>
                            <td><?php echo esc_html((string) $row['stage']); ?></td>
                            <td><span style="<?php echo esc_attr($status_style); ?>;padding:2px 8px;border-radius:3px;font-size:11px"><?php echo esc_html($status); ?></span></td>
                            <td><?php echo esc_html((string) $row['started_at']); ?></td>
                            <td><?php echo esc_html($duration); ?></td>
                            <td style="max-width:600px;word-break:break-word;font-family:monospace;font-size:11px"><?php echo esc_html($details); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php
            if (class_exists('Cashback_Pagination')) {
                Cashback_Pagination::render(array(
                    'total_items'  => $total_items,
                    'per_page'     => $per_page,
                    'current_page' => $current_page,
                    'total_pages'  => $total_pages,
                    'page_slug'    => 'cashback-cron-history',
                    'add_args'     => array(),
                ));
            }
            ?>
        </div>
        <?php
    }
}

Cashback_Cron_History_Admin::get_instance();
