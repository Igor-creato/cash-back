<?php
/**
 * Cashback_Shop_Group_Resolver — дедуп магазинов между CPA-сетями (v12).
 *
 * Один и тот же магазин может быть одновременно в Admitad и в EPN — на витрине
 * показывается ОДИН WC-товар (preferred) с самой выгодной для пользователя
 * ставкой. Группы формируются автоматически по нормализованному домену
 * (`_store_domain` метаполе, заполняется Cashback_Shop_Importer'ом), админ
 * может в UI «Группы магазинов» подтвердить / разделить / pin.
 *
 * Жизненный цикл:
 *   reconcile_for_product()  → найти/создать группу по домену, привязать продукт,
 *                              пересчитать preferred (вызывается из Importer
 *                              после upsert_product).
 *   resolve_preferred()      → для рендера в карточке: вернуть product_id
 *                              чьи цифры показывать (см. Этап 7 Calculator).
 *   recompute_preferred()    → пересчёт после tariff sync / split / pin.
 *
 * Score function (для preferred):
 *   - PERCENT: max(payment_size) — больше %=лучше для пользователя.
 *   - FIX:     max(payment_size) (в native currency).
 *   - Mixed-currency tie-break: RUB > USD > EUR (фильтр
 *     `cashback_group_currency_priority`).
 *   - pin_product_id перебивает расчёт.
 *
 * @package CashbackPlugin
 * @since   12.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Shop_Group_Resolver {

    public const TABLE_GROUPS  = 'cashback_shop_groups';
    public const TABLE_MEMBERS = 'cashback_shop_group_members';

    public const STATUS_AUTO      = 'auto';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_MANUAL    = 'manual';
    public const STATUS_SPLIT     = 'split';

    /**
     * Default-приоритет валют для tie-break при равных payment_size.
     * Меньший индекс = выше приоритет (RUB первый).
     */
    private const CURRENCY_PRIORITY_DEFAULT = array( 'RUB', 'USD', 'EUR' );

    /**
     * Найти или создать группу по домену продукта; привязать product_id;
     * пересчитать preferred. Идемпотентно.
     *
     * @return int|null group_id (или null если у продукта пустой домен).
     */
    public static function reconcile_for_product( int $product_id ): ?int {
        if ($product_id <= 0) {
            return null;
        }

        $domain = (string) get_post_meta($product_id, '_store_domain', true);
        if ($domain === '') {
            return null;
        }

        $group_id = self::find_or_create_group($domain, self::derive_display_name($product_id));
        if ($group_id === 0) {
            return null;
        }

        self::attach_member($group_id, $product_id);
        self::recompute_preferred($group_id);

        return $group_id;
    }

    /**
     * Найти product_id, чьи цифры показывать на витрине.
     *
     * Если product входит в группу — возвращаем preferred_product_id (или
     * pin_product_id если задан); иначе сам product_id.
     */
    public static function resolve_preferred( int $product_id ): int {
        if ($product_id <= 0) {
            return 0;
        }

        $group = self::get_group_for_product($product_id);
        if ($group === null) {
            return $product_id;
        }

        // Pin override.
        $pin = isset($group['pin_product_id']) ? (int) $group['pin_product_id'] : 0;
        if ($pin > 0) {
            return $pin;
        }

        $preferred = isset($group['preferred_product_id']) ? (int) $group['preferred_product_id'] : 0;
        return $preferred > 0 ? $preferred : $product_id;
    }

    /**
     * Пересчитать preferred_product_id для группы и записать в БД.
     * Учитывает только members с is_excluded=0; pin_product_id перебивает.
     *
     * @return int|null Новое значение preferred_product_id (0 если группа пуста).
     */
    public static function recompute_preferred( int $group_id ): ?int {
        if ($group_id <= 0) {
            return null;
        }

        $group = self::get_group_row($group_id);
        if ($group === null) {
            return null;
        }

        // Pin перебивает score-логику.
        $pin_id = isset($group['pin_product_id']) ? (int) $group['pin_product_id'] : 0;
        if ($pin_id > 0) {
            self::write_preferred($group_id, $pin_id);
            return $pin_id;
        }

        $members = self::get_active_members($group_id);
        if (empty($members)) {
            self::write_preferred($group_id, 0);
            return 0;
        }

        $best_id    = 0;
        $best_score = -1.0;
        $best_curr_idx = PHP_INT_MAX;

        foreach ($members as $product_id) {
            $score = self::score_product((int) $product_id);
            if ($score < 0) {
                continue; // продукт без тарифов / без offer_id — не учитываем.
            }
            $curr_idx = self::currency_priority_index(
                (string) get_post_meta((int) $product_id, '_cashback_campaign_currency', true)
            );

            // Сравнение: больший score лучше; при равенстве — меньший curr_idx.
            if ($score > $best_score
                || ( $score === $best_score && $curr_idx < $best_curr_idx )
            ) {
                $best_id       = (int) $product_id;
                $best_score    = $score;
                $best_curr_idx = $curr_idx;
            }
        }

        self::write_preferred($group_id, $best_id);
        return $best_id;
    }

    /**
     * Score продукта = max(payment_size) среди активных тарифов
     * (через Cashback_Shop_Tariff_Sync::get_active()).
     *
     * Для PERCENT — % напрямую (5.50 = 5.5).
     * Для FIX — payment_size в native currency.
     * Возвращает -1.0 если у продукта нет network_id/offer_id или нет
     * активных тарифов.
     */
    public static function score_product( int $product_id ): float {
        if ($product_id <= 0) {
            return -1.0;
        }

        $network_id = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        $offer_id   = (string) get_post_meta($product_id, '_offer_id', true);
        if ($network_id <= 0 || $offer_id === '') {
            return -1.0;
        }

        if (! class_exists('Cashback_Shop_Tariff_Sync')) {
            return -1.0;
        }

        $tariffs = Cashback_Shop_Tariff_Sync::get_active($network_id, $offer_id);
        if (empty($tariffs)) {
            return -1.0;
        }

        $best = -1.0;
        foreach ($tariffs as $row) {
            $size = isset($row['payment_size']) ? (float) $row['payment_size'] : 0.0;
            if ($size > $best) {
                $best = $size;
            }
        }
        return $best;
    }

    /**
     * Подтвердить группу (status auto → confirmed). Admin-action.
     */
    public static function confirm( int $group_id ): bool {
        return self::update_group_status($group_id, self::STATUS_CONFIRMED);
    }

    /**
     * Pin product_id как preferred — score-логика игнорируется до unpin.
     */
    public static function pin_product( int $group_id, int $product_id ): bool {
        if ($group_id <= 0 || $product_id <= 0) {
            return false;
        }
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_GROUPS;

        $r = $wpdb->update(
            $table,
            array(
                'pin_product_id'       => $product_id,
                'preferred_product_id' => $product_id,
                'status'               => self::STATUS_MANUAL,
            ),
            array( 'id' => $group_id ),
            array( '%d', '%d', '%s' ),
            array( '%d' )
        );

        if ($r !== false && function_exists('do_action')) {
            // Pin меняет preferred напрямую без recompute_preferred — фирим
            // action вручную для catalog visibility sync.
            do_action('cashback_group_preferred_changed', $group_id);
        }

        return $r !== false;
    }

    /**
     * Снять pin — preferred снова пересчитывается по score.
     */
    public static function unpin( int $group_id ): bool {
        if ($group_id <= 0) {
            return false;
        }
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_GROUPS;

        $r = $wpdb->update(
            $table,
            array(
                'pin_product_id' => null,
                'status'         => self::STATUS_AUTO,
            ),
            array( 'id' => $group_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ($r === false) {
            return false;
        }

        self::recompute_preferred($group_id);
        return true;
    }

    /**
     * Выкинуть product из группы — он становится одиночным (новая группа
     * со status=split, либо запись удаляется и при следующем reconcile
     * domain резолв создаст заново).
     */
    public static function split_member( int $product_id ): bool {
        if ($product_id <= 0) {
            return false;
        }
        global $wpdb;

        $members_table = $wpdb->prefix . self::TABLE_MEMBERS;

        // Найти группу.
        $group = self::get_group_for_product($product_id);
        if ($group === null) {
            return false;
        }
        $group_id = (int) $group['id'];

        // Удалить из members.
        $wpdb->delete(
            $members_table,
            array( 'product_id' => $product_id ),
            array( '%d' )
        );

        // Пересчитать preferred оставшейся группы (фирит cashback_group_preferred_changed
        // через write_preferred → catalog visibility sync для оставшихся members).
        self::recompute_preferred($group_id);

        // Splitted product сам уже не в группе — отдельный mark_visible
        // (он стал standalone, должен быть видим в каталоге).
        if (class_exists('Cashback_Catalog_Visibility')) {
            Cashback_Catalog_Visibility::mark_visible($product_id);
        }

        return true;
    }

    /**
     * Найти/создать группу по домену.
     *
     * @return int group_id (0 при ошибке INSERT).
     */
    public static function find_or_create_group( string $domain, string $display_name = '' ): int {
        if ($domain === '') {
            return 0;
        }
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_GROUPS;

        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE domain = %s LIMIT 1',
            $table,
            $domain
        ));

        if (is_numeric($existing) && (int) $existing > 0) {
            return (int) $existing;
        }

        $ok = $wpdb->insert(
            $table,
            array(
                'domain'       => $domain,
                'display_name' => $display_name,
                'status'       => self::STATUS_AUTO,
            ),
            array( '%s', '%s', '%s' )
        );

        if ($ok === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback Shop Group Resolver] insert group failed: ' . $wpdb->last_error);
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Привязать product к группе (UNIQUE по product_id — INSERT IGNORE-семантика
     * через ON DUPLICATE KEY UPDATE).
     */
    public static function attach_member( int $group_id, int $product_id ): void {
        if ($group_id <= 0 || $product_id <= 0) {
            return;
        }
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_MEMBERS;

        $sql = $wpdb->prepare(
            'INSERT INTO %i (group_id, product_id, is_excluded, created_at)
             VALUES (%d, %d, 0, %s)
             ON DUPLICATE KEY UPDATE group_id = VALUES(group_id), is_excluded = 0',
            $table,
            $group_id,
            $product_id,
            self::now_mysql()
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql собран wpdb->prepare выше, write-path.
        $wpdb->query($sql);
    }

    /**
     * Получить group для product_id (через members JOIN groups).
     *
     * @return array<string, mixed>|null
     */
    public static function get_group_for_product( int $product_id ): ?array {
        if ($product_id <= 0) {
            return null;
        }
        global $wpdb;

        $groups_table  = $wpdb->prefix . self::TABLE_GROUPS;
        $members_table = $wpdb->prefix . self::TABLE_MEMBERS;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT g.* FROM %i AS g
               JOIN %i AS m ON g.id = m.group_id
              WHERE m.product_id = %d AND m.is_excluded = 0
              LIMIT 1',
            $groups_table,
            $members_table,
            $product_id
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Прочитать row группы.
     *
     * @return array<string, mixed>|null
     */
    public static function get_group_row( int $group_id ): ?array {
        if ($group_id <= 0) {
            return null;
        }
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_GROUPS;
        $row   = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            $table,
            $group_id
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Активные members группы (is_excluded=0).
     *
     * @return array<int, int> product_ids
     */
    public static function get_active_members( int $group_id ): array {
        if ($group_id <= 0) {
            return array();
        }
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_MEMBERS;
        $rows  = $wpdb->get_results($wpdb->prepare(
            'SELECT product_id FROM %i WHERE group_id = %d AND is_excluded = 0',
            $table,
            $group_id
        ), ARRAY_A);

        if (! is_array($rows)) {
            return array();
        }
        $ids = array();
        foreach ($rows as $r) {
            $ids[] = (int) ( $r['product_id'] ?? 0 );
        }
        return array_filter($ids, static fn( int $i ): bool => $i > 0);
    }

    /**
     * Записать preferred_product_id (0 = NULL).
     *
     * Фирит action `cashback_group_preferred_changed` для синка catalog
     * visibility (Cashback_Catalog_Visibility::on_group_preferred_changed).
     */
    private static function write_preferred( int $group_id, int $preferred_id ): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_GROUPS;

        $wpdb->update(
            $table,
            array(
                'preferred_product_id' => $preferred_id > 0 ? $preferred_id : null,
            ),
            array( 'id' => $group_id ),
            array( '%d' ),
            array( '%d' )
        );

        if (function_exists('do_action')) {
            do_action('cashback_group_preferred_changed', $group_id);
        }
    }

    /**
     * UPDATE group.status.
     */
    private static function update_group_status( int $group_id, string $status ): bool {
        if ($group_id <= 0) {
            return false;
        }
        $allowed = array( self::STATUS_AUTO, self::STATUS_CONFIRMED, self::STATUS_MANUAL, self::STATUS_SPLIT );
        if (! in_array($status, $allowed, true)) {
            return false;
        }
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_GROUPS;
        $r     = $wpdb->update(
            $table,
            array( 'status' => $status ),
            array( 'id' => $group_id ),
            array( '%s' ),
            array( '%d' )
        );
        return $r !== false;
    }

    /**
     * Индекс валюты в priority-списке (для tie-break при равных score).
     * Меньше = лучше. PHP_INT_MAX для неизвестной валюты — последняя по приоритету.
     */
    private static function currency_priority_index( string $currency ): int {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return PHP_INT_MAX;
        }

        $list = self::CURRENCY_PRIORITY_DEFAULT;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('cashback_group_currency_priority', $list);
            if (is_array($filtered) && ! empty($filtered)) {
                $list = array_values(array_map('strtoupper', $filtered));
            }
        }

        $idx = array_search($currency, $list, true);
        return $idx === false ? PHP_INT_MAX : (int) $idx;
    }

    /**
     * display_name из post_title (best-effort).
     */
    private static function derive_display_name( int $product_id ): string {
        if (function_exists('get_the_title')) {
            $title = get_the_title($product_id);
            if (is_string($title) && $title !== '') {
                return $title;
            }
        }
        return '';
    }

    /**
     * Текущее UTC-время в MySQL-формате.
     */
    private static function now_mysql(): string {
        if (class_exists('Cashback_Time') && method_exists('Cashback_Time', 'now_mysql')) {
            return Cashback_Time::now_mysql();
        }
        return gmdate('Y-m-d H:i:s');
    }
}
