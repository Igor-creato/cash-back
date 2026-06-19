<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('price-assistant-connector-assets')]
final class PriceAssistantConnectorAssetsTest extends TestCase
{
    public function test_connector_manifest_requests_cookies_permission_without_all_urls(): void
    {
        $manifest_file = dirname(__DIR__, 3) . '/price-assistant-connector/manifest.json';

        self::assertFileExists($manifest_file, 'Marketplace session connector manifest must exist.');

        $manifest = json_decode((string) file_get_contents($manifest_file), true);

        self::assertSame(3, $manifest['manifest_version'] ?? null);
        self::assertContains('cookies', $manifest['permissions'] ?? array());
        self::assertNotContains('<all_urls>', $manifest['host_permissions'] ?? array());
        self::assertNotContains('<all_urls>', $manifest['optional_host_permissions'] ?? array());
    }

    public function test_connector_service_worker_filters_cookies_by_allowlist_after_consent(): void
    {
        $worker_file = dirname(__DIR__, 3) . '/price-assistant-connector/service-worker.js';

        self::assertFileExists($worker_file, 'Marketplace session connector service worker must exist.');

        $source = (string) file_get_contents($worker_file);

        self::assertStringContainsString('chrome.cookies.getAll', $source);
        self::assertStringContainsString('filterAllowlistedCookies', $source);
        self::assertStringContainsString('allowedNames', $source);
        self::assertStringContainsString('session-bundle', $source);
        self::assertStringContainsString('consent', $source);
        self::assertStringNotContainsString('document.cookie', $source);
        self::assertStringNotContainsString('localStorage', $source);
        self::assertStringNotContainsString('sessionStorage', $source);
        self::assertStringNotContainsString('password', strtolower($source));
    }
}
