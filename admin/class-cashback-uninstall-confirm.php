<?php
/**
 * Двухшаговое подтверждение удаления данных плагина при uninstall.
 *
 * UX-флоу:
 *   1) WP admin → Plugins → Удалить (на деактивированном плагине).
 *   2) WP показывает свою verify-delete страницу.
 *   3) При сабмите формы перехватываем submit, показываем модалку:
 *      Шаг 1: «Удалить все данные плагина из базы данных?» [Да] [Нет]
 *      Шаг 2 (только при «Да»): красное предупреждение [Продолжить] [Отмена]
 *   4) AJAX пишет transient `cashback_uninstall_purge_mode` (1 = full purge,
 *      0 = soft uninstall), потом форма submit'ится.
 *   5) `uninstall.php` читает transient и решает чистить БД/ключ или нет.
 *      Default-by-safety: при отсутствии transient выполняется soft
 *      uninstall (никаких DROP), чтобы CLI/cron не уничтожали данные молча.
 *
 * @package CashbackPlugin
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Cashback_Uninstall_Confirm {

    /** Transient key, читается uninstall.php. */
    public const TRANSIENT_KEY = 'cashback_uninstall_purge_mode';

    /** Nonce для AJAX-эндпоинта. */
    public const NONCE_ACTION = 'cashback_uninstall_confirm_nonce';

    /** TTL transient'а — окно между нажатием submit и запуском uninstall.php. */
    public const TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;

    /** Plugin basename для фильтрации checked[] на verify-delete странице. */
    private const PLUGIN_BASENAME = 'cash-back/cashback-plugin.php';

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ));
        add_action('wp_ajax_cashback_set_uninstall_mode', array( $this, 'ajax_set_mode' ));
    }

    /**
     * Подгружаем JS/CSS только на verify-delete странице плагинов
     * (там, где WP уже спрашивает «Are you sure?»).
     */
    public function maybe_enqueue_assets( string $hook ): void {
        if ($hook !== 'plugins.php') {
            return;
        }
        if (!current_user_can('activate_plugins')) {
            return;
        }
        // На verify-delete странице WP всегда передаёт ?action=delete-selected.
        // На обычной странице плагинов модалка не нужна. Это read-only гейтинг
        // подгрузки ассетов — никаких действий по данным, nonce не нужен.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset gating; nonce verification handled by WP core in plugins.php delete flow.
        $action = isset($_GET['action']) ? sanitize_key((string) wp_unslash($_GET['action'])) : '';
        if ($action !== 'delete-selected') {
            return;
        }

        $base = plugin_dir_url(__DIR__);

        wp_enqueue_style(
            'cashback-uninstall-confirm',
            $base . 'admin/css/uninstall-confirm.css',
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'cashback-uninstall-confirm',
            $base . 'admin/js/uninstall-confirm.js',
            array(),
            '1.0.0',
            true
        );

        wp_localize_script(
            'cashback-uninstall-confirm',
            'CashbackUninstallConfirm',
            array(
                'ajaxUrl'        => admin_url('admin-ajax.php'),
                'nonce'          => wp_create_nonce(self::NONCE_ACTION),
                'pluginBasename' => self::PLUGIN_BASENAME,
                'i18n'           => array(
                    'step1Title'  => __('Удаление плагина Cashback', 'cashback-plugin'),
                    'step1Body'   => __('Удалить все данные плагина из базы данных?', 'cashback-plugin'),
                    'btnYes'      => __('Да', 'cashback-plugin'),
                    'btnNo'       => __('Нет', 'cashback-plugin'),
                    'step2Title'  => __('Внимание', 'cashback-plugin'),
                    'step2Body'   => __('Все данные плагина будут безвозвратно удалены: таблицы, триггеры, MySQL-события, опции, transients, файлы поддержки и ключ шифрования из wp-content. Это действие нельзя отменить.', 'cashback-plugin'),
                    'btnContinue' => __('Продолжить', 'cashback-plugin'),
                    'btnCancel'   => __('Отмена', 'cashback-plugin'),
                    'noteKeep'    => __('Данные в базе и ключ шифрования будут сохранены — удалятся только файлы плагина.', 'cashback-plugin'),
                    'errAjax'     => __('Не удалось сохранить выбор. Повторите.', 'cashback-plugin'),
                    'closeAria'   => __('Закрыть', 'cashback-plugin'),
                ),
            )
        );
    }

    /**
     * AJAX: записываем в transient выбор пользователя.
     * Идемпотентен — последний вызов выигрывает (что и нужно для UX «Cancel → reopen»).
     */
    public function ajax_set_mode(): void {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(array( 'message' => 'invalid_nonce' ), 403);
            return;
        }
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error(array( 'message' => 'forbidden' ), 403);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен выше через check_ajax_referer.
        $purge_raw = isset($_POST['purge']) ? sanitize_text_field(wp_unslash((string) $_POST['purge'])) : '0';
        $purge     = $purge_raw === '1' ? '1' : '0';

        set_transient(self::TRANSIENT_KEY, $purge, self::TRANSIENT_TTL);

        wp_send_json_success(array( 'purge' => $purge ));
    }
}

Cashback_Uninstall_Confirm::get_instance();
