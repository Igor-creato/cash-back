<?php
/**
 * Admin view: Ротация ключа шифрования.
 *
 * Переменные из Cashback_Key_Rotation::render_admin_page():
 *
 * @var array<string,mixed> $state       Результат Cashback_Key_Rotation::get_state()
 * @var string              $fp_old      Fingerprint primary-ключа (HMAC, 64 hex) или ''
 * @var string              $fp_new      Fingerprint new-ключа (staging) или ''
 * @var string              $fp_prev     Fingerprint previous-ключа (после finalize) или ''
 * @var int                 $cleanup_at  Unix timestamp автоочистки previous; 0 если не запланировано
 * @var array|null          $flash       Результат consume_flash()
 * @var int                 $key_mtime   filemtime основного key-файла или 0
 */

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string,mixed> $state */
/** @var string $fp_old */
/** @var string $fp_new */
/** @var string $fp_prev */
/** @var int $cleanup_at */
/** @var array|null $flash */
/** @var int $key_mtime */

$state_name    = (string) ( $state['state'] ?? 'idle' );
$sanity_active = !empty($state['sanity_active']);
$sanity_dir    = (string) ( $state['sanity_direction'] ?? 'forward' );
$progress      = is_array($state['progress'] ?? null) ? $state['progress'] : array();

/**
 * Хелпер: короткий prefix fingerprint (первые 16 символов hex) для UI.
 */
$fp_prefix = static function ( string $fp ): string {
    return $fp === '' ? '—' : substr($fp, 0, 16) . '…';
};

/**
 * Хелпер: кнопка admin_post формы с nonce.
 *
 * @param string              $action_slug Имя admin_post_* хука (без префикса `cashback_rotation_`).
 * @param string              $label       Текст кнопки.
 * @param string              $button_class CSS-класс (button / button-primary / button-link-delete).
 * @param array<string,string> $extra_fields Доп. поля формы (name => value).
 */
$render_form = static function ( string $action_slug, string $label, string $button_class, array $extra_fields = array() ): void {
    $full_action = 'cashback_rotation_' . $action_slug;
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:.5em;">
        <input type="hidden" name="action" value="<?php echo esc_attr($full_action); ?>" />
        <?php wp_nonce_field($full_action); ?>
        <?php foreach ($extra_fields as $name => $value) : ?>
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" />
        <?php endforeach; ?>
        <button type="submit" class="button <?php echo esc_attr($button_class); ?>"><?php echo esc_html($label); ?></button>
    </form>
    <?php
};

