<?php
/**
 * Admin-UI для управления custom-allowlist'ом исходящих HTTP-запросов.
 *
 * Позволяет администратору (capability 'manage_options') добавлять/удалять
 * хосты, не входящие в baseline (`includes/config/cashback-outbound-allowlist.php`),
 * без правки кода плагина. Каждое изменение пишется в audit-log
 * и рассылается email-уведомлением суперадмину + всем администраторам.
 *
 * Baseline-хосты защищены от удаления через UI.
 *
 * См. ADR Группа 3, commit 2, finding F-12-001.
 *
 * @package CashbackPlugin
 * @since   7.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Admin_Outbound_Allowlist {

    public const OPTION_KEY   = 'cashback_outbound_allowlist_custom';
    public const NONCE_ACTION = 'cashback_outbound_allowlist_nonce';
    public const MAX_CUSTOM   = 50;

    /**
     * Reserved TLD/suffixes — нельзя добавлять такие хосты через UI,
     * чтобы админ-UI нельзя было использовать для обхода SSRF-защиты.
     */
    private const RESERVED_SUFFIXES = array(
        '.local',
        '.localhost',
        '.test',
        '.example',
        '.invalid',
        '.internal',
        '.lan',
        '.intranet',
    );

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_cashback_outbound_allowlist_add', array( $this, 'ajax_add' ));
        add_action('wp_ajax_cashback_outbound_allowlist_remove', array( $this, 'ajax_remove' ));

        // Инвалидация кеша guard'а при изменении опции.
        add_action('updated_option', array( $this, 'maybe_invalidate_guard_cache' ));
        add_action('added_option', array( $this, 'maybe_invalidate_guard_cache' ));
    }

    public function maybe_invalidate_guard_cache( string $option ): void {
        if ($option === self::OPTION_KEY && class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }
    }

    // ================================================================
    // AJAX-хендлеры: обёртки над handle_add / handle_remove.
    // Тесты вызывают handle_* напрямую с подготовленными данными.
    // ================================================================

    public function ajax_add(): void {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(array( 'message' => __('Неверный nonce.', 'cashback-plugin') ));
            return;
        }

        $input = array(
            'host'   => isset($_POST['host']) ? sanitize_text_field(wp_unslash((string) $_POST['host'])) : '',
            'reason' => isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash((string) $_POST['reason'])) : '',
        );

        $this->handle_add($input);
    }

    public function ajax_remove(): void {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(array( 'message' => __('Неверный nonce.', 'cashback-plugin') ));
            return;
        }

        $input = array(
            'host' => isset($_POST['host']) ? sanitize_text_field(wp_unslash((string) $_POST['host'])) : '',
        );

        $this->handle_remove($input);
    }

    // ================================================================
    // Бизнес-логика (отделена для тестируемости).
    // ================================================================

    /**
     * @param array{host?:string,reason?:string} $input
     */
    public function handle_add( array $input ): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ), 403);
            return;
        }

        $host   = strtolower(trim((string) ( $input['host'] ?? '' )));
        $reason = trim((string) ( $input['reason'] ?? '' ));

        $validation = $this->validate_host_for_add($host, $reason);
        if ($validation !== null) {
            wp_send_json_error(array( 'message' => $validation ));
            return;
        }

        $actor_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $entry    = array(
            'host'     => $host,
            'added_by' => $actor_id,
            'added_at' => current_time('mysql'),
            'reason'   => $reason,
        );

        $custom   = $this->get_custom_list();
        $custom[] = $entry;
        update_option(self::OPTION_KEY, $custom);

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }

        $this->write_audit('outbound_allowlist_added', array(
            'host'   => $host,
            'reason' => $reason,
        ));

        $this->send_alert_email('added', $host, $reason, $actor_id);

        wp_send_json_success(array(
            'message' => __('Домен добавлен.', 'cashback-plugin'),
            'hosts'   => $custom,
        ));
    }

    /**
     * @param array{host?:string} $input
     */
    public function handle_remove( array $input ): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => __('Недостаточно прав.', 'cashback-plugin') ), 403);
            return;
        }

        $host = strtolower(trim((string) ( $input['host'] ?? '' )));

        if ($host === '') {
            wp_send_json_error(array( 'message' => __('Не указан домен.', 'cashback-plugin') ));
            return;
        }

        if (in_array($host, $this->get_baseline_hosts(), true)) {
            wp_send_json_error(array( 'message' => __('Baseline-домен нельзя удалить через UI — правьте config-файл плагина.', 'cashback-plugin') ));
            return;
        }

        $custom   = $this->get_custom_list();
        $found    = false;
        $filtered = array();
        foreach ($custom as $entry) {
            if (isset($entry['host']) && strtolower((string) $entry['host']) === $host) {
                $found = true;
                continue;
            }
            $filtered[] = $entry;
        }

        if (!$found) {
            wp_send_json_error(array( 'message' => __('Домен не найден в кастомном списке.', 'cashback-plugin') ));
            return;
        }

        update_option(self::OPTION_KEY, $filtered);

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }

        $actor_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        $this->write_audit('outbound_allowlist_removed', array( 'host' => $host ));

        $this->send_alert_email('removed', $host, '', $actor_id);

        wp_send_json_success(array(
            'message' => __('Домен удалён.', 'cashback-plugin'),
            'hosts'   => $filtered,
        ));
    }

    // ================================================================
    // Валидация host'а перед добавлением.
    // Возвращает NULL если валиден, либо строку с причиной ошибки.
    // ================================================================

    private function validate_host_for_add( string $host, string $reason ): ?string {
        if ($host === '') {
            return __('Домен обязателен.', 'cashback-plugin');
        }

        if (strlen($reason) < 5) {
            return __('Причина должна быть не короче 5 символов.', 'cashback-plugin');
        }

        // Не должно быть пробелов, слэшей, протоколов.
        if (preg_match('/[^a-z0-9.\-]/', $host) === 1) {
            return __('Разрешены только буквы, цифры, точки и дефисы (FQDN).', 'cashback-plugin');
        }

        // Длина / формат FQDN.
        if (strlen($host) > 253) {
            return __('Слишком длинный домен (>253 символов).', 'cashback-plugin');
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return __('Нужен FQDN (минимум один уровень выше TLD).', 'cashback-plugin');
        }
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return __('Некорректная метка домена (пустая или длиннее 63).', 'cashback-plugin');
            }
        }

        // IP-литерал запрещён (через UI только FQDN).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return __('IP-адреса через UI не допускаются. Добавляйте только доменные имена.', 'cashback-plugin');
        }

        // Reserved suffixes.
        foreach (self::RESERVED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return sprintf(
                    /* translators: %s — зарезервированный TLD */
                    __('TLD %s зарезервирован для внутренних сетей.', 'cashback-plugin'),
                    $suffix
                );
            }
        }

        // Дубликат baseline.
        if (in_array($host, $this->get_baseline_hosts(), true)) {
            return __('Этот домен уже в baseline-списке плагина.', 'cashback-plugin');
        }

        // Дубликат custom (case-insensitive).
        foreach ($this->get_custom_list() as $entry) {
            if (isset($entry['host']) && strtolower((string) $entry['host']) === $host) {
                return __('Этот домен уже в кастомном списке.', 'cashback-plugin');
            }
        }

        if (count($this->get_custom_list()) >= self::MAX_CUSTOM) {
            return sprintf(
                /* translators: %d — лимит */
                __('Превышен лимит кастомных доменов (%d). Удалите ненужные.', 'cashback-plugin'),
                self::MAX_CUSTOM
            );
        }

        return null;
    }

    // ================================================================
    // Helpers
    // ================================================================

    /**
     * @return list<array{host:string,added_by?:int,added_at?:string,reason?:string}>
     */
    private function get_custom_list(): array {
        $stored = get_option(self::OPTION_KEY, array());
        if (!is_array($stored)) {
            return array();
        }
        return array_values($stored);
    }

    /**
     * @return string[]
     */
    private function get_baseline_hosts(): array {
        $file = dirname(__DIR__) . '/includes/config/cashback-outbound-allowlist.php';
        if (!file_exists($file)) {
            return array();
        }
        $loaded = require $file;
        if (!is_array($loaded) || !isset($loaded['hosts']) || !is_array($loaded['hosts'])) {
            return array();
        }
        return array_map('strtolower', array_map('strval', $loaded['hosts']));
    }

    private function write_audit( string $action, array $details ): void {
        if (!class_exists('Cashback_Encryption') || !isset($GLOBALS['wpdb'])) {
            return;
        }
        try {
            $actor_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            Cashback_Encryption::write_audit_log($action, $actor_id, 'security', null, $details);
        } catch (\Throwable $e) {
            if (function_exists('error_log')) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback Outbound Allowlist] audit-log error: ' . $e->getMessage());
            }
        }
    }

    private function send_alert_email( string $action, string $host, string $reason, int $actor_id ): void {
        $recipients = $this->get_alert_recipients();
        if (empty($recipients)) {
            return;
        }

        $subject = sprintf(
            '[Cashback Security] %s: %s',
            $action === 'added' ? __('Новый разрешённый домен', 'cashback-plugin') : __('Домен удалён из allowlist', 'cashback-plugin'),
            $host
        );

        $actor_info = $actor_id > 0 ? sprintf('user_id=%d', $actor_id) : 'unknown';
        $ip         = $this->get_request_ip();

        $lines = array(
            /* translators: %s — add or remove action */
            sprintf(__('Действие: %s', 'cashback-plugin'), $action),
            /* translators: %s — FQDN domain */
            sprintf(__('Домен: %s', 'cashback-plugin'), $host),
            /* translators: %s — actor info (user_id=N or 'unknown') */
            sprintf(__('Кем: %s', 'cashback-plugin'), $actor_info),
            /* translators: %s — initiator IP */
            sprintf(__('IP инициатора: %s', 'cashback-plugin'), $ip !== '' ? $ip : 'unknown'),
            /* translators: %s — timestamp */
            sprintf(__('Время: %s', 'cashback-plugin'), current_time('mysql')),
        );
        if ($reason !== '') {
            /* translators: %s — free-form reason supplied by admin */
            $lines[] = sprintf(__('Причина: %s', 'cashback-plugin'), $reason);
        }
        $lines[] = '';
        $lines[] = __('Если это не вы — смените пароль и проверьте audit-log (wp_cashback_audit_log).', 'cashback-plugin');

        wp_mail($recipients, $subject, implode("\n", $lines));
    }

    /**
     * Получить IP инициатора для audit-email. REMOTE_ADDR устанавливается
     * веб-сервером из TCP-соединения и используется только для записи в
     * email-уведомление (не для принятия решений), поэтому допустимо.
     */
    private function get_request_ip(): string {
        // phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
        if (!isset($_SERVER['REMOTE_ADDR'])) {
            return '';
        }
        $raw = sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR']));
        // phpcs:enable WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
        return $raw;
    }

    /**
     * @return string[]
     */
    private function get_alert_recipients(): array {
        $recipients = array();

        $admin_email = (string) get_option('admin_email', '');
        if ($admin_email !== '') {
            $recipients[] = $admin_email;
        }

        $admins = get_users(array(
            'role'   => 'administrator',
            'fields' => array( 'ID', 'user_email' ),
        ));
        foreach ($admins as $user) {
            $email = '';
            if (is_object($user) && isset($user->user_email)) {
                $email = (string) $user->user_email;
            } elseif (is_array($user) && isset($user['user_email'])) {
                $email = (string) $user['user_email'];
            }
            if ($email !== '') {
                $recipients[] = $email;
            }
        }

        $recipients = array_values(array_unique(array_filter(array_map('strtolower', $recipients))));

        $filtered = apply_filters('cashback_outbound_allowlist_alert_recipients', $recipients);
        if (!is_array($filtered)) {
            return $recipients;
        }
        return array_values(array_filter(array_map('strval', $filtered)));
    }

    // ================================================================
    // Рендер страницы
    // ================================================================

    /**
     * Рендер содержимого вкладки «Разрешенные домены API Base URL»
     * на странице «Партнеры» (admin.php?page=cashback-partners&tab=outbound-allowlist).
     *
     * Вызывается из partner/partner-management.php в ветке условного рендера вкладок.
     * НЕ обёрнут в <div class="wrap"> — внешнюю обёртку даёт страница «Партнеры».
     */
    public function render_tab_content(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }

        $baseline = $this->get_baseline_hosts();
        $custom   = $this->get_custom_list();
        ?>
        <div style="margin-top: 20px;">
            <p><?php esc_html_e('SSRF-защита плагина. Любой исходящий HTTP-запрос, хост которого отсутствует в этом списке, будет отклонён. В частности, в поле «API Base URL» партнёра можно вписать только домены из этого allowlist.', 'cashback-plugin'); ?></p>

            <h2><?php esc_html_e('Baseline (встроено в плагин)', 'cashback-plugin'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th><?php esc_html_e('Хост', 'cashback-plugin'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($baseline as $host) : ?>
                    <tr><td><?php echo esc_html($host); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top: 2em;"><?php esc_html_e('Добавленные администраторами', 'cashback-plugin'); ?></h2>
            <table class="wp-list-table widefat fixed striped" id="cb-outbound-custom-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Хост', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Добавлен', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Кем', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Причина', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Действие', 'cashback-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($custom)) : ?>
                    <tr><td colspan="5"><?php esc_html_e('Нет добавленных доменов.', 'cashback-plugin'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($custom as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ( $entry['host'] ?? '' )); ?></td>
                            <td><?php echo esc_html((string) ( $entry['added_at'] ?? '' )); ?></td>
                            <td><?php echo esc_html((string) ( $entry['added_by'] ?? '' )); ?></td>
                            <td><?php echo esc_html((string) ( $entry['reason'] ?? '' )); ?></td>
                            <td>
                                <button type="button" class="button cb-outbound-remove" data-host="<?php echo esc_attr((string) ( $entry['host'] ?? '' )); ?>">
                                    <?php esc_html_e('Удалить', 'cashback-plugin'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top: 2em;"><?php esc_html_e('Добавить домен', 'cashback-plugin'); ?></h2>
            <form id="cb-outbound-add-form" onsubmit="return false;">
                <?php wp_nonce_field(self::NONCE_ACTION, 'cb_outbound_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="cb-outbound-host"><?php esc_html_e('Хост (FQDN)', 'cashback-plugin'); ?></label></th>
                        <td><input type="text" id="cb-outbound-host" name="host" class="regular-text" placeholder="api.example.com" required></td>
                    </tr>
                    <tr>
                        <th><label for="cb-outbound-reason"><?php esc_html_e('Причина', 'cashback-plugin'); ?></label></th>
                        <td><textarea id="cb-outbound-reason" name="reason" rows="3" class="large-text" required minlength="5"></textarea></td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="button" class="button button-primary" id="cb-outbound-add-btn"><?php esc_html_e('Добавить', 'cashback-plugin'); ?></button>
                </p>
            </form>

            <?php
            $limit_text = sprintf(
                /* translators: %d — лимит custom-хостов */
                __('Лимит: %d кастомных доменов. Каждое изменение логируется в audit-log и отправляется email-уведомление всем администраторам.', 'cashback-plugin'),
                self::MAX_CUSTOM
            );
            ?>
            <p class="description"><?php echo esc_html($limit_text); ?></p>
        </div>
        <?php
    }
}

Cashback_Admin_Outbound_Allowlist::get_instance();
