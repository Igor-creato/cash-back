<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты на Cashback_Promocodes_Shortcode — рендер [cashback_promocodes].
 *
 * @group promocodes
 * @group shortcode
 */
#[Group('promocodes')]
#[Group('shortcode')]
final class PromocodesShortcodeTest extends TestCase
{
    private static string $plugin_root;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root = dirname(__DIR__, 3);
        $files = array(
            '/includes/promocodes/dto/class-coupon-dto.php',
            '/includes/promocodes/class-cashback-promocodes-repository.php',
            '/includes/promocodes/class-cashback-promocodes-shortcode.php',
        );
        foreach ($files as $f) {
            $path = self::$plugin_root . $f;
            if (!file_exists($path)) {
                self::markTestSkipped("File missing: {$f}");
            }
            require_once $path;
        }

        if (!function_exists('esc_url')) {
            function esc_url(string $url): string {
                return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            }
        }
        if (!function_exists('esc_textarea')) {
            function esc_textarea(string $text): string {
                return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }
        if (!function_exists('selected')) {
            function selected(mixed $a, mixed $b, bool $echo = true): string {
                $out = $a == $b ? ' selected="selected"' : '';
                if ($echo) echo $out;
                return $out;
            }
        }
        if (!function_exists('home_url')) {
            function home_url(string $path = '', ?string $scheme = null): string {
                return 'https://example.test' . $path;
            }
        }
        if (!function_exists('add_query_arg')) {
            function add_query_arg(...$args): string {
                if (is_array($args[0])) {
                    $params = $args[0];
                    $url    = (string) ($args[1] ?? '');
                } else {
                    $params = array((string) $args[0] => (string) $args[1]);
                    $url    = (string) ($args[2] ?? '');
                }
                $sep = strpos($url, '?') === false ? '?' : '&';
                $qs  = http_build_query($params);
                return $url . $sep . $qs;
            }
        }
    }

    private function make_repo_stub(array $rows): object
    {
        return new class($rows) extends Cashback_Promocodes_Repository {
            public array $get_active_calls = array();
            public function __construct(public array $rows) {}
            public function get_active_for_campaign( int $network_id, string $advcampaign_id, array $filters = array() ): array {
                $this->get_active_calls[] = array( 'network' => $network_id, 'campaign' => $advcampaign_id, 'filters' => $filters );
                return $this->rows;
            }
        };
    }

    private function sample_row(array $overrides = []): array
    {
        return array_merge(array(
            'id'             => 1,
            'network_id'     => 5,
            'advcampaign_id' => '35530',
            'external_id'    => 'C1',
            'species'        => 'promocode',
            'promocode'      => 'SAVE10',
            'name'           => 'Скидка 10%',
            'description'    => 'Текст',
            'discount'       => '10%',
            'goto_link'      => 'https://example.com/go',
            'date_end'       => '2026-12-31 23:59:59',
            'is_exclusive'   => 0,
            'image'          => null,
        ), $overrides);
    }

    protected function setUp(): void
    {
        $GLOBALS['_cb_test_enqueued_styles']  = array();
        $GLOBALS['_cb_test_enqueued_scripts'] = array();
        $GLOBALS['_cb_test_meta']             = array(
            123 => array( '_offer_id' => '35530', '_affiliate_network_id' => '5' ),
        );
        if (!function_exists('get_post_meta')) {
            function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed {
                $store = $GLOBALS['_cb_test_meta'][ $post_id ] ?? array();
                if ($key === '') return $store;
                if (!isset($store[$key])) return $single ? '' : array();
                return $single ? $store[$key] : array($store[$key]);
            }
        }
    }

    public function test_render_returns_empty_state_when_no_coupons(): void
    {
        $repo  = $this->make_repo_stub(array());
        $sc    = new Cashback_Promocodes_Shortcode($repo);
        $html  = $sc->render(array( 'product_id' => 123 ));

        $this->assertStringContainsString('cashback-promocodes', $html);
        $this->assertStringContainsString('cashback-promocodes--empty', $html, 'Должен быть empty-state класс');
    }

