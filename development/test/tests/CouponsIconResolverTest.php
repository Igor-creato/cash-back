<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты на Cashback_Coupons_Icon_Resolver — чистый mapper
 *   species + name + description → 'discount'|'gift'|'free_shipping'.
 *
 * Без WP-зависимостей. Используется и в шорткоде [cashback_coupons_icons]
 * (для рендера), и в Cashback_Coupons_Field_Mapper (для апгрейда species
 * по тексту, когда CPA-сеть не даёт точного типа).
 *
 * @group promocodes
 * @group coupons-icons
 */
#[Group('promocodes')]
#[Group('coupons-icons')]
final class CouponsIconResolverTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $file        = $plugin_root . '/includes/promocodes/class-cashback-coupons-icon-resolver.php';
        if (!file_exists($file)) {
            self::markTestSkipped("File missing: {$file}");
        }
        if (!class_exists('Cashback_Coupons_Icon_Resolver')) {
            require_once $file;
        }
    }

    private function row(string $species, string $name = '', string $description = ''): array
    {
        return array(
            'species'     => $species,
            'name'        => $name,
            'description' => $description,
        );
    }

    public function test_species_gift_resolves_to_gift(): void
    {
        $this->assertSame('gift', Cashback_Coupons_Icon_Resolver::resolve($this->row('gift')));
    }

    public function test_species_free_shipping_resolves_to_free_shipping(): void
    {
        $this->assertSame('free_shipping', Cashback_Coupons_Icon_Resolver::resolve($this->row('free_shipping')));
    }

    public function test_species_promocode_resolves_to_discount(): void
    {
        $this->assertSame('discount', Cashback_Coupons_Icon_Resolver::resolve($this->row('promocode')));
    }

    public function test_species_sale_resolves_to_discount(): void
    {
        $this->assertSame('discount', Cashback_Coupons_Icon_Resolver::resolve($this->row('sale')));
    }

    public function test_species_discount_resolves_to_discount(): void
    {
        $this->assertSame('discount', Cashback_Coupons_Icon_Resolver::resolve($this->row('discount')));
    }

    public function test_species_other_with_no_keywords_falls_back_to_discount(): void
    {
        $this->assertSame('discount', Cashback_Coupons_Icon_Resolver::resolve(
            $this->row('other', 'Купон', 'Без особых ключевых слов')
        ));
    }

    public function test_species_deal_with_gift_keyword_upgrades_to_gift(): void
    {
        $this->assertSame('gift', Cashback_Coupons_Icon_Resolver::resolve(
            $this->row('deal', 'Подарок при заказе', '')
        ));
    }

    public function test_species_other_with_english_gift_keyword_upgrades(): void
    {
        $this->assertSame('gift', Cashback_Coupons_Icon_Resolver::resolve(
            $this->row('other', 'Free Gift on order', '')
        ));
    }

    public function test_species_deal_with_free_shipping_keyword_upgrades(): void
    {
        $this->assertSame('free_shipping', Cashback_Coupons_Icon_Resolver::resolve(
            $this->row('deal', 'Бесплатная доставка', 'Заказы от 2000₽')
        ));
    }

    public function test_species_other_with_english_free_shipping_keyword_upgrades(): void
    {
        $this->assertSame('free_shipping', Cashback_Coupons_Icon_Resolver::resolve(
            $this->row('other', 'Free shipping for orders > $50', '')
        ));
    }

    public function test_keyword_priority_gift_wins_over_shipping_in_mixed_text(): void
    {
        $this->assertSame('gift', Cashback_Coupons_Icon_Resolver::resolve(
            $this->row('other', 'Подарок при бесплатной доставке', '')
        ));
    }

    public function test_explicit_promocode_species_is_not_downgraded_by_keywords(): void
    {
        // Если CPA-сеть явно сказала «promocode» — даже наличие слова «подарок»
        // в названии НЕ должно перебить явный mapping. discount остаётся.
        $this->assertSame('discount', Cashback_Coupons_Icon_Resolver::resolve(
            $this->row('promocode', 'Подарок к покупке', '')
        ));
    }

    public function test_unknown_species_with_no_text_returns_discount_default(): void
    {
        $this->assertSame('discount', Cashback_Coupons_Icon_Resolver::resolve($this->row('')));
    }

    public function test_detect_from_text_returns_null_when_nothing_matches(): void
    {
        $this->assertNull(Cashback_Coupons_Icon_Resolver::detect_from_text('Просто скидка на товар'));
    }

    public function test_detect_from_text_returns_gift_for_ru(): void
    {
        $this->assertSame('gift', Cashback_Coupons_Icon_Resolver::detect_from_text('Подарок при покупке'));
    }

    public function test_detect_from_text_returns_free_shipping_for_en(): void
    {
        $this->assertSame('free_shipping', Cashback_Coupons_Icon_Resolver::detect_from_text('Get free delivery on all orders'));
    }

    public function test_detect_from_text_requires_both_free_and_delivery_words(): void
    {
        // Просто «доставка» без «бесплатная» — не считается free_shipping.
        $this->assertNull(Cashback_Coupons_Icon_Resolver::detect_from_text('Быстрая доставка по Москве'));
    }

    public function test_detect_from_text_handles_mixed_case(): void
    {
        $this->assertSame('gift', Cashback_Coupons_Icon_Resolver::detect_from_text('GIFT for new customers'));
    }
}
