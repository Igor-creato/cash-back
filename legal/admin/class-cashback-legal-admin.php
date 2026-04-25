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
    public const PAGE_SLUG_VERSIONS = 'cashback-legal-versions';
    public const PAGE_SLUG_JOURNAL  = 'cashback-legal-journal';
    public const PAGE_SLUG_AUDIT    = 'cashback-legal-audit';

    public const ACTION_SAVE_OPERATOR = 'cashback_legal_save_operator';
    public const ACTION_BUMP_VERSION  = 'cashback_legal_bump_version';
    public const ACTION_BUMP_BATCH    = 'cashback_legal_bump_batch';

    public const NONCE_SAVE_OPERATOR = 'cashback_legal_save_operator_nonce';
    public const NONCE_BUMP_VERSION  = 'cashback_legal_bump_version_nonce';

    public static function init(): void {
        if (!is_admin()) {
            return;
        }
        add_action('admin_menu', array( __CLASS__, 'register_menu' ), 30);
        add_action('admin_post_' . self::ACTION_SAVE_OPERATOR, array( __CLASS__, 'handle_save_operator' ));
        add_action('admin_post_' . self::ACTION_BUMP_VERSION, array( __CLASS__, 'handle_bump_version' ));
        add_action(self::ACTION_BUMP_BATCH, array( __CLASS__, 'run_bump_batch' ), 10, 2);
        add_action('admin_notices', array( __CLASS__, 'admin_notice_not_configured' ));
        add_action('admin_notices', array( __CLASS__, 'admin_notice_third_party' ));
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
        add_submenu_page(
            'cashback-overview',
            __('Документы и версии', 'cashback-plugin'),
            __('Документы и версии', 'cashback-plugin'),
            'manage_options',
            self::PAGE_SLUG_VERSIONS,
            array( __CLASS__, 'render_versions_page' )
        );
        add_submenu_page(
            'cashback-overview',
            __('Журнал согласий', 'cashback-plugin'),
            __('Журнал согласий', 'cashback-plugin'),
            'manage_options',
            self::PAGE_SLUG_JOURNAL,
            array( __CLASS__, 'render_journal_page' )
        );
        add_submenu_page(
            'cashback-overview',
            __('Аудит сторонних форм', 'cashback-plugin'),
            __('Аудит сторонних форм', 'cashback-plugin'),
            'manage_options',
            self::PAGE_SLUG_AUDIT,
            array( __CLASS__, 'render_audit_page' )
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

    // ────────────────────────────────────────────────────────────
    // Phase 5: Версии документов + bump major
    // ────────────────────────────────────────────────────────────

    public static function render_versions_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'), '', array( 'response' => 403 ));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash через query var.
        $flash = isset($_GET['cashback_legal_flash']) ? sanitize_key((string) $_GET['cashback_legal_flash']) : '';

        ?>
        <div class="wrap cashback-legal-admin">
            <h1><?php esc_html_e('Документы и версии', 'cashback-plugin'); ?></h1>

            <div class="cashback-legal-warning notice notice-warning" style="border-left-width:4px;">
                <p>
                    <strong><?php esc_html_e('Bump major-версии — необратимое действие.', 'cashback-plugin'); ?></strong>
                    <?php esc_html_e('Все ранее данные согласия по этому документу будут помечены как superseded. При следующем входе пользователю покажется модал с обновлёнными чекбоксами; до акцепта доступ ограничен личным кабинетом и логаутом.', 'cashback-plugin'); ?>
                </p>
                <p><?php esc_html_e('Делайте bump только после фактической правки текста шаблона и согласования с юристом.', 'cashback-plugin'); ?></p>
            </div>

            <?php if ($flash === 'bumped') : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Версия увеличена. Запущена фоновая задача superseded для всех ранее согласовавших.', 'cashback-plugin'); ?></p></div>
            <?php elseif ($flash === 'invalid_type') : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Неизвестный тип документа.', 'cashback-plugin'); ?></p></div>
            <?php endif; ?>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Документ', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Текущая версия', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Hash', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Действия', 'cashback-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    echo self::render_versions_rows(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран через esc_attr/esc_html/esc_url в render_versions_rows.
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Сборка строк таблицы versions (вынесено для соблюдения Squiz.PHP.EmbeddedPhp).
     */
    private static function render_versions_rows(): string {
        $out = '';
        foreach (Cashback_Legal_Documents::all_types() as $type) {
            $meta       = Cashback_Legal_Documents::get_meta($type);
            $title      = isset($meta['title']) ? (string) $meta['title'] : $type;
            $version    = Cashback_Legal_Documents::get_active_version($type);
            $hash       = Cashback_Legal_Documents::compute_hash($type);
            $hash_short = $hash !== '' ? substr($hash, 0, 12) . '…' : '—';

            $confirm_msg = esc_js(__('Bump major действительно нужен? Все ранее согласовавшие пройдут re-consent.', 'cashback-plugin'));

            $out .= '<tr>'
                . '<td><strong>' . esc_html($title) . '</strong><br /><code>' . esc_html($type) . '</code></td>'
                . '<td><code>' . esc_html($version) . '</code></td>'
                . '<td><code title="' . esc_attr($hash) . '">' . esc_html($hash_short) . '</code></td>'
                . '<td>'
                . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" '
                . 'onsubmit="return confirm(\'' . $confirm_msg . '\');">'
                . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_BUMP_VERSION) . '" />'
                . '<input type="hidden" name="consent_type" value="' . esc_attr($type) . '" />'
                . wp_nonce_field(self::NONCE_BUMP_VERSION, '_wpnonce', true, false)
                . '<button type="submit" class="button button-secondary">'
                . esc_html__('Bump major →', 'cashback-plugin')
                . '</button>'
                . '</form>'
                . '</td>'
                . '</tr>';
        }
        return $out;
    }

    /**
     * POST-handler bump major-версии. Increments active version + ставит
     * async-задачу superseded через wp_schedule_single_event.
     */
    public static function handle_bump_version(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'), '', array( 'response' => 403 ));
        }
        check_admin_referer(self::NONCE_BUMP_VERSION);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer выше проверяет nonce.
        $type = isset($_POST['consent_type']) ? sanitize_key((string) $_POST['consent_type']) : '';

        if (!in_array($type, Cashback_Legal_Documents::all_types(), true)) {
            $url = admin_url('admin.php?page=' . self::PAGE_SLUG_VERSIONS . '&cashback_legal_flash=invalid_type');
            wp_safe_redirect($url);
            exit;
        }

        $old_version = Cashback_Legal_Documents::get_active_version($type);
        $new_version = Cashback_Legal_Documents::bump_major($type);

        // async batch superseded — через wp_schedule_single_event (как cron-pattern плагина),
        // не AS, чтобы не вводить новую зависимость на этом этапе.
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(
                time() + 30,
                self::ACTION_BUMP_BATCH,
                array( $type, $new_version )
            );
        }

        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'legal_version_bumped',
                get_current_user_id(),
                'legal_document',
                null,
                array(
                    'consent_type' => $type,
                    'from_version' => $old_version,
                    'to_version'   => $new_version,
                )
            );
        }

        $url = admin_url('admin.php?page=' . self::PAGE_SLUG_VERSIONS . '&cashback_legal_flash=bumped');
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Cron-callback: пишет superseded для всех ранее granted-юзеров с
     * document_version меньше bumped_to_version.
     */
    public static function run_bump_batch( string $type, string $bumped_to_version ): void {
        if (!class_exists('Cashback_Legal_Consent_Manager')) {
            return;
        }
        Cashback_Legal_Consent_Manager::mark_superseded_for_type($type, $bumped_to_version);
    }

    // ────────────────────────────────────────────────────────────
    // Phase 5: Журнал согласий
    // ────────────────────────────────────────────────────────────

    public static function render_journal_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'), '', array( 'response' => 403 ));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only фильтры.
        $filter_user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $filter_type    = isset($_GET['consent_type']) ? sanitize_key((string) $_GET['consent_type']) : '';
        $filter_action  = isset($_GET['log_action']) ? sanitize_key((string) $_GET['log_action']) : '';
        $filter_from    = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash((string) $_GET['date_from'])) : '';
        $filter_to      = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash((string) $_GET['date_to'])) : '';
        $page_num       = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $per_page = 50;
        $offset   = ( $page_num - 1 ) * $per_page;

        $filters = array();
        if ($filter_user_id > 0) {
            $filters['user_id'] = $filter_user_id;
        }
        if ($filter_type !== '' && in_array($filter_type, Cashback_Legal_Documents::all_types(), true)) {
            $filters['consent_type'] = $filter_type;
        }
        if (in_array($filter_action, array( 'granted', 'revoked', 'superseded' ), true)) {
            $filters['action'] = $filter_action;
        }
        if ($filter_from !== '') {
            $filters['date_from'] = $filter_from;
        }
        if ($filter_to !== '') {
            $filters['date_to'] = $filter_to;
        }

        $rows = Cashback_Legal_DB::query_log($filters, $per_page, $offset);

        ?>
        <div class="wrap cashback-legal-admin">
            <h1><?php esc_html_e('Журнал согласий', 'cashback-plugin'); ?></h1>

            <form method="get" class="cashback-legal-journal-filters" style="margin-bottom:16px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG_JOURNAL); ?>" />

                <label><?php esc_html_e('User ID', 'cashback-plugin'); ?>:
                    <input type="number" name="user_id" value="<?php echo esc_attr((string) $filter_user_id); ?>" style="width:100px;" />
                </label>

                <label style="margin-left:12px;"><?php esc_html_e('Тип', 'cashback-plugin'); ?>:
                    <select name="consent_type">
                        <option value=""><?php esc_html_e('— любой —', 'cashback-plugin'); ?></option>
                        <?php foreach (Cashback_Legal_Documents::all_types() as $type) : ?>
                            <option value="<?php echo esc_attr($type); ?>" <?php selected($filter_type, $type); ?>><?php echo esc_html($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label style="margin-left:12px;"><?php esc_html_e('Action', 'cashback-plugin'); ?>:
                    <select name="log_action">
                        <option value=""><?php esc_html_e('— любое —', 'cashback-plugin'); ?></option>
                        <?php foreach (array( 'granted', 'revoked', 'superseded' ) as $a) : ?>
                            <option value="<?php echo esc_attr($a); ?>" <?php selected($filter_action, $a); ?>><?php echo esc_html($a); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label style="margin-left:12px;"><?php esc_html_e('С', 'cashback-plugin'); ?>:
                    <input type="datetime-local" name="date_from" value="<?php echo esc_attr($filter_from); ?>" />
                </label>

                <label style="margin-left:12px;"><?php esc_html_e('По', 'cashback-plugin'); ?>:
                    <input type="datetime-local" name="date_to" value="<?php echo esc_attr($filter_to); ?>" />
                </label>

                <button type="submit" class="button" style="margin-left:12px;"><?php esc_html_e('Применить', 'cashback-plugin'); ?></button>
            </form>

            <?php if (empty($rows)) : ?>
                <p><?php esc_html_e('Записи не найдены.', 'cashback-plugin'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th><?php esc_html_e('User', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Тип', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Action', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Версия', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Источник', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('IP', 'cashback-plugin'); ?></th>
                            <th><?php esc_html_e('Дата (UTC)', 'cashback-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $row['id']); ?></td>
                            <td><?php echo $row['user_id'] === null ? '<em>guest</em>' : esc_html((string) $row['user_id']); ?></td>
                            <td><code><?php echo esc_html((string) $row['consent_type']); ?></code></td>
                            <td><?php echo esc_html((string) $row['action']); ?></td>
                            <td><code><?php echo esc_html((string) $row['document_version']); ?></code></td>
                            <td><?php echo esc_html((string) $row['source']); ?></td>
                            <td><?php echo esc_html((string) ( $row['ip_address'] ?? '' )); ?></td>
                            <td><?php echo esc_html((string) $row['granted_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:16px;">
                    <?php
                    $base = admin_url('admin.php?page=' . self::PAGE_SLUG_JOURNAL);
                    $build = static function ( int $p ) use ( $base, $filter_user_id, $filter_type, $filter_action, $filter_from, $filter_to ): string {
                        $args = array_filter(array(
                            'user_id'      => $filter_user_id > 0 ? $filter_user_id : null,
                            'consent_type' => $filter_type !== '' ? $filter_type : null,
                            'log_action'   => $filter_action !== '' ? $filter_action : null,
                            'date_from'    => $filter_from !== '' ? $filter_from : null,
                            'date_to'      => $filter_to !== '' ? $filter_to : null,
                            'paged'        => $p,
                        ), static fn( $v ): bool => $v !== null );
                        return add_query_arg($args, $base);
                    };
                    if ($page_num > 1) :
                        ?>
                        <a class="button" href="<?php echo esc_url($build($page_num - 1)); ?>">← <?php esc_html_e('Предыдущая', 'cashback-plugin'); ?></a>
                    <?php endif; ?>
                    <span style="margin:0 12px;"><?php echo esc_html(sprintf('%s %d', __('Страница', 'cashback-plugin'), $page_num)); ?></span>
                    <?php if (count($rows) === $per_page) : ?>
                        <a class="button" href="<?php echo esc_url($build($page_num + 1)); ?>"><?php esc_html_e('Следующая', 'cashback-plugin'); ?> →</a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    // ────────────────────────────────────────────────────────────
    // Phase 6: Аудит сторонних форм
    // ────────────────────────────────────────────────────────────

    /**
     * Runtime-детект сторонних форм сбора ПД. Каждая запись:
     *   - id    (уникальный slug)
     *   - title (отображаемое имя)
     *   - status: 'active' | 'inactive' | 'manual_check'
     *   - risk  (краткое описание)
     *   - recommendation
     *
     * @return array<int, array<string, string>>
     */
    public static function audit_third_party_forms(): array {
        return array(
            self::probe_woodmart_waitlist(),
            self::probe_woodmart_price_tracker(),
            self::probe_woodmart_social_auth(),
            self::probe_contact_form_7(),
            self::probe_elementor_pro_forms(),
            self::probe_wc_guest_reviews(),
        );
    }

    /**
     * Есть ли среди стороних форм хотя бы одна active — тогда показываем
     * глобальный admin_notice.
     */
    public static function has_active_third_party_forms(): bool {
        foreach (self::audit_third_party_forms() as $row) {
            if (isset($row['status']) && $row['status'] === 'active') {
                return true;
            }
        }
        return false;
    }

    public static function render_audit_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'), '', array( 'response' => 403 ));
        }

        $rows = self::audit_third_party_forms();

        ?>
        <div class="wrap cashback-legal-admin">
            <h1><?php esc_html_e('Аудит сторонних форм сбора персональных данных', 'cashback-plugin'); ?></h1>

            <p>
                <?php esc_html_e('Юр.чекбоксы плагина встроены только в формы самого Cashback Plugin (регистрация WC, вывод средств, контактная форма, social-auth). Сторонние модули темы Woodmart и других плагинов могут собирать ПД самостоятельно — этот раздел отслеживает их статус.', 'cashback-plugin'); ?>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Модуль', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Статус', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Риск по 152-ФЗ', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Рекомендация', 'cashback-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    echo self::render_audit_rows($rows); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML собран через esc_html в render_audit_rows.
                    ?>
                </tbody>
            </table>

            <h2><?php esc_html_e('Что делать, если модуль активен', 'cashback-plugin'); ?></h2>
            <ol>
                <li><?php esc_html_e('Зарегистрировать активацию в backlog как отдельную задачу (Phase 6.4-XXX).', 'cashback-plugin'); ?></li>
                <li><?php esc_html_e('Добавить чекбокс согласия в форму через хуки соответствующего модуля и записать факт в журнал согласий через Cashback_Legal_Consent_Manager::record_consent().', 'cashback-plugin'); ?></li>
                <li><?php esc_html_e('Не модифицировать файлы стороннего плагина/темы — обновления перетрут изменения.', 'cashback-plugin'); ?></li>
            </ol>
        </div>
        <?php
    }

    /**
     * Глобальный admin_notice: если хотя бы одна сторонняя форма active —
     * предупреждаем на всех admin-страницах.
     */
    public static function admin_notice_third_party(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen   = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_page  = $screen && isset($screen->id) && strpos((string) $screen->id, self::PAGE_SLUG_AUDIT) !== false;
        if ($on_page) {
            return;
        }
        if (!self::has_active_third_party_forms()) {
            return;
        }
        $url = admin_url('admin.php?page=' . self::PAGE_SLUG_AUDIT);
        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a>.</p></div>',
            esc_html__('Cashback:', 'cashback-plugin'),
            esc_html__('Обнаружены сторонние формы сбора ПД, не покрытые юр.чекбоксами плагина.', 'cashback-plugin'),
            esc_url($url),
            esc_html__('Открыть аудит', 'cashback-plugin')
        );
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private static function render_audit_rows( array $rows ): string {
        $out = '';
        foreach ($rows as $row) {
            $status = isset($row['status']) ? (string) $row['status'] : 'manual_check';
            $color  = 'manual_check' === $status ? '#856404' : ( 'active' === $status ? '#842029' : '#0f5132' );
            $bg     = 'manual_check' === $status ? '#fff3cd' : ( 'active' === $status ? '#f8d7da' : '#d1e7dd' );
            $label  = self::status_label($status);

            $out .= '<tr>'
                . '<td><strong>' . esc_html((string) $row['title']) . '</strong><br /><code>' . esc_html((string) $row['id']) . '</code></td>'
                . '<td><span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . esc_attr($bg) . ';color:' . esc_attr($color) . ';font-weight:600;">'
                . esc_html($label)
                . '</span></td>'
                . '<td>' . esc_html((string) ( $row['risk'] ?? '' )) . '</td>'
                . '<td>' . esc_html((string) ( $row['recommendation'] ?? '' )) . '</td>'
                . '</tr>';
        }
        return $out;
    }

    private static function status_label( string $status ): string {
        switch ($status) {
            case 'active':
                return __('активно — требует чекбокса', 'cashback-plugin');
            case 'manual_check':
                return __('проверьте вручную', 'cashback-plugin');
            case 'inactive':
            default:
                return __('неактивно', 'cashback-plugin');
        }
    }

    // ────────────────────────────────────────────────────────────
    // Phase 6: probe-функции (детект статуса)
    // ────────────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private static function probe_woodmart_waitlist(): array {
        $status = 'manual_check';
        if (function_exists('woodmart_get_opt')) {
            $status = (bool) woodmart_get_opt('waitlist') ? 'active' : 'inactive';
        }
        return array(
            'id'             => 'woodmart_waitlist',
            'title'          => 'Woodmart Waitlist (Back-in-Stock)',
            'status'         => $status,
            'risk'           => __('Гостевой email-сбор через wp_ajax_nopriv_woodmart_add_to_waitlist; данные хранятся отдельно от WP Users.', 'cashback-plugin'),
            'recommendation' => __('При активации добавить чекбокс согласия в форму waitlist + AJAX-handler.', 'cashback-plugin'),
        );
    }

    private static function probe_woodmart_price_tracker(): array {
        $status = 'manual_check';
        if (function_exists('woodmart_get_opt')) {
            $status = (bool) woodmart_get_opt('price_tracker') ? 'active' : 'inactive';
        }
        return array(
            'id'             => 'woodmart_price_tracker',
            'title'          => 'Woodmart Price Tracker',
            'status'         => $status,
            'risk'           => __('Гостевой email-сбор через wp_ajax_nopriv_woodmart_add_to_price_tracker.', 'cashback-plugin'),
            'recommendation' => __('При активации добавить чекбокс согласия в форму price-tracker.', 'cashback-plugin'),
        );
    }

    private static function probe_woodmart_social_auth(): array {
        $providers = array();
        if (function_exists('woodmart_get_opt')) {
            foreach (array( 'login_facebook', 'login_google', 'login_vkontakte' ) as $key) {
                if ((bool) woodmart_get_opt($key)) {
                    $providers[] = $key;
                }
            }
        }
        $status = empty($providers) ? 'inactive' : 'active';
        if (!function_exists('woodmart_get_opt')) {
            $status = 'manual_check';
        }
        return array(
            'id'             => 'woodmart_social_auth',
            'title'          => 'Woodmart Social Auth (Facebook/Google/VK)',
            'status'         => $status,
            'risk'           => __('OAuth обходит стандартный woocommerce_register_form; user_register создаётся без отметки чекбоксов плагина.', 'cashback-plugin'),
            'recommendation' => __('Перенаправлять активный OAuth-callback через нашу ветку social-auth (cash-back плагин), либо перехватить user_register для записи pd_consent + terms_offer.', 'cashback-plugin'),
        );
    }

    private static function probe_contact_form_7(): array {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_active = function_exists('is_plugin_active') && is_plugin_active('contact-form-7/wp-contact-form-7.php');
        if (!$plugin_active) {
            return array(
                'id'             => 'contact_form_7',
                'title'          => 'Contact Form 7',
                'status'         => 'inactive',
                'risk'           => __('Любые поля — email/имя/телефон.', 'cashback-plugin'),
                'recommendation' => __('Плагин не активен.', 'cashback-plugin'),
            );
        }
        $count = function_exists('wp_count_posts') ? wp_count_posts('wpcf7_contact_form') : null;
        $forms_published = is_object($count) && isset($count->publish) ? (int) $count->publish : 0;
        return array(
            'id'             => 'contact_form_7',
            'title'          => 'Contact Form 7',
            'status'         => $forms_published > 0 ? 'active' : 'inactive',
            'risk'           => __('Любые поля — email/имя/телефон.', 'cashback-plugin'),
            'recommendation' => $forms_published > 0
                ? __('Найдено опубликованных форм: ', 'cashback-plugin') . $forms_published . '. ' . __('Добавить юр.чекбокс в каждую форму через [acceptance] tag или хук wpcf7_form_elements + валидацию через wpcf7_validate.', 'cashback-plugin')
                : __('Плагин активен, но опубликованных форм нет.', 'cashback-plugin'),
        );
    }

    private static function probe_elementor_pro_forms(): array {
        $active = class_exists('\\ElementorPro\\Modules\\Forms\\Module');
        return array(
            'id'             => 'elementor_pro_forms',
            'title'          => 'Elementor Pro Forms',
            'status'         => $active ? 'active' : 'inactive',
            'risk'           => __('Любые поля собираются через elementor_pro/forms/process.', 'cashback-plugin'),
            'recommendation' => $active
                ? __('Подключиться к хуку elementor_pro/forms/validation для требования чекбокса; рекомендация — добавить отдельное Acceptance-поле в каждую форму вручную.', 'cashback-plugin')
                : __('Не установлено.', 'cashback-plugin'),
        );
    }

    private static function probe_wc_guest_reviews(): array {
        $registration_required = (int) get_option('comment_registration', 0);
        if ($registration_required === 1) {
            return array(
                'id'             => 'wc_guest_reviews',
                'title'          => 'WooCommerce Reviews',
                'status'         => 'inactive',
                'risk'           => __('Гости не могут оставлять отзывы — согласие даётся при регистрации.', 'cashback-plugin'),
                'recommendation' => __('Только text-remark под формой отзыва (Cashback_Legal_Reviews_Notice).', 'cashback-plugin'),
            );
        }
        return array(
            'id'             => 'wc_guest_reviews',
            'title'          => 'WooCommerce Guest Reviews',
            'status'         => 'active',
            'risk'           => __('Гости вводят имя+email при отзыве — это сбор ПД без чекбокса согласия.', 'cashback-plugin'),
            'recommendation' => __('Включите Settings → Discussion → "Users must be registered…" либо реализуйте гостевой чекбокс (Phase 6.4-WC-Reviews).', 'cashback-plugin'),
        );
    }
}
