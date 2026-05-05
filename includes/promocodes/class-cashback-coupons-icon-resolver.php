<?php

/**
 * Resolver: species + name + description → icon_type.
 *
 * Чистый mapper, без WP-зависимостей. Используется:
 *   1. В шорткоде [cashback_coupons_icons] для рендера иконок по
 *      активным купонам товара.
 *   2. В Cashback_Coupons_Field_Mapper для апгрейда canonical species
 *      'other'/'deal' до 'gift' / 'free_shipping' по keyword-эвристике
 *      когда CPA-сеть не отдаёт точный тип.
 *
 * Принцип «upgrade-only»: явный mapping ('promocode'/'gift'/'free_shipping')
 * никогда не downgrade-ится keyword-эвристикой.
 *
 * @package CashbackPlugin
 * @since   7.5.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cashback_Coupons_Icon_Resolver {

    /**
     * Канонические icon_type — строго 3 значения.
     */
    public const ICON_DISCOUNT      = 'discount';
    public const ICON_GIFT          = 'gift';
    public const ICON_FREE_SHIPPING = 'free_shipping';

    /**
     * Определить icon_type для строки купона.
     *
     * @param array{species?:string,name?:string,description?:string} $row
     */
    public static function resolve( array $row ): string {
        $species = isset( $row['species'] ) && is_string( $row['species'] )
            ? strtolower( $row['species'] )
            : '';

        switch ( $species ) {
            case self::ICON_GIFT:
                return self::ICON_GIFT;
            case self::ICON_FREE_SHIPPING:
                return self::ICON_FREE_SHIPPING;
            case 'promocode':
            case 'sale':
            case 'discount':
                return self::ICON_DISCOUNT;
            default:
                // 'deal' | 'other' | '' | unknown → пробуем text-эвристику.
                $name        = isset( $row['name'] ) && is_string( $row['name'] ) ? $row['name'] : '';
                $description = isset( $row['description'] ) && is_string( $row['description'] ) ? $row['description'] : '';
                $hint        = self::detect_from_text( $name . ' ' . $description );

                if ( self::ICON_GIFT === $hint ) {
                    return self::ICON_GIFT;
                }
                if ( self::ICON_FREE_SHIPPING === $hint ) {
                    return self::ICON_FREE_SHIPPING;
                }

                return self::ICON_DISCOUNT;
        }
    }

    /**
     * Эвристика по тексту (RU+EN). Приоритет: gift > free_shipping
     * (т.к. «подарок при бесплатной доставке» — это всё-таки про подарок).
     *
     * Возвращает 'gift' | 'free_shipping' | null.
     */
    public static function detect_from_text( string $text ): ?string {
        $text = function_exists( 'mb_strtolower' )
            ? mb_strtolower( $text, 'UTF-8' )
            : strtolower( $text );

        $gift_keywords = array( 'подарок', 'подарк', 'gift', 'present', 'пробник', 'sample' );
        foreach ( $gift_keywords as $kw ) {
            if ( '' !== $kw && false !== strpos( $text, $kw ) ) {
                return self::ICON_GIFT;
            }
        }

        $delivery_keywords = array( 'доставк', 'shipping', 'delivery' );
        $free_keywords     = array( 'беспл', 'free' );
        $has_delivery      = false;
        foreach ( $delivery_keywords as $kw ) {
            if ( false !== strpos( $text, $kw ) ) {
                $has_delivery = true;
                break;
            }
        }
        if ( $has_delivery ) {
            foreach ( $free_keywords as $kw ) {
                if ( false !== strpos( $text, $kw ) ) {
                    return self::ICON_FREE_SHIPPING;
                }
            }
        }

        return null;
    }
}