    public function test_render_outputs_coupon_card_with_escaped_fields(): void
    {
        $repo = $this->make_repo_stub(array(
            $this->sample_row(array(
                'name'      => '<script>alert(1)</script>',
                'promocode' => 'SAVE&10',
            )),
        ));
        $sc   = new Cashback_Promocodes_Shortcode($repo);
        $html = $sc->render(array( 'product_id' => 123 ));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'XSS должен быть экранирован');
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('SAVE&amp;10', $html, 'Промокод должен быть escaped');
    }

    public function test_render_passes_limit_and_species_to_repository(): void
    {
        $repo = $this->make_repo_stub(array());
        $sc   = new Cashback_Promocodes_Shortcode($repo);
        $sc->render(array(
            'product_id' => 123,
            'limit'      => '15',
            'species'    => 'promocode,deal',
        ));

        $this->assertCount(1, $repo->get_active_calls);
        $call = $repo->get_active_calls[0];
        $this->assertSame(15, $call['filters']['limit']);
        $this->assertSame(array( 'promocode', 'deal' ), $call['filters']['species']);
    }

    public function test_render_caps_limit_to_hard_max(): void
    {
        $repo = $this->make_repo_stub(array());
        $sc   = new Cashback_Promocodes_Shortcode($repo);
        $sc->render(array(
            'product_id' => 123,
            'limit'      => '999',
        ));

        $call = $repo->get_active_calls[0];
        $this->assertLessThanOrEqual(100, $call['filters']['limit']);
    }

    public function test_render_returns_empty_when_product_id_missing(): void
    {
        $repo = $this->make_repo_stub(array( $this->sample_row() ));
        $sc   = new Cashback_Promocodes_Shortcode($repo);
        $html = $sc->render(array());

        // Без product_id и без current loop product — пусто (либо empty-state).
        $this->assertCount(0, $repo->get_active_calls, 'Без product_id repository не должен вызываться');
    }

    public function test_render_goto_button_points_to_promo_redirect_endpoint(): void
    {
        // С 7.3.0: href ведёт НЕ на goto_link напрямую, а на серверный redirect-handler
        // /?cashback_promo_click={id} (Cashback_Promocodes_Redirect), который генерирует
        // click_id, подставляет CPA-параметры и пишет в cashback_click_log. goto_link
        // напрямую в HTML больше не светится (партнёрский URL не утекает в DOM).
        $repo = $this->make_repo_stub(array(
            $this->sample_row(array(
                'id'        => 42,
                'goto_link' => 'https://example.com/go?param=1&q=2',
            )),
        ));
        $sc   = new Cashback_Promocodes_Shortcode($repo);
        $html = $sc->render(array( 'product_id' => 123 ));

        $this->assertMatchesRegularExpression(
            '/href="[^"]*cashback_promo_click=42[^"]*"/',
            $html,
            'href должен содержать cashback_promo_click={promo_id}'
        );
        $this->assertStringNotContainsString(
            'example.com',
            $html,
            'Партнёрский goto_link не должен попадать в DOM (только в БД)'
        );
    }

    public function test_render_includes_copy_button_for_promocode_species(): void
    {
        $repo = $this->make_repo_stub(array( $this->sample_row(array( 'species' => 'promocode', 'promocode' => 'CODE1' )) ));
        $sc   = new Cashback_Promocodes_Shortcode($repo);
        $html = $sc->render(array( 'product_id' => 123 ));

        $this->assertStringContainsString('data-action="copy"', $html, 'Кнопка copy должна быть для species=promocode');
    }

    public function test_render_no_copy_button_for_deal_species(): void
    {
        $repo = $this->make_repo_stub(array( $this->sample_row(array( 'species' => 'deal', 'promocode' => null )) ));
        $sc   = new Cashback_Promocodes_Shortcode($repo);
        $html = $sc->render(array( 'product_id' => 123 ));

        $this->assertStringNotContainsString('data-action="copy"', $html, 'Для deal не должно быть кнопки copy');
        $this->assertStringContainsString('data-action="goto"', $html, 'Для deal должна быть кнопка goto');
    }
}
