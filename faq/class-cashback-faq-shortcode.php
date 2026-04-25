<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Faq_Shortcode
 *
 * Шорткод [cashback_faq] — рендерит публичный FAQ для пользователей.
 *
 * Атрибуты:
 *   - category    string  CSV-список slug'ов категорий или '' (все). По умолчанию ''.
 *   - open_first  bool    Открыть первый <details> по умолчанию ('true' / 'false').
 *   - show_titles bool    Показывать заголовки категорий ('true' / 'false').
 *
 * UI: native <details>/<summary> — без JS, accessible из коробки.
 *
 * @since 1.7.0
 */
class Cashback_Faq_Shortcode {

    public const SHORTCODE_TAG = 'cashback_faq';

    public static function init(): void {
        if (!function_exists('add_shortcode')) {
            return;
        }
        add_shortcode(self::SHORTCODE_TAG, array( __CLASS__, 'render' ));
    }

    /**
     * Render шорткода.
     *
     * @param array<string, string>|string $atts
     */
    public static function render( $atts = array() ): string {
        if (!class_exists('Cashback_Faq_Content')) {
            return '';
        }

        $atts = shortcode_atts(
            array(
                'category'    => '',
                'open_first'  => 'false',
                'show_titles' => 'true',
            ),
            is_array($atts) ? $atts : array(),
            self::SHORTCODE_TAG
        );

        $category_csv  = trim((string) $atts['category']);
        $open_first    = self::parse_bool((string) $atts['open_first']);
        $show_titles   = self::parse_bool((string) $atts['show_titles']);

        $categories = Cashback_Faq_Content::get_categories();

        if ($category_csv !== '') {
            $requested  = self::parse_categories($category_csv);
            $categories = array_intersect_key($categories, array_flip($requested));
        }

        if (empty($categories)) {
            return '';
        }

        $is_first = true;
        $lines    = array();
        $lines[]  = '<div class="cashback-faq">';

        foreach ($categories as $slug => $category) {
            $lines[] = sprintf(
                '<section class="cashback-faq__category" data-category="%s">',
                esc_attr((string) $slug)
            );

            if ($show_titles) {
                $lines[] = '<h2 class="cashback-faq__category-title">'
                    . esc_html((string) $category['title'])
                    . '</h2>';
            }

            foreach ($category['items'] as $item) {
                $open_attr = ( $open_first && $is_first ) ? ' open' : '';
                $is_first  = false;

                $lines[] = '<details class="cashback-faq__item"' . $open_attr . '>';
                $lines[] = '<summary class="cashback-faq__question">'
                    . esc_html((string) $item['q'])
                    . '</summary>';
                $lines[] = '<div class="cashback-faq__answer">'
                    . wp_kses_post((string) $item['a'])
                    . '</div>';
                $lines[] = '</details>';
            }

            $lines[] = '</section>';
        }

        $lines[] = '</div>';

        return implode("\n", $lines);
    }

    /**
     * Парсинг CSV-списка slug'ов в массив с фильтрацией невалидных значений.
     *
     * @return array<int, string>
     */
    private static function parse_categories( string $csv ): array {
        $csv = trim($csv);
        if ($csv === '') {
            return array();
        }
        $valid = Cashback_Faq_Content::category_slugs();
        $parts = array_map(
            static fn( string $part ): string => sanitize_key(trim($part)),
            explode(',', $csv)
        );
        $parts = array_filter(
            $parts,
            static fn( string $slug ): bool => $slug !== '' && in_array($slug, $valid, true)
        );
        return array_values(array_unique($parts));
    }

    private static function parse_bool( string $value ): bool {
        $value = strtolower(trim($value));
        return in_array($value, array( 'true', '1', 'yes', 'on' ), true);
    }
}
