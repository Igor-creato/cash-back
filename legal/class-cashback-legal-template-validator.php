<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Template_Validator
 *
 * Валидация и санитизация HTML-тела юр.документа перед сохранением draft и
 * публикацией.
 *
 * - validate_for_publish: жёсткие проверки (size, emptiness, обязательные
 *   плейсхолдеры из PHP-template). Используется в publish_draft.
 * - sanitize_html: wp_kses-фильтр с safelist'ом тегов; placeholder'ы
 *   {{operator_*}} защищаются маркерами <!--CB_PH:x--> на время фильтрации
 *   и восстанавливаются после.
 *
 * @since 1.7.0
 */
class Cashback_Legal_Template_Validator {

    public const MAX_BODY_BYTES = 200000;
    public const MIN_BODY_BYTES = 1;

    /**
     * @return array<int, string>
     */
    public static function extract_placeholders( string $html ): array {
        if (preg_match_all('/\{\{([a-z0-9_\.]+)\}\}/i', $html, $m) === false || empty($m[1])) {
            return array();
        }
        return array_values(array_unique($m[1]));
    }

    /**
     * Whitelist HTML-тегов для legal документов. Никаких script/style/iframe/form.
     *
     * @return array<string, array<string, bool>>
     */
    public static function allowed_html(): array {
        $attrs_a = array(
            'href'   => true,
            'target' => true,
            'rel'    => true,
            'title'  => true,
        );
        $attrs_basic = array(
            'class' => true,
            'id'    => true,
        );
        return array(
            'p'          => $attrs_basic,
            'br'         => array(),
            'strong'     => $attrs_basic,
            'b'          => $attrs_basic,
            'em'         => $attrs_basic,
            'i'          => $attrs_basic,
            'h2'         => $attrs_basic,
            'h3'         => $attrs_basic,
            'h4'         => $attrs_basic,
            'ul'         => $attrs_basic,
            'ol'         => $attrs_basic,
            'li'         => $attrs_basic,
            'a'          => array_merge($attrs_a, $attrs_basic),
            'blockquote' => $attrs_basic,
            'table'      => $attrs_basic,
            'thead'      => $attrs_basic,
            'tbody'      => $attrs_basic,
            'tr'         => $attrs_basic,
            'th'         => $attrs_basic,
            'td'         => $attrs_basic,
            'code'       => $attrs_basic,
            'span'       => $attrs_basic,
            'div'        => $attrs_basic,
            'hr'         => array(),
        );
    }

    /**
     * @return true|WP_Error
     */
    public static function validate_for_publish( string $type, string $body ) {
        if (!self::is_known_type($type)) {
            return new WP_Error('unknown_type', 'Неизвестный тип документа: ' . $type);
        }

        $trimmed = trim($body);
        if ($trimmed === '') {
            return new WP_Error('body_empty', 'Текст документа не может быть пустым.');
        }

        $size = strlen($body);
        if ($size > self::MAX_BODY_BYTES) {
            return new WP_Error(
                'body_too_large',
                sprintf('Размер документа превышает лимит (%d байт > %d).', $size, self::MAX_BODY_BYTES)
            );
        }

        $missing = self::missing_required_placeholders($type, $body);
        if (!empty($missing)) {
            return new WP_Error(
                'placeholders_missing',
                'Удалены обязательные плейсхолдеры: ' . implode(', ', $missing),
                array( 'missing' => $missing )
            );
        }

        return true;
    }

    /**
     * Soft-валидация для save_draft: только size + non-empty.
     * Placeholder'ы пока не обязательны — админ может работать поэтапно.
     *
     * @return true|WP_Error
     */
    public static function validate_for_draft( string $type, string $body ) {
        if (!self::is_known_type($type)) {
            return new WP_Error('unknown_type', 'Неизвестный тип документа: ' . $type);
        }

        $size = strlen($body);
        if ($size > self::MAX_BODY_BYTES) {
            return new WP_Error(
                'body_too_large',
                sprintf('Размер draft превышает лимит (%d байт > %d).', $size, self::MAX_BODY_BYTES)
            );
        }

        return true;
    }

