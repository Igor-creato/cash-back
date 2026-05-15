<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Привязка API-синхронизации к сохранённому в настройках Website ID площадки.
 *
 * Контекст: `/statistics/actions/` Admitad уже получал параметр
 * `website=<api_website_id>`, НО только под `if (!empty(...))`, и плагин
 * нигде не сверял возвращённые действия с сохранённым id. OAuth-токен
 * аккаунт-уровневый → при пустом поле / игноре параметра чужая площадка
 * могла сматчиться/вставиться. Добавлен defense-in-depth слой.
 *
 * Политика (общая для всех сетей):
 *  - площадка не задана → ограничения нет (тянем все);
 *  - задана + у действия есть website → строгое сравнение;
 *  - задана + у действия нет website → пропускаем (param-фильтр уже применён);
 *  - website_name НЕ участвует в сравнении (это имя, не id).
 *
 * @group api-client
 * @group website-scope
 */
#[Group('api-client')]
#[Group('website-scope')]
final class AdmitadWebsiteScopeTest extends TestCase
{
    private static \ReflectionClass $rc;

    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        if (!class_exists('Cashback_API_Client')) {
            require_once $plugin_root . '/includes/class-cashback-api-client.php';
        }
        self::$rc = new ReflectionClass('Cashback_API_Client');
    }

    private function client(): object
    {
        return self::$rc->newInstanceWithoutConstructor();
    }

    private function call(string $method, array $args)
    {
        $m = new ReflectionMethod('Cashback_API_Client', $method);
        $m->setAccessible(true);
        return $m->invoke($this->client(), ...$args);
    }

    public function test_helpers_exist(): void
    {
        foreach (
            array(
                'configured_website_id',
                'action_website_id',
                'action_in_configured_website',
                'filter_actions_by_website',
            ) as $m
        ) {
            $this->assertTrue(
                method_exists('Cashback_API_Client', $m),
                "Cashback_API_Client должен иметь приватный helper {$m}()"
            );
        }
    }

    public function test_configured_website_id_trims_and_handles_missing(): void
    {
        $this->assertSame('2941485', $this->call('configured_website_id', array(array('api_website_id' => '  2941485 '))));
        $this->assertSame('', $this->call('configured_website_id', array(array())));
        $this->assertSame('', $this->call('configured_website_id', array(array('api_website_id' => ''))));
    }

    public function test_action_website_id_ignores_website_name(): void
    {
        // website_name НЕ должен подхватываться как id.
        $this->assertSame(
            '',
            $this->call('action_website_id', array(array('website_name' => 'Some Site'), array()))
        );
        $this->assertSame(
            '2941485',
            $this->call('action_website_id', array(array('website' => 2941485, 'website_name' => 'Some Site'), array()))
        );
        $this->assertSame(
            '777',
            $this->call('action_website_id', array(array('website_id' => '777'), array()))
        );
    }

    /**
     * @dataProvider scope_cases
     */
    public function test_action_in_configured_website(array $action, array $config, bool $expected, string $msg): void
    {
        $this->assertSame(
            $expected,
            $this->call('action_in_configured_website', array($action, $config)),
            $msg
        );
    }

    public static function scope_cases(): array
    {
        $cfg = array('api_website_id' => '2941485', 'field_map' => array());
        $none = array('api_website_id' => '', 'field_map' => array());

        return array(
            'площадка не задана → всё проходит' => array(
                array('website' => '999'), $none, true,
                'Пустой api_website_id → ограничения нет (тянем все площадки)',
            ),
            'website_id строкой совпадает' => array(
                array('website_id' => '2941485'), $cfg, true,
                'Совпадение по website_id должно проходить',
            ),
            'website int совпадает со строковым cfg' => array(
                array('website' => 2941485), $cfg, true,
                "'2941485' и 2941485 должны считаться равными",
            ),
            'чужая площадка отсекается' => array(
                array('website_id' => '111'), $cfg, false,
                'Действие чужой площадки должно отсекаться',
            ),
            'нет website-поля → проходит (доверяем param-фильтру)' => array(
                array('status' => 'approved'), $cfg, true,
                'Без website-поля не плодим ложные скипы',
            ),
            'website_name не подменяет id (id чужой → false)' => array(
                array('website' => '111', 'website_name' => '2941485'), $cfg, false,
                'Сравнение идёт по id, не по имени площадки',
            ),
        );
    }

    public function test_filter_actions_by_website_passthrough_when_unset(): void
    {
        $actions = array(
            array('website' => '111'),
            array('website' => '222'),
        );
        $res = $this->call('filter_actions_by_website', array($actions, array('api_website_id' => '', 'field_map' => array()), 'unit'));

        $this->assertSame(2, count($res['actions']), 'Без заданного id список не фильтруется');
        $this->assertSame(0, $res['skipped']);
    }

    public function test_filter_actions_by_website_keeps_only_configured(): void
    {
        $cfg = array('api_website_id' => '2941485', 'field_map' => array());
        $actions = array(
            array('uniq_id' => 'a1', 'website' => '2941485'),
            array('uniq_id' => 'a2', 'website' => '111'),
            array('uniq_id' => 'a3'), // нет website → проходит
            array('uniq_id' => 'a4', 'website' => 2941485),
            array('uniq_id' => 'a5', 'website' => '999'),
        );

        $res = $this->call('filter_actions_by_website', array($actions, $cfg, 'unit'));

        $this->assertSame(2, $res['skipped'], 'Должны отсеяться a2 и a5');
        $kept = array_column($res['actions'], 'uniq_id');
        $this->assertSame(array('a1', 'a3', 'a4'), $kept);
    }

    /**
     * Структурный анти-регресс: первый слой (параметр website в запросе)
     * должен оставаться во всех 5 точках, второй слой (локальный фильтр)
     * подключён в 4 контекстах.
     */
    public function test_structural_website_param_and_filter_present(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $src = (string) file_get_contents($plugin_root . '/includes/class-cashback-api-client.php');

        // Слой 1: параметр запроса (как было) — не сломан.
        $this->assertStringContainsString("\$api_params['website'] = \$network['api_website_id'];", $src);
        $this->assertStringContainsString("\$extra_params['website'] = \$network['api_website_id'];", $src);
        $this->assertStringContainsString("\$sync_params['website'] = \$config['api_website_id'];", $src);
        $this->assertStringContainsString("\$api_params['website'] = \$config['api_website_id'];", $src);
        // Обе validate_*-ветки используют $network; decline/sync — $config.
        $this->assertSame(
            2,
            substr_count($src, "\$api_params['website'] = \$network['api_website_id'];"),
            'validate_user и validate_unregistered должны сохранять website-параметр'
        );

        // Слой 2: локальный фильтр в 4 контекстах + предохранитель на вставке.
        foreach (
            array(
                "'validate_user'",
                "'validate_user:transferred'",
                "'validate_unregistered'",
                "'do_background_sync'",
                "'decline_stale'",
                "'insert_missing_transaction'",
            ) as $ctx
        ) {
            $this->assertStringContainsString(
                $ctx,
                $src,
                "Контекст фильтра площадки {$ctx} должен присутствовать в api-client"
            );
        }
    }
}
