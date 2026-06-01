<?php

/**
 * Универсальный API-клиент для CPA-сетей
 *
 * Фасад, делегирующий сетевую специфику адаптерам (Cashback_Network_Adapter_Interface).
 * Встроенные адаптеры: Admitad, EPN. Расширяемо через register_adapter() или хук cashback_register_network_adapters.
 * Хранит credentials зашифрованными через Cashback_Encryption.
 *
 * Стратегия reconciliation (индустриальный стандарт кэшбэк-сервисов):
 *   МАТЧИНГ:    API.subid1 == DB.click_id (UUID, генерируемый кэшбэк-сервисом)
 *   СРАВНЕНИЕ:  status, payment/comission, cart/sum_order
 *   ФИЛЬТРАЦИЯ: API.subid2 == DB.user_id
 *   ЛОГИРОВАНИЕ: action_id (для lost order claims), order_id (для поддержки)
 *
 * @package CashbackPlugin
 * @since   6.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_API_Client {

    /** @var self|null */
    private static ?self $instance = null;

    /** @var string Таблица сетей */
    private string $networks_table;

    /** @var string Таблица чекпоинтов */
    private string $checkpoints_table;

    /** @var string Таблица транзакций */
    private string $transactions_table;

    /** @var string Таблица незарегистрированных транзакций */
    private string $unregistered_table;

    /** @var string Таблица синк-логов */
    private string $sync_log_table;

    /** @var array<string, Cashback_Network_Adapter_Interface> Реестр адаптеров (slug => adapter) */
    private array $adapters = array();

    /**
     * @return self
     */
    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->networks_table     = $wpdb->prefix . 'cashback_affiliate_networks';
        $this->checkpoints_table  = $wpdb->prefix . 'cashback_validation_checkpoints';
        $this->transactions_table = $wpdb->prefix . 'cashback_transactions';
        $this->unregistered_table = $wpdb->prefix . 'cashback_unregistered_transactions';
        $this->sync_log_table     = $wpdb->prefix . 'cashback_sync_log';

        // Регистрация встроенных адаптеров CPA-сетей
        $this->register_adapter(new Cashback_Admitad_Adapter());
        $this->register_adapter(new Cashback_Epn_Adapter());
        $this->register_adapter(new Cashback_Advcake_Adapter());

        /**
         * Позволяет внешним плагинам регистрировать свои адаптеры CPA-сетей.
         *
         * @param Cashback_API_Client $client Экземпляр API-клиента
         */
        do_action('cashback_register_network_adapters', $this);
    }

    // =========================================================================
    // Adapter registry
    // =========================================================================

    /**
     * Зарегистрировать адаптер CPA-сети
     *
     * @param Cashback_Network_Adapter_Interface $adapter
     */
    public function register_adapter( Cashback_Network_Adapter_Interface $adapter ): void {
        $this->adapters[ $adapter->get_slug() ] = $adapter;

        foreach ($adapter->get_aliases() as $alias) {
            if (!isset($this->adapters[ $alias ])) {
                $this->adapters[ $alias ] = $adapter;
            }
        }
    }

    /**
     * Получить адаптер по slug сети
     *
     * @param string $slug Slug сети (admitad, epn и др.)
     * @return Cashback_Network_Adapter_Interface|null
     */
    public function get_adapter( string $slug ): ?Cashback_Network_Adapter_Interface {
        return $this->adapters[ $slug ] ?? null;
    }

    /**
     * Проверить, зарегистрирован ли адаптер для сети
     *
     * @param string $slug
     * @return bool
     */
    public function has_adapter( string $slug ): bool {
        return isset($this->adapters[ $slug ]);
    }

    // =========================================================================
    // Credentials management
    // =========================================================================

    /**
     * Сохранить API credentials для сети (зашифрованные)
     *
     * @param int   $network_id ID сети
     * @param array $credentials ['client_id' => ..., 'client_secret' => ..., ...]
     * @return bool
     */
    public function save_credentials( int $network_id, array $credentials ): bool {
        global $wpdb;

        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: Encryption not configured');
            return false;
        }

        $json = wp_json_encode($credentials);
        if (false === $json) {
            return false;
        }

        // Row-lock TX: encrypt происходит ПОД удержанием FOR UPDATE на строке сети.
        // Закрывает TOCTOU-race с batch-job'ом ротации (фаза affiliate_networks):
        // write-key выбирается под lock'ом, batch не может одновременно перешифровать
        // эту же запись.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Row-lock TX begin.
        $wpdb->query('START TRANSACTION');

        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SELECT FOR UPDATE inside TX.
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT id FROM %i WHERE id = %d FOR UPDATE',
                    $this->networks_table,
                    $network_id
                )
            );

            if ($existing === null) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback unknown id.
                $wpdb->query('ROLLBACK');
                return false;
            }

            $encrypted = Cashback_Encryption::encrypt($json);
            if (false === $encrypted) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback encrypt failure.
                $wpdb->query('ROLLBACK');
                return false;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE locked row.
            $result = $wpdb->update(
                $this->networks_table,
                array( 'api_credentials' => $encrypted ),
                array( 'id' => $network_id ),
                array( '%s' ),
                array( '%d' )
            );

            if ($result === false) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback UPDATE failure.
                $wpdb->query('ROLLBACK');
                return false;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit.
            $wpdb->query('COMMIT');
            return true;
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback.
            $wpdb->query('ROLLBACK');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: save_credentials error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Проверяет, есть ли у сети зашифрованные credentials, которые
     * нельзя расшифровать текущими активными ключами. true означает:
     * строка в БД есть, api_credentials не пустые, но decrypt() вернул null.
     * Используется admin-UI для показа уведомления «требуется повторный ввод».
     *
     * @param int $network_id
     * @return bool
     */
    public function has_undecryptable_credentials( int $network_id ): bool {
        global $wpdb;
        $has_data = (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM %i WHERE id = %d AND api_credentials IS NOT NULL AND api_credentials <> ''",
                $this->networks_table,
                $network_id
            )
        );
        if (!$has_data) {
            return false;
        }
        return $this->get_credentials($network_id) === null;
    }

    /**
     * Получить расшифрованные credentials сети.
     *
     * Контракт: НИКОГДА не кидает. При невозможности расшифровать
     * (ключ ротирован/утерян, данные битые) — возвращает null + пишет
     * причину в error_log. Это единая точка защиты: десятки callers
     * (/activate, validate_user, check_campaigns, background_sync,
     * sync_update_local, render_settings_tab и т.д.) получают null
     * и обрабатывают его как «credentials не настроены» — никаких 500.
     *
     * @param int $network_id
     * @return array|null
     */
    public function get_credentials( int $network_id ): ?array {
        global $wpdb;

        $encrypted = $wpdb->get_var($wpdb->prepare(
            'SELECT api_credentials FROM %i WHERE id = %d',
            $this->networks_table,
            $network_id
        ));

        if (empty($encrypted)) {
            return null;
        }

        if (!class_exists('Cashback_Encryption') || !Cashback_Encryption::is_configured()) {
            return null;
        }

        try {
            $json = Cashback_Encryption::decrypt($encrypted);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback API Client] decrypt failed for network_id=' . $network_id . ': ' . $e->getMessage() . ' — treating as missing credentials.');
            return null;
        }

        if (false === $json) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Получить конфигурацию сети
     *
     * @param string $slug Slug сети (admitad, epn)
     * @return array|null
     */
    public function get_network_config( string $slug ): ?array {
        global $wpdb;

        $network = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE slug = %s AND is_active = 1',
            $this->networks_table,
            $slug
        ), ARRAY_A);

        if (!$network) {
            return null;
        }

        // Расшифровать credentials
        if (!empty($network['api_credentials'])) {
            $creds                  = $this->get_credentials((int) $network['id']);
            $network['credentials'] = $creds;
        }

        // Парсим маппинг статусов
        if (!empty($network['api_status_map'])) {
            $network['status_map'] = json_decode($network['api_status_map'], true) ?: array();
        } else {
            $network['status_map'] = $this->get_default_status_map($slug);
        }

        // Парсим маппинг полей API → локальные колонки
        $network['field_map'] = $this->get_field_map($network);

        return $network;
    }

    /**
     * Получить все активные сети с API-конфигурацией
     *
     * @return array
     */
    public function get_all_active_networks(): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, slug, api_base_url, api_user_field, api_click_field, api_website_id, api_status_map, is_active
                 FROM %i
                 WHERE is_active = 1 AND api_base_url IS NOT NULL AND api_base_url != ''
                 ORDER BY sort_order, name",
                $this->networks_table
            ),
            ARRAY_A
        ) ?: array();
    }

    /**
     * Маппинг статусов по умолчанию (делегация адаптеру)
     */
    private function get_default_status_map( string $slug ): array {
        $adapter = $this->get_adapter($slug);
        if ($adapter) {
            return $adapter->get_default_status_map();
        }

        // Общий fallback для сетей без адаптера
        return array(
            'pending'  => 'waiting',
            'approved' => 'completed',
            'declined' => 'declined',
        );
    }

    // =========================================================================
    // Field map helpers
    // =========================================================================

    /**
     * Дефолтный маппинг полей API → колонки транзакций
     */
    private const DEFAULT_FIELD_MAP = array(
        'payment'          => 'comission',
        'cart'             => 'sum_order',
        'action_id'        => 'uniq_id',
        'order_id'         => 'order_number',
        'advcampaign_id'   => 'offer_id',
        'advcampaign_name' => 'offer_name',
    );

    /**
     * Допустимые колонки транзакций для маппинга (whitelist)
     */
    private const ALLOWED_LOCAL_COLUMNS = array(
        'comission',
        'sum_order',
        'uniq_id',
        'order_number',
        'offer_id',
        'offer_name',
        'currency',
        'action_date',
        'click_time',
        'action_type',
        'website_id',
        'funds_ready',
        'decline_reason',
    );

    /**
     * Получить маппинг полей из конфига сети (мерж с дефолтом)
     *
     * Пользовательский маппинг дополняет дефолтный:
     * - Настроенные поля перезаписывают дефолтные (по локальной колонке)
     * - Ненастроенные колонки берутся из DEFAULT_FIELD_MAP
     * - Это гарантирует, что все обязательные колонки (uniq_id, comission и т.д.)
     *   всегда имеют маппинг, даже если админ настроил только часть полей
     *
     * @param array $config Конфиг сети (строка из cashback_affiliate_networks)
     * @return array<string, string> API field → local column
     */
    private function get_field_map( array $config ): array {
        if (!empty($config['api_field_map'])) {
            $raw = $config['api_field_map'];
            $map = is_string($raw) ? json_decode($raw, true) : ( is_array($raw) ? $raw : null );
            if (is_array($map) && !empty($map)) {
                // Фильтруем: допускаем только валидные локальные колонки
                $filtered = array();
                foreach ($map as $api_field => $local_col) {
                    $api_field = trim((string) $api_field);
                    $local_col = trim((string) $local_col);
                    if ($api_field !== '' && in_array($local_col, self::ALLOWED_LOCAL_COLUMNS, true)) {
                        $filtered[ $api_field ] = $local_col;
                    }
                }

                if (!empty($filtered)) {
                    // Мержим с дефолтом: для каждой дефолтной колонки,
                    // если она не покрыта пользовательским маппингом — добавляем дефолт
                    $covered_columns = array_values($filtered);
                    foreach (self::DEFAULT_FIELD_MAP as $def_api => $def_col) {
                        if (!in_array($def_col, $covered_columns, true)) {
                            $filtered[ $def_api ] = $def_col;
                        }
                    }
                    return $filtered;
                }
            }
        }

        return self::DEFAULT_FIELD_MAP;
    }

    /**
     * Извлечь значения из API action по маппингу полей
     *
     * Возвращает массив ['local_column' => value, ...] на основе field_map.
     * Для полей, отсутствующих в action, устанавливает дефолты.
     *
     * @param array $action  Нормализованный ответ API (после адаптера)
     * @param array $field_map Маппинг API field → local column
     * @return array<string, mixed> local_column → value
     */
    private function apply_field_map( array $action, array $field_map ): array {
        $result = array();

        foreach ($field_map as $api_field => $local_col) {
            $value = $action[ $api_field ] ?? null;

            // Приведение типов по колонке
            switch ($local_col) {
                case 'comission':
                case 'sum_order':
                    $result[ $local_col ] = (float) ( $value ?? 0 );
                    break;
                case 'offer_id':
                case 'website_id':
                    $result[ $local_col ] = ( $value !== null && $value !== '' ) ? (int) $value : null;
                    break;
                default:
                    $result[ $local_col ] = (string) ( $value ?? '' );
                    break;
            }
        }

        return $result;
    }

    /**
     * Обратный поиск: по имени локальной колонки найти имя поля в API
     *
     * @param string $local_column Колонка в таблице транзакций
     * @param array  $field_map    Маппинг API field → local column
     * @return string Имя поля API (или пустая строка)
     */
    private function api_field_for( string $local_column, array $field_map ): string {
        $flipped = array_flip($field_map);
        return (string) ( $flipped[ $local_column ] ?? '' );
    }

    /**
     * УНИВЕРСАЛЬНЫЙ резолвер идентичности транзакции (exactly-once).
     *
     * Детерминированный, чистый, BYTE-IDENTICAL с Python-зеркалом
     * `app/identity.py::resolve_uniq_id` в webhook-receiver. Любое расхождение
     * между этими двумя реализациями = silent dup/loss, поэтому контракт
     * закреплён shared-фикстурой development/test/fixtures/dedup-vectors.json
     * (PHP + Python гоняют один и тот же файл).
     *
     * Порядок разрешения:
     *   1. Native id — если значение, замапленное в `uniq_id`, непустое, и сеть
     *      не помечена `has_native_action_id:false` → вернуть как есть. Это
     *      сохраняет текущее поведение Admitad/Advcake/EPN и гарантирует
     *      webhook==XML паритет (обе стороны мапят один логический источник).
     *   2. Синтетика — только если native пуст И `has_native_action_id===false`:
     *      'syn_' . sha1( lower(slug) | <synthetic_fields по порядку> [ | click_id ] ).
     *      Стабильные поля only (НЕ status/amount/date) — status-change
     *      ре-постбэк обязан резолвиться в ТОТ ЖЕ id, иначе дубль.
     *   3. Нет идентичности — вернуть ['', 'no_dedup_inputs']; вызывающий код
     *      отправляет в DLQ / возвращает ошибку. НИКОГДА не вставляет.
     *
     * @param string     $slug           Slug сети (канонизируется lower).
     * @param string     $native_uniq_id Значение, замапленное в колонку uniq_id.
     * @param array      $fields         Канонические поля для синтетики:
     *                                   ['order_number'=>?, 'offer_id'=>?,
     *                                    'action_type'=>?, 'click_id'=>?].
     * @param array|null $dedup_identity  Контракт идентичности сети (колонка
     *                                    cashback_affiliate_networks.dedup_identity);
     *                                    null == has_native_action_id:true (legacy).
     * @return array{0:string,1:?string} [uniq_id, reason|null].
     */
    public static function resolve_uniq_id(
        string $slug,
        string $native_uniq_id,
        array $fields,
        ?array $dedup_identity
    ): array {
        $native = trim($native_uniq_id);

        // null контракт == legacy: считаем что native id есть (поведение до v16).
        $has_native = ($dedup_identity === null)
            ? true
            : ( ($dedup_identity['has_native_action_id'] ?? true) !== false );

        if ($native !== '') {
            return array( $native, null );
        }

        if ($has_native === true) {
            // Native ожидался, но отсутствует — дедупнуть нечем, в DLQ.
            return array( '', 'no_dedup_inputs' );
        }

        // ─── Синтетическая ветка (has_native_action_id === false) ───
        $synthetic_fields = $dedup_identity['synthetic_fields'] ?? null;
        if (!is_array($synthetic_fields) || $synthetic_fields === array()) {
            $synthetic_fields = array( 'order_number', 'offer_id', 'action_type' );
        }
        $include_click = ( ($dedup_identity['synthetic_include_click_id'] ?? false) === true );

        $components = array();
        $all_empty  = true;
        foreach ($synthetic_fields as $fname) {
            $val = trim( (string) ( $fields[ (string) $fname ] ?? '' ) );
            if ($val !== '') {
                $all_empty = false;
            }
            $components[] = $val;
        }
        if ($include_click) {
            $val = trim( (string) ( $fields['click_id'] ?? '' ) );
            if ($val !== '') {
                $all_empty = false;
            }
            $components[] = $val;
        }

        if ($all_empty) {
            return array( '', 'no_dedup_inputs' );
        }

        array_unshift($components, strtolower(trim($slug)));

        return array( 'syn_' . sha1( implode('|', $components) ), null );
    }

    /**
     * Декодировать контракт идентичности сети из $config (колонка
     * cashback_affiliate_networks.dedup_identity). NULL/невалидный JSON ==
     * legacy native-id (резолвер деградирует к текущему поведению).
     */
    private function dedup_identity_for( array $config ): ?array {
        $raw = $config['dedup_identity'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Разрешить идентичность action'а из API/XML через УНИВЕРСАЛЬНЫЙ резолвер —
     * та же логика что у webhook-receiver (app/identity.py), поэтому cron
     * insert_missing_transaction и realtime-webhook производят ОДИН И ТОТ ЖЕ
     * uniq_id для одного логического действия (включая синтетический syn_…
     * для direct-партнёров без native id). Для встроенных сетей резолвер —
     * verbatim passthrough native-значения (поведение не меняется).
     *
     * @return string Разрешённый uniq_id ('' = нет идентичности → caller skip).
     */
    private function resolve_action_identity( array $config, array $action ): string {
        $field_map   = $config['field_map'] ?? array();
        $fm_uniq     = $this->api_field_for('uniq_id', $field_map) ?: 'action_id';
        $fm_order    = $this->api_field_for('order_number', $field_map) ?: 'order_id';
        $fm_offer    = $this->api_field_for('offer_id', $field_map) ?: 'advcampaign_id';
        $click_field = $config['api_click_field'] ?? 'subid1';

        [$id] = self::resolve_uniq_id(
            (string) ( $config['slug'] ?? '' ),
            (string) ( $action[ $fm_uniq ] ?? '' ),
            array(
                'order_number' => (string) ( $action[ $fm_order ] ?? '' ),
                'offer_id'     => (string) ( $action[ $fm_offer ] ?? '' ),
                'action_type'  => (string) ( $action['action_type'] ?? '' ),
                'click_id'     => (string) ( $action[ $click_field ] ?? '' ),
            ),
            $this->dedup_identity_for($config)
        );

        return $id;
    }

    /**
     * Определить значение funds_ready из API-action.
     *
     * Порядок: field_map → адаптер (funds_ready) → Admitad (processed).
     *
     * @param array $action    Нормализованный action из API
     * @param array $field_map Маппинг полей
     * @return int 0 или 1
     */
    private function resolve_funds_ready( array $action, array $field_map ): int {
        $fm_funds_ready = $this->api_field_for('funds_ready', $field_map);
        if ($fm_funds_ready !== '') {
            return !empty($action[ $fm_funds_ready ]) ? 1 : 0;
        }
        if (isset($action['funds_ready'])) {
            return (int) $action['funds_ready'];
        }
        if (isset($action['processed'])) {
            return empty($action['processed']) ? 0 : 1;
        }
        return 0;
    }

    /**
     * Определить причину отказа из action только для итогового declined.
     *
     * Поддерживает field_map (любой API field → decline_reason), затем
     * каноничные fallback'и: Admitad `comment`, Advcake `reason`.
     */
    private function resolve_decline_reason( array $action, array $field_map, string $mapped_status ): ?string {
        if ($mapped_status !== 'declined') {
            return null;
        }

        $candidate_fields = array();
        $fm_reason        = $this->api_field_for('decline_reason', $field_map);
        if ($fm_reason !== '') {
            $candidate_fields[] = $fm_reason;
        }
        $candidate_fields[] = 'comment';
        $candidate_fields[] = 'reason';
        $candidate_fields   = array_values(array_unique($candidate_fields));
        foreach ($candidate_fields as $field) {
            if (!array_key_exists($field, $action)) {
                continue;
            }
            $reason = trim((string) $action[ $field ]);
            if ($reason === '') {
                continue;
            }
            return mb_substr($reason, 0, 2000);
        }

        return null;
    }

    /**
     * Нормализовать окно фоновой синхронизации под конкретную CPA-сеть.
     */
    private function build_background_sync_params( string $slug, array $config, string $date_start_dmy, string $date_end_dmy ): array {
        $adapter = $this->get_adapter($slug);
        if ($adapter instanceof Cashback_Advcake_Adapter) {
            $from = DateTime::createFromFormat('!d.m.Y', $date_start_dmy);
            $to   = DateTime::createFromFormat('!d.m.Y', $date_end_dmy);

            return array(
                'update_from' => $from instanceof DateTime ? $from->format('Y-m-d') : gmdate('Y-m-d', strtotime('-7 days')),
                'update_to'   => $to instanceof DateTime ? $to->format('Y-m-d') : gmdate('Y-m-d'),
            );
        }

        // Admitad и совместимые API: статусное окно + широкий date-range.
        return array(
            'status_updated_start' => $date_start_dmy . ' 00:00:00',
            'status_updated_end'   => $date_end_dmy . ' 23:59:59',
            'date_start'           => $this->default_lookback_date_dmy(),
            'date_end'             => $date_end_dmy,
        );
    }

    // =========================================================================
    // Website (площадка) scoping — defense-in-depth поверх API-параметра website
    // =========================================================================

    /**
     * Сохранённый в настройках сети id площадки (trimmed).
     *
     * @return string '' = площадка не задана (ограничения по площадке нет).
     */
    private function configured_website_id( array $config ): string {
        return trim((string) ( $config['api_website_id'] ?? '' ));
    }

    /**
     * Извлечь числовой id площадки из API-action.
     *
     * Порядок: маппинг api_field_for('website_id') → 'website_id' → 'website'.
     * `website_name` НАМЕРЕННО не используется — это имя площадки, не id;
     * сравнение id с именем дало бы ложный mismatch и отсекло бы все действия.
     *
     * @return string '' = в action нет поля website (нечего сверять локально).
     */
    private function action_website_id( array $action, array $field_map ): string {
        $fm = $this->api_field_for('website_id', $field_map) ?: 'website_id';
        foreach (array( $fm, 'website_id', 'website' ) as $key) {
            if (isset($action[ $key ]) && $action[ $key ] !== '' && $action[ $key ] !== null) {
                return (string) $action[ $key ];
            }
        }
        return '';
    }

    /**
     * Принадлежит ли action сконфигурированной площадке.
     *
     * Политика (общая для всех сетей):
     * - площадка не задана → true (тянем все площадки, поведение не меняется);
     * - в action нет website-поля → true (param-level фильтр сети уже применён,
     *   не плодим ложные скипы, если сеть не возвращает website в action);
     * - иначе строгое сравнение (как int и как строка — '2941485' == 2941485).
     */
    private function action_in_configured_website( array $action, array $config ): bool {
        $cfg = $this->configured_website_id($config);
        if ($cfg === '') {
            return true;
        }

        $aw = $this->action_website_id($action, $config['field_map'] ?? array());
        if ($aw === '') {
            return true;
        }

        return $aw === $cfg || (string) (int) $aw === (string) (int) $cfg;
    }

    /**
     * Отфильтровать список API-actions по сконфигурированной площадке.
     *
     * Площадка не задана → массив возвращается как есть (skipped=0).
     * Каждый отброшенный action логируется (без PII).
     *
     * @return array{actions: array<int,array>, skipped: int}
     */
    private function filter_actions_by_website( array $actions, array $config, string $context ): array {
        if ($this->configured_website_id($config) === '') {
            return array( 'actions' => array_values($actions), 'skipped' => 0 );
        }

        $kept    = array();
        $skipped = 0;
        foreach ($actions as $action) {
            if ($this->action_in_configured_website($action, $config)) {
                $kept[] = $action;
            } else {
                ++$skipped;
                $this->log_skipped_foreign_website($context, $action, $config);
            }
        }

        return array( 'actions' => $kept, 'skipped' => $skipped );
    }

    /**
     * Залогировать отброшенный по чужой площадке action (без PII).
     */
    private function log_skipped_foreign_website( string $context, array $action, array $config ): void {
        if (!( defined('WP_DEBUG') && WP_DEBUG )) {
            return;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log(sprintf(
            '[Cashback Website Scope] %s: skipped foreign website slug=%s configured=%s action=%s uniq=%s',
            $context,
            (string) ( $config['slug'] ?? '' ),
            $this->configured_website_id($config),
            $this->action_website_id($action, $config['field_map'] ?? array()),
            $this->resolve_action_identity($config, $action)
        ));
    }

    // =========================================================================
    // URL builder (used by test_connection for api_key branch)
    // =========================================================================

    /**
     * Собрать URL из конфига сети (api_base_url + endpoint) или вернуть fallback
     */
    private function build_api_url( array $network_config, string $endpoint_key, string $fallback_url ): string {
        $base     = rtrim($network_config['api_base_url'] ?? '', '/');
        $endpoint = $network_config[ $endpoint_key ] ?? '';

        if ($endpoint !== '' && preg_match('#^https?://#i', $endpoint)) {
            if (!$this->is_safe_api_url($endpoint)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback API] Blocked unsafe API endpoint URL: ' . $endpoint);
                return $fallback_url;
            }
            return $endpoint;
        }

        if ($base !== '' && $endpoint !== '') {
            $full_url = $base . '/' . ltrim($endpoint, '/');
            if (!$this->is_safe_api_url($full_url)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('[Cashback API] Blocked unsafe API base URL: ' . $full_url);
                return $fallback_url;
            }
            return $full_url;
        }

        return $fallback_url;
    }

    /**
     * Проверяет безопасность URL для API-запросов (защита от SSRF).
     *
     * Разрешает только HTTPS и блокирует приватные/зарезервированные IP-адреса.
     */
    private function is_safe_api_url( string $url ): bool {
        $parsed = wp_parse_url($url);

        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        // Только HTTPS
        if (strtolower($parsed['scheme']) !== 'https') {
            return false;
        }

        // Резолвим домен и проверяем что IP не приватный/зарезервированный
        $ip = gethostbyname($parsed['host']);
        if ($ip === $parsed['host']) {
            // gethostbyname вернул сам хост — не удалось зарезолвить
            return false;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        return true;
    }

    // =========================================================================
    // Универсальная авторизация (OAuth2 / API Key)
    // =========================================================================

    /**
     * Сформировать заголовки авторизации в зависимости от типа (делегация адаптеру)
     *
     * @param array  $credentials   Расшифрованные credentials
     * @param array  $network_config Конфигурация сети (api_auth_type, api_base_url, slug, ...)
     * @param string $network_slug  Slug сети для роутинга (epn, admitad и др.)
     * @return array|null Массив заголовков или null при ошибке
     */
    private function build_auth_headers( array $credentials, array $network_config, string $network_slug = '' ): ?array {
        $auth_type = $network_config['api_auth_type'] ?? 'oauth2';

        if ($auth_type === 'api_key') {
            $api_key = $credentials['api_key'] ?? '';
            if (empty($api_key)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback API Client: API key is empty');
                return null;
            }
            return array( 'Authorization' => 'Bearer ' . $api_key );
        }

        // OAuth2: делегация адаптеру
        $adapter = $this->get_adapter($network_slug);
        if (!$adapter) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('Cashback API Client: No adapter registered for network: ' . $network_slug);
            return null;
        }
        return $adapter->build_auth_headers($credentials, $network_config);
    }

    /**
     * Проверить подключение к API CPA-сети
     *
     * @param int $network_id ID сети
     * @return array ['success' => bool, 'message' => string]
     */
    public function test_connection( int $network_id ): array {
        global $wpdb;

        $network = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            $this->networks_table,
            $network_id
        ), ARRAY_A);

        if (!$network) {
            return array(
				'success' => false,
				'message' => 'Сеть не найдена',
			);
        }

        $credentials = $this->get_credentials($network_id);
        $auth_type   = $network['api_auth_type'] ?? 'oauth2';

        // Проверка наличия credentials
        if ($auth_type === 'api_key') {
            if (!$credentials || empty($credentials['api_key'])) {
                return array(
					'success' => false,
					'message' => 'API Key не настроен. Сохраните API Key.',
				);
            }
        } elseif (!$credentials || empty($credentials['client_id']) || empty($credentials['client_secret'])) {
                return array(
					'success' => false,
					'message' => 'API credentials не настроены. Сохраните client_id и client_secret.',
				);
        }

        $network_config = $network;

        if ($auth_type === 'api_key') {
            // Для api_key-сетей делегируем проверку в адаптер: он сам знает,
            // как подставить токен (в header / URL path / query — пример: Advcake
            // принимает токен в `/export/webmaster/{token}`). Generic-подход
            // через build_auth_headers + build_api_url не умеет path-placeholder'ы
            // и формирует невалидный URL → CPA возвращает 404.
            //
            // Для пустого окна `fetch_all_actions` отдаёт success=true с total=0,
            // т.е. это и тест credentials, и smoke-test endpoint'а одновременно.
            $slug    = $network['slug'] ?? '';
            $adapter = $this->get_adapter($slug);

            if (!$adapter) {
                return array(
                    'success' => false,
                    'message' => 'Нет зарегистрированного адаптера для сети: ' . $slug,
                );
            }

            $today = gmdate('Y-m-d');
            $params = array(
                'date_from' => $today,
                'date_to'   => $today,
            );

            $result = $adapter->fetch_all_actions($credentials, $params, 1, $network_config);

            if (!empty($result['success'])) {
                $total = (int) ( $result['total'] ?? 0 );
                return array(
                    'success' => true,
                    'message' => 'Соединение успешно. Получено действий: ' . $total . '.',
                );
            }

            $error = isset($result['error']) && is_string($result['error']) && $result['error'] !== ''
                ? $result['error']
                : 'unknown error';

            return array(
                'success' => false,
                'message' => 'API недоступен: ' . $error . '. Проверьте API Key и URL.',
            );
        }

        // OAuth2: делегация адаптеру
        $slug    = $network['slug'] ?? '';
        $adapter = $this->get_adapter($slug);

        if (!$adapter) {
            return array(
				'success' => false,
				'message' => 'Нет зарегистрированного адаптера для сети: ' . $slug,
			);
        }

        $token = $adapter->get_token($credentials, $network_config);

        if ($token) {
            return array(
				'success' => true,
				'message' => 'Соединение успешно. OAuth2 токен получен.',
			);
        }

        $detail = $adapter->get_last_token_error()
            ?: 'Проверьте client_id, client_secret и URL эндпоинта.';

        return array(
			'success' => false,
			'message' => 'Не удалось получить токен. ' . $detail,
		);
    }

    // =========================================================================
    // Fetch actions from CPA networks (delegated to adapters)
    // =========================================================================

    /**
     * Универсальный fetch: делегация адаптеру по slug сети
     *
     * @param string $slug          Slug сети (epn, admitad, ...)
     * @param array  $credentials   API credentials
     * @param array  $params        Параметры запроса
     * @param int    $max_pages     Максимальное количество страниц
     * @param array  $network_config Конфигурация сети
     * @return array ['success' => bool, 'actions' => [...], 'total' => int, 'error' => string|null]
     */
    public function fetch_all_actions_for_network( string $slug, array $credentials, array $params, int $max_pages = 20, array $network_config = array() ): array {
        $adapter = $this->get_adapter($slug);
        if (!$adapter) {
            return array(
                'success' => false,
                'actions' => array(),
                'total'   => 0,
                'error'   => 'No adapter registered for network: ' . $slug,
            );
        }

        return $adapter->fetch_all_actions($credentials, $params, $max_pages, $network_config);
    }

    // =========================================================================
    // Date parsing
    // =========================================================================

    /**
     * Парсинг даты из API в MySQL DATETIME формат
     *
     * Поддерживает: ISO 8601, MySQL, русский dd.mm.YYYY, Unix timestamps.
     *
     * @param string $date_str Строка даты из API
     * @return string|null MySQL DATETIME (Y-m-d H:i:s) или null
     */
    protected static function parse_api_date( string $date_str ): ?string {
        $date_str = trim($date_str);
        if ($date_str === '') {
            return null;
        }

        // Unix timestamp (10 цифр = секунды, 13 цифр = миллисекунды)
        if (preg_match('/^\d{10,13}$/', $date_str)) {
            $timestamp = (int) $date_str;
            if (strlen($date_str) === 13) {
                $timestamp = (int) ( $timestamp / 1000 );
            }
            // 12e ADR (F-8-004): DateTimeImmutable + wp_timezone() — неизменяемость
            // + прямой DateTimeZone без прохода через строку.
            $dt = ( new DateTimeImmutable('@' . $timestamp) )->setTimezone(wp_timezone());
            return $dt->format('Y-m-d H:i:s');
        }

        // ISO 8601 с T-разделителем: "2024-01-15T10:30:00"
        $date_str = str_replace('T', ' ', $date_str);

        // Убираем таймзону: "+03:00", " 03:00" (+ → пробел после URL encoding), "Z"
        $date_str = preg_replace('/[+-]\d{2}:\d{2}$/', '', $date_str);
        $date_str = preg_replace('/\s+\d{2}:\d{2}$/', '', $date_str);
        $date_str = rtrim($date_str, 'Z');

        $formats = array(
            'Y-m-d H:i:s',  // 2024-01-15 10:30:00
            'Y-m-d H:i',    // 2024-01-15 10:30
            'Y-m-d',         // 2024-01-15
            'd.m.Y H:i:s',  // 15.01.2024 10:30:00
            'd.m.Y H:i',    // 15.01.2024 10:30
            'd.m.Y',         // 15.01.2024
        );

        foreach ($formats as $format) {
            // 12e ADR (F-8-004): DateTimeImmutable::createFromFormat — immutable, не трогает $this.
            $dt = DateTimeImmutable::createFromFormat($format, $date_str);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /**
     * Конвертировать naive datetime, пришедший из API сети в её локальной
     * зоне, в UTC для хранения (ADR utc-everywhere).
     *
     * Применяется ПОСЛЕ parse_api_date (его tz-поведение намеренно
     * заморожено и shared с EPN/Advcake — не трогаем). Зону декларирует
     * адаптер сети через get_api_datetime_timezone(); пусто → значение
     * уже трактуется как UTC, возвращаем без изменений. Нераспарсиваемый
     * вход возвращается как есть (не роняем INSERT из-за tz-конверсии).
     *
     * @param string|null $mysql Выход parse_api_date ('Y-m-d H:i:s') или null.
     * @param string      $tz    IANA-зона API сети ('' = UTC, без конверсии).
     */
    private static function api_datetime_to_utc( ?string $mysql, string $tz ): ?string {
        if ($mysql === null || $mysql === '' || $tz === '') {
            return $mysql;
        }
        try {
            $dt = new DateTimeImmutable($mysql, new DateTimeZone($tz));
        } catch (\Exception $e) {
            return $mysql;
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Разрешить datetime из API: первый УСПЕШНО распарсенный кандидат +
     * корректная network→UTC нормализация.
     *
     * Codex fintech-review v4.4.4:
     *  - F-3: перебираем кандидатов и берём первый, который parse_api_date
     *    реально распарсил, а не первый непустой. Иначе present-but-garbage
     *    поле (напр. Admitad conversion_time=846 — длительность, не дата)
     *    блокирует деградацию к настоящим datetime-полям → action_date
     *    NULL и перекос hold-периода / funds_ready.
     *  - F-2: Unix-timestamp — уже абсолютный момент (parse_api_date
     *    локализует его через wp_timezone()); повторная network→UTC
     *    конверсия сдвинула бы его на часы при site-tz != network-tz.
     *    Конвертируем ТОЛЬКО naive-строки сети (не ts).
     *
     * @param array<int,scalar|null> $candidates Сырые значения по приоритету.
     * @param string                 $api_tz     Зона API сети ('' = UTC).
     * @return string|null MySQL DATETIME (UTC) или null если ничего не распарсилось.
     */
    private static function resolve_api_datetime( array $candidates, string $api_tz ): ?string {
        foreach ($candidates as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $parsed = self::parse_api_date($raw);
            if ($parsed === null) {
                continue;
            }
            // Unix-ts: parse_api_date уже дал абсолютный момент — не
            // подвергаем повторной network→UTC конверсии (F-2).
            if ($api_tz !== '' && !preg_match('/^\d{10,13}$/', $raw)) {
                return self::api_datetime_to_utc($parsed, $api_tz);
            }
            return $parsed;
        }
        return null;
    }

    // =========================================================================
    // Validation logic
    // =========================================================================

    /**
     * Дата начала окна выборки по умолчанию в формате d.m.Y.
     *
     * Глубина окна задаётся админом через опцию `cashback_api_sync_window_days`
     * (страница «API Валидация» → вкладка «Синхронизация»). Дефолт 180,
     * значение clamped в [1, 365] — защита от случайного возврата к 6-летнему
     * окну, которое стабильно ронит `/statistics/actions/` Admitad в HTTP 500
     * и cURL timeout 60s даже при пустой выборке. 180 дней — рекомендуемый
     * баланс: покрывает hold-периоды большинства CPA-офферов и держит API
     * Admitad в зелёной зоне.
     *
     * Применяется ко всем CPA-сетям (Admitad, EPN и любым будущим): метод
     * вызывается из `validate_user`, `validate_unregistered` и
     * `do_background_sync` (foreach по `get_all_active_networks()`).
     *
     * Дата считается в UTC: после ADR utc-everywhere весь plugin работает в
     * UTC, и Admitad принимает date_start без timezone-уточнения как UTC-day.
     */
    private function default_lookback_date_dmy(): string {
        $days = (int) get_option('cashback_api_sync_window_days', 180);
        if ($days < 1) {
            $days = 180;
        } elseif ($days > 365) {
            $days = 365;
        }

        return ( new DateTimeImmutable('now', new DateTimeZone('UTC')) )
            ->modify('-' . $days . ' days')
            ->format('d.m.Y');
    }

    /**
     * Валидация пользователя: сравнение данных API с локальными транзакциями
     *
     * Стратегия (индустриальный стандарт кэшбэк-сервисов):
     *   1. Запрос API с фильтром subid2 = user_id (все транзакции пользователя)
     *   2. Матчинг: API.subid1 == DB.click_id (наш UUID)
     *   3. Сравнение сматченных: status, payment/comission, cart/sum_order
     *   4. Выявление: missing_local (в API, нет у нас), missing_api (у нас, нет в API)
     *
     * @param int    $user_id
     * @param string $network_slug Slug сети (admitad, epn)
     * @param bool   $use_checkpoint Использовать инкрементальный чекпоинт
     * @return array Результат валидации
     */
    public function validate_user( int $user_id, string $network_slug = 'admitad', bool $use_checkpoint = true ): array {
        global $wpdb;

        $network = $this->get_network_config($network_slug);
        if (!$network || empty($network['credentials'])) {
            return array(
                'success' => false,
                'error'   => 'Сеть не найдена или API не настроен: ' . $network_slug,
                'user_id' => $user_id,
                'network' => $network_slug,
            );
        }

        // ─── Определяем дату начала ───
        // Fallback 180 дней: см. default_lookback_date_dmy() — широкие 6-летние
        // окна ронят /statistics/actions/ Admitad в HTTP 500/timeout.
        $date_start = $this->default_lookback_date_dmy();

        if ($use_checkpoint) {
            $checkpoint = $this->get_checkpoint($user_id, $network_slug);
            if ($checkpoint && !empty($checkpoint['last_validated_date'])) {
                $dt = new DateTime($checkpoint['last_validated_date']);
                $dt->modify('-7 days');
                $date_start = $dt->format('d.m.Y');
            } else {
                $reg_date = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_registered FROM {$wpdb->users} WHERE ID = %d",
                    $user_id
                ));
                if ($reg_date) {
                    $date_start = ( new DateTime($reg_date) )->format('d.m.Y');
                }
            }
        }

        $date_end = ( new DateTime() )->format('d.m.Y');

        // ─── Запрос к API ───
        // api_user_field = 'subid2' (partner_token передаётся в subid2 при генерации ссылки)
        // api_click_field = 'subid1' (click_id передаётся в subid1 — ключ матчинга)
        $user_field = $network['api_user_field'] ?? 'subid2';

        // В партнёрских ссылках используется partner_token (не user_id)
        $partner_token  = Mariadb_Plugin::get_partner_token($user_id);
        $api_user_value = $partner_token !== null ? $partner_token : (string) $user_id;

        $api_params = array(
            $user_field  => $api_user_value,
            'date_start' => $date_start,
            'date_end'   => $date_end,
        );

        if (!empty($network['api_website_id'])) {
            $api_params['website'] = $network['api_website_id'];
        }

        $api_result = $this->fetch_all_actions_for_network($network_slug, $network['credentials'], $api_params, 20, $network);

        if (!$api_result['success']) {
            return array(
                'success' => false,
                'error'   => 'API error: ' . $api_result['error'],
                'user_id' => $user_id,
                'network' => $network_slug,
            );
        }

        $api_actions = $api_result['actions'];

        // ─── Привязка к площадке (defense-in-depth) ───
        // Отбрасываем действия чужой площадки ДО матчинга/вставки.
        $website_filtered        = $this->filter_actions_by_website($api_actions, $network, 'validate_user');
        $api_actions             = $website_filtered['actions'];
        $skipped_foreign_website = $website_filtered['skipped'];

        // ─── Локальные транзакции ───
        // ВАЖНО: включаем click_id для матчинга и order_number для fallback
        $local_start = DateTime::createFromFormat('d.m.Y', $date_start)->format('Y-m-d');

        // Матчим partner по slug И name сети (case-insensitive),
        // т.к. webhook может записывать partner_name по-разному
        $network_name = $network['name'] ?? '';

        $local_transactions = $wpdb->get_results($wpdb->prepare(
            'SELECT t.id, t.click_id, t.uniq_id, t.order_number, t.offer_name,
                    t.comission, t.cashback, t.order_status, t.partner,
                    t.sum_order, t.created_at, t.updated_at, t.original_cpa_subid,
                    t.created_by_admin
             FROM %i t
             WHERE t.user_id = %d
               AND (LOWER(t.partner) = LOWER(%s) OR LOWER(t.partner) = LOWER(%s))
               AND t.created_at >= %s
             ORDER BY t.created_at',
            $this->transactions_table,
            $user_id,
            $network_slug,
            $network_name,
            $local_start
        ), ARRAY_A);

        // ─── Индекс для матчинга ───
        // Идентичность транзакции = uniq_id (= API.action_id, уникальный ID в
        // рамках CPA-сети). click_id НЕ ключ: split-order siblings разделяют
        // один click_id, индексация по нему схлопывала бы их в одну запись →
        // ложные missing_local / mismatched в отчёте сверки.
        $local_by_uniq_id = array();

        foreach ($local_transactions as $tx) {
            if (!empty($tx['uniq_id'])) {
                $local_by_uniq_id[ $tx['uniq_id'] ] = $tx;
            }
        }

        // Маппинг статусов
        $status_map = $network['status_map'];

        // Маппинг полей API → локальные колонки
        $field_map = $network['field_map'];

        // Имена полей API по маппингу (обратный поиск)
        $fm_payment     = $this->api_field_for('comission', $field_map) ?: 'payment';
        $fm_cart        = $this->api_field_for('sum_order', $field_map) ?: 'cart';
        $fm_uniq_id     = $this->api_field_for('uniq_id', $field_map) ?: 'action_id';
        $fm_order_id    = $this->api_field_for('order_number', $field_map) ?: 'order_id';
        $fm_offer_id    = $this->api_field_for('offer_id', $field_map) ?: 'advcampaign_id';
        $fm_offer_nm    = $this->api_field_for('offer_name', $field_map) ?: 'advcampaign_name';
        $fm_currency    = $this->api_field_for('currency', $field_map) ?: 'currency';
        $fm_action_date = $this->api_field_for('action_date', $field_map) ?: 'action_date';
        $fm_click_time  = $this->api_field_for('click_time', $field_map) ?: 'click_date';
        $fm_action_type = $this->api_field_for('action_type', $field_map) ?: 'action_type';
        $fm_website_id  = $this->api_field_for('website_id', $field_map) ?: 'website_id';

        // Имя поля для click_id в API (по умолчанию subid1)
        $click_field = $network['api_click_field'] ?? 'subid1';

        // ─── Сравнение ───
        $matched       = array();
        $mismatched    = array();
        $missing_local = array(); // Есть в API, нет локально

        // Суммы по API (по замапленным статусам)
        $api_sums = array(
			'approved' => 0.0,
			'pending'  => 0.0,
			'declined' => 0.0,
		);

        // Суммы по локальным сматченным (по статусам)
        $local_sums = array(
			'approved' => 0.0,
			'pending'  => 0.0,
			'declined' => 0.0,
		);

        // Множество сматченных click_id для обратной проверки (только полные совпадения)
        $matched_click_ids = array();

        // Множество click_id локальных транзакций, найденных в API (совпавших или расходящихся)
        // Используется в обратной проверке missing_api, чтобы не дублировать mismatched
        $api_matched_local_click_ids = array();

        // Debug: логируем только ключи первого action из API для диагностики маппинга
        // Данные не логируем — могут содержать PII (email, имя, телефон)
        if (defined('WP_DEBUG') && WP_DEBUG && !empty($api_actions)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback API Validate] First action keys: ' . implode(', ', array_keys($api_actions[0])));
        }

        foreach ($api_actions as $action) {
            $api_click_id  = (string) ( $action[ $click_field ] ?? '' );
            $api_status    = strtolower($action['status'] ?? 'pending');
            $api_payment   = (float) ( $action[ $fm_payment ] ?? 0 );
            $api_cart      = (float) ( $action[ $fm_cart ] ?? 0 );
            $mapped_status = $status_map[ $api_status ] ?? 'waiting';

            // Подсчёт сумм по API
            // balance — эквивалент completed (из кастомного маппинга approved→balance)
            if ($mapped_status === 'completed' || $mapped_status === 'balance') {
                $api_sums['approved'] += $api_payment;
            } elseif ($mapped_status === 'waiting') {
                $api_sums['pending'] += $api_payment;
            } elseif ($mapped_status === 'declined') {
                $api_sums['declined'] += $api_payment;
            }

            // ─── МАТЧИНГ по uniq_id ───
            // Идентичность = uniq_id (API.action_id). click_id НЕ ключ:
            // split-order siblings разделяют один click_id, но каждый —
            // отдельная транзакция со своим admitad_id/action_id.
            $local_tx      = null;
            $action_id_key = (string) ( $action[ $fm_uniq_id ] ?? '' );
            if ($action_id_key !== '' && isset($local_by_uniq_id[ $action_id_key ])) {
                $local_tx = $local_by_uniq_id[ $action_id_key ];
            }

            if (!$local_tx) {
                $missing_local[] = array(
                    'action_id'   => $action[ $fm_uniq_id ] ?? '',
                    'click_id'    => $api_click_id,
                    'order_id'    => $action[ $fm_order_id ] ?? '',
                    'status'      => $api_status,
                    'payment'     => $api_payment,
                    'cart'        => $api_cart,
                    'date'        => $action[ $fm_action_date ] ?? '',
                    'campaign'    => $action[ $fm_offer_nm ] ?? '',
                    'campaign_id' => $action[ $fm_offer_id ] ?? '',
                    'currency'    => $action[ $fm_currency ] ?? 'RUB',
                    'click_time'  => $action[ $fm_click_time ] ?? $action['click_time'] ?? $action['closing_date'] ?? '',
                    'action_type' => $action[ $fm_action_type ] ?? '',
                    'website_id'  => $action[ $fm_website_id ] ?? $action['website_name'] ?? $action['website'] ?? ( $network['api_website_id'] ?? '' ),
                    'funds_ready' => $this->resolve_funds_ready($action, $field_map),
                );
                continue;
            }

            // Запоминаем что эта локальная транзакция найдена в API (независимо от расхождений)
            if (!empty($local_tx['click_id'])) {
                $api_matched_local_click_ids[ $local_tx['click_id'] ] = true;
            }

            // Запоминаем что эта локальная транзакция сматчена
            if (!empty($local_tx['click_id'])) {
                $matched_click_ids[ $local_tx['click_id'] ] = true;
            }

            // ─── СРАВНЕНИЕ ───
            $local_status     = $local_tx['order_status'];
            $local_commission = (float) $local_tx['comission'];
            $local_cart       = (float) ( $local_tx['sum_order'] ?? 0 );

            // Суммы по локальным
            if ($local_status === 'completed' || $local_status === 'balance') {
                $local_sums['approved'] += $local_commission;
            } elseif ($local_status === 'waiting' || $local_status === 'hold') {
                $local_sums['pending'] += $local_commission;
            } elseif ($local_status === 'declined') {
                $local_sums['declined'] += $local_commission;
            }

            // Статус: completed и balance — оба эквивалентны approved в API
            // (balance = финализированный completed, зачислено в баланс)
            $approved_statuses = array( 'completed', 'balance' );
            $status_match      = ( $local_status === $mapped_status )
                || ( in_array($local_status, $approved_statuses, true)
                    && in_array($mapped_status, $approved_statuses, true) );

            // Комиссия: допускаем погрешность 0.001 (float-артефакты)
            $commission_match = abs($api_payment - $local_commission) < 0.001;

            // Сумма заказа: допускаем погрешность 0.001 (float-артефакты)
            // Не считаем mismatch если у одной из сторон 0 (не всегда передаётся)
            $cart_match = ( $api_cart == 0 || $local_cart == 0 )
                || abs($api_cart - $local_cart) < 0.001;

            if ($status_match && $commission_match && $cart_match) {
                $matched[] = array(
                    'local_id'         => (int) $local_tx['id'],
                    'click_id'         => $api_click_id,
                    'api_status'       => $api_status,
                    'local_status'     => $local_status,
                    'api_payment'      => $api_payment,
                    'local_commission' => $local_commission,
                );
            } else {
                $mismatched[] = array(
                    'uniq_id'             => $local_tx['uniq_id'] ?? '',
                    'click_id'            => $api_click_id,
                    'local_id'            => $local_tx['id'],
                    'api_status'          => $api_status,
                    'local_status'        => $local_status,
                    'mapped_api_status'   => $mapped_status,
                    'api_payment'         => $api_payment,
                    'local_commission'    => $local_commission,
                    'api_cart'            => $api_cart,
                    'local_cart'          => $local_cart,
                    'status_mismatch'     => !$status_match,
                    'commission_mismatch' => !$commission_match,
                    'cart_mismatch'       => !$cart_match,
                    'action_id'           => $action[ $fm_uniq_id ] ?? '',
                    'order_id'            => $action[ $fm_order_id ] ?? '',
                );

                // Авто-обновляем расхождения — синхронизируем локальные данные с API
                $dummy_updated = 0;
                $dummy_skipped = 0;
                $dummy_errors  = 0;
                $this->sync_update_local(
                    $wpdb,
                    $this->transactions_table,
                    $local_tx,
                    $mapped_status,
                    $api_payment,
                    $api_cart,
                    $network_slug,
                    $api_click_id,
                    $action,
                    $field_map,
                    $dummy_updated,
                    $dummy_skipped,
                    $dummy_errors
                );
            }
        }

        // ─── Обратная проверка: транзакции есть у нас, но нет в API ───
        $missing_api = array();
        foreach ($local_transactions as $tx) {
            // Пропускаем если транзакция найдена в API (полное совпадение или расхождение — неважно)
            if (!empty($tx['click_id']) && isset($api_matched_local_click_ids[ $tx['click_id'] ])) {
                continue;
            }
            // Пропускаем balance — финализировано, может быть за пределами API
            if ($tx['order_status'] === 'balance') {
                continue;
            }
            // Пропускаем если нет click_id — невозможно сверить
            if (empty($tx['click_id'])) {
                continue;
            }

            $missing_api[] = array(
                'local_id'         => $tx['id'],
                'uniq_id'          => $tx['uniq_id'] ?? '',
                'click_id'         => $tx['click_id'],
                'order_number'     => $tx['order_number'],
                'status'           => $tx['order_status'],
                'commission'       => (float) $tx['comission'],
                'sum_order'        => (float) ( $tx['sum_order'] ?? 0 ),
                'created'          => $tx['created_at'],
                // 1 = транзакция создана админом вручную (Сверка баланса → зависший claim).
                // Такая запись отсутствует в API by design — UI рендерит «Да» в столбце
                // «Добавлена админом», чтобы админ не искал причину «расхождения».
                'created_by_admin' => isset( $tx['created_by_admin'] ) ? (int) $tx['created_by_admin'] : 0,
            );
        }

        // ─── Дополнительная проверка для перенесённых из unregistered транзакций ───
        // Транзакции, перенесённые администратором из cashback_unregistered_transactions,
        // хранятся в CPA с original_cpa_subid (например 'unregistered'), а не с реальным user_id.
        // Поэтому основной запрос к API (subid2=user_id) их не возвращает.
        // Делаем дополнительные запросы по уникальным значениям original_cpa_subid.
        if (!empty($missing_api)) {
            $transferred_missing = array();
            foreach ($missing_api as $key => $m) {
                // Идентичность по uniq_id (split-order: один click_id → много tx).
                $local_tx   = $local_by_uniq_id[ $m['uniq_id'] ] ?? null;
                $orig_subid = $local_tx['original_cpa_subid'] ?? null;

                if ($orig_subid !== null && $orig_subid !== $api_user_value && $orig_subid !== (string) $user_id) {
                    $transferred_missing[ $orig_subid ][ $key ] = $m;
                }
            }

            foreach ($transferred_missing as $orig_subid => $items) {
                $extra_params = array(
                    $user_field  => $orig_subid,
                    'date_start' => $date_start,
                    'date_end'   => $date_end,
                );
                if (!empty($network['api_website_id'])) {
                    $extra_params['website'] = $network['api_website_id'];
                }

                $extra_result = $this->fetch_all_actions_for_network(
                    $network_slug,
                    $network['credentials'],
                    $extra_params,
                    20,
                    $network
                );

                if (!$extra_result['success']) {
                    continue;
                }

                $extra_filtered           = $this->filter_actions_by_website($extra_result['actions'], $network, 'validate_user:transferred');
                $skipped_foreign_website += $extra_filtered['skipped'];

                foreach ($extra_filtered['actions'] as $extra_action) {
                    $extra_click_id  = (string) ( $extra_action[ $click_field ] ?? '' );
                    $extra_action_id = (string) ( $extra_action['action_id'] ?? '' );

                    foreach ($items as $key => $m) {
                        $click_match  = $extra_click_id !== '' && $extra_click_id === $m['click_id'];
                        $action_match = $extra_action_id !== '' && $extra_action_id === ( $m['uniq_id'] ?? '' );

                        if ($click_match || $action_match) {
                            unset($missing_api[ $key ]);
                            $matched[] = array( 'local_id' => $m['local_id'] );
                            unset($items[ $key ]); // избегаем повторного матчинга
                            break;
                        }
                    }
                }
            }

            $missing_api = array_values($missing_api);
        }

        // ─── Обновляем api_verified для всех сматченных транзакций ───
        $matched_ids = array_column($matched, 'local_id');
        if (!empty($matched_ids)) {
            // Батчами по 500 чтобы не превысить лимит SQL
            foreach (array_chunk($matched_ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %d literals; sniff can't see %d inside $placeholders.
                $wpdb->query( $wpdb->prepare( "UPDATE %i SET api_verified = 1 WHERE id IN ({$placeholders}) AND api_verified = 0", $this->transactions_table, ...$chunk ) );
            }
        }

        // ─── Результат ───
        $has_issues        = !empty($mismatched) || !empty($missing_local) || !empty($missing_api);
        $total_checked     = count($api_actions);
        $validation_status = $has_issues ? 'mismatch' : 'match';

        // Расхождение: разница между API approved и локальными approved суммами
        $discrepancy = abs($api_sums['approved'] - $local_sums['approved']);

        // Обновляем чекпоинт
        $this->update_checkpoint($user_id, $network_slug, array(
            'last_validated_date'      => ( new DateTime() )->format('Y-m-d'),
            'api_sum_approved'         => $api_sums['approved'],
            'api_sum_pending'          => $api_sums['pending'],
            'api_sum_declined'         => $api_sums['declined'],
            'api_actions_count'        => $total_checked,
            'local_sum_approved'       => $local_sums['approved'],
            'local_sum_pending'        => $local_sums['pending'],
            'local_sum_declined'       => $local_sums['declined'],
            'local_transactions_count' => count($local_transactions),
            'validation_status'        => $validation_status,
            'discrepancy_amount'       => $discrepancy,
            'matched_count'            => count($matched),
            'mismatch_count'           => count($mismatched),
            'missing_local_count'      => count($missing_local),
            'missing_api_count'        => count($missing_api),
        ));

        return array(
            'success'        => true,
            'user_id'        => $user_id,
            'network'        => $network_slug,
            'status'         => $validation_status,
            'date_range'     => array(
				'start' => $date_start,
				'end'   => $date_end,
			),
            'api_total'      => $total_checked,
            'local_total'    => count($local_transactions),
            'matched_count'  => count($matched),
            'mismatch_count' => count($mismatched),
            'skipped_foreign_website' => $skipped_foreign_website,
            'missing_local'  => $missing_local,
            'missing_api'    => $missing_api,
            'mismatched'     => $mismatched,
            'sums'           => array(
                'api_approved'   => $api_sums['approved'],
                'api_pending'    => $api_sums['pending'],
                'api_declined'   => $api_sums['declined'],
                'local_approved' => $local_sums['approved'],
                'local_pending'  => $local_sums['pending'],
                'local_declined' => $local_sums['declined'],
                'discrepancy'    => $discrepancy,
            ),
        );
    }

    /**
     * Self-test дедупликации (read-only, zero side-effects).
     *
     * Ловит невнимательность админа: webhook-ресивер настроен верно, а в
     * кроне (api_field_map) на uniq_id повешено НЕ то API-поле (например
     * «номер заказа» вместо «ID действия»). Кросс-имённую авто-сверку
     * сделать нельзя (поля постбэка и API в разных пространствах имён,
     * ADR D-5b) — поэтому проверка ПОВЕДЕНЧЕСКАЯ: на свежей webhook-строке
     * берём ТУ ЖЕ конверсию из API, прогоняем крон-резолвер и сравниваем
     * полученный uniq_id с уже сохранённым.
     *
     * СТРОГО read-only: только SELECT + исходящий HTTP-GET к CPA (та же
     * операция, что у кнопки validate). НЕ вызывает validate_user /
     * sync_update_local / update_checkpoint / insert_missing_transaction /
     * log_audit / ledger — ни одного INSERT/UPDATE.
     *
     * @param string $network_slug Slug сети.
     * @param int    $sample_limit Сколько свежих строк проверить (по обеим таблицам).
     * @return array{verdict:string,network:string,checked:int,reason?:string,detail?:array,message:string}
     */
    public function dedup_selftest( string $network_slug, int $sample_limit = 25 ): array {
        global $wpdb;

        $slug_in = trim($network_slug);
        $cfg     = $this->get_network_config($slug_in);
        if (!is_array($cfg) || empty($cfg['credentials'])) {
            return array(
                'verdict' => 'inconclusive',
                'network' => $slug_in,
                'checked' => 0,
                'reason'  => 'network_unavailable',
                'message' => 'Сеть не найдена, неактивна или API не настроен — проверить нельзя.',
            );
        }

        $slug   = strtolower(trim((string) ( $cfg['slug'] ?? $slug_in )));
        $name   = (string) ( $cfg['name'] ?? '' );
        // Широкая выборка (closes Codex iter-2 B-2): webhook-строки
        // реалтайм → присутствуют среди свежих; «любой MISMATCH доминирует».
        $limit  = max(1, min(50, $sample_limit));

        // Окно выборки: то же, что lookback у validate (узкий хвост →
        // range-scan по idx_stats_created_at, оборван LIMIT).
        $start_dmy = $this->default_lookback_date_dmy();
        $start_obj = DateTime::createFromFormat('d.m.Y', $start_dmy);
        $start_sql = $start_obj ? $start_obj->format('Y-m-d') : gmdate('Y-m-d', strtotime('-180 days'));

        $samples = array();
        foreach (array( 'transactions' => $this->transactions_table, 'unregistered' => $this->unregistered_table ) as $tkey => $table) {
            // Колонки — статический литерал (без интерполяции переменных в SQL).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only diagnostic, narrow window + LIMIT.
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT id,user_id,partner,uniq_id,idempotency_key,click_id,order_number,'
                . 'offer_id,action_type,action_date,created_at,original_cpa_subid,'
                . 'created_by_admin,comission,sum_order FROM %i'
                . ' WHERE LOWER(partner) IN (LOWER(%s), LOWER(%s))'
                . " AND created_by_admin = 0 AND uniq_id <> ''"
                . ' AND created_at >= %s ORDER BY created_at DESC LIMIT %d',
                $table,
                $slug,
                $name,
                $start_sql,
                $limit
            ), ARRAY_A);
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $r['__table'] = $tkey;
                    $samples[]    = $r;
                }
            }
        }

        if ($samples === array()) {
            return array(
                'verdict' => 'inconclusive',
                'network' => $slug,
                'checked' => 0,
                'reason'  => 'no_webhook_sample',
                'message' => 'Нет свежих транзакций этой сети для проверки. Дождитесь хотя бы одной конверсии и повторите.',
            );
        }

        // Примечание о soundness (Codex iter-2 Blocker-1): локальный
        // scan «один заказ под двумя uniq_id» НЕ применяется — он не
        // отличим от ЛЕГИТИМНОГО Admitad split-order (один заказ →
        // несколько действий, у каждого свой uniq_id — это норма). Поэтому
        // вердикт строится ТОЛЬКО на сравнении «крон-резолвер из API vs
        // сохранённый uniq_id». Риск ложного MATCH на cron-origin строке
        // (нет origin-флага в схеме) закрывается ШИРОКОЙ выборкой: webhook
        // пишется в реалтайме на конверсию (раньше cron-reconciliation),
        // поэтому среди свежих строк окна webhook-строки присутствуют, а
        // агрегатор «любой MISMATCH доминирует» ловит баг по ним.
        // Остаточный edge (всё окно — только cron-дубли) — узкий; покрыт
        // defense-in-depth: v18 source-drift notice + dedup-config панель.

        // Свежие первыми; webhook-подобные (есть click_id, нет
        // original_cpa_subid — не перенос из unregistered) в приоритете.
        usort($samples, static function ( $a, $b ): int {
            $aw = ( ( (string) ( $a['click_id'] ?? '' ) ) !== '' && ( (string) ( $a['original_cpa_subid'] ?? '' ) ) === '' ) ? 1 : 0;
            $bw = ( ( (string) ( $b['click_id'] ?? '' ) ) !== '' && ( (string) ( $b['original_cpa_subid'] ?? '' ) ) === '' ) ? 1 : 0;
            if ($aw !== $bw) {
                return $bw <=> $aw;
            }
            return strcmp((string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ));
        });
        $samples = array_slice($samples, 0, $limit);

        // Поля API (точно как resolve_action_identity()).
        $field_map   = is_array($cfg['field_map'] ?? null) ? $cfg['field_map'] : array();
        $fm_uniq     = $this->api_field_for('uniq_id', $field_map) ?: 'action_id';
        $fm_order    = $this->api_field_for('order_number', $field_map) ?: 'order_id';
        $fm_offer    = $this->api_field_for('offer_id', $field_map) ?: 'advcampaign_id';
        $fm_pay      = $this->api_field_for('comission', $field_map) ?: 'payment';
        $fm_adate    = $this->api_field_for('action_date', $field_map) ?: 'action_date';
        $click_field = (string) ( $cfg['api_click_field'] ?? 'subid1' );
        $user_field  = (string) ( $cfg['api_user_field'] ?? 'subid2' );
        $di          = $this->dedup_identity_for($cfg);

        $checked        = 0;
        $match_count    = 0;
        $reason_tally   = array();
        $uniq_mismatch  = null;
        $idem_mismatch  = null;

        // Группируем строки по идентификатору пользователя для API. ОДИН
        // HTTP-запрос на пользователя (бюджет $max_probes пользователей —
        // 50 запросов на клик уронили бы CPA rate-limit), но КАЖДАЯ строка
        // пользователя оценивается против его actions. Это закрывает Codex
        // iter-2 B-2: webhook-строка и cron-дубль одной конверсии делят
        // user → обе будут проверены против одного fetch, и «любой
        // MISMATCH доминирует» поймает рассинхрон, не завися от того,
        // какую строку «выбрали».
        $profile_table = $wpdb->prefix . 'cashback_user_profile';
        $by_user = array();
        foreach ($samples as $row) {
            $uid_raw = (string) ( $row['user_id'] ?? '' );
            $uv = '';
            if (ctype_digit($uid_raw) && (int) $uid_raw > 0) {
                // READ-ONLY токен-lookup. НЕ Mariadb_Plugin::get_partner_token()
                // — та материализует NULL-токен через UPDATE (mariadb.php
                // ~2233), что нарушило бы zero-write контракт self-test.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only token lookup for diagnostic.
                $tok = $wpdb->get_var($wpdb->prepare(
                    'SELECT partner_token FROM %i WHERE user_id = %d',
                    $profile_table,
                    (int) $uid_raw
                ));
                $uv  = ( $tok !== null && (string) $tok !== '' ) ? (string) $tok : $uid_raw;
            } elseif ((string) ( $row['original_cpa_subid'] ?? '' ) !== '') {
                $uv = (string) $row['original_cpa_subid'];
            }
            if ($uv === '') {
                $reason_tally['no_usable_identifier'] = ( $reason_tally['no_usable_identifier'] ?? 0 ) + 1;
                continue;
            }
            $by_user[ $uv ][] = $row;
        }

        $max_probes = 8;
        $probes     = 0;

        foreach ($by_user as $api_user_value => $user_rows) {
            if ($probes >= $max_probes) {
                break;
            }
            ++$probes;

            // Окно покрывает все строки пользователя (min−7д … max+2д).
            $anchors = array();
            foreach ($user_rows as $row) {
                $a = (string) ( $row['action_date'] ?? '' );
                if ($a === '' || $a === '0000-00-00 00:00:00') {
                    $a = (string) ( $row['created_at'] ?? '' );
                }
                $anchors[] = strtotime($a) ?: time();
            }
            $ds = gmdate('d.m.Y', min($anchors) - 7 * DAY_IN_SECONDS);
            $de = gmdate('d.m.Y', max($anchors) + 2 * DAY_IN_SECONDS);

            $api_params = array(
                $user_field  => (string) $api_user_value,
                'date_start' => $ds,
                'date_end'   => $de,
            );
            if (!empty($cfg['api_website_id'])) {
                $api_params['website'] = $cfg['api_website_id'];
            }

            $api = $this->fetch_all_actions_for_network($slug, $cfg['credentials'], $api_params, 20, $cfg);
            if (empty($api['success'])) {
                $reason_tally['api_unavailable'] = ( $reason_tally['api_unavailable'] ?? 0 ) + count($user_rows);
                continue;
            }
            $actions = $this->filter_actions_by_website(
                is_array($api['actions'] ?? null) ? $api['actions'] : array(),
                $cfg,
                'dedup_selftest'
            )['actions'];

            foreach ($user_rows as $row) {
                $anchor = (string) ( $row['action_date'] ?? '' );
                if ($anchor === '' || $anchor === '0000-00-00 00:00:00') {
                    $anchor = (string) ( $row['created_at'] ?? '' );
                }
                $anchor_ts = strtotime($anchor) ?: time();

                // Грубый фильтр «та же конверсия» БЕЗ uniq_id.
                $r_order = trim((string) ( $row['order_number'] ?? '' ));
                $r_offer = (int) ( $row['offer_id'] ?? 0 );
                $r_atype = strtolower(trim((string) ( $row['action_type'] ?? '' )));
                $candidates = array();
                foreach ($actions as $act) {
                    if (trim((string) ( $act[ $fm_order ] ?? '' )) !== $r_order) {
                        continue;
                    }
                    if ((int) ( $act[ $fm_offer ] ?? 0 ) !== $r_offer) {
                        continue;
                    }
                    if (strtolower(trim((string) ( $act['action_type'] ?? '' ))) !== $r_atype) {
                        continue;
                    }
                    $candidates[] = $act;
                }

                if ($candidates === array()) {
                    $reason_tally['api_action_not_found'] = ( $reason_tally['api_action_not_found'] ?? 0 ) + 1;
                    continue;
                }
                if (count($candidates) > 1) {
                    // Split-order: доуточняем click_id + сумма + дата.
                    $r_click  = (string) ( $row['click_id'] ?? '' );
                    $r_amount = round((float) ( $row['comission'] ?? 0 ), 2);
                    $r_day    = gmdate('Y-m-d', $anchor_ts);
                    $refined  = array();
                    foreach ($candidates as $act) {
                        if ((string) ( $act[ $click_field ] ?? '' ) !== $r_click) {
                            continue;
                        }
                        if (round((float) ( $act[ $fm_pay ] ?? 0 ), 2) !== $r_amount) {
                            continue;
                        }
                        $a_date = self::parse_api_date((string) ( $act[ $fm_adate ] ?? '' ));
                        if ($a_date === null || substr($a_date, 0, 10) !== $r_day) {
                            continue;
                        }
                        $refined[] = $act;
                    }
                    if (count($refined) !== 1) {
                        // Неоднозначно → честное INCONCLUSIVE, НИКОГДА не MISMATCH.
                        $reason_tally['ambiguous_match'] = ( $reason_tally['ambiguous_match'] ?? 0 ) + 1;
                        continue;
                    }
                    $candidates = $refined;
                }

                $act = $candidates[0];
                ++$checked;

                [$computed_uniq] = self::resolve_uniq_id(
                    $slug,
                    (string) ( $act[ $fm_uniq ] ?? '' ),
                    array(
                        'order_number' => (string) ( $act[ $fm_order ] ?? '' ),
                        'offer_id'     => (string) ( $act[ $fm_offer ] ?? '' ),
                        'action_type'  => (string) ( $act['action_type'] ?? '' ),
                        'click_id'     => (string) ( $act[ $click_field ] ?? '' ),
                    ),
                    $di
                );
                $stored_uniq   = (string) ( $row['uniq_id'] ?? '' );
                $computed_idem = hash('sha256', $slug . '|' . $computed_uniq);
                $stored_idem   = (string) ( $row['idempotency_key'] ?? '' );

                if ($computed_uniq !== '' && $computed_uniq !== $stored_uniq) {
                    $uniq_mismatch = array(
                        'sub'                  => 'uniq_mismatch',
                        'table'                => (string) $row['__table'],
                        'tx_id'                => (int) ( $row['id'] ?? 0 ),
                        'stored_uniq'          => $stored_uniq,
                        'computed_uniq'        => $computed_uniq,
                        'api_field_cron_reads' => $fm_uniq,
                        'api_field_value'      => (string) ( $act[ $fm_uniq ] ?? '' ),
                        'stored_idem'          => $stored_idem,
                        'computed_idem'        => $computed_idem,
                    );
                    break 2; // надёжный MISMATCH — дальше API не дёргаем.
                }
                if ($computed_uniq !== '' && $computed_uniq === $stored_uniq) {
                    if ($stored_idem !== '' && $computed_idem !== $stored_idem && $idem_mismatch === null) {
                        $idem_mismatch = array(
                            'sub'           => 'idempotency_formula_mismatch',
                            'table'         => (string) $row['__table'],
                            'tx_id'         => (int) ( $row['id'] ?? 0 ),
                            'stored_uniq'   => $stored_uniq,
                            'computed_uniq' => $computed_uniq,
                            'stored_idem'   => $stored_idem,
                            'computed_idem' => $computed_idem,
                        );
                        continue;
                    }
                    ++$match_count;
                }
            }
        }

        if ($uniq_mismatch !== null) {
            return array(
                'verdict' => 'mismatch',
                'network' => $slug,
                'checked' => $checked,
                'detail'  => $uniq_mismatch,
                'message' => 'РАССИНХРОН: крон-маппинг для uniq_id читает API-поле «'
                    . $uniq_mismatch['api_field_cron_reads'] . '» (значение «'
                    . $uniq_mismatch['api_field_value'] . '»), а webhook сохранил uniq_id «'
                    . $uniq_mismatch['stored_uniq'] . '». Вероятно, в кроне на uniq_id '
                    . 'повешено не то поле — одна конверсия запишется дважды с разными '
                    . 'uniq_id (двойное начисление). Проверьте api_field_map этой сети.',
            );
        }
        if ($idem_mismatch !== null) {
            return array(
                'verdict' => 'mismatch',
                'network' => $slug,
                'checked' => $checked,
                'detail'  => $idem_mismatch,
                'message' => 'uniq_id совпадает, но idempotency_key разошёлся: сохранённый «'
                    . $idem_mismatch['stored_idem'] . '» ≠ ожидаемый sha256(lower(slug)|uniq_id). '
                    . 'Расхождение формулы ключа между путями записи — риск дубля. '
                    . 'Сверьте формулу idempotency_key (lower(slug), не имя сети).',
            );
        }
        if ($match_count > 0) {
            // Codex iter-3 B-2: если групп пользователей больше бюджета
            // ($max_probes), часть осталась НЕ опрошена — buggy webhook-
            // строка могла быть там. MATCH в этом случае недостоверен →
            // INCONCLUSIVE (MISMATCH доминирует выше и валиден всегда).
            if (count($by_user) > $max_probes) {
                return array(
                    'verdict' => 'inconclusive',
                    'network' => $slug,
                    'checked' => $checked,
                    'reason'  => 'probe_budget_exhausted',
                    'message' => 'Совпало на ' . $checked . ' конверсии(ях), но проверено '
                        . 'только ' . $max_probes . ' из ' . count($by_user) . ' групп '
                        . 'пользователей (лимит обращений к API). MATCH недостоверен — '
                        . 'повторите позже или сузьте окно (это НЕ ошибка маппинга).',
                );
            }
            return array(
                'verdict' => 'match',
                'network' => $slug,
                'checked' => $checked,
                'message' => 'OK: на ' . $match_count . ' проверенной(ых) конверсии(ях) крон-резолвер '
                    . 'даёт тот же uniq_id и idempotency_key, что уже сохранён webhook-ом. '
                    . 'Маппинг webhook и крона согласован.',
            );
        }

        arsort($reason_tally);
        $top_reason = (string) ( array_key_first($reason_tally) ?: 'api_action_not_found' );
        $reason_text = array(
            'api_unavailable'      => 'API сети недоступен / лимит запросов — повторите позже (это НЕ ошибка маппинга).',
            'api_action_not_found' => 'API не вернул проверяемые конверсии в окне дат — повторите позже или на другой сети (это НЕ ошибка).',
            'ambiguous_match'      => 'Не удалось ОДНОЗНАЧНО сопоставить конверсию с API (split-order) — повторите на другой конверсии (это НЕ ошибка).',
            'no_usable_identifier' => 'У свежих строк нет идентификатора для запроса API — это НЕ ошибка маппинга.',
        );
        return array(
            'verdict' => 'inconclusive',
            'network' => $slug,
            'checked' => $checked,
            'reason'  => $top_reason,
            'message' => $reason_text[ $top_reason ] ?? 'Проверка неубедительна — повторите позже (это НЕ ошибка).',
        );
    }

    // =========================================================================
    // Validation — unregistered transactions
    // =========================================================================

    /**
     * Валидация незарегистрированных транзакций по API
     *
     * Аналог validate_user(), но работает с таблицей cashback_unregistered_transactions.
     * Загружает ВСЕ локальные незарегистрированные транзакции и сопоставляет их
     * с данными API по click_id / order_number.
     *
     * @param string $network_slug Slug сети (admitad, epn)
     * @param bool   $use_checkpoint Использовать инкрементальный чекпоинт
     * @return array Результат валидации
     */
    public function validate_unregistered( string $network_slug = 'admitad', bool $use_checkpoint = true ): array {
        global $wpdb;

        $network = $this->get_network_config($network_slug);
        if (!$network || empty($network['credentials'])) {
            return array(
                'success' => false,
                'error'   => 'Сеть не найдена или API не настроен: ' . $network_slug,
                'user_id' => 0,
                'network' => $network_slug,
            );
        }

        // ─── Определяем дату начала ───
        // Fallback 180 дней: см. default_lookback_date_dmy().
        $date_start = $this->default_lookback_date_dmy();

        if ($use_checkpoint) {
            // user_id = 0 для чекпоинта незарегистрированных
            $checkpoint = $this->get_checkpoint(0, $network_slug);
            if ($checkpoint && !empty($checkpoint['last_validated_date'])) {
                $dt = new DateTime($checkpoint['last_validated_date']);
                $dt->modify('-7 days');
                $date_start = $dt->format('d.m.Y');
            }
        }

        $date_end = ( new DateTime() )->format('d.m.Y');

        // ─── Локальные незарегистрированные транзакции ───
        $local_start  = DateTime::createFromFormat('d.m.Y', $date_start)->format('Y-m-d');
        $network_name = $network['name'] ?? '';

        $local_transactions = $wpdb->get_results($wpdb->prepare(
            'SELECT t.id, t.click_id, t.uniq_id, t.order_number, t.offer_name,
                    t.comission, t.cashback, t.order_status, t.partner,
                    t.sum_order, t.created_at, t.updated_at, t.user_id
             FROM %i t
             WHERE (LOWER(t.partner) = LOWER(%s) OR LOWER(t.partner) = LOWER(%s))
               AND t.created_at >= %s
             ORDER BY t.created_at',
            $this->unregistered_table,
            $network_slug,
            $network_name,
            $local_start
        ), ARRAY_A);

        if (empty($local_transactions)) {
            // Нет локальных транзакций — нечего проверять
            $this->update_checkpoint(0, $network_slug, array(
                'last_validated_date'      => ( new DateTime() )->format('Y-m-d'),
                'api_actions_count'        => 0,
                'local_transactions_count' => 0,
                'validation_status'        => 'match',
                'matched_count'            => 0,
                'mismatch_count'           => 0,
                'missing_local_count'      => 0,
                'missing_api_count'        => 0,
            ));

            return array(
                'success'        => true,
                'user_id'        => 0,
                'network'        => $network_slug,
                'status'         => 'match',
                'date_range'     => array(
					'start' => $date_start,
					'end'   => $date_end,
				),
                'api_total'      => 0,
                'local_total'    => 0,
                'matched_count'  => 0,
                'mismatch_count' => 0,
                'skipped_foreign_website' => 0,
                'missing_local'  => array(),
                'missing_api'    => array(),
                'mismatched'     => array(),
                'sums'           => array(
                    'api_approved'   => 0,
					'api_pending'    => 0,
					'api_declined'   => 0,
                    'local_approved' => 0,
					'local_pending'  => 0,
					'local_declined' => 0,
                    'discrepancy'    => 0,
                ),
            );
        }

        // ─── Индекс для матчинга ───
        // Идентичность транзакции = uniq_id (= API.action_id, уникальный ID в
        // рамках CPA-сети). click_id НЕ ключ: split-order siblings разделяют
        // один click_id, индексация по нему схлопывала бы их в одну запись →
        // ложные missing_local / mismatched в отчёте сверки.
        $local_by_uniq_id = array();

        foreach ($local_transactions as $tx) {
            if (!empty($tx['uniq_id'])) {
                $local_by_uniq_id[ $tx['uniq_id'] ] = $tx;
            }
        }

        // ─── Запрос к API ───
        // В БД user_id хранится как '0', но в API subid = 'unregistered'.
        // Запрашиваем API с subid = 'unregistered' (литеральное значение из партнёрской ссылки).
        $user_field = $network['api_user_field'] ?? 'subid2';

        $api_params = array(
            $user_field  => 'unregistered',
            'date_start' => $date_start,
            'date_end'   => $date_end,
        );

        if (!empty($network['api_website_id'])) {
            $api_params['website'] = $network['api_website_id'];
        }

        $api_result = $this->fetch_all_actions_for_network($network_slug, $network['credentials'], $api_params, 20, $network);

        $api_actions = array();
        if ($api_result['success'] && !empty($api_result['actions'])) {
            $api_actions = $api_result['actions'];
        } elseif (!$api_result['success']) {
            return array(
                'success' => false,
                'error'   => 'API error: ' . $api_result['error'],
                'user_id' => 0,
                'network' => $network_slug,
            );
        }

        // ─── Привязка к площадке (defense-in-depth) ───
        $website_filtered        = $this->filter_actions_by_website($api_actions, $network, 'validate_unregistered');
        $api_actions             = $website_filtered['actions'];
        $skipped_foreign_website = $website_filtered['skipped'];

        // ─── Маппинг статусов и полей ───
        $status_map  = $network['status_map'];
        $click_field = $network['api_click_field'] ?? 'subid1';

        // Маппинг полей API → локальные колонки (те же переменные что и в registered-блоке)
        $field_map      = $network['field_map'];
        $fm_payment     = $this->api_field_for('comission', $field_map) ?: 'payment';
        $fm_cart        = $this->api_field_for('sum_order', $field_map) ?: 'cart';
        $fm_uniq_id     = $this->api_field_for('uniq_id', $field_map) ?: 'action_id';
        $fm_order_id    = $this->api_field_for('order_number', $field_map) ?: 'order_id';
        $fm_offer_id    = $this->api_field_for('offer_id', $field_map) ?: 'advcampaign_id';
        $fm_offer_nm    = $this->api_field_for('offer_name', $field_map) ?: 'advcampaign_name';
        $fm_currency    = $this->api_field_for('currency', $field_map) ?: 'currency';
        $fm_action_date = $this->api_field_for('action_date', $field_map) ?: 'action_date';
        $fm_click_time  = $this->api_field_for('click_time', $field_map) ?: 'click_date';
        $fm_action_type = $this->api_field_for('action_type', $field_map) ?: 'action_type';
        $fm_website_id  = $this->api_field_for('website_id', $field_map) ?: 'website_id';

        // ─── Сравнение ───
        $matched       = array();
        $mismatched    = array();
        $missing_local = array();

        $api_sums   = array(
			'approved' => 0.0,
			'pending'  => 0.0,
			'declined' => 0.0,
		);
        $local_sums = array(
			'approved' => 0.0,
			'pending'  => 0.0,
			'declined' => 0.0,
		);

        $matched_click_ids           = array();
        $api_matched_local_click_ids = array();

        foreach ($api_actions as $action) {
            $api_click_id  = (string) ( $action[ $click_field ] ?? '' );
            $api_status    = strtolower($action['status'] ?? 'pending');
            $api_payment   = (float) ( $action[ $fm_payment ] ?? 0 );
            $api_cart      = (float) ( $action[ $fm_cart ] ?? 0 );
            $mapped_status = $status_map[ $api_status ] ?? 'waiting';

            // Подсчёт сумм по API
            if ($mapped_status === 'completed' || $mapped_status === 'balance') {
                $api_sums['approved'] += $api_payment;
            } elseif ($mapped_status === 'waiting') {
                $api_sums['pending'] += $api_payment;
            } elseif ($mapped_status === 'declined') {
                $api_sums['declined'] += $api_payment;
            }

            // ─── МАТЧИНГ по uniq_id ───
            // Идентичность = uniq_id (API.action_id). click_id НЕ ключ:
            // split-order siblings разделяют один click_id, но каждый —
            // отдельная транзакция со своим admitad_id/action_id.
            $local_tx      = null;
            $action_id_key = (string) ( $action[ $fm_uniq_id ] ?? '' );
            if ($action_id_key !== '' && isset($local_by_uniq_id[ $action_id_key ])) {
                $local_tx = $local_by_uniq_id[ $action_id_key ];
            }

            if (!$local_tx) {
                $missing_local[] = array(
                    'action_id'   => $action[ $fm_uniq_id ] ?? '',
                    'click_id'    => $api_click_id,
                    'order_id'    => $action[ $fm_order_id ] ?? '',
                    'status'      => $api_status,
                    'payment'     => $api_payment,
                    'cart'        => $api_cart,
                    'date'        => $action[ $fm_action_date ] ?? '',
                    'campaign'    => $action[ $fm_offer_nm ] ?? '',
                    'campaign_id' => $action[ $fm_offer_id ] ?? '',
                    'currency'    => $action[ $fm_currency ] ?? 'RUB',
                    'click_time'  => $action[ $fm_click_time ] ?? $action['click_time'] ?? $action['closing_date'] ?? '',
                    'action_type' => $action[ $fm_action_type ] ?? '',
                    'website_id'  => $action[ $fm_website_id ] ?? $action['website_name'] ?? $action['website'] ?? ( $network['api_website_id'] ?? '' ),
                    'funds_ready' => $this->resolve_funds_ready($action, $field_map),
                );
                continue;
            }

            // Запоминаем что эта локальная транзакция найдена в API (независимо от расхождений)
            if (!empty($local_tx['click_id'])) {
                $api_matched_local_click_ids[ $local_tx['click_id'] ] = true;
            }

            // Запоминаем что эта локальная транзакция сматчена
            if (!empty($local_tx['click_id'])) {
                $matched_click_ids[ $local_tx['click_id'] ] = true;
            }

            // ─── СРАВНЕНИЕ ───
            $local_status     = $local_tx['order_status'];
            $local_commission = (float) $local_tx['comission'];
            $local_cart       = (float) ( $local_tx['sum_order'] ?? 0 );

            // Суммы по локальным
            if ($local_status === 'completed' || $local_status === 'balance') {
                $local_sums['approved'] += $local_commission;
            } elseif ($local_status === 'waiting' || $local_status === 'hold') {
                $local_sums['pending'] += $local_commission;
            } elseif ($local_status === 'declined') {
                $local_sums['declined'] += $local_commission;
            }

            $approved_statuses = array( 'completed', 'balance' );
            $status_match      = ( $local_status === $mapped_status )
                || ( in_array($local_status, $approved_statuses, true)
                    && in_array($mapped_status, $approved_statuses, true) );

            $commission_match = abs($api_payment - $local_commission) < 0.001;

            $cart_match = ( $api_cart == 0 || $local_cart == 0 )
                || abs($api_cart - $local_cart) < 0.001;

            if ($status_match && $commission_match && $cart_match) {
                $matched[] = array(
                    'local_id'         => (int) $local_tx['id'],
                    'click_id'         => $api_click_id,
                    'api_status'       => $api_status,
                    'local_status'     => $local_status,
                    'api_payment'      => $api_payment,
                    'local_commission' => $local_commission,
                );
            } else {
                $mismatched[] = array(
                    'uniq_id'             => $local_tx['uniq_id'] ?? '',
                    'click_id'            => $api_click_id,
                    'local_id'            => $local_tx['id'],
                    'api_status'          => $api_status,
                    'local_status'        => $local_status,
                    'mapped_api_status'   => $mapped_status,
                    'api_payment'         => $api_payment,
                    'local_commission'    => $local_commission,
                    'api_cart'            => $api_cart,
                    'local_cart'          => $local_cart,
                    'status_mismatch'     => !$status_match,
                    'commission_mismatch' => !$commission_match,
                    'cart_mismatch'       => !$cart_match,
                    'action_id'           => $action[ $fm_uniq_id ] ?? '',
                    'order_id'            => $action[ $fm_order_id ] ?? '',
                );

                // Авто-обновляем расхождения — синхронизируем локальные данные с API
                $dummy_updated = 0;
                $dummy_skipped = 0;
                $dummy_errors  = 0;
                $this->sync_update_local(
                    $wpdb,
                    $this->unregistered_table,
                    $local_tx,
                    $mapped_status,
                    $api_payment,
                    $api_cart,
                    $network_slug,
                    $api_click_id,
                    $action,
                    $field_map,
                    $dummy_updated,
                    $dummy_skipped,
                    $dummy_errors
                );
            }
        }

        // ─── Обратная проверка: транзакции есть у нас, но нет в API ───
        $missing_api = array();
        foreach ($local_transactions as $tx) {
            // Пропускаем если транзакция найдена в API (полное совпадение или расхождение — неважно)
            if (!empty($tx['click_id']) && isset($api_matched_local_click_ids[ $tx['click_id'] ])) {
                continue;
            }
            if ($tx['order_status'] === 'balance') {
                continue;
            }
            if (empty($tx['click_id'])) {
                continue;
            }

            $missing_api[] = array(
                'local_id'         => $tx['id'],
                'uniq_id'          => $tx['uniq_id'] ?? '',
                'click_id'         => $tx['click_id'],
                'order_number'     => $tx['order_number'],
                'status'           => $tx['order_status'],
                'commission'       => (float) $tx['comission'],
                'sum_order'        => (float) ( $tx['sum_order'] ?? 0 ),
                'created'          => $tx['created_at'],
                // 1 = транзакция создана админом вручную (Сверка баланса → зависший claim).
                // Такая запись отсутствует в API by design — UI рендерит «Да» в столбце
                // «Добавлена админом», чтобы админ не искал причину «расхождения».
                'created_by_admin' => isset( $tx['created_by_admin'] ) ? (int) $tx['created_by_admin'] : 0,
            );
        }

        // ─── Обновляем api_verified для всех сматченных транзакций ───
        $matched_ids = array_column($matched, 'local_id');
        if (!empty($matched_ids)) {
            foreach (array_chunk($matched_ids, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %d literals; sniff can't see %d inside $placeholders.
                $wpdb->query( $wpdb->prepare( "UPDATE %i SET api_verified = 1 WHERE id IN ({$placeholders}) AND api_verified = 0", $this->unregistered_table, ...$chunk ) );
            }
        }

        // ─── Результат ───
        $has_issues        = !empty($mismatched) || !empty($missing_local) || !empty($missing_api);
        $total_checked     = count($api_actions);
        $validation_status = $has_issues ? 'mismatch' : 'match';
        $discrepancy       = abs($api_sums['approved'] - $local_sums['approved']);

        // Обновляем чекпоинт (user_id = 0 для незарегистрированных)
        $this->update_checkpoint(0, $network_slug, array(
            'last_validated_date'      => ( new DateTime() )->format('Y-m-d'),
            'api_sum_approved'         => $api_sums['approved'],
            'api_sum_pending'          => $api_sums['pending'],
            'api_sum_declined'         => $api_sums['declined'],
            'api_actions_count'        => $total_checked,
            'local_sum_approved'       => $local_sums['approved'],
            'local_sum_pending'        => $local_sums['pending'],
            'local_sum_declined'       => $local_sums['declined'],
            'local_transactions_count' => count($local_transactions),
            'validation_status'        => $validation_status,
            'discrepancy_amount'       => $discrepancy,
            'matched_count'            => count($matched),
            'mismatch_count'           => count($mismatched),
            'missing_local_count'      => count($missing_local),
            'missing_api_count'        => count($missing_api),
        ));

        return array(
            'success'        => true,
            'user_id'        => 0,
            'network'        => $network_slug,
            'status'         => $validation_status,
            'date_range'     => array(
				'start' => $date_start,
				'end'   => $date_end,
			),
            'api_total'      => $total_checked,
            'local_total'    => count($local_transactions),
            'matched_count'  => count($matched),
            'mismatch_count' => count($mismatched),
            'skipped_foreign_website' => $skipped_foreign_website,
            'missing_local'  => $missing_local,
            'missing_api'    => $missing_api,
            'mismatched'     => $mismatched,
            'sums'           => array(
                'api_approved'   => $api_sums['approved'],
                'api_pending'    => $api_sums['pending'],
                'api_declined'   => $api_sums['declined'],
                'local_approved' => $local_sums['approved'],
                'local_pending'  => $local_sums['pending'],
                'local_declined' => $local_sums['declined'],
                'discrepancy'    => $discrepancy,
            ),
        );
    }

    // =========================================================================
    // Background sync — обновление статусов через cron
    // =========================================================================

    /**
     * Фоновая синхронизация статусов по всем сетям
     *
     * Матчинг: API.subid1 → DB.click_id
     * Вместо N+1 запросов — загружаем все нужные транзакции одним SELECT
     * и индексируем в PHP.
     *
     * Вызывается через WP Cron каждые 2-4 часа.
     *
     * @return array Результаты синхронизации
     */
    public function background_sync(): array {
        // Защита от параллельного запуска через единый Cashback_Lock
        // (то же имя лока, что и у run_sync в Cashback_API_Cron — ранее здесь был
        // отдельный GET_LOCK с другим именем, что не защищало от гонки cron+manual).
        //
        // Реентерабельность: если вызвано из Cashback_API_Cron::run_sync(), lock
        // уже держится — в этом случае не захватываем и не освобождаем повторно.
        $outer_lock_held = Cashback_Lock::is_lock_held_by_current_process();

        if (!$outer_lock_held) {
            if (!Cashback_Lock::acquire(30)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback background_sync: could not acquire lock, another sync is running');
                return array();
            }
        }

        try {
            return $this->do_background_sync();
        } finally {
            if (!$outer_lock_held) {
                Cashback_Lock::release();
            }
        }
    }

    /**
     * Внутренняя логика фоновой синхронизации (вызывается под GET_LOCK).
     */
    private function do_background_sync(): array {
        global $wpdb;

        $results = array();

        $networks = $this->get_all_active_networks();

        foreach ($networks as $network) {
            $slug   = $network['slug'];
            $config = $this->get_network_config($slug);

            if (!$config || empty($config['credentials'])) {
                continue;
            }

            // Дата последней синхронизации
            $last_sync = get_option("cashback_last_sync_{$slug}", '');

            if (empty($last_sync)) {
                $date_start = ( new DateTime() )->modify('-30 days')->format('d.m.Y');
            } else {
                $dt = new DateTime($last_sync);
                $dt->modify('-1 day');
                $date_start = $dt->format('d.m.Y');
            }

            $date_end = ( new DateTime() )->format('d.m.Y');

            $sync_params = $this->build_background_sync_params($slug, $config, $date_start, $date_end);

            if (!empty($config['api_website_id'])) {
                $sync_params['website'] = $config['api_website_id'];
            }

            $api_result = $this->fetch_all_actions_for_network($slug, $config['credentials'], $sync_params, 20, $config);

            if (!$api_result['success']) {
                $results[ $slug ] = array(
					'success' => false,
					'error'   => $api_result['error'],
				);
                continue;
            }

            $api_actions = $api_result['actions'];

            // ─── Привязка к площадке (defense-in-depth) ───
            // Фильтруем ОДИН раз: индексы, batch-резолв юзеров и цикл
            // обработки ниже получают только действия своей площадки.
            $website_filtered        = $this->filter_actions_by_website($api_actions, $config, 'do_background_sync');
            $api_actions             = $website_filtered['actions'];
            $skipped_foreign_website = $website_filtered['skipped'];

            if (empty($api_actions)) {
                // Проверяем stale транзакции даже если нет свежих обновлений в API
                $decline_result   = $this->decline_stale_missing_transactions($config, $slug);
                $results[ $slug ] = array(
                    'success'               => true,
                    'total'                 => 0,
                    'updated'               => 0,
                    'skipped'               => 0,
                    'not_found'             => 0,
                    'inserted'              => 0,
                    'insert_errors'         => 0,
                    'skipped_foreign_website' => $skipped_foreign_website,
                    'declined_stale'        => ( $decline_result['declined_registered'] + $decline_result['declined_unregistered'] ),
                    'declined_stale_detail' => $decline_result,
                );
                update_option("cashback_last_sync_{$slug}", ( new DateTime() )->format('Y-m-d H:i:s'));
                continue;
            }

            // ─── Загружаем ВСЕ нужные локальные транзакции одним запросом ───
            $click_field  = $config['api_click_field'] ?? 'subid1';
            $network_name = $config['name'] ?? $slug;

            // Маппинг полей API → локальные колонки
            $field_map  = $config['field_map'];
            $fm_payment = $this->api_field_for('comission', $field_map) ?: 'payment';
            $fm_cart    = $this->api_field_for('sum_order', $field_map) ?: 'cart';
            $fm_uniq_id = $this->api_field_for('uniq_id', $field_map) ?: 'action_id';

            // Собираем action_id (uniq_id) из API-ответа — ЕДИНСТВЕННЫЙ ключ
            // идентичности транзакции. click_id НЕ используется для матчинга:
            // один клик легитимно порождает много действий (Admitad split-order,
            // по одному постбэку на позицию/тариф), каждое со своим admitad_id.
            $api_action_ids = array();
            foreach ($api_actions as $action) {
                $aid = $this->resolve_action_identity($config, $action);
                if ($aid !== '') {
                    $api_action_ids[] = $aid;
                }
            }

            // ─── Batch-запрос: cashback_transactions по uniq_id ───
            // Идентичность = (partner, uniq_id), совпадает с DB
            // UNIQUE(uniq_id, partner). click_id демонтирован до атрибуции.
            $local_map_by_uniq = array();
            if (!empty($api_action_ids)) {
                $placeholders = implode(',', array_fill(0, count($api_action_ids), '%s'));
                $query_args   = array_merge(array( $this->transactions_table ), $api_action_ids, array( $slug, $network_name ));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %s literals; sniff can't see %s inside $placeholders.
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id, click_id, uniq_id, order_status, decline_reason, comission, sum_order, api_verified FROM %i WHERE uniq_id IN ({$placeholders}) AND (LOWER(partner) = LOWER(%s) OR LOWER(partner) = LOWER(%s))", ...$query_args), ARRAY_A);

                foreach ($rows as $row) {
                    if (!isset($local_map_by_uniq[ $row['uniq_id'] ])) {
                        $local_map_by_uniq[ $row['uniq_id'] ] = $row;
                    }
                }
            }

            // ─── Batch-запрос: cashback_unregistered_transactions по uniq_id ───
            $unreg_map_by_uniq = array();
            if (!empty($api_action_ids)) {
                $placeholders = implode(',', array_fill(0, count($api_action_ids), '%s'));
                $query_args   = array_merge(array( $this->unregistered_table ), $api_action_ids, array( $slug, $network_name ));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %s literals; sniff can't see %s inside $placeholders.
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id, click_id, uniq_id, order_status, decline_reason, comission, sum_order, user_id, api_verified FROM %i WHERE uniq_id IN ({$placeholders}) AND (LOWER(partner) = LOWER(%s) OR LOWER(partner) = LOWER(%s))", ...$query_args), ARRAY_A);

                foreach ($rows as $row) {
                    if (!isset($unreg_map_by_uniq[ $row['uniq_id'] ])) {
                        $unreg_map_by_uniq[ $row['uniq_id'] ] = $row;
                    }
                }
            }

            // ─── Batch-проверка существования пользователей для INSERT ───
            // subid может содержать partner_token (hex, 32 chars) или legacy user_id (numeric)
            $user_field         = $config['api_user_field'] ?? 'subid';
            $potential_user_ids = array();
            $potential_tokens   = array();

            foreach ($api_actions as $action) {
                // Найдётся ли action в одной из таблиц (по uniq_id —
                // единственному ключу идентичности транзакции).
                $aid_check   = $this->resolve_action_identity($config, $action);
                $would_match = $aid_check !== ''
                    && ( isset($local_map_by_uniq[ $aid_check ]) || isset($unreg_map_by_uniq[ $aid_check ]) );

                if (!$would_match) {
                    $uid = (string) ( $action[ $user_field ] ?? '' );
                    if (is_numeric($uid) && (int) $uid > 0) {
                        $potential_user_ids[] = (int) $uid;
                    } elseif (preg_match('/^[0-9a-f]{32}$/', $uid)) {
                        $potential_tokens[] = $uid;
                    }
                }
            }

            // Batch-резолв partner_token → user_id
            $token_to_user = array();
            if (!empty($potential_tokens)) {
                $token_to_user = Mariadb_Plugin::resolve_partner_tokens_batch($potential_tokens);
                foreach ($token_to_user as $resolved_uid) {
                    $potential_user_ids[] = $resolved_uid;
                }
            }

            $existing_user_ids = array();
            if (!empty($potential_user_ids)) {
                $potential_user_ids = array_unique($potential_user_ids);
                $placeholders       = implode(',', array_fill(0, count($potential_user_ids), '%d'));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %d literals; sniff can't see %d inside $placeholders.
                $rows              = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE ID IN ({$placeholders})", $wpdb->users, ...$potential_user_ids ) );
                $existing_user_ids = array_flip(array_map('intval', $rows));
            }

            // ─── Обработка actions ───
            $status_map    = $config['status_map'];
            $updated       = 0;
            $skipped       = 0;
            $update_errors = 0;
            $not_found     = 0;
            $inserted      = 0;
            $insert_errors = 0;

            foreach ($api_actions as $action) {
                $api_click_id  = (string) ( $action[ $click_field ] ?? '' );
                $api_status    = strtolower($action['status'] ?? 'pending');
                $mapped_status = $status_map[ $api_status ] ?? 'waiting';
                $api_payment   = (float) ( $action[ $fm_payment ] ?? 0 );
                $api_cart      = (float) ( $action[ $fm_cart ] ?? 0 );

                // ─── Матчинг: cashback_transactions по uniq_id ───
                // Идентичность = (partner, uniq_id). click_id НЕ используется:
                // split-order siblings разделяют один click_id, но каждый —
                // отдельная транзакция со своим admitad_id/action_id.
                $local         = null;
                $action_id_key = $this->resolve_action_identity($config, $action);
                if ($action_id_key !== '' && isset($local_map_by_uniq[ $action_id_key ])) {
                    $local = $local_map_by_uniq[ $action_id_key ];
                }

                // ─── Если найдено в cashback_transactions — обновляем ───
                if ($local) {
                    $this->sync_update_local($wpdb, $this->transactions_table, $local, $mapped_status, $api_payment, $api_cart, $slug, $api_click_id, $action, $field_map, $updated, $skipped, $update_errors);
                    continue;
                }

                // ─── Матчинг: cashback_unregistered_transactions по uniq_id ───
                $unreg = null;
                if ($action_id_key !== '' && isset($unreg_map_by_uniq[ $action_id_key ])) {
                    $unreg = $unreg_map_by_uniq[ $action_id_key ];
                }

                // ─── Если найдено в unregistered — обновляем ───
                if ($unreg) {
                    $this->sync_update_local($wpdb, $this->unregistered_table, $unreg, $mapped_status, $api_payment, $api_cart, $slug, $api_click_id, $action, $field_map, $updated, $skipped, $update_errors);
                    continue;
                }

                // ─── Guard: cross-table UNIQUE KEY check ───
                // Защита от дубликатов для перенесённых транзакций (click_id=NULL случай):
                // если батч-карты пропустили строку, последний шанс найти её по UNIQUE KEY (uniq_id, partner).
                // Тот же resolved id, что и при матчинге (одно действие).
                $action_id_guard = $action_id_key;
                if ($action_id_guard !== '') {
                    $transferred = $wpdb->get_row($wpdb->prepare(
                        'SELECT id, click_id, uniq_id, order_status, decline_reason, comission, sum_order, api_verified
                         FROM %i
                         WHERE uniq_id = %s
                           AND (partner = LOWER(%s) OR partner = LOWER(%s))
                         LIMIT 1',
                        $this->transactions_table,
                        $action_id_guard,
                        $slug,
                        $network_name
                    ), ARRAY_A);

                    if ($transferred) {
                        $this->sync_update_local($wpdb, $this->transactions_table, $transferred, $mapped_status, $api_payment, $api_cart, $slug, $api_click_id, $action, $field_map, $updated, $skipped, $update_errors);
                        continue;
                    }
                }

                // ─── Не найдено нигде: INSERT новой транзакции ───
                $insert_result = $this->insert_missing_transaction($action, $config, $slug, $wpdb, $existing_user_ids);

                if ($insert_result['success']) {
                    ++$inserted;

                    $this->log_sync_insert(
                        $slug,
                        $insert_result['insert_id'],
                        (string) ( $action[ $fm_uniq_id ] ?? '' ),
                        $mapped_status,
                        $api_payment
                    );
                } elseif (!empty($insert_result['skipped_foreign_website'])) {
                    // Чужая площадка — не ошибка вставки (предохранитель,
                    // основной фильтр уже отсёк это выше). Считаем отдельно.
                    ++$skipped_foreign_website;
                } elseif (strpos($insert_result['error'], 'Duplicate') !== false) {
                    // Дубликат — не ошибка, транзакция уже есть
                    ++$skipped;
                } else {
                    ++$insert_errors;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            '[Cashback Sync] Insert failed for action_id=%s: %s',
                            $action[ $fm_uniq_id ] ?? 'unknown',
                            $insert_result['error']
                        ));
                    }
                }
            }

            // ─── Auto-decline stale транзакций, отсутствующих в API ───
            $decline_result = $this->decline_stale_missing_transactions($config, $slug);

            // Сохраняем время последней синхронизации
            update_option("cashback_last_sync_{$slug}", ( new DateTime() )->format('Y-m-d H:i:s'));

            $results[ $slug ] = array(
                'success'               => true,
                'total'                 => count($api_actions),
                'updated'               => $updated,
                'skipped'               => $skipped,
                'update_errors'         => $update_errors,
                'not_found'             => $not_found,
                'inserted'              => $inserted,
                'insert_errors'         => $insert_errors,
                'skipped_foreign_website' => $skipped_foreign_website,
                'declined_stale'        => ( $decline_result['declined_registered'] + $decline_result['declined_unregistered'] ),
                'declined_stale_detail' => $decline_result,
            );
        }

        return $results;
    }

    // =========================================================================
    // Background sync — helper methods
    // =========================================================================

    /**
     * Обновить локальную транзакцию при синхронизации
     *
     * Общая логика для cashback_transactions и cashback_unregistered_transactions.
     * Защиты: skip balance, skip downgrade completed → waiting.
     *
     * Атомарность (Group 8 Step 1, F-8-001):
     *   - Оборачивает UPDATE в START TRANSACTION;
     *   - берёт SELECT ... FOR UPDATE по id перед guard'ами, чтобы проверки
     *     работали на committed-состоянии, а не на stale $local;
     *   - ретраит 3× на deadlock / lock wait timeout;
     *   - семантика приоритета API vs админ-правок не меняется —
     *     существующие guard'ы (balance / completed→waiting /
     *     validate_status_transition) просто видят свежую строку.
     *
     * @param wpdb   $wpdb
     * @param string $table          Таблица для UPDATE
     * @param array  $local          Локальная запись (id, order_status, comission, sum_order)
     * @param string $mapped_status  Статус из API после маппинга
     * @param float  $api_payment    Комиссия из API
     * @param float  $api_cart       Сумма заказа из API
     * @param string $slug           Slug сети
     * @param string $api_click_id   Click ID из API
     * @param array  $action         Полный action из API
     * @param int    &$updated       Счётчик обновлённых (по ссылке)
     * @param int    &$skipped       Счётчик пропущенных (по ссылке)
     * @param int    &$update_errors Счётчик ошибок UPDATE (по ссылке)
     * @param bool   $in_transaction Если true — caller сам владеет TX (COMMIT/ROLLBACK/retry не делаем)
     */
    private function sync_update_local(
        \wpdb $wpdb,
        string $table,
        array $local,
        string $mapped_status,
        float $api_payment,
        float $api_cart,
        string $slug,
        string $api_click_id,
        array $action,
        array $field_map,
        int &$updated,
        int &$skipped,
        int &$update_errors,
        bool $in_transaction = false
    ): void {
        $owns_tx = ! $in_transaction;

        $apply = function () use (
            $wpdb,
            $table,
            $local,
            $mapped_status,
            $api_payment,
            $api_cart,
            $slug,
            $api_click_id,
            $action,
            $field_map,
            $owns_tx,
            &$updated,
            &$skipped,
            &$update_errors
        ): void {
            if ($owns_tx) {
                $wpdb->query('START TRANSACTION');
            }

            try {
                // Перечитываем строку под локом — guard'ы работают на committed-состоянии.
                $fresh = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT * FROM %i WHERE id = %d FOR UPDATE',
                        $table,
                        (int) $local['id']
                    ),
                    ARRAY_A
                );

                if ($wpdb->last_error) {
                    $this->throw_if_deadlock($wpdb);
                    ++$update_errors;
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log(sprintf(
                        '[Cashback Sync] SELECT FOR UPDATE error for %s id=%d: %s',
                        $table,
                        (int) $local['id'],
                        $wpdb->last_error
                    ));
                    if ($owns_tx) {
                        $wpdb->query('ROLLBACK');
                    }
                    return;
                }

                if (! $fresh) {
                    // Строка пропала (удалена админом / другим cron) — считаем skip.
                    ++$skipped;
                    if ($owns_tx) {
                        $wpdb->query('COMMIT');
                    }
                    return;
                }

                $local_status = (string) $fresh['order_status'];

                // Защита: balance — финальный, не трогаем
                if ($local_status === 'balance') {
                    ++$skipped;
                    if ($owns_tx) {
                        $wpdb->query('COMMIT');
                    }
                    return;
                }

                // Защита от понижения: completed не откатываем в waiting
                if ($local_status === 'completed' && $mapped_status === 'waiting') {
                    ++$skipped;
                    if ($owns_tx) {
                        $wpdb->query('COMMIT');
                    }
                    return;
                }

                // Обновляем если статус, комиссия или сумма заказа изменились.
                // Money-сравнение (F-8-003): заменяет float-epsilon `abs(...) >= 0.001`
                // на bit-exact сравнение BCMath-decimal — устраняет корневой класс
                // false-positive/false-negative при accumulation-артефактах float'а.
                $status_changed        = ( $local_status !== $mapped_status );
                $api_payment_canon     = number_format( $api_payment, 2, '.', '' );
                $api_cart_canon        = number_format( $api_cart, 2, '.', '' );
                $api_payment_money     = Cashback_Money::from_db_value( $api_payment_canon );
                $fresh_comission_money = Cashback_Money::from_db_value( (string) ( $fresh['comission'] ?? '0' ) );
                $fresh_sum_order_money = Cashback_Money::from_db_value( (string) ( $fresh['sum_order'] ?? '0' ) );
                $api_cart_money        = Cashback_Money::from_db_value( $api_cart_canon );
                $commission_changed    = ! $api_payment_money->equals( $fresh_comission_money );
                $cart_changed          = ! $api_cart_money->equals( $fresh_sum_order_money );
                $api_decline_reason    = $this->resolve_decline_reason($action, $field_map, $mapped_status);
                $fresh_decline_reason  = isset($fresh['decline_reason']) ? trim((string) $fresh['decline_reason']) : '';
                $reason_changed        = ( (string) ( $api_decline_reason ?? '' ) !== $fresh_decline_reason );
                $needs_verify          = empty($fresh['api_verified']);

                // funds_ready: определяется через маппинг, fallback — прямое чтение из адаптера
                $api_funds_ready   = $this->resolve_funds_ready($action, $field_map);
                $needs_funds_ready = ( $api_funds_ready === 1 && empty($fresh['funds_ready']) );

                if (! $status_changed && ! $commission_changed && ! $cart_changed && ! $reason_changed && ! $needs_verify && ! $needs_funds_ready) {
                    ++$skipped;
                    if ($owns_tx) {
                        $wpdb->query('COMMIT');
                    }
                    return;
                }

                $update_data    = array();
                $update_formats = array();

                if ($status_changed) {
                    $update_data['order_status'] = $mapped_status;
                    $update_formats[]            = '%s';
                }

                if ($commission_changed) {
                    // canonical decimal-string, не float (F-8-003).
                    $update_data['comission'] = $api_payment_money->to_db_value();
                    $update_formats[]         = '%s';
                }

                if ($cart_changed) {
                    // canonical decimal-string, не float (F-8-003).
                    $update_data['sum_order'] = $api_cart_money->to_db_value();
                    $update_formats[]         = '%s';
                }

                if ($reason_changed) {
                    $update_data['decline_reason'] = $api_decline_reason;
                    $update_formats[]              = '%s';
                }

                // Транзакция найдена в API — помечаем как проверенную
                if ($needs_verify) {
                    $update_data['api_verified'] = 1;
                    $update_formats[]            = '%d';
                }

                // CPA-сеть подтвердила готовность средств к снятию — обновляем funds_ready
                // Только нарастающее: 0→1, обратно не сбрасываем
                if ($needs_funds_ready) {
                    $update_data['funds_ready'] = 1;
                    $update_formats[]           = '%d';
                }

                // Status-transition валидация и пересчёт cashback выполняются
                // MariaDB-триггерами (cashback_tr_validate_status_transition[_unregistered]
                // SIGNAL'ит SQLSTATE '45000' на запрещённых переходах;
                // calculate_cashback_before_update пересчитывает cashback при
                // изменении comission). Поле cashback НЕ передаём в $update_data.

                $wpdb->update(
                    $table,
                    $update_data,
                    array( 'id' => (int) $fresh['id'] ),
                    $update_formats,
                    array( '%d' )
                );

                $update_err = (string) $wpdb->last_error;
                if ($update_err !== '') {
                    // Распознаём SIGNAL SQLSTATE '45000' от status-validation триггера.
                    // Запрещённые переходы — это БИЗНЕС-логика (CPA-сеть прислала
                    // невалидный переход), а не ошибка БД. Учитываем как skipped,
                    // НЕ как update_error → preserve current behavior.
                    if (self::is_status_transition_signal($update_err)) {
                        ++$skipped;
                        if ($owns_tx) {
                            $wpdb->query('COMMIT');
                        }
                        return;
                    }

                    $this->throw_if_deadlock($wpdb);
                    ++$update_errors;
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log(sprintf(
                        '[Cashback Sync] UPDATE error for %s id=%d: %s',
                        $table,
                        (int) $fresh['id'],
                        $update_err
                    ));
                    if ($owns_tx) {
                        $wpdb->query('ROLLBACK');
                    }
                    return;
                }

                // Codex round 16 (2026-05-10): `++$updated` перенесён ПОСЛЕ
                // reversal-логики и refetch-блока. Раньше он стоял здесь, и
                // последующий ROLLBACK (из reversal или refetch failure) не
                // отменял уже инкрементированный counter — sync stats
                // считали rolled-back UPDATE как successful (observability bug,
                // алертинг/retry опираются на эти счётчики).

                // Группа 14 (ledger-first, шаг D): reversal. Если транзакция уже
                // процессена в ledger (processed_at IS NOT NULL) и CPA-сеть изменила
                // статус completed → declined, списываем прежнее начисление через
                // запись type='adjustment' с reference_type='reversal'. В той же TX
                // декрементим cashback_user_balance.available_balance (clamp>=0) —
                // иначе пользователь видит неизменённый баланс до reconciliation-job'a.
                // Идемпотентно: UNIQUE idempotency_key = reversal_tx_{id} гарантирует
                // однократность даже при retry sync_update_local.
                if (
                    ! empty( $fresh['processed_at'] )
                    && $status_changed
                    && $table === $this->transactions_table
                    && $local_status === 'completed'
                    && $mapped_status === 'declined'
                ) {
                    $old_cashback = Cashback_Money::from_db_value( (string) ( $fresh['cashback'] ?? '0' ) );
                    if ( ! $old_cashback->is_zero() && ! $old_cashback->is_negative() ) {
                        $reversal_amount = bcmul( $old_cashback->to_db_value(), '-1', 2 );
                        $reversal_idem   = sprintf( 'reversal_tx_%d', (int) $fresh['id'] );
                        $ledger_table    = $wpdb->prefix . 'cashback_balance_ledger';
                        $balance_table   = $wpdb->prefix . 'cashback_user_balance';

                        $ledger_ok = $wpdb->query( $wpdb->prepare(
                            'INSERT INTO %i (user_id, type, amount, transaction_id, reference_type, idempotency_key)
                             VALUES (%d, %s, %s, %d, %s, %s)
                             ON DUPLICATE KEY UPDATE id = id',
                            $ledger_table,
                            (int) $fresh['user_id'],
                            'adjustment',
                            $reversal_amount,
                            (int) $fresh['id'],
                            'reversal',
                            $reversal_idem
                        ) );

                        if ( $ledger_ok === false ) {
                            $this->throw_if_deadlock( $wpdb );
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                            error_log( sprintf(
                                '[Cashback Sync] reversal ledger INSERT failed for tx id=%d: %s',
                                (int) $fresh['id'],
                                $wpdb->last_error
                            ) );
                            ++$update_errors;
                            if ( $owns_tx ) {
                                $wpdb->query( 'ROLLBACK' );
                            }
                            return;
                        }

                        if ( (int) $wpdb->rows_affected === 1 ) {
                            $cache_ok = $wpdb->query( $wpdb->prepare(
                                'UPDATE %i
                                 SET available_balance = GREATEST(0.00, available_balance - %s),
                                     version = version + 1
                                 WHERE user_id = %d',
                                $balance_table,
                                $old_cashback->to_db_value(),
                                (int) $fresh['user_id']
                            ) );
                            if ( $cache_ok === false ) {
                                $this->throw_if_deadlock( $wpdb );
                                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                                error_log( sprintf(
                                    '[Cashback Sync] reversal balance UPDATE failed for user_id=%d: %s',
                                    (int) $fresh['user_id'],
                                    $wpdb->last_error
                                ) );
                                ++$update_errors;
                                if ( $owns_tx ) {
                                    $wpdb->query( 'ROLLBACK' );
                                }
                                return;
                            }
                        }
                    }
                }

                // Codex adversarial-review round 8 (2026-05-10): после
                // MariaDB-only рефакторинга поле cashback пересчитывается
                // DB-триггером calculate_cashback_before_update и НЕ
                // присутствует в $update_data. Чтобы transaction_data_changed
                // payload не врал пользователю старым значением, рефетчим
                // свежий cashback из строки прямо в той же транзакции.
                //
                // Codex round 13 (2026-05-10): refetch ОБЯЗАТЕЛЕН только для
                // ветки `transaction_data_changed` (commission/cart change без
                // status change). enqueue_notification_on_update для status_changed
                // выходит раньше и не использует refetched cashback. Безусловный
                // refetch + ROLLBACK на любой transient SELECT failure отменял
                // status-only sync, который даже не нуждался в cashback. Узим
                // scope к ветке, где значение действительно нужно.
                $cashback_after_trigger = null;
                $needs_cashback_refetch = ( ! $status_changed )
                    && ( $commission_changed || $cart_changed );
                if ($needs_cashback_refetch) {
                    $cashback_after_trigger = $wpdb->get_var( $wpdb->prepare(
                        'SELECT cashback FROM %i WHERE id = %d',
                        $table,
                        (int) $fresh['id']
                    ) );

                    // Codex round 10 (2026-05-10): refetch — часть transactional
                    // success criteria для data_changed-уведомления. Если SELECT
                    // упал (lock-wait, deadlock) или вернул NULL — ROLLBACK,
                    // counter, return. throw_if_deadlock для retry на верхнем уровне.
                    $refetch_err = (string) $wpdb->last_error;
                    if ($refetch_err !== '' || $cashback_after_trigger === null) {
                        $this->throw_if_deadlock($wpdb);
                        ++$update_errors;
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            '[Cashback Sync] cashback refetch failed for %s id=%d: %s',
                            $table,
                            (int) $fresh['id'],
                            $refetch_err !== '' ? $refetch_err : 'row missing or NULL after UPDATE'
                        ));
                        if ($owns_tx) {
                            $wpdb->query('ROLLBACK');
                        }
                        return;
                    }
                }

                // Codex round 16 (2026-05-10): инкрементируем counter ТОЛЬКО
                // ПОСЛЕ всех rollback-capable работ (UPDATE error check на
                // 2298, reversal logic, refetch). Все ранние return'ы
                // делают `++$update_errors` + ROLLBACK, поэтому здесь мы
                // уже гарантированно committable.
                ++$updated;

                // Запись в очередь уведомлений (вместо MySQL триггера)
                $this->enqueue_notification_on_update(
                    $wpdb,
                    (int) $fresh['id'],
                    (int) $fresh['user_id'],
                    $local_status,
                    $mapped_status,
                    $status_changed,
                    $commission_changed,
                    $cart_changed,
                    $fresh,
                    $update_data,
                    $cashback_after_trigger !== null ? (string) $cashback_after_trigger : null
                );

                $this->log_sync_event(
                    $slug,
                    (int) $fresh['id'],
                    $api_click_id ?: ( $fresh['uniq_id'] ?? '' ),
                    $local_status,
                    $mapped_status,
                    $api_payment
                );

                if ($owns_tx) {
                    $wpdb->query('COMMIT');
                }
            } catch (\Throwable $e) {
                if ($owns_tx) {
                    $wpdb->query('ROLLBACK');
                }
                throw $e;
            }
        };

        if ($owns_tx) {
            $this->retry_on_sync_deadlock($apply, 3);
        } else {
            // Внешний TX: ретраим решение оставляем caller'у.
            $apply();
        }
    }

    /**
     * Выбрасывает RuntimeException, если $wpdb->last_error — deadlock / lock wait timeout.
     *
     * Вызывается после каждого wpdb-вызова внутри sync_update_local. На обычные
     * SQL-ошибки не реагирует — те обрабатываются штатно (++update_errors + ROLLBACK).
     */
    /**
     * Распознаёт SIGNAL SQLSTATE '45000' от MariaDB-триггеров validate_status_transition
     * в `$wpdb->last_error`. Используется в sync_update_local для отличия
     * бизнес-валидации (запрещённый переход → skipped) от реальной DB-ошибки.
     *
     * См. cashback_tr_validate_status_transition[_unregistered] в mariadb.php.
     *
     * @param string $last_error Содержимое $wpdb->last_error
     * @return bool true если ошибка от status-validation триггера
     */
    private static function is_status_transition_signal( string $last_error ): bool {
        $known_signals = array(
            'Изменение запрещено: запись с финальным статусом не может быть изменена.',
            'Понижение статуса до waiting запрещено.',
            'Перевод в balance возможен только из completed.',
            'Перевод в hold возможен только из completed.',
            'Из declined возможен переход только в completed.',
        );
        foreach ($known_signals as $signal_text) {
            if (strpos($last_error, $signal_text) !== false) {
                return true;
            }
        }
        return false;
    }

    private function throw_if_deadlock( \wpdb $wpdb ): void {
        $err = (string) $wpdb->last_error;
        if ($err === '') {
            return;
        }

        $is_deadlock = ( stripos($err, 'deadlock') !== false )
            || ( stripos($err, 'lock wait timeout') !== false )
            || ( strpos($err, '1213') !== false )
            || ( strpos($err, '1205') !== false );

        if ($is_deadlock) {
            throw new \RuntimeException(
                esc_html( sprintf('[Cashback Sync] deadlock/lock-wait: %s', $err) )
            );
        }
    }

    /**
     * Ретраит переданный callable до $max попыток при deadlock / lock wait timeout.
     *
     * Линейный back-off (50 мс × номер попытки). Не-deadlock исключения
     * пробрасываются сразу.
     *
     * @param callable $callback Замыкание с SQL-операцией (должно само открыть/закрыть TX).
     * @param int      $max      Максимальное число попыток (включая первую).
     */
    private function retry_on_sync_deadlock( callable $callback, int $max = 3 ): void {
        $attempt = 0;
        while (true) {
            try {
                $callback();
                return;
            } catch (\Throwable $e) {
                $msg         = (string) $e->getMessage();
                $is_deadlock = ( stripos($msg, 'deadlock') !== false )
                    || ( stripos($msg, 'lock wait timeout') !== false )
                    || ( strpos($msg, '1213') !== false )
                    || ( strpos($msg, '1205') !== false );
                if (! $is_deadlock || ++$attempt >= $max) {
                    throw $e;
                }
                // Линейный back-off: 50 мс, 100 мс.
                usleep(50000 * $attempt);
            }
        }
    }

    /**
     * Вставить отсутствующую транзакцию из API в локальную БД
     *
     * Определяет user_id из action, выбирает таблицу (registered / unregistered),
     * проверяет существование пользователя, формирует данные и вставляет.
     * Триггеры calculate_cashback_before_insert автоматически рассчитают cashback.
     *
     * @param array  $action             API action данные
     * @param array  $config             Конфигурация сети
     * @param string $slug               Slug сети
     * @param wpdb   $wpdb              WordPress DB
     * @param array  $existing_user_ids  Массив существующих user_id (из batch-проверки)
     * @return array ['success' => bool, 'insert_id' => int, 'table_type' => string, 'error' => string]
     */
    private function insert_missing_transaction(
        array $action,
        array $config,
        string $slug,
        \wpdb $wpdb,
        array $existing_user_ids
    ): array {
        $user_field   = $config['api_user_field'] ?? 'subid';
        $click_field  = $config['api_click_field'] ?? 'subid1';
        $status_map   = $config['status_map'] ?? array();
        $network_name = $config['name'] ?? $slug;

        // 0. Привязка к площадке — финальный предохранитель (вызывающие циклы
        // уже отфильтрованы, но insert не должен материализовать чужую площадку).
        if (!$this->action_in_configured_website($action, $config)) {
            $this->log_skipped_foreign_website('insert_missing_transaction', $action, $config);
            return array(
                'success'                 => false,
                'insert_id'               => 0,
                'table_type'              => '',
                'skipped_foreign_website' => true,
                'error'                   => 'Skipped: action belongs to a different website',
            );
        }

        // 1. Определяем user_id (subid может содержать partner_token или legacy user_id)
        $raw_user_id = (string) ( $action[ $user_field ] ?? '' );

        $is_unregistered = strtolower($raw_user_id) === 'unregistered'
            || $raw_user_id === ''
            || $raw_user_id === '0';

        // 1a. Попытка разрешить partner_token → user_id (новый формат)
        if (!$is_unregistered && !is_numeric($raw_user_id)) {
            $resolved_user_id = Mariadb_Plugin::resolve_partner_token($raw_user_id);
            if ($resolved_user_id !== null) {
                $raw_user_id = (string) $resolved_user_id;
            } else {
                // Не числовой и не валидный токен → unregistered
                $is_unregistered = true;
            }
        }

        // 2. Для зарегистрированных — проверяем существование WP-пользователя
        if (!$is_unregistered && is_numeric($raw_user_id)) {
            if (!isset($existing_user_ids[ (int) $raw_user_id ])) {
                $is_unregistered = true;
            }
        }

        // 2a. Фикс B: если всё ещё unregistered, но click_id известен —
        //     проверяем, была ли предыдущая конверсия с тем же click_id уже перенесена
        //     к реальному пользователю. Если да — новую покупку кладём туда же.
        $click_id_early = (string) ( $action[ $click_field ] ?? '' );
        if ($is_unregistered && $click_id_early !== '') {
            $prior = $wpdb->get_row($wpdb->prepare(
                'SELECT user_id FROM %i
                 WHERE click_id = %s LIMIT 1',
                $this->transactions_table,
                $click_id_early
            ), ARRAY_A);

            if ($prior && (int) $prior['user_id'] > 0) {
                $raw_user_id     = (string) (int) $prior['user_id'];
                $is_unregistered = false;
            }
        }

        // 3. Целевая таблица
        $table      = $is_unregistered ? $this->unregistered_table : $this->transactions_table;
        $table_type = $is_unregistered ? 'unregistered' : 'transactions';

        // 4. Маппинг статуса
        $api_status    = strtolower($action['status'] ?? 'pending');
        $mapped_status = $status_map[ $api_status ] ?? 'waiting';

        // 5. Извлекаем поля через маппинг
        $field_map = $config['field_map'];
        $mapped    = $this->apply_field_map($action, $field_map);

        // 6. Парсим даты (через маппинг).
        // API некоторых сетей (Admitad) отдаёт naive локальное время без
        // tz-маркера. Хранение — UTC (ADR utc-everywhere) и должно совпадать
        // со строкой webhook-receiver. Зону декларирует адаптер сети;
        // пусто → уже UTC, конверсии нет (EPN/Advcake не затронуты).
        $fm_action_date = $this->api_field_for('action_date', $field_map) ?: 'action_date';
        $fm_click_time  = $this->api_field_for('click_time', $field_map) ?: 'click_date';
        $adapter        = $this->get_adapter($slug);
        $api_tz         = ( $adapter instanceof Cashback_Network_Adapter_Base )
            ? $adapter->get_api_datetime_timezone()
            : '';
        // resolve_api_datetime: первый УСПЕШНО распарсенный кандидат (а не
        // первый непустой — F-3: present-but-garbage поле вроде Admitad
        // conversion_time=846 не должно блокировать деградацию к реальной
        // дате) + корректная network→UTC без двойной конверсии unix-ts (F-2).
        //
        // Кандидаты — ТОЛЬКО семантически верное поле: замапленное (из
        // api_field_map) и его каноническое raw-имя для этой колонки.
        // closing_date / action_time НЕ используем как fallback (Codex F-3):
        // это «дата закрытия/одобрения», не время покупки/клика — её
        // подстановка сфабриковала бы правдоподобный, но НЕВЕРНЫЙ timestamp
        // и перекосила бы hold-период / funds_ready. Если ни одно верное
        // поле не распарсилось — честный NULL (а не выдуманная дата).
        $action_date_mysql = self::resolve_api_datetime(
            array(
                $action[ $fm_action_date ] ?? '',
                $action['action_date']     ?? '',
            ),
            $api_tz
        );
        $click_time_mysql = self::resolve_api_datetime(
            array(
                $action[ $fm_click_time ] ?? '',
                $action['click_date']     ?? '',
                $action['click_time']     ?? '',
            ),
            $api_tz
        );

        // Универсальный резолвер — ТОТ ЖЕ id, что использовался при матчинге
        // выше и что произвёл бы realtime-webhook (app/identity.py). Для
        // встроенных сетей = verbatim native passthrough.
        $action_id   = $this->resolve_action_identity($config, $action);
        $click_id    = (string) ( $action[ $click_field ] ?? '' );
        $order_id    = (string) ( $mapped['order_number'] ?? '' );
        $payment     = (float) ( $mapped['comission'] ?? 0 );
        $cart        = (float) ( $mapped['sum_order'] ?? 0 );
        $campaign    = (string) ( $mapped['offer_name'] ?? '' );
        $campaign_id = $mapped['offer_id'] ?? null;
        $currency    = (string) ( $mapped['currency'] ?? $action['currency'] ?? 'RUB' );
        $action_type = (string) ( $mapped['action_type'] ?? $action['action_type'] ?? '' );
        $website_id  = (string) ( $mapped['website_id'] ?? $action['website_id'] ?? $action['website'] ?? ( $config['api_website_id'] ?? '' ) );

        // 7. Валидация валюты (ISO 4217)
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'RUB';
        }

        // 7a. funds_ready: через маппинг, fallback — адаптер
        $api_funds_ready = $this->resolve_funds_ready($action, $field_map);
        $decline_reason  = $this->resolve_decline_reason($action, $field_map, $mapped_status);

        // 8. action_id и network_name обязательны (части UNIQUE KEY unique_uniq_partner)
        if ($action_id === '') {
            return array(
				'success'    => false,
				'insert_id'  => 0,
				'table_type' => $table_type,
				'error'      => 'Missing action_id',
			);
        }
        if (empty($network_name)) {
            return array(
				'success'    => false,
				'insert_id'  => 0,
				'table_type' => $table_type,
				'error'      => 'Missing network name (partner)',
			);
        }

        // 9. Ключ идемпотентности (детерминистический — один action_id+slug = один ключ)
        // Канонический cross-path ключ: sha256( lower(slug) | uniq_id ).
        // Идентичен формуле webhook-receiver (app/db.py) и admin-пути →
        // idx_idempotency_key становится настоящим cross-path exactly-once
        // backstop (webhook/cron/manual коллизируют на одном действии).
        $idempotency_key = hash('sha256', strtolower($slug) . '|' . $action_id);

        // 10. Формируем данные для INSERT.
        // Money-поля (comission/sum_order) — canonical decimal-string + `%s` (F-8-003).
        $data = array(
            'user_id'         => $is_unregistered ? $raw_user_id : (int) $raw_user_id,
            'uniq_id'         => $action_id,
            'order_number'    => $order_id,
            // Имя сети ($config['name'] ?? $slug, вычислено выше), а НЕ slug:
            // webhook-receiver и все остальные строки пишут имя ("Admitad").
            // Дедуп на LOWER(partner) ловит обе формы; idempotency_key ниже
            // остаётся на lower(slug) — это отдельный cross-path ключ.
            'partner'         => $network_name,
            'comission'       => number_format($payment, 2, '.', ''),
            'sum_order'       => number_format($cart, 2, '.', ''),
            'order_status'    => $mapped_status,
            'decline_reason'  => $decline_reason,
            'offer_id'        => ( $campaign_id !== null && $campaign_id !== '' && $campaign_id !== 0 ) ? (int) $campaign_id : null,
            'offer_name'      => $campaign,
            'currency'        => $currency,
            'action_date'     => $action_date_mysql,
            'click_time'      => $click_time_mysql,
            'click_id'        => $click_id !== '' ? $click_id : null,
            'website_id'      => ( $website_id !== '' && $website_id !== '0' ) ? (int) $website_id : null,
            'action_type'     => $action_type !== '' ? $action_type : null,
            'api_verified'    => 1,
            'funds_ready'     => $api_funds_ready,
            'idempotency_key' => $idempotency_key,
        );

        $formats = array(
            $is_unregistered ? '%s' : '%d',  // user_id
            '%s',  // uniq_id
            '%s',  // order_number
            '%s',  // partner
            '%s',  // comission (money → decimal-string)
            '%s',  // sum_order (money → decimal-string)
            '%s',  // order_status
            '%s',  // decline_reason
            '%d',  // offer_id
            '%s',  // offer_name
            '%s',  // currency
            '%s',  // action_date
            '%s',  // click_time
            '%s',  // click_id
            '%d',  // website_id
            '%s',  // action_type
            '%d',  // api_verified
            '%d',  // funds_ready
            '%s',  // idempotency_key
        );

        // applied_cashback_rate и cashback заполняются BEFORE INSERT-триггером
        // calculate_cashback_before_insert (mariadb.php) — он читает rate из
        // user_profile и считает cashback = ROUND(comission * rate / 100).
        // Поля НЕ передаём в $data — БД проставит сама.

        // 12. Убираем NULL-значения (аналогично ajax_add_transaction)
        $clean_data    = array();
        $clean_formats = array();
        $i             = 0;
        foreach ($data as $key => $value) {
            if ($value !== null) {
                $clean_data[ $key ] = $value;
                $clean_formats[]    = $formats[ $i ];
            }
            ++$i;
        }

        // 13. INSERT (UNIQUE KEY на uniq_id+partner защищает от дубликатов)
        $result = $wpdb->insert($table, $clean_data, $clean_formats);

        if ($result === false || $wpdb->last_error) {
            $error = $wpdb->last_error;
            return array(
				'success'    => false,
				'insert_id'  => 0,
				'table_type' => $table_type,
				'error'      => $error ?: 'Unknown insert error',
			);
        }

        $insert_id = (int) $wpdb->insert_id;

        // Запись в очередь уведомлений (вместо MySQL триггера)
        if ($table_type === 'transactions') {
            $user_id_int = (int) ( $data['user_id'] ?? 0 );
            if ($user_id_int > 0) {
                $wpdb->insert(
                    $wpdb->prefix . 'cashback_notification_queue',
                    array(
                        'event_type'     => 'transaction_new',
                        'transaction_id' => $insert_id,
                        'user_id'        => $user_id_int,
                        'new_status'     => $data['order_status'] ?? 'waiting',
                    ),
                    array( '%s', '%d', '%d', '%s' )
                );
            }
        }

        return array(
			'success'    => true,
			'insert_id'  => $insert_id,
			'table_type' => $table_type,
			'error'      => '',
		);
    }

    /**
     * Залогировать событие INSERT в cashback_sync_log
     *
     * @param string $network_slug
     * @param int    $transaction_id ID вставленной записи
     * @param string $action_id     ID действия из API
     * @param string $status        Статус вставленной транзакции
     * @param float  $api_payment   Комиссия из API
     */
    private function log_sync_insert(
        string $network_slug,
        int $transaction_id,
        string $action_id,
        string $status,
        float $api_payment
    ): void {
        global $wpdb;

        $wpdb->insert($this->sync_log_table, array(
            'network_slug'   => $network_slug,
            'transaction_id' => $transaction_id,
            'action_id'      => $action_id,
            'old_status'     => 'not_found',
            'new_status'     => $status,
            'api_payment'    => $api_payment,
            'sync_type'      => 'cron',
            'synced_at'      => Cashback_Time::now_mysql(),
        ));
    }

    // =========================================================================
    // Auto-decline stale transactions missing from API
    // =========================================================================

    /**
     * Автоматическое отклонение устаревших транзакций, отсутствующих в API
     *
     * Находит транзакции со статусами 'waiting'/'hold', у которых:
     *   - updated_at старше 5 дней
     *   - есть click_id (для сверки с API)
     *   - partner совпадает с сетью
     * Затем запрашивает API за полный диапазон дат (без status_updated фильтра)
     * и отклоняет те, что не найдены в API.
     *
     * Безопасность:
     *   - НИКОГДА не трогает 'balance' (финальный, защищён триггером БД)
     *   - Проверяет 'waiting', 'hold' и 'completed' с updated_at > 5 дней
     *   - Каждое изменение логируется в cashback_sync_log с sync_type='auto_decline'
     *
     * @param array  $config Конфигурация сети (из get_network_config)
     * @param string $slug   Slug сети
     * @return array ['declined_registered' => int, 'declined_unregistered' => int, 'checked' => int, 'error' => string|null]
     */
    public function decline_stale_missing_transactions( array $config, string $slug ): array {
        global $wpdb;

        $result = array(
            'declined_registered'   => 0,
            'declined_unregistered' => 0,
            'checked'               => 0,
            'skipped_foreign_website' => 0,
            'error'                 => null,
        );

        if (empty($config['credentials'])) {
            return $result;
        }

        $network_name   = $config['name'] ?? $slug;
        $stale_interval = 5; // дней

        // ─── 1. Найти устаревшие транзакции в обеих таблицах ───

        $stale_registered = $wpdb->get_results($wpdb->prepare(
            "SELECT id, click_id, order_number, order_status, comission, created_at, updated_at
             FROM %i
             WHERE order_status IN ('waiting', 'hold', 'completed')
               AND click_id IS NOT NULL AND click_id != ''
               AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
               AND (LOWER(partner) = LOWER(%s) OR LOWER(partner) = LOWER(%s))
             ORDER BY created_at ASC",
            $this->transactions_table,
            $stale_interval,
            $slug,
            $network_name
        ), ARRAY_A);

        $stale_unregistered = $wpdb->get_results($wpdb->prepare(
            "SELECT id, click_id, order_number, order_status, comission, created_at, updated_at
             FROM %i
             WHERE order_status IN ('waiting', 'hold', 'completed')
               AND click_id IS NOT NULL AND click_id != ''
               AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
               AND (LOWER(partner) = LOWER(%s) OR LOWER(partner) = LOWER(%s))
             ORDER BY created_at ASC",
            $this->unregistered_table,
            $stale_interval,
            $slug,
            $network_name
        ), ARRAY_A);

        $all_stale = array_merge($stale_registered, $stale_unregistered);

        if (empty($all_stale)) {
            return $result;
        }

        $result['checked'] = count($all_stale);

        // ─── 2. Определить диапазон дат для API-запроса ───

        $earliest_date = null;
        foreach ($all_stale as $tx) {
            $created = $tx['created_at'];
            if ($earliest_date === null || $created < $earliest_date) {
                $earliest_date = $created;
            }
        }

        $dt_start = new DateTime($earliest_date);
        $dt_start->modify('-1 day');
        $date_start = $dt_start->format('d.m.Y');
        $date_end   = ( new DateTime() )->format('d.m.Y');

        // ─── 3. Запросить API (полный диапазон, без status_updated фильтра) ───

        if ($this->get_adapter($slug) instanceof Cashback_Advcake_Adapter) {
            $from = DateTime::createFromFormat('!d.m.Y', $date_start);
            $to   = DateTime::createFromFormat('!d.m.Y', $date_end);
            $api_params = array(
                'date_from' => $from instanceof DateTime ? $from->format('Y-m-d') : gmdate('Y-m-d', strtotime('-7 days')),
                'date_to'   => $to instanceof DateTime ? $to->format('Y-m-d') : gmdate('Y-m-d'),
            );
        } else {
            $api_params = array(
                'date_start' => $date_start,
                'date_end'   => $date_end,
            );
        }

        if (!empty($config['api_website_id'])) {
            $api_params['website'] = $config['api_website_id'];
        }

        $max_pages  = 20;
        $page_limit = 500;
        $api_result = $this->fetch_all_actions_for_network(
            $slug,
            $config['credentials'],
            $api_params,
            $max_pages,
            $config
        );

        if (!$api_result['success']) {
            $result['error'] = 'API error during stale check: ' . $api_result['error'];
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log('[Cashback Auto-Decline] ' . $result['error']);
            return $result;
        }

        // ─── Привязка к площадке (defense-in-depth) ───
        // Действия чужой площадки НЕ должны «спасать» stale-tx от отклонения:
        // фильтруем до построения click/order-индекса.
        $website_filtered           = $this->filter_actions_by_website($api_result['actions'], $config, 'decline_stale');
        $api_actions_list           = $website_filtered['actions'];
        $result['skipped_foreign_website'] = $website_filtered['skipped'];

        // ─── 4. Построить индекс API actions по click_id и order_id ───

        $click_field   = $config['api_click_field'] ?? 'subid1';
        $api_click_ids = array();
        $api_order_ids = array();

        $fm_order_id_stale = $this->api_field_for('order_number', $config['field_map']) ?: 'order_id';

        foreach ($api_actions_list as $action) {
            $cid = (string) ( $action[ $click_field ] ?? '' );
            if ($cid !== '') {
                $api_click_ids[ $cid ] = true;
            }
            $oid = (string) ( $action[ $fm_order_id_stale ] ?? '' );
            if ($oid !== '') {
                $api_order_ids[ $oid ] = true;
            }
        }

        // ─── 5. Защита пагинации ───
        // Если API вернул >= лимита пагинации, данные могут быть неполными.
        // Не отклоняем транзакции с created_at старше самой ранней API-записи.
        $pagination_limit_hit = ( count($api_actions_list) >= $max_pages * $page_limit );
        $earliest_api_date    = null;

        if ($pagination_limit_hit && !empty($api_actions_list)) {
            foreach ($api_actions_list as $a) {
                $ad     = (string) ( $a['action_date'] ?? $a['click_time'] ?? '' );
                $parsed = self::parse_api_date($ad);
                if ($parsed !== null && ( $earliest_api_date === null || $parsed < $earliest_api_date )) {
                    $earliest_api_date = $parsed;
                }
            }
        }

        // ─── 6. Определить какие stale транзакции отсутствуют в API ───

        $to_decline_registered   = array();
        $to_decline_unregistered = array();

        foreach ($stale_registered as $tx) {
            // Пропускаем если лимит пагинации и транзакция старше ранней API-записи
            if ($pagination_limit_hit && $earliest_api_date !== null && $tx['created_at'] < $earliest_api_date) {
                continue;
            }
            $found = false;
            if (!empty($tx['click_id']) && isset($api_click_ids[ $tx['click_id'] ])) {
                $found = true;
            }
            if (!$found && !empty($tx['order_number']) && isset($api_order_ids[ $tx['order_number'] ])) {
                $found = true;
            }
            if (!$found) {
                $to_decline_registered[] = $tx;
            }
        }

        foreach ($stale_unregistered as $tx) {
            if ($pagination_limit_hit && $earliest_api_date !== null && $tx['created_at'] < $earliest_api_date) {
                continue;
            }
            $found = false;
            if (!empty($tx['click_id']) && isset($api_click_ids[ $tx['click_id'] ])) {
                $found = true;
            }
            if (!$found && !empty($tx['order_number']) && isset($api_order_ids[ $tx['order_number'] ])) {
                $found = true;
            }
            if (!$found) {
                $to_decline_unregistered[] = $tx;
            }
        }

        // ─── 7. Батчевое отклонение: cashback_transactions ───
        //
        // Атомарность (Group 8 Step 2, F-8-002):
        //   - Батч 100 (короткое окно row-locks).
        //   - Каждый батч в своей TX + SELECT ... FOR UPDATE + UPDATE + COMMIT.
        //   - Под локом перечитываем статус: UPDATE идёт только по строкам,
        //     всё ещё находящимся в ('waiting', 'hold', 'completed'). Если
        //     параллельно админ / другой процесс перевели строку в balance /
        //     declined / иное — пропускаем, логирование только по реально
        //     декланутым.
        //   - Retry 3× на deadlock / lock-wait через retry_on_sync_deadlock (Step 1).

        if (! empty($to_decline_registered)) {
            $result['declined_registered'] = $this->run_auto_decline_batches(
                $wpdb,
                $this->transactions_table,
                $to_decline_registered,
                $slug
            );
        }

        // ─── 8. Батчевое отклонение: cashback_unregistered_transactions ───

        if (! empty($to_decline_unregistered)) {
            $result['declined_unregistered'] = $this->run_auto_decline_batches(
                $wpdb,
                $this->unregistered_table,
                $to_decline_unregistered,
                $slug
            );
        }

        // ─── 9. Итоговое логирование ───

        $total_declined = $result['declined_registered'] + $result['declined_unregistered'];
        if ($total_declined > 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
            error_log(sprintf(
                '[Cashback Auto-Decline] Network=%s: declined %d registered + %d unregistered (checked %d stale, API returned %d actions)',
                $slug,
                $result['declined_registered'],
                $result['declined_unregistered'],
                $result['checked'],
                count($api_actions_list)
            ));
        }

        return $result;
    }

    /**
     * Атомарно отклонить батчи stale-транзакций с row-level locking.
     *
     * Group 8 Step 2 (F-8-002). Для каждого чанка (до 100 id):
     *   - START TRANSACTION
     *   - SELECT id, order_status FROM %i WHERE id IN (...) FOR UPDATE
     *   - фильтр «всё ещё в ('waiting','hold','completed')»
     *   - UPDATE только отфильтрованных (post-check в WHERE)
     *   - COMMIT
     * На deadlock / lock-wait — retry_on_sync_deadlock (3×).
     * log_sync_auto_decline вызывается только для реально декланутых строк
     * (после COMMIT, вне TX — чтобы ошибка логирования не откатывала UPDATE).
     *
     * @param \wpdb  $wpdb
     * @param string $table       Таблица транзакций (registered или unregistered).
     * @param array  $to_decline  Кандидаты на decline (результаты фильтрации по API).
     * @param string $slug        Slug сети (для логов).
     * @return int Число реально декланутых строк.
     */
    private function run_auto_decline_batches( \wpdb $wpdb, string $table, array $to_decline, string $slug ): int {
        if (empty($to_decline)) {
            return 0;
        }

        $tx_by_id = array();
        foreach ($to_decline as $tx) {
            $tx_by_id[ (int) $tx['id'] ] = $tx;
        }
        $ids = array_keys($tx_by_id);

        $declined_total = 0;

        foreach (array_chunk($ids, 100) as $chunk) {
            $declined_in_batch = array();

            $this->retry_on_sync_deadlock(function () use ( $wpdb, $table, $chunk, &$declined_in_batch ) {
                $wpdb->query('START TRANSACTION');

                try {
                    $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is array_fill of %d literals; sniff can't see %d inside $placeholders.
                    $locked = $wpdb->get_results($wpdb->prepare("SELECT id, order_status FROM %i WHERE id IN ({$placeholders}) FOR UPDATE", $table, ...$chunk), ARRAY_A);

                    if ($wpdb->last_error) {
                        $this->throw_if_deadlock($wpdb);
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            '[Cashback Auto-Decline] SELECT FOR UPDATE error on %s: %s',
                            $table,
                            $wpdb->last_error
                        ));
                        $wpdb->query('ROLLBACK');
                        return;
                    }

                    // Фильтр под локом: оставить только ещё stale-строки.
                    $lockable_ids = array();
                    foreach (( $locked ?: array() ) as $row) {
                        $status = (string) ( $row['order_status'] ?? '' );
                        if (in_array($status, array( 'waiting', 'hold', 'completed' ), true)) {
                            $lockable_ids[] = (int) $row['id'];
                        }
                    }

                    if (empty($lockable_ids)) {
                        $wpdb->query('COMMIT');
                        return;
                    }

                    $ph2 = implode(',', array_fill(0, count($lockable_ids), '%d'));
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $ph2 is array_fill of %d literals; sniff can't see %d inside $ph2.
                    $wpdb->query($wpdb->prepare("UPDATE %i SET order_status = 'declined' WHERE id IN ({$ph2}) AND order_status IN ('waiting', 'hold', 'completed')", $table, ...$lockable_ids));

                    $batch_err = (string) $wpdb->last_error;
                    if ($batch_err !== '') {
                        $this->throw_if_deadlock($wpdb);
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                        error_log(sprintf(
                            '[Cashback Auto-Decline] UPDATE error on %s: %s',
                            $table,
                            $batch_err
                        ));
                        $wpdb->query('ROLLBACK');
                        return;
                    }

                    $wpdb->query('COMMIT');
                    $declined_in_batch = $lockable_ids;
                } catch (\Throwable $e) {
                    $wpdb->query('ROLLBACK');
                    throw $e;
                }
            }, 3);

            // Логирование вне TX — сбой лога не откатывает UPDATE.
            foreach ($declined_in_batch as $id) {
                if (! isset($tx_by_id[ $id ])) {
                    continue;
                }
                $tx = $tx_by_id[ $id ];
                $this->log_sync_auto_decline(
                    $slug,
                    $id,
                    (string) ( $tx['click_id'] ?? '' ),
                    (string) ( $tx['order_status'] ?? '' ),
                    (float) ( $tx['comission'] ?? 0 )
                );
            }

            $declined_total += count($declined_in_batch);
        }

        return $declined_total;
    }

    /**
     * Залогировать автоматическое отклонение в cashback_sync_log
     */
    private function log_sync_auto_decline(
        string $network_slug,
        int $transaction_id,
        string $click_id,
        string $old_status,
        float $commission
    ): void {
        global $wpdb;

        $wpdb->insert($this->sync_log_table, array(
            'network_slug'   => $network_slug,
            'transaction_id' => $transaction_id,
            'action_id'      => $click_id,
            'old_status'     => $old_status,
            'new_status'     => 'declined',
            'api_payment'    => $commission,
            'sync_type'      => 'auto_decline',
            'synced_at'      => Cashback_Time::now_mysql(),
        ));
    }

    // =========================================================================
    // Checkpoints
    // =========================================================================

    /**
     * Получить чекпоинт валидации пользователя
     */
    public function get_checkpoint( int $user_id, string $network_slug ): ?array {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE user_id = %d AND network_slug = %s',
            $this->checkpoints_table,
            $user_id,
            $network_slug
        ), ARRAY_A);
    }

    /**
     * Обновить чекпоинт валидации
     */
    public function update_checkpoint( int $user_id, string $network_slug, array $data ): bool {
        global $wpdb;

        $data['user_id']      = $user_id;
        $data['network_slug'] = $network_slug;
        $data['validated_at'] = Cashback_Time::now_mysql();
        $data['validated_by'] = get_current_user_id() ?: 0;

        $existing = $this->get_checkpoint($user_id, $network_slug);

        if ($existing) {
            $result = $wpdb->update(
                $this->checkpoints_table,
                $data,
                array(
					'user_id'      => $user_id,
					'network_slug' => $network_slug,
				)
            );
        } else {
            $result = $wpdb->insert($this->checkpoints_table, $data);
        }

        return $result !== false;
    }

    // =========================================================================
    // Sync log
    // =========================================================================

    // =========================================================================
    // Auto-transfer unregistered
    // =========================================================================

    /**
     * Автоматически переносит незарегистрированные транзакции к реальным пользователям.
     *
     * Ищет строки в cashback_unregistered_transactions, у которых click_id совпадает
     * с уже перенесённой транзакцией в cashback_transactions (т.е. пользователь был
     * идентифицирован ранее). Переносит их атомарно: INSERT + DELETE + audit_log.
     *
     * Запускается из крона каждые 2 часа после background_sync().
     *
     * @param int $limit Максимум строк за один вызов (default: 50)
     * @return array ['transferred' => int, 'skipped_duplicate' => int, 'errors' => int, 'checked' => int]
     */
    public function auto_transfer_unregistered( int $limit = 50 ): array {
        global $wpdb;

        $result = array(
            'transferred'       => 0,
            'skipped_duplicate' => 0,
            'errors'            => 0,
            'checked'           => 0,
        );

        // Одним JOIN находим кандидатов: unregistered строки, чей click_id уже есть
        // в cashback_transactions с реальным user_id.
        // Оба конца JOIN используют indexed click_id.
        $user_profile_table = $wpdb->prefix . 'cashback_user_profile';

        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT
                u.id            AS unreg_id,
                u.user_id       AS unreg_user_id,
                u.uniq_id,
                u.order_number,
                u.offer_id,
                u.offer_name,
                u.order_status,
                u.partner,
                u.sum_order,
                u.comission,
                u.currency,
                u.api_verified,
                u.action_date,
                u.click_time,
                u.click_id,
                u.website_id,
                u.action_type,
                u.processed_at,
                u.processed_batch_id,
                u.idempotency_key,
                u.spam_click,
                u.created_at,
                MAX(t.user_id)  AS real_user_id
             FROM %i u
             INNER JOIN %i t
                 ON t.click_id = u.click_id
             LEFT JOIN %i cup
                 ON cup.user_id = t.user_id
             WHERE u.click_id IS NOT NULL
               AND u.click_id != ''
               AND (u.user_id = '0' OR u.user_id = 'unregistered')
               AND t.user_id > 0
               AND (cup.status IS NULL OR cup.status NOT IN ('banned', 'deleted'))
             GROUP BY u.id
             ORDER BY u.id ASC
             LIMIT %d",
            $this->unregistered_table,
            $this->transactions_table,
            $user_profile_table,
            $limit
        ), ARRAY_A);

        if (empty($candidates)) {
            return $result;
        }

        $result['checked'] = count($candidates);

        foreach ($candidates as $candidate) {
            $wpdb->query('START TRANSACTION');
            $success = false;

            try {
                // Блокируем исходную строку перед переносом
                $tx = $wpdb->get_row($wpdb->prepare(
                    'SELECT * FROM %i
                     WHERE id = %d FOR UPDATE',
                    $this->unregistered_table,
                    (int) $candidate['unreg_id']
                ), ARRAY_A);

                if (!$tx) {
                    $wpdb->query('ROLLBACK');
                    ++$result['skipped_duplicate'];
                    continue;
                }

                // Проверка дубликата. Основной матч — по каноническому
                // idempotency_key (sha256(lower(slug)|action_id)): он НЕ
                // зависит от строки partner, поэтому ловит дубль даже при
                // смешанных формах ('adm' vs 'Admitad') — иначе висящая
                // unregistered-строка не вычищается (Codex F-4). Fallback
                // uniq_id+partner — для legacy-строк без idempotency_key.
                $tx_idem = (string) ( $tx['idempotency_key'] ?? '' );
                if (!empty($tx['uniq_id']) || $tx_idem !== '') {
                    $dup = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM %i
                         WHERE ( idempotency_key = %s AND %s != '' )
                            OR ( uniq_id = %s AND partner = %s AND %s != '' AND %s != '' )",
                        $this->transactions_table,
                        $tx_idem,
                        $tx_idem,
                        $tx['uniq_id'],
                        $tx['partner'],
                        $tx['uniq_id'],
                        $tx['partner']
                    ));
                    if ($dup > 0) {
                        // Уже существует — удаляем дубликат из unregistered
                        $wpdb->delete($this->unregistered_table, array( 'id' => (int) $candidate['unreg_id'] ), array( '%d' ));
                        $wpdb->query('COMMIT');
                        ++$result['skipped_duplicate'];
                        continue;
                    }
                }

                // INSERT в cashback_transactions с реальным user_id
                $insert_data = array(
                    'user_id'            => (int) $candidate['real_user_id'],
                    'order_number'       => $tx['order_number'],
                    'offer_id'           => $tx['offer_id'] !== null ? (int) $tx['offer_id'] : null,
                    'offer_name'         => $tx['offer_name'],
                    'order_status'       => $tx['order_status'],
                    'partner'            => $tx['partner'],
                    'sum_order'          => $tx['sum_order'],
                    'comission'          => $tx['comission'],
                    'currency'           => $tx['currency'],
                    'uniq_id'            => $tx['uniq_id'],
                    'api_verified'       => (int) $tx['api_verified'],
                    'action_date'        => $tx['action_date'],
                    'click_time'         => $tx['click_time'],
                    'click_id'           => $tx['click_id'],
                    'website_id'         => $tx['website_id'] !== null ? (int) $tx['website_id'] : null,
                    'action_type'        => $tx['action_type'],
                    'processed_at'       => $tx['processed_at'],
                    'processed_batch_id' => $tx['processed_batch_id'],
                    'idempotency_key'    => $tx['idempotency_key'],
                    'spam_click'         => (int) $tx['spam_click'],
                    'created_at'         => $tx['created_at'],
                );

                // Убираем NULL-значения, чтобы не переписывать DEFAULT и триггеры
                $insert_data = array_filter($insert_data, static function ( $v ) {
                    return $v !== null;
                });

                $inserted = $wpdb->insert($this->transactions_table, $insert_data);

                if ($inserted === false || $wpdb->last_error) {
                    $err = $wpdb->last_error;
                    $wpdb->query('ROLLBACK');
                    ++$result['errors'];
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                    error_log(sprintf(
                        '[Cashback AutoTransfer] INSERT failed for unreg_id=%d: %s',
                        (int) $candidate['unreg_id'],
                        $err
                    ));
                    continue;
                }

                $new_id = (int) $wpdb->insert_id;

                // Удаляем исходную строку
                $wpdb->delete($this->unregistered_table, array( 'id' => (int) $candidate['unreg_id'] ), array( '%d' ));
                $wpdb->query('COMMIT');
                $success = true;

                // Уведомление о новой транзакции обрабатывается через MySQL триггер → очередь → WP Cron

                // Аудит-лог
                if (class_exists('Cashback_Encryption')) {
                    Cashback_Encryption::write_audit_log(
                        'unregistered_transaction_auto_transferred',
                        0, // системный актор
                        'transaction',
                        $new_id,
                        array(
                            'source_id'   => (int) $candidate['unreg_id'],
                            'target_user' => (int) $candidate['real_user_id'],
                            'click_id'    => $tx['click_id'],
                            'uniq_id'     => $tx['uniq_id'],
                            'partner'     => $tx['partner'],
                        )
                    );
                }

                ++$result['transferred'];

            } catch (\Throwable $e) {
                if (!$success) {
                    $wpdb->query('ROLLBACK');
                }
                ++$result['errors'];
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log(sprintf(
                    '[Cashback AutoTransfer] Exception for unreg_id=%d: %s',
                    (int) $candidate['unreg_id'],
                    $e->getMessage()
                ));
            }
        }

        return $result;
    }

    /**
     * Записать уведомление в очередь после UPDATE транзакции (вместо MySQL триггера)
     *
     * Обрабатывает два случая:
     * 1. Смена статуса → event_type = 'transaction_status'
     * 2. Изменение комиссии/суммы без смены статуса → event_type = 'transaction_data_changed'
     */
    private function enqueue_notification_on_update(
        \wpdb $wpdb,
        int $transaction_id,
        int $user_id,
        string $old_status,
        string $new_status,
        bool $status_changed,
        bool $commission_changed,
        bool $cart_changed,
        array $local,
        array $update_data,
        ?string $new_cashback_after_trigger = null
    ): void {
        if ($user_id <= 0) {
            return;
        }

        $queue_table = $wpdb->prefix . 'cashback_notification_queue';

        // Смена статуса (исключаем balance — для него есть отдельное уведомление cashback_credited)
        if ($status_changed && $new_status !== 'balance') {
            $wpdb->insert(
                $queue_table,
                array(
                    'event_type'     => 'transaction_status',
                    'transaction_id' => $transaction_id,
                    'user_id'        => $user_id,
                    'old_status'     => $old_status,
                    'new_status'     => $new_status,
                ),
                array( '%s', '%d', '%d', '%s', '%s' )
            );
            return;
        }

        // Изменение комиссии или суммы заказа без смены статуса
        if (( $commission_changed || $cart_changed ) && !$status_changed) {
            $old_comission = (float) ( $local['comission'] ?? 0 );
            $new_comission = isset($update_data['comission']) ? (float) $update_data['comission'] : $old_comission;
            $old_sum_order = (float) ( $local['sum_order'] ?? 0 );
            $new_sum_order = isset($update_data['sum_order']) ? (float) $update_data['sum_order'] : $old_sum_order;
            $old_cashback = (float) ( $local['cashback'] ?? 0 );
            // Codex round 9 (2026-05-10): fail-closed на refetch'е. После
            // MariaDB-only рефакторинга cashback пересчитывает DB-триггер,
            // и если caller не смог рефетчить свежее значение (SQL-сбой,
            // deadlock, lock-wait timeout, гонка с DELETE) — мы НЕ имеем
            // права подставить old_cashback в payload, иначе уведомление
            // снова будет врать пользователю. Лучше silent-skip enqueue
            // с error_log, чем silent-lying payload.
            if ($new_cashback_after_trigger === null) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log(sprintf(
                    '[Cashback Sync] refetched cashback was null for tx id=%d (commission_changed=%s, cart_changed=%s) — transaction_data_changed notification skipped to avoid stale payload',
                    $transaction_id,
                    $commission_changed ? '1' : '0',
                    $cart_changed ? '1' : '0'
                ));
                return;
            }
            $new_cashback = (float) $new_cashback_after_trigger;

            $extra = wp_json_encode(array(
                'old_comission' => $old_comission,
                'new_comission' => $new_comission,
                'old_sum_order' => $old_sum_order,
                'new_sum_order' => $new_sum_order,
                'old_cashback'  => $old_cashback,
                'new_cashback'  => $new_cashback,
            ));

            $wpdb->insert(
                $queue_table,
                array(
                    'event_type'     => 'transaction_data_changed',
                    'transaction_id' => $transaction_id,
                    'user_id'        => $user_id,
                    'new_status'     => $new_status,
                    'extra_data'     => $extra,
                ),
                array( '%s', '%d', '%d', '%s', '%s' )
            );
        }
    }

    /**
     * Залогировать событие синхронизации
     */
    private function log_sync_event(
        string $network_slug,
        int $transaction_id,
        string $match_key,
        string $old_status,
        string $new_status,
        float $api_payment
    ): void {
        global $wpdb;

        $wpdb->insert($this->sync_log_table, array(
            'network_slug'   => $network_slug,
            'transaction_id' => $transaction_id,
            'action_id'      => $match_key,
            'old_status'     => $old_status,
            'new_status'     => $new_status,
            'api_payment'    => $api_payment,
            'synced_at'      => Cashback_Time::now_mysql(),
        ));
    }

    // =========================================================================
    // Campaign Status Check — автоматическая деактивация магазинов
    // =========================================================================

    /**
     * Проверить статусы кампаний во всех активных сетях и деактивировать/реактивировать товары
     *
     * Логика:
     * 1. Для каждой активной сети получаем список кампаний через adapter->fetch_campaigns()
     * 2. Сопоставляем кампании с товарами WooCommerce через _offer_id post_meta
     * 3. Если кампания неактивна или отсутствует — товар переводится в draft
     * 4. Если ранее деактивированный товар — кампания снова активна — реактивируем
     * 5. Email-уведомление админу при деактивации
     *
     * Защита от ложных деактиваций:
     * - Если API вернул ошибку — ни один товар не трогается
     * - Товары без _offer_id пропускаются
     * - Реактивируются только автоматически деактивированные (_cashback_auto_deactivated = '1')
     *
     * @return array Результаты по каждой сети
     */
    public function check_campaign_statuses( ?string $only_slug = null ): array {
        global $wpdb;

        $results  = array();
        $networks = $this->get_all_active_networks();

        foreach ($networks as $network) {
            $slug       = $network['slug'] ?? '';
            $network_id = (int) ( $network['id'] ?? 0 );

            if ($only_slug !== null && $slug !== $only_slug) {
                continue;
            }

            if (empty($slug) || $network_id <= 0) {
                continue;
            }

            $config = $this->get_network_config($slug);
            if (!$config || empty($config['credentials'])) {
                $results[ $slug ] = array(
                    'success' => false,
                    'error'   => 'No credentials configured',
                );
                continue;
            }

            $adapter = $this->get_adapter($slug);
            if (!$adapter) {
                $results[ $slug ] = array(
                    'success' => false,
                    'error'   => 'No adapter found for: ' . $slug,
                );
                continue;
            }

            // Получаем список кампаний из CPA-сети
            $campaign_result = $adapter->fetch_campaigns($config['credentials'], $config);

            if (!$campaign_result['success']) {
                $results[ $slug ] = array(
                    'success' => false,
                    'error'   => $campaign_result['error'],
                );
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log(sprintf(
                    'Cashback Campaign Check [%s]: API error — %s',
                    $slug,
                    $campaign_result['error']
                ));
                continue;
            }

            // Строим карту: campaign_id => campaign_data
            $campaign_map = array();
            foreach ($campaign_result['campaigns'] as $campaign) {
                $cid = (string) $campaign['id'];
                if ($cid !== '') {
                    $campaign_map[ $cid ] = $campaign;
                }
            }

            // Защита: если API вернул 0 кампаний — возможна ошибка API, не деактивируем
            if (empty($campaign_map)) {
                $results[ $slug ] = array(
                    'success' => false,
                    'error'   => 'API вернул 0 кампаний — возможна проблема с API (неверный scope, token или website_id)',
                );
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
                error_log('Cashback Campaign Check [' . $slug . ']: ' . $results[ $slug ]['error']);
                continue;
            }

            // Находим все опубликованные товары привязанные к этой сети
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $published_products = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, pm_offer.meta_value AS offer_id
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_net ON p.ID = pm_net.post_id AND pm_net.meta_key = '_affiliate_network_id'
                 LEFT JOIN {$wpdb->postmeta} pm_offer ON p.ID = pm_offer.post_id AND pm_offer.meta_key = '_offer_id'
                 WHERE pm_net.meta_value = %d
                   AND p.post_status = 'publish'
                   AND p.post_type = 'product'",
                $network_id
            ), ARRAY_A) ?: array();

            $deactivated = 0;
            $reactivated = 0;
            $skipped     = 0;

            foreach ($published_products as $row) {
                $product_id       = (int) $row['ID'];
                $product_offer_id = trim((string) ( $row['offer_id'] ?? '' ));

                if ($product_offer_id === '') {
                    ++$skipped;
                    continue;
                }

                $campaign = $campaign_map[ $product_offer_id ] ?? null;

                if ($campaign === null) {
                    // Кампания не найдена в API — возможно удалена
                    $this->deactivate_product(
                        $product_id,
                        $slug,
                        $product_offer_id,
                        'Кампания не найдена в API CPA-сети'
                    );
                    ++$deactivated;
                    continue;
                }

                if (!$campaign['is_active']) {
                    $reason = sprintf(
                        'Кампания «%s» деактивирована в %s (status: %s, connection: %s)',
                        $campaign['name'],
                        strtoupper($slug),
                        $campaign['status'],
                        $campaign['connection_status']
                    );
                    $this->deactivate_product($product_id, $slug, $product_offer_id, $reason);
                    ++$deactivated;
                }
            }

            // Проверяем ранее деактивированные товары на реактивацию.
            // Дополнительный INNER JOIN на `_cashback_auto_publish_enabled='1'`:
            // авто-реактивация делается ТОЛЬКО для товаров, у которых админ
            // явно включил переключатель «Автопубликация» в Publish-метабоксе
            // (см. Cashback_Product_Autopublish). Свежеимпортированные draft
            // (без переключателя) и снятые админом вручную не публикуются
            // обратно автоматически.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $draft_products = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, pm_offer.meta_value AS offer_id
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_net ON p.ID = pm_net.post_id AND pm_net.meta_key = '_affiliate_network_id'
                 INNER JOIN {$wpdb->postmeta} pm_deact ON p.ID = pm_deact.post_id AND pm_deact.meta_key = '_cashback_auto_deactivated'
                 INNER JOIN {$wpdb->postmeta} pm_autopub ON p.ID = pm_autopub.post_id
                    AND pm_autopub.meta_key = '_cashback_auto_publish_enabled'
                    AND pm_autopub.meta_value = '1'
                 LEFT JOIN {$wpdb->postmeta} pm_offer ON p.ID = pm_offer.post_id AND pm_offer.meta_key = '_offer_id'
                 WHERE pm_net.meta_value = %d
                   AND p.post_status = 'draft'
                   AND p.post_type = 'product'
                   AND pm_deact.meta_value = '1'",
                $network_id
            ), ARRAY_A) ?: array();

            foreach ($draft_products as $row) {
                $product_id       = (int) $row['ID'];
                $product_offer_id = trim((string) ( $row['offer_id'] ?? '' ));

                if ($product_offer_id === '') {
                    continue;
                }

                $campaign = $campaign_map[ $product_offer_id ] ?? null;

                if ($campaign !== null && $campaign['is_active']) {
                    $this->reactivate_product($product_id, $slug, $product_offer_id, $campaign['name']);
                    ++$reactivated;
                }
            }

            // Сохраняем снимок статусов кампаний для админки
            update_option("cashback_campaign_status_{$slug}", array(
                'timestamp' => Cashback_Time::now_mysql(),
                'total'     => count($campaign_result['campaigns']),
                'active'    => count(array_filter($campaign_result['campaigns'], fn( $c ) => $c['is_active'])),
                'inactive'  => count(array_filter($campaign_result['campaigns'], fn( $c ) => !$c['is_active'])),
                'campaigns' => array_map(function ( $c ) {
                    return array(
                        'id'                => $c['id'],
                        'name'              => $c['name'],
                        'is_active'         => $c['is_active'],
                        'status'            => $c['status'],
                        'connection_status' => $c['connection_status'],
                    );
                }, $campaign_result['campaigns']),
            ), false);

            $results[ $slug ] = array(
                'success'         => true,
                'total_campaigns' => count($campaign_result['campaigns']),
                'deactivated'     => $deactivated,
                'reactivated'     => $reactivated,
                'skipped'         => $skipped,
            );
        }

        // Email-уведомление при деактивации
        $total_deactivated = 0;
        foreach ($results as $r) {
            if ($r['success'] ?? false) {
                $total_deactivated += ( $r['deactivated'] ?? 0 );
            }
        }
        if ($total_deactivated > 0) {
            $this->send_campaign_deactivation_notification($results);
        }

        return $results;
    }

    /**
     * Деактивировать товар WooCommerce (перевести в draft) из-за отключения кампании
     *
     * @param int    $product_id  ID товара
     * @param string $network_slug Slug CPA-сети
     * @param string $offer_id    ID кампании/оффера
     * @param string $reason      Причина деактивации
     */
    private function deactivate_product( int $product_id, string $network_slug, string $offer_id, string $reason ): void {
        // Проверяем: не деактивирован ли уже
        if (get_post_meta($product_id, '_cashback_auto_deactivated', true) === '1') {
            return;
        }

        wp_update_post(array(
            'ID'          => $product_id,
            'post_status' => 'draft',
        ));

        update_post_meta($product_id, '_cashback_auto_deactivated', '1');
        update_post_meta($product_id, '_cashback_deactivation_reason', $reason);
        update_post_meta($product_id, '_cashback_deactivated_at', Cashback_Time::now_mysql());
        update_post_meta($product_id, '_cashback_deactivated_network', $network_slug);

        // Аудит-лог
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'store_auto_deactivated',
                0,
                'product',
                $product_id,
                array(
                    'network_slug' => $network_slug,
                    'offer_id'     => $offer_id,
                    'reason'       => $reason,
                )
            );
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log(sprintf(
            'Cashback Campaign Check: Product #%d deactivated (network: %s, offer: %s) — %s',
            $product_id,
            $network_slug,
            $offer_id,
            $reason
        ));
    }

    /**
     * Реактивировать ранее автоматически деактивированный товар
     *
     * @param int    $product_id    ID товара
     * @param string $network_slug  Slug CPA-сети
     * @param string $offer_id      ID кампании/оффера
     * @param string $campaign_name Название кампании
     */
    private function reactivate_product( int $product_id, string $network_slug, string $offer_id, string $campaign_name ): void {
        wp_update_post(array(
            'ID'          => $product_id,
            'post_status' => 'publish',
        ));

        delete_post_meta($product_id, '_cashback_auto_deactivated');
        delete_post_meta($product_id, '_cashback_deactivation_reason');
        delete_post_meta($product_id, '_cashback_deactivated_at');
        delete_post_meta($product_id, '_cashback_deactivated_network');

        // Аудит-лог
        if (class_exists('Cashback_Encryption')) {
            Cashback_Encryption::write_audit_log(
                'store_auto_reactivated',
                0,
                'product',
                $product_id,
                array(
                    'network_slug'  => $network_slug,
                    'offer_id'      => $offer_id,
                    'campaign_name' => $campaign_name,
                )
            );
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin diagnostic logging.
        error_log(sprintf(
            'Cashback Campaign Check: Product #%d reactivated (network: %s, campaign: %s)',
            $product_id,
            $network_slug,
            $campaign_name
        ));
    }

    /**
     * Отправить email-уведомление администратору о деактивированных магазинах
     *
     * @param array $results Результаты check_campaign_statuses()
     */
    private function send_campaign_deactivation_notification( array $results ): void {
        if (!class_exists('Cashback_Email_Sender') || !class_exists('Cashback_Email_Builder')) {
            return;
        }

        $site_name = get_bloginfo('name');
        $subject   = sprintf(
            /* translators: %s: site name */
            __('[Cashback] %s: Магазины деактивированы из-за отключения кампаний', 'cashback-plugin'),
            $site_name
        );

        $dump  = "Отчёт о статусах кампаний CPA-сетей\n";
        $dump .= str_repeat('=', 50) . "\n";
        $dump .= sprintf("Дата: %s\n\n", Cashback_Time::now_mysql());

        foreach ($results as $network => $result) {
            if (!( $result['success'] ?? false )) {
                continue;
            }
            if (( $result['deactivated'] ?? 0 ) === 0 && ( $result['reactivated'] ?? 0 ) === 0) {
                continue;
            }

            $dump .= sprintf("[%s]\n", strtoupper($network));
            $dump .= sprintf("  Кампаний всего: %d\n", $result['total_campaigns']);
            $dump .= sprintf("  Деактивировано товаров: %d\n", $result['deactivated']);
            $dump .= sprintf("  Реактивировано товаров: %d\n", $result['reactivated']);
            $dump .= sprintf("  Пропущено (без offer_id): %d\n", $result['skipped']);
            $dump .= "\n";
        }

        $body  = Cashback_Email_Builder::paragraph(
            esc_html__('Обнаружены изменения статусов CPA-кампаний. Детали ниже.', 'cashback-plugin')
        );
        $body .= Cashback_Email_Builder::preformatted($dump);
        $body .= Cashback_Email_Builder::button(
            __('Открыть API-валидацию', 'cashback-plugin'),
            admin_url('admin.php?page=cashback-api-validation&tab=campaigns')
        );

        Cashback_Email_Sender::get_instance()->send_admin($subject, $body, 'api_sync_report');
    }
}
