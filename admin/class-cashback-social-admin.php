<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Административная страница настроек соц-авторизации.
 *
 * Кэшбэк → Социальная авторизация
 *
 * Реализовано через WP Settings API:
 *  - глобальный тумблер cashback_social_enabled
 *  - секции cashback_social_provider_yandex / cashback_social_provider_vkid
 *
 * Client secret хранится зашифрованным (AES-256-GCM) в массиве опций. В UI
 * показывается маской «●●●●●●●●●●» если секрет уже задан; поле принимает новое
 * значение только если пользователь его явно ввёл.
 */
class Cashback_Social_Admin {

    public const PARENT_SLUG  = 'cashback-overview';
    public const PAGE_SLUG    = 'cashback-social';
    public const OPTION_GROUP = 'cashback_social_settings_group';

    public function __construct() {
        add_action('admin_menu', array( $this, 'register_menu' ));
        add_action('admin_init', array( $this, 'register_settings' ));
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_assets' ));
    }

    public function register_menu(): void {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Социальная авторизация', 'cashback-plugin'),
            __('Социальная авторизация', 'cashback-plugin'),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    public function enqueue_assets( string $hook ): void {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        // Для WP media picker (выбор иконки).
        wp_enqueue_media();
    }

    public function register_settings(): void {
        // Глобальный тумблер.
        register_setting(
            self::OPTION_GROUP,
            'cashback_social_enabled',
            array(
                'type'              => 'integer',
                'sanitize_callback' => static function ( $value ): int {
                    return (int) ( ! empty($value) );
                },
                'default'           => 0,
            )
        );

        // По провайдерам — каждому своя замыкание, чтобы знать имя опции в sanitize.
        foreach (array( 'yandex', 'vkid' ) as $pid) {
            $option_name = 'cashback_social_provider_' . $pid;
            register_setting(
                self::OPTION_GROUP,
                $option_name,
                array(
                    'type'              => 'array',
                    'sanitize_callback' => function ( $value ) use ( $option_name ) {
                        return $this->sanitize_provider_settings($value, $option_name);
                    },
                    'default'           => array(),
                )
            );
        }
    }

    /**
     * Sanitize массива настроек провайдера. Шифрует новый client_secret.
     *
     * @param mixed  $value
     * @param string $option_name Имя текущей опции (передано из замыкания).
     * @return array<string, mixed>
     */
    public function sanitize_provider_settings( $value, string $option_name ): array {
        if (!is_array($value)) {
            return array();
        }

        $out = array();

        $out['enabled']        = !empty($value['enabled']) ? 1 : 0;
        $out['client_id']      = isset($value['client_id']) ? sanitize_text_field((string) $value['client_id']) : '';
        $out['phone_scope']    = !empty($value['phone_scope']) ? 1 : 0;
        $out['label_login']    = isset($value['label_login']) ? sanitize_text_field((string) $value['label_login']) : '';
        $out['label_register'] = isset($value['label_register']) ? sanitize_text_field((string) $value['label_register']) : '';
        $out['icon_id']        = isset($value['icon_id']) ? (int) $value['icon_id'] : 0;

        // Client secret: если пришло непустое значение — шифруем. Иначе — сохраняем прежний.
        $incoming_secret = isset($value['client_secret']) ? (string) $value['client_secret'] : '';

        $prev        = get_option($option_name, array());
        $prev_secret = is_array($prev) && isset($prev['client_secret_encrypted']) ? (string) $prev['client_secret_encrypted'] : '';

        if ($incoming_secret !== '') {
            if (class_exists('Cashback_Encryption') && Cashback_Encryption::is_configured()) {
                try {
                    $out['client_secret_encrypted'] = Cashback_Encryption::encrypt($incoming_secret);
                } catch (\Throwable $e) {
                    add_settings_error(
                        self::OPTION_GROUP,
                        'cashback_social_secret_encrypt_failed',
                        __('Не удалось зашифровать client secret. Проверьте ключ шифрования.', 'cashback-plugin'),
                        'error'
                    );
                    $out['client_secret_encrypted'] = $prev_secret;
                }
            } else {
                add_settings_error(
                    self::OPTION_GROUP,
                    'cashback_social_no_encryption',
                    __('Ключ шифрования не настроен — client secret не сохранён.', 'cashback-plugin'),
                    'error'
                );
                $out['client_secret_encrypted'] = $prev_secret;
            }
        } else {
            $out['client_secret_encrypted'] = $prev_secret;
        }

        return $out;
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }

        $global_enabled = (int) get_option('cashback_social_enabled', 0);
        $yandex         = (array) get_option('cashback_social_provider_yandex', array());
        $vkid           = (array) get_option('cashback_social_provider_vkid', array());

        ?>
        <div class="wrap cashback-social-admin">
            <h1><?php esc_html_e('Социальная авторизация', 'cashback-plugin'); ?></h1>

            <?php settings_errors(self::OPTION_GROUP); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                ?>

                <h2><?php esc_html_e('Общие настройки', 'cashback-plugin'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Включить модуль', 'cashback-plugin'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="cashback_social_enabled" value="1" <?php checked(1, $global_enabled); ?>>
                                <?php esc_html_e('Показывать кнопки соцсетей и обрабатывать OAuth callback.', 'cashback-plugin'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php
                $this->render_provider_section('yandex', __('Яндекс ID', 'cashback-plugin'), $yandex);
                $this->render_provider_section('vkid', __('VK ID', 'cashback-plugin'), $vkid);
                submit_button();
                ?>
            </form>
        </div>

        <script>
        (function(){
            // Простой media picker для иконок — без отдельного JS-ассета.
            document.addEventListener('click', function(e){
                var btn = e.target.closest('[data-cashback-social-icon-picker]');
                if (!btn) return;
                e.preventDefault();
                if (typeof wp === 'undefined' || !wp.media) return;

                var inputId = btn.getAttribute('data-cashback-social-icon-picker');
                var previewId = btn.getAttribute('data-preview');
                var frame = wp.media({
                    title: '<?php echo esc_js(__('Выбрать иконку', 'cashback-plugin')); ?>',
                    multiple: false,
                    library: { type: 'image' }
                });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    var input = document.getElementById(inputId);
                    if (input) input.value = att.id;
                    var preview = document.getElementById(previewId);
                    if (preview) {
                        preview.innerHTML = '<img src="' + att.url + '" style="max-width:48px;max-height:48px;">';
                    }
                });
                frame.open();
            });
        })();
        </script>
        <?php
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function render_provider_section( string $pid, string $label, array $settings ): void {
        $opt_name   = 'cashback_social_provider_' . $pid;
        $enabled    = !empty($settings['enabled']);
        $client_id  = isset($settings['client_id']) ? (string) $settings['client_id'] : '';
        $phone      = !empty($settings['phone_scope']);
        $lbl_login  = isset($settings['label_login']) ? (string) $settings['label_login'] : '';
        $lbl_reg    = isset($settings['label_register']) ? (string) $settings['label_register'] : '';
        $icon_id    = isset($settings['icon_id']) ? (int) $settings['icon_id'] : 0;
        $has_secret = !empty($settings['client_secret_encrypted']);

        $redirect_uri = home_url('/wp-json/cashback/v1/social/' . $pid . '/callback');

        $icon_preview_html = '';
        if ($icon_id > 0) {
            $src = wp_get_attachment_image_url($icon_id, 'thumbnail');
            if ($src) {
                $icon_preview_html = '<img src="' . esc_url($src) . '" style="max-width:48px;max-height:48px;">';
            }
        }

        ?>
        <h2><?php echo esc_html($label); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Включён', 'cashback-plugin'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($opt_name); ?>[enabled]" value="1" <?php checked(true, $enabled); ?>>
                        <?php esc_html_e('Использовать этого провайдера.', 'cashback-plugin'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr($opt_name); ?>_client_id">Client ID</label>
                </th>
                <td>
                    <input type="text"
                            id="<?php echo esc_attr($opt_name); ?>_client_id"
                            name="<?php echo esc_attr($opt_name); ?>[client_id]"
                            value="<?php echo esc_attr($client_id); ?>"
                            class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr($opt_name); ?>_client_secret">Client Secret</label>
                </th>
                <td>
                    <input type="password"
                            id="<?php echo esc_attr($opt_name); ?>_client_secret"
                            name="<?php echo esc_attr($opt_name); ?>[client_secret]"
                            value=""
                            placeholder="<?php echo $has_secret ? esc_attr('●●●●●●●●●●') : ''; ?>"
                            autocomplete="new-password"
                            class="regular-text">
                    <p class="description">
                        <?php
                        if ($has_secret) {
                            esc_html_e('Секрет сохранён (зашифрован). Введите новое значение, чтобы заменить.', 'cashback-plugin');
                        } else {
                            esc_html_e('Секрет будет зашифрован AES-256-GCM перед сохранением.', 'cashback-plugin');
                        }
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Redirect URI', 'cashback-plugin'); ?></th>
                <td>
                    <code><?php echo esc_html($redirect_uri); ?></code>
                    <p class="description"><?php esc_html_e('Укажите этот URL в настройках приложения провайдера.', 'cashback-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Запрашивать телефон', 'cashback-plugin'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($opt_name); ?>[phone_scope]" value="1" <?php checked(true, $phone); ?>>
                        <?php esc_html_e('Добавлять scope телефона (требует модерации приложения).', 'cashback-plugin'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr($opt_name); ?>_label_login">
                        <?php esc_html_e('Надпись на кнопке (вход)', 'cashback-plugin'); ?>
                    </label>
                </th>
                <td>
                    <input type="text"
                            id="<?php echo esc_attr($opt_name); ?>_label_login"
                            name="<?php echo esc_attr($opt_name); ?>[label_login]"
                            value="<?php echo esc_attr($lbl_login); ?>"
                            class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr($opt_name); ?>_label_register">
                        <?php esc_html_e('Надпись на кнопке (регистрация)', 'cashback-plugin'); ?>
                    </label>
                </th>
                <td>
                    <input type="text"
                            id="<?php echo esc_attr($opt_name); ?>_label_register"
                            name="<?php echo esc_attr($opt_name); ?>[label_register]"
                            value="<?php echo esc_attr($lbl_reg); ?>"
                            class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Иконка', 'cashback-plugin'); ?></th>
                <td>
                    <input type="hidden"
                            id="<?php echo esc_attr($opt_name); ?>_icon_id"
                            name="<?php echo esc_attr($opt_name); ?>[icon_id]"
                            value="<?php echo esc_attr((string) $icon_id); ?>">
                    <div id="<?php echo esc_attr($opt_name); ?>_icon_preview">
                    <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $icon_preview_html built from esc_url/esc_attr.
                        echo $icon_preview_html;
                    ?>
                    </div>
                    <button type="button"
                            class="button"
                            data-cashback-social-icon-picker="<?php echo esc_attr($opt_name); ?>_icon_id"
                            data-preview="<?php echo esc_attr($opt_name); ?>_icon_preview">
                        <?php esc_html_e('Выбрать иконку', 'cashback-plugin'); ?>
                    </button>
                </td>
            </tr>
        </table>
        <?php
    }
}
