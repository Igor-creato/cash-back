<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Cashback_Advcake_Adapter::fetch_campaigns_detailed
 * (auto-import магазинов Advcake через Cashback_Shop_Importer v12).
 *
 * Контракт симметричен Admitad/EPN: один HTTP-вызов на страницу offset/limit,
 * нормализация offer-объекта Advcake в DTO-array shape (поля: id, name,
 * site_url, image_url, description, status_raw, is_active, connection_status,
 * regions, categories, currency, goto_link, payment_time_days, raw,
 * inline_tariffs), отдача has_next/next_offset для дальнейшей пагинации
 * через Action Scheduler.
 *
 * @group adapters
 * @group advcake
 * @group shop-import
 */
#[Group('adapters')]
#[Group('advcake')]
#[Group('shop-import')]
final class AdvcakeShopsDetailedTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        self::require_if_missing('/includes/class-cashback-outbound-http-guard.php', 'Cashback_Outbound_HTTP_Guard');
        self::require_if_missing('/includes/adapters/interface-cashback-network-adapter.php', null);
        self::require_if_missing('/includes/adapters/abstract-cashback-network-adapter.php', 'Cashback_Network_Adapter_Base');
        self::require_if_missing('/includes/adapters/class-cashback-advcake-adapter.php', 'Cashback_Advcake_Adapter');
    }

    private static function require_if_missing(string $relative, ?string $class): void
    {
        if ($class !== null && ( class_exists($class) || interface_exists($class) )) {
            return;
        }
        $path = self::$plugin_root . $relative;
        if (!file_exists($path)) {
            self::markTestSkipped("File missing: {$relative}");
            return;
        }
        require_once $path;
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options']                = array();
        $GLOBALS['_cb_test_filters']                = array();
        $GLOBALS['_cb_test_transients']             = array();
        $GLOBALS['_cb_test_cache']                  = array();
        $GLOBALS['_cb_test_http_calls']             = array();
        $GLOBALS['_cb_test_http_response']          = array(
            'body'     => '{"success":true,"data":[]}',
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'headers'  => array(),
        );
        $GLOBALS['_cb_test_http_response_callback'] = null;

        // Обнуляем 5xx backoff — тесты не должны спать.
        add_filter(
            'cashback_advcake_5xx_retry_delay_seconds',
            static fn(): int => 0,
            10,
            3
        );

        if (class_exists('Cashback_Outbound_HTTP_Guard')) {
            Cashback_Outbound_HTTP_Guard::invalidate_cache();
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['_cb_test_http_response_callback'] = null;
        $GLOBALS['_cb_test_http_calls']             = array();
        $GLOBALS['_cb_test_filters']                = array();
    }

    private function http_response(int $code, string $body = ''): array
    {
        return array(
            'body'     => $body,
            'response' => array( 'code' => $code, 'message' => 'HTTP ' . $code ),
            'headers'  => array(),
        );
    }

    private function queue_responses(array $responses): void
    {
        $queue = $responses;
        $GLOBALS['_cb_test_http_response_callback'] = static function (string $url, array $args) use (&$queue) {
            if (count($queue) > 1) {
                return array_shift($queue);
            }
            return $queue[0] ?? array(
                'body'     => '',
                'response' => array( 'code' => 500, 'message' => 'Internal' ),
                'headers'  => array(),
            );
        };
    }

    private function default_credentials(): array
    {
        return array( 'api_key' => $this->synthetic_api_key() );
    }

    private function synthetic_api_key(): string
    {
        return 'unit_test_' . substr(hash('sha256', self::class), 0, 24);
    }

    private function default_network_config(): array
    {
        return array(
            'api_base_url'         => 'https://api.advcake.ru',
            'api_actions_endpoint' => '/export/webmaster/{token}',
        );
    }

    /** @return array<string, mixed> */
    private function sample_offer(array $overrides = array()): array
    {
        $defaults = array(
            'id'          => 403,
            'alias'       => 'geekbrainsru',
            'name'        => 'gb.ru',
            'description' => 'GeekBrains — образовательная экосистема.',
            'country'     => 'RU',
            'currency'    => 'RUB',
            'website_url' => 'https://gb.ru/',
            'thumbnail'   => 'https://static.advcake.ru/upload/offers/logo.png',
            'category'    => array( 'alias' => 'education', 'name' => 'Образование' ),
            'type'        => 'CPA',
            'action_type' => 'lead',
            'cookie_lifetime' => 30,
            'hold'        => 30,
            'available'   => true,
            'active'      => true,
            'geos'        => array( array( 'name' => 'RU' ) ),
            'landings'    => array(
                array(
                    'id'     => 'ln35232',
                    'name'   => 'gb.ru',
                    'offer_id' => 403,
                    'type'   => 'main_page',
                    'url'    => 'https://go.avred.online/main-page-link?erid=2VfnxxQa3a9&m=31',
                    'active' => true,
                    'status' => 'active',
                ),
                array(
                    'id'     => 'ln21389',
                    'name'   => 'Онлайн-университет',
                    'offer_id' => 403,
                    'type'   => 'desktop',
                    'url'    => 'https://go.avred.online/desktop-link?erid=2VfnxxEC8bj&m=31',
                    'active' => true,
                    'status' => 'active',
                ),
            ),
            'last_update' => '2026-05-13 06:30:38',
        );
        // array_replace (not recursive): overrides заменяют top-level ключ целиком,
        // включая массивы landings/geos/category — нужно для тестов, явно задающих
        // landings=[] или с другим набором landings.
        return array_replace($defaults, $overrides);
    }

    private function offers_body(array $data, ?int $total = null): string
    {
        return (string) wp_json_encode(array(
            'success' => true,
            'dt'      => '2026-05-14 12:00:00',
            'total'   => $total ?? count($data),
            'data'    => $data,
        ));
    }

    private function url_query(int $call_index = 0): array
    {
        $url = $GLOBALS['_cb_test_http_calls'][ $call_index ]['url'] ?? '';
        $qs  = (string) parse_url($url, PHP_URL_QUERY);
        $out = array();
        parse_str($qs, $out);
        return $out;
    }

    // ----------------------------------------------------------------------
    // URL composition
    // ----------------------------------------------------------------------

    public function test_url_uses_offers_endpoint_with_pass_type_limit_offset(): void
    {
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array())) ));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 250, 50);

        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringStartsWith('https://api.advcake.ru/offers?', $url);
        $this->assertStringNotContainsString('/export/webmaster/', $url);
        $this->assertStringNotContainsString('{token}', $url);

        $q = $this->url_query();
        $this->assertSame($this->synthetic_api_key(), $q['pass']);
        $this->assertSame('json', $q['type']);
        $this->assertSame('50', $q['limit']);
        $this->assertSame('250', $q['offset']);
    }

    // ----------------------------------------------------------------------
    // Поля DTO
    // ----------------------------------------------------------------------

    public function test_maps_all_dto_fields_from_offer(): void
    {
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array(
            $this->sample_offer(),
        ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertCount(1, $result['campaigns']);

        $c = $result['campaigns'][0];
        $this->assertSame('403', $c['id']);
        $this->assertSame('gb.ru', $c['name']);
        $this->assertSame('https://gb.ru/', $c['site_url']);
        $this->assertSame('https://static.advcake.ru/upload/offers/logo.png', $c['image_url']);
        $this->assertSame('GeekBrains — образовательная экосистема.', $c['description']);
        $this->assertSame('active', $c['status_raw']);
        $this->assertTrue($c['is_active']);
        $this->assertSame('available', $c['connection_status']);
        $this->assertSame(array( 'RU' ), $c['regions']);
        $this->assertSame(array( 'Образование' ), $c['categories']);
        $this->assertSame('RUB', $c['currency']);
        $this->assertSame('https://go.avred.online/main-page-link?erid=2VfnxxQa3a9&m=31', $c['goto_link']);
        $this->assertSame(30, $c['payment_time_days']);
        $this->assertSame(array(), $c['inline_tariffs']);
        $this->assertIsArray($c['raw']);
        $this->assertSame(403, $c['raw']['id']);
    }

    // ----------------------------------------------------------------------
    // goto_link
    // ----------------------------------------------------------------------

    public function test_goto_link_picks_main_page_landing_when_available(): void
    {
        $offer = $this->sample_offer(array(
            'landings' => array(
                array( 'id' => 'a', 'type' => 'desktop',   'url' => 'https://desktop/', 'active' => true, 'status' => 'active' ),
                array( 'id' => 'b', 'type' => 'main_page', 'url' => 'https://main/',    'active' => true, 'status' => 'active' ),
                array( 'id' => 'c', 'type' => 'campaign',  'url' => 'https://camp/',    'active' => true, 'status' => 'active' ),
            ),
        ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame('https://main/', $result['campaigns'][0]['goto_link']);
    }

    public function test_goto_link_falls_back_to_first_active_landing_when_no_main_page(): void
    {
        $offer = $this->sample_offer(array(
            'landings' => array(
                array( 'id' => 'a', 'type' => 'desktop',  'url' => 'https://first/',   'active' => true, 'status' => 'active' ),
                array( 'id' => 'b', 'type' => 'campaign', 'url' => 'https://second/',  'active' => true, 'status' => 'active' ),
            ),
        ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame('https://first/', $result['campaigns'][0]['goto_link']);
    }

    public function test_goto_link_skips_inactive_landings(): void
    {
        $offer = $this->sample_offer(array(
            'landings' => array(
                array( 'id' => 'a', 'type' => 'desktop', 'url' => 'https://inactive/', 'active' => false, 'status' => 'paused' ),
                array( 'id' => 'b', 'type' => 'desktop', 'url' => 'https://active/',   'active' => true,  'status' => 'active' ),
            ),
        ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame('https://active/', $result['campaigns'][0]['goto_link']);
    }

    public function test_goto_link_works_with_batch_offers_landings_format(): void
    {
        // В batch /offers Advcake landings приходят в коротком формате:
        // `id, name, promotional, start_date, available_deep_link, link` —
        // без `url`, `active`, `status`, `type`. Адаптер должен взять `link`.
        $offer = $this->sample_offer(array(
            'landings' => array(
                array(
                    'id' => 'ln35232',
                    'name' => 'gb.ru',
                    'promotional' => false,
                    'start_date' => '2025-04-14',
                    'available_deep_link' => true,
                    'link' => 'https://go.avred.online/short-link?erid=2VfnxxQa3a9&m=31',
                ),
            ),
        ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame(
            'https://go.avred.online/short-link?erid=2VfnxxQa3a9&m=31',
            $result['campaigns'][0]['goto_link']
        );
    }

    public function test_goto_link_empty_string_when_no_landings(): void
    {
        $offer = $this->sample_offer(array( 'landings' => array() ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame('', $result['campaigns'][0]['goto_link']);
    }

    // ----------------------------------------------------------------------
    // regions / categories / payment_time_days / status mapping
    // ----------------------------------------------------------------------

    public function test_regions_from_geos_uppercased_and_listed(): void
    {
        $offer = $this->sample_offer(array(
            'geos' => array( array( 'name' => 'ru' ), array( 'name' => 'by' ), array( 'name' => 'KZ' ) ),
        ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame(array( 'RU', 'BY', 'KZ' ), $result['campaigns'][0]['regions']);
    }

    public function test_categories_from_category_object(): void
    {
        $offer = $this->sample_offer(array(
            'category' => array( 'alias' => 'fashion', 'name' => 'Одежда и обувь' ),
        ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame(array( 'Одежда и обувь' ), $result['campaigns'][0]['categories']);
    }

    public function test_payment_time_days_from_hold(): void
    {
        $offer = $this->sample_offer(array( 'hold' => 45 ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame(45, $result['campaigns'][0]['payment_time_days']);
    }

    /**
     * @dataProvider provide_status_raw
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provide_status_raw')]
    public function test_status_raw_active_and_stopped_mapping(bool $active, string $expected_status_raw): void
    {
        $offer = $this->sample_offer(array( 'active' => $active, 'available' => true ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame($expected_status_raw, $result['campaigns'][0]['status_raw']);
    }

    public static function provide_status_raw(): array
    {
        return array(
            'active=true → status_raw=active'   => array( true,  'active' ),
            'active=false → status_raw=stopped' => array( false, 'stopped' ),
        );
    }

    /**
     * @dataProvider provide_active_available_combinations
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provide_active_available_combinations')]
    public function test_is_active_requires_both_active_and_available(
        bool $active,
        bool $available,
        bool $expected_is_active,
        string $expected_connection_status
    ): void {
        $offer = $this->sample_offer(array( 'active' => $active, 'available' => $available ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame($expected_is_active, $result['campaigns'][0]['is_active']);
        $this->assertSame($expected_connection_status, $result['campaigns'][0]['connection_status']);
    }

    public static function provide_active_available_combinations(): array
    {
        return array(
            'active+available → true+available'        => array( true,  true,  true,  'available' ),
            'active+!available → false+unavailable'    => array( true,  false, false, 'unavailable' ),
            '!active+available → false+available'      => array( false, true,  false, 'available' ),
            '!active+!available → false+unavailable'   => array( false, false, false, 'unavailable' ),
        );
    }

    public function test_detailed_status_stopped_overrides_active_flag(): void
    {
        $offer = $this->sample_offer(array(
            'active'    => true,
            'available' => true,
            'status'    => 'stopped',
        ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame('stopped', $result['campaigns'][0]['status_raw']);
        $this->assertFalse($result['campaigns'][0]['is_active']);
        $this->assertSame('available', $result['campaigns'][0]['connection_status']);
    }

    public function test_detailed_available_false_overrides_status_active(): void
    {
        $offer = $this->sample_offer(array(
            'active'    => true,
            'available' => false,
            'status'    => 'active',
        ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame('active', $result['campaigns'][0]['status_raw']);
        $this->assertFalse($result['campaigns'][0]['is_active']);
        $this->assertSame('unavailable', $result['campaigns'][0]['connection_status']);
    }

    // ----------------------------------------------------------------------
    // Пагинация и лимиты
    // ----------------------------------------------------------------------

    public function test_pagination_has_next_true_when_page_full(): void
    {
        $offers = array(
            $this->sample_offer(array( 'id' => 1, 'name' => 'a' )),
            $this->sample_offer(array( 'id' => 2, 'name' => 'b' )),
        );
        $this->queue_responses(array( $this->http_response(200, $this->offers_body($offers, 999)) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 2);

        $this->assertTrue($result['has_next']);
        $this->assertSame(2, $result['next_offset']);
    }

    public function test_pagination_has_next_false_when_page_short(): void
    {
        $offers = array(
            $this->sample_offer(array( 'id' => 1 )),
            $this->sample_offer(array( 'id' => 2 )),
            $this->sample_offer(array( 'id' => 3 )),
            $this->sample_offer(array( 'id' => 4 )),
        );
        $this->queue_responses(array( $this->http_response(200, $this->offers_body($offers, 4)) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 500);

        $this->assertFalse($result['has_next']);
        $this->assertSame(500, $result['next_offset']);
    }

    public function test_limit_capped_to_500_max(): void
    {
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array())) ));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 10000);

        $this->assertSame('500', $this->url_query()['limit']);
    }

    public function test_limit_floored_to_1_min(): void
    {
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array())) ));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config(), 0, 0);

        $this->assertSame('1', $this->url_query()['limit']);
    }

    // ----------------------------------------------------------------------
    // Ошибки
    // ----------------------------------------------------------------------

    public function test_401_returns_detailed_error_no_retry(): void
    {
        $this->queue_responses(array( $this->http_response(401, '{"error":"Unauthorized"}') ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertFalse($result['success']);
        $this->assertFalse($result['has_next']);
        $this->assertSame(0, $result['next_offset']);
        $this->assertSame(array(), $result['campaigns']);
        $this->assertStringContainsString('401', $result['error']);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_5xx_retries_then_succeeds(): void
    {
        $this->queue_responses(array(
            $this->http_response(500, 'gateway timeout'),
            $this->http_response(200, $this->offers_body(array(
                $this->sample_offer(array( 'id' => 7 )),
            ))),
        ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['campaigns']);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_empty_token_returns_error_no_http_call(): void
    {
        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed(array( 'api_key' => '' ), $this->default_network_config());

        $this->assertFalse($result['success']);
        $this->assertFalse($result['has_next']);
        $this->assertSame(0, $result['next_offset']);
        $this->assertSame(array(), $result['campaigns']);
        $this->assertCount(0, $GLOBALS['_cb_test_http_calls']);
    }

    public function test_skips_offer_without_id(): void
    {
        $offers = array(
            $this->sample_offer(array( 'id' => 1, 'name' => 'ok' )),
            array( 'name' => 'broken — нет id', 'active' => true, 'available' => true ),
            $this->sample_offer(array( 'id' => 0, 'name' => 'zero-id-skip' )),
            $this->sample_offer(array( 'id' => 2, 'name' => 'ok2' )),
        );
        $this->queue_responses(array( $this->http_response(200, $this->offers_body($offers)) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertTrue($result['success']);
        $ids = array_map(static fn(array $c): string => $c['id'], $result['campaigns']);
        $this->assertSame(array( '1', '2' ), $ids);
    }

    // ----------------------------------------------------------------------
    // inline_tariffs из bids[] (v4.3.3)
    // ----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function sample_bid(array $overrides = array()): array
    {
        $defaults = array(
            'id'         => 25531,
            'value'      => 12,
            'type'       => 'percent',
            'final'      => false,
            'text'       => 'Ставка 12% на cashback',
            'created_at' => '2026-04-01 17:40:49',
            'condition'  => array(
                'traffic_type' => array( '17', '26', '16' ),
                'start_date'   => '2026-04-10',
            ),
        );
        return array_replace($defaults, $overrides);
    }

    public function test_url_includes_with_bids_in_detailed_fetch(): void
    {
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array())) ));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $q = $this->url_query();
        $this->assertSame('1', $q['with_bids']);
    }

    public function test_url_omits_with_bids_in_campaigns_fetch(): void
    {
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array())) ));

        $adapter = new Cashback_Advcake_Adapter();
        $adapter->fetch_campaigns($this->default_credentials(), $this->default_network_config());

        $q = $this->url_query();
        $this->assertArrayNotHasKey('with_bids', $q, '/offers для check_campaign_statuses() bids не нужны');
    }

    public function test_inline_tariffs_maps_percent_bid_from_advcake_real_shape(): void
    {
        $offer = $this->sample_offer(array(
            'bids' => array( $this->sample_bid() ),
        ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $tariffs = $result['campaigns'][0]['inline_tariffs'];
        $this->assertCount(1, $tariffs);
        $this->assertSame('25531', $tariffs[0]['tariff_id']);
        $this->assertSame('percent', $tariffs[0]['tariff_type']);
        $this->assertEqualsWithDelta(12.0, (float) $tariffs[0]['payment_size'], 0.0001);
        $this->assertTrue($tariffs[0]['is_default'], 'один прошедший фильтр bid → is_default=true');
        $this->assertSame('Ставка 12% на cashback', $tariffs[0]['name']);
        $this->assertSame('RUB', $tariffs[0]['currency']);
        $this->assertNull($tariffs[0]['payment_max']);
    }

    public function test_inline_tariffs_maps_fix_bid_with_currency_and_max_commission(): void
    {
        $offer = $this->sample_offer(array(
            'bids' => array( $this->sample_bid(array(
                'id'             => 99001,
                'type'           => 'fix',
                'value'          => 1000.45,
                'text'           => 'Фикс за регистрацию юр.лица',
                'currency'       => 'RUB',
                'max_commission' => 5000.0,
            )) ),
        ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $tariffs = $result['campaigns'][0]['inline_tariffs'];
        $this->assertCount(1, $tariffs);
        $this->assertSame('fix', $tariffs[0]['tariff_type']);
        $this->assertEqualsWithDelta(1000.45, (float) $tariffs[0]['payment_size'], 0.0001);
        $this->assertEqualsWithDelta(5000.0, (float) $tariffs[0]['payment_max'], 0.0001);
        $this->assertSame('RUB', $tariffs[0]['currency']);
    }

    public function test_inline_tariffs_filters_out_bids_without_cashback_traffic_type(): void
    {
        $bid_context  = $this->sample_bid(array(
            'id' => 11,
            'condition' => array( 'traffic_type' => array( '9' ) ), // 9 = context, не cashback
        ));
        $bid_cashback = $this->sample_bid(array(
            'id' => 12,
            'condition' => array( 'traffic_type' => array( '17' ) ),
        ));
        $offer = $this->sample_offer(array( 'bids' => array( $bid_context, $bid_cashback ) ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $tariffs = $result['campaigns'][0]['inline_tariffs'];
        $this->assertCount(1, $tariffs);
        $this->assertSame('12', $tariffs[0]['tariff_id']);
    }

    public function test_inline_tariffs_accepts_bid_without_condition_as_applies_all(): void
    {
        $bid = $this->sample_bid();
        unset($bid['condition']);
        $offer = $this->sample_offer(array( 'bids' => array( $bid ) ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $tariffs = $result['campaigns'][0]['inline_tariffs'];
        $this->assertCount(1, $tariffs, 'bid без condition.traffic_type принимается как default applies-all');
    }

    public function test_inline_tariffs_accepts_bid_with_empty_traffic_type_array(): void
    {
        $bid = $this->sample_bid(array( 'condition' => array( 'traffic_type' => array() ) ));
        $offer = $this->sample_offer(array( 'bids' => array( $bid ) ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertCount(1, $result['campaigns'][0]['inline_tariffs']);
    }

    public function test_inline_tariffs_marks_is_default_false_when_multiple_bids(): void
    {
        $offer = $this->sample_offer(array(
            'bids' => array(
                $this->sample_bid(array( 'id' => 1, 'value' => 12 )),
                $this->sample_bid(array( 'id' => 2, 'value' => 8 )),
            ),
        ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $tariffs = $result['campaigns'][0]['inline_tariffs'];
        $this->assertCount(2, $tariffs);
        $this->assertFalse($tariffs[0]['is_default']);
        $this->assertFalse($tariffs[1]['is_default']);
    }

    public function test_inline_tariffs_empty_when_offer_has_no_bids_key(): void
    {
        $offer = $this->sample_offer();
        $this->assertArrayNotHasKey('bids', $offer, 'sample_offer defaults без bids — sanity');
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame(array(), $result['campaigns'][0]['inline_tariffs']);
    }

    public function test_inline_tariffs_empty_when_all_bids_filtered(): void
    {
        $offer = $this->sample_offer(array(
            'bids' => array(
                $this->sample_bid(array( 'condition' => array( 'traffic_type' => array( '9' ) ) )),
                $this->sample_bid(array( 'condition' => array( 'traffic_type' => array( '8', '11' ) ) )),
            ),
        ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame(array(), $result['campaigns'][0]['inline_tariffs']);
    }

    public function test_inline_tariffs_skips_bid_without_id(): void
    {
        $bid = $this->sample_bid();
        unset($bid['id']);
        $offer = $this->sample_offer(array( 'bids' => array( $bid ) ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame(array(), $result['campaigns'][0]['inline_tariffs']);
    }

    public function test_inline_tariffs_skips_bid_with_unknown_type(): void
    {
        $bid = $this->sample_bid(array( 'type' => 'mixed' ));
        $offer = $this->sample_offer(array( 'bids' => array( $bid ) ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertSame(array(), $result['campaigns'][0]['inline_tariffs']);
    }

    public function test_inline_tariffs_percent_bid_does_not_populate_payment_max(): void
    {
        // percent-bid не должен подхватывать max_commission в payment_max —
        // это поле семантично только для fix-bid'ов (как cap на абсолютную сумму).
        $bid = $this->sample_bid(array( 'max_commission' => 999.0 ));
        $offer = $this->sample_offer(array( 'bids' => array( $bid ) ));
        $this->queue_responses(array( $this->http_response(200, $this->offers_body(array( $offer ))) ));

        $adapter = new Cashback_Advcake_Adapter();
        $result  = $adapter->fetch_campaigns_detailed($this->default_credentials(), $this->default_network_config());

        $this->assertNull($result['campaigns'][0]['inline_tariffs'][0]['payment_max']);
    }
}
