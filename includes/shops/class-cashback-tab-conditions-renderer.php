<?php
/**
 * Cashback_Tab_Conditions_Renderer — генератор HTML-контента для WoodMart
 * Tab[1] «Условия» при импорте товара из CPA-сети.
 *
 * Производит две секции:
 *  1. «Условия начисления:» — список percent-тарифов, посчитанных как
 *     `payment_size × cashback_guest_display_rate / 100`. Цифры одинаковые
 *     для всех пользователей (страница товара статична для гостя/юзера).
 *  2. «Срок начисления кэшбэка:» — «Средний срок начисления N дней», где
 *     N = `_cashback_avg_payment_days` (Admitad: `avg_money_transfer_time`)
 *     + PAYMENT_DAYS_BUFFER. Если meta нет — используется FALLBACK_PAYMENT_DAYS.
 *
 * Sentinel-маркер `<!-- cashback:autogen:v1 -->` ставится в начале:
 * импортёр перезаписывает контент только если маркер на месте — иначе
 * расценивается как admin-edit и сохраняется.
 *
 * @package CashbackPlugin
 * @since   12.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH') && ! defined('PHPUNIT_RUNNING')) {
    exit;
}

if (class_exists('Cashback_Tab_Conditions_Renderer', false)) {
    return;
}

final class Cashback_Tab_Conditions_Renderer {

    public const SENTINEL              = '<!-- cashback:autogen:v1 -->';
    public const PAYMENT_DAYS_BUFFER   = 3;
    public const FALLBACK_PAYMENT_DAYS = 30;

    /**
     * Сгенерировать HTML контента Tab[1].
     *
     * Секция «Срок начисления» рендерится всегда (resolve_payment_days
     * имеет fallback на 30 дней), секция «Условия начисления» — только если
     * есть хотя бы один активный percent-тариф.
     */
    public static function render( int $product_id, int $network_id, string $offer_id ): string {
        if ( ! class_exists( 'Cashback_Shop_Tariff_Sync' ) ) {
            return '';
        }

        $tariffs      = Cashback_Shop_Tariff_Sync::get_active( $network_id, $offer_id );
        $guest_rate   = self::resolve_guest_rate();
        $days         = self::resolve_payment_days( $product_id );
        $tariff_lines = self::format_tariff_lines( $tariffs, $guest_rate );

        $html = self::SENTINEL . "\n";
        if ( $tariff_lines !== array() ) {
            $html .= "<h3><strong>Условия начисления:</strong></h3>\n";
            foreach ( $tariff_lines as $line ) {
                $html .= $line . "\n";
            }
        }
        $html .= "<h3><strong>Срок начисления кэшбэка:</strong></h3>\n";
        $html .= sprintf(
            "<p>Средний срок начисления <strong>%d %s</strong></p>\n",
            $days,
            self::pluralize_days( $days )
        );

        return rtrim( $html ) . "\n";
    }

    /**
     * Проверить — autogen ли контент (стартует с sentinel).
     * Лидирующие пробелы/BOM игнорируются.
     */
    public static function is_autogen( string $content ): bool {
        $stripped = ltrim( $content, "\xEF\xBB\xBF \t\n\r\0\x0B" );
        return strpos( $stripped, self::SENTINEL ) === 0;
    }

    /**
     * @param array<int, array<string, mixed>> $tariffs
     * @return array<int, string>
     */
    private static function format_tariff_lines( array $tariffs, float $guest_rate ): array {
        $lines = array();
        foreach ( $tariffs as $tariff ) {
            if ( ! is_array( $tariff ) ) {
                continue;
            }
            $type = isset( $tariff['tariff_type'] ) ? (string) $tariff['tariff_type'] : '';
            if ( $type !== 'percent' ) {
                continue;
            }
            $size_raw = $tariff['payment_size'] ?? 0;
            if ( ! is_numeric( $size_raw ) ) {
                continue;
            }
            $name = isset( $tariff['name'] ) ? trim( (string) $tariff['name'] ) : '';
            if ( $name === '' ) {
                $name = 'Тариф';
            }

            $value = (float) $size_raw * $guest_rate / 100.0;
            $value = round( $value, 2 );

            if ( $value > 0 && $value < 0.01 ) {
                $formatted = '<0,01';
            } else {
                $formatted = number_format( $value, 2, ',', '' );
            }

            $lines[] = sprintf(
                '<p>%s <strong>%s%%</strong></p>',
                esc_html( $name ),
                $formatted
            );
        }
        return $lines;
    }

    private static function resolve_guest_rate(): float {
        $rate = (float) get_option( 'cashback_guest_display_rate', 60.0 );
        if ( $rate < 0.0 ) {
            return 0.0;
        }
        if ( $rate > 100.0 ) {
            return 100.0;
        }
        return $rate;
    }

    /**
     * Получить итоговое число дней «Средний срок начисления N дней».
     * Если product meta `_cashback_avg_payment_days` отсутствует или вне
     * диапазона [0, 365] — используется FALLBACK_PAYMENT_DAYS, поэтому
     * метод всегда возвращает валидное число > 0.
     */
    private static function resolve_payment_days( int $product_id ): int {
        $base = null;
        if ( $product_id > 0 ) {
            $raw = get_post_meta( $product_id, Cashback_Shop_Importer::META_AVG_PAYMENT_DAYS, true );
            if ( is_numeric( $raw ) ) {
                $candidate = (int) $raw;
                if ( $candidate >= 0 && $candidate <= 365 ) {
                    $base = $candidate;
                }
            }
        }
        if ( $base === null ) {
            $base = self::FALLBACK_PAYMENT_DAYS;
        }

        return $base + self::PAYMENT_DAYS_BUFFER;
    }

    /**
     * Русская плюрализация: 1 день / 2 дня / 5 дней.
     * Захардкоден — плагин рассчитан на ru-локаль; формы фиксированы.
     */
    private static function pluralize_days( int $n ): string {
        $abs = (int) abs( $n );
        $mod10  = $abs % 10;
        $mod100 = $abs % 100;
        if ( $mod10 === 1 && $mod100 !== 11 ) {
            return 'день';
        }
        if ( $mod10 >= 2 && $mod10 <= 4 && ( $mod100 < 12 || $mod100 > 14 ) ) {
            return 'дня';
        }
        return 'дней';
    }
}
