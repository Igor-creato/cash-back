<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Административная страница «Отображение кэшбэка».
 *
 * Кэшбэк → Отображение
 *
 * Реализовано через WP Settings API:
 *  - cashback_display_mode: 'classic' | 'block'
 *
 * 'classic' (по умолчанию): кэшбэк дописывается фильтром
 *   `woocommerce_get_price_html` + резервные хуки. Подходит для классических
 *   тем (woodmart-child).
 *
 * 'block': хуки выключены — кэшбэк показывается только через
 *   Gutenberg-блок «Кэшбэк (Cashback display)», который в FSE-шаблоне
 *   карточки товара ставится отдельным блоком.
 */
class Cashback_Display_Admin {

    public const PARENT_SLUG  = 'cashback-overview';
    public const PAGE_SLUG    = 'cashback-display';
    public const OPTION_GROUP = 'cashback_display_settings_group';
    public const OPTION_NAME  = 'cashback_display_mode';

    public function __construct() {
        add_action('admin_menu', array( $this, 'register_menu' ));
        add_action('admin_init', array( $this, 'register_settings' ));
    }

    public function register_menu(): void {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Отображение кэшбэка', 'cashback-plugin'),
            __('Отображение', 'cashback-plugin'),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    public function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_mode' ),
                'default'           => 'classic',
            )
        );
    }

    public function sanitize_mode( $value ): string {
        $value = is_string($value) ? $value : 'classic';
        return ('block' === $value) ? 'block' : 'classic';
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }

        $mode = get_option(self::OPTION_NAME, 'classic');
        if ('block' !== $mode) {
            $mode = 'classic';
        }
        ?>
        <div class="wrap cashback-display-admin">
            <h1><?php esc_html_e('Отображение кэшбэка', 'cashback-plugin'); ?></h1>

            <?php settings_errors(self::OPTION_GROUP); ?>

            <p>
                <?php esc_html_e('Выберите способ вывода кэшбэка на карточках и страницах товаров.', 'cashback-plugin'); ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Способ вывода', 'cashback-plugin'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text">
                                    <?php esc_html_e('Способ вывода кэшбэка', 'cashback-plugin'); ?>
                                </legend>

                                <label>
                                    <input type="radio" name="<?php echo esc_attr(self::OPTION_NAME); ?>" value="classic" <?php checked('classic', $mode); ?>>
                                    <strong><?php esc_html_e('Классический фильтр', 'cashback-plugin'); ?></strong>
                                </label>
                                <p class="description" style="margin: 4px 0 12px 26px;">
                                    <?php esc_html_e('Кэшбэк дописывается в HTML цены через woocommerce_get_price_html. Подходит для классических тем (например, woodmart-child).', 'cashback-plugin'); ?>
                                </p>

                                <label>
                                    <input type="radio" name="<?php echo esc_attr(self::OPTION_NAME); ?>" value="block" <?php checked('block', $mode); ?>>
                                    <strong><?php esc_html_e('Отдельный блок «Cashback display»', 'cashback-plugin'); ?></strong>
                                </label>
                                <p class="description" style="margin: 4px 0 0 26px;">
                                    <?php esc_html_e('Фильтр и резервные хуки отключаются. Кэшбэк показывается только через Gutenberg-блок «Кэшбэк», который нужно вставить в FSE-шаблон карточки товара отдельным блоком (например, под блоком «Цена товара»).', 'cashback-plugin'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
