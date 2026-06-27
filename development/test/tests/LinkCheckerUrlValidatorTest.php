<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('link-checker')]
#[Group('security')]
final class LinkCheckerUrlValidatorTest extends TestCase {

    public static function setUpBeforeClass(): void {
        require_once dirname(__DIR__, 3) . '/includes/link-checker/class-cashback-link-checker-url-validator.php';
    }

    public function test_accepts_public_http_and_https_urls_and_normalizes_host(): void {
        $result = Cashback_Link_Checker_Url_Validator::validate(' https://www.Example.com/product/123?utm_source=x ');

        self::assertTrue($result['ok']);
        self::assertSame('https://www.Example.com/product/123?utm_source=x', $result['url']);
        self::assertSame('example.com', $result['host']);

        $http = Cashback_Link_Checker_Url_Validator::validate('http://shop.example/catalog');
        self::assertTrue($http['ok']);
        self::assertSame('shop.example', $http['host']);
    }

    #[DataProvider('unsafeUrlProvider')]
    public function test_rejects_unsafe_urls(string $url, string $expected_error): void {
        $result = Cashback_Link_Checker_Url_Validator::validate($url);

        self::assertFalse($result['ok'], $url);
        self::assertSame('', $result['url']);
        self::assertSame('', $result['host']);
        self::assertSame($expected_error, $result['error']);
    }

    /**
     * @return array<string, array{0:string,1:string}>
     */
    public static function unsafeUrlProvider(): array {
        return array(
            'empty'              => array( '', 'empty_url' ),
            'relative'           => array( '/catalog/product', 'invalid_url' ),
            'javascript'         => array( 'javascript:alert(1)', 'invalid_url' ),
            'data'               => array( 'data:text/html;base64,xxx', 'invalid_url' ),
            'file'               => array( 'file:///etc/passwd', 'invalid_url' ),
            'localhost'          => array( 'https://localhost/product', 'blocked_host' ),
            'localhost_sub'      => array( 'https://shop.localhost/product', 'blocked_host' ),
            'loopback_ipv4'      => array( 'https://127.0.0.1/product', 'private_ip' ),
            'private_10'         => array( 'https://10.1.2.3/product', 'private_ip' ),
            'private_172'        => array( 'https://172.16.0.1/product', 'private_ip' ),
            'private_192'        => array( 'https://192.168.1.1/product', 'private_ip' ),
            'link_local'         => array( 'https://169.254.1.1/product', 'private_ip' ),
            'ipv6_loopback'      => array( 'https://[::1]/product', 'private_ip' ),
            'ipv6_unique_local'  => array( 'https://[fd00::1]/product', 'private_ip' ),
            'userinfo_in_url'    => array( 'https://user:pass@example.com/product', 'userinfo_not_allowed' ),
        );
    }
}
