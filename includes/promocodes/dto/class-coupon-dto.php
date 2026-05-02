<?php

/**
 * Нормализованный DTO купона CPA-сети.
 *
 * Адаптеры (generic JSON или code-based) превращают raw-payload сети
 * в Cashback_Coupon_DTO[]. Repository работает только с этим DTO,
 * не зная про Admitad/CityAds/EPN/etc.
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Coupon_DTO {

    public function __construct(
        public readonly string $external_id,
        public readonly string $species,
        public readonly ?string $promocode,
        public readonly string $name,
        public readonly ?string $short_name,
        public readonly ?string $description,
        public readonly ?string $discount,
        public readonly ?DateTimeImmutable $date_start,
        public readonly ?DateTimeImmutable $date_end,
        public readonly array $regions,
        public readonly array $categories,
        public readonly ?string $image_url,
        public readonly string $goto_link,
        public readonly bool $is_exclusive,
        public readonly ?float $rating,
        public readonly array $raw_payload
    ) {}

    /**
     * Создать DTO из ассоциативного массива (после field-mapper'а).
     *
     * @param array<string,mixed> $data
     * @throws InvalidArgumentException Когда обязательные поля пусты или некорректны.
     */
    public static function from_array( array $data ): self {
        $external_id = isset( $data['external_id'] ) ? (string) $data['external_id'] : '';
        $species     = isset( $data['species'] ) ? (string) $data['species'] : '';
        $name        = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
        $goto_link   = isset( $data['goto_link'] ) ? (string) $data['goto_link'] : '';

        if ( $external_id === '' ) {
            throw new InvalidArgumentException( 'Coupon DTO: external_id обязателен' );
        }
        if ( $species === '' ) {
            throw new InvalidArgumentException( 'Coupon DTO: species обязателен' );
        }
        if ( $name === '' ) {
            throw new InvalidArgumentException( 'Coupon DTO: name обязателен' );
        }
        if ( $goto_link === '' ) {
            throw new InvalidArgumentException( 'Coupon DTO: goto_link обязателен' );
        }
        if ( filter_var( $goto_link, FILTER_VALIDATE_URL ) === false ) {
            throw new InvalidArgumentException( 'Coupon DTO: goto_link должен быть валидным URL' );
        }

        $promocode = $data['promocode'] ?? null;
        if ( $promocode !== null ) {
            $promocode = trim( (string) $promocode );
            if ( $promocode === '' ) {
                $promocode = null;
            }
        }

        $short_name  = self::optional_string( $data['short_name'] ?? null );
        $description = self::optional_string( $data['description'] ?? null );
        $discount    = self::optional_string( $data['discount'] ?? null );
        $image_url   = self::optional_string( $data['image_url'] ?? null );

        $date_start = self::parse_date( $data['date_start'] ?? null, 'date_start' );
        $date_end   = self::parse_date( $data['date_end'] ?? null, 'date_end' );

        $regions    = self::parse_string_array( $data['regions'] ?? null );
        $categories = self::parse_string_array( $data['categories'] ?? null );

        $is_exclusive = self::parse_bool( $data['is_exclusive'] ?? false );

        $rating = null;
        if ( isset( $data['rating'] ) && $data['rating'] !== '' && $data['rating'] !== null ) {
            $rating = (float) $data['rating'];
        }

        // raw_payload: либо явно переданный (mapper сохраняет оригинал API),
        // либо весь $data (для unit-тестов и manual construction).
        $raw_payload = ( isset( $data['raw_payload'] ) && is_array( $data['raw_payload'] ) )
            ? $data['raw_payload']
            : $data;

        return new self(
            $external_id,
            $species,
            $promocode,
            $name,
            $short_name,
            $description,
            $discount,
            $date_start,
            $date_end,
            $regions,
            $categories,
            $image_url,
            $goto_link,
            $is_exclusive,
            $rating,
            $raw_payload
        );
    }

    private static function optional_string( mixed $value ): ?string {
        if ( $value === null ) {
            return null;
        }
        $str = trim( (string) $value );
        return $str === '' ? null : $str;
    }

    /**
     * Парсит дату из ISO 8601, unix timestamp, 'Y-m-d H:i:s' или 'Y-m-d'.
     * Возвращает null для null/''.
     *
     * @throws InvalidArgumentException На некорректном формате.
     */
    private static function parse_date( mixed $value, string $field ): ?DateTimeImmutable {
        if ( $value === null || $value === '' ) {
            return null;
        }
        if ( $value instanceof DateTimeImmutable ) {
            return $value;
        }
        if ( $value instanceof DateTimeInterface ) {
            return DateTimeImmutable::createFromInterface( $value );
        }
        if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
            $ts = (int) $value;
            $dt = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( 'UTC' ) );
            return $dt;
        }
        if ( ! is_string( $value ) ) {
            throw new InvalidArgumentException( esc_html( "Coupon DTO: {$field} должен быть string|int|DateTimeInterface" ) );
        }

        try {
            return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
        } catch ( \Exception $e ) {
            throw new InvalidArgumentException( esc_html( "Coupon DTO: {$field} имеет некорректный формат — " . $e->getMessage() ) );
        }
    }

    /**
     * Нормализует regions/categories в массив строк.
     * Принимает: null, string (CSV), array (любой shape).
     *
     * @return string[]
     */
    private static function parse_string_array( mixed $value ): array {
        if ( $value === null || $value === '' ) {
            return array();
        }
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $item ) {
                $str = trim( (string) $item );
                if ( $str !== '' ) {
                    $out[] = $str;
                }
            }
            return $out;
        }
        $items = array_map( 'trim', explode( ',', (string) $value ) );
        return array_values( array_filter( $items, static fn( $s ) => $s !== '' ) );
    }

    private static function parse_bool( mixed $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_int( $value ) ) {
            return $value !== 0;
        }
        if ( is_string( $value ) ) {
            $normalized = strtolower( trim( $value ) );
            return in_array( $normalized, array( '1', 'true', 'yes', 'y', 'on' ), true );
        }
        return false;
    }
}
