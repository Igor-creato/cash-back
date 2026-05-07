<?php
/**
 * Cashback_Legal_Template_Editor — view.
 *
 * Контекст в области видимости (через render_page):
 *   string $type
 *   string $title
 *   string $published_body
 *   string $published_hash
 *   string $published_version
 *   array<string,mixed>|null $draft
 *   array<int,string> $required_phs
 *
 * @var string $type
 * @var string $title
 * @var string $published_body
 * @var string $published_hash
 * @var string $published_version
 * @var array<string, mixed>|null $draft
 * @var array<int, string> $required_phs
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$back_url    = admin_url('admin.php?page=' . Cashback_Legal_Admin::PAGE_SLUG_VERSIONS);
$next_major = ( function ( string $current ): string {
    if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $current, $m)) {
        return ((int) $m[1] + 1) . '.0.0';
    }
    return '2.0.0';
} )($published_version);

?>
<div class="wrap cashback-legal-template-editor" data-type="<?php echo esc_attr($type); ?>">
    <h1>
        <a href="<?php echo esc_url($back_url); ?>" class="button button-link" style="margin-right:8px;">← <?php esc_html_e('Назад', 'cashback-plugin'); ?></a>
        <?php echo esc_html($title); ?>
        <code style="font-size:13px;font-weight:400;"><?php echo esc_html($type); ?></code>
    </h1>

    <div class="cashback-legal-template-warn notice notice-warning" style="border-left-width:4px;">
        <p>
            <strong><?php esc_html_e('Публикация — необратимое действие.', 'cashback-plugin'); ?></strong>
            <?php esc_html_e('Все ранее данные согласия по этому документу будут помечены как superseded; пользователи пройдут re-consent при следующем входе. Делайте публикацию только после согласования с юристом.', 'cashback-plugin'); ?>
        </p>
        <p>
            <?php esc_html_e('Сохранение черновика никак не влияет на публичные страницы — они продолжают показывать опубликованную версию до момента «Опубликовать новую версию».', 'cashback-plugin'); ?>
        </p>
    </div>

    <div class="cashback-legal-template-meta">
        <div class="cashback-legal-template-status" data-role="published-meta">
            <span class="badge badge-published"><?php esc_html_e('Опубликовано', 'cashback-plugin'); ?></span>
            <strong><?php echo esc_html('v' . $published_version); ?></strong>
            <code title="<?php echo esc_attr($published_hash); ?>"><?php echo esc_html(substr($published_hash, 0, 12) . '…'); ?></code>
        </div>
        <div class="cashback-legal-template-status" data-role="draft-meta">
            <?php if (is_array($draft)) : ?>
                <span class="badge badge-draft"><?php esc_html_e('Черновик', 'cashback-plugin'); ?></span>
                <code title="<?php echo esc_attr((string) ($draft['body_hash'] ?? '')); ?>"><?php echo esc_html(substr((string) ($draft['body_hash'] ?? ''), 0, 12) . '…'); ?></code>
                <small>(<?php echo esc_html((string) ($draft['created_at'] ?? '')); ?> UTC)</small>
            <?php else : ?>
                <span class="badge badge-no-draft"><?php esc_html_e('Черновика нет', 'cashback-plugin'); ?></span>
            <?php endif; ?>
        </div>
        <div class="cashback-legal-template-dirty" data-role="dirty-indicator" hidden>
            <span class="badge badge-dirty"><?php esc_html_e('Несохранённые изменения', 'cashback-plugin'); ?></span>
        </div>
    </div>

    <textarea
        id="cashback-legal-template-body"
        name="body"
        rows="24"
        spellcheck="false"
        style="width:100%;font-family:Consolas,Monaco,monospace;"
    ><?php echo esc_textarea(is_array($draft) ? (string) ($draft['body_html'] ?? $published_body) : $published_body); ?></textarea>

    <?php if (!empty($required_phs)) : ?>
        <div class="cashback-legal-placeholders">
            <p><strong><?php esc_html_e('Обязательные плейсхолдеры (клик — копирование):', 'cashback-plugin'); ?></strong></p>
            <p>
                <?php foreach ($required_phs as $ph) : ?>
                    <button type="button" class="button button-small cashback-legal-placeholder-chip" data-placeholder="<?php echo esc_attr('{{' . $ph . '}}'); ?>">
                        <?php echo esc_html('{{' . $ph . '}}'); ?>
                    </button>
                <?php endforeach; ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="cashback-legal-template-actions">
        <button type="button" class="button" data-action="save"><?php esc_html_e('Сохранить черновик', 'cashback-plugin'); ?></button>
        <button type="button" class="button" data-action="discard"><?php esc_html_e('Отменить черновик', 'cashback-plugin'); ?></button>
        <button type="button" class="button" data-action="preview"><?php esc_html_e('Превью →', 'cashback-plugin'); ?></button>
        <button type="button" class="button button-primary" data-action="publish">
            <?php
            /* translators: %s — целевая semver-версия (например, 2.0.0). */
            printf(esc_html__('Опубликовать v%s →', 'cashback-plugin'), esc_html($next_major));
            ?>
        </button>
    </div>

    <dialog id="cashback-legal-template-preview-dialog" class="cashback-legal-template-dialog">
        <button type="button" class="cashback-legal-template-dialog-close" aria-label="<?php esc_attr_e('Закрыть', 'cashback-plugin'); ?>">×</button>
        <h2><?php esc_html_e('Превью документа', 'cashback-plugin'); ?></h2>
        <iframe data-role="preview-frame" sandbox="allow-same-origin" style="width:100%;height:60vh;border:1px solid #ccd0d4;"></iframe>
    </dialog>

    <dialog id="cashback-legal-template-publish-dialog" class="cashback-legal-template-dialog">
        <button type="button" class="cashback-legal-template-dialog-close" aria-label="<?php esc_attr_e('Закрыть', 'cashback-plugin'); ?>">×</button>
        <h2><?php esc_html_e('Опубликовать новую major-версию?', 'cashback-plugin'); ?></h2>
        <p>
            <?php
            /* translators: %1$s — текущая semver-версия, %2$s — целевая semver-версия. */
            printf(esc_html__('Текущая v%1$s → v%2$s', 'cashback-plugin'), esc_html($published_version), esc_html($next_major));
            ?>
        </p>
        <ul style="margin-left:1.5em;list-style:disc;">
            <li><?php esc_html_e('Атомарно увеличит версию документа.', 'cashback-plugin'); ?></li>
            <li><?php esc_html_e('Пометит все ранее данные согласия как superseded.', 'cashback-plugin'); ?></li>
            <li><?php esc_html_e('Запустит re-consent flow для всех пользователей при следующем входе.', 'cashback-plugin'); ?></li>
        </ul>
        <p>
            <label for="cashback-legal-template-publish-confirm">
                <?php
                /* translators: %s — фраза подтверждения публикации (PUBLISH X.0.0). */
                printf(esc_html__('Введите %s для подтверждения:', 'cashback-plugin'), '<code>PUBLISH ' . esc_html($next_major) . '</code>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML-фрагмент <code> с уже escaped значением.
                ?>
            </label>
            <input
                type="text"
                id="cashback-legal-template-publish-confirm"
                data-expected="<?php echo esc_attr('PUBLISH ' . $next_major); ?>"
                style="width:100%;font-family:Consolas,Monaco,monospace;"
                autocomplete="off"
            />
        </p>
        <div class="cashback-legal-template-actions">
            <button type="button" class="button" data-action="cancel-publish"><?php esc_html_e('Отмена', 'cashback-plugin'); ?></button>
            <button type="button" class="button button-primary" data-action="confirm-publish" disabled><?php esc_html_e('Опубликовать', 'cashback-plugin'); ?></button>
        </div>
    </dialog>

    <div class="cashback-legal-template-feedback" data-role="feedback" aria-live="polite"></div>

    <script>
        window.CashbackLegalTemplateEditorBoot = {
            type: <?php echo wp_json_encode($type); ?>,
            publishedHash: <?php echo wp_json_encode($published_hash); ?>,
            publishedVersion: <?php echo wp_json_encode($published_version); ?>,
            nextMajor: <?php echo wp_json_encode($next_major); ?>,
        };
    </script>
</div>
