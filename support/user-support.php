<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс поддержки в кабинете пользователя (WooCommerce My Account)
 */
class Cashback_User_Support {

    private const PER_PAGE          = 10;
    private const MAX_ALLOWED_PAGES = 1000;

    private static ?self $instance = null;
    private string $tickets_table;
    private string $messages_table;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->tickets_table  = $wpdb->prefix . 'cashback_support_tickets';
        $this->messages_table = $wpdb->prefix . 'cashback_support_messages';

        // Отложенный flush rewrite rules после отключения модуля
        add_action('init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 999);

        // Не регистрируем хуки если модуль выключен
        if (!Cashback_Support_DB::is_module_enabled()) {
            return;
        }

        add_action('init', array( $this, 'register_endpoint' ));
        add_filter('query_vars', array( $this, 'add_query_vars' ));
        add_filter('woocommerce_account_menu_items', array( $this, 'add_menu_item' ));
        add_action('woocommerce_account_cashback-support_endpoint', array( $this, 'endpoint_content' ));
        add_action('wp_enqueue_scripts', array( $this, 'enqueue_scripts' ));
        add_action('wp_footer', array( $this, 'render_menu_badge' ));

        // AJAX обработчики
        add_action('wp_ajax_support_create_ticket', array( $this, 'handle_create_ticket' ));
        add_action('wp_ajax_support_user_reply', array( $this, 'handle_user_reply' ));
        add_action('wp_ajax_support_user_close_ticket', array( $this, 'handle_close_ticket' ));
        add_action('wp_ajax_support_load_ticket', array( $this, 'handle_load_ticket' ));
        add_action('wp_ajax_support_download_file', array( $this, 'handle_download_file' ));
        add_action('wp_ajax_support_load_tickets_page', array( $this, 'handle_load_tickets_page' ));
    }

    /**
     * Регистрация endpoint
     */
    public function register_endpoint(): void {
        add_rewrite_endpoint('cashback-support', EP_ROOT | EP_PAGES);
    }

    /**
     * Добавление query vars
     */
    public function add_query_vars( array $vars ): array {
        $vars[] = 'cashback-support';
        return $vars;
    }

    /**
     * Добавление пункта меню "Поддержка" в My Account
     */
    public function add_menu_item( array $items ): array {
        if (isset($items['customer-logout'])) {
            $logout = $items['customer-logout'];
            unset($items['customer-logout']);
            $items['cashback-support'] = 'Поддержка';
            $items['customer-logout']  = $logout;
        } else {
            $items['cashback-support'] = 'Поддержка';
        }
        return $items;
    }

    /**
     * Подключение скриптов
     */
    public function enqueue_scripts(): void {
        if (!is_user_logged_in() || is_admin()) {
            return;
        }

        $is_account_page = function_exists('is_account_page') && is_account_page();
        if (!$is_account_page) {
            return;
        }

        wp_enqueue_style(
            'cashback-user-support-css',
            plugins_url('assets/css/user-support.css', __FILE__),
            array(),
            '1.1.0'
        );

        wp_enqueue_script(
            'dompurify',
            plugins_url('assets/js/purify.min.js', __FILE__),
            array(),
            '3.3.2',
            true
        );

        wp_enqueue_script(
            'cashback-safe-html',
            plugins_url('assets/js/safe-html.js', __FILE__),
            array( 'dompurify' ),
            '1.0.0',
            true
        );

        wp_enqueue_script(
            'cashback-user-support',
            plugins_url('assets/js/user-support.js', __FILE__),
            array( 'jquery', 'cashback-safe-html' ),
            '1.1.0',
            true
        );

        wp_localize_script('cashback-user-support', 'cashback_support', array(
            'ajax_url'              => admin_url('admin-ajax.php'),
            'create_nonce'          => wp_create_nonce('support_create_ticket_nonce'),
            'reply_nonce'           => wp_create_nonce('support_user_reply_nonce'),
            'close_nonce'           => wp_create_nonce('support_close_ticket_nonce'),
            'load_nonce'            => wp_create_nonce('support_load_ticket_nonce'),
            'download_nonce'        => wp_create_nonce('support_download_file_nonce'),
            'tickets_page_nonce'    => wp_create_nonce('support_load_tickets_page_nonce'),
            'attachments_enabled'   => Cashback_Support_DB::is_attachments_enabled() ? 1 : 0,
            'max_file_size_kb'      => Cashback_Support_DB::get_max_file_size(),
            'max_files_per_message' => Cashback_Support_DB::get_max_files_per_message(),
            'allowed_extensions'    => implode(',', Cashback_Support_DB::get_allowed_extensions()),
        ));
    }

