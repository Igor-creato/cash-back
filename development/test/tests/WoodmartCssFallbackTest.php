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
        $this->assertStringContainsString("'wp_head'", $this->source);
        $this->assertStringContainsString("'print_footer_layout_fallback_on_cashback_pages'", $this->source);
    }

    public function test_footer_fallback_targets_legal_and_contact_shortcode_pages(): void
    {
        $this->assertStringContainsString('_cashback_legal_type', $this->source);
        $this->assertStringContainsString('cashback_legal_doc', $this->source);
        $this->assertStringContainsString('cashback_contact_form', $this->source);
        $this->assertStringContainsString('is_woodmart_wishlist_page', $this->source);
        $this->assertStringContainsString('wishlist_page', $this->source);
        $this->assertStringContainsString('izbrannye-magaziny', $this->source);
        $this->assertStringContainsString('is_woocommerce_account_page', $this->source);
        $this->assertStringContainsString('is_account_page', $this->source);
    }

    public function test_footer_fallback_forces_woodmart_footer_base_style(): void
    {
        $this->assertStringContainsString("woodmart_force_enqueue_style('footer-base')", $this->source);
    }

    public function test_footer_layout_fallback_keeps_elementor_payment_block_stacked(): void
    {
        $this->assertStringContainsString('FOOTER_LAYOUT_CSS', $this->source);
        $this->assertStringContainsString('.elementor-element-fc1da1d.e-flexbox-base', $this->source);
        $this->assertStringContainsString('flex-direction:column', $this->source);
        $this->assertStringContainsString('cashback-woodmart-footer-layout-fallback', $this->source);
    }
}