    /**
     * Sanitize HTML через wp_kses с защитой плейсхолдеров.
     *
     * Алгоритм:
     *   1. Все {{name}} замещаются маркером \x02CB_PH_<base16(name)>\x03 (не-HTML).
     *   2. wp_kses фильтрует через safelist.
     *   3. Маркеры возвращаются обратно в {{name}}.
     *
     * Зачем: wp_kses может посчитать `{{operator_x}}` за вложенный «тег» в
     * редких случаях, а атрибуты `data-something="{{operator_x}}"` точно
     * сломаются на привычной фильтрации. Round-trip гарантирует bit-for-bit
     * сохранение всех placeholder'ов.
     */
    public static function sanitize_html( string $body ): string {
        // Defense-in-depth: явно вырезаем самые опасные теги/атрибуты ДО wp_kses,
        // чтобы поведение не зависело от того, не подменили ли wp_kses в чужой среде.
        $body = (string) preg_replace('/<script\b[^>]*>.*?<\/script\s*>/is', '', $body);
        $body = (string) preg_replace('/<style\b[^>]*>.*?<\/style\s*>/is', '', $body);
        $body = (string) preg_replace('/<iframe\b[^>]*>.*?<\/iframe\s*>/is', '', $body);
        $body = (string) preg_replace('/<object\b[^>]*>.*?<\/object\s*>/is', '', $body);
        $body = (string) preg_replace('/<embed\b[^>]*\/?\s*>/is', '', $body);
        $body = (string) preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $body);
        // Вырезаем javascript:/data:/vbscript: в href.
        $body = (string) preg_replace('/(href\s*=\s*["\']?)\s*(javascript|vbscript|data)\s*:/i', '$1#blocked-', $body);

        $placeholders = array();
        $protected    = preg_replace_callback(
            '/\{\{([a-z0-9_\.]+)\}\}/i',
            function ( array $m ) use ( &$placeholders ): string {
                $key                  = bin2hex($m[1]);
                $placeholders[ $key ] = $m[0];
                return "\x02CBPH_" . $key . "\x03";
            },
            $body
        );
        if (!is_string($protected)) {
            $protected = $body;
        }

        if (function_exists('wp_kses')) {
            $cleaned = wp_kses($protected, self::allowed_html());
        } else {
            // Fallback для не-WP контекстов (CLI-инструменты): minimal strip.
            // Defense-in-depth pre-strip выше уже вырезал script/style/iframe/on*=,
            // так что этот путь безопасен.
            $allowed_tags_str = '<' . implode('><', array_keys(self::allowed_html())) . '>';
            // phpcs:ignore WordPressVIPMinimum.Functions.StripTags.StripTagsTwoParameters -- pre-strip выше убрал опасные теги; fallback только когда wp_kses недоступен.
            $cleaned = strip_tags($protected, $allowed_tags_str);
        }

        $restored = preg_replace_callback(
            '/\x02CBPH_([a-f0-9]+)\x03/',
            function ( array $m ) use ( $placeholders ): string {
                $orig = $placeholders[ $m[1] ] ?? '';
                if ($orig !== '') {
                    return $orig;
                }
                $hex = $m[1];
                if ((strlen($hex) % 2) !== 0 || preg_match('/^[a-f0-9]+$/i', $hex) !== 1) {
                    return '';
                }
                $bin = hex2bin($hex);
                return is_string($bin) ? '{{' . $bin . '}}' : '';
            },
            $cleaned
        );
        return is_string($restored) ? $restored : $cleaned;
    }

    /**
     * @return array<int, string>
     */
    public static function missing_required_placeholders( string $type, string $body ): array {
        $required = self::required_placeholders_for_type($type);
        $present  = self::extract_placeholders($body);
        return array_values(array_diff($required, $present));
    }

    /**
     * @return array<int, string>
     */
    public static function required_placeholders_for_type( string $type ): array {
        if (!class_exists('Cashback_Legal_Documents')) {
            return array();
        }
        // Baseline берём из PHP-шаблона — он source of truth для обязательных
        // плейсхолдеров. Если в новой редакции PHP-шаблона placeholder исчез,
        // он автоматически перестаёт быть обязательным.
        $php_body = self::raw_php_template($type);
        if ($php_body === '') {
            return array();
        }
        return self::extract_placeholders($php_body);
    }

    private static function is_known_type( string $type ): bool {
        if (!class_exists('Cashback_Legal_Documents')) {
            return false;
        }
        return in_array($type, Cashback_Legal_Documents::all_types(), true);
    }

    private static function raw_php_template( string $type ): string {
        $meta = Cashback_Legal_Documents::get_meta($type);
        if (empty($meta['template_path'])) {
            return '';
        }
        $plugin_root = dirname(__DIR__);
        $path        = $plugin_root . '/' . $meta['template_path'];
        if (!file_exists($path)) {
            return '';
        }
        $content = include $path;
        return is_string($content) ? $content : '';
    }
}
