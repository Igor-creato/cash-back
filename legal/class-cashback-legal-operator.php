<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Operator
 *
 * Wrapper над опцией cashback_legal_operator_data: реквизиты оператора ПД
 * (ст. 18.1 152-ФЗ — наименование, ОГРН, ИНН, юр. адрес, контакты), DPO,
 * номер регистрации в реестре операторов РКН.
 *
 * Эти данные подставляются в шаблоны 6 документов через render_placeholders().
 * До заполнения — шаблоны содержат видимые `{{operator_*}}` маркеры, и
 * Cashback_Legal_Operator::is_configured() возвращает false. Это позволяет
 * Footer-блоку и admin-уведомлениям предупреждать о незаполненных реквизитах.
 *
 * @since 1.3.0
 */
class Cashback_Legal_Operator {

    public const OPTION_KEY = 'cashback_legal_operator_data';

    /**
     * Поля, обязательные для is_configured() = true.
     *
     * @return array<int, string>
     */
    public static function required_fields(): array {
        return array(
            'full_name',
            'org_form',
            'ogrn',
            'inn',
            'legal_address',
            'contact_email',
        );
    }

    /**
     * Все известные поля (для admin-формы и render_placeholders).
     *
     * @return array<int, string>
     */
    public static function all_fields(): array {
        return array(
            'full_name',
            'short_name',
            'org_form',          // ЮЛ / ИП / самозанятый
            'ogrn',              // ОГРН (ЮЛ) или ОГРНИП (ИП)
            'inn',
            'kpp',               // только для ЮЛ
            'legal_address',
            'postal_address',
            'contact_email',
            'contact_phone',
            'dpo_name',          // ответственный за обработку ПД
            'dpo_email',
            'rkn_registration_id', // регистрационный номер в реестре операторов РКН
        );
    }

    /**
     * Получить все реквизиты как ассоциативный массив.
     *
     * @return array<string, string>
     */
    public static function get_all(): array {
        $data = get_option(self::OPTION_KEY, array());
        if (!is_array($data)) {
            $data = array();
        }
        $out = array();
        foreach (self::all_fields() as $field) {
            $out[ $field ] = isset($data[ $field ]) ? (string) $data[ $field ] : '';
        }
        return $out;
    }

    /**
     * Получить одно поле.
     */
    public static function get( string $field ): string {
        $all = self::get_all();
        return isset($all[ $field ]) ? $all[ $field ] : '';
    }

    /**
     * Сохранить все реквизиты (admin-only). Не валидируем форму ОГРН/ИНН
     * на этом уровне — это делает admin-form класс, чтобы вернуть UI-ошибки.
     *
     * @param array<string, string> $data
     */
    public static function set_all( array $data ): void {
        $sanitized = array();
        foreach (self::all_fields() as $field) {
            $value                = isset($data[ $field ]) ? (string) $data[ $field ] : '';
            $sanitized[ $field ] = self::sanitize_field($field, $value);
        }
        update_option(self::OPTION_KEY, $sanitized, false);
    }

    /**
     * Все обязательные поля заполнены — реквизиты можно показывать публично.
     */
    public static function is_configured(): bool {
        $data = self::get_all();
        foreach (self::required_fields() as $field) {
            if (!isset($data[ $field ]) || trim((string) $data[ $field ]) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Подстановка плейсхолдеров `{{operator_*}}` в HTML/текст.
     * Дополнительно: `{{site_url}}`, `{{site_name}}`, `{{document_version}}`,
     * `{{effective_date}}` — заполняются caller'ом через extra-mapping или
     * автоматически из WP-функций (site_url, get_bloginfo).
     *
     * Missing-fields НЕ заменяются — остаются как `{{operator_xxx}}` в тексте,
     * чтобы юрист/админ видели «ещё не заполнено».
     *
     * @param array<string, string> $extra Дополнительные replace'ы (override стандартных).
     */
    public static function render_placeholders( string $content, array $extra = array() ): string {
        if ($content === '') {
            return '';
        }

        $data = self::get_all();

        $replacements = array(
            '{{operator_full_name}}'           => $data['full_name'],
            '{{operator_short_name}}'          => $data['short_name'] !== '' ? $data['short_name'] : $data['full_name'],
            '{{operator_org_form}}'            => $data['org_form'],
            '{{operator_ogrn}}'                => $data['ogrn'],
            '{{operator_inn}}'                 => $data['inn'],
            '{{operator_kpp}}'                 => $data['kpp'],
            '{{operator_legal_address}}'       => $data['legal_address'],
            '{{operator_postal_address}}'      => $data['postal_address'] !== '' ? $data['postal_address'] : $data['legal_address'],
            '{{operator_contact_email}}'       => $data['contact_email'],
            '{{operator_contact_phone}}'       => $data['contact_phone'],
            '{{operator_dpo_name}}'            => $data['dpo_name'] !== '' ? $data['dpo_name'] : $data['full_name'],
            '{{operator_dpo_email}}'           => $data['dpo_email'] !== '' ? $data['dpo_email'] : $data['contact_email'],
            '{{operator_rkn_registration_id}}' => $data['rkn_registration_id'],
            '{{site_url}}'                     => function_exists('home_url') ? (string) home_url('/') : '',
            '{{site_name}}'                    => function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '',
            '{{current_year}}'                 => (string) gmdate('Y'),
        );

        foreach ($extra as $key => $value) {
            $replacements[ $key ] = (string) $value;
        }

        // Не заменяем пустые значения — оставляем `{{operator_*}}` видимыми,
        // чтобы было сразу очевидно «не заполнено».
        $non_empty = array();
        foreach ($replacements as $key => $value) {
            if ($value !== '') {
                $non_empty[ $key ] = $value;
            }
        }

        return strtr($content, $non_empty);
    }

    /**
     * Поля, отсутствующие в обязательных. Используется в admin-warning.
     *
     * @return array<int, string>
     */
    public static function get_missing_required_fields(): array {
        $data    = self::get_all();
        $missing = array();
        foreach (self::required_fields() as $field) {
            if (trim((string) $data[ $field ]) === '') {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /**
     * Базовая sanitize для каждого поля — без жёсткой валидации формата
     * (валидация в admin-form классе с обратной связью пользователю).
     */
    private static function sanitize_field( string $field, string $value ): string {
        $value = trim($value);
        switch ($field) {
            case 'contact_email':
            case 'dpo_email':
                $value = function_exists('sanitize_email') ? sanitize_email($value) : $value;
                break;
            case 'legal_address':
            case 'postal_address':
                $value = function_exists('sanitize_textarea_field') ? sanitize_textarea_field($value) : $value;
                break;
            default:
                $value = function_exists('sanitize_text_field') ? sanitize_text_field($value) : $value;
                break;
        }
        return $value;
    }
}
