<?php
/**
 * Cashback_Shop_Rate_Of_Approve_Refresher — фоновый 2-часовой sync поля
 * `rate_of_approve` (процент подтверждения заказов магазином, как его видит
 * сама CPA-сеть) в post_meta WC-продуктов.
 *
 * Отдельный cron, потому что значение приходит **только** в per-campaign
 * запрос `/advcampaigns/{id}/`, а не в массовом `/advcampaigns/website/{wid}/`,
 * который использует `Cashback_Shop_Importer`. Тащить N+1 запросов в основной
 * импортёр магазинов было бы рискованно: он daily, тяжёлый, и его падение
 * блокирует обновление витрины. Здесь — лёгкий per-campaign refresh,
 * который можно безопасно перезапустить.
 *
 * Поддерживаемая сеть определяется наличием метода `fetch_campaign_by_id` на
 * адаптере (см. Cashback_Admitad_Adapter::fetch_campaign_by_id). Это де-факто
 * интерфейс; провайдер-абстракция в [[cashback-cpa-approval-rate-provider]]
 * использует тот же метод.
 *
 * @package CashbackPlugin
 * @since   4.4.22
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Shop_Rate_Of_Approve_Refresher {

    public const HOOK_RECURRING = 'cashback_shop_rate_of_approve_refresh';
    public const HOOK_BATCH     = 'cashback_shop_rate_of_approve_refresh_batch';
    public const AS_GROUP       = 'cashback';

    public const META_RATE       = '_cashback_rate_of_approve';
    public const META_FETCHED_AT = '_cashback_rate_of_approve_fetched_at';
    public const META_SOURCE     = '_cashback_rate_of_approve_source';

    /** Сколько WC-product'ов обрабатывать в одной AS-итерации. */
    public const BATCH_SIZE = 30;

    /**
     * Пауза между HTTP-запросами внутри батча (микросекунды). 1 секунда —
     * подобрано после prod-инцидента 2026-05-20: при 150ms Admitad возвращал
     * 429 для большинства запросов в batch'е (rate-limit ~5 req/sec).
     * 1 сек даёт стабильные ~1 req/sec, под Admitad-лимит. Filterable
     * через `cashback_shop_rate_of_approve_per_request_pause_us`.
     */
    public const PER_REQUEST_PAUSE_US = 1_000_000;

    /**
     * Lock helper. Сообщает is_lock_held для тестов; в проде GET_LOCK
     * освобождается при закрытии connection (на случай зависшего AS-job).
     */
    private const LOCK_PREFIX = 'cashback_rate_of_approve_n';

    /**
     * Регистрация AS-handlers + recurring schedule. Идемпотентно.
     */
    public static function init(): void {
        if (! function_exists('add_action')) {
            return;
        }

        add_action(self::HOOK_RECURRING, array( self::class, 'enqueue_all_active' ));
        add_action(self::HOOK_BATCH, array( self::class, 'run_batch_action' ), 10, 2);

        self::maybe_schedule_recurring();
    }

    /**
     * Action callback-обёртка: AS-handler не должен возвращать значение,
     * результат run_batch() используется тестами и refresh-one path.
     *
     * Сигнатура `(network_id, cycle_started_at)`: cycle_started_at — unix-секунды
     * момента старта текущего цикла обхода (его проставляет enqueue_all_active()
     * или follow-up батч). Это keyset-cutoff: в выборку попадают только товары,
     * у которых `_cashback_rate_of_approve_fetched_at < cycle_started_at`
     * (или NULL). Так после refresh товар выпадает из выборки следующего батча,
     * и OFFSET-пагинация по изменяемому полю не требуется (F-44-001).
     */
    public static function run_batch_action( $network_id, $cycle_started_at = 0 ): void {
        self::run_batch((int) $network_id, (int) $cycle_started_at);
    }

    /**
     * Запланировать recurring (если ещё не запланирован). Интервал — 2 часа
     * по умолчанию, фильтруемый через `cashback_shop_rate_of_approve_refresh_interval`.
     */
    public static function maybe_schedule_recurring(): void {
        if (! function_exists('as_has_scheduled_action') || ! function_exists('as_schedule_recurring_action')) {
            return;
        }
        if (as_has_scheduled_action(self::HOOK_RECURRING, array(), self::AS_GROUP)) {
            return;
        }

        $interval_default = defined('HOUR_IN_SECONDS') ? 2 * HOUR_IN_SECONDS : 7200;
        $interval         = (int) apply_filters('cashback_shop_rate_of_approve_refresh_interval', $interval_default);
        if ($interval < 300) {
            $interval = $interval_default; // защита от случайного 0/слишком частого тика
        }

        as_schedule_recurring_action(
            time() + 300,
            $interval,
            self::HOOK_RECURRING,
            array(),
            self::AS_GROUP
        );
    }

    /**
     * AS-handler HOOK_RECURRING. Для каждой активной сети, у адаптера которой
     * есть `fetch_campaign_by_id`, enqueue первого batch'а.
     */
    public static function enqueue_all_active(): void {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb) || ! function_exists('as_enqueue_async_action')) {
            return;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, slug FROM %i WHERE is_active = 1',
                $wpdb->prefix . 'cashback_affiliate_networks'
            ),
            ARRAY_A
        );
        if (! is_array($rows)) {
            return;
        }

        $client = self::get_api_client();
        if ($client === null) {
            return;
        }

        // Cycle-cutoff: товары, обновлённые в этом цикле, должны выпадать
        // из выборки follow-up батчей. Используем time() старта enqueue —
        // все save_rate_for_product записывают fetched_at >= этой границы.
        $cycle_started_at = time();

        foreach ($rows as $row) {
            $nid  = isset($row['id']) ? (int) $row['id'] : 0;
            $slug = isset($row['slug']) ? (string) $row['slug'] : '';
            if ($nid <= 0 || $slug === '') {
                continue;
            }
            $adapter = $client->get_adapter($slug);
            if (! is_object($adapter) || ! method_exists($adapter, 'fetch_campaign_by_id')) {
                continue;
            }

            as_enqueue_async_action(
                self::HOOK_BATCH,
                array( $nid, $cycle_started_at ),
                self::AS_GROUP
            );
        }
    }

    /**
     * Обработать батч из BATCH_SIZE WC-product'ов сети `$network_id`,
     * пропуская тех, кто уже обновлён в текущем цикле (`fetched_at >= $cycle_started_at`).
     *
     * Если `$cycle_started_at <= 0` — фолбэк на «без cutoff» (обновляем
     * NULL/самые старые) для обратной совместимости с прямыми вызовами
     * из тестов / WP-CLI.
     *
     * Re-enqueue follow-up батча с тем же `cycle_started_at` если выборка
     * вернула полный batch — иначе цикл завершён.
     *
     * @return array{success: bool, processed: int, updated: int, errors: int, has_next: bool, error: ?string}
     */
    public static function run_batch( int $network_id, int $cycle_started_at = 0 ): array {
        if ($network_id <= 0) {
            return self::error_result('network_id required');
        }

        $lock_key = self::LOCK_PREFIX . $network_id;
        if (! self::try_lock($lock_key)) {
            return self::error_result('Refresher уже выполняется для этой сети (busy lock)');
        }

        try {
            $network = self::get_network_row($network_id);
            if ($network === null) {
                return self::error_result("Сеть #{$network_id} не найдена или неактивна");
            }

            $client = self::get_api_client();
            if ($client === null) {
                return self::error_result('Cashback_API_Client недоступен');
            }

            $adapter = $client->get_adapter((string) ($network['slug'] ?? ''));
            if (! is_object($adapter) || ! method_exists($adapter, 'fetch_campaign_by_id')) {
                return self::error_result("Adapter для сети #{$network_id} не поддерживает fetch_campaign_by_id");
            }

            $creds = $client->get_credentials($network_id);
            if (! is_array($creds)) {
                return self::error_result("Credentials для сети #{$network_id} не настроены");
            }

            $product_ids = self::query_product_ids_for_network($network_id, $cycle_started_at, self::BATCH_SIZE);
            if ($product_ids === array()) {
                return array(
                    'success'   => true,
                    'processed' => 0,
                    'updated'   => 0,
                    'errors'    => 0,
                    'has_next'  => false,
                    'error'     => null,
                );
            }

            $processed = 0;
            $updated   = 0;
            $errors    = 0;

            foreach ($product_ids as $pid) {
                $offer_id = (string) get_post_meta($pid, Cashback_Shop_Importer::META_OFFER_ID, true);
                if ($offer_id === '') {
                    continue;
                }

                $result = $adapter->fetch_campaign_by_id($creds, $network, $offer_id);
                ++$processed;

                if (empty($result['success'])) {
                    ++$errors;
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                    error_log('[Cashback Rate-Of-Approve] product=' . $pid . ' err=' . (string) ($result['error'] ?? 'unknown'));
                } elseif (! is_array($result['campaign'] ?? null)) {
                    // success=true + campaign=null → 404 / удалённая кампания.
                    // Не ошибка: удаляем мету, чтобы UI показал «нет данных».
                    self::save_rate_for_product($pid, null, (string) ($network['slug'] ?? ''));
                    ++$updated;
                } else {
                    $campaign = $result['campaign'];
                    self::save_rate_for_product(
                        $pid,
                        isset($campaign['rate_of_approve']) ? $campaign['rate_of_approve'] : null,
                        (string) ($network['slug'] ?? '')
                    );
                    ++$updated;
                }

                // Rate-limit pause — даже после ошибки, чтобы не упереться
                // в admitad anti-flood retry-after на батче из 30 запросов.
                // Filterable, чтобы PHPUnit мог отключить sleep в тестах.
                $pause_us = (int) apply_filters(
                    'cashback_shop_rate_of_approve_per_request_pause_us',
                    self::PER_REQUEST_PAUSE_US
                );
                if ($pause_us > 0) {
                    usleep($pause_us);
                }
            }

            $has_next = count($product_ids) === self::BATCH_SIZE;
            if ($has_next && function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    self::HOOK_BATCH,
                    array( $network_id, $cycle_started_at ),
                    self::AS_GROUP
                );
            }

            return array(
                'success'   => true,
                'processed' => $processed,
                'updated'   => $updated,
                'errors'    => $errors,
                'has_next'  => $has_next,
                'error'     => null,
            );
        } finally {
            self::release_lock($lock_key);
        }
    }

    /**
     * Синхронный refresh для одного товара (используется AJAX-кнопкой и
     * провайдер-абстракцией). Возвращает свежий снимок поля или ошибку.
     *
     * @return array{success: bool, rate: ?float, fetched_at: ?int, source: string, error: ?string}
     */
    public static function refresh_one( int $product_id ): array {
        if ($product_id <= 0) {
            return self::single_error('product_id обязателен');
        }

        $network_id = (int) get_post_meta($product_id, Cashback_Shop_Importer::META_NETWORK_ID, true);
        $offer_id   = (string) get_post_meta($product_id, Cashback_Shop_Importer::META_OFFER_ID, true);
        if ($network_id <= 0 || $offer_id === '') {
            return self::single_error('Товар не привязан к CPA-сети');
        }

        $client = self::get_api_client();
        if ($client === null) {
            return self::single_error('Cashback_API_Client недоступен');
        }

        $network = self::get_network_row($network_id);
        if ($network === null) {
            return self::single_error('Сеть не найдена или неактивна');
        }

        $adapter = $client->get_adapter((string) ($network['slug'] ?? ''));
        if (! is_object($adapter) || ! method_exists($adapter, 'fetch_campaign_by_id')) {
            return self::single_error('Адаптер сети не поддерживает обновление этого поля');
        }

        $creds = $client->get_credentials($network_id);
        if (! is_array($creds)) {
            return self::single_error('Credentials сети не настроены');
        }

        $result = $adapter->fetch_campaign_by_id($creds, $network, $offer_id);
        if (empty($result['success'])) {
            return self::single_error((string) ($result['error'] ?? 'Не удалось получить данные кампании'));
        }

        // success=true + campaign=null → 404 (удалённая кампания) — это
        // валидный «нет данных», а не ошибка. Удаляем мету.
        $campaign = is_array($result['campaign'] ?? null) ? $result['campaign'] : null;
        $rate     = is_array($campaign) && isset($campaign['rate_of_approve']) ? $campaign['rate_of_approve'] : null;
        self::save_rate_for_product($product_id, $rate, (string) ($network['slug'] ?? ''));

        return array(
            'success'    => true,
            'rate'       => is_numeric($rate) ? (float) $rate : null,
            'fetched_at' => time(),
            'source'     => (string) ($network['slug'] ?? ''),
            'error'      => null,
        );
    }

    /**
     * Сохранить значение в post_meta. Null → удалить мету (честное «нет данных»).
     */
    private static function save_rate_for_product( int $product_id, $rate, string $source ): void {
        if ($product_id <= 0) {
            return;
        }
        if ($rate === null || ! is_numeric($rate)) {
            delete_post_meta($product_id, self::META_RATE);
            delete_post_meta($product_id, self::META_FETCHED_AT);
            delete_post_meta($product_id, self::META_SOURCE);
            return;
        }

        update_post_meta($product_id, self::META_RATE, (string) round((float) $rate, 2));
        update_post_meta($product_id, self::META_FETCHED_AT, (string) time());
        if ($source !== '') {
            update_post_meta($product_id, self::META_SOURCE, $source);
        }
    }

    /**
     * Выбрать batch product_id для сети, keyset-pagination через cycle-cutoff:
     *   - в выборку попадают только товары с `fetched_at < $cycle_started_at`
     *     или `fetched_at IS NULL` (никогда не обновлялись);
     *   - после `save_rate_for_product()` товар получает `fetched_at >= cycle_started_at`
     *     и автоматически выпадает из выборки следующего батча (F-44-001 fix);
     *   - сортировка «NULL → самые старые» сохраняется как приоритет внутри
     *     цикла, но больше не влияет на пагинацию (она по cutoff'у).
     *
     * `$cycle_started_at <= 0` → cutoff отключён: возвращаем NULL/самые старые
     * (используется WP-CLI / тестами при прямом вызове `run_batch`).
     *
     * @return array<int, int>
     */
    private static function query_product_ids_for_network( int $network_id, int $cycle_started_at, int $limit ): array {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb) || ! method_exists($wpdb, 'get_col') || ! method_exists($wpdb, 'prepare')) {
            return array();
        }

        $limit            = max(1, min(500, $limit));
        $cycle_started_at = max(0, $cycle_started_at);

        // Cutoff: в выборке либо ещё не трогали (mf.meta_value IS NULL), либо
        // обновляли ДО старта текущего цикла. Если cutoff = 0 — пускаем всех
        // (legacy-режим без cycle).
        $cutoff_clause = $cycle_started_at > 0
            ? '(mf.meta_value IS NULL OR CAST(mf.meta_value AS UNSIGNED) < %d)'
            : '(1 = 1 OR %d = 0)'; // %d-плейсхолдер сохраняем для одинакового arg-списка prepare()

        $sql = "SELECT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} mn ON mn.post_id = p.ID AND mn.meta_key = %s
                INNER JOIN {$wpdb->postmeta} mo ON mo.post_id = p.ID AND mo.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} mf ON mf.post_id = p.ID AND mf.meta_key = %s
                WHERE p.post_type = 'product'
                  AND p.post_status IN ('publish', 'draft', 'private', 'pending')
                  AND mn.meta_value = %d
                  AND mo.meta_value <> ''
                  AND " . $cutoff_clause . '
                ORDER BY (mf.meta_value IS NULL) DESC, CAST(mf.meta_value AS UNSIGNED) ASC, p.ID ASC
                LIMIT %d';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col($wpdb->prepare(
            $sql,
            Cashback_Shop_Importer::META_NETWORK_ID,
            Cashback_Shop_Importer::META_OFFER_ID,
            self::META_FETCHED_AT,
            $network_id,
            $cycle_started_at,
            $limit
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return is_array($ids) ? array_map('intval', $ids) : array();
    }

    /**
     * Читаем row из cashback_affiliate_networks. Совпадает по семантике с
     * `Cashback_Shop_Importer::get_network_row` — нужен api_base_url + slug +
     * api_website_id для адаптера.
     *
     * @return array<string, mixed>|null
     */
    private static function get_network_row( int $network_id ): ?array {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb) || ! method_exists($wpdb, 'get_row') || ! method_exists($wpdb, 'prepare')) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d AND is_active = 1',
            $wpdb->prefix . 'cashback_affiliate_networks',
            $network_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function get_api_client(): ?Cashback_API_Client {
        if (! class_exists('Cashback_API_Client') || ! method_exists('Cashback_API_Client', 'get_instance')) {
            return null;
        }
        return Cashback_API_Client::get_instance();
    }

    private static function try_lock( string $lock_key ): bool {
        global $wpdb;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var') || ! method_exists($wpdb, 'prepare')) {
            return true;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory-lock.
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_key, 0));
        return (int) $result === 1;
    }

    private static function release_lock( string $lock_key ): void {
        global $wpdb;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var') || ! method_exists($wpdb, 'prepare')) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory-lock.
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_key));
    }

    /**
     * @return array{success: false, processed: 0, updated: 0, errors: 0, has_next: false, error: string}
     */
    private static function error_result( string $msg ): array {
        return array(
            'success'   => false,
            'processed' => 0,
            'updated'   => 0,
            'errors'    => 0,
            'has_next'  => false,
            'error'     => $msg,
        );
    }

    /**
     * @return array{success: false, rate: null, fetched_at: null, source: string, error: string}
     */
    private static function single_error( string $msg ): array {
        return array(
            'success'    => false,
            'rate'       => null,
            'fetched_at' => null,
            'source'     => '',
            'error'      => $msg,
        );
    }
}
