<?php
/**
 * Cashback_Product_Sort — сортировка каталога WooCommerce по размеру кэшбэка.
 *
 * Заменяет стандартные WC-опции `price` / `price-desc` на `cashback` /
 * `cashback-desc`. Сортирует по числовой meta `_cashback_sort_value`,
 * рассчитанной из тарифов через Cashback_Cashback_Display_Calculator
 * (или legacy `_cashback_display_value` parsed → float).
 *
 * Свойство монотонности: value = payment_size_max × guest_rate / 100,
 * умножение на одну константу для всех продуктов сохраняет порядок.
 * Поэтому сортируем по guest-варианту независимо от user_id —
 * порядок для авторизованного пользователя совпадает.
 *
 * Микс % и ₽: сравниваем по чистому числу, без нормализации.
 *
 * @package CashbackPlugin
 * @since   12.1.0
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cashback_Product_Sort {

    public const SORT_META_KEY     = '_cashback_sort_value';
    public const ORDERBY_ASC       = 'cashback';
    public const ORDERBY_DESC      = 'cashback-desc';
    public const BACKFILL_OPTION   = 'cashback_product_sort_backfill_v1';
    public const CRON_BACKFILL_HOOK = 'cashback_product_sort_backfill';

    /**
     * Регистрация фильтров. Идемпотентно — внутренний static guard.
     */
    public static function register(): void {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        if (function_exists('add_filter')) {
            add_filter('woocommerce_catalog_orderby', array( __CLASS__, 'filter_orderby_options' ), 20, 1);
            add_filter('woocommerce_get_catalog_ordering_args', array( __CLASS__, 'filter_ordering_args' ), 20, 3);
            add_filter('woocommerce_default_catalog_orderby', array( __CLASS__, 'filter_default_catalog_orderby' ), 20, 1);
        }
        if (function_exists('add_action')) {
            // Tariff sync: пересчёт meta при изменении тарифов.
            // Приоритет 20 — после nginx purger (10), не блокируем.
            add_action('cashback_tariffs_changed', array( __CLASS__, 'recompute_for_product' ), 20, 1);
            // Deferred one-shot backfill (см. ensure_backfilled).
            add_action(self::CRON_BACKFILL_HOOK, array( __CLASS__, 'handle_backfill_cron' ), 10, 0);
        }
    }

    /**
     * Фильтр выпадающего списка сортировки каталога.
     *
     * Снимает price/price-desc, добавляет cashback-desc/cashback на их место.
     *
     * @param array<string, string> $options
     * @return array<string, string>
     */
    public static function filter_orderby_options( $options ): array {
        if (! is_array($options)) {
            return array();
        }
        unset($options['price']);
        unset($options['price-desc']);

        $options[ self::ORDERBY_DESC ] = __('По убыванию кэшбэка', 'cashback-plugin');
        $options[ self::ORDERBY_ASC ]  = __('По возрастанию кэшбэка', 'cashback-plugin');
        return $options;
    }

    /**
     * Фильтр query-args для каталога. Если выбран cashback / cashback-desc —
     * подменяем orderby на meta_value_num по нашему meta.
     *
     * @param array<string, mixed> $args
     * @param string|null          $orderby_value Передаётся WC начиная с 3.0.
     * @param string|null          $order_value   Дополнительный аргумент WC; не используется (берём из orderby).
     * @return array<string, mixed>
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $order_value диктуется сигнатурой WC-фильтра woocommerce_get_catalog_ordering_args (3 args начиная с WC 3.0).
    public static function filter_ordering_args( $args, $orderby_value = null, $order_value = null ): array {
        if (! is_array($args)) {
            $args = array();
        }
        $orderby = self::resolve_orderby_param($orderby_value);
        if ($orderby !== self::ORDERBY_ASC && $orderby !== self::ORDERBY_DESC) {
            return $args;
        }

        $args['orderby']  = 'meta_value_num';
        $args['order']    = ( $orderby === self::ORDERBY_DESC ) ? 'DESC' : 'ASC';
        $args['meta_key'] = self::SORT_META_KEY; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- catalog sort by guest cashback value (см. plan).
        return $args;
    }

    /**
     * Защита от устаревшего значения опции woocommerce_default_catalog_orderby:
     * если админ ранее выбрал "По возрастанию цены" / "По убыванию цены" как
     * дефолт каталога, теперь там будет невалидный ключ — мапим на menu_order.
     *
     * @param mixed $value
     */
    public static function filter_default_catalog_orderby( $value ): string {
        $value = is_string($value) ? $value : '';
        if ($value === 'price' || $value === 'price-desc') {
            return 'menu_order';
        }
        return $value;
    }

    /**
     * Пересчитать `_cashback_sort_value` для одного product_id.
     *
     * Идемпотентно. Вызывается на cashback_tariffs_changed action и из
     * save_meta_box при сохранении ставок в админке.
     */
    public static function recompute_for_product( $product_id ): void {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return;
        }

        $value = self::compute_value_for_product($product_id);

        // Сохраняем как строку с фиксированной точностью — meta_value_num
        // парсит число из строки независимо от формата.
        $stored = self::format_for_storage($value);
        if (function_exists('update_post_meta')) {
            update_post_meta($product_id, self::SORT_META_KEY, $stored);
        }
    }

    /**
     * Расчёт sort-значения для одного product_id.
     *
     * Порядок:
     *   1. Cashback_Cashback_Display_Calculator::compute_sort_value (тарифы / manual override)
     *   2. legacy `_cashback_display_value` → parse_legacy_value
     *   3. 0.0 (товар без кэшбэка → в конец списка)
     */
    public static function compute_value_for_product( int $product_id ): float {
        if ($product_id <= 0) {
            return 0.0;
        }

        if (class_exists('Cashback_Cashback_Display_Calculator')) {
            $value = Cashback_Cashback_Display_Calculator::compute_sort_value($product_id);
            if ($value > 0.0) {
                return $value;
            }
        }

        // Legacy fallback: _cashback_display_value (ручной ввод в метабоксе).
        if (function_exists('get_post_meta')) {
            $legacy = (string) get_post_meta($product_id, '_cashback_display_value', true);
            if ($legacy !== '') {
                return self::parse_legacy_value($legacy);
            }
        }

        return 0.0;
    }

    /**
     * Извлечь первое число из произвольной строки display-значения.
     *
     * Примеры: "6,5%" → 6.5, "до 65 ₽" → 65, "до 12.5%" → 12.5,
     * "" → 0, "abc" → 0.
     */
    public static function parse_legacy_value( string $raw ): float {
        $raw = trim($raw);
        if ($raw === '') {
            return 0.0;
        }
        if (! preg_match('/(\d+(?:[.,]\d+)?)/u', $raw, $m)) {
            return 0.0;
        }
        $num = str_replace(',', '.', $m[1]);
        return (float) $num;
    }

    /**
     * One-shot backfill для всех external-товаров. Идемпотентно: повторный
     * прогон обновит значения, что безопасно (тариф не менялся → значение
     * не меняется).
     *
     * Вызывается из cashback-plugin.php при первом init после релиза
     * (защищено BACKFILL_OPTION). Также можно вызвать вручную из wp eval.
     *
     * @return int Количество обработанных продуктов.
     */
    public static function backfill_all(): int {
        if (! function_exists('get_posts')) {
            return 0;
        }

        $processed = 0;
        $offset    = 0;
        $batch     = 200;

        do {
            $ids = get_posts(array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
                'posts_per_page' => $batch,
                'offset'         => $offset,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ));
            if (empty($ids) || ! is_array($ids)) {
                break;
            }
            $count = count($ids);
            foreach ($ids as $pid) {
                self::recompute_for_product((int) $pid);
                ++$processed;
            }
            $offset += $batch;
        } while ($count === $batch);

        return $processed;
    }

    /**
     * Гейт для one-shot backfill при апгрейде плагина. Идемпотентно.
     * Можно безопасно вызывать на каждом init.
     *
     * Self-healing state machine: терминальное состояние — только '1'
     * (handle_backfill_cron реально отработал и записал backfill). Если
     * опция в любом другом значении (включая 'scheduled' от прошлого init),
     * проверяем актуальное состояние очереди wp-cron через wp_next_scheduled.
     * Если событие drop'нулось (DISABLE_WP_CRON, CrowdSec, reactivation
     * или ручная очистка очереди) — перепланируем.
     *
     * Чтобы не блокировать первый user-request после деплоя на ~7-15s
     * (1-2k продуктов × tariff lookup), backfill откладывается на wp-cron
     * (single event). Обработчик handle_backfill_cron вызовется при
     * следующем тике.
     */
    public static function ensure_backfilled(): void {
        if (! function_exists('get_option') || ! function_exists('update_option')) {
            return;
        }
        // Терминальное состояние — только '1'. Любое другое (включая
        // 'scheduled') считается not-done, чтобы self-heal пропавшее событие.
        if ((string) get_option(self::BACKFILL_OPTION, '') === '1') {
            return;
        }
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
            if (wp_next_scheduled(self::CRON_BACKFILL_HOOK)) {
                // Событие уже в очереди — ждём, ничего не делаем.
                return;
            }
            wp_schedule_single_event(time() + 30, self::CRON_BACKFILL_HOOK);
            update_option(self::BACKFILL_OPTION, 'scheduled', false);
            return;
        }
        // Fallback (CLI / тестовое окружение без wp-cron функций).
        self::handle_backfill_cron();
    }

    /**
     * wp-cron handler для one-shot backfill.
     */
    public static function handle_backfill_cron(): void {
        try {
            self::backfill_all();
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback Product Sort] backfill failed: ' . $e->getMessage());
            return;
        }
        if (function_exists('update_option')) {
            update_option(self::BACKFILL_OPTION, '1', false);
        }
    }

    /**
     * Достать активный orderby-параметр из аргументов / $_GET.
     *
     * WC core сам резолвит orderby из $_GET и `woocommerce_default_catalog_orderby`
     * опции до вызова filter_ordering_args, поэтому отдельный WC session lookup
     * здесь излишен (плюс PHPStan ругается на typed-non-nullable WC->session).
     */
    private static function resolve_orderby_param( $explicit ): string {
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only orderby selector каталога; не write-path.
        if (isset($_GET['orderby']) && is_string($_GET['orderby'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key + matching против whitelist ниже.
            return sanitize_key((string) $_GET['orderby']);
        }
        return '';
    }

    /**
     * Отформатировать float как фиксированную строку с 2 знаками — для
     * meta_value_num orderby (приведение к числу через CAST в MySQL).
     */
    private static function format_for_storage( float $value ): string {
        if ($value < 0.0) {
            $value = 0.0;
        }
        return number_format($value, 2, '.', '');
    }
}
