<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Хелпер для получения цветов активной темы WordPress.
 *
 * Используется плагином Cashback в рассылке писем и в стилях личного кабинета,
 * чтобы оформление плагина согласовывалось с брендовыми цветами темы сайта.
 */
class Cashback_Theme_Color {

    /**
     * Основной брендовый цвет активной темы.
     *
     * Приоритет: primary-color активной темы (Woodmart хранит массив с ключом 'idle')
     * → fallback #2271b1.
     */
    public static function get_brand_color(): string {
        $fallback = '#2271b1';
        $primary  = get_theme_mod('primary-color');

        if (is_array($primary) && isset($primary['idle'])) {
            $primary = $primary['idle'];
        }

        if (!is_string($primary) || $primary === '') {
            return $fallback;
        }

        $hex = sanitize_hex_color($primary);
        if ($hex) {
            return $hex;
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) {
            return $primary;
        }

        return $fallback;
    }

    /**
     * Подбирает читаемый цвет текста (#ffffff или #1a1a1a) для фона $hex.
     * Используется формула luma: Y = 0.299R + 0.587G + 0.114B, порог 128.
     */
    public static function get_contrast_text_color( string $hex ): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return '#ffffff';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $y = 0.299 * $r + 0.587 * $g + 0.114 * $b;

        return $y >= 128 ? '#1a1a1a' : '#ffffff';
    }
}
