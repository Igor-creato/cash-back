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

    public const META_NETWORK_ID    = '_affiliate_network_id';
    public const META_OFFER_ID      = '_offer_id';
    public const META_STORE_DOMAIN  = '_store_domain';
    public const META_IMPORT_SOURCE = '_cashback_import_source';
    public const META_SIGNATURE     = '_cashback_import_signature';
    public const META_IMPORT_AT     = '_cashback_import_at';
    public const META_LAST_SEEN_AT  = '_cashback_last_seen_at';
    public const META_CURRENCY      = '_cashback_campaign_currency';
    public const META_STATUS_RAW    = '_cashback_campaign_status_raw';
    public const META_RATE_LOCKED   = '_rate_locked';

    // Дефолты, проставляемые ТОЛЬКО при первичном импорте (insert_draft_product).
    // На update_existing_product не трогаем — админ мог изменить.
    public const DEFAULT_BUTTON_TEXT    = 'Перейти';
    public const DEFAULT_POPUP_MODE     = 'hide';
    public const DEFAULT_DISPLAY_LABEL  = 'Кэшбэк';
    public const DEFAULT_TAB1_TITLE     = 'Условия';
    public const DEFAULT_TAB1_PRIORITY  = '80';
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
        // оборачиваем в run_action() который игнорирует результат.
        add_action(self::HOOK_RUN, array( self::class, 'run_action' ), 10, 3);
        add_action(self::HOOK_RECURRING, array( self::class, 'enqueue_all_active' ));
        add_action(self::HOOK_GROUPS_RECOMPUTE, array( self::class, 'recompute_auto_groups' ));
        add_action(self::HOOK_LOG_GC, array( self::class, 'gc_old_logs' ));

        self::maybe_schedule_recurring();
    }

    /**
     * Action handler-обёртка для HOOK_RUN. Action Scheduler ожидает callback
     * без возвращаемого значения; результат run() (для тестов / admin) сюда
     * не пробрасывается.
     */
    public static function run_action( int $network_id, string $run_id, int $offset = 0 ): void {
        self::run($network_id, $run_id, $offset);
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
                array( $network_id, $run_id, 0 ),
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
     * @param int    $network_id ID сети (cashback_affiliate_networks.id).
     * @param string $run_id     UUIDv7 запуска (общий для всех страниц одного импорта).
     * @param int    $offset     Смещение пагинации (0 на первой странице).
     * @return array{success: bool, fetched: int, upserted_new: int, upserted_upd: int, tariffs_synced: int, has_next: bool, next_offset: int, error: ?string}
     */
    public static function run( int $network_id, string $run_id, int $offset = 0 ): array {
        $page    = (int) max(0, $offset);
        $page_no = $page > 0 && class_exists('Cashback_Shop_Options')
            ? (int) ( $offset / max(1, Cashback_Shop_Options::get_import_batch_size()) )
            : 0;

        $log_id = Cashback_Shop_Import_Log::start_page($run_id, $network_id, $page_no);

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

            $batch_size = class_exists('Cashback_Shop_Options')
                ? Cashback_Shop_Options::get_import_batch_size()
                : 100;

            $fetched_result = $adapter->fetch_campaigns_detailed($creds, $network, $offset, $batch_size);
            if (empty($fetched_result['success'])) {
                $err = (string) ( $fetched_result['error'] ?? 'fetch_campaigns_detailed failed' );
                Cashback_Shop_Import_Log::finish_page($log_id, $err);
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

            foreach ($campaigns as $campaign) {
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
                    $stats['tariffs_synced'] += self::sync_tariffs_for_campaign(
                        $adapter,
                        $creds,
                        $network,
                        $network_id,
                        $dto
                    );
                }
            }

            Cashback_Shop_Import_Log::update_progress(
                $log_id,
                $stats['fetched'],
                $stats['upserted_new'],
                $stats['upserted_upd'],
                $stats['tariffs_synced']
            );
            Cashback_Shop_Import_Log::finish_page($log_id, null);

            $has_next    = ! empty($fetched_result['has_next']);
            $next_offset = isset($fetched_result['next_offset']) ? (int) $fetched_result['next_offset'] : ($offset + $batch_size);

            // Re-enqueue follow-up если есть ещё страницы (action scheduler).
            if ($has_next && function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(
                    self::HOOK_RUN,
                    array( $network_id, $run_id, $next_offset ),
                    self::AS_GROUP
                );
            }

            return array(
                'success'        => true,
                'fetched'        => $stats['fetched'],
                'upserted_new'   => $stats['upserted_new'],
                'upserted_upd'   => $stats['upserted_upd'],
                'tariffs_synced' => $stats['tariffs_synced'],
                'has_next'       => $has_next,
                'next_offset'    => $next_offset,
                'error'          => null,
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
            // Без изменений — только last_seen_at + recompute группы (на случай
            // если соседний продукт изменил тарифы и preferred мог сместиться).
            update_post_meta($existing_id, self::META_LAST_SEEN_AT, $now);
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
            'name'        => $dto->name,
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
        // Маркер external (WC product type taxonomy) — используем metabox-фолбэк.
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
     * UPDATE существующего product (post_title/post_content) + refresh metas.
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
        if (function_exists('wp_update_post')) {
            // post_content НЕ обновляем при ре-импорте — иначе уничтожим
            // отредактированное админом описание. Импорт создаёт draft с
            // пустым content (см. insert_draft_product), дальше — admin
            // территория.
            wp_update_post(array(
                'ID'         => $product_id,
                'post_title' => $dto->name !== '' ? $dto->name : ('Кампания #' . $dto->id),
                // post_status НЕ меняем — админ может уже его опубликовать.
            ));
        }
        self::write_product_meta($product_id, $dto, $network_id, $signature, $domain, $now, $adapter_slug);
        if ($dto->goto_link !== '') {
            update_post_meta($product_id, '_product_url', $dto->goto_link);
        }

        // Featured image заливаем только если у товара ещё нет thumbnail —
        // не перекачиваем повторно, экономим HTTP и место в uploads.
        if (function_exists('has_post_thumbnail') && ! has_post_thumbnail($product_id)) {
            self::attach_featured_image_from_url($product_id, $dto->image_url, $adapter_slug, $dto->id);
        }
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
    }

    /**
     * Скачать картинку из URL в media library и поставить как featured image.
     * Best-effort: ошибки логируются, но не пробрасываются (импорт
     * не должен валиться из-за недоступного CDN).
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
        if (! function_exists('media_sideload_image') || ! function_exists('set_post_thumbnail')) {
            // ABSPATH/wp-admin не загружен (CLI без админ-include). Подключаем —
            // media_sideload_image живёт в wp-admin/includes/media.php.
            if (defined('ABSPATH')) {
                $media_file = ABSPATH . 'wp-admin/includes/media.php';
                $file_file  = ABSPATH . 'wp-admin/includes/file.php';
                $image_file = ABSPATH . 'wp-admin/includes/image.php';
                if (file_exists($media_file)) {
                    require_once $media_file;
                }
                if (file_exists($file_file)) {
                    require_once $file_file;
                }
                if (file_exists($image_file)) {
                    require_once $image_file;
                }
            }
        }
        if (! function_exists('media_sideload_image') || ! function_exists('set_post_thumbnail')) {
            return;
        }

        $attachment_id = media_sideload_image($image_url, $product_id, null, 'id');
        if (function_exists('is_wp_error') && is_wp_error($attachment_id)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log(sprintf(
                '[Cashback Shop Importer] media_sideload_image failed for product=%d offer=%s slug=%s: %s',
                $product_id,
                $offer_id,
                $adapter_slug,
                $attachment_id->get_error_message()
            ));
            return;
        }
        if (! is_int($attachment_id) || $attachment_id <= 0) {
            return;
        }
        set_post_thumbnail($product_id, $attachment_id);
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
    ): int {
        $offer_id = $dto->id;

        $raw_tariffs = $dto->inline_tariffs;
        if ($raw_tariffs === array()) {
            $tariff_result = $adapter->fetch_shop_tariffs($creds, $network, $offer_id);
            if (empty($tariff_result['success'])) {
                return 0;
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

        $sync = Cashback_Shop_Tariff_Sync::sync($network_id, $offer_id, $dtos);
        return (int) ( $sync['upserted'] ?? 0 );
    }

    /**
     * Прочитать row из cashback_affiliate_networks (с api_credentials расшифровкой
     * мы не делаем тут — это сделает get_credentials() ниже).
     *
     * @return array<string, mixed>|null
     */
    private static function get_network_row( int $network_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d AND is_active = 1',
            $wpdb->prefix . 'cashback_affiliate_networks',
            $network_id
        ), ARRAY_A);
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
        return Cashback_API_Client::get_instance();
    }

    /**
     * Best-effort lock через transient (5 мин TTL = больше чем типичный AS-tick).
     * Возвращает true если получили lock, false если уже занят.
     */
    private static function try_lock( string $lock_key ): bool {
        if (! function_exists('get_transient') || ! function_exists('set_transient')) {
            return true; // в среде без transient API считаем что блокировки нет.
        }
        if (get_transient($lock_key)) {
            return false;
        }
        set_transient($lock_key, '1', 300);
        return true;
    }

    private static function release_lock( string $lock_key ): void {
        if (function_exists('delete_transient')) {
            delete_transient($lock_key);
        }
    }

    /**
     * Стандартный формат ошибочного результата.
     *
     * @return array{success: bool, fetched: int, upserted_new: int, upserted_upd: int, tariffs_synced: int, has_next: bool, next_offset: int, error: ?string}
     */
    private static function error_result( string $error ): array {
        return array(
            'success'        => false,
            'fetched'        => 0,
            'upserted_new'   => 0,
            'upserted_upd'   => 0,
            'tariffs_synced' => 0,
            'has_next'       => false,
            'next_offset'    => 0,
            'error'          => $error,
        );
    }

    private static function now_mysql(): string {
        if (class_exists('Cashback_Time') && method_exists('Cashback_Time', 'now_mysql')) {
            return Cashback_Time::now_mysql();
        }
        return gmdate('Y-m-d H:i:s');
    }
}
