<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты на Cashback_Coupon_DTO::from_array() — нормализованный объект
 * купона между repository, fetcher и shortcode.
 *
 * @group promocodes
 * @group dto
 */
#[Group('promocodes')]
#[Group('dto')]
final class CouponDtoTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        $dto_file = self::$plugin_root . '/includes/promocodes/dto/class-coupon-dto.php';
        if (!file_exists($dto_file)) {
            self::markTestSkipped('Cashback_Coupon_DTO not present yet.');
        }
        if (!class_exists('Cashback_Coupon_DTO')) {
            require_once $dto_file;
        }
    }

    private function valid_payload(array $overrides = []): array
    {
        return array_merge(array(
            'external_id' => 'C123',
            'species'     => 'promocode',
            'promocode'   => 'SAVE10',
            'name'        => 'Скидка 10%',
            'goto_link'   => 'https://ad.admitad.com/g/abc123/',
            'date_start'  => '2026-01-01 00:00:00',
            'date_end'    => '2026-12-31 23:59:59',
            'regions'     => 'RU',
        ), $overrides);
    }

    public function test_from_array_creates_dto_with_required_fields(): void
    {
        $dto = Cashback_Coupon_DTO::from_array($this->valid_payload());

        $this->assertSame('C123', $dto->external_id);
        $this->assertSame('promocode', $dto->species);
        $this->assertSame('SAVE10', $dto->promocode);
        $this->assertSame('Скидка 10%', $dto->name);
        $this->assertSame('https://ad.admitad.com/g/abc123/', $dto->goto_link);
    }

    public function test_from_array_throws_on_missing_external_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Coupon_DTO::from_array($this->valid_payload(array( 'external_id' => '' )));
    }

    public function test_from_array_throws_on_missing_goto_link(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Coupon_DTO::from_array($this->valid_payload(array( 'goto_link' => '' )));
    }

    public function test_from_array_throws_on_missing_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Coupon_DTO::from_array($this->valid_payload(array( 'name' => '' )));
    }

    public function test_from_array_throws_on_missing_species(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Coupon_DTO::from_array($this->valid_payload(array( 'species' => '' )));
    }

    public function test_from_array_throws_on_invalid_goto_link_url(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Coupon_DTO::from_array($this->valid_payload(array( 'goto_link' => 'not-a-url' )));
    }

    public function test_promocode_is_null_for_deals(): void
    {
        $dto = Cashback_Coupon_DTO::from_array($this->valid_payload(array(
            'species'   => 'deal',
            'promocode' => null,
        )));

        $this->assertSame('deal', $dto->species);
        $this->assertNull($dto->promocode);
    }

    public function test_dates_parsed_as_datetime_immutable(): void
    {
        $dto = Cashback_Coupon_DTO::from_array($this->valid_payload(array(
            'date_start' => '2026-01-01 00:00:00',
            'date_end'   => '2026-12-31 23:59:59',
        )));

        $this->assertInstanceOf(DateTimeImmutable::class, $dto->date_start);
        $this->assertInstanceOf(DateTimeImmutable::class, $dto->date_end);
        $this->assertSame('2026-01-01', $dto->date_start->format('Y-m-d'));
        $this->assertSame('2026-12-31', $dto->date_end->format('Y-m-d'));
    }

    public function test_null_dates_remain_null(): void
    {
        $dto = Cashback_Coupon_DTO::from_array($this->valid_payload(array(
            'date_start' => null,
            'date_end'   => null,
        )));

        $this->assertNull($dto->date_start);
        $this->assertNull($dto->date_end);
    }

    public function test_regions_parsed_as_array_from_csv(): void
    {
        $dto = Cashback_Coupon_DTO::from_array($this->valid_payload(array(
            'regions' => 'RU,KZ,BY',
        )));

        $this->assertSame(array( 'RU', 'KZ', 'BY' ), $dto->regions);
    }

    public function test_regions_parsed_from_array(): void
    {
        $dto = Cashback_Coupon_DTO::from_array($this->valid_payload(array(
            'regions' => array( 'RU', 'KZ' ),
        )));

        $this->assertSame(array( 'RU', 'KZ' ), $dto->regions);
    }

    public function test_categories_default_to_empty_array(): void
    {
        $dto = Cashback_Coupon_DTO::from_array($this->valid_payload());
        $this->assertSame(array(), $dto->categories);
    }

    public function test_is_exclusive_defaults_to_false(): void
    {
        $dto = Cashback_Coupon_DTO::from_array($this->valid_payload());
        $this->assertFalse($dto->is_exclusive);
    }

    public function test_is_exclusive_truthy_values(): void
    {
        foreach (array( true, 1, '1', 'yes' ) as $truthy) {
            $dto = Cashback_Coupon_DTO::from_array($this->valid_payload(array(
                'is_exclusive' => $truthy,
            )));
            $this->assertTrue($dto->is_exclusive, 'truthy value: ' . var_export($truthy, true));
        }
    }

    public function test_raw_payload_preserves_original(): void
    {
        $payload  = $this->valid_payload(array( 'extra_field' => 'preserved' ));
        $dto      = Cashback_Coupon_DTO::from_array($payload);

        $this->assertSame($payload, $dto->raw_payload);
    }

    public function test_throws_on_invalid_date_format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_Coupon_DTO::from_array($this->valid_payload(array(
            'date_start' => 'not-a-date',
        )));
    }
}
