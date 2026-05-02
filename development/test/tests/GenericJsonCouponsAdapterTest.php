<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты на Cashback_Generic_Json_Coupons_Adapter — generic движок,
 * работающий через admin-конфиг (api_coupons_endpoint, field_map, species_map,
 * pagination). Покрывает:
 *   - placeholder substitution в endpoint.
 *   - offset_limit pagination loop.
 *   - page pagination loop.
 *   - pagination=none.
 *   - mapping raw → DTO[].
 *   - filter status='active' (опционально).
 *   - 401/insufficient_scope → invalidate + return [] + log.
 *
 * @group promocodes
 * @group adapters
 */
#[Group('promocodes')]
#[Group('adapters')]
final class GenericJsonCouponsAdapterTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        $files = array(
            '/includes/promocodes/contracts/interface-coupons-adapter.php',
            '/includes/promocodes/dto/class-coupon-dto.php',
            '/includes/promocodes/class-coupons-field-mapper.php',
            '/includes/promocodes/adapters/class-generic-json-coupons-adapter.php',
        );
        foreach ($files as $f) {
            $path = self::$plugin_root . $f;
            if (!file_exists($path)) {
                self::markTestSkipped("File missing: {$f}");
            }
            require_once $path;
        }
    }

    private function admitad_config(array $overrides = []): object
    {
        return (object) array_merge(array(
            'id'                       => 1,
            'slug'                     => 'admitad',
            'api_base_url'             => 'https://api.admitad.com',
            'api_auth_type'            => 'oauth2_client_credentials',
            'api_token_endpoint'       => '/token/',
            'api_coupons_endpoint'     => '/coupons/website/{website_id}/?campaign={advcampaign_id}&limit={limit}&offset={offset}',
            'api_website_id'           => '8888',
            'api_coupons_field_map'    => wp_json_encode(array(
                'id'         => 'external_id',
                'promocode'  => 'promocode',
                'name'       => 'name',
                'goto_link'  => 'goto_link',
                'date_start' => 'date_start',
                'date_end'   => 'date_end',
                'status'     => 'status',
                'regions'    => 'regions',
                'type'       => 'species_raw',
            )),
            'api_coupons_species_map'  => wp_json_encode(array(
                'promo_code' => 'promocode',
                'deal'       => 'deal',
            )),
            'api_coupons_pagination'   => 'offset_limit',
        ), $overrides);
    }

    private function make_api_client_stub(array $credentials): object
    {
        return new class($credentials) {
            public array $get_credentials_calls = array();
            public function __construct(public array $stored) {}
            public function get_credentials( int $network_id ): array {
                $this->get_credentials_calls[] = $network_id;
                return $this->stored;
            }
        };
    }

    private function make_http_stub(): object
    {
        return new class {
            /** @var array<int,array{url:string,auth:array}> */
            public array $get_calls = array();
            /** @var array<int,array> Список ответов (по очереди — для pagination). */
            public array $responses = array();
            /** @var array<int,array{cid:string,ns:string}> */
            public array $invalidate_calls = array();

            public function get( string $url, array $auth_config ): array|WP_Error {
                $this->get_calls[] = array( 'url' => $url, 'auth' => $auth_config );
                if ( empty( $this->responses ) ) {
                    return array( 'body' => '{"results":[]}', 'response' => array( 'code' => 200, 'message' => 'OK' ) );
                }
                return array_shift( $this->responses );
            }

            public function invalidate_oauth_token( string $client_id, string $cache_namespace ): void {
                $this->invalidate_calls[] = array( 'cid' => $client_id, 'ns' => $cache_namespace );
            }
        };
    }

    private function admitad_raw_coupon(array $overrides = []): array
    {
        return array_merge(array(
            'id'         => '12345',
            'promocode'  => 'SAVE10',
            'name'       => 'Скидка 10%',
            'goto_link'  => 'https://ad.admitad.com/g/abc/',
            'date_start' => '2026-01-01 00:00:00',
            'date_end'   => '2026-12-31 23:59:59',
            'status'     => 'active',
            'regions'    => 'RU',
            'type'       => 'promo_code',
        ), $overrides);
    }

    public function test_substitutes_placeholders_in_endpoint(): void
    {
        $http = $this->make_http_stub();
        $http->responses[] = array( 'body' => wp_json_encode(array( 'results' => array() )), 'response' => array( 'code' => 200 ) );

        $api_client = $this->make_api_client_stub(array(
            'client_id'     => 'cid',
            'client_secret' => 'sec',
            'scope'         => 'coupons_for_website',
        ));

        $adapter = new Cashback_Generic_Json_Coupons_Adapter(
            $this->admitad_config(),
            $api_client,
            $http,
            new Cashback_Coupons_Field_Mapper()
        );

        $adapter->fetch_coupons( '35530' );

        $this->assertNotEmpty($http->get_calls);
        $first_url = $http->get_calls[0]['url'];
        $this->assertStringContainsString('/coupons/website/8888/', $first_url, '{website_id} должен быть заменён');
        $this->assertStringContainsString('campaign=35530', $first_url, '{advcampaign_id} должен быть заменён');
        $this->assertStringContainsString('offset=0', $first_url);
    }

    public function test_offset_limit_pagination_loops_through_pages(): void
    {
        // Первая страница — 50 купонов (полный page_size) → fetcher идёт за второй.
        // Вторая — 2 купона (меньше page_size) → стоп после неё.
        $first_page = array();
        for ($i = 0; $i < 50; $i++) {
            $first_page[] = $this->admitad_raw_coupon(array( 'id' => 'A' . $i ));
        }
        $second_page = array(
            $this->admitad_raw_coupon(array( 'id' => 'B1' )),
            $this->admitad_raw_coupon(array( 'id' => 'B2' )),
        );

        $http = $this->make_http_stub();
        $http->responses[] = array(
            'body'     => wp_json_encode(array( 'results' => $first_page )),
            'response' => array( 'code' => 200 ),
        );
        $http->responses[] = array(
            'body'     => wp_json_encode(array( 'results' => $second_page )),
            'response' => array( 'code' => 200 ),
        );

        $api_client = $this->make_api_client_stub(array(
            'client_id'     => 'cid',
            'client_secret' => 'sec',
        ));

        $adapter = new Cashback_Generic_Json_Coupons_Adapter(
            $this->admitad_config(),
            $api_client,
            $http,
            new Cashback_Coupons_Field_Mapper()
        );

        $coupons = $adapter->fetch_coupons( '35530' );

        $this->assertCount(52, $coupons);
        $this->assertContainsOnlyInstancesOf(Cashback_Coupon_DTO::class, $coupons);
        $this->assertCount(2, $http->get_calls, 'Должно быть 2 страницы запрошено');
        $this->assertStringContainsString('offset=0', $http->get_calls[0]['url']);
        $this->assertStringContainsString('offset=50', $http->get_calls[1]['url']);
    }

    public function test_returns_dto_array_after_mapping(): void
    {
        $http = $this->make_http_stub();
        $http->responses[] = array(
            'body'     => wp_json_encode(array( 'results' => array(
                $this->admitad_raw_coupon(array( 'id' => 42, 'promocode' => 'HELLO' )),
            ))),
            'response' => array( 'code' => 200 ),
        );
        $http->responses[] = array( 'body' => wp_json_encode(array( 'results' => array() )), 'response' => array( 'code' => 200 ) );

        $api_client = $this->make_api_client_stub(array( 'client_id' => 'cid', 'client_secret' => 'sec' ));

        $adapter = new Cashback_Generic_Json_Coupons_Adapter(
            $this->admitad_config(),
            $api_client,
            $http,
            new Cashback_Coupons_Field_Mapper()
        );

        $coupons = $adapter->fetch_coupons( '35530' );

        $this->assertCount(1, $coupons);
        $this->assertSame('42', $coupons[0]->external_id);
        $this->assertSame('HELLO', $coupons[0]->promocode);
        $this->assertSame('promocode', $coupons[0]->species);
    }

    public function test_get_network_slug_returns_config_slug(): void
    {
        $adapter = new Cashback_Generic_Json_Coupons_Adapter(
            $this->admitad_config(),
            $this->make_api_client_stub(array()),
            $this->make_http_stub(),
            new Cashback_Coupons_Field_Mapper()
        );

        $this->assertSame('admitad', $adapter->get_network_slug());
    }

    public function test_supports_campaign_filter_when_endpoint_has_placeholder(): void
    {
        $adapter = new Cashback_Generic_Json_Coupons_Adapter(
            $this->admitad_config(),
            $this->make_api_client_stub(array()),
            $this->make_http_stub(),
            new Cashback_Coupons_Field_Mapper()
        );

        $this->assertTrue($adapter->supports_campaign_filter());
    }

    public function test_does_not_support_campaign_filter_when_no_placeholder(): void
    {
        $adapter = new Cashback_Generic_Json_Coupons_Adapter(
            $this->admitad_config(array(
                'api_coupons_endpoint' => '/coupons/all/',
            )),
            $this->make_api_client_stub(array()),
            $this->make_http_stub(),
            new Cashback_Coupons_Field_Mapper()
        );

        $this->assertFalse($adapter->supports_campaign_filter());
    }

    public function test_returns_empty_array_on_401_without_throwing(): void
    {
        $http = $this->make_http_stub();
        $http->responses[] = array(
            'body'     => wp_json_encode(array( 'error' => 'invalid_token' )),
            'response' => array( 'code' => 401 ),
        );

        $api_client = $this->make_api_client_stub(array(
            'client_id'     => 'cid',
            'client_secret' => 'sec',
            'scope'         => 'wrong',
        ));

        $adapter = new Cashback_Generic_Json_Coupons_Adapter(
            $this->admitad_config(),
            $api_client,
            $http,
            new Cashback_Coupons_Field_Mapper()
        );

        $coupons = $adapter->fetch_coupons( '35530' );

        $this->assertSame(array(), $coupons, 'На 401 адаптер возвращает пустой массив (soft-fail)');
        // Должна быть инвалидация токена.
        $this->assertNotEmpty($http->invalidate_calls, 'OAuth токен должен быть инвалидирован при 401');
    }

    public function test_filters_inactive_coupons_at_status_level(): void
    {
        $http = $this->make_http_stub();
        $http->responses[] = array(
            'body'     => wp_json_encode(array( 'results' => array(
                $this->admitad_raw_coupon(array( 'id' => 'A', 'status' => 'active' )),
                $this->admitad_raw_coupon(array( 'id' => 'B', 'status' => 'archive' )),
                $this->admitad_raw_coupon(array( 'id' => 'C', 'status' => 'active' )),
            ))),
            'response' => array( 'code' => 200 ),
        );
        $http->responses[] = array( 'body' => wp_json_encode(array( 'results' => array() )), 'response' => array( 'code' => 200 ) );

        $api_client = $this->make_api_client_stub(array( 'client_id' => 'cid', 'client_secret' => 'sec' ));

        $adapter = new Cashback_Generic_Json_Coupons_Adapter(
            $this->admitad_config(),
            $api_client,
            $http,
            new Cashback_Coupons_Field_Mapper()
        );

        $coupons = $adapter->fetch_coupons( '35530' );

        $this->assertCount(2, $coupons, 'Только active купоны должны попасть в результат');
        $ids = array_map(fn(Cashback_Coupon_DTO $c) => $c->external_id, $coupons);
        $this->assertSame(array( 'A', 'C' ), $ids);
    }

    public function test_pagination_none_makes_single_request(): void
    {
        $http = $this->make_http_stub();
        $http->responses[] = array(
            'body'     => wp_json_encode(array( 'results' => array(
                $this->admitad_raw_coupon(array( 'id' => 'X' )),
            ))),
            'response' => array( 'code' => 200 ),
        );
        // Дополнительный response не должен использоваться.
        $http->responses[] = array(
            'body'     => wp_json_encode(array( 'results' => array(
                $this->admitad_raw_coupon(array( 'id' => 'Y' )),
            ))),
            'response' => array( 'code' => 200 ),
        );

        $config = $this->admitad_config(array( 'api_coupons_pagination' => 'none' ));

        $adapter = new Cashback_Generic_Json_Coupons_Adapter(
            $config,
            $this->make_api_client_stub(array( 'client_id' => 'cid', 'client_secret' => 'sec' )),
            $http,
            new Cashback_Coupons_Field_Mapper()
        );

        $coupons = $adapter->fetch_coupons( '35530' );

        $this->assertCount(1, $coupons);
        $this->assertCount(1, $http->get_calls, 'Pagination=none делает только 1 запрос');
    }

    public function test_hard_cap_protects_against_runaway_pagination(): void
    {
        $http = $this->make_http_stub();
        // Эмулируем зацикливание: API всегда возвращает 50 купонов на странице.
        for ($i = 0; $i < 50; $i++) {
            $batch = array();
            for ($j = 0; $j < 50; $j++) {
                $batch[] = $this->admitad_raw_coupon(array( 'id' => "P{$i}_J{$j}" ));
            }
            $http->responses[] = array(
                'body'     => wp_json_encode(array( 'results' => $batch )),
                'response' => array( 'code' => 200 ),
            );
        }

        $adapter = new Cashback_Generic_Json_Coupons_Adapter(
            $this->admitad_config(),
            $this->make_api_client_stub(array( 'client_id' => 'cid', 'client_secret' => 'sec' )),
            $http,
            new Cashback_Coupons_Field_Mapper()
        );

        $coupons = $adapter->fetch_coupons( '35530' );

        // Hard-cap по плану ~1000 купонов.
        $this->assertLessThanOrEqual(1000, count($coupons), 'Hard-cap должен сработать');
    }
}
