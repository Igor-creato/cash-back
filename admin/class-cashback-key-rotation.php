<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Online dual-key ротация ключа шифрования из админки.
 *
 * State-machine:
 *   idle → staging → migrating → migrated → completed  (happy path)
 *                               ↓
 *                           rolling_back → idle         (откат в окне 7 дней после completed)
 *                  ↓
 *                idle                                    (abort в фазе staging)
 *
 * Файлы ключей в wp-content/ (chmod 0600):
 *   .cashback-encryption-key.php            → CB_ENCRYPTION_KEY (primary)
 *   .cashback-encryption-key.new.php        → CB_ENCRYPTION_KEY_NEW (в фазах staging/migrating/migrated)
 *   .cashback-encryption-key.previous.php   → CB_ENCRYPTION_KEY_PREVIOUS (в окне отката после completed)
 *
 * См. план: C:\Users\User\.claude\plans\jolly-roaming-kitten.md
 */
class Cashback_Key_Rotation {

    public const STATE_OPTION            = 'cashback_key_rotation_state';
    public const CLEANUP_DEADLINE_OPTION = 'cashback_key_rotation_cleanup_at';

    public const AS_HOOK_MIGRATE  = 'cashback_key_rotation_migrate_batch';
    public const AS_HOOK_ROLLBACK = 'cashback_key_rotation_rollback_batch';
    public const AS_HOOK_CLEANUP  = 'cashback_key_rotation_cleanup_previous';
    public const AS_GROUP         = 'cashback';

    public const ADMIN_PAGE_SLUG  = 'cashback-key-rotation';
    public const PARENT_MENU_SLUG = 'cashback-overview';

    public const BATCH_SIZE_PROFILES = 200;
    public const BATCH_SIZE_PAYOUTS  = 200;
    public const BATCH_SIZE_SMALL    = 100;

    public const ROLLBACK_WINDOW_DAYS = 7;

    /** Слова-подтверждения для критических операций. Сравниваются через hash_equals. */
    public const CONFIRMATION_START    = 'ROTATE_ENCRYPTION_KEY';
    public const CONFIRMATION_ROLLBACK = 'ROLLBACK_ENCRYPTION_KEY';

    /** Nonce-ID'ы для admin_post и AJAX. */
    public const NONCE_GENERATE = 'cashback_rotation_generate';
    public const NONCE_START    = 'cashback_rotation_start';
    public const NONCE_FINALIZE = 'cashback_rotation_finalize';
    public const NONCE_ROLLBACK = 'cashback_rotation_rollback';
    public const NONCE_ABORT    = 'cashback_rotation_abort';
    public const NONCE_STATUS   = 'cashback_rotation_status';

    /** Валидные состояния state-machine. */
    public const STATE_IDLE         = 'idle';
    public const STATE_STAGING      = 'staging';
    public const STATE_MIGRATING    = 'migrating';
    public const STATE_MIGRATED     = 'migrated';
    public const STATE_ROLLING_BACK = 'rolling_back';
    public const STATE_COMPLETED    = 'completed';

    /** Фазы batch-job для migrate / rollback (порядок важен — от мелких к крупным). */
    public const PHASES = array(
        'options_captcha',
        'options_epn',
        'options_social',
        'affiliate_networks',
        'social_tokens',
        'social_pending',
        'payout_requests',
        'user_profile',
    );

    // ================================================================
    // Регистрация хуков
    // ================================================================

    public static function init(): void {
        add_action('admin_menu', array( __CLASS__, 'register_admin_page' ));

        add_action('admin_post_cashback_rotation_generate', array( __CLASS__, 'handle_generate' ));
        add_action('admin_post_cashback_rotation_start', array( __CLASS__, 'handle_start' ));
        add_action('admin_post_cashback_rotation_finalize', array( __CLASS__, 'handle_finalize' ));
        add_action('admin_post_cashback_rotation_rollback', array( __CLASS__, 'handle_rollback' ));
        add_action('admin_post_cashback_rotation_abort', array( __CLASS__, 'handle_abort' ));

        add_action('wp_ajax_cashback_rotation_status', array( __CLASS__, 'ajax_status' ));

        add_action(self::AS_HOOK_MIGRATE, array( __CLASS__, 'run_migrate_batch' ));
        add_action(self::AS_HOOK_ROLLBACK, array( __CLASS__, 'run_rollback_batch' ));
        add_action(self::AS_HOOK_CLEANUP, array( __CLASS__, 'cleanup_previous_key' ));
    }

    // ================================================================
    // Пути к файлам ключей (дублируют логику из cashback-plugin.php)
    // ================================================================

    public static function get_primary_key_path(): string {
        return (string) apply_filters(
            'cashback_key_rotation_primary_key_path',
            WP_CONTENT_DIR . '/.cashback-encryption-key.php'
        );
    }

    public static function get_new_key_path(): string {
        return (string) apply_filters(
            'cashback_key_rotation_new_key_path',
            WP_CONTENT_DIR . '/.cashback-encryption-key.new.php'
        );
    }

    public static function get_previous_key_path(): string {
        return (string) apply_filters(
            'cashback_key_rotation_previous_key_path',
            WP_CONTENT_DIR . '/.cashback-encryption-key.previous.php'
        );
    }

    // ================================================================
    // State helpers
    // ================================================================

