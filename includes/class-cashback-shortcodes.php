<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Шорткоды для отображения кешбэк-баланса авторизованного пользователя
 * и кэшбэка товара (для Woodmart Custom Loop Item Layout).
 *
 * Использование:
 *   [cashback_balance]                — доступный баланс (число + ₽)
 *   [cashback_balance type="pending"] — баланс в обработке
 *   [cashback_balance type="paid"]    — выплачено всего
 *   [cashback_balance type="all"]     — виджет со всеми типами баланса
 *   [cashback_balance type="available" format="number"] — только число без символа валюты
 *
 *   [cashback_display]                          — кэшбэк текущего товара (внутри loop / single)
 *   [cashback_display id="123"]                 — кэшбэк конкретного товара
 *   [cashback_display value="до 5%"]            — статичный override (без post-meta)
 *   [cashback_display label="Бонус" value="100"] — кастомный label + value
 *
 * Атрибуты [cashback_balance]:
 *   type    = available (по умолчанию) | pending | paid | all
 *   format  = widget (по умолчанию) | number
 *   guest   = hide (по умолчанию) | login_link | text
 *   decimals = 2 (по умолчанию) — количество знаков после запятой
 *
 * Атрибуты [cashback_display]:
 *   id    = ID товара (по умолчанию текущий get_the_ID())
 *   label = override текста метки
 *   value = override значения кэшбэка (если задан — meta игнорируется)
 *   class = доп. CSS-классы
 */
