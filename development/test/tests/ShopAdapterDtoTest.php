<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на DTO для shop importer (v12):
 *   - Cashback_Campaign_Detail_DTO — детальная кампания из API
 *   - Cashback_Shop_Tariff_DTO     — один тариф магазина
 *
 * Проверяем roundtrip from_array → properties → to_array, дефолты, валидацию.
 *
 * @group shop-import
 * @group adapters
 * @group dto
 */
#[Group('shop-import')]
#[Group('adapters')]
#[Group('dto')]
final class ShopAdapterDtoTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        self::require_if_missing('/includes/adapters/class-cashback-campaign-detail-dto.php', 'Cashback_Campaign_Detail_DTO');
        self::require_if_missing('/includes/adapters/class-cashback-shop-tariff-dto.php', 'Cashback_Shop_Tariff_DTO');
    }

    private static function require_if_missing(string $relative, string $class): void
    {
        if (class_exists($class)) {
            return;
        }
        $path = self::$plugin_root . $relative;
        if (!file_exists($path)) {
            self::markTestSkipped("File missing: {$relative}");
        }
        require_once $path;
    }

    // ============================================================
    // Cashback_Campaign_Detail_DTO
    // ============================================================

    public function test_campaign_dto_roundtrip_full_payload(): void
    {
        $input = array(
            'id'          => 'admitad-12345',
            'name'        => 'Joom',
            'site_url'    => 'https://www.joom.com/ru',
            'image_url'   => 'https://cdn.example.com/logo.png',
            'description' => 'International marketplace',
            'status_raw'  => 'active',
            'is_active'   => true,
            'regions'     => array('RU', 'BY'),
            'categories'  => array('electronics', 'apparel'),
            'currency'    => 'rub',
            'goto_link'   => 'https://ad.admitad.com/g/abc?subid={uid}',
            'raw'         => array('extra' => 'meta'),
        );

        $dto = Cashback_Campaign_Detail_DTO::from_array($input);

        $this->assertSame('admitad-12345', $dto->id);
        $this->assertSame('Joom', $dto->name);
        $this->assertSame('https://www.joom.com/ru', $dto->site_url);
        $this->assertSame('RUB', $dto->currency, 'currency должна нормализоваться к UPPER');
        $this->assertTrue($dto->is_active);
        $this->assertSame(array('RU', 'BY'), $dto->regions);
        $this->assertSame(array('electronics', 'apparel'), $dto->categories);
        $this->assertSame(array('extra' => 'meta'), $dto->raw);

        $array = $dto->to_array();
        $this->assertSame('admitad-12345', $array['id']);
        $this->assertSame('RUB', $array['currency']);
    }

    public function test_campaign_dto_defaults_for_missing_optional_fields(): void
    {
        $dto = Cashback_Campaign_Detail_DTO::from_array(array('id' => '777'));

        $this->assertSame('777', $dto->id);
        $this->assertSame('', $dto->name);
        $this->assertSame('', $dto->site_url);
        $this->assertSame('RUB', $dto->currency, 'default currency = RUB');
        $this->assertFalse($dto->is_active);
        $this->assertSame(array(), $dto->regions);
        $this->assertSame(array(), $dto->categories);
        $this->assertSame(array(), $dto->raw);
    }

    public function test_campaign_dto_throws_on_missing_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cashback_Campaign_Detail_DTO::from_array(array('name' => 'no-id'));
    }

    public function test_campaign_dto_throws_on_empty_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cashback_Campaign_Detail_DTO::from_array(array('id' => ''));
    }

    public function test_campaign_dto_invalid_currency_falls_back_to_rub(): void
    {
        $dto = Cashback_Campaign_Detail_DTO::from_array(array(
            'id'       => '1',
            'currency' => 'rubles', // не 3-буквенный — fallback
        ));
        $this->assertSame('RUB', $dto->currency);
    }

    public function test_campaign_dto_regions_coerced_to_strings(): void
    {
        $dto = Cashback_Campaign_Detail_DTO::from_array(array(
            'id'      => '1',
            'regions' => array(1, 2, 'RU'),
        ));
        $this->assertSame(array('1', '2', 'RU'), $dto->regions);
    }

    // ============================================================
    // Cashback_Shop_Tariff_DTO
    // ============================================================

    public function test_tariff_dto_roundtrip_percent(): void
    {
        $input = array(
            'tariff_id'    => 'cat-5',
            'name'         => 'Оплаченный заказ из категории 5',
            'tariff_type'  => 'percent',
            'payment_size' => 15.05,
            'payment_min'  => 1.0,
            'payment_max'  => 100.0,
            'currency'     => 'RUB',
            'is_default'   => false,
            'raw'          => array('id' => 'cat-5'),
        );

        $dto = Cashback_Shop_Tariff_DTO::from_array($input);

        $this->assertSame('cat-5', $dto->tariff_id);
        $this->assertSame('percent', $dto->tariff_type);
        $this->assertSame(15.05, $dto->payment_size);
        $this->assertSame(1.0, $dto->payment_min);
        $this->assertSame(100.0, $dto->payment_max);
        $this->assertSame('RUB', $dto->currency);
        $this->assertFalse($dto->is_default);

        $array = $dto->to_array();
        $this->assertSame('percent', $array['tariff_type']);
        $this->assertSame(15.05, $array['payment_size']);
    }

    public function test_tariff_dto_roundtrip_fix(): void
    {
        $input = array(
            'tariff_id'    => 'flat',
            'tariff_type'  => 'fix',
            'payment_size' => 65.00,
            'currency'     => 'EUR',
            'is_default'   => true,
        );

        $dto = Cashback_Shop_Tariff_DTO::from_array($input);

        $this->assertSame('flat', $dto->tariff_id);
        $this->assertSame('fix', $dto->tariff_type);
        $this->assertSame(65.00, $dto->payment_size);
        $this->assertNull($dto->payment_min);
        $this->assertNull($dto->payment_max);
        $this->assertSame('EUR', $dto->currency);
        $this->assertTrue($dto->is_default);
    }

    public function test_tariff_dto_throws_on_missing_tariff_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cashback_Shop_Tariff_DTO::from_array(array(
            'tariff_type'  => 'percent',
            'payment_size' => 5,
        ));
    }

    public function test_tariff_dto_throws_on_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cashback_Shop_Tariff_DTO::from_array(array(
            'tariff_id'   => '1',
            'tariff_type' => 'unknown',
        ));
    }

    public function test_tariff_dto_payment_size_clamps_negative_to_zero(): void
    {
        $dto = Cashback_Shop_Tariff_DTO::from_array(array(
            'tariff_id'    => '1',
            'tariff_type'  => 'percent',
            'payment_size' => -5.0,
        ));
        $this->assertSame(0.0, $dto->payment_size);
    }

    public function test_tariff_dto_invalid_currency_falls_back_to_rub(): void
    {
        $dto = Cashback_Shop_Tariff_DTO::from_array(array(
            'tariff_id'   => '1',
            'tariff_type' => 'fix',
            'currency'    => '',
        ));
        $this->assertSame('RUB', $dto->currency);
    }

    public function test_tariff_dto_type_constants_are_canonical(): void
    {
        $this->assertSame('percent', Cashback_Shop_Tariff_DTO::TYPE_PERCENT);
        $this->assertSame('fix',     Cashback_Shop_Tariff_DTO::TYPE_FIX);
    }

    public function test_tariff_dto_type_lowercased_on_input(): void
    {
        // Адаптеры могут вернуть 'PERCENT' или 'Fixed' — DTO нормализует.
        $dto = Cashback_Shop_Tariff_DTO::from_array(array(
            'tariff_id'   => '1',
            'tariff_type' => 'PERCENT',
        ));
        $this->assertSame('percent', $dto->tariff_type);
    }
}
