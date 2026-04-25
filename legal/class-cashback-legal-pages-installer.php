<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Pages_Installer
 *
 * Создаёт публичные WP-страницы для каждого юридического документа на activation
 * и при первом запуске плагина (idempotent). Сохраняет page_id → consent_type в
 * опции cashback_legal_pages_map. Дополнительно set'ит wp_page_for_privacy_policy
 * для интеграции с WP-нативным механизмом приватности.
 *
 * Контент страницы — шорткод [cashback_legal_doc type="..."], который при render'е
 * подставляет актуальный шаблон с реквизитами оператора. Это решает проблему
 * versioning: текст всегда отражает текущую версию из cashback_legal_consent_versions.
 *
 * @since 1.3.0
 */
class Cashback_Legal_Pages_Installer {

    public const PAGES_MAP_OPTION = 'cashback_legal_pages_map';
    public const INSTALL_FLAG_OPTION = 'cashback_legal_pages_installed_v1';

    /**
     * Регистрация хука. Вызывается из Cashback_Legal_Bootstrap.
     */
    public static function init(): void {
        add_action('admin_init', array( __CLASS__, 'maybe_install' ));
    }

    /**
     * Idempotent: если страницы уже созданы (флаг + page существует) — noop.
     * Если запись map есть, но page удалена — пересоздаст.
     */
    public static function maybe_install(): void {
        if (!is_admin() || !function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }
        $installed = (bool) get_option(self::INSTALL_FLAG_OPTION, false);
        if ($installed) {
            // Проверяем что страницы реально существуют — иначе пересоздаём пропавшие.
            $missing = self::detect_missing_pages();
            if (empty($missing)) {
                return;
            }
            self::install($missing);
            return;
        }

        self::install();
        update_option(self::INSTALL_FLAG_OPTION, true, false);
    }

    /**
     * Создание страниц для всех типов документов (или только указанных).
     *
     * @param array<int, string> $only_types Если задан — создаются только эти типы.
     */
    public static function install( array $only_types = array() ): void {
        if (!class_exists('Cashback_Legal_Documents')) {
            return;
        }

        $map = get_option(self::PAGES_MAP_OPTION, array());
        if (!is_array($map)) {
            $map = array();
        }

        $types = empty($only_types) ? Cashback_Legal_Documents::all_types() : $only_types;

        foreach ($types as $type) {
            $meta = Cashback_Legal_Documents::get_meta($type);
            if (empty($meta)) {
                continue;
            }
            // Пропускаем contact_form_pd — это inline-чекбокс на форме обратной
            // связи, отдельной публичной страницы для него не делаем.
            if ($type === Cashback_Legal_Documents::TYPE_CONTACT_FORM_PD) {
                continue;
            }

            $existing_id = isset($map[ $type ]) ? (int) $map[ $type ] : 0;
            if ($existing_id > 0 && get_post_status($existing_id) !== false) {
                // Страница есть — обновим только meta (slug может быть переименован вручную).
                continue;
            }

            $page_id = self::create_page_for_type($type, $meta);
            if ($page_id > 0) {
                $map[ $type ] = $page_id;
            }
        }

        update_option(self::PAGES_MAP_OPTION, $map, false);

        // Устанавливаем pd_policy как WP privacy policy page (если ещё не задана).
        if (!empty($map[ Cashback_Legal_Documents::TYPE_PD_POLICY ])) {
            $current = (int) get_option('wp_page_for_privacy_policy', 0);
            if ($current <= 0 || get_post_status($current) === false) {
                update_option('wp_page_for_privacy_policy', (int) $map[ Cashback_Legal_Documents::TYPE_PD_POLICY ]);
            }
        }
    }

    /**
     * Возвращает список типов, для которых page_id отсутствует или указывает
     * на удалённую страницу.
     *
     * @return array<int, string>
     */
    public static function detect_missing_pages(): array {
        if (!class_exists('Cashback_Legal_Documents')) {
            return array();
        }
        $map = get_option(self::PAGES_MAP_OPTION, array());
        if (!is_array($map)) {
            $map = array();
        }
        $missing = array();
        foreach (Cashback_Legal_Documents::all_types() as $type) {
            if ($type === Cashback_Legal_Documents::TYPE_CONTACT_FORM_PD) {
                continue;
            }
            $page_id = isset($map[ $type ]) ? (int) $map[ $type ] : 0;
            if ($page_id <= 0 || get_post_status($page_id) === false) {
                $missing[] = $type;
            }
        }
        return $missing;
    }

    /**
     * Получить URL страницы документа.
     */
    public static function get_url_for_type( string $type ): string {
        $map = get_option(self::PAGES_MAP_OPTION, array());
        if (!is_array($map) || empty($map[ $type ])) {
            return '';
        }
        $url = function_exists('get_permalink') ? get_permalink((int) $map[ $type ]) : '';
        return is_string($url) ? $url : '';
    }

    /**
     * Создаёт WP-page для конкретного типа.
     *
     * @param array<string, string|bool> $meta
     */
    private static function create_page_for_type( string $type, array $meta ): int {
        if (!function_exists('wp_insert_post')) {
            return 0;
        }
        $slug    = isset($meta['slug']) ? (string) $meta['slug'] : sanitize_key($type);
        $title   = isset($meta['title']) ? (string) $meta['title'] : 'Юридический документ';
        $content = '[cashback_legal_doc type="' . $type . '"]';

        $post_id = wp_insert_post(array(
            'post_title'     => $title,
            'post_name'      => $slug,
            'post_content'   => $content,
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
            'meta_input'     => array(
                '_cashback_legal_type' => $type,
            ),
        ), true);

        if (is_wp_error($post_id)) {
            return 0;
        }
        return (int) $post_id;
    }
}
