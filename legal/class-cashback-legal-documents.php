<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Documents
 *
 * Реестр 6 юридических документов плагина (152-ФЗ, 38-ФЗ, 161-ФЗ, ГК ст. 437):
 *   - pd_policy         — Политика обработки ПД (152-ФЗ ст. 18.1, публичная)
 *   - pd_consent        — Согласие на обработку ПД (152-ФЗ ст. 9, чекбокс)
 *   - payment_pd        — Согласие на обработку платёжных данных (161-ФЗ, чекбокс)
 *   - terms_offer       — Пользовательское соглашение / публичная оферта (ГК ст. 437)
 *   - marketing         — Согласие на рекламные рассылки (38-ФЗ ст. 18, опц.)
 *   - cookies_policy    — Соглашение об использовании cookies
 *   - contact_form_pd   — короткое согласие на обработку ПД для гостевой контактной формы
 *   - tech_data         — Согласие на обработку технических данных (149-ФЗ ст. 10)
 *   - affiliate_program — Условия партнёрской (реферальной) программы (ГК ст. 437; opt-in)
 *
 * Версии хранятся в опции cashback_legal_consent_versions (JSON map: type → semver).
 * Текст шаблона — PHP-файл legal/templates/{slug}.php, return string с placeholder'ами.
 *
 * Hash документа = SHA-256 от конечного отрендеренного текста (после подстановки
 * placeholder'ов оператора). Записывается в журнал согласий вместе с granted_at —
 * это защита от ретроактивной правки текста (старая запись связана с актуальным
 * hash и невозможна попытка сослаться на текст, которого не было).
 *
 * @since 1.3.0
 */
class Cashback_Legal_Documents {

    public const TYPE_PD_POLICY       = 'pd_policy';
    public const TYPE_PD_CONSENT      = 'pd_consent';
    public const TYPE_PAYMENT_PD      = 'payment_pd';
    public const TYPE_TERMS_OFFER     = 'terms_offer';
    public const TYPE_MARKETING       = 'marketing';
    public const TYPE_COOKIES_POLICY  = 'cookies_policy';
    public const TYPE_CONTACT_FORM_PD = 'contact_form_pd';
    public const TYPE_TECH_DATA       = 'tech_data';
    public const TYPE_AFFILIATE_PROGRAM = 'affiliate_program';

    public const VERSIONS_OPTION = 'cashback_legal_consent_versions';

    public const DEFAULT_VERSION = '1.0.0';

    /**
     * Все известные типы (для валидации и итерации).
     *
     * @return array<int, string>
     */
    public static function all_types(): array {
        return array(
            self::TYPE_PD_POLICY,
            self::TYPE_PD_CONSENT,
            self::TYPE_PAYMENT_PD,
            self::TYPE_TERMS_OFFER,
            self::TYPE_MARKETING,
            self::TYPE_COOKIES_POLICY,
            self::TYPE_CONTACT_FORM_PD,
            self::TYPE_TECH_DATA,
            self::TYPE_AFFILIATE_PROGRAM,
        );
    }

    /**
     * Типы, требующие чекбокса (соглашение subject'а).
     * pd_policy — публичный документ-уведомление, не требует чекбокса.
     *
     * @return array<int, string>
     */
    public static function consent_types(): array {
        return array(
            self::TYPE_PD_CONSENT,
            self::TYPE_PAYMENT_PD,
            self::TYPE_TERMS_OFFER,
            self::TYPE_MARKETING,
            self::TYPE_COOKIES_POLICY,
            self::TYPE_CONTACT_FORM_PD,
            self::TYPE_TECH_DATA,
            self::TYPE_AFFILIATE_PROGRAM,
        );
    }

    /**
     * Метаданные документа: slug (для URL/файлов), title (для UI), template_path,
     * is_public (footer-ссылка), is_consent (требует чекбокса), is_required
     * (обязательный чекбокс при регистрации).
     *
     * @return array<string, string|bool>
     */
    public static function get_meta( string $type ): array {
        $map = array(
            self::TYPE_PD_POLICY => array(
                'slug'          => 'pd-policy',
                'title'         => 'Политика обработки персональных данных',
                'template_path' => 'legal/templates/pd-policy.php',
                'is_public'     => true,
                'is_consent'    => false,
                'is_required'   => false,
            ),
            self::TYPE_PD_CONSENT => array(
                'slug'          => 'pd-consent',
                'title'         => 'Согласие на обработку персональных данных',
                'template_path' => 'legal/templates/pd-consent.php',
                'is_public'     => true,
                'is_consent'    => true,
                'is_required'   => true,
            ),
            self::TYPE_PAYMENT_PD => array(
                'slug'          => 'payment-pd',
                'title'         => 'Согласие на обработку платёжных данных',
                'template_path' => 'legal/templates/payment-pd.php',
                'is_public'     => true,
                'is_consent'    => true,
                'is_required'   => true,
            ),
            self::TYPE_TERMS_OFFER => array(
                'slug'          => 'terms-offer',
                'title'         => 'Пользовательское соглашение (публичная оферта)',
                'template_path' => 'legal/templates/terms-offer.php',
                'is_public'     => true,
                'is_consent'    => true,
                'is_required'   => true,
            ),
            self::TYPE_MARKETING => array(
                'slug'          => 'marketing',
                'title'         => 'Согласие на получение рекламных рассылок',
                'template_path' => 'legal/templates/marketing.php',
                'is_public'     => true,
                'is_consent'    => true,
                'is_required'   => false,
            ),
            self::TYPE_COOKIES_POLICY => array(
                'slug'          => 'cookies-policy',
                'title'         => 'Соглашение об использовании cookies',
                'template_path' => 'legal/templates/cookies-policy.php',
                'is_public'     => true,
                'is_consent'    => true,
                'is_required'   => false,
            ),
            self::TYPE_CONTACT_FORM_PD => array(
                'slug'          => 'contact-form-pd',
                'title'         => 'Согласие на обработку ПД для обратной связи',
                'template_path' => 'legal/templates/contact-form-pd.php',
                'is_public'     => false,
                'is_consent'    => true,
                'is_required'   => true,
            ),
            self::TYPE_TECH_DATA => array(
                'slug'          => 'tech-data',
                'title'         => 'Согласие на обработку технических данных (149-ФЗ)',
                'template_path' => 'legal/templates/tech-data.php',
                'is_public'     => true,
                'is_consent'    => true,
                'is_required'   => false,
            ),
            self::TYPE_AFFILIATE_PROGRAM => array(
                'slug'          => 'affiliate-program',
                'title'         => 'Условия партнёрской (реферальной) программы',
                'template_path' => 'legal/templates/affiliate-program.php',
                'is_public'     => true,
                'is_consent'    => true,
                'is_required'   => false,
            ),
        );

        return $map[ $type ] ?? array();
    }

    /**
     * Активная версия документа (semver).
     */
    public static function get_active_version( string $type ): string {
        $versions = get_option(self::VERSIONS_OPTION, array());
        if (!is_array($versions)) {
            $versions = array();
        }
        $version = isset($versions[ $type ]) ? (string) $versions[ $type ] : '';
        return $version !== '' ? $version : self::DEFAULT_VERSION;
    }

    /**
     * Установить активную версию документа (для bump через admin UI).
     */
    public static function set_active_version( string $type, string $version ): void {
        if (!in_array($type, self::all_types(), true)) {
            return;
        }
        $versions = get_option(self::VERSIONS_OPTION, array());
        if (!is_array($versions)) {
            $versions = array();
        }
        $versions[ $type ] = $version;
        update_option(self::VERSIONS_OPTION, $versions, false);
    }

    /**
     * Заполнить версии по умолчанию (1.0.0) для всех типов, у которых ещё нет.
     * Вызывается на activation, идемпотентно.
     */
    public static function seed_versions(): void {
        $versions = get_option(self::VERSIONS_OPTION, array());
        if (!is_array($versions)) {
            $versions = array();
        }
        $changed = false;
        foreach (self::all_types() as $type) {
            if (empty($versions[ $type ])) {
                $versions[ $type ] = self::DEFAULT_VERSION;
                $changed           = true;
            }
        }
        if ($changed) {
            update_option(self::VERSIONS_OPTION, $versions, false);
        }
    }

    /**
     * Bump major-версии документа (1.0.0 → 2.0.0). Минорные правки не делают
     * re-consent — это сознательный бизнес-выбор: только смысловая правка.
     *
     * @return string Новая версия.
     */
    public static function bump_major( string $type ): string {
        $current = self::get_active_version($type);
        $parts   = explode('.', $current);
        $major   = isset($parts[0]) ? (int) $parts[0] : 1;
        $new     = ( $major + 1 ) . '.0.0';
        self::set_active_version($type, $new);
        return $new;
    }

    /**
     * Загрузить сырой текст шаблона (без render_placeholders).
     * Возвращает HTML с placeholder'ами {{operator_*}}.
     */
    public static function load_template( string $type ): string {
        // DB-first override: если в wp_cashback_legal_template_versions есть
        // строка status='published', она имеет приоритет над PHP-шаблоном
        // (админ редактировал текст через UI). PHP-файл остаётся seed/fallback
        // для свежих инсталляций до первого редактирования.
        if (class_exists('Cashback_Legal_Template_Storage')) {
            $db_body = Cashback_Legal_Template_Storage::get_active_body($type);
            if (is_string($db_body) && $db_body !== '') {
                return $db_body;
            }
        }

        $meta = self::get_meta($type);
        if (empty($meta['template_path'])) {
            return '';
        }

        $path = self::plugin_root_dir() . '/' . $meta['template_path'];
        if (!file_exists($path)) {
            return '';
        }

        // PHP-файл шаблона возвращает строку через `return '...';` без вывода.
        $content = include $path;
        return is_string($content) ? $content : '';
    }

    /**
     * Полный отрендеренный документ: шаблон + подстановка реквизитов оператора.
     *
     * При renderer'е плейсхолдеров missing-fields оставляются как `{{...}}` —
     * Cashback_Legal_Operator::is_configured() это контролирует на admin-side.
     */
    public static function get_rendered( string $type ): string {
        $raw = self::load_template($type);
        if ($raw === '') {
            return '';
        }
        if (class_exists('Cashback_Legal_Operator')) {
            return Cashback_Legal_Operator::render_placeholders($raw);
        }
        return $raw;
    }

    /**
     * SHA-256 хэш отрендеренного документа.
     * Используется в записи журнала как доказательство «версия+текст».
     */
    public static function compute_hash( string $type ): string {
        $rendered = self::get_rendered($type);
        if ($rendered === '') {
            return '';
        }
        return hash('sha256', $rendered);
    }

    /**
     * Корень плагина (для безопасного построения путей шаблонов).
     */
    private static function plugin_root_dir(): string {
        // legal/class-cashback-legal-documents.php → корень = ../
        return dirname(__DIR__);
    }
}
