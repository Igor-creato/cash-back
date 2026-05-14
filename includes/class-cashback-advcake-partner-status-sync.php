<?php

/**
 * Обработчик постбэков «изменение статуса оффера партнёрской программы» от Advcake.
 *
 * Webhook Receiver принимает запрос вида
 *   GET https://<site>/advcake/<secret>/?event_type=partner_status
 *       &offer_id=<num>&offer_alias=<str>&status=<active|stopped>
 * и пишет raw payload в `cashback_webhooks` с `event_type='partner_status'`
 * и `processing_status=NULL` (см. `event_type` migrations v14).
 *
 * Этот класс — фоновая обработка таких rows: Action Scheduler hook раз в
 * 5 минут берёт до 200 необработанных rows, для каждой по `offer_id` ищет
 * WC-product через `Cashback_Shop_Importer::find_product_by_offer()` и
 * флипает `post_status` (`active`→`publish`, `stopped`→`draft`). Помечает
 * row `processing_status='ok'` / `'click_not_found'` (если продукт не нашёлся).
 *
 * Идемпотентность: Action Scheduler гарантирует один live-instance hook'а
 * одновременно. Каждая row помечается СРАЗУ после успешной обработки —
 * повторный запуск не двинет её повторно (WHERE processing_status IS NULL).
 *
 * @package CashbackPlugin
 * @since   14.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Cashback_Advcake_Partner_Status_Sync {

    /** @var string Имя хука Action Scheduler */
    public const HOOK_NAME = 'cashback_advcake_partner_status_sync';

    /** @var string Группа Action Scheduler */
    private const AS_GROUP = 'cashback';

    /** @var int Интервал между запусками, сек */
    private const INTERVAL = 5 * MINUTE_IN_SECONDS;

    /** @var int Сколько rows обрабатываем за один запуск (защита от long-running job) */
    private const BATCH_LIMIT = 200;

    /**
     * Регистрация хуков. Вызывается из bootstrap'а плагина.
     */
    public static function init(): void {
        add_action(self::HOOK_NAME, array( self::class, 'run' ));
        add_action('init', array( self::class, 'maybe_schedule' ));
    }

    /**
     * Action Scheduler callback (void). Хуковый wrapper над {@see process_batch()},
     * чтобы фрейморк-сигнатура `Action callback returns nothing` была соблюдена,
     * а батч-метод сохранил return-значение для тестов и admin diag-tools.
     */
    public static function run(): void {
        self::process_batch();
    }

    /**
     * Запланировать recurring action через Action Scheduler (one-shot на init).
     */
    public static function maybe_schedule(): void {
        if (function_exists('as_has_scheduled_action')
            && function_exists('as_schedule_recurring_action')
            && !as_has_scheduled_action(self::HOOK_NAME)
        ) {
            as_schedule_recurring_action(
                time(),
                self::INTERVAL,
                self::HOOK_NAME,
                array(),
                self::AS_GROUP
            );
        }
    }

    /**
     * Обработать очередной batch необработанных partner-status webhook'ов.
     *
     * @return array{processed:int, ok:int, not_found:int, error:int}
     */
    public static function process_batch(): array {
        global $wpdb;

        $stats = array( 'processed' => 0, 'ok' => 0, 'not_found' => 0, 'error' => 0 );

        // C-1 (concurrency): advisory lock защищает от двух параллельных
        // process_batch'ей, которые могли бы взять одни и те же rows и
        // дважды флипнуть post_status. GET_LOCK(name, 0) = no-wait; если
        // другой worker держит lock — тихо выходим, на следующем 5-min тике
        // отработаем.
        $lock_acquired = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            'cashback_advcake_partner_status_sync',
            0
        ));
        if ($lock_acquired !== 1) {
            return $stats;
        }

        try {
            return self::process_batch_locked($stats);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'cashback_advcake_partner_status_sync'));
        }
    }

    /**
     * Тело batch-обработки внутри advisory lock'а (см. {@see process_batch()}).
     *
     * @param array{processed:int,ok:int,not_found:int,error:int} $stats
     * @return array{processed:int,ok:int,not_found:int,error:int}
     */
    private static function process_batch_locked( array $stats ): array {
        global $wpdb;

        $webhooks_table = $wpdb->prefix . 'cashback_webhooks';
        $networks_table = $wpdb->prefix . 'cashback_affiliate_networks';

        // P-2 (kill-switch): resolve Advcake network_id ТОЛЬКО среди активных
        // сетей (is_active=1). Если admin выключил Advcake — webhook'и продолжат
        // прилетать на receiver, но мы их не процессим (post_status не флипается).
        // На следующих cron-тиках с is_active=1 — process_batch снова возьмёт
        // pending rows (processing_status IS NULL не меняется).
        $network_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE slug IN (%s, %s) AND is_active = 1 ORDER BY id LIMIT 1',
            $networks_table,
            'advcake',
            'adv'
        ));

        if ($network_id <= 0) {
            return $stats;
        }

        // Проверяем, что колонка event_type существует. На свежеустановленных
        // инсталляциях с db_version < 14 миграция ещё могла не отработать —
        // лучше тихо выйти, чем падать с SQL-error.
        $has_event_type = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = %s
                AND COLUMN_NAME  = %s',
            $webhooks_table,
            'event_type'
        ));
        if ($has_event_type === 0) {
            return $stats;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Background batch processor.
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, payload FROM %i
              WHERE network_slug IN (%s, %s)
                AND event_type = %s
                AND processing_status IS NULL
              ORDER BY id ASC
              LIMIT %d',
            $webhooks_table,
            'advcake',
            'adv',
            'partner_status',
            self::BATCH_LIMIT
        ));

        if (!is_array($rows) || $rows === array()) {
            return $stats;
        }

        foreach ($rows as $row) {
            ++$stats['processed'];

            $row_id  = (int) $row->id;
            $payload = is_string($row->payload) ? $row->payload : '';
            $data    = self::decode_payload($payload);

            if ($data === null) {
                self::mark_row($row_id, 'error');
                ++$stats['error'];
                continue;
            }

            $offer_id = self::extract_offer_id($data);
            $status   = self::extract_partner_status($data);

            if ($offer_id === '' || $status === '') {
                self::mark_row($row_id, 'error');
                ++$stats['error'];
                continue;
            }

            $product_id = Cashback_Shop_Importer::find_product_by_offer($network_id, $offer_id);
            if ($product_id <= 0) {
                self::mark_row($row_id, 'click_not_found');
                ++$stats['not_found'];
                continue;
            }

            $new_status = $status === 'active' ? 'publish' : 'draft';

            // wp_update_post сам не падает если post_status уже такой же, но
            // мы дополнительно избегаем лишних updated_at-флапов и post-meta-revisions.
            $current_post = get_post($product_id);
            if ($current_post instanceof WP_Post && $current_post->post_status !== $new_status) {
                $update = wp_update_post(array(
                    'ID'          => $product_id,
                    'post_status' => $new_status,
                ), true);

                if (is_wp_error($update) || (int) $update === 0) {
                    self::mark_row($row_id, 'error');
                    ++$stats['error'];
                    continue;
                }

                // C-1 sub (meta-coordination): синхронизируем флаг
                // `_cashback_auto_deactivated` с реальным `post_status` после
                // успешного flip'а. Это закрывает 3-writer race на post_status
                // (partner_status_sync + check_campaign_statuses + shop_importer):
                // любой источник, который двигает status, должен поддерживать
                // ту же meta-семантику, чтобы reactivate-cron не оставался в
                // confusing-state «published но auto-deactivated».
                if ($new_status === 'publish') {
                    delete_post_meta($product_id, '_cashback_auto_deactivated');
                    delete_post_meta($product_id, '_cashback_auto_deactivated_at');
                    delete_post_meta($product_id, '_cashback_auto_deactivated_source');
                } else {
                    update_post_meta($product_id, '_cashback_auto_deactivated', '1');
                    update_post_meta($product_id, '_cashback_auto_deactivated_at', current_time('mysql', true));
                    update_post_meta($product_id, '_cashback_auto_deactivated_source', 'advcake_partner_status');
                }
            }

            self::mark_row($row_id, 'ok');
            ++$stats['ok'];
        }

        return $stats;
    }

    /**
     * Декодировать payload. Поддерживаются:
     *  - JSON-encoded строка (worker пушит msgpack/JSON в Redis → JSON в БД);
     *  - URL-encoded query string (`a=1&b=2`), на случай если receiver пишет raw query.
     *
     * @return array<string, mixed>|null
     */
    private static function decode_payload( string $payload ): ?array {
        if ($payload === '') {
            return null;
        }

        $trimmed = ltrim($payload);
        if ($trimmed !== '' && ( $trimmed[0] === '{' || $trimmed[0] === '[' )) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            return null;
        }

        $parsed = array();
        parse_str($payload, $parsed);
        return $parsed === array() ? null : $parsed;
    }

    /**
     * Извлечь numeric offer_id из payload.
     *
     * @param array<string, mixed> $data
     */
    private static function extract_offer_id( array $data ): string {
        $candidates = array( 'offer_id', 'offerId', 'offer' );
        foreach ($candidates as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[ $key ];
            if (is_scalar($value)) {
                $value = trim((string) $value);
                // Допускаем только цифры — match через find_product_by_offer
                // идёт по `_offer_id` post-meta которая хранит string-id из API.
                if (preg_match('/^\d+$/', $value)) {
                    return $value;
                }
            }
        }
        return '';
    }

    /**
     * Извлечь partner status из payload.
     *
     * @param array<string, mixed> $data
     */
    private static function extract_partner_status( array $data ): string {
        $candidates = array( 'status', 'program_status', 'partner_status' );
        foreach ($candidates as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[ $key ];
            if (is_scalar($value)) {
                $value = strtolower(trim((string) $value));
                if ($value === 'active' || $value === 'stopped') {
                    return $value;
                }
            }
        }
        return '';
    }

    /**
     * Пометить строку cashback_webhooks как обработанную.
     */
    private static function mark_row( int $row_id, string $status ): void {
        if ($row_id <= 0) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Marker write, no cache.
        $wpdb->update(
            $wpdb->prefix . 'cashback_webhooks',
            array( 'processing_status' => $status ),
            array( 'id' => $row_id ),
            array( '%s' ),
            array( '%d' )
        );
    }
}
