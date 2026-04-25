<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Legal_Admin
 *
 * Регистрирует подстраницы под Cashback → Юр. документы:
 *   - «Реквизиты»  (Phase 2): форма ввода реквизитов оператора ПД.
 *   - «Документы и версии» (Phase 5): bump major-версии.
 *   - «Журнал согласий» (Phase 5): фильтры + пагинация.
 *
 * Phase 2 реализует только «Реквизиты». Остальные страницы добавляются позже.
 *
 * @since 1.3.0
 */
class Cashback_Legal_Admin {

    public const PAGE_SLUG_OPERATOR = 'cashback-legal-operator';

    public const ACTION_SAVE_OPERATOR = 'cashback_legal_save_operator';

    public const NONCE_SAVE_OPERATOR = 'cashback_legal_save_operator_nonce';

    public static function init(): void {
        if (!is_admin()) {
            return;
        }
        add_action('admin_menu', array( __CLASS__, 'register_menu' ), 30);
        add_action('admin_post_' . self::ACTION_SAVE_OPERATOR, array( __CLASS__, 'handle_save_operator' ));
        add_action('admin_notices', array( __CLASS__, 'admin_notice_not_configured' ));
    }

    public static function register_menu(): void {
        add_submenu_page(
            'cashback-overview',
            __('Юр. документы', 'cashback-plugin'),
            __('Юр. документы', 'cashback-plugin'),
            'manage_options',
            self::PAGE_SLUG_OPERATOR,
            array( __CLASS__, 'render_operator_page' )
        );
    }

