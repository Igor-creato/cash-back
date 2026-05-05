<?php

/**
 * Админ-настройки шорткода [cashback_coupons_icons].
 *
 * Регистрирует Settings API option `cashback_coupons_icons_settings` —
 * массив с тремя attachment_id (Media Library) для иконок типов
 * discount/gift/free_shipping. Рендерит блок описания шорткода + 3
 * media-picker поля внутри существующей вкладки «Шорткоды»
 * (admin.php?page=cashback-overview&tab=shortcodes), вызывается из
 * admin/statistics.php → render_overview_page().
 *
 * @package CashbackPlugin
 * @since   7.5.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cashback_Coupons_Icons_Admin {

    public const OPTION_NAME  = 'cashback_coupons_icons_settings';
    public const OPTION_GROUP = 'cashback_coupons_icons_group';

    private const ICON_TYPES = array( 'discount', 'gift', 'free_shipping' );

    public static function init(): void {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function register_settings(): void {
        if ( ! function_exists( 'register_setting' ) ) {
            return;
        }

        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( __CLASS__, 'sanitize' ),
                'default'           => array(
                    'discount'      => 0,
                    'gift'          => 0,
                    'free_shipping' => 0,
                ),
            )
        );
    }

    /**
     * Sanitize: пропускаем только положительные attachment_id картинок.
     *
     * @param mixed $value
     * @return array{discount:int,gift:int,free_shipping:int}
     */
    public static function sanitize( $value ): array {
        $out = array( 'discount' => 0, 'gift' => 0, 'free_shipping' => 0 );
        if ( ! is_array( $value ) ) {
            return $out;
        }
        foreach ( self::ICON_TYPES as $key ) {
            $aid = isset( $value[ $key ] ) ? (int) $value[ $key ] : 0;
            if ( $aid > 0 && function_exists( 'wp_attachment_is_image' ) && wp_attachment_is_image( $aid ) ) {
                $out[ $key ] = $aid;
            } else {
                $out[ $key ] = 0;
            }
        }
        return $out;
    }

    /**
     * Подключает wp_enqueue_media() только на нашей вкладке шорткодов.
     */
    public static function enqueue_assets( string $hook ): void {
        // Ограничиваем page=cashback-overview & tab=shortcodes.
        if ( strpos( (string) $hook, 'cashback-overview' ) === false ) {
            return;
        }
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $tab !== 'shortcodes' ) {
            return;
        }
        if ( function_exists( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }
    }

    /**
     * Рендер блока описания шорткода + form с picker'ами.
     * Встраивается в admin/statistics.php → render_overview_page() (tab=shortcodes).
     */
    public static function render_section(): void {
        if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = function_exists( 'get_option' ) ? (array) get_option( self::OPTION_NAME, array() ) : array();
        $current  = array(
            'discount'      => isset( $settings['discount'] ) ? (int) $settings['discount'] : 0,
            'gift'          => isset( $settings['gift'] ) ? (int) $settings['gift'] : 0,
            'free_shipping' => isset( $settings['free_shipping'] ) ? (int) $settings['free_shipping'] : 0,
        );

        $labels = array(
            'discount'      => __( 'Купон на скидку', 'cashback-plugin' ),
            'gift'          => __( 'Подарок при покупке', 'cashback-plugin' ),
            'free_shipping' => __( 'Бесплатная доставка', 'cashback-plugin' ),
        );

        ?>
        <details class="cb-shortcode-section">
            <summary>[cashback_coupons_icons] — <?php echo esc_html__( 'Иконки активных купонов товара', 'cashback-plugin' ); ?></summary>
            <div class="cb-shortcode-body">
                <p>
                    <?php
                    echo esc_html__(
                        'Выводит ряд иконок (по одной на уникальный тип купона: скидка / подарок / бесплатная доставка) активных купонов товара. При наведении показывается подсказка, при клике — переход на страницу товара с автоматическим открытием вкладки «Купоны». Если у товара нет активных купонов или иконка соответствующего типа не выбрана — иконка не выводится.',
                        'cashback-plugin'
                    );
                    ?>
                </p>

                <table class="widefat striped" style="margin-bottom: 12px;">
                    <thead>
                        <tr>
                            <th style="width: 38%;"><?php esc_html_e( 'Шорткод', 'cashback-plugin' ); ?></th>
                            <th><?php esc_html_e( 'Описание', 'cashback-plugin' ); ?></th>
                            <th style="width: 20%;"><?php esc_html_e( 'Пример', 'cashback-plugin' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rows = array(
                            array( '[cashback_coupons_icons]', __( 'Иконки для текущего товара (в loop / single-product).', 'cashback-plugin' ), '' ),
                            array( '[cashback_coupons_icons id="123"]', __( 'Иконки для конкретного товара по ID.', 'cashback-plugin' ), '' ),
                            array( '[cashback_coupons_icons size="lg"]', __( 'Размер иконок: sm / md / lg.', 'cashback-plugin' ), '' ),
                            array( '[cashback_coupons_icons icons="discount,gift"]', __( 'Показать только указанные типы.', 'cashback-plugin' ), '' ),
                            array( '[cashback_coupons_icons class="my-extra"]', __( 'Дополнительный CSS-класс на обёртку.', 'cashback-plugin' ), '' ),
                            array( '[cashback_coupons_icons tab="coupons"]', __( 'Слаг таба товара, который надо открыть. По умолчанию «coupons».', 'cashback-plugin' ), '' ),
                        );
                        foreach ( $rows as $row ) :
                            ?>
                            <tr>
                                <td>
                                    <span class="cb-shortcode-copy" data-shortcode="<?php echo esc_attr( $row[0] ); ?>" title="<?php echo esc_attr__( 'Нажмите, чтобы скопировать', 'cashback-plugin' ); ?>">
                                        <span class="cb-copy-icon">⎘</span><?php echo esc_html( $row[0] ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( $row[1] ); ?></td>
                                <td><?php echo esc_html( $row[2] ); ?></td>
                            </tr>
                            <?php
                        endforeach;
                        ?>
                    </tbody>
                </table>

                <h3 style="margin-top: 18px;"><?php esc_html_e( 'Иконки купонов', 'cashback-plugin' ); ?></h3>
                <p>
                    <?php
                    echo esc_html__(
                        'Выберите 3 картинки из медиатеки. Если иконка не выбрана — соответствующий тип купона не будет отображаться.',
                        'cashback-plugin'
                    );
                    ?>
                </p>

                <form method="post" action="options.php">
                    <?php settings_fields( self::OPTION_GROUP ); ?>

                    <table class="form-table" role="presentation">
                        <?php
                        foreach ( self::ICON_TYPES as $type ) :
                            $aid          = $current[ $type ];
                            $input_id     = 'cashback_coupons_icons_' . $type;
                            $preview_id   = $input_id . '_preview';
                            $preview_html = '';
                            if ( $aid > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
                                $url = wp_get_attachment_image_url( $aid, 'thumbnail' );
                                if ( $url ) {
                                    $preview_html = '<img src="' . esc_url( $url ) . '" style="max-width:48px;max-height:48px;">';
                                }
                            }
                            ?>
                            <tr>
                                <th scope="row"><?php echo esc_html( $labels[ $type ] ); ?></th>
                                <td>
                                    <input type="hidden"
                                            id="<?php echo esc_attr( $input_id ); ?>"
                                            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $type ); ?>]"
                                            value="<?php echo esc_attr( (string) $aid ); ?>">
                                    <div id="<?php echo esc_attr( $preview_id ); ?>" style="display:inline-block;vertical-align:middle;margin-right:8px;min-width:48px;min-height:48px;">
                                        <?php echo $preview_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- собран из esc_url. ?>
                                    </div>
                                    <button type="button"
                                            class="button"
                                            data-cashback-coupons-icon-picker="<?php echo esc_attr( $input_id ); ?>"
                                            data-preview="<?php echo esc_attr( $preview_id ); ?>">
                                        <?php esc_html_e( 'Выбрать иконку', 'cashback-plugin' ); ?>
                                    </button>
                                    <?php if ( $aid > 0 ) : ?>
                                        <button type="button"
                                                class="button-link"
                                                data-cashback-coupons-icon-clear="<?php echo esc_attr( $input_id ); ?>"
                                                data-preview="<?php echo esc_attr( $preview_id ); ?>">
                                            <?php esc_html_e( 'Очистить', 'cashback-plugin' ); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                        endforeach;
                        ?>
                    </table>

                    <?php submit_button( __( 'Сохранить иконки', 'cashback-plugin' ) ); ?>
                </form>
            </div>
        </details>

        <script>
        (function () {
            document.addEventListener('click', function (e) {
                var pickerBtn = e.target.closest('[data-cashback-coupons-icon-picker]');
                if (pickerBtn) {
                    e.preventDefault();
                    if (typeof wp === 'undefined' || !wp.media) { return; }
                    var inputId   = pickerBtn.getAttribute('data-cashback-coupons-icon-picker');
                    var previewId = pickerBtn.getAttribute('data-preview');
                    var frame = wp.media({
                        title:    '<?php echo esc_js( __( 'Выбрать иконку купона', 'cashback-plugin' ) ); ?>',
                        multiple: false,
                        library:  { type: 'image' }
                    });
                    frame.on('select', function () {
                        var att   = frame.state().get('selection').first().toJSON();
                        var input = document.getElementById(inputId);
                        if (input) { input.value = att.id; }
                        var preview = document.getElementById(previewId);
                        if (preview) {
                            preview.innerHTML = '<img src="' + att.url + '" style="max-width:48px;max-height:48px;">';
                        }
                    });
                    frame.open();
                    return;
                }
                var clearBtn = e.target.closest('[data-cashback-coupons-icon-clear]');
                if (clearBtn) {
                    e.preventDefault();
                    var inputId   = clearBtn.getAttribute('data-cashback-coupons-icon-clear');
                    var previewId = clearBtn.getAttribute('data-preview');
                    var input = document.getElementById(inputId);
                    if (input) { input.value = '0'; }
                    var preview = document.getElementById(previewId);
                    if (preview) { preview.innerHTML = ''; }
                }
            });
        })();
        </script>
        <?php
    }
}
