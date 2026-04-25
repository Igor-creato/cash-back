<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Shortcodes
 *
 * Шорткоды:
 *   - [cashback_legal_doc type="pd_policy"] — рендер документа на public page
 *   - [cashback_legal_operator] — карточка реквизитов оператора (149-ФЗ ст. 10)
 *   - [cashback_legal_footer] — компактный footer-блок: реквизиты + 6 ссылок
 *
 * Authorisation: все три шорткода рендерятся для всех (включая гостей);
 * чувствительных данных не содержат — только публичные реквизиты ЮЛ/ИП.
 *
 * @since 1.3.0
 */
class Cashback_Legal_Shortcodes {

    public static function init(): void {
        if (!function_exists('add_shortcode')) {
            return;
        }
        add_shortcode('cashback_legal_doc', array( __CLASS__, 'render_doc' ));
        add_shortcode('cashback_legal_operator', array( __CLASS__, 'render_operator' ));
        add_shortcode('cashback_legal_footer', array( __CLASS__, 'render_footer_block' ));
    }

    /**
     * [cashback_legal_doc type="pd_policy"] — рендер шаблона документа.
     *
     * @param array<string, string>|string $atts
     */
    public static function render_doc( $atts = array() ): string {
        if (!class_exists('Cashback_Legal_Documents')) {
            return '';
        }
        $atts = shortcode_atts(
            array(
                'type' => '',
            ),
            is_array($atts) ? $atts : array(),
            'cashback_legal_doc'
        );

        $type = (string) $atts['type'];
        if (!in_array($type, Cashback_Legal_Documents::all_types(), true)) {
            return '';
        }

        $version = Cashback_Legal_Documents::get_active_version($type);
        $rendered = Cashback_Legal_Documents::get_rendered($type);

        if ($rendered === '') {
            return '';
        }

        // effective_date в шаблоне — на момент render'а: либо явное поле в опциях
        // (для будущей админ-страницы версий), либо текущая дата.
        $effective_date = self::get_effective_date_for_type($type);
        $rendered       = Cashback_Legal_Operator::render_placeholders(
            $rendered,
            array(
                '{{document_version}}' => $version,
                '{{effective_date}}'   => $effective_date,
            )
        );

        $admin_warning = self::admin_warning_block($type);

        // wp_kses_post срезает невалидный HTML, оставляя нашу разметку.
        return $admin_warning . wp_kses_post($rendered);
    }

