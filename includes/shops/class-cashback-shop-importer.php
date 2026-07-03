<?php
/**
 * Cashback_Shop_Importer — оркестратор импорта магазинов из CPA-сетей (v12).
 *
 * Один запуск:
 *   1. Lock per-network (cashback_shops_import_n{network_id}).
 *   2. Берёт сеть из cashback_affiliate_networks по network_id.
 *   3. Получает adapter по slug сети + creds через Cashback_API_Client.
 *   4. adapter::fetch_campaigns_detailed(creds, cfg, offset, batch_size).
 *   5. Для каждой кампании:
 *        - upsert WC external product (status=draft) с метаполями привязки;
 *        - adapter::fetch_shop_tariffs() → Cashback_Shop_Tariff_Sync::sync().
 *   6. Логирует прогресс в Cashback_Shop_Import_Log per-page.
 *   7. has_next → re-enqueue follow-up страницы (Action Scheduler).
 *
 * Регистрация AS-recurring hook'а — отдельный Этап 9 (cron-регистрация).
 * Здесь только base run() — вызывается WP-CLI / admin-кнопкой / AS-async.
 *
 * @package CashbackPlugin
 * @since   12.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Shop_Importer {

    public const HOOK_RUN              = 'cashback_shops_import_run';
    public const HOOK_RECURRING        = 'cashback_shops_import_recurring';
    public const HOOK_GROUPS_RECOMPUTE = 'cashback_shop_groups_recompute';
    public const HOOK_LOG_GC           = 'cashback_shop_import_log_gc';
    public const AS_GROUP              = 'cashback';

    public const META_NETWORK_ID       = '_affiliate_network_id';
    public const META_OFFER_ID         = '_offer_id';
    public const META_STORE_DOMAIN     = '_store_domain';
    public const META_IMPORT_SOURCE    = '_cashback_import_source';
    public const META_SIGNATURE        = '_cashback_import_signature';
    public const META_IMPORT_AT        = '_cashback_import_at';
    public const META_LAST_SEEN_AT     = '_cashback_last_seen_at';
    public const META_CURRENCY         = '_cashback_campaign_currency';
    public const META_STATUS_RAW       = '_cashback_campaign_status_raw';
    public const META_AVG_PAYMENT_DAYS = '_cashback_avg_payment_days';
    public const META_RATE_LOCKED   = '_rate_locked';

    /**
     * Жёсткий таймаут (секунды) на скачивание любого image при импорте
     * (logo / favicon / featured image) — и для legacy raster-пути через
     * `media_sideload_image()`, и для SVG-пути через `download_url()`.
     *
     * Проблема: WP-core `download_url()` принимает таймаут аргументом
     * (default = 300с), и фильтр `http_request_timeout` НЕ переопределяет
     * аргумент. `media_sideload_image()` внутри тоже использует 300с.
     * Один медленный/недоступный CDN-image (Advcake, Admitad) при 100+
     * офферах на странице упирался в `action_scheduler_failure_period`
     * (= 300с) и AS убивал задачу с «неизвестная ошибка» без forward
     * progress.
     *
     * 15с = баланс: достаточно для большинства CDN-pull'ов, мало чтобы
     * один зависший image убил весь батч. Применяется через scoped
     * `http_request_args` фильтр (см. {@see sideload_raster_attachment()}).
     *
     * @see https://developer.wordpress.org/reference/functions/download_url/
     */
    public const IMAGE_DOWNLOAD_TIMEOUT_SECONDS = 15;

    /**
     * Wallclock-бюджет одного `run()`-вызова перед early-break (секунды).
     *
     * 80% от Action Scheduler default `failure_period = 300с` оставляет
     * 60с резерва на финализацию лога, re-enqueue следующей страницы
     * и release lock'а. Без этого guard'а медленный CDN + длинная страница
     * офферов = AS убивает задачу без сохранения прогресса.
     *
     * После превышения бюджета `run()` запоминает page_cursor (индекс
     * следующего необработанного оффера в текущей странице) и re-enqueues
     * `cashback_shops_import_run` с тем же offset + cursor → следующий
     * tick продолжит ровно с того же места.
     */
    public const SAFE_RUN_BUDGET_SECONDS = 240;

    /**
     * Soft memory-budget guard для одного import action.
     *
     * Проверяем usage после каждого обработанного offer и re-enqueue'им
     * продолжение до того как процесс приблизится к PHP/container OOM.
     */
    public const SAFE_MEMORY_USAGE_RATIO = 0.70;

    /**
     * Дополнительный минимальный headroom до memory_limit.
     */
    public const MIN_MEMORY_HEADROOM_BYTES = 67108864; // 64 MiB.

    // Дефолты, проставляемые ТОЛЬКО при первичном импорте (insert_draft_product).
    // На update_existing_product не трогаем — админ мог изменить.
    public const DEFAULT_BUTTON_TEXT    = 'Перейти';
    public const DEFAULT_POPUP_MODE     = 'hide';
    public const DEFAULT_DISPLAY_LABEL  = 'Кэшбэк';
    public const DEFAULT_TAB1_TITLE     = 'Условия';
    public const DEFAULT_TAB1_PRIORITY  = '1';
    public const DEFAULT_TAB2_TITLE     = 'Промокоды';
    public const DEFAULT_TAB2_PRIORITY  = '90';
    public const DEFAULT_TAB2_CONTENT   = '[cashback_promocodes]';

    /**
     * Зарегистрировать AS-handlers + recurring schedules.
     *
     * Hooks (все в группе 'cashback'):
     *   - cashback_shops_import_run         — обработка одной страницы
     *     (вызывается импортёром per-network).
     *   - cashback_shops_import_recurring   — daily 03:00, enqueue'ит
     *     async actions для всех активных сетей.
     *   - cashback_shop_groups_recompute    — hourly, recompute preferred
     *     для всех групп со status='auto' (на случай tariff drift вне импорта).
     *   - cashback_shop_import_log_gc       — weekly, удаляет log-row
     *     старше 30 дней.
     */
    public static function init(): void {
        if (! function_exists('add_action')) {
            return;
        }

        // run() возвращает массив с результатом — для прямых вызовов из тестов
        // и admin-кнопки. Action callback не должен ничего возвращать, поэтому
        // оборачиваем в run_action() который игнорирует результат. 4-й аргумент
        // (page_cursor) и 5-й (log_id) опциональны — старые AS-jobs,
        // поставленные до релиза fix/advcake-import-hang, придут с 3 args.
        add_action(self::HOOK_RUN, array( self::class, 'run_action' ), 10, 5);
        add_action(self::HOOK_RECURRING, array( self::class, 'enqueue_all_active' ));
        add_action(self::HOOK_GROUPS_RECOMPUTE, array( self::class, 'recompute_auto_groups' ));
        add_action(self::HOOK_LOG_GC, array( self::class, 'gc_old_logs' ));

        self::maybe_schedule_recurring();
    }

    /**
     * Action handler-обёртка для HOOK_RUN. Action Scheduler ожидает callback
     * без возвращаемого значения; результат run() (для тестов / admin) сюда
     * не пробрасывается.
     *
     * @param int      $page_cursor Индекс внутри текущей страницы, с которого
     *                              продолжить (0 для первого tick'а; > 0 если
     *                              предыдущий tick прервался по time-budget).
     * @param int|null $log_id      Existing import_log row для resume той же страницы.
     */
    public static function run_action( int $network_id, string $run_id, int $offset = 0, int $page_cursor = 0, ?int $log_id = null ): void {
        self::run($network_id, $run_id, $offset, $page_cursor, $log_id);
    }

    /**
     * Зарегистрировать recurring AS-actions если ещё не зарегистрированы.
     * Идемпотентно через as_has_scheduled_action.
     */
    public static function maybe_schedule_recurring(): void {
        if (! function_exists('as_has_scheduled_action') || ! function_exists('as_schedule_recurring_action')) {
            return;
        }

        // Daily import: 03:00 UTC, period 24h.
        if (! as_has_scheduled_action(self::HOOK_RECURRING, array(), self::AS_GROUP)) {
            $start = self::next_03_utc();
            as_schedule_recurring_action($start, DAY_IN_SECONDS, self::HOOK_RECURRING, array(), self::AS_GROUP);
        }

        // Hourly groups recompute (защита от drift).
        if (! as_has_scheduled_action(self::HOOK_GROUPS_RECOMPUTE, array(), self::AS_GROUP)) {
            as_schedule_recurring_action(time() + 600, HOUR_IN_SECONDS, self::HOOK_GROUPS_RECOMPUTE, array(), self::AS_GROUP);
        }

        // Weekly log GC.
        if (! as_has_scheduled_action(self::HOOK_LOG_GC, array(), self::AS_GROUP)) {
            as_schedule_recurring_action(time() + 3600, 7 * DAY_IN_SECONDS, self::HOOK_LOG_GC, array(), self::AS_GROUP);
        }
    }

    /**
     * AS handler: enqueue async action для каждой активной сети.
     * Каждая сеть импортируется параллельно (lock per-network).
     */
    public static function enqueue_all_active(): void {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb) || ! function_exists('as_enqueue_async_action')) {
            return;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE is_active = 1',
                $wpdb->prefix . 'cashback_affiliate_networks'
            ),
            ARRAY_A
        );
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $network_id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($network_id <= 0) {
                continue;
            }
            $run_id = class_exists('Cashback_Shop_Import_Log')
                ? Cashback_Shop_Import_Log::generate_run_id()
                : (string) time();

            as_enqueue_async_action(
                self::HOOK_RUN,
                array( $network_id, $run_id, 0, 0 ),
                self::AS_GROUP
            );
        }
    }

    /**
     * AS handler: пересчитать preferred для всех auto-групп.
     * Защищает от drift когда тарифы поменялись вне импорт-цикла.
     */
    public static function recompute_auto_groups(): void {
        if (! class_exists('Cashback_Shop_Group_Resolver')) {
            return;
        }
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE status = %s LIMIT 500',
                $wpdb->prefix . Cashback_Shop_Group_Resolver::TABLE_GROUPS,
                Cashback_Shop_Group_Resolver::STATUS_AUTO
            ),
            ARRAY_A
        );
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $group_id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($group_id > 0) {
                Cashback_Shop_Group_Resolver::recompute_preferred($group_id);
            }
        }
    }

    /**
     * AS handler: weekly log GC — удаляет import_log старше 30 дней.
     */
    public static function gc_old_logs(): void {
        if (! class_exists('Cashback_Shop_Import_Log')) {
            return;
        }
        Cashback_Shop_Import_Log::gc_old(30);
    }

    /**
     * Возвращает timestamp следующего 03:00 UTC. Если сейчас < 03:00 — сегодня;
     * иначе — завтра.
     */
    private static function next_03_utc(): int {
        $now    = time();
        $today  = strtotime(gmdate('Y-m-d 03:00:00', $now) . ' UTC');
        if ($today === false) {
            return $now + HOUR_IN_SECONDS;
        }
        return $today > $now ? $today : ($today + DAY_IN_SECONDS);
    }

    /**
     * Прогнать ОДНУ страницу импорта (batch=cashback_shop_import_batch_size).
     *
     * Page cursor + time-budget guard (fix/advcake-import-hang):
     *   - $page_cursor — индекс внутри текущей страницы, с которого нужно
     *     продолжить. На первом tick'е равен 0. Если предыдущий tick прервался
     *     по time-budget — равен индексу следующего необработанного оффера.
     *   - В цикле меряем `microtime(true) - $start_ts`; при превышении
     *     {@see SAFE_RUN_BUDGET_SECONDS} re-enqueues `cashback_shops_import_run`
     *     с ТЕМ ЖЕ `$offset` и обновлённым `$page_cursor` — следующий tick
     *     продолжит ровно с того же места. Это даёт forward progress даже
     *     при медленном CDN, без потери офферов и без AS-«unknown error».
     *
     * Throttle: реально применяется `Cashback_Shop_Options::get_import_throttle_ms()`
     * через `usleep` между офферами — раньше опция была декларирована, но не
     * использовалась.
     *
     * @param int    $network_id  ID сети (cashback_affiliate_networks.id).
     * @param string $run_id      UUIDv7 запуска (общий для всех страниц одного импорта).
     * @param int    $offset      Смещение пагинации (0 на первой странице).
     * @param int      $page_cursor Индекс внутри страницы; 0 для первого tick'а.
     * @param int|null $log_id      Existing import_log row для resume той же страницы.
     * @return array{success: bool, fetched: int, upserted_new: int, upserted_upd: int, tariffs_synced: int, has_next: bool, next_offset: int, page_cursor: int, error: ?string}
     */
    public static function run( int $network_id, string $run_id, int $offset = 0, int $page_cursor = 0, ?int $log_id = null ): array {
        $start_ts      = microtime(true);
        $memory_before = memory_get_usage(true);
        $page_cursor   = max(0, $page_cursor);

        $page    = (int) max(0, $offset);
        $page_no = $page > 0 && class_exists('Cashback_Shop_Options')
            ? (int) ( $offset / max(1, Cashback_Shop_Options::get_import_batch_size()) )
            : 0;

        $log_id = $log_id !== null && $log_id > 0
            ? $log_id
            : Cashback_Shop_Import_Log::start_page($run_id, $network_id, $page_no);

        $lock_key = 'cashback_shops_import_n' . $network_id;
        $locked   = self::try_lock($lock_key);
        if (!$locked) {
            $err = 'Импорт сети уже идёт (busy lock)';
            Cashback_Shop_Import_Log::finish_page($log_id, $err);
            return self::error_result($err);
        }

        try {
            $network = self::get_network_row($network_id);
            if ($network === null) {
                $err = "Сеть #{$network_id} не найдена или неактивна";
                Cashback_Shop_Import_Log::finish_page($log_id, $err);
                return self::error_result($err);
            }

            $api_client = self::get_api_client();
            if ($api_client === null) {
                $err = 'Cashback_API_Client недоступен';
                Cashback_Shop_Import_Log::finish_page($log_id, $err);
                return self::error_result($err);
            }

            $adapter = $api_client->get_adapter((string) $network['slug']);
            if ($adapter === null) {
                $err = "Адаптер для slug='{$network['slug']}' не зарегистрирован";
                Cashback_Shop_Import_Log::finish_page($log_id, $err);
                return self::error_result($err);
            }

            $creds = $api_client->get_credentials($network_id);
            if (! is_array($creds)) {
                $err = "Credentials для сети #{$network_id} не настроены";
                Cashback_Shop_Import_Log::finish_page($log_id, $err);
                return self::error_result($err);
            }

            $batch_size = class_exists('Cashback_Shop_Options') && method_exists('Cashback_Shop_Options', 'get_import_batch_size_for_network')
                ? Cashback_Shop_Options::get_import_batch_size_for_network($network)
                : 100;

            $cache_key      = self::import_page_cache_key($run_id, $network_id, $offset);
            $fetched_result = $page_cursor > 0 ? self::get_cached_import_page($cache_key) : null;
            if ($fetched_result === null) {
                $fetched_result = $adapter->fetch_campaigns_detailed($creds, $network, $offset, $batch_size);
                if (! empty($fetched_result['success'])) {
                    self::set_cached_import_page($cache_key, $fetched_result);
                }
            }
            if (empty($fetched_result['success'])) {
                $err = (string) ( $fetched_result['error'] ?? 'fetch_campaigns_detailed failed' );
                Cashback_Shop_Import_Log::finish_page($log_id, $err);
                self::delete_cached_import_page($cache_key);
                return self::error_result($err);
            }

            $campaigns = isset($fetched_result['campaigns']) && is_array($fetched_result['campaigns'])
                ? $fetched_result['campaigns']
                : array();

            $stats = array(
                'fetched'        => count($campaigns),
                'upserted_new'   => 0,
                'upserted_upd'   => 0,
                'tariffs_synced' => 0,
            );
            if ($page_cursor > 0) {
                $previous_stats = self::get_import_log_stats($log_id);
                if ($previous_stats !== null) {
                    $stats['upserted_new']   = $previous_stats['upserted_new'];
                    $stats['upserted_upd']   = $previous_stats['upserted_upd'];
                    $stats['tariffs_synced'] = $previous_stats['tariffs_synced'];
                }
            }

            $throttle_ms = class_exists('Cashback_Shop_Options')
                ? Cashback_Shop_Options::get_import_throttle_ms()
                : 0;

            $budget_exceeded     = false;
            $next_page_cursor    = 0;
            $reschedule_reason   = null;
            $changed_product_ids = array();

            foreach ($campaigns as $idx => $campaign) {
                // Skip уже обработанное в предыдущем sub-run'е (cursor pickup).
                if ($idx < $page_cursor) {
                    continue;
                }
                if (! is_array($campaign)) {
                    continue;
                }
                try {
                    $dto = Cashback_Campaign_Detail_DTO::from_array($campaign);
                } catch (\Throwable $e) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                    error_log('[Cashback Shop Importer] DTO error: ' . $e->getMessage());
                    continue;
                }

                $upsert = self::upsert_product($dto, $network_id, (string) ( $network['slug'] ?? '' ));
                if ($upsert['kind'] === 'new') {
                    ++$stats['upserted_new'];
                } elseif ($upsert['kind'] === 'updated' || $upsert['kind'] === 'seen') {
                    ++$stats['upserted_upd'];
                }

                // Tariff sync — только если product создан/обновлён
                // (на rate_locked product мы всё равно обновляем тарифы, чтобы видеть актуальные суммы).
                if ($upsert['product_id'] > 0) {
                    $tariff_sync = self::sync_tariffs_for_campaign(
                        $adapter,
                        $creds,
                        $network,
                        $network_id,
                        $dto
                    );
                    $stats['tariffs_synced'] += (int) ( $tariff_sync['upserted'] ?? 0 );
                    if (! empty($tariff_sync['changed'])) {
                        $changed_product_ids[ (int) $upsert['product_id'] ] = (int) $upsert['product_id'];
                    }

                    // Tab[1] «Условия» — рендерим ПОСЛЕ tariff sync, иначе
                    // renderer прочитает старые данные. Покрывает все ветки
                    // upsert (new/updated/unchanged/seen) — кроме skipped.
                    if ($upsert['kind'] !== 'skipped') {
                        self::apply_tab1_conditions_content(
                            $upsert['product_id'],
                            $network_id,
                            $dto->id
                        );
                    }
                }

                // Throttle между офферами (опция cashback_shop_import_throttle_ms).
                // Применяется ПЕРЕД time-budget guard, чтобы пауза тоже шла
                // в счёт wallclock'а — иначе при большом throttle мы пройдём
                // guard, но превысим бюджет на следующей паузе.
                if ($throttle_ms > 0) {
                    usleep($throttle_ms * 1000);
                }

                Cashback_Shop_Import_Log::update_progress(
                    $log_id,
                    $stats['fetched'],
                    $stats['upserted_new'],
                    $stats['upserted_upd'],
                    $stats['tariffs_synced']
                );
                if (self::should_pause_for_memory_budget()) {
                    $budget_exceeded   = true;
                    $next_page_cursor  = $idx + 1;
                    $reschedule_reason = 'memory_budget';
                    break;
                }

                // Time-budget guard: если прошло > SAFE_RUN_BUDGET_SECONDS,
                // прерываем цикл и re-enqueues текущую страницу с cursor =
                // $idx + 1 (следующий tick продолжит со следующего оффера).
                $elapsed = microtime(true) - $start_ts;
                if ($elapsed > self::SAFE_RUN_BUDGET_SECONDS) {
                    $budget_exceeded   = true;
                    $next_page_cursor  = $idx + 1;
                    $reschedule_reason = 'time_budget';
                    break;
                }
            }

            self::flush_deferred_tariff_side_effects($changed_product_ids);

            Cashback_Shop_Import_Log::update_progress(
                $log_id,
                $stats['fetched'],
                $stats['upserted_new'],
                $stats['upserted_upd'],
                $stats['tariffs_synced']
            );
            if (! $budget_exceeded) {
                Cashback_Shop_Import_Log::finish_page($log_id, null);
                self::delete_cached_import_page($cache_key);
            }

            $has_next    = ! empty($fetched_result['has_next']);
            $next_offset = isset($fetched_result['next_offset']) ? (int) $fetched_result['next_offset'] : ($offset + $batch_size);
            self::log_run_diagnostics(
                array(
                    'network_slug'      => (string) ( $network['slug'] ?? '' ),
                    'offset'            => $offset,
                    'page_cursor'       => $page_cursor,
                    'next_page_cursor'  => $next_page_cursor,
                    'batch_size'        => $batch_size,
                    'fetched'           => $stats['fetched'],
                    'processed'         => max(0, $next_page_cursor > 0 ? $next_page_cursor : count($campaigns)) - $page_cursor,
                    'memory_before'     => $memory_before,
                    'memory_after'      => memory_get_usage(true),
                    'reschedule_reason' => $reschedule_reason,
                )
            );

            if ($budget_exceeded) {
                // Re-enqueue ТУ ЖЕ страницу с обновлённым cursor — следующий
                // tick продолжит с $next_page_cursor. has_next в этой ветке
                // означает «текущая страница не доедена», вместо стандартной
                // семантики «есть следующий offset». next_offset = $offset,
                // чтобы admin-trigger при ручном чтении лога видел правильный
                // продолжающийся offset.
                if (function_exists('as_enqueue_async_action')) {
                    as_enqueue_async_action(
                        self::HOOK_RUN, array( $network_id, $run_id, $offset, $next_page_cursor, $log_id ), self::AS_GROUP
                    );
                }
                return array(
					'success'        => true,
					'fetched'        => $stats['fetched'],
					'upserted_new'   => $stats['upserted_new'],
					'upserted_upd'   => $stats['upserted_upd'],
					'tariffs_synced' => $stats['tariffs_synced'], 'has_next'       => true, 'next_offset'    => $offset, 'page_cursor'    => $next_page_cursor, 'error'          => null,
				);
            }

            // Re-enqueue follow-up если есть ещё страницы (action scheduler).
            // page_cursor = 0 для новой страницы (старт с первого оффера).
            if ($has_next && function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    self::HOOK_RUN,
                    array( $network_id, $run_id, $next_offset, 0 ),
                    self::AS_GROUP
                );
            }

            return array(
                'success'        => true,
                'fetched'        => $stats['fetched'],
                'upserted_new'   => $stats['upserted_new'],
                'upserted_upd'   => $stats['upserted_upd'],
                'tariffs_synced' => $stats['tariffs_synced'], 'has_next'       => $has_next, 'next_offset'    => $next_offset, 'page_cursor'    => 0, 'error'          => null,
			);
        } finally {
            self::release_lock($lock_key);
        }
    }

    /**
     * Создать/обновить WC external product для одной кампании.
     *
     * Поиск существующего товара — по паре meta (_affiliate_network_id, _offer_id).
     * При наличии _rate_locked=1 — НЕ перезаписываем product (только last_seen_at),
     * чтобы admin-override не съело cron-sync'ом.
     *
     * @param string $adapter_slug Slug сети для META_IMPORT_SOURCE (например, 'adm').
     *                             Передаётся из run(), где network row уже загружен;
     *                             пустая строка допустима для backward-compat.
     * @return array{kind: 'new'|'updated'|'unchanged'|'seen'|'skipped', product_id: int}
     */
    public static function upsert_product( Cashback_Campaign_Detail_DTO $dto, int $network_id, string $adapter_slug = '' ): array {
        if ($dto->id === '' || $network_id <= 0) {
            return array( 'kind' => 'skipped', 'product_id' => 0 );
        }

        $existing_id = self::find_product_by_offer($network_id, $dto->id);
        $now         = self::now_mysql();
        $signature   = self::compute_signature($dto);
        $domain      = self::parse_domain($dto->site_url);

        // rate_locked — только касаемся last_seen_at, ничего больше не правим.
        if ($existing_id > 0 && self::is_rate_locked($existing_id)) {
            update_post_meta($existing_id, self::META_LAST_SEEN_AT, $now);
            return array( 'kind' => 'seen', 'product_id' => $existing_id );
        }

        if ($existing_id === 0) {
            $product_id = self::insert_draft_product($dto, $network_id, $signature, $domain, $now, $adapter_slug);
            self::reconcile_group($product_id);
            return array(
                'kind'       => $product_id > 0 ? 'new' : 'skipped',
                'product_id' => $product_id,
            );
        }

        // Existing product — diff signature.
        $prev_signature = (string) get_post_meta($existing_id, self::META_SIGNATURE, true);
        if ($prev_signature === $signature) {
            // Без изменений — только last_seen_at + idempotent backfill для
            // товаров, импортированных до релиза с taxonomy/defaults +
            // recompute группы (на случай если соседний продукт изменил
            // тарифы и preferred мог сместиться).
            update_post_meta($existing_id, self::META_LAST_SEEN_AT, $now);
            self::backfill_missing_admin_fields($existing_id);
            if ($dto->payment_time_days !== null) {
                update_post_meta($existing_id, self::META_AVG_PAYMENT_DAYS, (string) $dto->payment_time_days);
            }
            // Backfill featured image: товары, импортированные до фикса
            // SVG-сpath, остались без thumbnail. signature не меняется —
            // через ветку 'updated' они туда не попадут. Догружаем здесь
            // (idempotent: только если has_post_thumbnail() == false).
            if (
                $dto->image_url !== ''
                && function_exists('has_post_thumbnail')
                && ! has_post_thumbnail($existing_id)
            ) {
                self::attach_featured_image_from_url($existing_id, $dto->image_url, $adapter_slug, $dto->id);
            }
            self::reconcile_group($existing_id);
            return array( 'kind' => 'unchanged', 'product_id' => $existing_id );
        }

        self::update_existing_product($existing_id, $dto, $network_id, $signature, $domain, $now, $adapter_slug);
        self::reconcile_group($existing_id);
        return array( 'kind' => 'updated', 'product_id' => $existing_id );
    }

    /**
     * Привязать product к группе по домену + пересчитать preferred.
     * Best-effort: ошибки резолвера не должны рушить импорт.
     */
    private static function reconcile_group( int $product_id ): void {
        if ($product_id <= 0 || ! class_exists('Cashback_Shop_Group_Resolver')) {
            return;
        }
        try {
            Cashback_Shop_Group_Resolver::reconcile_for_product($product_id);
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback Shop Importer] reconcile_group failed for product=' . $product_id . ': ' . $e->getMessage());
        }
    }

    /**
     * Sha256 ключевых полей DTO. Если signature не изменилась — product не правим.
     */
    public static function compute_signature( Cashback_Campaign_Detail_DTO $dto ): string {
        $canonical = wp_json_encode(array(
            'site_url'    => $dto->site_url,
            'image_url'   => $dto->image_url,
            'description' => $dto->description,
            'status_raw'  => $dto->status_raw,
            'currency'    => $dto->currency,
            'goto_link'   => $dto->goto_link,
            'regions'     => $dto->regions,
            'categories'  => $dto->categories,
        ));
        return hash('sha256', is_string($canonical) ? $canonical : '');
    }

    /**
     * Извлечь нормализованный домен из site_url для дедупа.
     *
     * Шаги: wp_parse_url → host → lowercase → drop www. → IDN → trim.
     * Возвращает '' если URL не валиден.
     */
    public static function parse_domain( string $site_url ): string {
        if ($site_url === '') {
            return '';
        }
        $url = $site_url;
        // Если URL без схемы — добавляем для wp_parse_url.
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return '';
        }
        $host = strtolower(trim($host));
        // IDN → utf8 (если функция доступна). Заглушаем warning у некорректных
        // ASCII-хостов через @ — fallback оставляет $host как есть.
        if (function_exists('idn_to_utf8')) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- idn_to_utf8 шлёт warning при невалидном punycode; fallback на оригинальный host безопасен.
            $idn = @idn_to_utf8($host, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);
            if (is_string($idn) && $idn !== '') {
                $host = $idn;
            }
        }
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return rtrim($host, '/');
    }

    /**
     * Проверить, заблокирован ли product от auto-sync через _rate_locked=1.
     */
    public static function is_rate_locked( int $product_id ): bool {
        return (string) get_post_meta($product_id, self::META_RATE_LOCKED, true) === '1';
    }

    /**
     * Найти product_id по паре (network_id, offer_id) метаполей. 0 если нет.
     */
    public static function find_product_by_offer( int $network_id, string $offer_id ): int {
        if ($network_id <= 0 || $offer_id === '') {
            return 0;
        }
        global $wpdb;

        $row = $wpdb->get_var($wpdb->prepare(
            'SELECT pm1.post_id
               FROM ' . $wpdb->postmeta . ' AS pm1
               JOIN ' . $wpdb->postmeta . ' AS pm2 ON pm1.post_id = pm2.post_id
              WHERE pm1.meta_key = %s AND pm1.meta_value = %s
                AND pm2.meta_key = %s AND pm2.meta_value = %s
              LIMIT 1',
            self::META_NETWORK_ID,
            (string) $network_id,
            self::META_OFFER_ID,
            $offer_id
        ));

        return is_numeric($row) ? (int) $row : 0;
    }

    /**
     * INSERT нового draft external product + metas + featured image.
     *
     * @return int product_id или 0 при ошибке.
     */
    private static function insert_draft_product(
        Cashback_Campaign_Detail_DTO $dto,
        int $network_id,
        string $signature,
        string $domain,
        string $now,
        string $adapter_slug
    ): int {
        if (! function_exists('wp_insert_post')) {
            return 0;
        }

        // post_content оставляем пустым: Admitad description написан для
        // рекламодателей (фирменные регалии, скидки реселлерам, контакты),
        // а не для конечных покупателей. Админ заполняет описание сам перед
        // публикацией товара. Сырой description сохранён в _cashback_campaign_*
        // raw_payload — admin может скопировать вручную если нужно.
        $post_id = wp_insert_post(array(
            'post_title'   => $dto->name !== '' ? $dto->name : ('Кампания #' . $dto->id),
            'post_content' => '',
            'post_status'  => 'draft',
            'post_type'    => 'product',
        ), true);

        if (is_wp_error($post_id) || $post_id === 0) {
            return 0;
        }

        self::write_product_meta((int) $post_id, $dto, $network_id, $signature, $domain, $now, $adapter_slug);
        // External product type → meta _product_url для goto_link.
        if ($dto->goto_link !== '') {
            update_post_meta((int) $post_id, '_product_url', $dto->goto_link);
        }
        // Тип товара — taxonomy term `product_type=external`. WC использует
        // именно taxonomy для определения типа: без него товар считается
        // simple, и External Product поля (URL/Текст кнопки) НЕ рендерятся
        // в админ-метабоксе. post_meta `_product_type` оставляем как
        // metabox-фолбэк для совместимости.
        self::set_product_type_external((int) $post_id);
        update_post_meta((int) $post_id, '_product_type', 'external');

        // Дефолты UX/админки, проставляются только при первичном импорте.
        // На обновлении не трогаем — админ мог изменить или вычистить.
        self::apply_first_import_defaults((int) $post_id);

        // Featured image: грузим логотип из CDN сети как WP attachment и
        // привязываем к товару. Best-effort: ошибка media_sideload_image
        // не должна валить весь импорт.
        self::attach_featured_image_from_url((int) $post_id, $dto->image_url, $adapter_slug, $dto->id);

        return (int) $post_id;
    }

    /**
     * Назначить товару taxonomy term product_type=external.
     * Идемпотентно: повторный вызов не дублирует. Без явного term'а WC
     * считает товар simple и скрывает поля External Product
     * (URL Товара / Текст кнопки).
     */
    private static function set_product_type_external( int $product_id ): void {
        if ($product_id <= 0 || ! function_exists('wp_set_object_terms')) {
            return;
        }
        wp_set_object_terms($product_id, 'external', 'product_type', false);
    }

    /**
     * Проставить дефолтные значения админских полей при первичном импорте:
     *   — _button_text          = 'Перейти' (текст кнопки external product);
     *   — _store_popup_mode     = 'hide'    (всплывающее окно расширения);
     *   — _cashback_display_label = 'Кэшбэк' (метка в карточке);
     *   — Woodmart Tab[1] = 'Условия'  (priority=80, пустой content);
     *   — Woodmart Tab[2] = 'Промокоды' (priority=90, content='[cashback_coupons_icons]').
     *
     * Вызывается ТОЛЬКО из insert_draft_product. На update_existing_product
     * не трогаем — админ мог осознанно поменять/очистить эти поля.
     */
    private static function apply_first_import_defaults( int $product_id ): void {
        if ($product_id <= 0) {
            return;
        }

        update_post_meta($product_id, '_button_text', self::DEFAULT_BUTTON_TEXT);
        update_post_meta($product_id, '_store_popup_mode', self::DEFAULT_POPUP_MODE);
        update_post_meta($product_id, '_cashback_display_label', self::DEFAULT_DISPLAY_LABEL);

        // Tab 1 — «Условия» (пустой контент, заполняет админ).
        update_post_meta($product_id, '_woodmart_product_custom_tab_title', self::DEFAULT_TAB1_TITLE);
        update_post_meta($product_id, '_woodmart_product_custom_tab_priority', self::DEFAULT_TAB1_PRIORITY);
        update_post_meta($product_id, '_woodmart_product_custom_tab_content_type', 'text');
        update_post_meta($product_id, '_woodmart_product_custom_tab_content', '');

        // Tab 2 — «Промокоды» с шорткодом.
        update_post_meta($product_id, '_woodmart_product_custom_tab_title_2', self::DEFAULT_TAB2_TITLE);
        update_post_meta($product_id, '_woodmart_product_custom_tab_priority_2', self::DEFAULT_TAB2_PRIORITY);
        update_post_meta($product_id, '_woodmart_product_custom_tab_content_type_2', 'text');
        update_post_meta($product_id, '_woodmart_product_custom_tab_content_2', self::DEFAULT_TAB2_CONTENT);
    }

    /**
     * Обновить мета существующего товара.
     *
     * Название магазина — только при первом импорте: CPA-сеть может
     * переименовать оффер, но WC title уже мог быть отредактирован админом.
     */
    private static function update_existing_product(
        int $product_id,
        Cashback_Campaign_Detail_DTO $dto,
        int $network_id,
        string $signature,
        string $domain,
        string $now,
        string $adapter_slug
    ): void {
        self::write_product_meta($product_id, $dto, $network_id, $signature, $domain, $now, $adapter_slug);
        if ($dto->goto_link !== '') {
            update_post_meta($product_id, '_product_url', $dto->goto_link);
        }

        // Idempotent backfill для товаров, импортированных до релиза с
        // taxonomy/defaults: если term или мета пустые — проставляем,
        // если уже заполнено админом — не трогаем.
        self::backfill_missing_admin_fields($product_id);

        // Featured image заливаем только если у товара ещё нет thumbnail —
        // не перекачиваем повторно, экономим HTTP и место в uploads.
        if (function_exists('has_post_thumbnail') && ! has_post_thumbnail($product_id)) {
            self::attach_featured_image_from_url($product_id, $dto->image_url, $adapter_slug, $dto->id);
        }
    }

    /**
     * Backfill для существующих товаров: проставляет дефолт ТОЛЬКО если
     * текущее значение пустое. Покрывает товары, импортированные до фикса
     * с taxonomy / дефолтами админ-полей.
     *
     * НИКОГДА не перезаписывает не-пустое значение — админ мог его
     * целенаправленно изменить или удалить.
     */
    private static function backfill_missing_admin_fields( int $product_id ): void {
        if ($product_id <= 0) {
            return;
        }

        // Taxonomy product_type — без него WC прячет External Product поля.
        // wp_get_object_terms возвращает array|WP_Error; WP_Error — объект,
        // потому `! is_array($current)` уже отлавливает WP_Error и любой
        // не-array fallback. Доп. is_wp_error() была бы dead code
        // (PHPStan: function.impossibleType).
        if (function_exists('wp_get_object_terms')) {
            $current  = wp_get_object_terms($product_id, 'product_type', array( 'fields' => 'slugs' ));
            $is_empty = $current === array();
            if ($is_empty) {
                self::set_product_type_external($product_id);
            }
        }

        $defaults = array(
            '_button_text'                                  => self::DEFAULT_BUTTON_TEXT,
            '_store_popup_mode'                             => self::DEFAULT_POPUP_MODE,
            '_cashback_display_label'                       => self::DEFAULT_DISPLAY_LABEL,
            '_woodmart_product_custom_tab_title'            => self::DEFAULT_TAB1_TITLE,
            '_woodmart_product_custom_tab_priority'         => self::DEFAULT_TAB1_PRIORITY,
            '_woodmart_product_custom_tab_content_type'     => 'text',
            '_woodmart_product_custom_tab_title_2'          => self::DEFAULT_TAB2_TITLE,
            '_woodmart_product_custom_tab_priority_2'       => self::DEFAULT_TAB2_PRIORITY,
            '_woodmart_product_custom_tab_content_type_2'   => 'text',
            '_woodmart_product_custom_tab_content_2'        => self::DEFAULT_TAB2_CONTENT,
        );
        foreach ($defaults as $meta_key => $default_value) {
            $existing = get_post_meta($product_id, $meta_key, true);
            if ($existing === '' || $existing === null || $existing === false) {
                update_post_meta($product_id, $meta_key, $default_value);
            }
        }
    }

    /**
     * Разовый backfill приоритета Tab[1] «Условия» для уже импортированных
     * товаров. Маркер авто-импорта — наличие meta `_affiliate_network_id`
     * (= self::META_NETWORK_ID). Приоритет — это лишь порядок вкладки, не
     * контент, поэтому перезаписываем у ВСЕХ авто-товаров (включая те, где
     * админ редактировал контент «Условий»).
     *
     * Идемпотентно: update_post_meta идёт только если текущее значение != '1',
     * повторный вызов вернёт 0. update_post_meta (а не raw SQL) — чтобы
     * object cache postmeta остался когерентным.
     *
     * @return int Количество товаров, у которых приоритет был изменён на '1'.
     */
    public static function backfill_tab1_priority(): int {
        global $wpdb;

        $product_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
                self::META_NETWORK_ID
            )
        );

        if (! is_array($product_ids) || $product_ids === array()) {
            return 0;
        }

        $updated = 0;
        foreach ($product_ids as $product_id) {
            $product_id = (int) $product_id;
            if ($product_id <= 0) {
                continue;
            }
            $current = (string) get_post_meta($product_id, '_woodmart_product_custom_tab_priority', true);
            if ($current === self::DEFAULT_TAB1_PRIORITY) {
                continue;
            }
            update_post_meta($product_id, '_woodmart_product_custom_tab_priority', self::DEFAULT_TAB1_PRIORITY);
            ++$updated;
        }

        return $updated;
    }

    /**
     * Сгенерировать и записать HTML-контент Tab[1] «Условия».
     *
     * Вызывается во всех трёх ветках upsert (new/updated/unchanged): новые
     * товары получают свежий HTML, существующие — backfill с учётом текущих
     * тарифов и payment_time. Существующий контент перезаписывается ТОЛЬКО
     * если он отсутствует или начинается с sentinel-маркера autogen v1 —
     * любая admin-edit (без маркера) сохраняется.
     *
     * `update_post_meta` сам no-op'ит при old==new — лишних cache invalidations
     * не будет, поэтому idempotency на cron-проходах не страдает.
     */
    private static function apply_tab1_conditions_content( int $product_id, int $network_id, string $offer_id ): void {
        if ($product_id <= 0 || $network_id <= 0 || $offer_id === '') {
            return;
        }
        if (! class_exists('Cashback_Tab_Conditions_Renderer')) {
            return;
        }

        $current = (string) get_post_meta($product_id, '_woodmart_product_custom_tab_content', true);
        if ($current !== '' && ! Cashback_Tab_Conditions_Renderer::is_autogen($current)) {
            // Admin отредактировал контент или удалил sentinel — не трогаем.
            return;
        }

        $html = Cashback_Tab_Conditions_Renderer::render($product_id, $network_id, $offer_id);
        if ($html === '') {
            return;
        }

        update_post_meta($product_id, '_woodmart_product_custom_tab_content', $html);
        update_post_meta($product_id, '_woodmart_product_custom_tab_title', self::DEFAULT_TAB1_TITLE);
        update_post_meta($product_id, '_woodmart_product_custom_tab_priority', self::DEFAULT_TAB1_PRIORITY);
        update_post_meta($product_id, '_woodmart_product_custom_tab_content_type', 'text');
    }

    /**
     * Запись общих метаполей (привязка + signature + status).
     */
    private static function write_product_meta(
        int $product_id,
        Cashback_Campaign_Detail_DTO $dto,
        int $network_id,
        string $signature,
        string $domain,
        string $now,
        string $adapter_slug
    ): void {
        update_post_meta($product_id, self::META_NETWORK_ID, (string) $network_id);
        update_post_meta($product_id, self::META_OFFER_ID, $dto->id);
        update_post_meta($product_id, self::META_STORE_DOMAIN, $domain);
        update_post_meta($product_id, self::META_IMPORT_SOURCE, $adapter_slug);
        update_post_meta($product_id, self::META_SIGNATURE, $signature);
        update_post_meta($product_id, self::META_IMPORT_AT, $now);
        update_post_meta($product_id, self::META_LAST_SEEN_AT, $now);
        update_post_meta($product_id, self::META_CURRENCY, $dto->currency);
        update_post_meta($product_id, self::META_STATUS_RAW, $dto->status_raw);

        if ($dto->payment_time_days !== null) {
            update_post_meta($product_id, self::META_AVG_PAYMENT_DAYS, (string) $dto->payment_time_days);
        }
    }

    /**
     * Скачать картинку из URL в media library и поставить как featured image.
     * Best-effort: ошибки логируются, но не пробрасываются (импорт
     * не должен валиться из-за недоступного CDN).
     *
     * Для SVG идём через download_url + sanitize + wp_handle_sideload, потому
     * что WP-core `media_sideload_image()` принимает только jpe?g/gif/png/webp
     * и для .svg возвращает WP_Error «Неверный URL изображения». Admitad
     * отдаёт ~50% магазинов в виде .svg-логотипов на CDN — без этой ветки
     * половина товаров остаётся без featured image.
     *
     * @param string $image_url   URL логотипа (часто на CDN сети).
     * @param string $adapter_slug Slug сети — для контекста в логе.
     * @param string $offer_id    ID кампании в сети — для контекста в логе.
     */
    private static function attach_featured_image_from_url(
        int $product_id,
        string $image_url,
        string $adapter_slug,
        string $offer_id
    ): void {
        if ($product_id <= 0 || $image_url === '') {
            return;
        }

        self::ensure_admin_media_includes();

        if (! function_exists('set_post_thumbnail')) {
            return;
        }

        $attachment_id = self::is_svg_url($image_url)
            ? self::sideload_svg_attachment($image_url, $product_id, $adapter_slug, $offer_id)
            : self::sideload_raster_attachment($image_url, $product_id, $adapter_slug, $offer_id);

        if ($attachment_id === null) {
            return;
        }
        set_post_thumbnail($product_id, $attachment_id);
    }

    /**
     * Подгрузить wp-admin/includes/{media,file,image}.php — они нужны и
     * для `media_sideload_image`, и для `download_url` / `wp_handle_sideload` /
     * `wp_generate_attachment_metadata`. На AS-cron ABSPATH есть, но admin
     * include'ы не загружены.
     */
    private static function ensure_admin_media_includes(): void {
        if (! defined('ABSPATH')) {
            return;
        }
        $files = array(
            ABSPATH . 'wp-admin/includes/media.php',
            ABSPATH . 'wp-admin/includes/file.php',
            ABSPATH . 'wp-admin/includes/image.php',
        );
        foreach ($files as $f) {
            if (file_exists($f)) {
                require_once $f;
            }
        }
    }

    private static function is_svg_url( string $url ): bool {
        if ($url === '') {
            return false;
        }
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '');
        if ($path === '') {
            return false;
        }
        return strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'svg';
    }

    /**
     * Legacy WP-путь: media_sideload_image для jpg/png/gif/webp.
     *
     * Оборачивает вызов в scoped `http_request_args` фильтр, режущий
     * timeout до {@see IMAGE_DOWNLOAD_TIMEOUT_SECONDS} только для этого
     * вызова. Фильтр снимается в `finally` — никаких побочных эффектов
     * на остальные HTTP-запросы плагина в том же процессе.
     */
    private static function sideload_raster_attachment(
        string $image_url,
        int $product_id,
        string $adapter_slug,
        string $offer_id
    ): ?int {
        if (! function_exists('media_sideload_image')) {
            return null;
        }

        $timeout_filter = static function ( $args ) {
            if (! is_array($args)) {
                $args = array();
            }
            $args['timeout'] = self::IMAGE_DOWNLOAD_TIMEOUT_SECONDS;
            return $args;
        };
        // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_args -- AS-cron контекст (НЕ web-request); cap 15с снижает дефолтные 300с — ровно то, что нужно для image-sideload, чтобы не блокировать всю задачу.
        add_filter('http_request_args', $timeout_filter, 99);
        try {
            $attachment_id = media_sideload_image($image_url, $product_id, null, 'id');
        } finally {
            remove_filter('http_request_args', $timeout_filter, 99);
        }
        if (function_exists('is_wp_error') && is_wp_error($attachment_id)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log(sprintf(
                '[Cashback Shop Importer] media_sideload_image failed for product=%d offer=%s slug=%s: %s',
                $product_id,
                $offer_id,
                $adapter_slug,
                $attachment_id->get_error_message()
            ));
            return null;
        }
        return is_int($attachment_id) && $attachment_id > 0 ? $attachment_id : null;
    }

    /**
     * SVG-путь: download_url + санитизация + wp_handle_sideload + wp_insert_attachment.
     * Без санитизации XSS-вектор: SVG может содержать `<script>` / `on*=` /
     * `href="javascript:..."`. CDN партнёра (cdn.admitad-connect.com) — не
     * сторонний UGC, но компромис партнёра возможен — defense-in-depth.
     */
    private static function sideload_svg_attachment(
        string $image_url,
        int $product_id,
        string $adapter_slug,
        string $offer_id
    ): ?int {
        if (
            ! function_exists('download_url')
            || ! function_exists('wp_handle_sideload')
            || ! function_exists('wp_insert_attachment')
        ) {
            return null;
        }

        $tmp = download_url($image_url, self::IMAGE_DOWNLOAD_TIMEOUT_SECONDS);
        if (function_exists('is_wp_error') && is_wp_error($tmp)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log(sprintf(
                '[Cashback Shop Importer] SVG download_url failed for product=%d offer=%s slug=%s: %s',
                $product_id,
                $offer_id,
                $adapter_slug,
                $tmp->get_error_message()
            ));
            return null;
        }
        if ($tmp === '') {
            return null;
        }

        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local tmp-file from download_url(); @-silence — на race-condition если файл удалили parallel'но.
            $raw = @file_get_contents($tmp);
            if ($raw === false) {
                return null;
            }
            $clean = self::sanitize_svg($raw);
            if ($clean === null) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                error_log(sprintf(
                    '[Cashback Shop Importer] SVG payload rejected by sanitizer for product=%d offer=%s slug=%s url=%s',
                    $product_id,
                    $offer_id,
                    $adapter_slug,
                    $image_url
                ));
                return null;
            }
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- @-silence на race-condition, false-обработка ниже.
            if (@file_put_contents($tmp, $clean) === false) {
                return null;
            }

            $url_path = (string) (wp_parse_url($image_url, PHP_URL_PATH) ?? '');
            $basename = $url_path !== '' ? basename($url_path) : '';
            if ($basename === '' || stripos($basename, '.svg') === false) {
                $basename = 'cashback-shop-' . $product_id . '.svg';
            }

            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- @-silence на race-condition, fallback 0.
            $size = @filesize($tmp);
            $file_array = array(
                'name'     => $basename,
                'type'     => 'image/svg+xml',
                'tmp_name' => $tmp,
                'error'    => 0,
                'size'     => is_int($size) ? $size : 0,
            );
            $overrides = array(
                'test_form' => false,
                'mimes'     => array( 'svg' => 'image/svg+xml' ),
            );

            // WP-core wp_check_filetype_and_ext через finfo_file часто возвращает
            // для SVG mime «text/xml» / «text/html», и WP сбрасывает type=false
            // → wp_handle_sideload отдаёт «Извините, вам не разрешено загружать
            // SVG файлы». Локальные фильтры (только на время этого вызова)
            // форсят корректный mime+ext. Фильтры removed в finally.
            //
            // Защита: фильтр срабатывает ТОЛЬКО если caller-overrides включают
            // svg в разрешённых mime ($mimes-аргумент) И real_mime похож на
            // XML/text/octet-stream (НЕ PHP/исполняемый — defense-in-depth).
            $force_svg_check = static function ( array $check, string $file_path, string $filename, $mimes, string $real_mime = '' ): array {
                unset($file_path);
                if (! preg_match('/\.svgz?$/i', $filename)) {
                    return $check;
                }
                if (! is_array($mimes) || ! isset($mimes['svg'])) {
                    return $check;
                }
                $rm = strtolower($real_mime);
                $is_safe_real_mime = $rm === ''
                    || str_starts_with($rm, 'image/svg')
                    || str_starts_with($rm, 'text/')
                    || $rm === 'application/xml'
                    || $rm === 'application/octet-stream';
                if (! $is_safe_real_mime) {
                    return $check;
                }
                $check['ext']             = 'svg';
                $check['type']            = 'image/svg+xml';
                $check['proper_filename'] = $filename;
                return $check;
            };
            $allow_svg_mime = static function ( array $mimes ): array {
                $mimes['svg'] = 'image/svg+xml';
                return $mimes;
            };

            add_filter('wp_check_filetype_and_ext', $force_svg_check, 99, 5);
            // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.upload_mimes -- SVG разрешается ТОЛЬКО на время этого sideload (remove_filter в finally); content санитизируется выше через sanitize_svg().
            add_filter('upload_mimes', $allow_svg_mime, 99);
            // safe-svg блокирует SVG-upload для guest user (на AS-cron user=0)
            // через wp_handle_upload_prefilter → check_for_svg → ! current_user_can_upload_svg
            // → 'Sorry, you are not allowed to upload SVG files.' Снимаем restriction
            // через документированный filter safe-svg ТОЛЬКО на время sideload —
            // контент уже санитизирован нашим sanitize_svg(); safe-svg внутри
            // дополнительно прогонит enshrined/svg-sanitize (defense-in-depth).
            add_filter('safe_svg_current_user_can_upload', '__return_true', 99);
            try {
                $sideloaded = wp_handle_sideload($file_array, $overrides);
            } finally {
                remove_filter('wp_check_filetype_and_ext', $force_svg_check, 99);
                remove_filter('upload_mimes', $allow_svg_mime, 99);
                remove_filter('safe_svg_current_user_can_upload', '__return_true', 99);
            }

            if (isset($sideloaded['error'])) {
                $err = (string) ($sideloaded['error'] ?? '?');
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                error_log(sprintf(
                    '[Cashback Shop Importer] SVG wp_handle_sideload failed for product=%d offer=%s slug=%s: %s',
                    $product_id,
                    $offer_id,
                    $adapter_slug,
                    $err
                ));
                return null;
            }

            $final_file = (string) ($sideloaded['file'] ?? '');
            if ($final_file === '') {
                return null;
            }

            $title = (string) pathinfo($basename, PATHINFO_FILENAME);
            if (function_exists('sanitize_file_name')) {
                $title = sanitize_file_name($title);
            }

            $attachment = array(
                'post_mime_type' => 'image/svg+xml',
                'post_title'     => $title !== '' ? $title : ('shop-' . $product_id),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_parent'    => $product_id,
            );
            $attachment_id = wp_insert_attachment($attachment, $final_file, $product_id, true);
            if (function_exists('is_wp_error') && is_wp_error($attachment_id)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                error_log(sprintf(
                    '[Cashback Shop Importer] SVG wp_insert_attachment failed for product=%d offer=%s slug=%s: %s',
                    $product_id,
                    $offer_id,
                    $adapter_slug,
                    $attachment_id->get_error_message()
                ));
                return null;
            }
            if ($attachment_id <= 0) {
                return null;
            }

            if (function_exists('wp_generate_attachment_metadata') && function_exists('wp_update_attachment_metadata')) {
                $meta = wp_generate_attachment_metadata($attachment_id, $final_file);
                wp_update_attachment_metadata($attachment_id, $meta);
            }

            return $attachment_id;
        } finally {
            // tmp-файл оставлять нельзя — wp_handle_sideload его перемещает
            // в uploads, но если был ранний return — мы должны убрать.
            if (file_exists($tmp)) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup, race с move внутри wp_handle_sideload.
                @unlink($tmp);
            }
        }
    }

    /**
     * Минимальный SVG-санитайзер (defense-in-depth для image-payload от партнёра):
     *  • удаляет опасные элементы (<script>, <foreignObject>, <iframe>,
     *    <embed>, <object>, <animate*>, <set>, <handler>, <use href>);
     *  • удаляет on*-атрибуты обработчиков событий;
     *  • удаляет href/src/xlink:href со схемой javascript:|vbscript:|data:;
     *  • отвергает payload без `<svg`-корня (вернёт null).
     *
     * Возвращает санитизированную строку или null если payload не похож
     * на SVG (всё неразобранное должно отбрасываться).
     */
    private static function sanitize_svg( string $content ): ?string {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (trim($content) === '') {
            return null;
        }
        if (stripos($content, '<svg') === false) {
            return null;
        }

        $dangerous = array(
            'script',
            'foreignObject',
            'iframe',
            'embed',
            'object',
            'animate',
            'animateTransform',
            'animateMotion',
            'set',
            'handler',
        );
        foreach ($dangerous as $tag) {
            $pattern_paired = '#<\s*' . preg_quote($tag, '#') . '\b[^>]*>.*?<\s*/\s*' . preg_quote($tag, '#') . '\s*>#is';
            $content = (string) preg_replace($pattern_paired, '', $content);
            $pattern_self = '#<\s*' . preg_quote($tag, '#') . '\b[^>]*/\s*>#is';
            $content = (string) preg_replace($pattern_self, '', $content);
            $pattern_open = '#<\s*' . preg_quote($tag, '#') . '\b[^>]*>#is';
            $content = (string) preg_replace($pattern_open, '', $content);
        }

        // on*-обработчики событий: onclick / onload / onerror / onmouseover ...
        $content = (string) preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $content);

        // javascript:/vbscript:/data: URL в href / src / xlink:href.
        $content = (string) preg_replace(
            '#\s+(?:xlink:href|href|src)\s*=\s*("|\')\s*(?:javascript|vbscript|data)\s*:[^"\']*\1#i',
            '',
            $content
        );

        return $content;
    }

    /**
     * Sync тарифов одной кампании. Возвращает количество upserted-row.
     *
     * Источник тарифов: `$dto->inline_tariffs` (Admitad website-scoped endpoint
     * отдаёт тарифы прямо в детальной кампании). Если inline нет — fallback
     * на adapter::fetch_shop_tariffs (legacy /actions/ endpoint, для каталога
     * вне website-scope или если CPA-сеть починит его в будущем).
     */
    private static function sync_tariffs_for_campaign(
        Cashback_Network_Adapter_Interface $adapter,
        array $creds,
        array $network,
        int $network_id,
        Cashback_Campaign_Detail_DTO $dto
    ): array {
        $offer_id = $dto->id;
        $raw_tariffs = $dto->inline_tariffs;
        if ($raw_tariffs === array()) {
            $tariff_result = $adapter->fetch_shop_tariffs($creds, $network, $offer_id);
            if (empty($tariff_result['success'])) {
                return array(
                    'upserted' => 0,
                    'changed'  => false,
                );
            }
            $raw_tariffs = isset($tariff_result['tariffs']) && is_array($tariff_result['tariffs'])
                ? $tariff_result['tariffs']
                : array();
        }

        $dtos = array();
        foreach ($raw_tariffs as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            try {
                $dtos[] = Cashback_Shop_Tariff_DTO::from_array($raw);
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                error_log('[Cashback Shop Importer] tariff DTO error for offer ' . $offer_id . ': ' . $e->getMessage());
                continue;
            }
        }

        $sync         = Cashback_Shop_Tariff_Sync::sync($network_id, $offer_id, $dtos);
        $upserted     = (int) ( $sync['upserted'] ?? 0 );
        $soft_deleted = (int) ( $sync['soft_deleted'] ?? 0 );
        return array(
            'upserted' => $upserted,
            'changed'  => ( $upserted > 0 || $soft_deleted > 0 ),
        );
    }

    /**
     * Flush tariff-change side effects once per processed batch/partial-run.
     *
     * @param array<int, int> $product_ids Product IDs keyed by ID for dedupe.
     */
    private static function flush_deferred_tariff_side_effects( array $product_ids ): void {
        foreach ($product_ids as $product_id) {
            $product_id = (int) $product_id;
            if ($product_id > 0) {
                do_action('cashback_tariffs_changed', $product_id);
            }
        }
    }

    /**
     * Прочитать row из cashback_affiliate_networks (с api_credentials расшифровкой
     * мы не делаем тут — это сделает get_credentials() ниже).
     *
     * @return array<string, mixed>|null
     */
    private static function get_network_row( int $network_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d AND is_active = 1',
                $wpdb->prefix . 'cashback_affiliate_networks',
                $network_id
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * Получить singleton Cashback_API_Client (если зарегистрирован).
     *
     * Cashback_API_Client использует приватный конструктор и доступен только
     * через статический get_instance() — никаких new для него.
     */
    private static function get_api_client(): ?Cashback_API_Client {
        if (! class_exists('Cashback_API_Client')) {
            return null;
        }
        if (! method_exists('Cashback_API_Client', 'get_instance')) {
            return null;
        }
        // phpcs:ignore Generic.Formatting.DisallowMultipleStatements.SameLine -- PHPCS false-positive on this single return after phpcbf newline normalization.
        return Cashback_API_Client::get_instance();
    }

    /**
     * Атомарный per-network lock через MySQL GET_LOCK (F-P1-002).
     *
     * Старая реализация (`get_transient → set_transient` через два WP API-вызова)
     * имела TOCTOU race: два параллельных AS-tick'а на одну CPA-сеть могли оба
     * пройти проверку и оба войти в `run()`, что в свою очередь приводило к
     * дублям в `wp_cashback_shop_tariffs` (UNIQUE constraint спасает) и
     * дублям WC-product'ов в postmeta (UNIQUE невозможен на postmeta — F-P1-003,
     * закрывается ровно этим lock'ом, который сериализует upsert per-network).
     *
     * `GET_LOCK(name, 0)` атомарен на уровне MySQL: возвращает 1 при захвате,
     * 0 если уже занят другим соединением, NULL при ошибке. Lock автоматически
     * освобождается при закрытии connection (защита от зависшего AS-job).
     *
     * @return bool true если lock захвачен.
     */
    private static function try_lock( string $lock_key ): bool {
        global $wpdb;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var') || ! method_exists($wpdb, 'prepare')) {
            return true; // в среде без БД (unit-тесты) — не блокируем.
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK через prepare; advisory-lock не подразумевает caching.
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_key, 0));
        return (int) $result === 1;
    }

    private static function release_lock( string $lock_key ): void {
        global $wpdb;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var') || ! method_exists($wpdb, 'prepare')) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- RELEASE_LOCK через prepare.
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_key));
    }

    private static function import_page_cache_key( string $run_id, int $network_id, int $offset ): string {
        return 'cashback_import_page_' . md5($run_id . '|' . $network_id . '|' . $offset);
    }

    private static function get_cached_import_page( string $cache_key ): ?array {
        if (! function_exists('get_transient')) {
            return null;
        }
        $cached = get_transient($cache_key);
        return is_array($cached) ? $cached : null;
    }

    private static function set_cached_import_page( string $cache_key, array $page ): void {
        if (function_exists('set_transient')) {
            set_transient($cache_key, $page, 15 * MINUTE_IN_SECONDS);
        }
    }

    private static function delete_cached_import_page( string $cache_key ): void {
        if (function_exists('delete_transient')) {
            delete_transient($cache_key);
        }
    }

    /**
     * @return array{upserted_new: int, upserted_upd: int, tariffs_synced: int}|null
     */
    private static function get_import_log_stats( int $log_id ): ?array {
        if ($log_id <= 0) {
            return null;
        }
        global $wpdb;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_row') || ! method_exists($wpdb, 'prepare')) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT upserted_new, upserted_upd, tariffs_synced FROM %i WHERE id = %d',
                $wpdb->prefix . Cashback_Shop_Import_Log::TABLE,
                $log_id
            ),
            ARRAY_A
        );
        if (! is_array($row)) {
            return null;
        }

        return array(
            'upserted_new'   => (int) ( $row['upserted_new'] ?? 0 ),
            'upserted_upd'   => (int) ( $row['upserted_upd'] ?? 0 ),
            'tariffs_synced' => (int) ( $row['tariffs_synced'] ?? 0 ),
        );
    }

    private static function should_pause_for_memory_budget(): bool {
        $limit = self::get_memory_limit_bytes();
        if ($limit <= 0) {
            return false;
        }

        $usage    = memory_get_usage(true);
        $headroom = $limit - $usage;
        return $usage >= (int) floor($limit * self::SAFE_MEMORY_USAGE_RATIO)
            || $headroom <= self::MIN_MEMORY_HEADROOM_BYTES;
    }

    private static function get_memory_limit_bytes(): int {
        $raw = ini_get('memory_limit');
        if (! is_string($raw) || $raw === '') {
            return 0;
        }

        $raw = trim($raw);
        if ($raw === '-1') {
            return 0;
        }

        $unit  = strtolower(substr($raw, -1));
        $value = (float) $raw;
        if ($value <= 0) {
            return 0;
        }

        switch ($unit) {
            case 'g':
                $value *= 1024;
                // no break.
            case 'm':
                $value *= 1024;
                // no break.
            case 'k':
                $value *= 1024;
                break;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function log_run_diagnostics( array $context ): void {
        $slug = (string) ( $context['network_slug'] ?? '' );
        if ($slug !== 'advcake' && empty($context['reschedule_reason'])) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Import diagnostics for production OOM investigation.
        error_log('[Cashback Shop Importer] run diagnostics: ' . wp_json_encode($context));
    }

    /**
     * Стандартный формат ошибочного результата.
     *
     * @return array{success: bool, fetched: int, upserted_new: int, upserted_upd: int, tariffs_synced: int, has_next: bool, next_offset: int, page_cursor: int, error: ?string}
     */
    private static function error_result( string $error ): array {
        return array( 'success'        => false, 'fetched'        => 0, 'upserted_new'   => 0, 'upserted_upd'   => 0, 'tariffs_synced' => 0, 'has_next'       => false, 'next_offset'    => 0, 'page_cursor'    => 0, 'error'          => $error );
    }

    private static function now_mysql(): string {
        if (class_exists('Cashback_Time') && method_exists('Cashback_Time', 'now_mysql')) {
            return Cashback_Time::now_mysql();
        }
        return gmdate('Y-m-d H:i:s');
    }
}
