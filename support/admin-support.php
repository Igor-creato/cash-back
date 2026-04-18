<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс управления поддержкой в админ-панели
 */
class Cashback_Support_Admin {

    private string $tickets_table;
    private string $messages_table;

    public function __construct() {
        global $wpdb;
        $this->tickets_table  = $wpdb->prefix . 'cashback_support_tickets';
        $this->messages_table = $wpdb->prefix . 'cashback_support_messages';

        add_action('admin_menu', array( $this, 'add_admin_menu' ));

        // AJAX обработчики (регистрируем всегда для работы кнопки toggle)
        add_action('wp_ajax_support_toggle_module', array( $this, 'handle_toggle_module' ));
        add_action('wp_ajax_support_admin_reply', array( $this, 'handle_admin_reply' ));
        add_action('wp_ajax_support_change_status', array( $this, 'handle_change_status' ));
        add_action('wp_ajax_support_admin_unread_count', array( $this, 'handle_get_unread_count' ));
        add_action('wp_ajax_support_save_attachment_settings', array( $this, 'handle_save_attachment_settings' ));
        add_action('wp_ajax_support_admin_download_file', array( $this, 'handle_admin_download_file' ));

        add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));
        add_action('admin_footer', array( $this, 'render_badge_updater_script' ));
    }

    /**
     * Регистрация подменю "Поддержка" в меню "Кэшбэк"
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'cashback-overview',
            'Поддержка',
            $this->get_menu_title(),
            'manage_options',
            'cashback-support',
            array( $this, 'render_support_page' )
        );
    }

    /**
     * Подключение DOMPurify и safe-html на странице поддержки
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by admin_enqueue_scripts hook signature.
    public function enqueue_admin_scripts( string $hook ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page-detect, no state change.
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page !== 'cashback-support') {
            return;
        }

        wp_enqueue_script(
            'dompurify',
            plugins_url('assets/js/purify.min.js', __FILE__),
            array(),
            '3.3.2',
            false
        );

        wp_enqueue_script(
            'cashback-safe-html',
            plugins_url('assets/js/safe-html.js', __FILE__),
            array( 'dompurify' ),
            '1.0.0',
            false
        );
    }

    /**
     * Получить заголовок меню с бейджем непрочитанных
     */
    private function get_menu_title(): string {
        $title = 'Поддержка';

        if (!Cashback_Support_DB::is_module_enabled()) {
            return $title;
        }

        $count = Cashback_Support_DB::get_unread_tickets_count();
        if ($count > 0) {
            $title .= sprintf(
                ' <span class="awaiting-mod count-%d"><span class="pending-count">%s</span></span>',
                $count,
                number_format_i18n($count)
            );
        }

        return $title;
    }

    /**
     * Главная страница рендеринга
     */
    public function render_support_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('У вас недостаточно прав для просмотра этой страницы.');
        }

        $module_enabled = Cashback_Support_DB::is_module_enabled();

        echo '<div class="wrap">';

        if (!$module_enabled) {
            $this->render_module_disabled();
            echo '</div>';
            return;
        }

        // Определяем текущее действие
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin UI routing, allowlist-compared below.
        $action = isset($_GET['action']) && is_string($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin UI routing, absint-cast.
        $ticket_id = isset($_GET['ticket_id']) ? absint(wp_unslash($_GET['ticket_id'])) : 0;

        if ($action === 'view' && $ticket_id > 0) {
            $this->render_ticket_view($ticket_id);
        } elseif ($action === 'settings') {
            $this->render_settings_page();
        } else {
            $this->render_ticket_list();
        }

        echo '</div>';
    }

    /**
     * Вывод контейнера уведомлений и JS-функции showAdminNotice
     */
    private function render_admin_notice_container(): void {
        ?>
        <div id="support-admin-notice"></div>
        <script>
        function showAdminNotice(type, message, container) {
            var cssClass = type === 'success' ? 'notice-success' : 'notice-error';
            var $container = jQuery(container || '#support-admin-notice');
            $container.html(
                '<div class="notice ' + cssClass + ' is-dismissible" style="margin: 10px 0;">' +
                '<p>' + jQuery('<span>').text(message).html() + '</p>' +
                '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Закрыть</span></button>' +
                '</div>'
            ).show();
            $container.find('.notice-dismiss').on('click', function() {
                jQuery(this).closest('.notice').fadeOut(300, function() { jQuery(this).remove(); });
            });
            if (type === 'success') {
                setTimeout(function() { $container.find('.notice').fadeOut(300, function() { jQuery(this).remove(); }); }, 5000);
            }
        }
        jQuery(document).on('click', '.cashback-copy-ref', function(e) {
            e.preventDefault();
            var $el = jQuery(this);
            var text = $el.data('copy');
            if (text === undefined || text === null) { return; }
            text = String(text);
            var done = function() {
                var original = $el.text();
                $el.text('✓ скопировано');
                setTimeout(function() { $el.text(original); }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function() {
                    var ta = document.createElement('textarea');
                    ta.value = text; document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); done(); } catch (err) {}
                    document.body.removeChild(ta);
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = text; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); done(); } catch (err) {}
                document.body.removeChild(ta);
            }
        });
        </script>
        <?php
    }

    /**
     * Рендеринг состояния "модуль выключен"
     */
    private function render_module_disabled(): void {
        $nonce = wp_create_nonce('support_toggle_module_nonce');
        ?>
        <h1 class="wp-heading-inline">Поддержка</h1>
        <hr class="wp-header-end">
        <?php $this->render_admin_notice_container(); ?>
        <div class="notice notice-info">
            <p>Модуль поддержки отключен. Включите его, чтобы активировать систему тикетов.</p>
        </div>
        <p>
            <button type="button" class="button button-primary" id="support-enable-module"
                    data-nonce="<?php echo esc_attr($nonce); ?>">
                Включить модуль
            </button>
        </p>
        <script>
        jQuery(document).ready(function($) {
            $('#support-enable-module').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Включение...');
                $.post(ajaxurl, {
                    action: 'support_toggle_module',
                    enabled: 1,
                    nonce: btn.data('nonce')
                }, function(response) {
                    if (response.success) {
                        showAdminNotice('success', 'Модуль поддержки включен');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showAdminNotice('error', response.data.message || 'Ошибка');
                        btn.prop('disabled', false).text('Включить модуль');
                    }
                }).fail(function() {
                    showAdminNotice('error', 'Ошибка сервера');
                    btn.prop('disabled', false).text('Включить модуль');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Рендеринг списка тикетов
     */
    private function render_ticket_list(): void {
        global $wpdb;

        $nonce = wp_create_nonce('support_toggle_module_nonce');

        // Фильтры
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing filter, allowlist-validated below.
        $filter_status = isset($_GET['filter_status']) && is_string($_GET['filter_status']) ? sanitize_text_field(wp_unslash($_GET['filter_status'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing filter, allowlist-validated below.
        $filter_priority = isset($_GET['filter_priority']) && is_string($_GET['filter_priority']) ? sanitize_text_field(wp_unslash($_GET['filter_priority'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing filter, compared to literal '1'.
        $filter_unread = isset($_GET['filter_unread']) && is_string($_GET['filter_unread']) ? sanitize_text_field(wp_unslash($_GET['filter_unread'])) : '';

        // Пагинация
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin listing pagination, absint-cast.
        $current_page = max(1, isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1);
        $per_page     = 10;
        $offset       = ( $current_page - 1 ) * $per_page;

        // Построение WHERE
        $where_conditions = array();
        $where_params     = array();
        $need_unread_join = false;

        if (!empty($filter_status) && in_array($filter_status, array( 'open', 'answered', 'closed' ), true)) {
            $where_conditions[] = 't.status = %s';
            $where_params[]     = $filter_status;
        }

        if (!empty($filter_priority) && in_array($filter_priority, array( 'urgent', 'normal', 'not_urgent' ), true)) {
            $where_conditions[] = 't.priority = %s';
            $where_params[]     = $filter_priority;
        }

        if ($filter_unread === '1') {
            $where_conditions[] = 'EXISTS (SELECT 1 FROM %i m WHERE m.ticket_id = t.id AND m.is_admin = 0 AND m.is_read = 0)';
            $where_params[]     = $this->messages_table;
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        // Подсчёт общего количества
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $where_clause из allowlist-условий (status/priority/EXISTS) с %s/%i; $count_sql передаётся в prepare().
        $count_sql    = "SELECT COUNT(*) FROM %i t {$where_clause}";
        $count_params = array_merge(array( $this->tickets_table ), $where_params);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $count_sql хранит SQL с %i/%s плейсхолдерами, передаётся в prepare().
        $total_items = (int) $wpdb->get_var($wpdb->prepare($count_sql, $count_params));

        $total_pages = (int) ceil($total_items / $per_page);

        // Получение тикетов
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $where_clause из allowlist-условий (status/priority/EXISTS) с %s/%i; $select_sql передаётся в prepare().
        $select_sql = "SELECT t.*, u.user_login, u.user_email,
            (SELECT COUNT(*) FROM %i m WHERE m.ticket_id = t.id AND m.is_admin = 0 AND m.is_read = 0) as unread_count
            FROM %i t
            LEFT JOIN %i u ON t.user_id = u.ID
            {$where_clause}
            ORDER BY
                (unread_count > 0) DESC,
                CASE t.status WHEN 'open' THEN 0 WHEN 'answered' THEN 1 WHEN 'closed' THEN 2 END,
                CASE t.priority WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 WHEN 'not_urgent' THEN 2 END,
                t.updated_at DESC
            LIMIT %d OFFSET %d";

        $query_params = array_merge(array( $this->messages_table, $this->tickets_table, $wpdb->users ), $where_params, array( $per_page, $offset ));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $select_sql хранит SQL с %i/%s/%d плейсхолдерами, передаётся в prepare().
        $tickets = $wpdb->get_results($wpdb->prepare($select_sql, $query_params));

        ?>
        <style>
            .support-admin-badge {
                display: inline-block;
                padding: 2px 8px;
                font-size: 0.85em;
                border-radius: 3px;
                color: #fff;
            }
            .status-open { background: #2196F3; }
            .status-answered { background: #4CAF50; }
            .status-closed { background: #9E9E9E; }
            .priority-urgent { background: #f44336; }
            .priority-normal { background: #ff9800; }
            .priority-not_urgent { background: #607d8b; }
            .support-related-chip {
                display: inline-block;
                padding: 2px 8px;
                font-size: 11px;
                border-radius: 10px;
                background: #e3eef7;
                color: #2b5e86;
                margin-left: 6px;
                vertical-align: middle;
                white-space: nowrap;
            }
            .support-related-chip--affiliate_accrual {
                background: #f0e4f7;
                color: #6a2b86;
            }
            .support-related-chip--payout {
                background: #e4f7ea;
                color: #2b8648;
            }
        </style>

        <h1 class="wp-heading-inline">Поддержка</h1>
        <hr class="wp-header-end">
        <?php $this->render_admin_notice_container(); ?>

        <p>
            <button type="button" class="button" id="support-disable-module"
                    data-nonce="<?php echo esc_attr($nonce); ?>">
                Отключить модуль
            </button>
            <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-support&action=settings')); ?>" class="button" style="margin-left: 10px;">
                Настройки вложений
            </a>
        </p>

        <div class="notice notice-info" style="margin: 15px 0;">
            <p>Закрытые тикеты автоматически удаляются через 1 месяц после закрытия.</p>
        </div>

        <!-- Фильтры -->
        <div class="tablenav top">
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="cashback-support">
                <div class="alignleft actions">
                    <select name="filter_status">
                        <option value="">Все статусы</option>
                        <option value="open" <?php selected($filter_status, 'open'); ?>>Открыт</option>
                        <option value="answered" <?php selected($filter_status, 'answered'); ?>>Отвечен</option>
                        <option value="closed" <?php selected($filter_status, 'closed'); ?>>Закрыт</option>
                    </select>
                    <select name="filter_priority">
                        <option value="">Все приоритеты</option>
                        <option value="urgent" <?php selected($filter_priority, 'urgent'); ?>>Срочный</option>
                        <option value="normal" <?php selected($filter_priority, 'normal'); ?>>Обычный</option>
                        <option value="not_urgent" <?php selected($filter_priority, 'not_urgent'); ?>>Не срочный</option>
                    </select>
                    <select name="filter_unread">
                        <option value="">Все сообщения</option>
                        <option value="1" <?php selected($filter_unread, '1'); ?>>Только непрочитанные</option>
                    </select>
                    <button type="submit" class="button action">Фильтровать</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-support')); ?>" class="button action">Сбросить</a>
                </div>
            </form>
            <br class="clear">
        </div>

        <!-- Таблица тикетов -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 60px;">№</th>
                    <th scope="col">Тема</th>
                    <th scope="col" style="width: 80px;">ID польз.</th>
                    <th scope="col" style="width: 150px;">Пользователь</th>
                    <th scope="col" style="width: 100px;">Приоритет</th>
                    <th scope="col" style="width: 100px;">Статус</th>
                    <th scope="col" style="width: 40px;" title="Непрочитанные сообщения">✉</th>
                    <th scope="col" style="width: 140px;">Создан</th>
                    <th scope="col" style="width: 140px;">Обновлён</th>
                    <th scope="col" style="width: 100px;">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($tickets)) : ?>
                    <?php foreach ($tickets as $ticket) : ?>
                        <tr<?php echo $ticket->unread_count > 0 ? ' style="font-weight: bold;"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML literal selected by ternary. ?>>
                            <td><?php echo esc_html(Cashback_Support_DB::format_ticket_number((int) $ticket->id)); ?></td>
                            <td>
                                <?php echo esc_html($ticket->subject); ?>
                                <?php if (!empty($ticket->related_type)) : ?>
                                    <span class="support-related-chip support-related-chip--<?php echo esc_attr($ticket->related_type); ?>" title="<?php echo esc_attr(Cashback_Support_DB::get_related_type_label((string) $ticket->related_type)); ?>">
                                        <?php echo esc_html(Cashback_Support_DB::get_related_type_label((string) $ticket->related_type)); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html((string) $ticket->user_id); ?></td>
                            <td>
                                <?php echo esc_html($ticket->user_login ?? 'Удалён'); ?>
                                <?php if ($ticket->user_email) : ?>
                                    <br><small><?php echo esc_html($ticket->user_email); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="support-admin-badge <?php echo esc_attr($this->get_priority_css_class($ticket->priority)); ?>">
                                    <?php echo esc_html($this->get_priority_label($ticket->priority)); ?>
                                </span>
                            </td>
                            <td>
                                <span class="support-admin-badge <?php echo esc_attr($this->get_status_css_class($ticket->status)); ?>">
                                    <?php echo esc_html($this->get_status_label($ticket->status)); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($ticket->unread_count > 0) : ?>
                                    <span class="awaiting-mod count-<?php echo (int) $ticket->unread_count; ?>">
                                        <span class="pending-count"><?php echo (int) $ticket->unread_count; ?></span>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($ticket->created_at))); ?></td>
                            <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($ticket->updated_at))); ?></td>
                            <td>
                                <a href="
                                <?php
                                echo esc_url(add_query_arg(array(
									'page'      => 'cashback-support',
									'action'    => 'view',
									'ticket_id' => $ticket->id,
								), admin_url('admin.php')));
?>
" class="button button-small">
                                    Ответить
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="10">Тикеты не найдены.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        Cashback_Admin_Pagination::render(array(
            'total_items'  => $total_items,
            'current_page' => $current_page,
            'total_pages'  => $total_pages,
            'page_slug'    => 'cashback-support',
            'add_args'     => array_filter(array(
                'filter_status'   => $filter_status,
                'filter_priority' => $filter_priority,
                'filter_unread'   => $filter_unread,
            )),
        ));
        ?>

        <script>
        jQuery(document).ready(function($) {
            $('#support-disable-module').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Отключение...');
                $.post(ajaxurl, {
                    action: 'support_toggle_module',
                    enabled: 0,
                    nonce: btn.data('nonce')
                }, function(response) {
                    if (response.success) {
                        showAdminNotice('success', 'Модуль поддержки отключен');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showAdminNotice('error', response.data.message || 'Ошибка');
                        btn.prop('disabled', false).text('Отключить модуль');
                    }
                }).fail(function() {
                    showAdminNotice('error', 'Ошибка сервера');
                    btn.prop('disabled', false).text('Отключить модуль');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Рендеринг просмотра тикета
     */
    private function render_ticket_view( int $ticket_id ): void {
        global $wpdb;

        // Получаем тикет
        $ticket = $wpdb->get_row($wpdb->prepare(
            'SELECT t.*, u.user_login, u.user_email
             FROM %i t
             LEFT JOIN %i u ON t.user_id = u.ID
             WHERE t.id = %d',
            $this->tickets_table,
            $wpdb->users,
            $ticket_id
        ));

        if (!$ticket) {
            echo '<div class="notice notice-error"><p>Тикет не найден.</p></div>';
            return;
        }

        // Помечаем сообщения пользователя как прочитанные
        $wpdb->update(
            $this->messages_table,
            array( 'is_read' => 1 ),
            array(
				'ticket_id' => $ticket_id,
				'is_admin'  => 0,
				'is_read'   => 0,
			),
            array( '%d' ),
            array( '%d', '%d', '%d' )
        );

        // Получаем все сообщения
        $messages = $wpdb->get_results($wpdb->prepare(
            'SELECT m.*, u.user_login
             FROM %i m
             LEFT JOIN %i u ON m.user_id = u.ID
             WHERE m.ticket_id = %d
             ORDER BY m.created_at ASC',
            $this->messages_table,
            $wpdb->users,
            $ticket_id
        ));

        // Batch-загрузка вложений
        $message_ids     = array_map(function ( $m ) {
            return (int) $m->id;
        }, $messages);
        $attachments_map = Cashback_Support_DB::get_attachments_for_messages($message_ids);

        $is_closed    = ( $ticket->status === 'closed' );
        $reply_nonce  = wp_create_nonce('support_admin_reply_nonce');
        $status_nonce = wp_create_nonce('support_change_status_nonce');

        ?>
        <h1 class="wp-heading-inline">
            <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-support')); ?>">&larr; Назад к списку</a>
            &nbsp;|&nbsp;
            Тикет <?php echo esc_html(Cashback_Support_DB::format_ticket_number($ticket_id)); ?>
        </h1>
        <hr class="wp-header-end">

        <!-- Информация о тикете -->
        <table class="form-table">
            <tr>
                <th>Тема</th>
                <td><?php echo esc_html($ticket->subject); ?></td>
            </tr>
            <tr>
                <th>Пользователь</th>
                <td>
                    <?php echo esc_html($ticket->user_login ?? 'Удалён'); ?>
                    <?php if ($ticket->user_email) : ?>
                        (<?php echo esc_html($ticket->user_email); ?>)
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Приоритет</th>
                <td>
                    <span class="support-admin-badge <?php echo esc_attr($this->get_priority_css_class($ticket->priority)); ?>">
                        <?php echo esc_html($this->get_priority_label($ticket->priority)); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Статус</th>
                <td>
                    <span class="support-admin-badge <?php echo esc_attr($this->get_status_css_class($ticket->status)); ?>">
                        <?php echo esc_html($this->get_status_label($ticket->status)); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Создан</th>
                <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($ticket->created_at))); ?></td>
            </tr>
            <?php if ($ticket->closed_at) : ?>
            <tr>
                <th>Закрыт</th>
                <td><?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($ticket->closed_at))); ?></td>
            </tr>
            <?php endif; ?>
            <?php
            if (!empty($ticket->related_type) && !empty($ticket->related_id)) :
                $related = Cashback_Support_DB::get_related_entity((string) $ticket->related_type, (int) $ticket->related_id);
                if ($related) :
                    $type_label = Cashback_Support_DB::get_related_type_label((string) $related['type']);
                    $ref        = !empty($related['reference_id']) ? (string) $related['reference_id'] : '#' . (int) $related['id'];
                    $amount     = number_format_i18n((float) ( $related['amount'] ?? 0 ), 2);
                    $currency   = (string) ( $related['currency'] ?? '' );
                    $status     = (string) ( $related['status'] ?? '' );
                    $created    = !empty($related['created_at']) ? date_i18n('d.m.Y H:i', strtotime((string) $related['created_at'])) : '';
                    $admin_url  = '';
                    if ($related['type'] === 'cashback_tx') {
                        $admin_url = admin_url('admin.php?page=cashback-transactions&reference_id=' . rawurlencode($ref));
                    } elseif ($related['type'] === 'affiliate_accrual') {
                        $admin_url = admin_url('admin.php?page=cashback-affiliate&tab=accruals&reference_id=' . rawurlencode($ref));
                    } elseif ($related['type'] === 'payout') {
                        $admin_url = admin_url('admin.php?page=cashback-payouts&reference_id=' . rawurlencode($ref));
                    }
                    ?>
                    <tr>
                        <th>Связано с</th>
                        <td>
                            <strong><?php echo esc_html($type_label); ?></strong>
                            <code class="cashback-copy-ref" data-copy="<?php echo esc_attr($ref); ?>" title="<?php echo esc_attr__('Скопировать', 'cashback-plugin'); ?>" style="cursor:pointer;"><?php echo esc_html($ref); ?></code>
                            <?php if ($admin_url) : ?>
                                <a href="<?php echo esc_url($admin_url); ?>" class="cashback-ref-link" title="<?php echo esc_attr__('Открыть', 'cashback-plugin'); ?>" style="text-decoration:none;margin-left:4px;">↗</a>
                            <?php endif; ?>
                            <?php if (!empty($related['title'])) : ?>
                                — <?php echo esc_html((string) $related['title']); ?>
                            <?php endif; ?>
                            <br>
                            <span style="color:#666; font-size: 12px;">
                                <?php echo esc_html($amount . ' ' . $currency); ?>
                                <?php if ($status !== '') : ?>
                                    • <?php echo esc_html($status); ?>
                                <?php endif; ?>
                                <?php if ($created !== '') : ?>
                                    • <?php echo esc_html($created); ?>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                    <?php
                endif;
            endif;
            ?>
        </table>

        <?php $this->render_admin_notice_container(); ?>

        <h2>Переписка</h2>

        <!-- Лента сообщений -->
        <div id="support-messages" style="max-width: 800px;">
            <?php foreach ($messages as $msg) : ?>
                <?php
                $msg_attachments = $attachments_map[ (int) $msg->id ] ?? array();
                echo $this->render_message_html($msg, $msg_attachments); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_message_html() builds escaped HTML internally.
                ?>
            <?php endforeach; ?>
        </div>

        <?php if (!$is_closed) : ?>
        <!-- Форма ответа и управление -->
        <div id="support-reply-section" style="max-width: 800px; margin-top: 20px;">
            <h3>Ответить</h3>
            <textarea id="support-admin-message" rows="5" class="large-text" placeholder="Введите ваш ответ..."></textarea>
            <?php if (Cashback_Support_DB::is_attachments_enabled()) : ?>
            <div style="margin-top: 10px;">
                <input type="file" id="support-admin-files" name="support_files[]" multiple>
                <p class="description">Макс. <?php echo absint(Cashback_Support_DB::get_max_files_per_message()); ?> файлов, до <?php echo esc_html(number_format_i18n(Cashback_Support_DB::get_max_file_size())); ?> КБ каждый</p>
            </div>
            <?php endif; ?>
            <p>
                <button type="button" class="button button-primary" id="support-send-reply">Отправить ответ</button>
                <button type="button" class="button" id="support-close-ticket" style="margin-left: 10px; color: #a00;">Закрыть тикет</button>
            </p>
        </div>
        <?php else : ?>
        <div class="notice notice-warning" style="max-width: 800px; margin-top: 20px;">
            <p>Тикет закрыт. Ответить невозможно.</p>
        </div>
        <?php endif; ?>

        <script>
        jQuery(document).ready(function($) {
            var ticketId = <?php echo (int) $ticket_id; ?>;

            // Обновляем бейдж при открытии тикета (сообщения помечены прочитанными)
            if (typeof updateSupportBadge === 'function') updateSupportBadge();

            // Снимаем подсветку ошибки при вводе
            $('#support-admin-message').on('input', function() {
                $(this).css({'border-color': '', 'box-shadow': ''});
            });

            // Ответ администратора
            $('#support-send-reply').on('click', function() {
                var message = $('#support-admin-message').val().trim();
                if (!message) {
                    showAdminNotice('error', 'Введите сообщение пожалуйста');
                    $('#support-admin-message').css({'border-color': '#f44336', 'box-shadow': '0 0 0 1px #f44336'}).focus();
                    return;
                }

                var btn = $(this);
                btn.prop('disabled', true).text('Отправка...');

                var fd = new FormData();
                fd.append('action', 'support_admin_reply');
                fd.append('ticket_id', ticketId);
                fd.append('message', message);
                fd.append('nonce', '<?php echo esc_js($reply_nonce); ?>');

                var fileInput = document.getElementById('support-admin-files');
                if (fileInput && fileInput.files.length) {
                    for (var i = 0; i < fileInput.files.length; i++) {
                        fd.append('support_files[]', fileInput.files[i]);
                    }
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            $('#support-messages').append(cashbackSafeHtml(response.data.html));
                            $('#support-admin-message').val('').css({'border-color': '', 'box-shadow': ''});
                            if (fileInput) fileInput.value = '';
                            var $statusBadge = $('.form-table .support-admin-badge.status-open, .form-table .support-admin-badge.status-answered, .form-table .support-admin-badge.status-closed');
                            $statusBadge.removeClass('status-open status-closed').addClass('status-answered').text('Отвечен');
                            showAdminNotice('success', 'Сообщение отправлено');
                            if (typeof updateSupportBadge === 'function') updateSupportBadge();
                        } else {
                            showAdminNotice('error', response.data.message || 'Ошибка при отправке');
                        }
                        btn.prop('disabled', false).text('Отправить ответ');
                    },
                    error: function() {
                        showAdminNotice('error', 'Ошибка сервера');
                        btn.prop('disabled', false).text('Отправить ответ');
                    }
                });
            });

            // Закрытие тикета
            $('#support-close-ticket').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Закрытие...');

                $.post(ajaxurl, {
                    action: 'support_change_status',
                    ticket_id: ticketId,
                    status: 'closed',
                    nonce: '<?php echo esc_js($status_nonce); ?>'
                }, function(response) {
                    if (response.success) {
                        var $statusBadge = $('.form-table .status-open, .form-table .status-answered, .form-table .status-closed');
                        $statusBadge.removeClass('status-open status-answered status-closed')
                            .addClass('status-closed').text('Закрыт');
                        $('#support-reply-section').hide();
                        $('#support-messages').after(
                            '<div class="notice notice-warning" style="max-width: 800px; margin-top: 20px;">' +
                            '<p>Тикет закрыт. Ответить невозможно.</p></div>'
                        );
                        showAdminNotice('success', 'Тикет закрыт');
                        if (typeof updateSupportBadge === 'function') updateSupportBadge();
                    } else {
                        showAdminNotice('error', response.data.message || 'Ошибка при закрытии');
                        btn.prop('disabled', false).text('Закрыть тикет');
                    }
                }).fail(function() {
                    showAdminNotice('error', 'Ошибка сервера');
                    btn.prop('disabled', false).text('Закрыть тикет');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Генерация HTML одного сообщения
     *
     * @param object[] $attachments
     */
    private function render_message_html( object $msg, array $attachments = array() ): string {
        $is_admin = (int) $msg->is_admin === 1;
        $bg_color = $is_admin ? '#e8f4f8' : '#f9f9f9';
        $sender   = $is_admin ? 'Администратор' : ( $msg->user_login ?? 'Пользователь' );
        $date     = date_i18n('d.m.Y H:i', strtotime($msg->created_at));

        $attachments_html = '';
        if (!empty($attachments)) {
            $attachments_html .= '<div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #e0e0e0;">';
            foreach ($attachments as $att) {
                $size              = size_format($att->file_size);
                $attachments_html .= sprintf(
                    '<div>'
                    . '<form method="post" action="%s" style="display:inline;">'
                    . '<input type="hidden" name="action" value="support_admin_download_file">'
                    . '<input type="hidden" name="nonce" value="%s">'
                    . '<input type="hidden" name="id" value="%d">'
                    . '<button type="submit" style="background:none;border:none;padding:0;color:#0073aa;cursor:pointer;text-decoration:underline;">&#128206; %s</button>'
                    . '</form>'
                    . ' <small>(%s)</small></div>',
                    esc_url(admin_url('admin-ajax.php')),
                    esc_attr(wp_create_nonce('support_admin_download_file_nonce')),
                    (int) $att->id,
                    esc_html($att->file_name),
                    esc_html($size)
                );
            }
            $attachments_html .= '</div>';
        }

        return sprintf(
            '<div style="background: %s; border: 1px solid #ddd; border-left: 4px solid %s; padding: 12px 16px; margin-bottom: 10px;">
                <div style="margin-bottom: 8px;">
                    <strong>%s</strong>
                    <span style="color: #888; float: right;">%s</span>
                </div>
                <div>%s</div>
                %s
            </div>',
            esc_attr($bg_color),
            $is_admin ? '#0073aa' : '#999',
            esc_html($sender),
            esc_html($date),
            nl2br(esc_html($msg->message)),
            $attachments_html
        );
    }

    // ========= AJAX обработчики =========

    /**
     * Включение/выключение модуля
     */
    public function handle_toggle_module(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'support_toggle_module_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав.' ));
            return;
        }

        $enabled = ( isset( $_POST['enabled'] ) ? absint( wp_unslash( $_POST['enabled'] ) ) : 0 ) === 1;
        Cashback_Support_DB::set_module_enabled($enabled);

        if ($enabled) {
            // При включении убедимся что таблицы существуют
            Cashback_Support_DB::create_tables();
        }

        // Откладываем flush до следующего запроса, когда endpoint будет
        // зарегистрирован (при включении) или снят (при отключении)
        set_transient('cashback_support_flush_rules', 1, 60);

        wp_send_json_success(array( 'enabled' => $enabled ));
    }

    /**
     * Ответ администратора на тикет
     */
    public function handle_admin_reply(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'support_admin_reply_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав.' ));
            return;
        }

        global $wpdb;

        $ticket_id = isset($_POST['ticket_id']) ? absint(wp_unslash($_POST['ticket_id'])) : 0;
        $message   = isset($_POST['message']) && is_string($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

        if (!$ticket_id || empty($message)) {
            wp_send_json_error(array( 'message' => 'Заполните все поля.' ));
            return;
        }

        if (mb_strlen($message) > 5000) {
            wp_send_json_error(array( 'message' => 'Сообщение слишком длинное (максимум 5000 символов).' ));
            return;
        }

        // Ранняя проверка числа файлов — ДО любых изменений в БД
        $upload_errors    = array();
        $files_to_process = array();
        if (Cashback_Support_DB::is_attachments_enabled() && isset($_FILES['support_files']['name']) && is_array($_FILES['support_files']['name']) && !empty($_FILES['support_files']['name'][0])) {
            $files_count = count($_FILES['support_files']['name']);
            $max_files   = Cashback_Support_DB::get_max_files_per_message();

            if ($files_count > $max_files) {
                wp_send_json_error(array( 'message' => sprintf('Максимум %d файлов.', $max_files) ));
                return;
            }

            for ($i = 0; $i < $files_count; $i++) {
                if (isset($_FILES['support_files']['error'][ $i ]) && (int) $_FILES['support_files']['error'][ $i ] !== UPLOAD_ERR_NO_FILE) {
                    $files_to_process[] = array(
                        'name'     => isset($_FILES['support_files']['name'][ $i ]) && is_string($_FILES['support_files']['name'][ $i ]) ? sanitize_text_field(wp_unslash($_FILES['support_files']['name'][ $i ])) : '',
                        'type'     => isset($_FILES['support_files']['type'][ $i ]) && is_string($_FILES['support_files']['type'][ $i ]) ? sanitize_text_field(wp_unslash($_FILES['support_files']['type'][ $i ])) : '',
                        'tmp_name' => isset($_FILES['support_files']['tmp_name'][ $i ]) && is_string($_FILES['support_files']['tmp_name'][ $i ]) ? sanitize_text_field(wp_unslash($_FILES['support_files']['tmp_name'][ $i ])) : '',
                        'error'    => isset($_FILES['support_files']['error'][ $i ]) ? (int) $_FILES['support_files']['error'][ $i ] : UPLOAD_ERR_NO_FILE,
                        'size'     => isset($_FILES['support_files']['size'][ $i ]) ? (int) $_FILES['support_files']['size'][ $i ] : 0,
                    );
                }
            }
        }

        // Проверяем что тикет существует и не закрыт — внутри транзакции с блокировкой строки
        $wpdb->query('START TRANSACTION');

        $ticket = $wpdb->get_row($wpdb->prepare(
            'SELECT id, user_id, subject, status FROM %i WHERE id = %d FOR UPDATE',
            $this->tickets_table,
            $ticket_id
        ));

        if (!$ticket) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Тикет не найден.' ));
            return;
        }

        if ($ticket->status === 'closed') {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Невозможно ответить на закрытый тикет.' ));
            return;
        }

        // Вставляем сообщение
        $inserted = $wpdb->insert(
            $this->messages_table,
            array(
                'ticket_id' => $ticket_id,
                'user_id'   => get_current_user_id(),
                'message'   => $message,
                'is_admin'  => 1,
                'is_read'   => 0,
            ),
            array( '%d', '%d', '%s', '%d', '%d' )
        );

        if (!$inserted) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Ошибка при сохранении сообщения.' ));
            return;
        }

        $message_id = (int) $wpdb->insert_id;

        // Обновляем статус тикета
        $updated = $wpdb->update(
            $this->tickets_table,
            array(
                'status'     => 'answered',
                'updated_at' => current_time('mysql'),
            ),
            array( 'id' => $ticket_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении статуса тикета.' ));
            return;
        }

        $wpdb->query('COMMIT');

        // Обработка вложений — после коммита, ошибки не критичны для основной операции
        foreach ($files_to_process as $single_file) {
            $result = Cashback_Support_DB::handle_file_upload($single_file, $ticket_id, $message_id, get_current_user_id());
            if (is_string($result)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Support] Admin file upload error: ' . $result);
                $upload_errors[] = $result;
            }
        }

        // Отправляем email пользователю через модуль уведомлений
        if (has_action('cashback_notification_ticket_reply')) {
            do_action('cashback_notification_ticket_reply', $ticket_id, $ticket->subject, (int) $ticket->user_id, $message);
        } else {
            $this->send_user_notification($ticket_id, $ticket->subject, (int) $ticket->user_id, $message);
        }

        // Генерируем HTML нового сообщения
        $msg = (object) array(
            'is_admin'   => 1,
            'user_login' => wp_get_current_user()->user_login,
            'message'    => $message,
            'created_at' => current_time('mysql'),
        );

        // Получаем вложения для нового сообщения
        $msg_attachments_map = Cashback_Support_DB::get_attachments_for_messages(array( $message_id ));
        $msg_attachments     = $msg_attachments_map[ $message_id ] ?? array();

        $response = array( 'html' => $this->render_message_html($msg, $msg_attachments) );
        if (!empty($upload_errors)) {
            $response['upload_warnings'] = $upload_errors;
        }

        wp_send_json_success($response);
    }

    /**
     * Смена статуса тикета
     */
    public function handle_change_status(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'support_change_status_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав.' ));
            return;
        }

        global $wpdb;

        $ticket_id  = isset($_POST['ticket_id']) ? absint(wp_unslash($_POST['ticket_id'])) : 0;
        $new_status = isset($_POST['status']) && is_string($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';

        if (!$ticket_id || !in_array($new_status, array( 'open', 'answered', 'closed' ), true)) {
            wp_send_json_error(array( 'message' => 'Некорректные данные.' ));
            return;
        }

        // Читаем и обновляем статус атомарно, чтобы избежать гонки состояний
        $wpdb->query('START TRANSACTION');

        $ticket = $wpdb->get_row($wpdb->prepare(
            'SELECT id, status FROM %i WHERE id = %d FOR UPDATE',
            $this->tickets_table,
            $ticket_id
        ));

        if (!$ticket) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Тикет не найден.' ));
            return;
        }

        if ($ticket->status === 'closed') {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Невозможно изменить статус закрытого тикета.' ));
            return;
        }

        $update_data   = array(
            'status'     => $new_status,
            'updated_at' => current_time('mysql'),
        );
        $update_format = array( '%s', '%s' );

        if ($new_status === 'closed') {
            $update_data['closed_at'] = current_time('mysql');
            $update_format[]          = '%s';
        }

        $updated = $wpdb->update(
            $this->tickets_table,
            $update_data,
            array( 'id' => $ticket_id ),
            $update_format,
            array( '%d' )
        );

        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Ошибка при обновлении статуса.' ));
            return;
        }

        $wpdb->query('COMMIT');

        wp_send_json_success(array( 'status' => $new_status ));
    }

    /**
     * Получение количества непрочитанных тикетов (для AJAX-обновления бейджа)
     */
    public function handle_get_unread_count(): void {
        if (!check_ajax_referer('support_admin_unread_count_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error();
            return;
        }

        wp_send_json_success(array( 'count' => Cashback_Support_DB::get_unread_tickets_count() ));
    }

    /**
     * Скрипт автообновления бейджа в меню
     */
    public function render_badge_updater_script(): void {
        if (!Cashback_Support_DB::is_module_enabled()) {
            return;
        }
        ?>
        <script>
        (function($) {
            var unreadCountNonce = '<?php echo esc_js(wp_create_nonce('support_admin_unread_count_nonce')); ?>';
            window.updateSupportBadge = function() {
                $.post(ajaxurl, { action: 'support_admin_unread_count', nonce: unreadCountNonce }, function(response) {
                    if (!response.success) return;
                    var count = response.data.count;
                    var $menuLink = $('#adminmenu a[href*="cashback-support"]');
                    if (!$menuLink.length) return;
                    var $badge = $menuLink.find('.awaiting-mod');
                    if (count > 0) {
                        if ($badge.length) {
                            $badge.find('.pending-count').text(count);
                        } else {
                            $menuLink.append(' <span class="awaiting-mod count-' + count + '"><span class="pending-count">' + count + '</span></span>');
                        }
                    } else {
                        $badge.remove();
                    }
                });
            };
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Сохранение настроек вложений
     */
    public function handle_save_attachment_settings(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'support_save_attachment_settings_nonce')) {
            wp_send_json_error(array( 'message' => 'Неверный токен безопасности.' ));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Недостаточно прав.' ));
            return;
        }

        Cashback_Support_DB::save_attachment_settings(array(
            'enabled'               => isset($_POST['enabled']) ? absint(wp_unslash($_POST['enabled'])) : 0,
            'max_file_size'         => isset($_POST['max_file_size']) ? absint(wp_unslash($_POST['max_file_size'])) : 5120,
            'max_files_per_message' => isset($_POST['max_files_per_message']) ? absint(wp_unslash($_POST['max_files_per_message'])) : 3,
            'allowed_extensions'    => isset($_POST['allowed_extensions']) && is_string($_POST['allowed_extensions']) ? sanitize_text_field(wp_unslash($_POST['allowed_extensions'])) : '',
        ));

        wp_send_json_success();
    }

    /**
     * Скачивание файла вложения (админ)
     */
    public function handle_admin_download_file(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'support_admin_download_file_nonce')) {
            wp_die('Неверный токен безопасности.', 'Ошибка', array( 'response' => 403 ));
        }

        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав.', 'Ошибка', array( 'response' => 403 ));
        }

        $attachment_id = isset($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;
        if (!$attachment_id) {
            wp_die('Не указан файл.', 'Ошибка', array( 'response' => 400 ));
        }

        // Аудит-лог: фиксируем скачивание вложения администратором
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'admin_file_download',
                get_current_user_id(),
                'attachment',
                $attachment_id
            );
        }

        Cashback_Support_DB::serve_file($attachment_id, get_current_user_id(), true);
    }

    /**
     * Страница настроек вложений
     */
    private function render_settings_page(): void {
        $nonce      = wp_create_nonce('support_save_attachment_settings_nonce');
        $enabled    = Cashback_Support_DB::is_attachments_enabled();
        $max_size   = Cashback_Support_DB::get_max_file_size();
        $max_files  = Cashback_Support_DB::get_max_files_per_message();
        $extensions = implode(',', Cashback_Support_DB::get_allowed_extensions());

        ?>
        <h1 class="wp-heading-inline">
            <a href="<?php echo esc_url(admin_url('admin.php?page=cashback-support')); ?>">&larr; Назад к тикетам</a>
            &nbsp;|&nbsp; Настройки вложений
        </h1>
        <hr class="wp-header-end">

        <?php $this->render_admin_notice_container(); ?>

        <table class="form-table" id="attachment-settings-form">
            <tr>
                <th>Вложения включены</th>
                <td>
                    <label>
                        <input type="checkbox" id="att-enabled" <?php checked($enabled); ?>>
                        Разрешить прикреплять файлы к тикетам
                    </label>
                </td>
            </tr>
            <tr>
                <th>Макс. размер файла (КБ)</th>
                <td>
                    <input type="number" id="att-max-size" value="<?php echo absint($max_size); ?>" min="1" max="51200" class="small-text">
                    <p class="description">Текущий лимит PHP upload_max_filesize: <?php echo esc_html(ini_get('upload_max_filesize') ?: 'не определен'); ?></p>
                </td>
            </tr>
            <tr>
                <th>Макс. файлов на сообщение</th>
                <td>
                    <input type="number" id="att-max-files" value="<?php echo absint($max_files); ?>" min="1" max="10" class="small-text">
                </td>
            </tr>
            <tr>
                <th>Допустимые расширения</th>
                <td>
                    <input type="text" id="att-extensions" value="<?php echo esc_attr($extensions); ?>" class="regular-text">
                    <p class="description">Через запятую, без точки. Пример: jpg,png,pdf,txt</p>
                </td>
            </tr>
        </table>
        <p>
            <button type="button" class="button button-primary" id="save-attachment-settings">Сохранить настройки</button>
        </p>

        <script>
        jQuery(document).ready(function($) {
            $('#save-attachment-settings').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Сохранение...');

                $.post(ajaxurl, {
                    action: 'support_save_attachment_settings',
                    nonce: '<?php echo esc_js($nonce); ?>',
                    enabled: $('#att-enabled').is(':checked') ? 1 : 0,
                    max_file_size: $('#att-max-size').val(),
                    max_files_per_message: $('#att-max-files').val(),
                    allowed_extensions: $('#att-extensions').val()
                }, function(response) {
                    if (response.success) {
                        showAdminNotice('success', 'Настройки сохранены');
                    } else {
                        showAdminNotice('error', response.data.message || 'Ошибка сохранения');
                    }
                    btn.prop('disabled', false).text('Сохранить настройки');
                }).fail(function() {
                    showAdminNotice('error', 'Ошибка сервера');
                    btn.prop('disabled', false).text('Сохранить настройки');
                });
            });
        });
        </script>
        <?php
    }

    // ========= Утилиты =========

    /**
     * Отправка email уведомления пользователю
     */
    private function send_user_notification( int $ticket_id, string $subject, int $user_id, string $admin_message ): void {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $ticket_number = Cashback_Support_DB::format_ticket_number($ticket_id);
        $email_subject = sprintf('Ответ на тикет %s: %s', $ticket_number, $subject);

        $account_url = '';
        if (function_exists('wc_get_account_endpoint_url')) {
            $account_url = wc_get_account_endpoint_url('cashback-support');
        }

        $body = sprintf(
            "Здравствуйте, %s!\n\nВы получили ответ на ваш тикет %s «%s».\n\nОтвет:\n%s\n\n%s",
            $user->display_name,
            $ticket_number,
            $subject,
            wp_trim_words($admin_message, 100, '...'),
            $account_url ? "Просмотреть: {$account_url}" : ''
        );

        wp_mail($user->user_email, $email_subject, $body);
    }

    private function get_priority_label( string $priority ): string {
        $labels = array(
            'urgent'     => 'Срочный',
            'normal'     => 'Обычный',
            'not_urgent' => 'Не срочный',
        );
        return $labels[ $priority ] ?? $priority;
    }

    private function get_status_label( string $status ): string {
        $labels = array(
            'open'     => 'Открыт',
            'answered' => 'Отвечен',
            'closed'   => 'Закрыт',
        );
        return $labels[ $status ] ?? $status;
    }

    private function get_status_css_class( string $status ): string {
        $classes = array(
            'open'     => 'status-open',
            'answered' => 'status-answered',
            'closed'   => 'status-closed',
        );
        return $classes[ $status ] ?? '';
    }

    private function get_priority_css_class( string $priority ): string {
        $classes = array(
            'urgent'     => 'priority-urgent',
            'normal'     => 'priority-normal',
            'not_urgent' => 'priority-not_urgent',
        );
        return $classes[ $priority ] ?? '';
    }
}

// Инициализируем класс в админке
if (is_admin()) {
    new Cashback_Support_Admin();
}