    /**
     * [cashback_legal_operator] — реквизиты оператора (для отдельной страницы
     * «Контакты» / «Реквизиты»).
     */
    public static function render_operator(): string {
        if (!class_exists('Cashback_Legal_Operator')) {
            return '';
        }
        if (!Cashback_Legal_Operator::is_configured()) {
            return self::not_configured_warning('operator');
        }

        $data = Cashback_Legal_Operator::get_all();

        $lines = array();
        $lines[] = '<div class="cashback-legal-operator">';
        $lines[] = '<dl class="cashback-legal-operator__list">';

        $rows = array(
            'Полное наименование'        => $data['full_name'],
            'Организационно-правовая форма' => $data['org_form'],
            'ОГРН/ОГРНИП'                => $data['ogrn'],
            'ИНН'                        => $data['inn'],
            'КПП'                        => $data['kpp'],
            'Юридический адрес'          => $data['legal_address'],
            'Почтовый адрес'             => $data['postal_address'] !== '' ? $data['postal_address'] : $data['legal_address'],
            'E-mail'                     => $data['contact_email'],
            'Телефон'                    => $data['contact_phone'],
        );
        if (!empty($data['rkn_registration_id'])) {
            $rows['Регистрационный номер в реестре операторов ПД РКН'] = $data['rkn_registration_id'];
        }

        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }
            $lines[] = '<dt>' . esc_html($label) . '</dt>';
            $lines[] = '<dd>' . esc_html((string) $value) . '</dd>';
        }
        $lines[] = '</dl>';
        $lines[] = '</div>';

        return implode("\n", $lines);
    }

    /**
     * [cashback_legal_footer] — компактный footer-блок: краткие реквизиты + ссылки.
     */
    public static function render_footer_block(): string {
        if (!class_exists('Cashback_Legal_Operator') || !class_exists('Cashback_Legal_Pages_Installer')) {
            return '';
        }

        $configured = Cashback_Legal_Operator::is_configured();
        if (!$configured) {
            // Для гостей — пусто. Для админа — заметная плашка-предупреждение.
            return self::not_configured_warning('footer');
        }

        $data = Cashback_Legal_Operator::get_all();

        $lines = array();
        $lines[] = '<div class="cashback-legal-footer">';

        // Краткие реквизиты (149-ФЗ ст. 10).
        $brief  = esc_html($data['full_name']);
        $brief .= ' · ' . esc_html(__('ОГРН', 'cashback-plugin')) . ' ' . esc_html($data['ogrn']);
        $brief .= ' · ' . esc_html(__('ИНН', 'cashback-plugin')) . ' ' . esc_html($data['inn']);
        if ($data['legal_address'] !== '') {
            $brief .= ' · ' . esc_html($data['legal_address']);
        }
        if ($data['contact_email'] !== '') {
            $brief .= ' · ' . esc_html($data['contact_email']);
        }
        $lines[] = '<p class="cashback-legal-footer__brief">' . $brief . '</p>';

        // Ссылки на 6 публичных документов.
        $link_types = array(
            Cashback_Legal_Documents::TYPE_PD_POLICY,
            Cashback_Legal_Documents::TYPE_PD_CONSENT,
            Cashback_Legal_Documents::TYPE_PAYMENT_PD,
            Cashback_Legal_Documents::TYPE_TERMS_OFFER,
            Cashback_Legal_Documents::TYPE_MARKETING,
            Cashback_Legal_Documents::TYPE_COOKIES_POLICY,
        );

        $link_pieces = array();
        foreach ($link_types as $type) {
            $url = Cashback_Legal_Pages_Installer::get_url_for_type($type);
            if ($url === '') {
                continue;
            }
            $meta = Cashback_Legal_Documents::get_meta($type);
            $title = isset($meta['title']) ? (string) $meta['title'] : $type;
            $link_pieces[] = sprintf(
                '<a href="%s" rel="nofollow">%s</a>',
                esc_url($url),
                esc_html($title)
            );
        }

        if (!empty($link_pieces)) {
            $lines[] = '<p class="cashback-legal-footer__links">' . implode(' · ', $link_pieces) . '</p>';
        }

        $lines[] = '<p class="cashback-legal-footer__copyright">© ' . esc_html(gmdate('Y')) . ' '
            . esc_html($data['short_name'] !== '' ? $data['short_name'] : $data['full_name'])
            . '</p>';
        $lines[] = '</div>';

        return implode("\n", $lines);
    }

    /**
     * Дата вступления в силу версии документа.
     * Phase 2 — текущая дата; Phase 5 (admin Bump UI) сохранит дату bump'а в опции.
     */
    private static function get_effective_date_for_type( string $type ): string {
        $stored = get_option('cashback_legal_effective_dates', array());
        if (is_array($stored) && !empty($stored[ $type ])) {
            return (string) $stored[ $type ];
        }
        return gmdate('Y-m-d');
    }

    /**
     * Плашка над публичным документом, видимая только админам, с предупреждением
     * о необходимости утверждения юристом.
     */
    private static function admin_warning_block( string $type ): string {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return '';
        }
        return '<div class="cashback-legal-admin-warning" style="background:#fff3cd;border-left:4px solid #f0a500;padding:12px 16px;margin:0 0 20px;font-size:13px;">'
            . esc_html__('⚠ Шаблон документа сгенерирован автоматически и должен быть утверждён юристом до публикации. Текст видим только администраторам как напоминание.', 'cashback-plugin')
            . ' <code>' . esc_html($type) . '</code>'
            . '</div>';
    }

    /**
     * Видимое только администраторам предупреждение «реквизиты не настроены».
     */
    private static function not_configured_warning( string $context ): string {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return '';
        }
        return '<div class="cashback-legal-not-configured" style="background:#f8d7da;border:1px solid #f5c2c7;color:#842029;padding:12px 16px;margin:8px 0;font-size:13px;">'
            . esc_html__('⚠ Реквизиты оператора не заполнены. Перейдите в Cashback → Юр. документы → Реквизиты.', 'cashback-plugin')
            . ' (' . esc_html($context) . ')'
            . '</div>';
    }
}