    /**
     * Вывод бейджа непрочитанных ответов в меню My Account через CSS ::after
     */
    public function render_menu_badge(): void {
        if (!is_user_logged_in() || is_admin()) {
            return;
        }

        if (!function_exists('is_account_page') || !is_account_page()) {
            return;
        }

        $count = Cashback_Support_DB::get_unread_admin_replies_count(get_current_user_id());
        if ($count <= 0) {
            return;
        }

        ?>
        <style id="cashback-support-menu-badge-style">
            .woocommerce-MyAccount-navigation-link--cashback-support a::after {
                content: '<?php echo esc_js((string) absint($count)); ?>';
                display: inline-block;
                min-width: 18px;
                height: 18px;
                line-height: 18px;
                padding: 0 5px;
                border-radius: 50%;
                background: #f44336;
                color: #fff !important;
                font-size: 11px;
                font-weight: bold;
                text-align: center;
                margin-left: 6px;
                vertical-align: middle;
            }
        </style>
        <?php
    }

    /**
     * Рендеринг содержимого endpoint
     */
    public function endpoint_content(): void {
        if (!is_user_logged_in()) {
            echo '<p>Для доступа к поддержке необходимо войти в аккаунт.</p>';
            return;
        }

        // Проверка статуса "banned"
        $user_id = get_current_user_id();
        if (Cashback_User_Status::is_user_banned($user_id)) {
            $ban_info = Cashback_User_Status::get_ban_info($user_id);
            echo '<h2>Поддержка</h2>';
            echo '<div class="woocommerce-message woocommerce-error" role="alert">';
            echo esc_html(Cashback_User_Status::get_banned_message($ban_info));
            echo '</div>';
            return;
        }

        ?>
        <h2>Поддержка</h2>

        <!-- Вкладки -->
        <?php $unread_count = Cashback_Support_DB::get_unread_admin_replies_count(get_current_user_id()); ?>
        <div class="cashback-support-tabs">
            <button type="button" class="cashback-support-tab active" data-tab="new">Новый тикет</button>
            <button type="button" class="cashback-support-tab" data-tab="history">История тикетов
            <?php
            if ($unread_count > 0) :
?>
<span class="support-tab-badge" id="support-tab-unread-badge"><?php echo absint($unread_count); ?></span><?php endif; ?></button>
        </div>

        <!-- Вкладка: Новый тикет -->
        <div class="cashback-support-tab-content active" id="tab-new">
            <?php $this->render_create_form(); ?>
        </div>

        <!-- Вкладка: История тикетов -->
        <div class="cashback-support-tab-content" id="tab-history" style="display: none;">
            <?php $this->render_tickets_list(); ?>
        </div>

        <!-- Область просмотра тикета -->
        <div id="support-ticket-detail" style="display: none;">
            <p><a href="#" id="support-back-to-list">&larr; Назад к списку</a></p>
            <div id="support-ticket-detail-content"></div>
        </div>

        <?php
    }

    /**
     * Рендеринг списка тикетов пользователя
     */
    private function render_tickets_list(): void {
        $user_id     = get_current_user_id();
        $per_page    = self::PER_PAGE;
        $total       = $this->get_total_tickets($user_id);
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
        $total_pages = min($total_pages, self::MAX_ALLOWED_PAGES);

        $tickets = $this->get_tickets($user_id, $per_page, 0);

        echo '<p style="color: #666; font-size: 0.9em; margin-bottom: 15px;">Все закрытые тикеты автоматически удаляются через месяц!</p>';

        echo '<div id="support-tickets-container">';
        if (empty($tickets)) {
            echo '<p>У вас пока нет тикетов. Создайте новый тикет, чтобы связаться с поддержкой.</p>';
        } else {
            $this->render_tickets_rows($tickets);
        }
        echo '</div>';

        echo '<div id="support-pagination">';
        if ($total_pages > 1) {
            $this->render_pagination(1, $total_pages);
        }
        echo '</div>';
    }

