<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional тесты на Cashback_Advcake_Coupons_Adapter.
 *
 * Code-adapter для Advcake промокодов: дёргает GET /promocodes?pass={token}&type=json,
 * нормализует ответ в Cashback_Coupon_DTO[] (см. реальный shape в diagnostic v4.3.3).
 *
 * Ключевые отличия от гипотезы брифинга, подтверждённые diagnostic'ом 2026-05-14:
 *   - `name` в payload — это сам **промокод** (например, "geekpromo"), а `short_name` —
 *     отображаемое название → DTO.promocode = raw.name, DTO.name = raw.short_name.
 *   - `goto_link` ← raw.referral_link (не raw.link).
 *   - `offer_id` — int, нужен cast в string для filter.
 *   - Server-side filter `?offer={id}` не работает → supports_campaign_filter()=false,
 *     фильтрация делается клиентом по `offer_id` после fetch'а всех промокодов.
 *   - regions у Advcake промокодов не отдаются → пустой массив.
 *
 * @group promocodes
 * @group adapters
 * @group advcake
 */
#[Group('promocodes')]
#[Group('adapters')]
#[Group('advcake')]
final class AdvcakeCouponsAdapterTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);

        self::require_if_missing('/includes/class-cashback-outbound-http-guard.php', 'Cashback_Outbound_HTTP_Guard');
        self::require_if_missing('/includes/adapters/interface-cashback-network-adapter.php', null);
        self::require_if_missing('/includes/adapters/abstract-cashback-network-adapter.php', 'Cashback_Network_Adapter_Base');
        self::require_if_missing('/includes/adapters/class-cashback-advcake-adapter.php', 'Cashback_Advcake_Adapter');
        self::require_if_missing('/includes/promocodes/contracts/interface-coupons-adapter.php', 'Cashback_Coupons_Adapter_Interface');
        self::require_if_missing('/includes/promocodes/dto/class-coupon-dto.php', 'Cashback_Coupon_DTO');
        self::require_if_missing('/includes/promocodes/adapters/class-cashback-advcake-coupons-adapter.php', 'Cashback_Advcake_Coupons_Adapter');
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

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------

    private function http_response(int $code, string $body = ''): array
    {
        return array(
            'body'     => $body,
            'response' => array( 'code' => $code, 'message' => 'HTTP ' . $code ),
            'headers'  => array(),
        );
    }

    /** @param array<int,array<string,mixed>> $responses */
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

    private function url_query(int $call_index = 0): array
    {
        $url = $GLOBALS['_cb_test_http_calls'][ $call_index ]['url'] ?? '';
        $qs  = (string) parse_url($url, PHP_URL_QUERY);
        $out = array();
        parse_str($qs, $out);
        return $out;
    }

    private function promocodes_body(array $data, ?int $total = null): string
    {
        return (string) wp_json_encode(array(
            'success' => true,
            'date'    => '2026-05-14 22:34:49',
            'total'   => $total ?? count($data),
            'data'    => $data,
        ));
    }

    /**
     * Реальный staging shape /promocodes (diagnostic v4.3.3): pc108357 / gb.ru / promo "geekpromo".
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function sample_promocode(array $overrides = array()): array
    {
        $defaults = array(
            'id'                 => 'pc108357',
            'name'               => 'geekpromo',
            'short_name'         => 'Скидка 7% на заказ',
            'offer_id'           => 403,
            'offer_name'         => 'gb.ru',
            'offer_url'          => 'https://gb.ru/',
            'description'        => 'Дополнительная скидка 7%. Промокод нужно сказать менеджеру.',
            'min_price'          => null,
            'discount'           => 7,
            'discount_type'      => 'percent',
            'date_start'         => '2026-01-01',
            'date_end'           => '2026-05-31',
            'active'             => true,
            'forced_attribution' => false,
            'referral_link'      => 'https://go.avred.online/f4cf85834ec9b491?erid=2VfnxxP64Ho&m=34',
            'private'            => false,
            'banners'            => array(),
            'status'             => 'active',
        );
        return array_replace($defaults, $overrides);
    }

    /**
     * Минимальный API-client stub: get_credentials($id) → {api_key}, get_network_config('advcake') → {id, api_base_url}.
     */
    private function make_api_client_stub(?string $api_key = 'REDACTED_ADVCAKE_TEST_KEY', int $network_id = 9): object
    {
        return new class($api_key, $network_id) {
            public array $get_credentials_calls = array();
            public array $get_network_config_calls = array();
            public function __construct(public ?string $api_key, public int $network_id) {}
            public function get_credentials( int $network_id ): ?array {
                $this->get_credentials_calls[] = $network_id;
                return $this->api_key === null
                    ? null
                    : array( 'api_key' => $this->api_key );
            }
            public function get_network_config( string $slug ): ?array {
                $this->get_network_config_calls[] = $slug;
                return array(
                    'id'           => $this->network_id,
                    'slug'         => $slug,
                    'api_base_url' => 'https://api.advcake.ru',
                    'is_active'    => 1,
                );
            }
        };
    }

    private function adapter(?object $api_client = null): Cashback_Advcake_Coupons_Adapter
    {
        return new Cashback_Advcake_Coupons_Adapter(
            new Cashback_Advcake_Adapter(),
            $api_client ?? $this->make_api_client_stub()
        );
    }

    // ----------------------------------------------------------------------
    // Contract
    // ----------------------------------------------------------------------

    public function test_get_network_slug_returns_advcake(): void
    {
        $this->assertSame('advcake', $this->adapter()->get_network_slug());
    }

    public function test_supports_campaign_filter_returns_false(): void
    {
        // Diagnostic 2026-05-14: /promocodes?offer=403 НЕ фильтрует на стороне сервера —
        // возвращает все промокоды, не только те у которых offer_id=403. Поэтому делаем
        // client-side filter в fetch_coupons.
        $this->assertFalse($this->adapter()->supports_campaign_filter());
    }

    public function test_get_required_scope_returns_null(): void
    {
        // Advcake использует api_key, не OAuth — scope не применим.
        $this->assertNull($this->adapter()->get_required_scope());
    }

    // ----------------------------------------------------------------------
    // URL composition + no-token short-circuit
    // ----------------------------------------------------------------------

    public function test_fetch_coupons_returns_empty_when_credentials_missing(): void
    {
        $api_client = $this->make_api_client_stub(null);
        $adapter    = $this->adapter($api_client);

        $coupons = $adapter->fetch_coupons('403');

        $this->assertSame(array(), $coupons);
        $this->assertSame(array(), $GLOBALS['_cb_test_http_calls'], 'нет credentials — нет HTTP-вызова');
    }

    public function test_fetch_coupons_builds_url_with_pass_type_limit_offset(): void
    {
        $this->queue_responses(array( $this->http_response(200, $this->promocodes_body(array())) ));

        $this->adapter()->fetch_coupons('403');

        $this->assertCount(1, $GLOBALS['_cb_test_http_calls']);
        $url = $GLOBALS['_cb_test_http_calls'][0]['url'];
        $this->assertStringStartsWith('https://api.advcake.ru/promocodes?', $url);

        $q = $this->url_query();
        $this->assertSame('REDACTED_ADVCAKE_TEST_KEY', $q['pass']);
        $this->assertSame('json', $q['type']);
        $this->assertArrayHasKey('limit', $q);
        $this->assertSame('0', $q['offset']);
        // supports_campaign_filter()=false → НЕ должно быть &offer=403 в URL.
        $this->assertArrayNotHasKey('offer', $q, 'server-side filter не поддерживается → не шлём offer=');
    }

    // ----------------------------------------------------------------------
    // Mapping → DTO
    // ----------------------------------------------------------------------

    public function test_fetch_coupons_maps_real_advcake_promocode_to_dto(): void
    {
        $this->queue_responses(array( $this->http_response(200, $this->promocodes_body(array(
            $this->sample_promocode(),
        ))) ));

        $coupons = $this->adapter()->fetch_coupons('403');

        $this->assertCount(1, $coupons);
        $dto = $coupons[0];
        $this->assertInstanceOf(Cashback_Coupon_DTO::class, $dto);
        $this->assertSame('pc108357', $dto->external_id);
        $this->assertSame('promocode', $dto->species);
        // ВАЖНО: raw.name='geekpromo' — это сам код, маппится в DTO.promocode.
        $this->assertSame('geekpromo', $dto->promocode);
        // DTO.name — это отображаемое название, берётся из raw.short_name.
        $this->assertSame('Скидка 7% на заказ', $dto->name);
        $this->assertSame('Скидка 7% на заказ', $dto->short_name);
        $this->assertSame('https://go.avred.online/f4cf85834ec9b491?erid=2VfnxxP64Ho&m=34', $dto->goto_link);
        $this->assertSame('7%', $dto->discount);
        $this->assertNotNull($dto->date_start);
        $this->assertSame('2026-01-01', $dto->date_start->format('Y-m-d'));
        $this->assertNotNull($dto->date_end);
        $this->assertSame('2026-05-31', $dto->date_end->format('Y-m-d'));
        $this->assertSame(array(), $dto->regions, 'Advcake /promocodes regions не отдаёт');
        $this->assertSame(array(), $dto->categories);
        $this->assertFalse($dto->is_exclusive);
        $this->assertArrayHasKey('id', $dto->raw_payload);
        $this->assertSame('pc108357', $dto->raw_payload['id']);
    }

    public function test_fetch_coupons_classifies_as_deal_when_promocode_empty(): void
    {
        // У Advcake промокод хранится в `name`. Если name пуст (сейл-кампания без кода),
        // species='deal', promocode=null.
        $raw = $this->sample_promocode(array( 'id' => 'pc999', 'name' => '' ));
        $this->queue_responses(array( $this->http_response(200, $this->promocodes_body(array( $raw ))) ));

        $coupons = $this->adapter()->fetch_coupons('403');

        $this->assertCount(1, $coupons);
        $this->assertSame('deal', $coupons[0]->species);
        $this->assertNull($coupons[0]->promocode);
    }

    public function test_fetch_coupons_discount_string_uses_percent_sign(): void
    {
        $raw = $this->sample_promocode(array( 'discount' => 75, 'discount_type' => 'percent' ));
        $this->queue_responses(array( $this->http_response(200, $this->promocodes_body(array( $raw ))) ));

        $coupons = $this->adapter()->fetch_coupons('403');
        $this->assertSame('75%', $coupons[0]->discount);
    }

    public function test_fetch_coupons_discount_string_omitted_when_value_null(): void
    {
        // Citilink-кейс из diagnostic'а: discount=null, discount_type='percent' →
        // discount строка не строится (нет смысла "null%").
        $raw = $this->sample_promocode(array( 'discount' => null, 'discount_type' => 'percent' ));
        $this->queue_responses(array( $this->http_response(200, $this->promocodes_body(array( $raw ))) ));

        $coupons = $this->adapter()->fetch_coupons('403');
        $this->assertNull($coupons[0]->discount);
    }

    // ----------------------------------------------------------------------
    // Client-side filter (server-side не работает)
    // ----------------------------------------------------------------------

    public function test_fetch_coupons_filters_by_offer_id_client_side(): void
    {
        // diagnostic 2026-05-14 показал что /promocodes?offer=403 не фильтрует —
        // адаптер сам отбрасывает строки с offer_id ≠ запрошенного.
        $raw_gb_ru    = $this->sample_promocode(array( 'id' => 'pc_gb',  'offer_id' => 403 ));
        $raw_skillbox = $this->sample_promocode(array( 'id' => 'pc_skb', 'offer_id' => 94 ));
        $raw_citilink = $this->sample_promocode(array( 'id' => 'pc_ctl', 'offer_id' => 622 ));

        $this->queue_responses(array( $this->http_response(200, $this->promocodes_body(array(
            $raw_gb_ru, $raw_skillbox, $raw_citilink,
        ))) ));

        $coupons = $this->adapter()->fetch_coupons('403');

        $this->assertCount(1, $coupons);
        $this->assertSame('pc_gb', $coupons[0]->external_id);
    }

    public function test_fetch_coupons_accepts_offer_id_as_int_or_string(): void
    {
        // sample_promocode имеет int offer_id=403, мы вызываем с string '403'.
        $raw = $this->sample_promocode(array( 'offer_id' => 403 ));
        $this->queue_responses(array( $this->http_response(200, $this->promocodes_body(array( $raw ))) ));

        $coupons = $this->adapter()->fetch_coupons('403');
        $this->assertCount(1, $coupons);
    }

    // ----------------------------------------------------------------------
    // Filter + skip rules
    // ----------------------------------------------------------------------

    public function test_fetch_coupons_skips_inactive_promocodes(): void
    {
        $active   = $this->sample_promocode(array( 'id' => 'pc_active',   'active' => true ));
        $inactive = $this->sample_promocode(array( 'id' => 'pc_inactive', 'active' => false ));
        $this->queue_responses(array( $this->http_response(200, $this->promocodes_body(array( $active, $inactive ))) ));

        $coupons = $this->adapter()->fetch_coupons('403');

        $this->assertCount(1, $coupons);
        $this->assertSame('pc_active', $coupons[0]->external_id);
    }

    public function test_fetch_coupons_skips_row_when_required_field_missing(): void
    {
        $good = $this->sample_promocode(array( 'id' => 'pc_good' ));
        // У этой записи нет referral_link → goto_link empty → DTO throws → skip.
        $broken = $this->sample_promocode(array( 'id' => 'pc_broken', 'referral_link' => '' ));
        // У этой записи нет id → external_id empty → DTO throws → skip.
        $no_id  = $this->sample_promocode(array( 'id' => '' ));

        $this->queue_responses(array( $this->http_response(200, $this->promocodes_body(array(
            $good, $broken, $no_id,
        ))) ));

        $coupons = $this->adapter()->fetch_coupons('403');

        $this->assertCount(1, $coupons);
        $this->assertSame('pc_good', $coupons[0]->external_id);
    }

    // ----------------------------------------------------------------------
    // HTTP error handling (soft-fail)
    // ----------------------------------------------------------------------

    public function test_fetch_coupons_returns_empty_on_401_no_retry(): void
    {
        $this->queue_responses(array( $this->http_response(401, '{"error":"Unauthorized"}') ));

        $coupons = $this->adapter()->fetch_coupons('403');

        $this->assertSame(array(), $coupons);
        $this->assertCount(1, $GLOBALS['_cb_test_http_calls'], '401 не retry-ится');
    }

    public function test_fetch_coupons_returns_empty_when_decoded_success_false(): void
    {
        $body = (string) wp_json_encode(array( 'success' => false, 'error' => 'token invalid' ));
        $this->queue_responses(array( $this->http_response(200, $body) ));

        $coupons = $this->adapter()->fetch_coupons('403');

        $this->assertSame(array(), $coupons);
    }

    public function test_fetch_coupons_returns_empty_on_malformed_json(): void
    {
        $this->queue_responses(array( $this->http_response(200, 'not json {{{ broken') ));

        $coupons = $this->adapter()->fetch_coupons('403');

        $this->assertSame(array(), $coupons);
    }

    public function test_fetch_coupons_returns_empty_on_wp_error(): void
    {
        $GLOBALS['_cb_test_http_response_callback'] = static function () {
            return new WP_Error('http_request_failed', 'connection timeout');
        };

        $coupons = $this->adapter()->fetch_coupons('403');

        $this->assertSame(array(), $coupons);
    }

    // ----------------------------------------------------------------------
    // Pagination
    // ----------------------------------------------------------------------

    public function test_fetch_coupons_paginates_until_short_page(): void
    {
        // Полный лимит=100 → второй запрос со offset=100. Вторая страница неполная → стоп.
        $page1 = array();
        for ($i = 0; $i < 100; $i++) {
            $page1[] = $this->sample_promocode(array( 'id' => 'p_a_' . $i ));
        }
        $page2 = array(
            $this->sample_promocode(array( 'id' => 'p_b_1' )),
            $this->sample_promocode(array( 'id' => 'p_b_2' )),
        );

        $this->queue_responses(array(
            $this->http_response(200, $this->promocodes_body($page1, 102)),
            $this->http_response(200, $this->promocodes_body($page2, 102)),
        ));

        $coupons = $this->adapter()->fetch_coupons('403');

        $this->assertCount(102, $coupons);
        $this->assertCount(2, $GLOBALS['_cb_test_http_calls']);
        $this->assertSame('0', $this->url_query(0)['offset']);
        $this->assertSame('100', $this->url_query(1)['offset']);
    }
}