?>
<div class="wrap cashback-key-rotation-page" data-state="<?php echo esc_attr($state_name); ?>">
    <h1><?php esc_html_e('Ротация ключа шифрования', 'cashback-plugin'); ?></h1>

    <?php if ($flash !== null && isset($flash['message'])) : ?>
        <?php $level_class = ( ( $flash['level'] ?? '' ) === 'error' ) ? 'notice-error' : 'notice-success'; ?>
        <div class="notice <?php echo esc_attr($level_class); ?> is-dismissible">
            <p><?php echo esc_html((string) $flash['message']); ?></p>
        </div>
    <?php endif; ?>

    <div class="notice notice-info inline">
        <p>
            <?php esc_html_e('Формы пользователей и админ-операции продолжают работать штатно без прерываний. Перешифровка идёт в фоне; задержка отдельной операции во время ротации — до нескольких миллисекунд (row-lock).', 'cashback-plugin'); ?>
        </p>
    </div>

    <h2><?php esc_html_e('Текущие ключи', 'cashback-plugin'); ?></h2>
    <table class="widefat striped" style="max-width:900px;">
        <thead>
            <tr>
                <th><?php esc_html_e('Роль', 'cashback-plugin'); ?></th>
                <th><?php esc_html_e('Fingerprint (HMAC-SHA256, prefix)', 'cashback-plugin'); ?></th>
                <th><?php esc_html_e('Статус', 'cashback-plugin'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>CB_ENCRYPTION_KEY</code> (primary)</td>
                <td><code data-fp-role="primary"><?php echo esc_html($fp_prefix($fp_old)); ?></code></td>
                <td>
                    <?php if ($key_mtime > 0) : ?>
                        <?php
                        /* translators: %s — human-readable date of the primary key file. */
                        echo esc_html(sprintf(__('Активен, файл обновлён %s', 'cashback-plugin'), wp_date('Y-m-d H:i', $key_mtime)));
                        ?>
                    <?php else : ?>
                        <?php esc_html_e('Активен', 'cashback-plugin'); ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><code>CB_ENCRYPTION_KEY_NEW</code> (staging)</td>
                <td><code data-fp-role="new"><?php echo esc_html($fp_prefix($fp_new)); ?></code></td>
                <td>
                    <?php echo $fp_new === '' ? esc_html__('Не сконфигурирован', 'cashback-plugin') : esc_html__('Сконфигурирован', 'cashback-plugin'); ?>
                </td>
            </tr>
            <tr>
                <td><code>CB_ENCRYPTION_KEY_PREVIOUS</code> (окно отката)</td>
                <td><code data-fp-role="previous"><?php echo esc_html($fp_prefix($fp_prev)); ?></code></td>
                <td>
                    <?php if ($fp_prev === '') : ?>
                        <?php esc_html_e('Отсутствует', 'cashback-plugin'); ?>
                    <?php elseif ($cleanup_at > 0) : ?>
                        <?php
                        $seconds_left = max(0, $cleanup_at - time());
                        $days_left    = (int) floor($seconds_left / 86400);
                        /* translators: %d — days left until automatic cleanup of the previous key. */
                        echo esc_html(sprintf(_n('Авто-удаление через %d день', 'Авто-удаление через %d дн.', $days_left, 'cashback-plugin'), $days_left));
                        ?>
                    <?php else : ?>
                        <?php esc_html_e('Активен', 'cashback-plugin'); ?>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <h2 style="margin-top:2em;">
        <?php
        /* translators: %s — human-readable rotation state (idle/staging/migrating/...). */
        echo esc_html(sprintf(__('Состояние: %s', 'cashback-plugin'), $state_name));
        if ($sanity_active) {
            echo ' — ' . esc_html__('выполняется sanity-pass', 'cashback-plugin');
            echo ' (' . esc_html($sanity_dir) . ')';
        }
        ?>
    </h2>

    <?php /* ================== IDLE ================== */ ?>
    <?php if ($state_name === 'idle') : ?>
        <div class="cashback-rotation-panel">
            <p><?php esc_html_e('Ротация не запущена. Сгенерируйте новый ключ, чтобы начать процесс. Текущий ключ останется активным до явного подтверждения переключения.', 'cashback-plugin'); ?></p>
            <?php $render_form('generate', __('Сгенерировать новый ключ', 'cashback-plugin'), 'button-primary'); ?>
        </div>

    <?php /* ================== STAGING ================== */ ?>
    <?php elseif ($state_name === 'staging') : ?>
        <div class="cashback-rotation-panel">
            <p>
                <?php esc_html_e('Подготовлен новый ключ шифрования. Все активные ключи загружены в процесс PHP; trial-decrypt уже работает. Для запуска перешифровки введите слово-подтверждение и нажмите «Начать ротацию».', 'cashback-plugin'); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Fingerprint нового ключа:', 'cashback-plugin'); ?></strong>
                <code><?php echo esc_html($fp_prefix($fp_new)); ?></code>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1em;">
                <input type="hidden" name="action" value="cashback_rotation_start" />
                <?php wp_nonce_field('cashback_rotation_start'); ?>
                <label style="display:block;margin-bottom:.5em;">
                    <?php
                    /* translators: %s — confirmation word to start rotation. */
                    echo esc_html(sprintf(__('Введите %s для подтверждения:', 'cashback-plugin'), Cashback_Key_Rotation::CONFIRMATION_START));
                    ?>
                    <input type="text" name="confirmation" required autocomplete="off" style="width:280px;" />
                </label>
                <button type="submit" class="button button-primary"><?php esc_html_e('Начать ротацию', 'cashback-plugin'); ?></button>
            </form>
            <?php $render_form('abort', __('Отменить', 'cashback-plugin'), 'button-link-delete'); ?>
        </div>

    <?php /* ================== MIGRATING ================== */ ?>
    <?php elseif ($state_name === 'migrating') : ?>
        <div class="cashback-rotation-panel">
            <?php if ($sanity_active) : ?>
                <p>
                    <strong><?php esc_html_e('Выполняется sanity-pass (проверка пропущенных записей).', 'cashback-plugin'); ?></strong>
                    <?php
                    /* translators: %1$d — current sanity iteration, %2$d — max iterations. */
                    echo esc_html(sprintf(__(' Итерация %1$d из %2$d.', 'cashback-plugin'), (int) $state['sanity_iteration'], Cashback_Key_Rotation::SANITY_MAX_ITERATIONS));
                    ?>
                </p>
            <?php else : ?>
                <p>
                    <?php esc_html_e('Идёт перешифровка данных. Счётчики прогресса обновляются в фоне каждые 3 секунды; при смене состояния страница перезагрузится автоматически.', 'cashback-plugin'); ?>
                </p>
            <?php endif; ?>

            <table class="widefat striped cashback-rotation-progress" style="max-width:900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Фаза', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Готово', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Всего', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Ошибок', 'cashback-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (Cashback_Key_Rotation::PHASES as $phase) : ?>
                        <?php
                        $row        = $progress[ $phase ] ?? array();
                        $done       = (int) ( $row['done']   ?? 0 );
                        $total      = (int) ( $row['total']  ?? 0 );
                        $failed     = (int) ( $row['failed'] ?? 0 );
                        $is_current = ( ( $state['current_phase'] ?? null ) === $phase );
                        ?>
                        <tr data-phase="<?php echo esc_attr($phase); ?>" <?php echo $is_current ? 'class="cashback-row-active"' : ''; ?>>
                            <td><code><?php echo esc_html($phase); ?></code><?php echo $is_current ? ' ← ' . esc_html__('текущая', 'cashback-plugin') : ''; ?></td>
                            <td data-cell="done"><?php echo esc_html((string) $done); ?></td>
                            <td data-cell="total"><?php echo esc_html((string) $total); ?></td>
                            <td data-cell="failed"><?php echo esc_html((string) $failed); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!empty($state['last_error'])) : ?>
                <div class="notice notice-error inline" style="margin-top:1em;">
                    <p>
                        <strong><?php esc_html_e('Последняя ошибка batch-job:', 'cashback-plugin'); ?></strong>
                        <code><?php echo esc_html((string) $state['last_error']); ?></code>
                    </p>
                </div>
            <?php endif; ?>
        </div>

    <?php /* ================== MIGRATED ================== */ ?>
    <?php elseif ($state_name === 'migrated') : ?>
        <div class="cashback-rotation-panel">
            <p>
                <strong><?php esc_html_e('Все данные перешифрованы новым ключом.', 'cashback-plugin'); ?></strong>
                <?php esc_html_e('Подтвердите завершение ротации — ключи будут переключены, старый ключ сохранится ещё 7 дней для возможного отката.', 'cashback-plugin'); ?>
            </p>
            <?php if (!empty($state['sanity_unresolved'])) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        /* translators: %d — number of records that couldn't be re-encrypted even after sanity iterations. */
                        echo esc_html(sprintf(__('Внимание: %d записей остались нерасшифрованными после sanity-pass. См. audit log.', 'cashback-plugin'), (int) $state['sanity_unresolved']));
                        ?>
                    </p>
                </div>
            <?php endif; ?>
            <?php $render_form('finalize', __('Завершить ротацию (swap ключей)', 'cashback-plugin'), 'button-primary'); ?>
        </div>

    <?php /* ================== COMPLETED ================== */ ?>
    <?php elseif ($state_name === 'completed') : ?>
        <div class="cashback-rotation-panel">
            <p>
                <?php
                $finalized_at = (string) ( $state['finalized_at'] ?? '' );
                if ($finalized_at !== '') {
                    $ts = strtotime($finalized_at);
                    if ($ts !== false) {
                        /* translators: %s — date/time of rotation finalize. */
                        echo esc_html(sprintf(__('Ротация завершена %s.', 'cashback-plugin'), wp_date('Y-m-d H:i', $ts)));
                    }
                } else {
                    esc_html_e('Ротация завершена.', 'cashback-plugin');
                }
                ?>
            </p>
            <?php if ($cleanup_at > 0) : ?>
                <?php
                $seconds_left = max(0, $cleanup_at - time());
                $days_left    = (int) floor($seconds_left / 86400);
                ?>
                <p>
                    <?php if ($seconds_left > 0) : ?>
                        <?php
                        /* translators: %d — days left in rollback window. */
                        echo esc_html(sprintf(_n('Окно отката активно ещё %d день.', 'Окно отката активно ещё %d дн.', $days_left, 'cashback-plugin'), $days_left));
                        ?>
                    <?php else : ?>
                        <?php esc_html_e('Окно отката истекло. Старый ключ будет удалён при следующем cron-цикле.', 'cashback-plugin'); ?>
                    <?php endif; ?>
                </p>

                <?php if ($seconds_left > 0) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="cashback_rotation_rollback" />
                        <?php wp_nonce_field('cashback_rotation_rollback'); ?>
                        <label style="display:block;margin-bottom:.5em;">
                            <?php
                            /* translators: %s — confirmation word to rollback. */
                            echo esc_html(sprintf(__('Введите %s для отката:', 'cashback-plugin'), Cashback_Key_Rotation::CONFIRMATION_ROLLBACK));
                            ?>
                            <input type="text" name="confirmation" required autocomplete="off" style="width:320px;" />
                        </label>
                        <button type="submit" class="button"
                            onclick="return confirm('<?php echo esc_js(__('Откат вернёт старый ключ как основной и перешифрует все записи обратно. Продолжить?', 'cashback-plugin')); ?>');">
                            <?php esc_html_e('Откатить ротацию', 'cashback-plugin'); ?>
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    <?php /* ================== ROLLING_BACK ================== */ ?>
    <?php elseif ($state_name === 'rolling_back') : ?>
        <div class="cashback-rotation-panel">
            <p>
                <strong><?php esc_html_e('Идёт откат к предыдущему ключу.', 'cashback-plugin'); ?></strong>
                <?php esc_html_e('Страница обновляется автоматически.', 'cashback-plugin'); ?>
            </p>

            <table class="widefat striped cashback-rotation-progress" style="max-width:900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Фаза', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Готово', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Всего', 'cashback-plugin'); ?></th>
                        <th><?php esc_html_e('Ошибок', 'cashback-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (Cashback_Key_Rotation::PHASES as $phase) : ?>
                        <?php
                        $row    = $progress[ $phase ] ?? array();
                        $done   = (int) ( $row['done']   ?? 0 );
                        $total  = (int) ( $row['total']  ?? 0 );
                        $failed = (int) ( $row['failed'] ?? 0 );
                        ?>
                        <tr data-phase="<?php echo esc_attr($phase); ?>">
                            <td><code><?php echo esc_html($phase); ?></code></td>
                            <td data-cell="done"><?php echo esc_html((string) $done); ?></td>
                            <td data-cell="total"><?php echo esc_html((string) $total); ?></td>
                            <td data-cell="failed"><?php echo esc_html((string) $failed); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
.cashback-key-rotation-page .notice.inline { margin: .5em 0; }
.cashback-rotation-panel { background: #fff; border: 1px solid #ccd0d4; padding: 1em 1.25em; max-width: 900px; margin-top: 1em; }
.cashback-rotation-progress tr.cashback-row-active td { font-weight: 600; background: #f0f6fc; }
.cashback-rotation-progress td[data-cell="failed"] { color: #b32d2e; }
</style>
