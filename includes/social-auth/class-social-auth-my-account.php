<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Интеграция модуля соц-авторизации в стандартную вкладку WooCommerce
 * «Данные аккаунта» (edit-account, в RU-переводе — «Анкета»).
 *
 * Раньше вкладка жила на отдельном endpoint /my-account/cashback-social/,
 * но из-за отсутствия flush_rewrite_rules() пункт меню вёл на 404.
 * Теперь содержимое (привязки соц-сетей) добавляется второй вкладкой
 * прямо в edit-account через хуки WooCommerce.
 *
 * @since 1.1.0
 */
class Cashback_Social_Auth_My_Account {

    private const TAB_ACCOUNT_ID = 'tab-edit-account';
    private const TAB_SOCIAL_ID  = 'tab-social-links';

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
    }

    public function register_hooks(): void {
        add_action('woocommerce_before_edit_account_form', array( $this, 'render_tabs_open' ));
        add_action('woocommerce_after_edit_account_form', array( $this, 'render_tabs_close_with_social' ));
    }

    /**
     * Включён ли модуль (общий switch) — единая точка проверки.
     */
    private function is_enabled(): bool {
        return (int) get_option('cashback_social_enabled', 0) === 1;
    }

    /**
     * Открыть навигацию вкладок и контейнер первой вкладки (Анкета).
     * WooCommerce-форма edit-account отрисуется ВНУТРИ этого контейнера.
     */
    public function render_tabs_open(): void {
        if (!$this->is_enabled()) {
            return;
        }

        echo '<div class="cashback-tabs">';
        echo '<button type="button" class="cashback-tab active" data-tab="' . esc_attr(self::TAB_ACCOUNT_ID) . '">'
            . esc_html__('Анкета', 'cashback-plugin') . '</button>';
        echo '<button type="button" class="cashback-tab" data-tab="' . esc_attr(self::TAB_SOCIAL_ID) . '">'
            . esc_html__('Привязанные соц. сети', 'cashback-plugin') . '</button>';
        echo '</div>';

        echo '<div class="cashback-tab-content active" id="' . esc_attr(self::TAB_ACCOUNT_ID) . '">';
    }

    /**
     * Закрыть первую вкладку и отрисовать вторую — список привязок соц-сетей.
     */
    public function render_tabs_close_with_social(): void {
        if (!$this->is_enabled()) {
            return;
        }

        echo '</div>'; // #tab-edit-account

        echo '<div class="cashback-tab-content" id="' . esc_attr(self::TAB_SOCIAL_ID) . '">';
        $this->render_social_content();
        echo '</div>'; // #tab-social-links

        $this->maybe_print_autoactivate_script();
    }

    /**
     * Если в URL есть флэш-параметр от OAuth-колбэка/unlink — автоматически
     * переключиться на вкладку соц-сетей при загрузке страницы.
     */
    private function maybe_print_autoactivate_script(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash detection.
        $has_flash = isset($_GET['cashback_social_linked'])
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash detection.
            || isset($_GET['cashback_social_unlinked'])
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash detection.
            || isset($_GET['cashback_social_error']);

        if (!$has_flash) {
            return;
        }

        $tab_id = self::TAB_SOCIAL_ID;
        ?>
<script>
(function () {
    var target = <?php echo wp_json_encode($tab_id); ?>;
    function activate() {
        var tabs = document.querySelectorAll('.cashback-tab');
        var contents = document.querySelectorAll('.cashback-tab-content');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].getAttribute('data-tab') === target);
        }
        for (var j = 0; j < contents.length; j++) {
            contents[j].classList.toggle('active', contents[j].id === target);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', activate);
    } else {
        activate();
    }
}());
</script>
        <?php
    }

    /**
     * Содержимое вкладки «Привязанные соц. сети».
     */
    private function render_social_content(): void {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            echo '<p>' . esc_html__('Вы должны быть авторизованы.', 'cashback-plugin') . '</p>';
            return;
        }

        $this->render_flash_messages();

        $links  = Cashback_Social_Auth_DB::get_links_for_user($user_id);
        $labels = Cashback_Social_Auth_Providers::labels();

        echo '<h3>' . esc_html__('Подключённые социальные сети', 'cashback-plugin') . '</h3>';

        if (empty($links)) {
            echo '<p class="cashback-social-empty">' . esc_html__('У вас пока нет привязанных социальных сетей.', 'cashback-plugin') . '</p>';
        } else {
            $this->render_links_table($links, $labels, $user_id);
        }

        echo '<h3>' . esc_html__('Привязать социальную сеть', 'cashback-plugin') . '</h3>';

        $renderer = Cashback_Social_Auth_Renderer::instance();
        $html     = $renderer->render_buttons('account_link');
        if ($html === '') {
            echo '<p class="cashback-social-empty">' . esc_html__('Все доступные провайдеры уже привязаны или не настроены.', 'cashback-plugin') . '</p>';
        } else {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран из escaped-фрагментов в render_buttons().
            echo $html;
        }
    }

    /**
     * Таблица привязок.
     *
     * @param array<int, array<string, mixed>> $links
     * @param array<string, string>            $labels
     */
    private function render_links_table( array $links, array $labels, int $user_id ): void {
        $total_links  = count($links);
        $user         = get_userdata($user_id);
        $has_password = $user instanceof WP_User && $user->user_pass !== '';
        $nonce        = wp_create_nonce('wp_rest');

        echo '<table class="cashback-social-links-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Провайдер', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Привязан', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('IP', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Устройство', 'cashback-plugin') . '</th>';
        echo '<th>' . esc_html__('Действия', 'cashback-plugin') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($links as $row) {
            $provider_id = isset($row['provider']) ? (string) $row['provider'] : '';
            $label       = $labels[ $provider_id ] ?? $provider_id;
            $linked_at   = isset($row['linked_at']) ? (string) $row['linked_at'] : '';
            $link_ip     = isset($row['link_ip']) ? (string) $row['link_ip'] : '';
            $link_ua     = isset($row['link_user_agent']) ? (string) $row['link_user_agent'] : '';
            $link_ua_sh  = $link_ua !== '' ? substr($link_ua, 0, 60) . ( strlen($link_ua) > 60 ? '…' : '' ) : '';

            // Разрешено удалять если есть пароль ИЛИ это не последняя связка.
            $can_unlink = $has_password || $total_links > 1;
            $disabled   = $can_unlink ? '' : ' disabled="disabled" title="' . esc_attr__('Это ваш единственный способ входа. Сначала установите пароль.', 'cashback-plugin') . '"';

            echo '<tr>';
            echo '<td>' . esc_html($label) . '</td>';
            echo '<td>' . esc_html($this->format_datetime($linked_at)) . '</td>';
            echo '<td>' . esc_html($link_ip) . '</td>';
            echo '<td>' . esc_html($link_ua_sh) . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(rest_url('cashback/v1/social/unlink')) . '" class="cashback-social-unlink-form" data-provider="' . esc_attr($provider_id) . '">';
            echo '<input type="hidden" name="provider" value="' . esc_attr($provider_id) . '" />';
            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $disabled содержит pre-escaped attribute или пустую строку.
            echo '<button type="submit" class="cashback-social-unlink-btn" onclick="return confirm(\'' . esc_js(__('Отвязать аккаунт?', 'cashback-plugin')) . '\');"' . $disabled . '>';
            echo esc_html__('Отвязать', 'cashback-plugin');
            echo '</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Отобразить flash-сообщения после unlink/link.
     */
    private function render_flash_messages(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash from query.
        if (isset($_GET['cashback_social_linked'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash from query.
            $provider = sanitize_key((string) wp_unslash($_GET['cashback_social_linked']));
            if ($provider !== '') {
                $labels = Cashback_Social_Auth_Providers::labels();
                $name   = $labels[ $provider ] ?? $provider;
                echo '<div class="woocommerce-message" role="alert">';
                echo esc_html(sprintf(
                    /* translators: %s: provider label. */
                    __('Аккаунт %s успешно привязан.', 'cashback-plugin'),
                    $name
                ));
                echo '</div>';
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash from query.
        if (isset($_GET['cashback_social_unlinked'])) {
            echo '<div class="woocommerce-message" role="alert">';
            echo esc_html__('Аккаунт успешно отвязан.', 'cashback-plugin');
            echo '</div>';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash from query.
        if (isset($_GET['cashback_social_error'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash from query.
            $code = sanitize_key((string) wp_unslash($_GET['cashback_social_error']));
            $msg  = $this->resolve_error_message($code);
            if ($msg !== '') {
                echo '<div class="woocommerce-error" role="alert"><ul class="woocommerce-error" role="alert"><li>';
                echo esc_html($msg);
                echo '</li></ul></div>';
            }
        }
    }

    private function resolve_error_message( string $code ): string {
        switch ($code) {
            case 'already_linked':
                return __('Этот аккаунт уже привязан к другому пользователю.', 'cashback-plugin');
            case 'last_method':
                return __('Нельзя удалить последний способ входа. Установите пароль перед отвязкой.', 'cashback-plugin');
            case 'account_error':
                return __('Не удалось завершить авторизацию.', 'cashback-plugin');
            case 'unlink_failed':
                return __('Не удалось отвязать аккаунт. Попробуйте ещё раз.', 'cashback-plugin');
            default:
                return '';
        }
    }

    /**
     * Человеко-читаемая дата (формат сайта).
     */
    private function format_datetime( string $mysql ): string {
        if ($mysql === '' || $mysql === null) {
            return '';
        }
        $ts = strtotime($mysql);
        if (!$ts) {
            return $mysql;
        }
        $fmt = get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i');
        return (string) date_i18n($fmt, $ts);
    }
}
