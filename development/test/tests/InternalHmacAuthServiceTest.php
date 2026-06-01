<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('internal-rest-api')]
final class InternalHmacAuthServiceTest extends TestCase
{
    private const SECRET = 'internal-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        update_option('savello_internal_api_enabled', 1);
        update_option('savello_internal_api_secret', self::SECRET);
    }

    private function request(string $body = '{"b":2,"a":1}', ?string $timestamp = null, ?string $signature = null): WP_REST_Request
    {
        require_once dirname(__DIR__, 3) . '/includes/services/class-internal-hmac-auth-service.php';

        $timestamp ??= (string) time();
        $signature ??= Savello_Internal_HMAC_Auth_Service::build_signature($timestamp, $body, self::SECRET);

        $request = new WP_REST_Request('POST', '/savello-internal/v1/resolve-product');
        $request->set_body($body);
        $request->set_header('X-Savello-Site', 'savelloclub.test');
        $request->set_header('X-Savello-Timestamp', $timestamp);
        $request->set_header('X-Savello-Signature', $signature);

        return $request;
    }

    public function test_valid_signature_is_accepted(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/services/class-internal-hmac-auth-service.php';

        $result = (new Savello_Internal_HMAC_Auth_Service())->verify_request($this->request());

        self::assertTrue($result);
    }

    public function test_missing_headers_are_rejected_with_401(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/services/class-internal-hmac-auth-service.php';

        $result = (new Savello_Internal_HMAC_Auth_Service())->verify_request(new WP_REST_Request('GET', '/savello-internal/v1/health'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('savello_internal_auth_missing', $result->get_error_code());
        self::assertSame(401, $result->get_error_data()['status'] ?? null);
    }

    public function test_invalid_signature_is_rejected_with_403(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/services/class-internal-hmac-auth-service.php';

        $result = (new Savello_Internal_HMAC_Auth_Service())->verify_request($this->request(signature: 'bad-signature'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('savello_internal_auth_invalid', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status'] ?? null);
    }

    public function test_expired_timestamp_is_rejected(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/services/class-internal-hmac-auth-service.php';

        $timestamp = (string) ( time() - 301 );
        $body      = '{"ok":true}';
        $result    = (new Savello_Internal_HMAC_Auth_Service())->verify_request($this->request($body, $timestamp));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('savello_internal_auth_invalid', $result->get_error_code());
    }

    public function test_signature_uses_raw_body_not_reencoded_json(): void
    {
        require_once dirname(__DIR__, 3) . '/includes/services/class-internal-hmac-auth-service.php';

        $raw_body = '{"b":2, "a":1}';
        $request  = $this->request($raw_body);
        $request->set_body_params(array( 'a' => 1, 'b' => 2 ));

        $result = (new Savello_Internal_HMAC_Auth_Service())->verify_request($request);

        self::assertTrue($result);
    }

    public function test_source_uses_hash_equals_and_does_not_expose_secret_in_errors(): void
    {
        $path = dirname(__DIR__, 3) . '/includes/services/class-internal-hmac-auth-service.php';
        $src  = file_get_contents($path);

        self::assertIsString($src);
        self::assertStringContainsString('hash_equals', $src);

        require_once $path;
        $result = (new Savello_Internal_HMAC_Auth_Service())->verify_request($this->request(signature: 'bad'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertStringNotContainsString(self::SECRET, wp_json_encode($result));
    }
}
