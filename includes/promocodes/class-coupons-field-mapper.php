<?php

/**
 * Field-mapper для купонов CPA-сетей.
 *
 * Преобразует raw payload сети (JSON-объект из API) в массив,
 * готовый для Cashback_Coupon_DTO::from_array(). Применяет:
 *   - field_map (rename ключей: 'id' → 'external_id').
 *   - species_map (normalize: 'promo_code' → 'promocode').
 *   - сохраняет оригинальный raw в ключ 'raw_payload'.
 *
 * НЕ делает фильтрацию по статусу/датам/регионам — это задача
 * fetcher'а / repository (фильтр RU + active + dates).
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Coupons_Field_Mapper {

    /**
     * Преобразовать raw coupon из API в DTO-совместимый массив.
     *
     * @param array<string,mixed>  $raw          Один купон из API-ответа.
     * @param array<string,string> $field_map    raw_key → dto_key.
     * @param array<string,string> $species_map  raw_species → canonical (promocode|deal|...).
     * @return array<string,mixed> Готовый для Cashback_Coupon_DTO::from_array().
     */
    public function map( array $raw, array $field_map, array $species_map ): array {
        $mapped = array();

        foreach ( $field_map as $raw_key => $dto_key ) {
            if ( ! array_key_exists( $raw_key, $raw ) ) {
                continue;
            }
            $value = $raw[ $raw_key ];
            // external_id всегда сериализуем в string (сети возвращают int или string).
            if ( $dto_key === 'external_id' ) {
                $value = (string) $value;
            }
            $mapped[ $dto_key ] = $value;
        }

        // Normalize species через species_map. Канонические значения: promocode|deal.
        // Всё незнакомое → 'other' (не падаем).
        $species_raw = $mapped['species_raw'] ?? null;
        if ( is_string( $species_raw ) && $species_raw !== '' ) {
            $key = strtolower( $species_raw );
            $mapped['species'] = $species_map[ $key ] ?? $species_map[ $species_raw ] ?? 'other';
        } elseif ( ! isset( $mapped['species'] ) ) {
            $mapped['species'] = 'other';
        }

        // Сохраняем raw для DTO.raw_payload (DTO::from_array распаковывает его явно).
        $mapped['raw_payload'] = $raw;

        return $mapped;
    }
}