    /**
     * Получить тикеты с пагинацией.
     *
     * @return object[]
     */
    private function get_tickets( int $user_id, int $limit, int $offset ): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.*,
                (SELECT COUNT(*) FROM `{$this->messages_table}` m WHERE m.ticket_id = t.id AND m.is_admin = 1 AND m.is_read = 0) as unread_count
             FROM `{$this->tickets_table}` t
             WHERE t.user_id = %d
             ORDER BY (unread_count > 0) DESC, t.created_at DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        ));
    }

    /**
     * Получить общее количество тикетов пользователя.
     */
    private function get_total_tickets( int $user_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$this->tickets_table}` WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Рендеринг строк тикетов.
     *
     * @param object[] $tickets
     */
    private function render_tickets_rows( array $tickets ): void {
        echo '<div id="support-tickets-list">';
        foreach ($tickets as $ticket) {
            $ticket_number  = Cashback_Support_DB::format_ticket_number((int) $ticket->id);
            $priority_label = $this->get_priority_label($ticket->priority);
            $status_label   = $this->get_status_label($ticket->status);
            $date           = date_i18n('d.m.Y H:i', strtotime($ticket->created_at));

            $has_unread = (int) $ticket->unread_count > 0;
            ?>
            <div class="support-ticket-row<?php echo $has_unread ? ' has-unread' : ''; ?>" data-ticket-id="<?php echo (int) $ticket->id; ?>">
                <div class="support-ticket-info">
                    <strong>
                        <?php echo esc_html($ticket_number . ' — ' . $ticket->subject); ?>
                        <?php if ($has_unread) : ?>
                            <span class="support-unread-badge"><?php echo (int) $ticket->unread_count; ?></span>
                        <?php endif; ?>
                    </strong>
                    <div class="support-ticket-meta">
                        <span class="support-badge support-badge-<?php echo esc_attr($ticket->status); ?>"><?php echo esc_html($status_label); ?></span>
                        <span class="support-badge support-badge-<?php echo esc_attr($ticket->priority); ?>"><?php echo esc_html($priority_label); ?></span>
                        <span><?php echo esc_html($date); ?></span>
                    </div>
                </div>
            </div>
            <?php
        }
        echo '</div>';
    }

    /**
     * Рендеринг пагинации (аналогично CashbackHistory).
     */
    private function render_pagination( int $current_page, int $total_pages ): void {
        if ($total_pages <= 1) {
            return;
        }

        $range = 2;
        $edge  = 2;

        $pages = array();
        for ($i = 1; $i <= min($edge, $total_pages); $i++) {
            $pages[] = $i;
        }
        for ($i = max(1, $current_page - $range); $i <= min($total_pages, $current_page + $range); $i++) {
            $pages[] = $i;
        }
        for ($i = max(1, $total_pages - $edge + 1); $i <= $total_pages; $i++) {
            $pages[] = $i;
        }

        $pages = array_unique($pages);
        sort($pages);

        echo '<nav class="woocommerce-pagination">';
        echo '<ul class="page-numbers">';

        if ($current_page > 1) {
            echo '<li><a href="#" class="page-numbers prev" data-page="' . esc_attr((string) ( $current_page - 1 )) . '">&lsaquo;</a></li>';
        }

        $prev = 0;
        foreach ($pages as $page) {
            if ($prev && $page - $prev > 1) {
                echo '<li><span class="page-numbers dots">&hellip;</span></li>';
            }
            $class = ( $page === $current_page ) ? 'current' : '';
            echo '<li><a href="#" class="page-numbers ' . esc_attr($class) . '" data-page="' . esc_attr((string) $page) . '">' . esc_html((string) $page) . '</a></li>';
            $prev = $page;
        }

        if ($current_page < $total_pages) {
            echo '<li><a href="#" class="page-numbers next" data-page="' . esc_attr((string) ( $current_page + 1 )) . '">&rsaquo;</a></li>';
        }

        echo '</ul>';
        echo '</nav>';
    }

    /**
     * Рендеринг формы создания тикета
     */
    private function render_create_form(): void {
        ?>
        <div id="support-create-alert" style="display: none;"></div>

        <form id="support-create-form" method="post" novalidate enctype="multipart/form-data" data-cb-protected="1">
            <div class="support-form-group">
                <label for="support-subject">Тема</label>
                <input type="text" id="support-subject" name="subject" maxlength="255" placeholder="Опишите тему обращения">
            </div>
            <div class="support-form-group">
                <label for="support-priority">Срочность</label>
                <select id="support-priority" name="priority">
                    <option value="" disabled selected>Выберите срочность</option>
                    <option value="not_urgent">Не срочный</option>
                    <option value="normal">Обычный</option>
                    <option value="urgent">Срочный</option>
                </select>
            </div>
            <div class="support-form-group">
                <label for="support-message">Сообщение</label>
                <textarea id="support-message" name="message" maxlength="5000" placeholder="Опишите вашу проблему или вопрос подробно..."></textarea>
            </div>
            <?php if (Cashback_Support_DB::is_attachments_enabled()) : ?>
            <div class="support-form-group">
                <label for="support-files">Прикрепить файлы</label>
                <input type="file" id="support-files" name="support_files[]" multiple
                        accept="<?php echo esc_attr('.' . implode(',.', Cashback_Support_DB::get_allowed_extensions())); ?>">
                <div class="support-files-info">
                    Макс. <?php echo absint(Cashback_Support_DB::get_max_files_per_message()); ?> файлов,
                    до <?php echo esc_html(number_format_i18n(Cashback_Support_DB::get_max_file_size())); ?> КБ каждый.
                    Допустимые форматы: <?php echo esc_html(implode(', ', Cashback_Support_DB::get_allowed_extensions())); ?>
                </div>
                <div id="support-files-list" class="support-files-preview"></div>
            </div>
            <?php endif; ?>
            <?php
            if (class_exists('Cashback_Captcha')) {
echo Cashback_Captcha::render_container('cb-captcha-support'); }
?>
            <button type="submit" class="support-btn support-btn-primary" id="support-submit-btn">Отправить</button>
        </form>
        <?php
    }

    // ========= AJAX обработчики =========

    /**
     * Создание нового тикета
     */
    public function handle_create_ticket(): void {
        if (!check_ajax_referer('support_create_ticket_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Ошибка при отправке, попробуйте еще раз' ));
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(array( 'message' => 'Ошибка при отправке, попробуйте еще раз' ));
            return;
        }

        global $wpdb;

        $user_id = get_current_user_id();

        // Проверка статуса "banned"
        if (Cashback_User_Status::is_user_banned($user_id)) {
            wp_send_json_error(array( 'message' => 'Создание тикетов заблокировано для вашего аккаунта.' ));
            return;
        }

        $subject  = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $priority = sanitize_text_field(wp_unslash($_POST['priority'] ?? 'not_urgent'));
        $message  = sanitize_textarea_field($_POST['message'] ?? '');

        if (empty($subject) || empty($priority) || empty($message)) {
            wp_send_json_error(array( 'message' => 'Заполните все обязательные поля.' ));
            return;
        }

        if (mb_strlen($subject) > 255) {
            wp_send_json_error(array( 'message' => 'Тема слишком длинная (максимум 255 символов).' ));
            return;
        }

        if (mb_strlen($message) > 5000) {
            wp_send_json_error(array( 'message' => 'Сообщение слишком длинное (максимум 5000 символов).' ));
            return;
        }

        // Защита от спама: максимум 5 тикетов в час (проверка через БД — атомарна)
        $tickets_table_rl = $wpdb->prefix . 'cashback_support_tickets';
        $recent_tickets   = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$tickets_table_rl}` WHERE user_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $user_id
        ));
        if ($recent_tickets >= 5) {
            wp_send_json_error(array( 'message' => 'Слишком много тикетов. Попробуйте позже.' ));
            return;
        }

        if (!in_array($priority, array( 'urgent', 'normal', 'not_urgent' ), true)) {
            wp_send_json_error(array( 'message' => 'Выберите срочность.' ));
            return;
        }

        // Вставляем тикет и первое сообщение в транзакции
        $wpdb->query('START TRANSACTION');

        $inserted = $wpdb->insert(
            $this->tickets_table,
            array(
                'user_id'  => $user_id,
                'subject'  => $subject,
                'priority' => $priority,
                'status'   => 'open',
            ),
            array( '%d', '%s', '%s', '%s' )
        );

        if (!$inserted) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Ошибка при отправке, попробуйте еще раз' ));
            return;
        }

        $ticket_id = (int) $wpdb->insert_id;

        // Вставляем первое сообщение
        $message_inserted = $wpdb->insert(
            $this->messages_table,
            array(
                'ticket_id' => $ticket_id,
                'user_id'   => $user_id,
                'message'   => $message,
                'is_admin'  => 0,
                'is_read'   => 0,
            ),
            array( '%d', '%d', '%s', '%d', '%d' )
        );

        if (!$message_inserted) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => 'Ошибка при отправке, попробуйте еще раз' ));
            return;
        }

        $message_id = (int) $wpdb->insert_id;

        // Обработка вложений
        if (Cashback_Support_DB::is_attachments_enabled() && !empty($_FILES['support_files']['name'][0])) {
            $files_count = count($_FILES['support_files']['name']);
            $max_files   = Cashback_Support_DB::get_max_files_per_message();

            if ($files_count > $max_files) {
                $wpdb->query('ROLLBACK');
                Cashback_Support_DB::delete_ticket_files($ticket_id);
                wp_send_json_error(array( 'message' => sprintf('Максимум %d файлов.', $max_files) ));
                return;
            }

            for ($i = 0; $i < $files_count; $i++) {
                if ($_FILES['support_files']['error'][ $i ] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $single_file = array(
                    'name'     => $_FILES['support_files']['name'][ $i ],
                    'type'     => $_FILES['support_files']['type'][ $i ],
                    'tmp_name' => $_FILES['support_files']['tmp_name'][ $i ],
                    'error'    => $_FILES['support_files']['error'][ $i ],
                    'size'     => $_FILES['support_files']['size'][ $i ],
                );

                $result = Cashback_Support_DB::handle_file_upload($single_file, $ticket_id, $message_id, $user_id);
                if (is_string($result)) {
                    $wpdb->query('ROLLBACK');
                    Cashback_Support_DB::delete_ticket_files($ticket_id);
                    wp_send_json_error(array( 'message' => $result ));
                    return;
                }
            }
        }

        $wpdb->query('COMMIT');

        // Отправляем email администратору через модуль уведомлений
        if (has_action('cashback_notification_ticket_admin_alert')) {
            do_action('cashback_notification_ticket_admin_alert', $ticket_id, 'new_ticket', $subject);
        } else {
            $this->send_admin_notification($ticket_id, 'new_ticket', $subject);
        }

        $ticket_number  = Cashback_Support_DB::format_ticket_number($ticket_id);
        $priority_label = $this->get_priority_label($priority);
        $date           = wp_date('d.m.Y H:i');

        $ticket_html = sprintf(
            '<div class="support-ticket-row" data-ticket-id="%d">
                <div class="support-ticket-info">
                    <strong>%s</strong>
                    <div class="support-ticket-meta">
                        <span class="support-badge support-badge-open">Открыт</span>
                        <span class="support-badge support-badge-%s">%s</span>
                        <span>%s</span>
                    </div>
                </div>
            </div>',
            $ticket_id,
            esc_html($ticket_number . ' — ' . $subject),
            esc_attr($priority),
            esc_html($priority_label),
            esc_html($date)
        );

        wp_send_json_success(array(
            'message'       => 'Ваше сообщение отправлено, мы уже готовим ответ',
            'ticket_id'     => $ticket_id,
            'ticket_number' => $ticket_number,
            'ticket_html'   => $ticket_html,
        ));
    }

    /**
     * Ответ пользователя на тикет
     */
    public function handle_user_reply(): void {
        if (!check_ajax_referer('support_user_reply_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Ошибка при отправке, попробуйте еще раз' ));
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(array( 'message' => 'Ошибка при отправке, попробуйте еще раз' ));
            return;
        }

        global $wpdb;

        $user_id = get_current_user_id();

        // Проверка статуса "banned"
        if (Cashback_User_Status::is_user_banned($user_id)) {
            wp_send_json_error(array( 'message' => 'Ответы на тикеты заблокированы для вашего аккаунта.' ));
            return;
        }

        $ticket_id = absint($_POST['ticket_id'] ?? 0);
        $message   = sanitize_textarea_field($_POST['message'] ?? '');

        if (!$ticket_id || empty($message)) {
            wp_send_json_error(array( 'message' => 'Ошибка при отправке, попробуйте еще раз' ));
            return;
        }

        if (mb_strlen($message) > 5000) {
            wp_send_json_error(array( 'message' => 'Сообщение слишком длинное (максимум 5000 символов).' ));
            return;
        }

        // Защита от спама: максимум 20 ответов в час (проверка через БД — атомарна)
        $messages_table_rl = $wpdb->prefix . 'cashback_support_messages';
        $recent_replies    = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$messages_table_rl}` WHERE user_id = %d AND is_admin = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $user_id
        ));
        if ($recent_replies >= 20) {
            wp_send_json_error(array( 'message' => 'Слишком много сообщений. Попробуйте позже.' ));
            return;
        }

        // 🔒 НАЧИНАЕМ ТРАНЗАКЦИЮ для атомарности операций
        $wpdb->query('START TRANSACTION');

        try {
            // БЛОКИРУЕМ тикет с FOR UPDATE
            $ticket = $wpdb->get_row($wpdb->prepare(
                "SELECT id, user_id, subject, status
                 FROM `{$this->tickets_table}`
                 WHERE id = %d AND user_id = %d
                 FOR UPDATE",
                $ticket_id,
                $user_id
            ));

            if (!$ticket) {
                throw new Exception('Тикет не найден или не принадлежит пользователю');
            }

            if ($ticket->status === 'closed') {
                throw new Exception('Невозможно ответить на закрытый тикет. Создайте новый тикет.');
            }

            // Вставляем сообщение
            $message_inserted = $wpdb->insert(
                $this->messages_table,
                array(
                    'ticket_id' => $ticket_id,
                    'user_id'   => $user_id,
                    'message'   => $message,
                    'is_admin'  => 0,
                    'is_read'   => 0,
                ),
                array( '%d', '%d', '%s', '%d', '%d' )
            );

            if (!$message_inserted) {
                throw new Exception('Ошибка при вставке сообщения');
            }

            $message_id = (int) $wpdb->insert_id;

            // Обработка вложений
            if (Cashback_Support_DB::is_attachments_enabled() && !empty($_FILES['support_files']['name'][0])) {
                $files_count = count($_FILES['support_files']['name']);
                $max_files   = Cashback_Support_DB::get_max_files_per_message();

                if ($files_count > $max_files) {
                    throw new Exception(sprintf('Максимум %d файлов.', $max_files));
                }

                for ($i = 0; $i < $files_count; $i++) {
                    if ($_FILES['support_files']['error'][ $i ] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }

                    $single_file = array(
                        'name'     => $_FILES['support_files']['name'][ $i ],
                        'type'     => $_FILES['support_files']['type'][ $i ],
                        'tmp_name' => $_FILES['support_files']['tmp_name'][ $i ],
                        'error'    => $_FILES['support_files']['error'][ $i ],
                        'size'     => $_FILES['support_files']['size'][ $i ],
                    );

                    $result = Cashback_Support_DB::handle_file_upload($single_file, (int) $ticket_id, $message_id, $user_id);
                    if (is_string($result)) {
                        throw new Exception($result);
                    }
                }
            }

            // Обновляем статус тикета на "open"
            $ticket_updated = $wpdb->update(
                $this->tickets_table,
                array(
                    'status'     => 'open',
                    'updated_at' => current_time('mysql'),
                ),
                array( 'id' => $ticket_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );

            if ($ticket_updated === false) {
                throw new Exception('Ошибка при обновлении статуса тикета');
            }

            // ✅ ФИКСИРУЕМ транзакцию
            $wpdb->query('COMMIT');

        } catch (Exception $e) {
            // ❌ ОТКАТЫВАЕМ транзакцию при ошибке
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array( 'message' => $e->getMessage() ));
            return;
        }

        // ✉️ Email ПОСЛЕ транзакции (некритичная операция)
        if (has_action('cashback_notification_ticket_admin_alert')) {
            do_action('cashback_notification_ticket_admin_alert', $ticket_id, 'user_reply', $ticket->subject);
        } else {
            $this->send_admin_notification($ticket_id, 'user_reply', $ticket->subject);
        }

        $user = wp_get_current_user();

        // Получаем вложения для нового сообщения
        $msg_attachments = Cashback_Support_DB::get_attachments_for_messages(array( $message_id ));
        $attachments     = $msg_attachments[ $message_id ] ?? array();

        wp_send_json_success(array(
            'html' => $this->render_message_html($message, $user->user_login, false, current_time('mysql'), $attachments),
        ));
    }

    /**
     * Закрытие тикета пользователем
     */
    public function handle_close_ticket(): void {
        if (!check_ajax_referer('support_close_ticket_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Ошибка при выполнении, попробуйте еще раз' ));
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(array( 'message' => 'Ошибка при выполнении, попробуйте еще раз' ));
            return;
        }

        // Rate limiting: максимум 10 запросов в минуту
        $user_id    = get_current_user_id();
        $rate_key   = 'cb_close_ticket_rate_' . $user_id;
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 10) {
            wp_send_json_error(array( 'message' => 'Слишком много запросов. Попробуйте через минуту.' ));
            return;
        }
        set_transient($rate_key, $rate_count + 1, MINUTE_IN_SECONDS);

        global $wpdb;

        // Проверка статуса "banned"
        if (Cashback_User_Status::is_user_banned($user_id)) {
            wp_send_json_error(array( 'message' => 'Управление тикетами заблокировано для вашего аккаунта.' ));
            return;
        }

        $ticket_id = absint($_POST['ticket_id'] ?? 0);

        if (!$ticket_id) {
            wp_send_json_error(array( 'message' => 'Ошибка при выполнении, попробуйте еще раз' ));
            return;
        }

        // Проверяем что тикет принадлежит пользователю
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM `{$this->tickets_table}` WHERE id = %d AND user_id = %d",
            $ticket_id,
            $user_id
        ));

        if (!$ticket) {
            wp_send_json_error(array( 'message' => 'Ошибка при выполнении, попробуйте еще раз' ));
            return;
        }

        if ($ticket->status === 'closed') {
            wp_send_json_error(array( 'message' => 'Тикет уже закрыт.' ));
            return;
        }

        $wpdb->update(
            $this->tickets_table,
            array(
                'status'     => 'closed',
                'closed_at'  => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array( 'id' => $ticket_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        wp_send_json_success(array( 'message' => 'Тикет закрыт.' ));
    }

    /**
     * Загрузка переписки тикета
     */
    public function handle_load_ticket(): void {
        if (!check_ajax_referer('support_load_ticket_nonce', 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'Ошибка загрузки' ));
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(array( 'message' => 'Ошибка загрузки' ));
            return;
        }

        // Rate limiting: максимум 20 запросов в минуту
        $user_id    = get_current_user_id();
        $rate_key   = 'cb_load_ticket_rate_' . $user_id;
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 20) {
            wp_send_json_error(array( 'message' => 'Слишком много запросов. Попробуйте через минуту.' ));
            return;
        }
        set_transient($rate_key, $rate_count + 1, MINUTE_IN_SECONDS);

        global $wpdb;

        $ticket_id = absint($_POST['ticket_id'] ?? 0);

        if (!$ticket_id) {
            wp_send_json_error(array( 'message' => 'Ошибка загрузки' ));
            return;
        }

        // Проверяем что тикет принадлежит пользователю
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$this->tickets_table}` WHERE id = %d AND user_id = %d",
            $ticket_id,
            $user_id
        ));

        if (!$ticket) {
            wp_send_json_error(array( 'message' => 'Тикет не найден' ));
            return;
        }

        // Помечаем сообщения админа как прочитанные
        $wpdb->update(
            $this->messages_table,
            array( 'is_read' => 1 ),
            array(
				'ticket_id' => $ticket_id,
				'is_admin'  => 1,
				'is_read'   => 0,
			),
            array( '%d' ),
            array( '%d', '%d', '%d' )
        );

        // Получаем сообщения
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, u.user_login
             FROM `{$this->messages_table}` m
             LEFT JOIN `{$wpdb->users}` u ON m.user_id = u.ID
             WHERE m.ticket_id = %d
             ORDER BY m.created_at ASC",
            $ticket_id
        ));

        $is_closed = ( $ticket->status === 'closed' );

        // Batch-загрузка вложений для всех сообщений
        $message_ids     = array_map(function ( $m ) {
            return (int) $m->id;
        }, $messages);
        $attachments_map = Cashback_Support_DB::get_attachments_for_messages($message_ids);

        // Генерируем HTML
        $html = '';

        // Заголовок
        $ticket_number = Cashback_Support_DB::format_ticket_number($ticket_id);
        $html         .= '<h3>' . esc_html($ticket_number . ' — ' . $ticket->subject) . '</h3>';
        $html         .= '<p>';
        $html         .= '<span class="support-badge support-badge-' . esc_attr($ticket->status) . '">' . esc_html($this->get_status_label($ticket->status)) . '</span> ';
        $html         .= '<span class="support-badge support-badge-' . esc_attr($ticket->priority) . '">' . esc_html($this->get_priority_label($ticket->priority)) . '</span> ';
        $html         .= '<span style="color: #666;">' . esc_html(date_i18n('d.m.Y H:i', strtotime($ticket->created_at))) . '</span>';
        $html         .= '</p>';

        // Контейнер уведомлений
        $html .= '<div id="support-detail-alert" style="display:none;"></div>';

        // Сообщения
        $html .= '<div id="support-messages-list">';
        foreach ($messages as $msg) {
            $is_admin        = (int) $msg->is_admin === 1;
            $msg_attachments = $attachments_map[ (int) $msg->id ] ?? array();
            $html           .= $this->render_message_html(
                $msg->message,
                $is_admin ? 'Администратор' : ( $msg->user_login ?? 'Вы' ),
                $is_admin,
                $msg->created_at,
                $msg_attachments
            );
        }
        $html .= '</div>';

        // Форма ответа (если тикет не закрыт)
        if (!$is_closed) {
            $html .= '<div class="support-ticket-actions">';
            $html .= '<div style="flex: 1;">';
            $html .= '<textarea id="support-reply-message" rows="3" maxlength="5000" style="width: 100%; padding: 8px; border: 1px solid #ddd;" placeholder="Введите ваш ответ..."></textarea>';
            if (Cashback_Support_DB::is_attachments_enabled()) {
                $html .= '<div style="margin-top: 8px;">';
                $html .= '<input type="file" id="support-reply-files" name="support_files[]" multiple accept=".' . esc_attr(implode(',.', Cashback_Support_DB::get_allowed_extensions())) . '">';
                $html .= '<div class="support-files-info">Макс. ' . absint(Cashback_Support_DB::get_max_files_per_message()) . ' файлов, до ' . esc_html(number_format_i18n(Cashback_Support_DB::get_max_file_size())) . ' КБ каждый</div>';
                $html .= '<div id="support-reply-files-list" class="support-files-preview"></div>';
                $html .= '</div>';
            }
            $html .= '<div style="margin-top: 10px;">';
            $html .= '<button type="button" class="support-btn support-btn-primary" id="support-reply-btn" data-ticket-id="' . (int) $ticket_id . '">Ответить</button> ';
            $html .= '<button type="button" class="support-btn support-btn-danger" id="support-close-btn" data-ticket-id="' . (int) $ticket_id . '">Закрыть тикет</button>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        } else {
            $html .= '<p style="color: #666; margin-top: 15px;">Тикет закрыт. Для нового обращения создайте новый тикет.</p>';
        }

        // Возвращаем актуальный счётчик непрочитанных для обновления бейджа в меню
        $unread_total = Cashback_Support_DB::get_unread_admin_replies_count($user_id);

        wp_send_json_success(array(
			'html'         => $html,
			'unread_total' => $unread_total,
		));
    }

    /**
     * AJAX: загрузка страницы тикетов
     */
    public function handle_load_tickets_page(): void {
        if (!isset($_POST['nonce']) || !check_ajax_referer('support_load_tickets_page_nonce', 'nonce', false)) {
            wp_send_json_error('Ошибка безопасности.');
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Вы должны быть авторизованы.');
        }

        // Rate limiting: максимум 30 запросов в минуту
        $rate_key   = 'cb_tickets_page_rate_' . $user_id;
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 30) {
            wp_send_json_error('Слишком много запросов. Попробуйте через минуту.');
        }
        set_transient($rate_key, $rate_count + 1, MINUTE_IN_SECONDS);

        if (!isset($_POST['page'])) {
            wp_send_json_error('Некорректный запрос.');
        }

        $per_page    = self::PER_PAGE;
        $total       = $this->get_total_tickets($user_id);
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
        $total_pages = min($total_pages, self::MAX_ALLOWED_PAGES);

        $page   = intval($_POST['page']);
        $page   = max(1, min($page, $total_pages));
        $offset = ( $page - 1 ) * $per_page;

        $tickets = $this->get_tickets($user_id, $per_page, $offset);

        ob_start();
        if (empty($tickets)) {
            echo '<p>У вас пока нет тикетов.</p>';
        } else {
            $this->render_tickets_rows($tickets);
        }
        $html = ob_get_clean();

        wp_send_json_success(array(
            'html'         => $html,
            'current_page' => $page,
            'total_pages'  => $total_pages,
        ));
    }

    /**
     * Отложенный сброс rewrite rules после отключения модуля
     */
    public static function maybe_flush_rewrite_rules(): void {
        if (get_transient('cashback_support_flush_rules')) {
            delete_transient('cashback_support_flush_rules');
            flush_rewrite_rules();
        }
    }

    // ========= Утилиты =========

    /**
     * Генерация HTML одного сообщения
     *
     * @param object[] $attachments
     */
    private function render_message_html( string $message, string $sender, bool $is_admin, string $date, array $attachments = array() ): string {
        $css_class      = $is_admin ? 'support-message-admin' : 'support-message-user';
        $formatted_date = date_i18n('d.m.Y H:i', strtotime($date));

        $attachments_html = '';
        if (!empty($attachments)) {
            $attachments_html .= '<div class="support-attachments">';
            foreach ($attachments as $att) {
                $icon = self::get_file_icon($att->file_name);
                $size = size_format($att->file_size);

                $attachments_html .= sprintf(
                    '<button type="button" class="support-attachment-link support-download-btn" data-id="%d" style="background:none;border:none;padding:0;cursor:pointer;">%s %s <span class="support-attachment-size">(%s)</span></button>',
                    (int) $att->id,
                    $icon,
                    esc_html($att->file_name),
                    esc_html($size)
                );
            }
            $attachments_html .= '</div>';
        }

        return sprintf(
            '<div class="support-message %s">
                <div class="support-message-header">
                    <strong>%s</strong>
                    <span class="date">%s</span>
                </div>
                <div>%s</div>
                %s
            </div>',
            esc_attr($css_class),
            esc_html($sender),
            esc_html($formatted_date),
            nl2br(esc_html($message)),
            $attachments_html
        );
    }

    /**
     * Иконка по типу файла
     */
    private static function get_file_icon( string $filename ): string {
        $ext   = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $icons = array(
            'pdf'  => '&#128196;',
            'doc'  => '&#128196;',
            'docx' => '&#128196;',
            'jpg'  => '&#128247;',
            'jpeg' => '&#128247;',
            'png'  => '&#128247;',
            'gif'  => '&#128247;',
            'txt'  => '&#128196;',
        );
        return $icons[ $ext ] ?? '&#128206;';
    }

    /**
     * Скачивание файла вложения (пользователь)
     */
    public function handle_download_file(): void {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'support_download_file_nonce')
        ) {
            wp_die('Неверный токен безопасности.', 'Ошибка', array( 'response' => 403 ));
        }

        if (!is_user_logged_in()) {
            wp_die('Требуется авторизация.', 'Ошибка', array( 'response' => 403 ));
        }

        $attachment_id = absint($_POST['id'] ?? 0);
        if (!$attachment_id) {
            wp_die('Не указан файл.', 'Ошибка', array( 'response' => 400 ));
        }

        Cashback_Support_DB::serve_file($attachment_id, get_current_user_id(), false);
    }

    /**
     * Отправка email уведомления администратору
     */
    private function send_admin_notification( int $ticket_id, string $event_type, string $subject ): void {
        $admin_email = get_option('admin_email');
        if (!$admin_email) {
            return;
        }

        $user          = wp_get_current_user();
        $ticket_number = Cashback_Support_DB::format_ticket_number($ticket_id);

        if ($event_type === 'new_ticket') {
            $email_subject = sprintf('Новый тикет %s: %s', $ticket_number, $subject);
        } else {
            $email_subject = sprintf('Новый ответ в тикете %s: %s', $ticket_number, $subject);
        }

        $admin_url = admin_url('admin.php?page=cashback-support&action=view&ticket_id=' . $ticket_id);

        $body = sprintf(
            "Пользователь: %s (%s)\nТема: %s\n\nПросмотреть в админке: %s",
            $user->user_login,
            $user->user_email,
            $subject,
            $admin_url
        );

        wp_mail($admin_email, $email_subject, $body);
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
}
