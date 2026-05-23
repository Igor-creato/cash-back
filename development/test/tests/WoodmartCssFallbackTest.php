<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('woodmart')]
#[Group('frontend')]
final class WoodmartCssFallbackTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $path = dirname(__DIR__, 3) . '/includes/class-cashback-woodmart-css-fallback.php';
        $this->assertFileExists($path);
        $this->source = (string) file_get_contents($path);
    }

    public function test_register_forces_footer_styles_during_frontend_enqueue(): void
    {
        $this->assertStringContainsString("'wp_enqueue_scripts'", $this->source);
        $this->assertStringContainsString("'force_footer_styles_on_cashback_pages'", $this->source);
    }

    public function test_footer_fallback_targets_legal_and_contact_shortcode_pages(): void
    {
        $this->assertStringContainsString('_cashback_legal_type', $this->source);
        $this->assertStringContainsString('cashback_legal_doc', $this->source);
        $this->assertStringContainsString('cashback_contact_form', $this->source);
    }

    public function test_footer_fallback_forces_woodmart_footer_base_style(): void
    {
        $this->assertStringContainsString("woodmart_force_enqueue_style('footer-base')", $this->source);
    }
}
