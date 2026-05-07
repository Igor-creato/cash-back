<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тест fallback-окна `date_start` для cron-sync через Admitad API.
 *
 * Контекст: до этой задачи 3 места в Cashback_API_Client использовали
 * хардкод `'01.01.2020'` как fallback при отсутствии checkpoint /
 * user_registered. На широких окнах (~6 лет) `/statistics/actions/`
 * Admitad деградирует — наблюдалось чередование HTTP 500 и cURL timeout 60s
 * при 0 действий локально. Узкое окно (≤30 дней) отвечает мгновенно 200 OK.
 *
 * Решение: централизованный приватный метод `default_lookback_date_dmy()`,
 * возвращающий дату `today - 180 days` в формате `d.m.Y`. Используется как
 * fallback во всех ветках `validate_user_transactions`, `validate_unregistered`
 * и периодического sync.
 *
 * Тесты:
 *  - Поведенческий: метод возвращает корректную дату 180 дней назад в d.m.Y.
 *  - Структурный: в продакшен-коде api-client не осталось хардкода `01.01.2020`
 *    (защита от регрессии — если кто-то вернёт хардкод, тест поймает).
 *
 * @group api-client
 * @group lookback
 */
#[Group('api-client')]
#[Group('lookback')]
final class ApiClientLookbackTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        if (!class_exists('Cashback_API_Client')) {
            require_once $plugin_root . '/includes/class-cashback-api-client.php';
        }
    }

    public function test_default_lookback_date_dmy_is_180_days_ago_in_dmy_format(): void
    {
        $this->assertTrue(
            method_exists('Cashback_API_Client', 'default_lookback_date_dmy'),
            'Cashback_API_Client должен предоставлять приватный helper default_lookback_date_dmy()'
        );

        // newInstanceWithoutConstructor — конструктор Cashback_API_Client регистрирует
        // адаптеры и обращается к глобальному $wpdb, что для unit-теста чистого
        // date-helper'а избыточно и хрупко.
        $class  = new ReflectionClass('Cashback_API_Client');
        $client = $class->newInstanceWithoutConstructor();

        $method = new ReflectionMethod('Cashback_API_Client', 'default_lookback_date_dmy');
        $method->setAccessible(true);

        $result = (string) $method->invoke($client);

        // Формат d.m.Y — то, что Admitad ожидает в `date_start` параметре.
        $this->assertMatchesRegularExpression(
            '/^\d{2}\.\d{2}\.\d{4}$/',
            $result,
            "Дата должна быть в формате d.m.Y, получено: {$result}"
        );

        // Дата ровно 180 дней назад (UTC, как и весь bootstrap тестов).
        $expected = ( new DateTimeImmutable('now', new DateTimeZone('UTC')) )
            ->modify('-180 days')
            ->format('d.m.Y');

        $this->assertSame(
            $expected,
            $result,
            'default_lookback_date_dmy() должен возвращать today - 180 days в d.m.Y'
        );
    }

    /**
     * Структурный регресс-тест: в коде api-client не должно остаться
     * хардкода `'01.01.2020'`. Это окно превращало sync в 6-летнюю выборку,
     * которую `/statistics/actions/` стабильно не вытягивает.
     */
    public function test_no_hardcoded_2020_date_in_api_client_source(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $source      = (string) file_get_contents($plugin_root . '/includes/class-cashback-api-client.php');

        $this->assertNotEmpty($source, 'class-cashback-api-client.php должен читаться');

        $this->assertStringNotContainsString(
            "'01.01.2020'",
            $source,
            'Хардкод date_start = 01.01.2020 должен быть полностью заменён на default_lookback_date_dmy() — '
            . 'иначе cron sync снова уйдёт в 6-летнее окно и упрётся в HTTP 500/timeout от Admitad.'
        );
    }
}
