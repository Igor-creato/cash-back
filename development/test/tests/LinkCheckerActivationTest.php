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
}