    /**
     * Глобальное admin-уведомление о незаполненных реквизитах оператора.
     * Скрывается на самой странице ввода реквизитов (там есть свой блок).
     */
    public static function admin_notice_not_configured(): void {
        if (!current_user_can('manage_options') || !class_exists('Cashback_Legal_Operator')) {
            return;
        }
        if (Cashback_Legal_Operator::is_configured()) {
            return;
        }
        $screen   = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_page  = $screen && isset($screen->id) && strpos((string) $screen->id, self::PAGE_SLUG_OPERATOR) !== false;
        if ($on_page) {
            return;
        }
        $url = admin_url('admin.php?page=' . self::PAGE_SLUG_OPERATOR);
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a>.</p></div>',
            esc_html__('Cashback:', 'cashback-plugin'),
            esc_html__('Реквизиты оператора персональных данных не заполнены. Юридические документы публикуются с видимыми плейсхолдерами и не считаются юридически валидными до заполнения.', 'cashback-plugin'),
            esc_url($url),
            esc_html__('Заполнить реквизиты', 'cashback-plugin')
        );
    }

    /**
     * POST-обработчик сохранения реквизитов. Защита: capability + nonce + idempotency.
     */
    public static function handle_save_operator(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'), '', array( 'response' => 403 ));
        }

        check_admin_referer(self::NONCE_SAVE_OPERATOR);

        // Idempotency: повторный submit с тем же request_id игнорируется.
        // Используем существующий Cashback_Idempotency helper (Group 5 ADR).
        if (class_exists('Cashback_Idempotency')) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен через check_admin_referer выше.
            $request_id = isset($_POST['cashback_legal_request_id'])
                ? sanitize_text_field(wp_unslash((string) $_POST['cashback_legal_request_id']))
                : '';
            $request_id = Cashback_Idempotency::normalize_request_id($request_id);
            if ($request_id !== '') {
                $claimed = Cashback_Idempotency::claim('legal_save_operator', $request_id, 300);
                if (!$claimed) {
                    self::redirect_to_operator_page('replay');
                    return;
                }
            }
        }

        $fields = array();
        foreach (Cashback_Legal_Operator::all_fields() as $field) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce проверен через check_admin_referer; sanitize выполняется в Cashback_Legal_Operator::set_all().
            $value             = isset($_POST[ $field ]) ? wp_unslash((string) $_POST[ $field ]) : '';
            $fields[ $field ] = $value;
        }

        Cashback_Legal_Operator::set_all($fields);

        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'legal_operator_updated',
                get_current_user_id(),
                'legal_operator',
                null,
                array(
                    'configured_after' => Cashback_Legal_Operator::is_configured(),
                    'missing'          => Cashback_Legal_Operator::get_missing_required_fields(),
                )
            );
        }

        self::redirect_to_operator_page('saved');
    }

    /**
     * Render формы реквизитов.
     */
    public static function render_operator_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'), '', array( 'response' => 403 ));
        }

        $data        = Cashback_Legal_Operator::get_all();
        $is_ready    = Cashback_Legal_Operator::is_configured();
        $missing     = Cashback_Legal_Operator::get_missing_required_fields();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash через query var; нет state-изменений.
        $flash       = isset($_GET['cashback_legal_flash']) ? sanitize_key((string) $_GET['cashback_legal_flash']) : '';

        $request_id = function_exists('cashback_generate_uuid7')
            ? cashback_generate_uuid7(false)
            : bin2hex(random_bytes(16));

        ?>
        <div class="wrap cashback-legal-admin">
            <h1><?php esc_html_e('Реквизиты оператора персональных данных', 'cashback-plugin'); ?></h1>

            <div class="cashback-legal-warning notice notice-warning" style="border-left-width:4px;">
                <p>
                    <strong><?php esc_html_e('Шаблоны юридических документов требуют утверждения юристом.', 'cashback-plugin'); ?></strong>
                    <?php esc_html_e('Каркасы документов сгенерированы по требованиям 152-ФЗ, 38-ФЗ, 161-ФЗ, 149-ФЗ, ГК ст. 437 на 25.04.2026, но финальная редакция должна быть проверена и адаптирована профильным юристом до публикации.', 'cashback-plugin'); ?>
                </p>
                <p>
                    <?php esc_html_e('Также не забудьте подать уведомление об операторе персональных данных в Роскомнадзор:', 'cashback-plugin'); ?>
                    <a href="https://pd.rkn.gov.ru/operators-registry/notification/" target="_blank" rel="noopener noreferrer">pd.rkn.gov.ru</a>.
                </p>
            </div>

            <?php if ($flash === 'saved') : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Реквизиты сохранены.', 'cashback-plugin'); ?></p></div>
            <?php elseif ($flash === 'replay') : ?>
                <div class="notice notice-info is-dismissible"><p><?php esc_html_e('Повторная отправка формы проигнорирована.', 'cashback-plugin'); ?></p></div>
            <?php endif; ?>

            <?php if (!$is_ready) : ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php esc_html_e('Не заполнены обязательные поля:', 'cashback-plugin'); ?></strong>
                        <?php echo esc_html(implode(', ', $missing)); ?>.
                        <?php esc_html_e('Без них сгенерированные документы содержат видимые плейсхолдеры {{...}} и не являются юридически валидными.', 'cashback-plugin'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cashback-legal-form">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SAVE_OPERATOR); ?>" />
                <input type="hidden" name="cashback_legal_request_id" value="<?php echo esc_attr((string) $request_id); ?>" />
                <?php wp_nonce_field(self::NONCE_SAVE_OPERATOR); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <?php echo self::render_form_rows($data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран через esc_attr/esc_html в render_form_rows. ?>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Сохранить реквизиты', 'cashback-plugin'); ?>
                    </button>
                </p>
            </form>

            <h2><?php esc_html_e('Публичные страницы документов', 'cashback-plugin'); ?></h2>
            <p><?php esc_html_e('Страницы создаются автоматически. Контент управляется шорткодом — текст всегда отражает актуальную версию шаблона.', 'cashback-plugin'); ?></p>
            <ul>
                <?php echo self::render_pages_list(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран через esc_html/esc_url в render_pages_list. ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Сборка HTML для строк формы (вынесена из render_operator_page для соблюдения
     * Squiz.PHP.EmbeddedPhp правил и тестируемости).
     *
     * @param array<string, string> $data
     */
    private static function render_form_rows( array $data ): string {
        $out = '';
        foreach (self::field_definitions() as $field => $def) {
            $value       = isset($data[ $field ]) ? (string) $data[ $field ] : '';
            $is_textarea = !empty($def['textarea']);
            $is_required = in_array($field, Cashback_Legal_Operator::required_fields(), true);
            $required_attr = $is_required ? ' required' : '';
            $required_mark = $is_required ? '<span style="color:#b32d2e;"> *</span>' : '';
            $label_html    = esc_html((string) $def['label']);
            $field_attr    = esc_attr($field);

            $input_html = $is_textarea
                ? sprintf(
                    '<textarea id="cashback-legal-%1$s" name="%1$s" rows="3" class="large-text"%2$s>%3$s</textarea>',
                    $field_attr,
                    $required_attr,
                    esc_textarea($value)
                )
                : sprintf(
                    '<input type="text" id="cashback-legal-%1$s" name="%1$s" value="%2$s" class="regular-text"%3$s />',
                    $field_attr,
                    esc_attr($value),
                    $required_attr
                );

            $help_html = !empty($def['help'])
                ? '<p class="description">' . esc_html((string) $def['help']) . '</p>'
                : '';

            $out .= '<tr>'
                . '<th scope="row"><label for="cashback-legal-' . $field_attr . '">' . $label_html . $required_mark . '</label></th>'
                . '<td>' . $input_html . $help_html . '</td>'
                . '</tr>';
        }
        return $out;
    }

    /**
     * Список страниц документов с ссылками или статусом «не создана».
     */
    private static function render_pages_list(): string {
        $out = '';
        foreach (Cashback_Legal_Documents::all_types() as $type) {
            if ($type === Cashback_Legal_Documents::TYPE_CONTACT_FORM_PD) {
                continue;
            }
            $url   = Cashback_Legal_Pages_Installer::get_url_for_type($type);
            $meta  = Cashback_Legal_Documents::get_meta($type);
            $title = isset($meta['title']) ? (string) $meta['title'] : $type;

            if ($url === '') {
                $out .= '<li>' . esc_html($title) . ' — <em>'
                    . esc_html__('страница не создана', 'cashback-plugin')
                    . '</em></li>';
            } else {
                $version = Cashback_Legal_Documents::get_active_version($type);
                $out    .= sprintf(
                    '<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a> · %s</li>',
                    esc_url($url),
                    esc_html($title),
                    esc_html(__('версия', 'cashback-plugin') . ' ' . $version)
                );
            }
        }
        return $out;
    }

    /**
     * Метаданные полей формы (label / help / textarea).
     *
     * @return array<string, array<string, string|bool>>
     */
    private static function field_definitions(): array {
        return array(
            'full_name'           => array(
                'label' => __('Полное наименование', 'cashback-plugin'),
                'help'  => __('Например: Общество с ограниченной ответственностью «Кэшбэк». Для ИП — Индивидуальный предприниматель Иванов Иван Иванович.', 'cashback-plugin'),
            ),
            'short_name'          => array(
                'label' => __('Краткое наименование', 'cashback-plugin'),
                'help'  => __('Используется в footer и © . Если пусто — берётся полное наименование.', 'cashback-plugin'),
            ),
            'org_form'            => array(
                'label' => __('Организационно-правовая форма', 'cashback-plugin'),
                'help'  => __('ЮЛ / ИП / Самозанятый.', 'cashback-plugin'),
            ),
            'ogrn'                => array(
                'label' => __('ОГРН/ОГРНИП', 'cashback-plugin'),
            ),
            'inn'                 => array(
                'label' => __('ИНН', 'cashback-plugin'),
            ),
            'kpp'                 => array(
                'label' => __('КПП', 'cashback-plugin'),
                'help'  => __('Только для юридических лиц.', 'cashback-plugin'),
            ),
            'legal_address'       => array(
                'label'    => __('Юридический адрес', 'cashback-plugin'),
                'textarea' => true,
            ),
            'postal_address'      => array(
                'label'    => __('Почтовый адрес', 'cashback-plugin'),
                'textarea' => true,
                'help'     => __('Если совпадает с юридическим — оставьте пустым.', 'cashback-plugin'),
            ),
            'contact_email'       => array(
                'label' => __('Контактный e-mail', 'cashback-plugin'),
            ),
            'contact_phone'       => array(
                'label' => __('Контактный телефон', 'cashback-plugin'),
            ),
            'dpo_name'            => array(
                'label' => __('Ответственный за обработку ПД (ФИО)', 'cashback-plugin'),
                'help'  => __('152-ФЗ ст. 22.1. Если не указано — ответственным считается оператор.', 'cashback-plugin'),
            ),
            'dpo_email'           => array(
                'label' => __('E-mail для запросов субъектов ПД', 'cashback-plugin'),
                'help'  => __('Если не указано — используется контактный e-mail.', 'cashback-plugin'),
            ),
            'rkn_registration_id' => array(
                'label' => __('Регистрационный номер в реестре операторов ПД РКН', 'cashback-plugin'),
                'help'  => __('Заполняется после подачи уведомления через pd.rkn.gov.ru.', 'cashback-plugin'),
            ),
        );
    }

    private static function redirect_to_operator_page( string $flash ): void {
        $url = admin_url('admin.php?page=' . self::PAGE_SLUG_OPERATOR . '&cashback_legal_flash=' . $flash);
        wp_safe_redirect($url);
        exit;
    }
}
