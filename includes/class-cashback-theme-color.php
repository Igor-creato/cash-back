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
     * Цепочка источников:
     * 1) woodmart_get_opt('primary-color') — глобальная функция темы Woodmart;
     * 2) get_option('xts-woodmart-options')['primary-color'] — прямое чтение опции
     *    темы (на случай ранних хуков, когда функция темы ещё не подгружена);
     * 3) get_theme_mod('primary-color') — для тем, использующих стандартный
     *    Customizer API;
     * 4) fallback #2271b1.
     */
    public static function get_brand_color(): string {
        $candidates = array();

        if (function_exists('woodmart_get_opt')) {
            $candidates[] = woodmart_get_opt('primary-color');
        }

        $woodmart_options = get_option('xts-woodmart-options');
        if (is_array($woodmart_options) && isset($woodmart_options['primary-color'])) {
            $candidates[] = $woodmart_options['primary-color'];
        }

        $candidates[] = get_theme_mod('primary-color');

        foreach ($candidates as $candidate) {
            $hex = self::normalize_color_value($candidate);
            if ($hex !== null) {
                return $hex;
            }
        }

        return '#2271b1';
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

    /**
     * Приводит произвольное значение цвета к строке вида #RRGGBB.
     *
     * Поддерживает формат Woodmart (массив с ключом 'idle') и обычные строки.
     * Возвращает null, если значение не может быть нормализовано.
     *
     * @param mixed $value
     */
    private static function normalize_color_value( $value ): ?string {
        if (is_array($value)) {
            $value = $value['idle'] ?? '';
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        $hex = sanitize_hex_color($value);
        if ($hex) {
            return $hex;
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return $value;
        }

        return null;
    }
}
