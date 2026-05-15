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

    public const PREFERRED_BACKFILL_OPTION    = 'cashback_group_preferred_backfill_v1';
    public const PREFERRED_BACKFILL_CRON_HOOK = 'cashback_group_preferred_backfill';
    public const PREFERRED_BACKFILL_BATCH     = 50;

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
     * Приоритет:
     *   1) pin_product_id (админ-override) — если product publishable в members.
     *   2) preferred_product_id (лучший по тарифам, см. recompute_preferred) —
     *      если product publishable в members.
     *   3) deterministic fallback из pick_fallback_member — publishable member
     *      с предпочтением manual override / legacy display value, последний
     *      резерв — min(product_id). Используется когда preferred=NULL
     *      (нет тарифов) ИЛИ pin/preferred указывает на trashed/draft/private
     *      (тот же helper, что в Cashback_Catalog_Visibility::sync_group).
     *   4) Если product вне группы — сам product_id (legacy compat).
     *
     * Codex Round 4 (medium): pin/preferred ВАЛИДИРУЮТСЯ — без этого trashed
     * pin продолжал бы возвращаться слепо.
     *
     * Codex Round 7 (R7-1, high): валидация идёт через publishable, не active.
     * recompute_preferred может выбрать draft/private member как best-by-tariffs
     * (admin pre-publish workflow), и preferred_product_id окажется на draft.
     * Frontend `resolve_preferred` тогда возвращал draft → catalog
     * sync_group hide'ил всех publish → guest catalog пустой. Теперь:
     * preferred=draft → не публично → fall through к publishable fallback.
     *
     * resolve_preferred — read-only hot path: НЕ делаем auto-clear stale pin
     * (как `recompute_preferred` через `clear_pin`). Очистка происходит
     * асинхронно в других местах.
     */
    public static function resolve_preferred( int $product_id ): int {
        if ($product_id <= 0) {
            return 0;
        }

        $group = self::get_group_for_product($product_id);
        if ($group === null) {
            return $product_id;
        }

        $group_id  = (int) ( $group['id'] ?? 0 );
        $pin       = isset($group['pin_product_id']) ? (int) $group['pin_product_id'] : 0;
        $preferred = isset($group['preferred_product_id']) ? (int) $group['preferred_product_id'] : 0;
        $effective = $pin > 0 ? $pin : $preferred;

        if ($effective > 0) {
            // Validation против PUBLISHABLE members (Round 7) закрывает
            // split-brain через draft/private preferred: catalog visibility
            // делает то же самое в sync_group, оба пути выбирают один и
            // тот же frontend-visible член.
            $members = self::get_publishable_members($group_id);
            if (in_array($effective, $members, true)) {
                return $effective;
            }
            // Stale (trashed/deleted) ИЛИ не publishable (draft/private) —
            // fall through к pick_fallback_member.
        }

        // No effective ИЛИ stale ИЛИ non-publishable → deterministic anchor.
        $fallback = self::pick_fallback_member($group_id);
        return $fallback > 0 ? $fallback : $product_id;
    }

    /**
     * Deterministic anchor для группы при отсутствии preferred (no-tariff
     * случай). Используется как single source of truth обоими местами:
     *   - Cashback_Shop_Group_Resolver::resolve_preferred (display calculator);
     *   - Cashback_Catalog_Visibility::sync_group (catalog meta_query).
     *
     * Оба места обязаны выбирать ОДИНАКОВЫЙ member в одинаковом состоянии БД,
     * иначе пользователь увидит один товар в каталоге и другой при прямом
     * заходе по deeplink (фильтр pre_get_posts намеренно пропускает
     * is_singular для CPA-сессий) → split-brain в расчёте кэшбэка.
     *
     * Иерархия выбора (Codex Rounds 1, 2 и 5):
     *   1) Publishable member с manual override (`_rate_locked=1` + непустой
     *      `_manual_advertiser_rate`) — display calculator его рендерит без
     *      тарифов;
     *   2) Publishable member с непустым `_cashback_display_value` (legacy);
     *   3) Publishable min(product_id) — никого с usable cashback, но
     *      invariant «один товар на витрине» соблюдается;
     *   4) Last-resort fallback на active (включая draft/private) — только
     *      если publishable пусто. В нормальном catalog-flow guest всё равно
     *      не увидит draft/private (WC default-фильтр post_status='publish'),
     *      но invariant «sync_group вернул решение» сохраняется для
     *      consistency с resolve_preferred. Tier 4 чаще всего relevant для
     *      pre-publish админ-преview.
     *
     * Codex Round 5 regression fix: до tier 1-3 publishable, draft member
     * с smaller product_id мог победить published sibling → sync_group
     * скрывал published sibling → guest catalog пуст.
     *
     * Sort по SORT_NUMERIC даёт deterministic tie-break внутри каждого tier:
     * если несколько members подходят — выбираем минимальный.
     *
     * @return int product_id или 0 если group_id невалиден / нет members.
     */
    public static function pick_fallback_member( int $group_id ): int {
        if ($group_id <= 0) {
            return 0;
        }

        // Tiers 1-3 ограничены publishable members — то, что guest реально
        // увидит в catalog. Если publishable пусто, идём в tier 4 (active).
        $publishable = self::get_publishable_members($group_id);
        $picked      = self::pick_first_usable_member($publishable);
        if ($picked > 0) {
            return $picked;
        }

        // Tier 4: last-resort на active (для pre-publish/admin-preview).
        $active = self::get_active_members($group_id);
        return self::pick_first_usable_member($active);
    }

    /**
     * Внутренний helper: выбирает member из набора по 3-tier иерархии
     * (manual override → legacy display value → min product_id).
     *
     * @param array<int, int> $members product_ids
     * @return int product_id или 0 если набор пуст.
     */
    private static function pick_first_usable_member( array $members ): int {
        if (empty($members)) {
            return 0;
        }
        $sorted = $members;
        sort($sorted, SORT_NUMERIC);

        if (! function_exists('get_post_meta')) {
            return (int) $sorted[0];
        }

        // Tier 1: manual override.
        foreach ($sorted as $pid) {
            $pid = (int) $pid;
            if ((string) get_post_meta($pid, '_rate_locked', true) !== '1') {
                continue;
            }
            if ((string) get_post_meta($pid, '_manual_advertiser_rate', true) === '') {
                continue;
            }
            return $pid;
        }

        // Tier 2: legacy display value.
        foreach ($sorted as $pid) {
            $pid = (int) $pid;
            if ((string) get_post_meta($pid, '_cashback_display_value', true) !== '') {
                return $pid;
            }
        }

        // Tier 3: deterministic min product_id.
        return (int) $sorted[0];
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

        $members = self::get_active_members($group_id);
        if (empty($members)) {
            self::write_preferred($group_id, 0);
            return 0;
        }

        // Pin перебивает score-логику — но только если pin указывает на
        // active member. Stale pin (на удалённый/trashed product) автоматически
        // очищается, чтобы preferred не остался указывать на мёртвый ID.
        $pin_id = isset($group['pin_product_id']) ? (int) $group['pin_product_id'] : 0;
        if ($pin_id > 0) {
            if (in_array($pin_id, $members, true)) {
                self::write_preferred($group_id, $pin_id);
                return $pin_id;
            }
            self::clear_pin($group_id);
        }

        $best_id   = 0;
        // $best_rank — результат rank_product() лучшего члена либо null.
        $best_rank = null;

        foreach ($members as $product_id) {
            $product_id = (int) $product_id;
            $rank       = self::rank_product($product_id);
            if ($rank === null) {
                continue; // продукт без тарифов / без offer_id — не учитываем.
            }
            $rank['currency_idx'] = self::currency_priority_index(
                (string) get_post_meta($product_id, '_cashback_campaign_currency', true)
            );

            if ($best_rank === null || self::is_better($rank, $best_rank)) {
                $best_id   = $product_id;
                $best_rank = $rank;
            } elseif (
                $best_id > 0
                && $product_id < $best_id
                && ! self::is_better($best_rank, $rank)
            ) {
                // Полное равенство (ни один не is_better) → детерминированно
                // меньший product_id. Без этого победитель зависел бы от
                // порядка строк get_active_members и флапал post_status
                // в draft-модели (Codex HIGH-1).
                $best_id = $product_id;
            }
        }

        self::write_preferred($group_id, $best_id);
        return $best_id;
    }

    /**
     * Ранжирование продукта по активным тарифам для выбора preferred.
     *
     * Возвращает `null` если у продукта нет network_id/offer_id или нет
     * активных тарифов (раньше score_product отдавал -1.0).
     *
     * Иначе:
     *   - has_percent   — есть хотя бы один percent-тариф;
     *   - best_percent  — max(payment_size) среди percent-тарифов (0.0 если нет);
     *   - best_fix      — max(payment_size) среди fix-тарифов (0.0 если нет).
     *
     * Используется recompute_preferred() через is_better(). Разделение по
     * tariff_type обязательно: percent (16%) и fix (27000₽) нельзя сравнивать
     * как голые числа — это давало неверный preferred для mixed-групп
     * (skillfactory.ru: Advcake fix 27000 ошибочно «побеждал» Admitad 16%).
     *
     * @return array{has_percent: bool, best_percent: float, best_fix: float}|null
     */
    public static function rank_product( int $product_id ): ?array {
        if ($product_id <= 0) {
            return null;
        }

        $network_id = (int) get_post_meta($product_id, '_affiliate_network_id', true);
        $offer_id   = (string) get_post_meta($product_id, '_offer_id', true);
        if ($network_id <= 0 || $offer_id === '') {
            return null;
        }

        if (! class_exists('Cashback_Shop_Tariff_Sync')) {
            return null;
        }

        $tariffs = Cashback_Shop_Tariff_Sync::get_active($network_id, $offer_id);
        if (empty($tariffs)) {
            return null;
        }

        $has_percent  = false;
        $best_percent = 0.0;
        $best_fix     = 0.0;
        foreach ($tariffs as $row) {
            $type = isset($row['tariff_type']) ? strtolower((string) $row['tariff_type']) : '';
            $size = isset($row['payment_size']) ? (float) $row['payment_size'] : 0.0;
            if ($type === 'percent') {
                $has_percent = true;
                if ($size > $best_percent) {
                    $best_percent = $size;
                }
            } elseif ($type === 'fix') {
                if ($size > $best_fix) {
                    $best_fix = $size;
                }
            }
        }

        return array(
            'has_percent'  => $has_percent,
            'best_percent' => $best_percent,
            'best_fix'     => $best_fix,
        );
    }

    /**
     * Строго ли $a выгоднее $b (для выбора preferred).
     *
     * Порядок (зеркалит логику Cashback_Cashback_Display_Calculator, который
     * при mixed %+fix отбрасывает fix):
     *   1) percent-товар ≻ fix-only товар (has_percent=true бьёт false);
     *   2) оба percent → больший best_percent; при равенстве — меньший
     *      currency_idx (RUB ≻ USD ≻ EUR, см. currency_priority_index);
     *   3) оба fix-only → больший best_fix; при равенстве — меньший currency_idx.
     *
     * $a / $b — массивы из rank_product() с добавленным ключом currency_idx.
     *
     * @param array{has_percent: bool, best_percent: float, best_fix: float, currency_idx?: int} $a
     * @param array{has_percent: bool, best_percent: float, best_fix: float, currency_idx?: int} $b
     */
    private static function is_better( array $a, array $b ): bool {
        $a_has = ! empty($a['has_percent']);
        $b_has = ! empty($b['has_percent']);

        // (1) percent-товар всегда выгоднее fix-only.
        if ($a_has !== $b_has) {
            return $a_has;
        }

        $a_curr = isset($a['currency_idx']) ? (int) $a['currency_idx'] : PHP_INT_MAX;
        $b_curr = isset($b['currency_idx']) ? (int) $b['currency_idx'] : PHP_INT_MAX;

        if ($a_has) {
            // (2) оба percent — сравниваем best_percent, tie → валюта.
            $a_val = (float) $a['best_percent'];
            $b_val = (float) $b['best_percent'];
        } else {
            // (3) оба fix-only — сравниваем best_fix, tie → валюта.
            $a_val = (float) $a['best_fix'];
            $b_val = (float) $b['best_fix'];
        }

        // Epsilon-сравнение: payment_size приходит из DECIMAL-строк БД, но
        // currency-конвертация / округление могут дать дрожание младшего
        // разряда — строгий === тогда молча ломал бы currency tie-break.
        if (abs($a_val - $b_val) < 1e-9) {
            return $a_curr < $b_curr;
        }
        return $a_val > $b_val;
    }

    /**
     * LEGACY (не для выбора preferred — см. rank_product()/is_better()).
     *
     * Score продукта = max(payment_size) среди активных тарифов БЕЗ учёта
     * tariff_type. Сравнение percent и fix как голых чисел давало неверный
     * preferred для mixed-групп; recompute_preferred() с Этапа 1 использует
     * rank_product(). Метод оставлен для backward-compat (внешние вызовы /
     * диагностика). Возвращает -1.0 если нет network_id/offer_id или тарифов.
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
            // Гонка параллельного импорта: конкурент уже вставил тот же
            // domain, наш INSERT упал по UNIQUE(domain). НЕ возвращаем 0
            // (это пропустило бы attach_member/recompute на цикл и сломало
            // дедуп) — перечитываем фактический id (Codex F-iter-41-002).
            $raced = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM %i WHERE domain = %s LIMIT 1',
                $table,
                $domain
            ));
            if (is_numeric($raced) && (int) $raced > 0) {
                return (int) $raced;
            }
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
     * Defense-in-depth: INNER JOIN с wp_posts фильтрует ghost product_id —
     * записи о products, которые были удалены из wp_posts (например, через
     * Cashback_API_Client::check_campaign_statuses) или ушли в trash.
     * Без JOIN'а downstream-логика (preferred resolver, catalog visibility,
     * admin UI) оперировала бы мёртвыми ID.
     *
     * @return array<int, int> product_ids
     */
    public static function get_active_members( int $group_id ): array {
        if ($group_id <= 0) {
            return array();
        }
        global $wpdb;

        $members_table = $wpdb->prefix . self::TABLE_MEMBERS;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only group lookup; кеш сбивается на attach/detach/recompute.
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.product_id FROM %i AS m
               INNER JOIN %i AS p ON p.ID = m.product_id
                                  AND p.post_type = %s
                                  AND p.post_status NOT IN ("trash", "auto-draft")
              WHERE m.group_id = %d AND m.is_excluded = 0
              ORDER BY m.product_id ASC',
            $members_table,
            $wpdb->posts,
            'product',
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
     * Только публично-видимые members (post_status='publish').
     *
     * Используется catalog-visibility fallback'ом (Codex Round 5): если
     * pick_fallback_member выбирал draft member из-за smaller product_id
     * либо manual override, sync_group скрывал published sibling, и WC
     * default-фильтр (post_status='publish') скрывал draft → guest catalog
     * пуст. Этот helper возвращает только members, которые WC реально
     * показал бы в каталоге.
     *
     * Отдельный метод от `get_active_members`, потому что recompute_preferred
     * и admin UI должны учитывать draft members (тарифы scoring'уются
     * независимо от статуса; админ видит pre-publish state).
     *
     * @return array<int, int> product_ids
     */
    public static function get_publishable_members( int $group_id ): array {
        if ($group_id <= 0) {
            return array();
        }
        global $wpdb;

        $members_table = $wpdb->prefix . self::TABLE_MEMBERS;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only group lookup; кеш сбивается на attach/detach/recompute.
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.product_id FROM %i AS m
               INNER JOIN %i AS p ON p.ID = m.product_id
                                  AND p.post_type = %s
                                  AND p.post_status = %s
              WHERE m.group_id = %d AND m.is_excluded = 0',
            $members_table,
            $wpdb->posts,
            'product',
            'publish',
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
     * Хук на permanent-delete WC product (before_delete_post).
     *
     * Чистит member-record, recompute_preferred оставшейся группы или
     * удаляет пустую группу. Без этого hook'а 1729 ghost-записей накапливались
     * в `cashback_shop_group_members` за жизнь плагина.
     *
     * НЕ навешен на wp_trash_post намеренно — trash в WP обратим через
     * «Восстановить», и destructive cleanup на trash приводил бы к потере
     * группы при routine admin-операции. Trashed members отфильтровываются
     * через INNER JOIN wp_posts в get_active_members (защищает все downstream
     * места: visibility, recompute, admin UI).
     *
     * @param int   $post_id ID удаляемого поста.
     * @param mixed $post    WP_Post; для совместимости с before_delete_post сигнатурой.
     */
    public static function on_before_delete_post( int $post_id, $post = null ): void {
        if ($post_id <= 0) {
            return;
        }
        if (! is_object($post)) {
            $post = function_exists('get_post') ? get_post($post_id) : null;
            if (! is_object($post)) {
                return;
            }
        }
        $post_type = (string) ($post->post_type ?? '');
        if ($post_type !== 'product') {
            return;
        }
        self::detach_member($post_id);
    }

    /**
     * Listener на cashback_tariffs_changed.
     *
     * Закрывает race-condition в shop-importer: reconcile_for_product вызывает
     * recompute_preferred ДО синка тарифов (score=-1, preferred=NULL), а после
     * tariff sync action не триггерит пересчёт. Без этого listener'а group
     * preferred остаётся NULL до следующего цикла импорта (~24h), и админ-UI
     * показывает «Нет тарифов» бейдж даже когда тарифы реально есть.
     *
     * @param int $product_id ID товара чьи тарифы изменились.
     */
    public static function on_tariffs_changed( $product_id ): void {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return;
        }
        $group = self::get_group_for_product($product_id);
        if ($group === null) {
            return;
        }
        $group_id = (int) ($group['id'] ?? 0);
        if ($group_id <= 0) {
            return;
        }
        self::recompute_preferred($group_id);
    }

    /**
     * Удалить member-row и recompute preferred / удалить пустую группу.
     * Идемпотентно — безопасно вызывать на product без группы.
     */
    public static function detach_member( int $product_id ): void {
        if ($product_id <= 0) {
            return;
        }

        global $wpdb;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var') || ! method_exists($wpdb, 'delete')) {
            return;
        }

        $members_table = $wpdb->prefix . self::TABLE_MEMBERS;
        $groups_table  = $wpdb->prefix . self::TABLE_GROUPS;

        // Найти group_id перед удалением + текущий pin.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row lookup перед delete.
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT m.group_id, g.pin_product_id
               FROM %i AS m
               LEFT JOIN %i AS g ON g.id = m.group_id
              WHERE m.product_id = %d
              LIMIT 1',
            $members_table,
            $groups_table,
            $product_id
        ), ARRAY_A);
        $group_id = is_array($row) ? (int) ($row['group_id'] ?? 0) : 0;
        if ($group_id <= 0) {
            return;
        }

        // Если удаляемый product был pinned — очистить pin перед recompute,
        // иначе preferred остался бы указывать на мёртвый ID (Codex finding #1).
        $pin_id = is_array($row) ? (int) ($row['pin_product_id'] ?? 0) : 0;
        if ($pin_id === $product_id) {
            self::clear_pin($group_id);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup на before_delete_post.
        $wpdb->delete($members_table, array( 'product_id' => $product_id ), array( '%d' ));

        // Если группа опустела — удалить.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Post-delete count для решения "пуста ли группа".
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE group_id = %d AND is_excluded = 0',
            $members_table,
            $group_id
        ));
        if ($remaining === 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Удаление пустой группы.
            $wpdb->delete($groups_table, array( 'id' => $group_id ), array( '%d' ));
            return;
        }

        // Иначе — пересчитать preferred (фирит cashback_group_preferred_changed).
        self::recompute_preferred($group_id);
    }

    /**
     * Очистить pin_product_id группы (NULL). Используется при detach
     * pinned-member и при stale-pin авто-cleanup в recompute_preferred.
     * Не фирит cashback_group_preferred_changed — caller сам делает
     * write_preferred с новым значением.
     */
    private static function clear_pin( int $group_id ): void {
        if ($group_id <= 0) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_GROUPS;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Stale-pin cleanup, single-row UPDATE.
        $wpdb->update(
            $table,
            array( 'pin_product_id' => null ),
            array( 'id' => $group_id ),
            array( '%s' ),
            array( '%d' )
        );
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

    /**
     * One-shot backfill для групп с preferred_product_id IS NULL — race
     * condition в импортере (recompute вызывался ДО tariff sync) оставлял
     * preferred=NULL на v12-данных. Hook на cashback_tariffs_changed закрывает
     * race для будущих синков, но existing-данные требуют one-shot recompute.
     *
     * Идемпотентный self-healing pattern (как Cashback_Product_Sort::ensure_backfilled):
     * шедулится через wp-cron, выполняется в handle_preferred_backfill_cron
     * batch'ами по PREFERRED_BACKFILL_BATCH. Терминальное состояние —
     * только 'done' (когда WHERE preferred_product_id IS NULL вернул 0 rows).
     * Любое другое значение опции (включая 'scheduled') считается not-done,
     * и self-healing перепланирует событие если оно drop'нулось.
     */
    public static function ensure_preferred_backfilled(): void {
        if (! function_exists('get_option') || ! function_exists('update_option')) {
            return;
        }
        $current = (string) get_option(self::PREFERRED_BACKFILL_OPTION, '');
        if ($current === 'done') {
            return;
        }
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
            if (wp_next_scheduled(self::PREFERRED_BACKFILL_CRON_HOOK)) {
                return;
            }
            // partial → unresolvable группы (нет тарифов ни у одного member).
            // Back-off 1ч чтобы не молотить cron между tariff sync'ами;
            // как только тарифы появятся, on_tariffs_changed дёрнет recompute
            // и обновит preferred естественным путём.
            $delay = ($current === 'partial') ? 3600 : 30;
            wp_schedule_single_event(time() + $delay, self::PREFERRED_BACKFILL_CRON_HOOK);
            if ($current !== 'partial') {
                update_option(self::PREFERRED_BACKFILL_OPTION, 'scheduled', false);
            }
            return;
        }
        // Fallback (CLI / окружение без wp-cron функций).
        self::handle_preferred_backfill_cron();
    }

    /**
     * wp-cron handler для backfill. Resumable: за один прогон обрабатывает
     * до PREFERRED_BACKFILL_BATCH групп с preferred IS NULL. Если после batch'а
     * осталось ещё >0 — перепланирует следующее событие. Когда все группы
     * пересчитаны → state = 'done', cron больше не планируется.
     *
     * Failure handling (Codex finding):
     *   - SELECT failure (false/null) НЕ трактуется как «нет rows». Schedule
     *     retry с increased delay, состояние остаётся retryable.
     *   - recompute exception на одной группе → прерываем batch, НЕ продвигаем
     *     cursor дальше failed-группы. Следующий cron возьмёт ту же группу.
     *     recompute_preferred идемпотентен — повторный прогон безопасен.
     *
     * Resumability обеспечивается тем, что recompute_preferred() пишет
     * preferred_product_id (или 0 если score=-1 для всех members) → SELECT
     * WHERE IS NULL на следующей итерации не вернёт уже обработанные.
     *
     * Финализация (Codex adversarial finding):
     *   - 'done' пишется только если total-COUNT NULL-групп = 0 (все resolvable
     *     группы получили preferred);
     *   - 'partial' пишется когда после прохода остались no-tariff группы
     *     (recompute оставил preferred=NULL). 'partial' трактуется как
     *     non-terminal в ensure_preferred_backfilled, поэтому при следующей
     *     загрузке cron перепланируется с back-off 1h. Когда тарифы появятся
     *     (через api-sync), on_tariffs_changed вызовет recompute напрямую
     *     и preferred установится без ожидания cron.
     */
    public static function handle_preferred_backfill_cron(): void {
        global $wpdb;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_col')) {
            return;
        }

        $cursor_option = self::PREFERRED_BACKFILL_OPTION . '_cursor';
        $cursor        = function_exists('get_option') ? (int) get_option($cursor_option, 0) : 0;
        $groups_table  = $wpdb->prefix . self::TABLE_GROUPS;
        $batch         = self::PREFERRED_BACKFILL_BATCH;

        // Codex Round 4 (high): $wpdb->last_error sticky между запросами разных
        // плагинов/тем. Без явного сброса унаследованная чужая ошибка превращает
        // успешный SELECT в ложно-failed → cron retry'ится навсегда. Сбрасываем
        // ДО get_col(), чтобы детектировать именно нашу ошибку.
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Resumable backfill SELECT.
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM %i
              WHERE preferred_product_id IS NULL AND id > %d
              ORDER BY id ASC
              LIMIT %d',
            $groups_table,
            $cursor,
            $batch
        ));

        // Различаем query-failure от empty-result. wpdb->get_col на ошибке
        // возвращает пустой массив И выставляет $wpdb->last_error. Empty
        // результат при успехе — пустой массив + last_error пустой.
        $db_error = '';
        if (property_exists($wpdb, 'last_error')) {
            $db_error = (string) $wpdb->last_error;
        }
        if (! is_array($ids) || $db_error !== '') {
            // SELECT упал → НЕ финализируем, оставляем retryable. Реструктируем
            // через wp-cron с увеличенным delay (60s — backoff против тяжёлой
            // нагрузки на БД).
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback Group Resolver] preferred backfill SELECT failed: ' . $db_error);
            if (function_exists('update_option')) {
                update_option(self::PREFERRED_BACKFILL_OPTION, 'scheduled', false);
            }
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
                if (! wp_next_scheduled(self::PREFERRED_BACKFILL_CRON_HOOK)) {
                    wp_schedule_single_event(time() + 60, self::PREFERRED_BACKFILL_CRON_HOOK);
                }
            }
            return;
        }

        if (empty($ids)) {
            // Cursor-окно опустело — но это не значит, что вся таблица чиста:
            // recompute мог оставить preferred=NULL для no-tariff групп ниже
            // курсора. Делаем total-COUNT (без cursor) и решаем done vs partial.
            self::finalize_backfill_state($wpdb, $groups_table, $cursor_option);
            return;
        }

        $max_id          = $cursor;
        $batch_processed = 0;
        $batch_failed    = false;

        foreach ($ids as $gid) {
            $gid = (int) $gid;
            if ($gid <= 0) {
                continue;
            }
            try {
                self::recompute_preferred($gid);
            } catch (\Throwable $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                error_log('[Cashback Group Resolver] preferred backfill failed for group=' . $gid . ': ' . $e->getMessage());
                $batch_failed = true;
                // НЕ двигаем cursor дальше failed-группы — следующий cron
                // возьмёт её повторно (recompute идемпотентен).
                break;
            }
            if ($gid > $max_id) {
                $max_id = $gid;
            }
            ++$batch_processed;
        }

        // Cursor продвигается ТОЛЬКО для успешно обработанных групп (см. break
        // на exception выше). Это гарантирует retry для transient failures и
        // одновременно предотвращает infinite-loop на «нерешаемых» группах
        // (там recompute_preferred завершается без exception, пишет preferred=0).
        if (function_exists('update_option') && $max_id > $cursor) {
            update_option($cursor_option, $max_id, false);
        }

        // Если batch вернул меньше чем лимит И не было failure — больше
        // неисследованных групп нет, финализируем. При $batch_failed оставляем
        // retryable и планируем повтор.
        if (! $batch_failed && count($ids) < $batch) {
            self::finalize_backfill_state($wpdb, $groups_table, $cursor_option);
            return;
        }

        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
            $delay = $batch_failed ? 60 : 30; // backoff на failure.
            if (! wp_next_scheduled(self::PREFERRED_BACKFILL_CRON_HOOK)) {
                wp_schedule_single_event(time() + $delay, self::PREFERRED_BACKFILL_CRON_HOOK);
            }
        }
    }

    /**
     * Решить терминальный статус backfill после прохода: 'done' если все
     * группы получили preferred, иначе 'partial' (no-tariff группы остались).
     *
     * Codex adversarial findings:
     *   1) Раньше cron писал 'done' как только cursor-окно опустело, не
     *      проверяя total-COUNT. Группы где recompute оставил preferred=NULL
     *      (нет тарифов) застревали навсегда — ensure_preferred_backfilled
     *      считает 'done' terminal'ом и больше не планирует cron.
     *   2) Сам COUNT-запрос может упасть (deadlock, lost connection). (int)null
     *      = 0 → ветка 'done' срабатывала ложно при transient DB-error и
     *      перманентно замораживала backfill. Применяем ту же дисциплину
     *      last_error, что в основном handler'е: при failure статус остаётся
     *      'scheduled', cursor НЕ сбрасывается, следующий cron повторит.
     */
    private static function finalize_backfill_state(
        object $wpdb,
        string $groups_table,
        string $cursor_option
    ): void {
        if (! function_exists('update_option')) {
            return;
        }
        if (! method_exists($wpdb, 'get_var') || ! method_exists($wpdb, 'prepare')) {
            // Окружение без полноценного wpdb — оставляем retryable, без записи
            // терминального статуса (тест-стаб без get_var тоже сюда попадёт).
            update_option(self::PREFERRED_BACKFILL_OPTION, 'scheduled', false);
            return;
        }

        // Сбрасываем last_error до запроса, чтобы детектировать именно эту
        // ошибку, а не унаследованную от предыдущего вызова.
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Total остатков NULL для решения done vs partial.
        $raw = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE preferred_product_id IS NULL',
            $groups_table
        ));

        $db_error = property_exists($wpdb, 'last_error')
            ? (string) $wpdb->last_error
            : '';
        if ($raw === null || $raw === false || $db_error !== '') {
            // COUNT failed → НЕЛЬЗЯ интерпретировать (int)null как «0 NULL-групп».
            // Оставляем retryable, cursor НЕ сбрасываем — следующий cron повторит
            // total-COUNT и решит done vs partial по реальному состоянию.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback Group Resolver] preferred backfill final COUNT failed: ' . $db_error);
            update_option(self::PREFERRED_BACKFILL_OPTION, 'scheduled', false);
            // Codex Round 7 (R7-2): зеркалим scheduling из main SELECT handler.
            // Без этого option='scheduled' остаётся, но cron event не создан —
            // backfill stalls до случайного page-load (ensure_preferred_backfilled).
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
                if (! wp_next_scheduled(self::PREFERRED_BACKFILL_CRON_HOOK)) {
                    wp_schedule_single_event(time() + 60, self::PREFERRED_BACKFILL_CRON_HOOK);
                }
            }
            return;
        }

        $remaining = (int) $raw;
        if ($remaining > 0) {
            update_option(self::PREFERRED_BACKFILL_OPTION, 'partial', false);
        } else {
            update_option(self::PREFERRED_BACKFILL_OPTION, 'done', false);
        }
        update_option($cursor_option, 0, false);
    }
}
