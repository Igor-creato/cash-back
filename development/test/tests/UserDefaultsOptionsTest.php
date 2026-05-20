<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit-тесты Cashback_User_Defaults: round-trip опций + clamp/validate.
 *
 * Опции хранят глобальные дефолты cashback_rate / min_payout_amount, применяемые
 * при создании новых профилей в Mariadb_Plugin::add_user_to_profile().
 */
#[Group('user-defaults')]
final class UserDefaultsOptionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        if (!class_exists('Cashback_User_Defaults')) {
            require_once $plugin_root . '/includes/class-cashback-user-defaults.php';
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options'] = array();
    }

    public function test_fallback_when_option_not_set(): void
    {
        $this->assertSame('60.00', Cashback_User_Defaults::get_default_rate());
        $this->assertSame('100.00', Cashback_User_Defaults::get_default_min_payout());
    }

    public function test_set_default_rate_round_trip(): void
    {
        $saved = Cashback_User_Defaults::set_default_rate('75');
        $this->assertSame('75.00', $saved);
        $this->assertSame('75.00', Cashback_User_Defaults::get_default_rate());
    }

    public function test_set_default_min_payout_round_trip(): void
    {
        $saved = Cashback_User_Defaults::set_default_min_payout('250.5');
        $this->assertSame('250.50', $saved);
        $this->assertSame('250.50', Cashback_User_Defaults::get_default_min_payout());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid_rate_provider(): array
    {
        return array(
            'negative'            => array('-1'),
            'over_100'            => array('100.01'),
            'three_decimals'      => array('60.123'),
            'non_numeric'         => array('abc'),
            'empty'               => array(''),
            'comma_separator'     => array('60,5'),
            'leading_plus'        => array('+60'),
            'scientific_notation' => array('6e1'),
        );
    }

    /**
     * @dataProvider invalid_rate_provider
     */
    public function test_set_default_rate_rejects_invalid(string $bad): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_User_Defaults::set_default_rate($bad);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid_min_payout_provider(): array
    {
        return array(
            'zero'                => array('0'),
            'below_one'           => array('0.99'),
            'over_max'            => array('100000.01'),
            'three_decimals'      => array('150.123'),
            'non_numeric'         => array('abc'),
            'empty'               => array(''),
            'negative'            => array('-50'),
        );
    }

    /**
     * @dataProvider invalid_min_payout_provider
     */
    public function test_set_default_min_payout_rejects_invalid(string $bad): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cashback_User_Defaults::set_default_min_payout($bad);
    }

    public function test_set_default_rate_accepts_boundary_values(): void
    {
        $this->assertSame('0.00', Cashback_User_Defaults::set_default_rate('0'));
        $this->assertSame('100.00', Cashback_User_Defaults::set_default_rate('100'));
    }

    public function test_set_default_min_payout_accepts_boundary_values(): void
    {
        $this->assertSame('1.00', Cashback_User_Defaults::set_default_min_payout('1'));
        $this->assertSame('100000.00', Cashback_User_Defaults::set_default_min_payout('100000'));
    }

    public function test_sanitize_callback_falls_back_on_corrupted_option(): void
    {
        // Симулируем повреждённое значение в БД (некорректные данные в опции).
        $GLOBALS['_cb_test_options'][ Cashback_User_Defaults::OPT_RATE ] = '999';
        $GLOBALS['_cb_test_options'][ Cashback_User_Defaults::OPT_MIN_PAYOUT ] = 'garbage';

        $this->assertSame(
            Cashback_User_Defaults::FALLBACK_RATE,
            Cashback_User_Defaults::get_default_rate(),
            'get_default_rate() должен sanitize некорректное значение в FALLBACK_RATE.'
        );
        $this->assertSame(
            Cashback_User_Defaults::FALLBACK_MIN_PAYOUT,
            Cashback_User_Defaults::get_default_min_payout(),
            'get_default_min_payout() должен sanitize некорректное значение в FALLBACK_MIN_PAYOUT.'
        );
    }

    public function test_sanitize_callback_normalizes_decimals(): void
    {
        $this->assertSame('60.00', Cashback_User_Defaults::sanitize_rate('60'));
        $this->assertSame('100.00', Cashback_User_Defaults::sanitize_min_payout('100'));
        $this->assertSame('33.33', Cashback_User_Defaults::sanitize_rate('33.33'));
    }
}
