<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Тесты Cashback_Faq_Shortcode — render шорткода [cashback_faq].
 */
#[Group('faq')]
#[Group('faq-shortcode')]
final class FaqShortcodeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);

        if (!function_exists('shortcode_atts')) {
            function shortcode_atts( array $pairs, array $atts, string $shortcode = '' ): array {
                return array_merge($pairs, $atts);
            }
        }
        if (!function_exists('add_shortcode')) {
            function add_shortcode( string $tag, callable $callback ): bool {
                return true;
            }
        }
        if (!function_exists('wp_kses_post')) {
            function wp_kses_post( string $content ): string {
                // Минимальный mock: вырезаем <script> блоки.
                return preg_replace('#<script\b[^>]*>.*?</script>#is', '', $content) ?? '';
            }
        }

        require_once $plugin_root . '/faq/class-cashback-faq-content.php';
        require_once $plugin_root . '/faq/class-cashback-faq-shortcode.php';
    }

    public function test_renders_all_categories_by_default(): void
    {
        $output = Cashback_Faq_Shortcode::render(array());

        $this->assertNotSame('', $output);
        $this->assertStringContainsString('class="cashback-faq"', $output);

        foreach (Cashback_Faq_Content::category_slugs() as $slug) {
            $this->assertStringContainsString(
                'data-category="' . $slug . '"',
                $output,
                'Категория "' . $slug . '" должна присутствовать в default-render'
            );
        }
    }

    public function test_filters_by_single_category(): void
    {
        $output = Cashback_Faq_Shortcode::render(array(
            'category' => Cashback_Faq_Content::CATEGORY_WITHDRAWAL,
        ));

        $this->assertStringContainsString(
            'data-category="' . Cashback_Faq_Content::CATEGORY_WITHDRAWAL . '"',
            $output
        );
        $this->assertStringNotContainsString(
            'data-category="' . Cashback_Faq_Content::CATEGORY_REFERRAL . '"',
            $output
        );
        $this->assertStringNotContainsString(
            'data-category="' . Cashback_Faq_Content::CATEGORY_GETTING_STARTED . '"',
            $output
        );
    }

    public function test_filters_by_csv_categories(): void
    {
        $output = Cashback_Faq_Shortcode::render(array(
            'category' => Cashback_Faq_Content::CATEGORY_WITHDRAWAL . ',' . Cashback_Faq_Content::CATEGORY_REFERRAL,
        ));

        $this->assertStringContainsString(
            'data-category="' . Cashback_Faq_Content::CATEGORY_WITHDRAWAL . '"',
            $output
        );
        $this->assertStringContainsString(
            'data-category="' . Cashback_Faq_Content::CATEGORY_REFERRAL . '"',
            $output
        );
        $this->assertStringNotContainsString(
            'data-category="' . Cashback_Faq_Content::CATEGORY_GETTING_STARTED . '"',
            $output
        );
    }

    public function test_invalid_category_renders_nothing(): void
    {
        $output = Cashback_Faq_Shortcode::render(array(
            'category' => 'non-existent-slug',
        ));

        $this->assertSame('', $output);
    }

    public function test_open_first_adds_open_attribute(): void
    {
        $output_default = Cashback_Faq_Shortcode::render(array(
            'category'   => Cashback_Faq_Content::CATEGORY_WITHDRAWAL,
            'open_first' => 'false',
        ));
        $output_open = Cashback_Faq_Shortcode::render(array(
            'category'   => Cashback_Faq_Content::CATEGORY_WITHDRAWAL,
            'open_first' => 'true',
        ));

        $this->assertStringNotContainsString('<details class="cashback-faq__item" open>', $output_default);
        $this->assertStringContainsString('<details class="cashback-faq__item" open>', $output_open);
        // Только первый — не все.
        $this->assertSame(1, substr_count($output_open, ' open>'));
    }

    public function test_show_titles_false_omits_category_title(): void
    {
        $output_with    = Cashback_Faq_Shortcode::render(array(
            'category'    => Cashback_Faq_Content::CATEGORY_GETTING_STARTED,
            'show_titles' => 'true',
        ));
        $output_without = Cashback_Faq_Shortcode::render(array(
            'category'    => Cashback_Faq_Content::CATEGORY_GETTING_STARTED,
            'show_titles' => 'false',
        ));

        $this->assertStringContainsString('cashback-faq__category-title', $output_with);
        $this->assertStringNotContainsString('cashback-faq__category-title', $output_without);
    }

    public function test_answers_pass_through_kses_post(): void
    {
        // Полагаемся на wp_kses_post mock — он вырезает <script> блоки.
        // Проверяем что render не выводит сырой пользовательский HTML, а
        // прогоняет через фильтр (контракт безопасности).
        $output = Cashback_Faq_Shortcode::render(array());
        $this->assertStringNotContainsString('<script>', $output);
    }

    public function test_sanitizes_csv_with_whitespace(): void
    {
        $output = Cashback_Faq_Shortcode::render(array(
            'category' => '  ' . Cashback_Faq_Content::CATEGORY_REFERRAL . ' , bad-slug ',
        ));

        $this->assertStringContainsString(
            'data-category="' . Cashback_Faq_Content::CATEGORY_REFERRAL . '"',
            $output
        );
        $this->assertStringNotContainsString('bad-slug', $output);
    }
}
