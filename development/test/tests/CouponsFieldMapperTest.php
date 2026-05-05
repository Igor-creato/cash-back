<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты на Cashback_Coupons_Field_Mapper — преобразует raw payload сети
 * в массив, готовый для Cashback_Coupon_DTO::from_array().
 *
 * Применяет:
 *   - field_map: переименование raw → DTO ключей.
 *   - species_map: normalize raw species → canonical 'promocode'|'deal'.
 *   - parse дат и regions делает уже DTO::from_array.
 *
 * @group promocodes
 * @group dto
 */
#[Group('promocodes')]
#[Group('dto')]
final class CouponsFieldMapperTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        $files = array(
            self::$plugin_root . '/includes/promocodes/dto/class-coupon-dto.php',
            self::$plugin_root . '/includes/promocodes/class-coupons-field-mapper.php',
            self::$plugin_root . '/includes/promocodes/class-cashback-coupons-icon-resolver.php',
        );
        foreach ($files as $f) {
            if (!file_exists($f)) {
                self::markTestSkipped("File missing: {$f}");
            }
        }
        if (!class_exists('Cashback_Coupon_DTO')) {
            require_once $files[0];
        }
        if (!class_exists('Cashback_Coupons_Icon_Resolver')) {
            require_once $files[2];
        }
        if (!class_exists('Cashback_Coupons_Field_Mapper')) {
            require_once $files[1];
        }
    }

    private function admitad_field_map(): array
    {
        return array(
            'id'          => 'external_id',
            'promocode'   => 'promocode',
            'name'        => 'name',
            'description' => 'description',
            'discount'    => 'discount',
            'date_start'  => 'date_start',
            'date_end'    => 'date_end',
            'status'      => 'status',
            'regions'     => 'regions',
            'goto_link'   => 'goto_link',
            'exclusive'   => 'is_exclusive',
            'type'        => 'species_raw',
        );
    }

    private function admitad_species_map(): array
    {
        return array(
            'promocode'  => 'promocode',
            'promo_code' => 'promocode',
            'deal'       => 'deal',
            'sale'       => 'deal',
            'discount'   => 'deal',
        );
    }

    private function admitad_raw_coupon(array $overrides = []): array
    {
        return array_merge(array(
            'id'          => 12345,
            'promocode'   => 'SAVE10',
            'name'        => 'Скидка 10% на всё',
            'description' => 'Промокод на скидку',
            'discount'    => '10%',
            'date_start'  => '2026-01-01 00:00:00',
            'date_end'    => '2026-12-31 23:59:59',
            'status'      => 'active',
            'regions'     => 'RU,KZ',
            'goto_link'   => 'https://ad.admitad.com/g/abc123/',
            'exclusive'   => 0,
            'type'        => 'promo_code',
        ), $overrides);
    }

    public function test_maps_id_to_external_id(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        $mapped = $mapper->map( $this->admitad_raw_coupon(), $this->admitad_field_map(), $this->admitad_species_map() );

        $this->assertSame('12345', $mapped['external_id']);
    }

    public function test_maps_promo_code_species_to_canonical_promocode(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        $mapped = $mapper->map( $this->admitad_raw_coupon(), $this->admitad_field_map(), $this->admitad_species_map() );

        // raw type=promo_code → species_raw → species=promocode (canonical).
        $this->assertSame('promocode', $mapped['species']);
    }

    public function test_maps_sale_to_canonical_deal(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        $raw = $this->admitad_raw_coupon(array( 'type' => 'sale', 'promocode' => null ));
        $mapped = $mapper->map( $raw, $this->admitad_field_map(), $this->admitad_species_map() );

        $this->assertSame('deal', $mapped['species']);
    }

    public function test_unknown_species_falls_back_to_other(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        $raw = $this->admitad_raw_coupon(array( 'type' => 'unknown_xyz' ));
        $mapped = $mapper->map( $raw, $this->admitad_field_map(), $this->admitad_species_map() );

        $this->assertSame('other', $mapped['species']);
    }

    public function test_preserves_raw_payload_for_dto(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        $raw    = $this->admitad_raw_coupon();
        $mapped = $mapper->map( $raw, $this->admitad_field_map(), $this->admitad_species_map() );

        $this->assertArrayHasKey('raw_payload', $mapped);
        $this->assertSame($raw, $mapped['raw_payload']);
    }

    public function test_full_pipeline_produces_valid_dto(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        $mapped = $mapper->map( $this->admitad_raw_coupon(), $this->admitad_field_map(), $this->admitad_species_map() );

        $dto = Cashback_Coupon_DTO::from_array($mapped);

        $this->assertSame('12345', $dto->external_id);
        $this->assertSame('promocode', $dto->species);
        $this->assertSame('SAVE10', $dto->promocode);
        $this->assertSame('Скидка 10% на всё', $dto->name);
        $this->assertSame(array( 'RU', 'KZ' ), $dto->regions);
        $this->assertInstanceOf(DateTimeImmutable::class, $dto->date_start);
        // raw_payload должен содержать оригинал, не mapped.
        $this->assertSame(12345, $dto->raw_payload['id']);
        $this->assertSame('promo_code', $dto->raw_payload['type']);
    }

    public function test_missing_optional_fields_omitted(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        // Минимальный raw — только обязательные.
        $raw = array(
            'id'        => 'X1',
            'name'      => 'Минимум',
            'goto_link' => 'https://example.com/go',
            'type'      => 'deal',
        );
        $mapped = $mapper->map( $raw, $this->admitad_field_map(), $this->admitad_species_map() );

        $this->assertSame('X1', $mapped['external_id']);
        $this->assertSame('deal', $mapped['species']);
        $this->assertSame('Минимум', $mapped['name']);
        // Опциональных не должно быть лишних null'ов в выходном массиве —
        // DTO::from_array сам разберётся с отсутствием.
    }

    public function test_status_filter_can_be_applied_externally(): void
    {
        // Mapper НЕ фильтрует по status — это задача fetcher'а / repository.
        // Просто проверяем что status пробрасывается в mapped как есть.
        $mapper = new Cashback_Coupons_Field_Mapper();
        $raw = $this->admitad_raw_coupon(array( 'status' => 'inactive' ));
        $mapped = $mapper->map( $raw, $this->admitad_field_map(), $this->admitad_species_map() );

        $this->assertSame('inactive', $mapped['status']);
    }

    public function test_handles_unix_timestamp_in_dates(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        $raw = $this->admitad_raw_coupon(array(
            'date_start' => 1735689600, // 2025-01-01 UTC
        ));
        $mapped = $mapper->map( $raw, $this->admitad_field_map(), $this->admitad_species_map() );
        $dto    = Cashback_Coupon_DTO::from_array($mapped);

        $this->assertInstanceOf(DateTimeImmutable::class, $dto->date_start);
        $this->assertSame('2025-01-01', $dto->date_start->format('Y-m-d'));
    }

    public function test_array_regions_passes_through(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        $raw = $this->admitad_raw_coupon(array( 'regions' => array( 'RU', 'BY' ) ));
        $mapped = $mapper->map( $raw, $this->admitad_field_map(), $this->admitad_species_map() );
        $dto    = Cashback_Coupon_DTO::from_array($mapped);

        $this->assertSame(array( 'RU', 'BY' ), $dto->regions);
    }

    public function test_species_map_can_yield_canonical_gift(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        $species_map = array_merge($this->admitad_species_map(), array(
            'gift'        => 'gift',
            'present'     => 'gift',
        ));
        $raw    = $this->admitad_raw_coupon(array( 'type' => 'gift', 'promocode' => null ));
        $mapped = $mapper->map($raw, $this->admitad_field_map(), $species_map);

        $this->assertSame('gift', $mapped['species']);
    }

    public function test_species_map_can_yield_canonical_free_shipping(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        $species_map = array_merge($this->admitad_species_map(), array(
            'free_shipping' => 'free_shipping',
            'shipping'      => 'free_shipping',
        ));
        $raw    = $this->admitad_raw_coupon(array( 'type' => 'free_shipping', 'promocode' => null ));
        $mapped = $mapper->map($raw, $this->admitad_field_map(), $species_map);

        $this->assertSame('free_shipping', $mapped['species']);
    }

    public function test_other_with_gift_keyword_in_name_is_upgraded_to_gift(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        $raw    = $this->admitad_raw_coupon(array(
            'type' => 'unknown_xyz',
            'name' => 'Подарок при покупке от 3000₽',
            'promocode' => null,
        ));
        $mapped = $mapper->map($raw, $this->admitad_field_map(), $this->admitad_species_map());

        // Mapper-result 'other' проходит через text-эвристику и апгрейдится до 'gift'.
        $this->assertSame('gift', $mapped['species']);
    }

    public function test_other_with_free_shipping_keyword_is_upgraded(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        $raw    = $this->admitad_raw_coupon(array(
            'type'        => 'unknown_xyz',
            'name'        => 'Free shipping for orders > $50',
            'description' => 'на все категории',
            'promocode'   => null,
        ));
        $mapped = $mapper->map($raw, $this->admitad_field_map(), $this->admitad_species_map());

        $this->assertSame('free_shipping', $mapped['species']);
    }

    public function test_explicit_promocode_species_is_not_downgraded_by_text(): void
    {
        $mapper = new Cashback_Coupons_Field_Mapper();
        // type=promo_code → species=promocode (явный mapping). Слово «подарок» в name
        // НЕ должно перебить.
        $raw    = $this->admitad_raw_coupon(array(
            'type' => 'promo_code',
            'name' => 'Подарок к покупке',
        ));
        $mapped = $mapper->map($raw, $this->admitad_field_map(), $this->admitad_species_map());

        $this->assertSame('promocode', $mapped['species']);
    }
}
