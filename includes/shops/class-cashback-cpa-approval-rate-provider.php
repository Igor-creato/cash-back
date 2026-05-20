<?php
/**
 * Cashback_CPA_Approval_Rate_Provider — facade для блока «Данные CPA-сети
 * о подтверждении заказов» в редакторе товара.
 *
 * Зачем нужен отдельный facade, а не прямой вызов Refresher из renderer'а:
 *   - render — read-only, AJAX-кнопка — write; имеют одну точку входа в plugin code,
 *     поэтому переопределить для конкретной сети можно одним filter'ом
 *     (`cashback_cpa_approval_rate_provider`), не правя обе вершины;
 *   - "сеть поддерживается" — отдельная семантическая проверка (наличие
 *     метода `fetch_campaign_by_id` на адаптере), здесь же.
 *
 * По умолчанию читает post_meta keys, которые пишет
 * `Cashback_Shop_Rate_Of_Approve_Refresher`, и делегирует refresh туда же.
 * Других провайдеров пока нет — для дополнительных сетей подцепляются
 * через filter.
 *
 * @package CashbackPlugin
 * @since   4.4.22
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Cashback_CPA_Approval_Rate_Provider {

    /**
     * Прочитать текущий снимок approval rate магазина для UI-блока.
     *
     * @return array{rate: ?float, fetched_at: ?int, source: string, refreshable: bool, error: ?string}|null
     *         null — товар не привязан к CPA-сети или сеть не поддерживается
     */
    public static function for_product( int $product_id ): ?array {
        if ($product_id <= 0) {
            return null;
        }

        $network_id = (int) get_post_meta($product_id, Cashback_Shop_Importer::META_NETWORK_ID, true);
        $offer_id   = (string) get_post_meta($product_id, Cashback_Shop_Importer::META_OFFER_ID, true);
        if ($network_id <= 0 || $offer_id === '') {
            return null;
        }

        // Сеть либо имеет API-метод обновления (Admitad), либо поддерживает
        // ручной ввод (Advcake/EPN, v4.4.28+). В обоих режимах post_meta
        // читается одинаково — режим важен только для рендера UI.
        if (! self::network_supports_approval_rate($network_id)
            && ! self::network_supports_manual_entry($network_id)
        ) {
            return null;
        }

        $callback = apply_filters('cashback_cpa_approval_rate_provider', null, $network_id, $offer_id, $product_id);
        if (is_callable($callback)) {
            $result = call_user_func($callback, $product_id, $network_id, $offer_id, 'read');
            return is_array($result) ? self::normalize_read_result($result) : null;
        }

        return self::default_read($product_id);
    }

    /**
     * Синхронно обновить approval rate для конкретного товара (используется
     * AJAX-кнопкой). Возврат совпадает с `for_product()` плюс ключ `success`.
     *
     * @return array{success: bool, rate: ?float, fetched_at: ?int, source: string, refreshable: bool, error: ?string}
     */
    public static function refresh( int $product_id ): array {
        if ($product_id <= 0) {
            return self::error_result('Некорректный product_id');
        }

        $network_id = (int) get_post_meta($product_id, Cashback_Shop_Importer::META_NETWORK_ID, true);
        $offer_id   = (string) get_post_meta($product_id, Cashback_Shop_Importer::META_OFFER_ID, true);
        if ($network_id <= 0 || $offer_id === '') {
            return self::error_result('Товар не привязан к CPA-сети');
        }

        if (! self::network_supports_approval_rate($network_id)) {
            return self::error_result('Сеть не поддерживает обновление поля');
        }

        $callback = apply_filters('cashback_cpa_approval_rate_provider', null, $network_id, $offer_id, $product_id);
        if (is_callable($callback)) {
            $result = call_user_func($callback, $product_id, $network_id, $offer_id, 'refresh');
            if (is_array($result)) {
                return self::normalize_refresh_result($result);
            }
            return self::error_result('Custom provider вернул некорректный результат');
        }

        return self::default_refresh($product_id);
    }

    /**
     * Сеть поддерживает **ручной ввод** approval rate (admin вводит число
     * из кабинета CPA-сети). Условие: сеть активна и у товара есть _offer_id.
     * Используется UI-renderer'ом для решения «показать ли manual-блок» когда
     * `network_supports_approval_rate()` = false (Advcake/EPN и пр., где
     * offer-wide AR не выставлен в публичном API).
     *
     * @since 4.4.28
     */
    public static function network_supports_manual_entry( int $network_id ): bool {
        $slug = self::get_active_network_slug($network_id);
        return in_array($slug, array( 'advcake', 'epn' ), true);
    }

    /**
     * Сохранить approval rate, введённый админом вручную. Запись в те же
     * post_meta что и API-режим — UI-renderer и `for_product()` читают их
     * без разделения. `source` = `manual` (а не slug сети), чтобы по аудиту
     * было видно происхождение значения.
     *
     * Пустая строка / null → удаление post_meta (отказ от прежнего значения).
     * Числа клампятся в [0..100]; за пределами диапазона — ошибка без записи.
     *
     * @since 4.4.28
     * @param mixed $rate string|float|null от UI
     * @return array{success: bool, rate: ?float, fetched_at: ?int, source: string, error: ?string}
     */
    public static function save_manual_rate( int $product_id, $rate ): array {
        if ($product_id <= 0) {
            return self::error_result('Некорректный product_id');
        }

        $network_id = (int) get_post_meta($product_id, Cashback_Shop_Importer::META_NETWORK_ID, true);
        $offer_id   = (string) get_post_meta($product_id, Cashback_Shop_Importer::META_OFFER_ID, true);
        if ($network_id <= 0 || $offer_id === '') {
            return self::error_result('Товар не привязан к CPA-сети');
        }

        if (! self::network_supports_manual_entry($network_id)) {
            return self::error_result('Сеть не поддерживает ручной ввод');
        }

        // Пустая строка → удалить запись (UI: «нет данных от сети»).
        $is_empty = ($rate === null) || (is_string($rate) && trim($rate) === '');
        if ($is_empty) {
            delete_post_meta($product_id, Cashback_Shop_Rate_Of_Approve_Refresher::META_RATE);
            delete_post_meta($product_id, Cashback_Shop_Rate_Of_Approve_Refresher::META_FETCHED_AT);
            delete_post_meta($product_id, Cashback_Shop_Rate_Of_Approve_Refresher::META_SOURCE);
            return array(
                'success'    => true,
                'rate'       => null,
                'fetched_at' => null,
                'source'     => '',
                'error'      => null,
            );
        }

        if (! is_numeric($rate)) {
            return self::error_result('Значение должно быть числом');
        }

        $value = (float) $rate;
        if ($value < 0.0 || $value > 100.0) {
            return self::error_result('Значение должно быть в диапазоне 0..100');
        }

        $rounded = round($value, 2);
        $now     = time();
        update_post_meta($product_id, Cashback_Shop_Rate_Of_Approve_Refresher::META_RATE, (string) $rounded);
        update_post_meta($product_id, Cashback_Shop_Rate_Of_Approve_Refresher::META_FETCHED_AT, (string) $now);
        update_post_meta($product_id, Cashback_Shop_Rate_Of_Approve_Refresher::META_SOURCE, 'manual');

        return array(
            'success'    => true,
            'rate'       => $rounded,
            'fetched_at' => $now,
            'source'     => 'manual',
            'error'      => null,
        );
    }

    /**
     * Сеть поддерживает обновление approval rate если на её адаптере есть
     * метод `fetch_campaign_by_id` (текущий де-факто интерфейс).
     */
    public static function network_supports_approval_rate( int $network_id ): bool {
        $slug = self::get_active_network_slug($network_id);
        if (! is_string($slug) || $slug === '') {
            return false;
        }

        if (! class_exists('Cashback_API_Client') || ! method_exists('Cashback_API_Client', 'get_instance')) {
            return false;
        }
        $client = Cashback_API_Client::get_instance();
        if (! method_exists($client, 'get_adapter')) {
            return false;
        }
        $adapter = $client->get_adapter($slug);
        return is_object($adapter) && method_exists($adapter, 'fetch_campaign_by_id');
    }

    /**
     * Возвращает slug активной сети или null, если сеть неактивна/недоступна.
     */
    private static function get_active_network_slug( int $network_id ): ?string {
        if ($network_id <= 0) {
            return null;
        }
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)
            || ! method_exists($wpdb, 'get_var') || ! method_exists($wpdb, 'prepare')
        ) {
            return null;
        }

        $slug = $wpdb->get_var($wpdb->prepare(
            'SELECT slug FROM %i WHERE id = %d AND is_active = 1',
            $wpdb->prefix . 'cashback_affiliate_networks',
            $network_id
        ));

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    private static function default_read( int $product_id ): array {
        $rate_raw       = get_post_meta($product_id, Cashback_Shop_Rate_Of_Approve_Refresher::META_RATE, true);
        $fetched_at_raw = get_post_meta($product_id, Cashback_Shop_Rate_Of_Approve_Refresher::META_FETCHED_AT, true);
        $source_raw     = get_post_meta($product_id, Cashback_Shop_Rate_Of_Approve_Refresher::META_SOURCE, true);

        return array(
            'rate'        => is_numeric($rate_raw) ? (float) $rate_raw : null,
            'fetched_at'  => is_numeric($fetched_at_raw) ? (int) $fetched_at_raw : null,
            'source'      => is_string($source_raw) ? $source_raw : '',
            'refreshable' => true,
            'error'       => null,
        );
    }

    private static function default_refresh( int $product_id ): array {
        if (! class_exists('Cashback_Shop_Rate_Of_Approve_Refresher')) {
            return self::error_result('Refresher недоступен');
        }
        $result = Cashback_Shop_Rate_Of_Approve_Refresher::refresh_one($product_id);
        return self::normalize_refresh_result($result);
    }

    /**
     * @param array<string, mixed> $r
     * @return array{rate: ?float, fetched_at: ?int, source: string, refreshable: bool, error: ?string}
     */
    private static function normalize_read_result( array $r ): array {
        return array(
            'rate'        => isset($r['rate']) && is_numeric($r['rate']) ? (float) $r['rate'] : null,
            'fetched_at'  => isset($r['fetched_at']) && is_numeric($r['fetched_at']) ? (int) $r['fetched_at'] : null,
            'source'      => isset($r['source']) && is_string($r['source']) ? $r['source'] : '',
            'refreshable' => array_key_exists('refreshable', $r) ? (bool) $r['refreshable'] : true,
            'error'       => isset($r['error']) && is_string($r['error']) ? $r['error'] : null,
        );
    }

    /**
     * @param array<string, mixed> $r
     * @return array{success: bool, rate: ?float, fetched_at: ?int, source: string, refreshable: bool, error: ?string}
     */
    private static function normalize_refresh_result( array $r ): array {
        $base = self::normalize_read_result($r);
        return array(
            'success'     => array_key_exists('success', $r) ? (bool) $r['success'] : ($base['error'] === null),
            'rate'        => $base['rate'],
            'fetched_at'  => $base['fetched_at'],
            'source'      => $base['source'],
            'refreshable' => $base['refreshable'],
            'error'       => $base['error'],
        );
    }

    /**
     * @return array{success: false, rate: null, fetched_at: null, source: string, refreshable: bool, error: string}
     */
    private static function error_result( string $msg ): array {
        return array(
            'success'     => false,
            'rate'        => null,
            'fetched_at'  => null,
            'source'      => '',
            'refreshable' => false,
            'error'       => $msg,
        );
    }

    /**
     * Бакет цветовой плашки. Совпадает по семантике с
     * `Cashback_Shop_Approval_Rate::resolve_bucket`, но порог тот же,
     * чтобы оба блока в редакторе товара читались одинаково.
     */
    public static function bucket_for_rate( ?float $rate ): string {
        if ($rate === null) {
            return 'insufficient';
        }
        if ($rate < 50.0) {
            return 'red';
        }
        if ($rate < 80.0) {
            return 'yellow';
        }
        return 'green';
    }
}