    /**
     * Возвращает текущее состояние ротации. При отсутствии записи — idle.
     *
     * @return array{
     *   state:string, started_at:?string, finalized_at:?string,
     *   initiator_id:int,
     *   progress:array<string,array{total:int,done:int,failed:int,cursor:int}>,
     *   current_phase:?string, last_error:?string, total_batches:int
     * }
     */
    public static function get_state(): array {
        $raw = get_option(self::STATE_OPTION, '');
        if (is_array($raw)) {
            return self::normalize_state($raw);
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return self::normalize_state($decoded);
            }
        }
        return self::new_idle_state();
    }

    /**
     * Сохраняет состояние в wp_option. Вызывающий передаёт полный массив (не патч).
     *
     * @param array<string,mixed> $state
     */
    public static function save_state( array $state ): void {
        $normalized = self::normalize_state($state);
        update_option(self::STATE_OPTION, wp_json_encode($normalized), false);
    }

    /**
     * Приводит state-массив к каноническому виду (заполняет отсутствующие поля).
     *
     * @param array<string,mixed> $state
     * @return array{
     *   state:string, started_at:?string, finalized_at:?string,
     *   initiator_id:int,
     *   progress:array<string,array{total:int,done:int,failed:int,cursor:int}>,
     *   current_phase:?string, last_error:?string, total_batches:int
     * }
     */
    private static function normalize_state( array $state ): array {
        $valid_states = array(
            self::STATE_IDLE,
            self::STATE_STAGING,
            self::STATE_MIGRATING,
            self::STATE_MIGRATED,
            self::STATE_ROLLING_BACK,
            self::STATE_COMPLETED,
        );
        $name         = isset($state['state']) ? (string) $state['state'] : self::STATE_IDLE;
        if (!in_array($name, $valid_states, true)) {
            $name = self::STATE_IDLE;
        }

        $progress     = array();
        $raw_progress = isset($state['progress']) && is_array($state['progress']) ? $state['progress'] : array();
        foreach (self::PHASES as $phase) {
            $row                = isset($raw_progress[ $phase ]) && is_array($raw_progress[ $phase ]) ? $raw_progress[ $phase ] : array();
            $progress[ $phase ] = array(
                'total'  => isset($row['total'])  ? max(0, (int) $row['total'])  : 0,
                'done'   => isset($row['done'])   ? max(0, (int) $row['done'])   : 0,
                'failed' => isset($row['failed']) ? max(0, (int) $row['failed']) : 0,
                // cursor: PK последней обработанной записи в фазе. Для wp_options-фаз не используется.
                'cursor' => isset($row['cursor']) ? max(0, (int) $row['cursor']) : 0,
            );
        }

        $current_phase = isset($state['current_phase']) && is_string($state['current_phase']) && in_array($state['current_phase'], self::PHASES, true)
            ? (string) $state['current_phase']
            : null;

        return array(
            'state'         => $name,
            'started_at'    => isset($state['started_at'])    && is_string($state['started_at'])    ? (string) $state['started_at']    : null,
            'finalized_at'  => isset($state['finalized_at'])  && is_string($state['finalized_at'])  ? (string) $state['finalized_at']  : null,
            'initiator_id'  => isset($state['initiator_id'])  ? (int) $state['initiator_id']        : 0,
            'progress'      => $progress,
            'current_phase' => $current_phase,
            'last_error'    => isset($state['last_error'])    && is_string($state['last_error'])    ? (string) $state['last_error']    : null,
            // Счётчик batch-вызовов с момента start_migration — для throttled audit log
            // (key_rotation_batch_completed раз в 10 батчей).
            'total_batches' => isset($state['total_batches']) ? max(0, (int) $state['total_batches']) : 0,
        );
    }

    /**
     * @return array{
     *   state:string, started_at:?string, finalized_at:?string,
     *   initiator_id:int,
     *   progress:array<string,array{total:int,done:int,failed:int,cursor:int}>,
     *   current_phase:?string, last_error:?string, total_batches:int
     * }
     */
    public static function new_idle_state(): array {
        $progress = array();
        foreach (self::PHASES as $phase) {
            $progress[ $phase ] = array(
                'total'  => 0,
                'done'   => 0,
                'failed' => 0,
                'cursor' => 0,
            );
        }
        return array(
            'state'         => self::STATE_IDLE,
            'started_at'    => null,
            'finalized_at'  => null,
            'initiator_id'  => 0,
            'progress'      => $progress,
            'current_phase' => null,
            'last_error'    => null,
            'total_batches' => 0,
        );
    }

    public static function current_state_name(): string {
        return self::get_state()['state'];
    }

    // ================================================================
    // generate_new_key: staging-фаза, создаёт .new.php с CB_ENCRYPTION_KEY_NEW
    // ================================================================

    /**
     * Генерирует staging-ключ и записывает его в .cashback-encryption-key.new.php.
     *
     * @return array{ok:bool,message:string,new_fingerprint?:string}
     */
    public static function generate_new_key(): array {
        $state = self::get_state();
        if ($state['state'] !== self::STATE_IDLE) {
            return array(
                'ok'      => false,
                'message' => 'Ротация уже идёт (текущее состояние: ' . $state['state'] . '). Новый ключ можно сгенерировать только из idle.',
            );
        }

        if (!Cashback_Encryption::is_configured()) {
            return array(
                'ok'      => false,
                'message' => 'Основной ключ (CB_ENCRYPTION_KEY) не сконфигурирован — ротация невозможна.',
            );
        }

        $new_path = self::get_new_key_path();
        if (file_exists($new_path)) {
            return array(
                'ok'      => false,
                'message' => 'Staging-файл ' . basename($new_path) . ' уже существует. Удалите его или отмените предыдущую ротацию.',
            );
        }

        $dir = dirname($new_path);
        if (!is_writable($dir)) {
            return array(
                'ok'      => false,
                'message' => 'Директория ' . $dir . ' недоступна для записи.',
            );
        }

        // Генерируем ключ с гарантией отличия от primary (коллизия random_bytes(32) практически невозможна,
        // но явная проверка дёшевая и защищает от теоретических ошибок ГПСЧ).
        $primary_hex = defined('CB_ENCRYPTION_KEY') ? (string) CB_ENCRYPTION_KEY : '';
        $new_hex     = '';
        for ($i = 0; $i < 5; $i++) {
            $candidate = bin2hex(random_bytes(32));
            if (!hash_equals($primary_hex, $candidate)) {
                $new_hex = $candidate;
                break;
            }
        }
        if ($new_hex === '' || strlen($new_hex) !== 64) {
            return array(
                'ok'      => false,
                'message' => 'Не удалось сгенерировать уникальный ключ (проблема с random_bytes).',
            );
        }

        $content = "<?php\n"
            . "/**\n"
            . " * Cashback Plugin — Encryption Key NEW (staging, auto-generated by Cashback_Key_Rotation).\n"
            . " *\n"
            . " * Этот файл существует только во время dual-key ротации. После finalize он\n"
            . " * переименовывается в основной (.cashback-encryption-key.php), основной становится\n"
            . " * .previous.php. После 7-дневного окна отката previous удаляется cron'ом.\n"
            . " *\n"
            . " * WARNING: Do not share, commit to VCS, or delete this file manually.\n"
            . " */\n"
            . "if (!defined('ABSPATH')) { exit; }\n"
            . "define('CB_ENCRYPTION_KEY_NEW', '" . $new_hex . "');\n";

        $written = file_put_contents($new_path, $content, LOCK_EX);
        if ($written === false) {
            return array(
                'ok'      => false,
                'message' => 'Не удалось записать файл staging-ключа.',
            );
        }

        if (function_exists('chmod')) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- chmod может выдавать warning на Windows; сбой прав некритичен, файл уже создан.
            @chmod($new_path, 0600);
        }

        // Загружаем константу в текущий процесс, чтобы get_fingerprint('new') работал сразу.
        if (!defined('CB_ENCRYPTION_KEY_NEW')) {
            define('CB_ENCRYPTION_KEY_NEW', $new_hex);
        }

        $new_fingerprint = Cashback_Encryption::get_fingerprint(Cashback_Encryption::KEY_ROLE_NEW);

        // Переход idle → staging.
        $next                 = self::new_idle_state();
        $next['state']        = self::STATE_STAGING;
        $next['started_at']   = current_time('mysql');
        $next['initiator_id'] = (int) ( function_exists('get_current_user_id') ? get_current_user_id() : 0 );
        self::save_state($next);

        try {
            Cashback_Encryption::write_audit_log(
                'key_rotation_generated',
                $next['initiator_id'],
                'key_rotation',
                null,
                array( 'new_fingerprint_prefix' => substr($new_fingerprint, 0, 16) )
            );
        } catch (\Throwable $e) {
            unset($e);
        }

        return array(
            'ok'              => true,
            'message'         => 'Новый ключ сгенерирован. Проверьте fingerprint и подтвердите запуск ротации.',
            'new_fingerprint' => $new_fingerprint,
        );
    }

    // ================================================================
    // count_*: подсчёт записей по каждой фазе (для total в progress)
    // ================================================================

    /**
     * Возвращает total-count для всех фаз. Используется в start_migration()
     * для инициализации progress перед первым batch-job'ом.
     *
     * @return array<string,int>  [phase => total]
     */
    public static function count_all_phases(): array {
        $counts = array();
        foreach (self::PHASES as $phase) {
            $counts[ $phase ] = self::count_phase($phase);
        }
        return $counts;
    }

    /**
     * Роутер подсчёта по имени фазы. Неизвестная фаза → 0.
     */
    public static function count_phase( string $phase ): int {
        switch ($phase) {
            case 'options_captcha':
                return self::count_options_captcha();
            case 'options_epn':
                return self::count_options_epn();
            case 'options_social':
                return self::count_options_social();
            case 'affiliate_networks':
                return self::count_affiliate_networks();
            case 'social_tokens':
                return self::count_social_tokens();
            case 'social_pending':
                return self::count_social_pending();
            case 'payout_requests':
                return self::count_payout_requests();
            case 'user_profile':
                return self::count_user_profile();
            default:
                return 0;
        }
    }

    /**
     * wp_options['cashback_captcha_server_key']: 1 если значение зашифровано helper'ом, иначе 0.
     * Plaintext-значения до миграции options_encrypted_v1 — не в скоупе ротации ключа.
     */
    private static function count_options_captcha(): int {
        $value = (string) get_option('cashback_captcha_server_key', '');
        return ( $value !== '' && Cashback_Encryption::is_option_ciphertext($value) ) ? 1 : 0;
    }

    /**
     * wp_options: все опции cashback_epn_refresh_* с непустым зашифрованным значением.
     */
    private static function count_options_epn(): int {
        global $wpdb;
        if (!is_object($wpdb)) {
            return 0;
        }
        $options_table = property_exists($wpdb, 'options') ? (string) $wpdb->options : 'wp_options';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count query for rotation progress.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s AND option_value <> \'\' AND option_value LIKE %s',
                $options_table,
                'cashback_epn_refresh_%',
                'ENC:v1:%'
            )
        );
        return (int) $count;
    }

    /**
     * cashback_social_provider_{yandex,vkid}: 1 за каждый provider, у которого
     * client_secret_encrypted непустой и начинается с version-префикса v1:/v2:
     * (т.е. прошёл через Cashback_Encryption::encrypt()).
     */
    private static function count_options_social(): int {
        $total = 0;
        foreach (array( 'cashback_social_provider_yandex', 'cashback_social_provider_vkid' ) as $option_name) {
            $raw = get_option($option_name, array());
            if (!is_array($raw)) {
                continue;
            }
            $secret = isset($raw['client_secret_encrypted']) ? (string) $raw['client_secret_encrypted'] : '';
            if ($secret === '') {
                continue;
            }
            if (strpos($secret, 'v2:') === 0 || strpos($secret, 'v1:') === 0) {
                ++$total;
            }
        }
        return $total;
    }

    private static function count_affiliate_networks(): int {
        global $wpdb;
        if (!is_object($wpdb)) {
            return 0;
        }
        $table = $wpdb->prefix . 'cashback_affiliate_networks';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count query for rotation progress.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE api_credentials IS NOT NULL AND api_credentials <> \'\'',
                $table
            )
        );
    }

    private static function count_social_tokens(): int {
        global $wpdb;
        if (!is_object($wpdb)) {
            return 0;
        }
        $table = $wpdb->prefix . 'cashback_social_tokens';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count query for rotation progress.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE refresh_token_encrypted <> \'\'',
                $table
            )
        );
    }

    /**
     * Только не потреблённые и не истёкшие записи: consumed pending-строки старше
     * суток чистятся cleanup_expired_pending() и не нуждаются в перешифровке.
     * Оставляем только «живые» pending — их payload понадобится при consume_pending().
     */
    private static function count_social_pending(): int {
        global $wpdb;
        if (!is_object($wpdb)) {
            return 0;
        }
        $table   = $wpdb->prefix . 'cashback_social_pending';
        $now_utc = gmdate('Y-m-d H:i:s');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count query for rotation progress.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE payload_json <> \'\' AND consumed_at IS NULL AND expires_at >= %s',
                $table,
                $now_utc
            )
        );
    }

    private static function count_payout_requests(): int {
        global $wpdb;
        if (!is_object($wpdb)) {
            return 0;
        }
        $table = $wpdb->prefix . 'cashback_payout_requests';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count query for rotation progress.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE encrypted_details IS NOT NULL AND encrypted_details <> \'\'',
                $table
            )
        );
    }

    private static function count_user_profile(): int {
        global $wpdb;
        if (!is_object($wpdb)) {
            return 0;
        }
        $table = $wpdb->prefix . 'cashback_user_profile';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count query for rotation progress.
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE encrypted_details IS NOT NULL AND encrypted_details <> \'\'',
                $table
            )
        );
    }

    // ================================================================
    // start_migration: staging → migrating, первый batch-job
    // ================================================================

    /**
     * Переводит state из staging в migrating, считает totals по всем фазам,
     * ставит первый AS batch-job. Требует слово-подтверждения $confirmation.
     *
     * Возвращает массив {ok, message} — вызывается из handle_start() и тестов.
     *
     * @return array{ok:bool,message:string,totals?:array<string,int>}
     */
    public static function start_migration( string $confirmation ): array {
        $state = self::get_state();

        if ($state['state'] !== self::STATE_STAGING) {
            return array(
                'ok'      => false,
                'message' => 'Нельзя запустить миграцию: состояние = ' . $state['state'] . ' (требуется staging).',
            );
        }

        if (!hash_equals(self::CONFIRMATION_START, $confirmation)) {
            return array(
                'ok'      => false,
                'message' => 'Неверное слово-подтверждение. Ожидается: ' . self::CONFIRMATION_START,
            );
        }

        if (!Cashback_Encryption::is_key_role_configured(Cashback_Encryption::KEY_ROLE_NEW)) {
            return array(
                'ok'      => false,
                'message' => 'Staging-ключ не загружен (CB_ENCRYPTION_KEY_NEW). Проверьте .cashback-encryption-key.new.php.',
            );
        }

        $totals = self::count_all_phases();

        // Заполняем totals в progress. done/failed/cursor остаются 0.
        $progress = array();
        foreach (self::PHASES as $phase) {
            $progress[ $phase ] = array(
                'total'  => isset($totals[ $phase ]) ? (int) $totals[ $phase ] : 0,
                'done'   => 0,
                'failed' => 0,
                'cursor' => 0,
            );
        }

        $next                  = $state;
        $next['state']         = self::STATE_MIGRATING;
        $next['progress']      = $progress;
        $next['current_phase'] = self::PHASES[0];
        $next['last_error']    = null;
        if (empty($next['started_at'])) {
            $next['started_at'] = current_time('mysql');
        }
        $next['initiator_id'] = (int) ( function_exists('get_current_user_id') ? get_current_user_id() : 0 );
        self::save_state($next);

        // Планируем первый batch — даже если totals=0 везде, batch прогонит фазы и сразу
        // переключится на sanity/state=migrated. Это дешевле, чем дублировать логику здесь.
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::AS_HOOK_MIGRATE, array(), self::AS_GROUP);
        }

        try {
            Cashback_Encryption::write_audit_log(
                'key_rotation_started',
                $next['initiator_id'],
                'key_rotation',
                null,
                array( 'counts' => $totals )
            );
        } catch (\Throwable $e) {
            unset($e);
        }

        return array(
            'ok'      => true,
            'message' => 'Миграция запущена. Фоновая перешифровка стартовала; прогресс доступен на этой странице.',
            'totals'  => $totals,
        );
    }

    // ================================================================
    // admin_post handlers
    // ================================================================

    public static function handle_generate(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }
        check_admin_referer(self::NONCE_GENERATE);

        $result = self::generate_new_key();
        self::redirect_with_flash($result['ok'] ? 'notice' : 'error', $result['message']);
    }

    public static function handle_start(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }
        check_admin_referer(self::NONCE_START);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer выше.
        $confirmation = isset($_POST['confirmation']) ? sanitize_text_field(wp_unslash((string) $_POST['confirmation'])) : '';

        $result = self::start_migration($confirmation);
        self::redirect_with_flash($result['ok'] ? 'notice' : 'error', $result['message']);
    }

    public static function handle_finalize(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }
        check_admin_referer(self::NONCE_FINALIZE);

        self::redirect_with_flash('error', 'finalize ещё не реализован (шаг 3.4 плана).');
    }

    public static function handle_rollback(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }
        check_admin_referer(self::NONCE_ROLLBACK);

        self::redirect_with_flash('error', 'rollback ещё не реализован (шаг 3.5 плана).');
    }

    public static function handle_abort(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }
        check_admin_referer(self::NONCE_ABORT);

        self::redirect_with_flash('error', 'abort ещё не реализован (шаг 3.6 плана).');
    }

    // ================================================================
    // run_migrate_batch: фазовый dispatcher (шаг 3.4 плана)
    // ================================================================

    /**
     * Обрабатывает один batch текущей фазы, обновляет progress, затем:
     *   — если в фазе остались записи (has_more=true) → re-schedule себя.
     *   — если фаза закончена → переключает current_phase на следующую, re-schedule себя.
     *   — если все фазы закончены → state → migrated (sanity-pass добавится в шаге 3.6).
     *
     * Idempotent по отношению к двойному вызову: каждый вызов читает state заново,
     * работа одного батча не зависит от state других процессов (cursor per phase).
     *
     * Per-phase updaters (run_phase_batch) пока stub'ы — реальные реализации в шаге 3.5.
     */
    public static function run_migrate_batch(): void {
        $state = self::get_state();

        if ($state['state'] !== self::STATE_MIGRATING) {
            // Гонка: state уже продвинут другим процессом или ротация отменена. Тихо выходим.
            return;
        }

        $phase = $state['current_phase'];
        if ($phase === null || !in_array($phase, self::PHASES, true)) {
            // Несогласованный state — current_phase должен быть выставлен в start_migration.
            // Самоисправляемся: ставим первую фазу и re-schedule.
            $state['current_phase'] = self::PHASES[0];
            self::save_state($state);
            self::reschedule_migrate();
            return;
        }

        $cursor      = (int) ( $state['progress'][ $phase ]['cursor'] ?? 0 );
        $batch_size  = self::batch_size_for_phase($phase);
        $phase_index = (int) array_search($phase, self::PHASES, true);

        try {
            $result = self::run_phase_batch($phase, $cursor, $batch_size);
        } catch (\Throwable $e) {
            // Фатальный сбой батча (например, БД-ошибка). Не перезапускаем; UI покажет last_error.
            $state['last_error'] = $e->getMessage();
            self::save_state($state);
            try {
                Cashback_Encryption::write_audit_log(
                    'key_rotation_batch_failed',
                    0,
                    'key_rotation',
                    null,
                    array(
						'phase' => $phase,
						'error' => $e->getMessage(),
					)
                );
            } catch (\Throwable $inner) {
                unset($inner);
            }
            return;
        }

        // Обновляем прогресс по результату.
        $processed                              = max(0, (int) ( $result['processed'] ?? 0 ));
        $failed                                 = max(0, (int) ( $result['failed'] ?? 0 ));
        $next_cursor                            = max(0, (int) ( $result['next_cursor'] ?? $cursor ));
        $has_more                               = (bool) ( $result['has_more'] ?? false );
        $state['progress'][ $phase ]['done']   += $processed;
        $state['progress'][ $phase ]['failed'] += $failed;
        $state['progress'][ $phase ]['cursor']  = $next_cursor;
        ++$state['total_batches'];

        // Audit: throttled batch-completed каждые 10 батчей.
        if (( $state['total_batches'] % 10 ) === 0) {
            try {
                Cashback_Encryption::write_audit_log(
                    'key_rotation_batch_completed',
                    0,
                    'key_rotation',
                    null,
                    array(
                        'phase'            => $phase,
                        'processed_so_far' => $state['progress'][ $phase ]['done'],
                        'total'            => $state['progress'][ $phase ]['total'],
                        'batches'          => $state['total_batches'],
                    )
                );
            } catch (\Throwable $e) {
                unset($e);
            }
        }

        // Фаза ещё не закончена → сохраняем и re-schedule.
        if ($has_more) {
            self::save_state($state);
            self::reschedule_migrate();
            return;
        }

        // Фаза закончена → audit + переключаем фазу.
        try {
            Cashback_Encryption::write_audit_log(
                'key_rotation_phase_completed',
                0,
                'key_rotation',
                null,
                array(
                    'phase'           => $phase,
                    'total_in_phase'  => $state['progress'][ $phase ]['total'],
                    'done_in_phase'   => $state['progress'][ $phase ]['done'],
                    'failed_in_phase' => $state['progress'][ $phase ]['failed'],
                )
            );
        } catch (\Throwable $e) {
            unset($e);
        }

        $next_index = $phase_index + 1;
        if ($next_index < count(self::PHASES)) {
            $state['current_phase'] = self::PHASES[ $next_index ];
            self::save_state($state);
            self::reschedule_migrate();
            return;
        }

        // Все фазы завершены. В шаге 3.6 здесь появится sanity-pass перед state=migrated.
        $state['state']         = self::STATE_MIGRATED;
        $state['current_phase'] = null;
        self::save_state($state);
    }

    /**
     * Роутер per-phase updater'ов. Каждый возвращает
     *   ['processed' => int, 'failed' => int, 'next_cursor' => int, 'has_more' => bool].
     *
     * 3.5a реализовал wp_options-фазы (captcha/epn/social). DB-таблицы остаются
     * под фильтр-fallback и будут реализованы в 3.5b.
     *
     * @return array{processed:int,failed:int,next_cursor:int,has_more:bool}
     */
    public static function run_phase_batch( string $phase, int $cursor, int $batch_size ): array {
        $default = array(
            'processed'   => 0,
            'failed'      => 0,
            'next_cursor' => (int) $cursor,
            'has_more'    => false,
        );

        if (!in_array($phase, self::PHASES, true)) {
            return $default;
        }

        // Диспатчим на реализованные фазы (3.5a: wp_options). Оставшиеся фазы (3.5b)
        // пока идут через фильтр-fallback — для тестов и кастомного расширения.
        switch ($phase) {
            case 'options_captcha':
                $result = self::run_phase_options_captcha();
                break;
            case 'options_epn':
                $result = self::run_phase_options_epn($cursor, $batch_size);
                break;
            case 'options_social':
                $result = self::run_phase_options_social($cursor);
                break;
            default:
                $result = apply_filters(
                    'cashback_key_rotation_phase_batch_result',
                    $default,
                    $phase,
                    $cursor,
                    $batch_size
                );
                break;
        }

        if (!is_array($result)) {
            return $default;
        }
        return array(
            'processed'   => isset($result['processed'])   ? max(0, (int) $result['processed'])   : 0,
            'failed'      => isset($result['failed'])      ? max(0, (int) $result['failed'])      : 0,
            'next_cursor' => isset($result['next_cursor']) ? max(0, (int) $result['next_cursor']) : (int) $cursor,
            'has_more'    => isset($result['has_more'])    ? (bool) $result['has_more']           : false,
        );
    }

    // ================================================================
    // Per-phase updaters: wp_options (3.5a)
    // ================================================================

    /**
     * Ротация `cashback_captcha_server_key`: одна опция, один батч, has_more=false.
     * Транзакция + SELECT ... FOR UPDATE на option_name защищает от TOCTOU с admin-save.
     *
     * @return array{processed:int,failed:int,next_cursor:int,has_more:bool}
     */
    private static function run_phase_options_captcha(): array {
        $result = self::rotate_single_option('cashback_captcha_server_key', 'options_captcha');
        return array(
            'processed'   => $result['processed'],
            'failed'      => $result['failed'],
            'next_cursor' => 0,
            'has_more'    => false,
        );
    }

    /**
     * Ротация EPN refresh-токенов (`cashback_epn_refresh_<md5(client_id)>`).
     * Cursor по option_id — один батч обрабатывает до $batch_size строк.
     *
     * @return array{processed:int,failed:int,next_cursor:int,has_more:bool}
     */
    private static function run_phase_options_epn( int $cursor, int $batch_size ): array {
        global $wpdb;
        if (!is_object($wpdb)) {
            return array(
                'processed'   => 0,
                'failed'      => 0,
                'next_cursor' => $cursor,
                'has_more'    => false,
            );
        }
        $options_table = property_exists($wpdb, 'options') ? (string) $wpdb->options : 'wp_options';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paginate EPN refresh tokens for rotation.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT option_id, option_name FROM %i WHERE option_name LIKE %s AND option_value <> \'\' AND option_id > %d ORDER BY option_id LIMIT %d',
                $options_table,
                'cashback_epn_refresh_%',
                $cursor,
                $batch_size
            )
        );

        if (!is_array($rows) || count($rows) === 0) {
            return array(
                'processed'   => 0,
                'failed'      => 0,
                'next_cursor' => $cursor,
                'has_more'    => false,
            );
        }

        $processed   = 0;
        $failed      = 0;
        $next_cursor = $cursor;
        foreach ($rows as $row) {
            $option_id   = (int) ( is_object($row) ? ( $row->option_id ?? 0 ) : ( $row['option_id'] ?? 0 ) );
            $option_name = (string) ( is_object($row) ? ( $row->option_name ?? '' ) : ( $row['option_name'] ?? '' ) );
            if ($option_id <= 0 || $option_name === '') {
                continue;
            }
            $next_cursor = max($next_cursor, $option_id);

            $result     = self::rotate_single_option($option_name, 'options_epn');
            $processed += $result['processed'];
            $failed    += $result['failed'];
        }

        return array(
            'processed'   => $processed,
            'failed'      => $failed,
            'next_cursor' => $next_cursor,
            'has_more'    => count($rows) >= $batch_size,
        );
    }

    /**
     * Ротация `cashback_social_provider_{yandex,vkid}`: два wp_option с serialize-массивом,
     * где ротируется только `client_secret_encrypted`. Cursor 0 → yandex, 1 → vkid, 2 → готово.
     *
     * @return array{processed:int,failed:int,next_cursor:int,has_more:bool}
     */
    private static function run_phase_options_social( int $cursor ): array {
        $providers = array( 'cashback_social_provider_yandex', 'cashback_social_provider_vkid' );
        if ($cursor < 0 || $cursor >= count($providers)) {
            return array(
                'processed'   => 0,
                'failed'      => 0,
                'next_cursor' => $cursor,
                'has_more'    => false,
            );
        }

        $option_name = $providers[ $cursor ];
        $result      = self::rotate_social_provider_option($option_name);
        $next_cursor = $cursor + 1;

        return array(
            'processed'   => $result['processed'],
            'failed'      => $result['failed'],
            'next_cursor' => $next_cursor,
            'has_more'    => $next_cursor < count($providers),
        );
    }

    // ================================================================
    // Helpers для wp_options-updater'ов
    // ================================================================

    /**
     * Rotates a single wp_option value that uses ENC:v1:-prefix (captcha, EPN).
     * Wraps SELECT ... FOR UPDATE + UPDATE + wp_cache_delete in a transaction.
     * Не-ciphertext значения (plaintext до миграции options_encrypted_v1) пропускаются.
     *
     * @return array{processed:int,failed:int}
     */
    private static function rotate_single_option( string $option_name, string $audit_phase ): array {
        global $wpdb;
        if (!is_object($wpdb)) {
            return array(
				'processed' => 0,
				'failed'    => 0,
			);
        }
        $options_table = property_exists($wpdb, 'options') ? (string) $wpdb->options : 'wp_options';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction begin for row-lock.
        $wpdb->query('START TRANSACTION');
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SELECT FOR UPDATE inside transaction.
            $raw = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE',
                    $options_table,
                    $option_name
                )
            );

            if ($raw === null || $raw === '' || !Cashback_Encryption::is_option_ciphertext((string) $raw)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Close transaction without changes.
                $wpdb->query('COMMIT');
                return array(
					'processed' => 0,
					'failed'    => 0,
				);
            }

            $rotated = Cashback_Encryption::rotate_option_ciphertext((string) $raw);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update locked row inside transaction.
            $updated = $wpdb->update(
                $options_table,
                array( 'option_value' => $rotated ),
                array( 'option_name' => $option_name ),
                array( '%s' ),
                array( '%s' )
            );
            if ($updated === false) {
                throw new \RuntimeException('UPDATE failed for option ' . $option_name);
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit transaction.
            $wpdb->query('COMMIT');

            if (function_exists('wp_cache_delete')) {
                wp_cache_delete($option_name, 'options');
            }

            return array(
				'processed' => 1,
				'failed'    => 0,
			);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback on error.
            $wpdb->query('ROLLBACK');
            self::record_item_failure($audit_phase, $option_name, $e->getMessage());
            return array(
				'processed' => 0,
				'failed'    => 1,
			);
        }
    }

    /**
     * Ротация JSON-массива в wp_option `cashback_social_provider_*`: field
     * `client_secret_encrypted` содержит raw v2:/v1:-ciphertext без ENC:v1: префикса
     * (см. class-cashback-social-admin.php::sanitize_provider_settings).
     *
     * @return array{processed:int,failed:int}
     */
    private static function rotate_social_provider_option( string $option_name ): array {
        global $wpdb;
        if (!is_object($wpdb)) {
            return array(
				'processed' => 0,
				'failed'    => 0,
			);
        }
        $options_table = property_exists($wpdb, 'options') ? (string) $wpdb->options : 'wp_options';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction begin.
        $wpdb->query('START TRANSACTION');
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock row before modify.
            $raw = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE',
                    $options_table,
                    $option_name
                )
            );

            if ($raw === null || $raw === '') {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Nothing to update.
                $wpdb->query('COMMIT');
                return array(
					'processed' => 0,
					'failed'    => 0,
				);
            }

            $value = maybe_unserialize((string) $raw);
            if (!is_array($value)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Not an array payload; skip.
                $wpdb->query('COMMIT');
                return array(
					'processed' => 0,
					'failed'    => 0,
				);
            }

            $secret = isset($value['client_secret_encrypted']) ? (string) $value['client_secret_encrypted'] : '';
            if ($secret === '' || ( strpos($secret, 'v2:') !== 0 && strpos($secret, 'v1:') !== 0 )) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Nothing encrypted here.
                $wpdb->query('COMMIT');
                return array(
					'processed' => 0,
					'failed'    => 0,
				);
            }

            $value['client_secret_encrypted'] = Cashback_Encryption::rotate_value($secret);
            $new_raw                          = maybe_serialize($value);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update inside transaction.
            $updated = $wpdb->update(
                $options_table,
                array( 'option_value' => $new_raw ),
                array( 'option_name' => $option_name ),
                array( '%s' ),
                array( '%s' )
            );
            if ($updated === false) {
                throw new \RuntimeException('UPDATE failed for social provider ' . $option_name);
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit.
            $wpdb->query('COMMIT');

            if (function_exists('wp_cache_delete')) {
                wp_cache_delete($option_name, 'options');
            }

            return array(
				'processed' => 1,
				'failed'    => 0,
			);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback on error.
            $wpdb->query('ROLLBACK');
            self::record_item_failure('options_social', $option_name, $e->getMessage());
            return array(
				'processed' => 0,
				'failed'    => 1,
			);
        }
    }

    /**
     * Audit-запись `key_rotation_item_failed` для одной записи. Fail-safe: swallow
     * ошибки аудита, чтобы диагностика сама не уронила батч.
     */
    private static function record_item_failure( string $phase, string $entity_id, string $reason ): void {
        try {
            Cashback_Encryption::write_audit_log(
                'key_rotation_item_failed',
                0,
                'key_rotation',
                null,
                array(
                    'phase'     => $phase,
                    'entity_id' => $entity_id,
                    'reason'    => $reason,
                )
            );
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Batch size для каждой фазы (из констант класса + per-phase override).
     */
    public static function batch_size_for_phase( string $phase ): int {
        switch ($phase) {
            case 'user_profile':
                return self::BATCH_SIZE_PROFILES;
            case 'payout_requests':
                return self::BATCH_SIZE_PAYOUTS;
            case 'options_captcha':
            case 'options_epn':
            case 'options_social':
            case 'affiliate_networks':
            case 'social_tokens':
            case 'social_pending':
            default:
                return self::BATCH_SIZE_SMALL;
        }
    }

    /**
     * Ставит следующий batch в AS. Тонкая обёртка, вынесена чтобы не дублировать
     * guard на function_exists('as_enqueue_async_action') в dispatcher.
     */
    private static function reschedule_migrate(): void {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::AS_HOOK_MIGRATE, array(), self::AS_GROUP);
        }
    }

    public static function run_rollback_batch(): void {
        // TODO: шаг 3.5 плана — обратная перешифровка.
    }

    public static function cleanup_previous_key(): void {
        // TODO: шаг 3.6 плана — удаление .previous.php после окна отката.
    }

    // ================================================================
    // AJAX status (заглушка — UI polling)
    // ================================================================

    public static function ajax_status(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array( 'message' => 'Forbidden' ), 403);
        }
        check_ajax_referer(self::NONCE_STATUS);

        wp_send_json_success(array(
            'state' => self::get_state(),
        ));
    }

    // ================================================================
    // Admin page (голый скелет)
    // ================================================================

    public static function register_admin_page(): void {
        add_submenu_page(
            self::PARENT_MENU_SLUG,
            esc_html__('Ротация ключа шифрования', 'cashback-plugin'),
            esc_html__('Ротация ключа', 'cashback-plugin'),
            'manage_options',
            self::ADMIN_PAGE_SLUG,
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав.', 'cashback-plugin'));
        }

        $view = plugin_dir_path(__FILE__) . 'partials/key-rotation-page.php';
        if (file_exists($view)) {
            $state   = self::get_state();
            $fp_old  = Cashback_Encryption::get_fingerprint(Cashback_Encryption::KEY_ROLE_PRIMARY);
            $fp_new  = Cashback_Encryption::get_fingerprint(Cashback_Encryption::KEY_ROLE_NEW);
            $fp_prev = Cashback_Encryption::get_fingerprint(Cashback_Encryption::KEY_ROLE_PREVIOUS);
            // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- $view собран из plugin_dir_path + статической строки; без пользовательского ввода.
            include $view;
            return;
        }

        echo '<div class="wrap"><h1>' . esc_html__('Ротация ключа шифрования', 'cashback-plugin') . '</h1>';
        echo '<p>' . esc_html__('View-шаблон ещё не создан. Смотри шаг 3.8 плана.', 'cashback-plugin') . '</p></div>';
    }

    // ================================================================
    // Flash-сообщения через transients (простой способ без сессий)
    // ================================================================

    /**
     * Редирект обратно на admin-страницу с flash-сообщением.
     *
     * @param string $level   'notice' | 'error'
     */
    private static function redirect_with_flash( string $level, string $message ): void {
        $transient_key = 'cashback_rotation_flash_' . (int) get_current_user_id();
        set_transient($transient_key, array(
			'level'   => $level,
			'message' => $message,
		), 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::ADMIN_PAGE_SLUG));
        exit;
    }

    /**
     * Считывает и сбрасывает flash-сообщение (для рендера во view-шаблоне).
     *
     * @return array{level:string,message:string}|null
     */
    public static function consume_flash(): ?array {
        $key   = 'cashback_rotation_flash_' . (int) get_current_user_id();
        $flash = get_transient($key);
        if (!is_array($flash) || !isset($flash['level'], $flash['message'])) {
            return null;
        }
        delete_transient($key);
        return array(
            'level'   => (string) $flash['level'],
            'message' => (string) $flash['message'],
        );
    }
}