class Cashback_Shortcodes {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('cashback_balance', array( $this, 'render_balance' ));
        add_shortcode('cashback_display', array( $this, 'render_cashback_display' ));
    }

    /**
     * Шорткод [cashback_balance].
     *
     * @param array<string, string>|string $atts
     * @return string HTML-вывод
     */
    public function render_balance( $atts ): string {
        $atts = shortcode_atts(array(
            'type'     => 'available',
            'format'   => 'widget',
            'guest'    => 'hide',
            'decimals' => '2',
        ), $atts, 'cashback_balance');

        $type     = sanitize_key($atts['type']);
        $format   = sanitize_key($atts['format']);
        $guest    = sanitize_key($atts['guest']);
        $decimals = max(0, (int) $atts['decimals']);

        // Не авторизован
        if (!is_user_logged_in()) {
            return $this->render_guest($guest);
        }

        $user_id = get_current_user_id();
        $balance = $this->get_balance($user_id);

        if ($format === 'number') {
            return $this->render_number($balance, $type, $decimals);
        }

        if ($type === 'all') {
            return $this->render_all_widget($balance, $decimals);
        }

        return $this->render_single_widget($balance, $type, $decimals);
    }

    /**
     * Получение балансов пользователя из БД.
     *
     * @return array{available: float, pending: float, paid: float}
     */
    private function get_balance( int $user_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'cashback_user_balance';
        $row   = $wpdb->get_row($wpdb->prepare(
            'SELECT available_balance, pending_balance, paid_balance
             FROM %i
             WHERE user_id = %d',
            $table,
            $user_id
        ));

        return array(
            'available' => (float) ( $row->available_balance ?? 0.0 ),
            'pending'   => (float) ( $row->pending_balance ?? 0.0 ),
            'paid'      => (float) ( $row->paid_balance ?? 0.0 ),
        );
    }

    /**
     * Форматирует сумму: «1 234,56 ₽».
     */
    private function format_amount( float $amount, int $decimals ): string {
        return number_format($amount, $decimals, ',', ' ') . ' ₽';
    }

    /**
     * Только число без разметки (для встройки в текст/атрибуты).
     *
     * @param array{available: float, pending: float, paid: float} $balance
     */
    private function render_number( array $balance, string $type, int $decimals ): string {
        $value = $balance[ $type ] ?? $balance['available'];
        return esc_html(number_format($value, $decimals, ',', ' '));
    }

    /**
     * Компактный виджет для одного типа баланса.
     *
     * @param array{available: float, pending: float, paid: float} $balance
     */
    private function render_single_widget( array $balance, string $type, int $decimals ): string {
        $labels = array(
            'available' => 'Баланс',
            'pending'   => 'В обработке',
            'paid'      => 'Выплачено',
        );

        $value = $balance[ $type ] ?? $balance['available'];
        $label = $labels[ $type ] ?? $labels['available'];

        ob_start();
?>
        <span class="cashback-balance cashback-balance--<?php echo esc_attr($type); ?>">
            <span class="cashback-balance__label"><?php echo esc_html($label); ?>:</span>
            <span class="cashback-balance__amount"><?php echo esc_html($this->format_amount($value, $decimals)); ?></span>
        </span>
    <?php
        return (string) ob_get_clean();
    }

    /**
     * Полный виджет со всеми типами баланса.
     *
     * @param array{available: float, pending: float, paid: float} $balance
     */
    private function render_all_widget( array $balance, int $decimals ): string {
        ob_start();
    ?>
        <div class="cashback-balance-widget">
            <div class="cashback-balance-widget__row cashback-balance-widget__row--available">
                <span class="cashback-balance-widget__label">Доступный баланс</span>
                <span class="cashback-balance-widget__amount"><?php echo esc_html($this->format_amount($balance['available'], $decimals)); ?></span>
            </div>
            <div class="cashback-balance-widget__row cashback-balance-widget__row--pending">
                <span class="cashback-balance-widget__label">В обработке</span>
                <span class="cashback-balance-widget__amount"><?php echo esc_html($this->format_amount($balance['pending'], $decimals)); ?></span>
            </div>
            <div class="cashback-balance-widget__row cashback-balance-widget__row--paid">
                <span class="cashback-balance-widget__label">Выплачено</span>
                <span class="cashback-balance-widget__amount"><?php echo esc_html($this->format_amount($balance['paid'], $decimals)); ?></span>
            </div>
        </div>
<?php
        return (string) ob_get_clean();
    }

    /**
     * Шорткод [cashback_display] — отдельный HTML-узел кэшбэка товара.
     *
     * Используется в Woodmart Custom Loop Item Layout (Kartochka). Внутри
     * Woodmart-цикла глобальный $post установлен на текущий товар, поэтому
     * атрибут id опционален — fallback на get_the_ID().
     *
     * Если задан атрибут value — рендерит его напрямую (статичный override).
     * Иначе читает post-meta `_cashback_display_value` через
     * {@see WC_Affiliate_URL_Params::render_cashback_html()}.
     *
     * @param array<string, string>|string $atts
     * @return string HTML-строка или пустая строка.
     */
    public function render_cashback_display( $atts ): string {
        $atts = shortcode_atts(array(
            'id'    => '0',
            'label' => '',
            'value' => '',
            'class' => '',
        ), $atts, 'cashback_display');

        $product_id = (int) $atts['id'];
        if (0 === $product_id) {
            $product_id = (int) get_the_ID();
        }

        $value_override = trim((string) $atts['value']);
        $label_override = trim((string) $atts['label']);
        $extra_class    = sanitize_html_class($atts['class']);

        if ('' !== $value_override) {
            $label_to_render = '' !== $label_override ? $label_override : 'Кэшбэк';
            $classes         = 'cashback-display cashback-display--shortcode cashback-display--standalone';
            if ('' !== $extra_class) {
                $classes .= ' ' . $extra_class;
            }

            return sprintf(
                '<span class="%s"><span class="cashback-display__label">%s</span> <span class="cashback-display__value">%s</span></span>',
                esc_attr($classes),
                esc_html($label_to_render),
                esc_html($value_override)
            );
        }

        if (0 === $product_id) {
            return '';
        }

        if (!class_exists('WC_Affiliate_URL_Params')) {
            return '';
        }

        $html = WC_Affiliate_URL_Params::render_cashback_html($product_id, 'shortcode', true);
        if ('' === $html) {
            return '';
        }

        if ('' !== $label_override) {
            // Подменяем содержимое <span class="cashback-display__label">…</span>
            // на пользовательский label. Используем preg_replace на гарантированной
            // нашей же шаблонной строке (см. render_cashback_html выше).
            $html = preg_replace(
                '#(<span class="cashback-display__label">)([^<]*)(</span>)#u',
                '$1' . esc_html($label_override) . '$3',
                $html,
                1
            );
        }

        if ('' !== $extra_class) {
            $html = preg_replace(
                '#class="(cashback-display [^"]*)"#u',
                'class="$1 ' . $extra_class . '"',
                $html,
                1
            );
        }

        return (string) $html;
    }

    /**
     * Вывод для неавторизованного пользователя.
     */
    private function render_guest( string $mode ): string {
        if ($mode === 'hide') {
            return '';
        }

        if ($mode === 'login_link') {
            return sprintf(
                '<a href="%s" class="cashback-balance__login-link">%s</a>',
                esc_url(wc_get_page_permalink('myaccount')),
                esc_html__('Войдите, чтобы увидеть баланс', 'cashback-plugin')
            );
        }

        // mode = text
        return '<span class="cashback-balance__guest">' .
            esc_html__('Доступно после авторизации', 'cashback-plugin') .
            '</span>';
    }
}
