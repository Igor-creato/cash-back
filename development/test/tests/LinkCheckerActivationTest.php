<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('link-checker')]
#[Group('activate-dedup')]
final class LinkCheckerActivationTest extends TestCase {

    public function test_click_session_service_exposes_link_checker_activation_entrypoint(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php');

        self::assertStringContainsString('activate_for_link_checker', $source);
        self::assertStringContainsString("'source'            => 'link_checker'", $source);
        self::assertStringContainsString("'utm_source'        => 'link_checker'", $source);
    }

    public function test_click_log_persists_utm_source_medium_and_campaign_when_present(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/class-cashback-click-session-service.php');

        self::assertStringContainsString("'utm_source'", $source);
        self::assertStringContainsString("'utm_medium'", $source);
        self::assertStringContainsString("'utm_campaign'", $source);
        self::assertMatchesRegularExpression('/\\$row\\[\\s*[\'"]utm_source[\'"]\\s*\\]/', $source);
    }

    #[Group('direct-product-link')]
    public function test_direct_product_flow_uses_shared_tracking_api_without_hardcoded_sub_fallbacks(): void {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/services/class-cashback-internal-api-service.php');

        self::assertStringContainsString('build_affiliate_tracking_params', $source);
        self::assertStringContainsString('tracking_unavailable', $source);
        self::assertDoesNotMatchRegularExpression('/\\$params\\[\\s*[\'"]sub(?:id)?\\d?[\'"]\\s*\\]\\s*=/', $source);
    }

    #[Group('direct-product-link')]
    public function test_deeplink_adapters_accept_tracking_array_without_sub_only_filters(): void {
        $admitad = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/adapters/class-admitad-adapter.php');
        $advcake = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/adapters/class-cashback-advcake-adapter.php');

        self::assertStringContainsString('create_deeplink', $admitad);
        self::assertStringContainsString('/validate_links/?', $admitad);
        self::assertStringNotContainsString('/^subid', $this->createDeeplinkMethodSource($admitad));

        self::assertStringContainsString('create_deeplink', $advcake);
        self::assertStringContainsString("array( 'data', 'result' )", $advcake);
        self::assertStringNotContainsString('/^sub', $this->createDeeplinkMethodSource($advcake));
        self::assertStringNotContainsString("['sub1']", $this->createDeeplinkMethodSource($advcake));
    }

    private function createDeeplinkMethodSource(string $source): string {
        if (!preg_match('/public function create_deeplink[\\s\\S]*?\\n    public function /', $source, $matches)) {
            return $source;
        }

        return $matches[0];
    }
}
