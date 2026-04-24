<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-2-001 п.5: host-валидация на admin save (не на redirect).
 *
 * Scope: WC_Affiliate_URL_Params::is_safe_destination_host +
 * validate_external_product_url_scheme (reject unsafe_host).
 *
 * Runtime (is_safe_http_url) остаётся scheme-only by design —
 * wp_safe_redirect с host-allowlist ломает CPA-атрибуцию.
 */
#[Group('security')]
#[Group('f-2-001')]
#[Group('host-validation')]
final class DestinationHostValidationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('WC_Affiliate_URL_Params')) {
            require_once dirname(__DIR__, 3) . '/wc-affiliate-url-params.php';
        }
    }

    // =====================================================================
    // 1. Behavioural: is_safe_destination_host
    // =====================================================================

    #[DataProvider('accepted_hosts')]
    public function test_accepts_safe_host( string $url ): void
    {
        $this->assertTrue($this->invoke_host_check($url), "URL should be accepted: {$url}");
    }

    public static function accepted_hosts(): array
    {
        return array(
            'https_public'        => array( 'https://shop.example.com/p/123' ),
            'http_public'         => array( 'http://example.com/path?a=1' ),
            'subdomain'           => array( 'https://cdn.partner.ru/item' ),
            'numeric_subdomain'   => array( 'https://1.partner.ru/' ),
            'deep_subdomain'      => array( 'https://a.b.c.d.example.org/' ),
            'uppercase'           => array( 'https://SHOP.EXAMPLE.COM/' ),
        );
    }

    #[DataProvider('rejected_hosts')]
    public function test_rejects_unsafe_host( string $url, string $reason ): void
    {
        $this->assertFalse($this->invoke_host_check($url), $reason);
    }

    public static function rejected_hosts(): array
    {
        return array(
            'empty_string'         => array( '', 'Пустая строка — нет host.' ),
            'no_host'              => array( 'not a url', 'Мусорный ввод.' ),
            'ipv4'                 => array( 'https://127.0.0.1/shop', 'Loopback IPv4.' ),
            'ipv4_public'          => array( 'https://8.8.8.8/path', 'Public IPv4-литерал — всё равно отклоняется.' ),
            'ipv4_three_octets'    => array( 'https://1.2.3.4/', 'Classic IPv4.' ),
            'ipv6_loopback'        => array( 'https://[::1]/path', 'IPv6 loopback.' ),
            'ipv6_full'            => array( 'https://[2001:db8::1]/', 'IPv6 literal.' ),
            'localhost'            => array( 'http://localhost/', 'Single-label localhost.' ),
            'single_label'         => array( 'https://intranet/path', 'Single-label dev-host.' ),
            'tld_local'            => array( 'https://shop.local/', 'Reserved TLD .local.' ),
            'tld_localhost'        => array( 'https://shop.localhost/', 'Reserved TLD .localhost.' ),
            'tld_test'             => array( 'https://shop.test/', 'Reserved TLD .test.' ),
            'tld_example'          => array( 'https://shop.example/', 'Reserved TLD .example.' ),
            'tld_internal'         => array( 'https://shop.internal/', 'Reserved TLD .internal.' ),
            'tld_invalid'          => array( 'https://shop.invalid/', 'Reserved TLD .invalid.' ),
            'tld_uppercase'        => array( 'https://shop.LOCAL/', 'TLD case-insensitive.' ),
        );
    }

    private function invoke_host_check( string $url ): bool
    {
        $reflection = new ReflectionClass('WC_Affiliate_URL_Params');
        $instance   = $reflection->newInstanceWithoutConstructor();
        $method     = $reflection->getMethod('is_safe_destination_host');
        $method->setAccessible(true);

        return (bool) $method->invoke($instance, $url);
    }

    // =====================================================================
    // 2. Behavioural: validate_external_product_url_scheme clears unsafe host
    // =====================================================================

    public function test_validate_clears_url_for_ipv4_host(): void
    {
        $product = $this->make_fake_product('https://127.0.0.1/shop');
        $this->invoke_validate($product);

        self::assertArrayHasKey('product_url', $product->props_set);
        self::assertSame('', $product->props_set['product_url']);
    }

    public function test_validate_clears_url_for_localhost(): void
    {
        $product = $this->make_fake_product('http://localhost/path');
        $this->invoke_validate($product);

        self::assertArrayHasKey('product_url', $product->props_set);
        self::assertSame('', $product->props_set['product_url']);
    }

    public function test_validate_clears_url_for_test_tld(): void
    {
        $product = $this->make_fake_product('https://shop.test/product');
        $this->invoke_validate($product);

        self::assertArrayHasKey('product_url', $product->props_set);
        self::assertSame('', $product->props_set['product_url']);
    }

    public function test_validate_preserves_public_https_host(): void
    {
        // Regression: валидный public-URL не затираем.
        $product = $this->make_fake_product('https://shop.example.com/p/42');
        $this->invoke_validate($product);

        self::assertSame(array(), $product->props_set, 'Public URL должен остаться нетронутым.');
    }

    private function make_fake_product( string $url ): object
    {
        return new class($url) {
            public string $url;
            public array  $props_set = array();

            public function __construct( string $url )
            {
                $this->url = $url;
            }

            public function get_type(): string { return 'external'; }
            public function get_product_url(): string { return $this->url; }
            public function set_props( array $props ): void {
                $this->props_set = $props;
                if (array_key_exists('product_url', $props)) {
                    $this->url = (string) $props['product_url'];
                }
            }
            public function get_id(): int { return 42; }
            public function get_name(): string { return 'Fake Product'; }
        };
    }

    private function invoke_validate( object $product ): void
    {
        $reflection = new ReflectionClass('WC_Affiliate_URL_Params');
        $instance   = $reflection->newInstanceWithoutConstructor();
        $method     = $reflection->getMethod('validate_external_product_url_scheme');

        $method->invoke($instance, $product);
    }
}
