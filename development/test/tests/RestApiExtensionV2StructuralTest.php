<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурный тест: REST API v1 правки для browser-extension popup'а.
 *
 * Покрывает три фичи (план vast-pebble):
 *  - A1: /stores отдаёт cashback_value через Cashback_Cashback_Display_Calculator
 *        (формат «до X%» / «X%» — matches карточку товара), кэш-ключ bumped до _v2;
 *  - A2: /me возвращает поле account_url (URL личного кабинета);
 *  - A3: GET /promocodes — новый public-endpoint, формат plain DTO, кэш с
 *        version-based ключом, инвалидация через cashback_promocodes_upserted_after_cron.
 *
 * Source-based regression: защита от случайного удаления/деградации этих
 * полей при будущих рефакторингах. Полная функциональность проверяется
 * E2E на staging (см. plan-файл verification section).
 */
#[Group('rest-api')]
#[Group('browser-extension')]
final class RestApiExtensionV2StructuralTest extends TestCase
{
    private function rest_api_source(): string
    {
        $path = dirname(__DIR__, 3) . '/includes/class-cashback-rest-api.php';
        $c    = file_get_contents($path);
        $this->assertIsString($c, 'includes/class-cashback-rest-api.php must be readable');
        return $c;
    }

    private function fetcher_source(): string
    {
        $path = dirname(__DIR__, 3) . '/includes/promocodes/class-cashback-promocodes-fetcher.php';
        $c    = file_get_contents($path);
        $this->assertIsString($c, 'class-cashback-promocodes-fetcher.php must be readable');
        return $c;
    }

    // ─── A1: /stores cashback_value via Display_Calculator ───

    public function test_stores_cache_key_bumped_to_v2(): void
    {
        $src = $this->rest_api_source();

        $this->assertMatchesRegularExpression(
            '/STORES_CACHE_KEY\s*=\s*[\'"]cashback_ext_stores_cache_v2[\'"]/',
            $src,
            'STORES_CACHE_KEY должен быть bumped до _v2 для инвалидации старых entries после перехода на Display_Calculator'
        );
    }

    public function test_get_stores_uses_display_calculator_for_cashback_value(): void
    {
        $src = $this->rest_api_source();

        $this->assertMatchesRegularExpression(
            '/Cashback_Cashback_Display_Calculator::compute\s*\(\s*\(int\)\s*\$product\[[\'"]ID[\'"]\]\s*,\s*0\s*\)/',
            $src,
            'get_stores должен вызывать Cashback_Cashback_Display_Calculator::compute($product["ID"], 0) для guest-rate расчёта'
        );
    }

    public function test_get_stores_falls_back_to_legacy_value_when_calculator_empty(): void
    {
        $src = $this->rest_api_source();

        $this->assertMatchesRegularExpression(
            '/\$cashback_value\s*===\s*[\'"][\'"]\s*\)\s*\{\s*\$cashback_value\s*=\s*\(string\)\s*\(\s*\$product\[[\'"]cashback_value[\'"]\]/s',
            $src,
            'Должен быть legacy fallback на $product["cashback_value"] (post_meta _cashback_display_value) когда compute() возвращает пусто'
        );
    }

    // ─── A2: /me account_url ───

    public function test_get_me_returns_account_url_field(): void
    {
        $src = $this->rest_api_source();

        $this->assertMatchesRegularExpression(
            '/[\'"]account_url[\'"]\s*=>\s*\$account_url/',
            $src,
            '/me response должен содержать поле account_url'
        );
    }

    public function test_get_me_prefers_wc_get_page_permalink_for_account_url(): void
    {
        $src = $this->rest_api_source();

        $this->assertMatchesRegularExpression(
            '/wc_get_page_permalink\s*\(\s*[\'"]myaccount[\'"]\s*\)/',
            $src,
            'account_url должен резолвиться через wc_get_page_permalink("myaccount") (WC-настроенная страница)'
        );

        $this->assertMatchesRegularExpression(
            '/home_url\s*\(\s*[\'"]\/my-account\/[\'"]\s*\)/',
            $src,
            'account_url должен иметь home_url("/my-account/") fallback'
        );
    }

    // ─── A3: /promocodes endpoint ───

    public function test_promocodes_route_registered(): void
    {
        $src = $this->rest_api_source();

        $this->assertMatchesRegularExpression(
            '/register_rest_route\s*\(\s*self::NAMESPACE\s*,\s*[\'"]\/promocodes[\'"]/',
            $src,
            'Должен быть зарегистрирован route /promocodes в namespace cashback/v1'
        );

        $this->assertMatchesRegularExpression(
            '/register_rest_route\s*\(\s*self::NAMESPACE\s*,\s*[\'"]\/promocodes[\'"]\s*,\s*array\s*\(\s*[\'"]methods[\'"]\s*=>\s*[\'"]GET[\'"]/s',
            $src,
            '/promocodes должен быть GET'
        );
    }

    public function test_promocodes_endpoint_is_public(): void
    {
        $src = $this->rest_api_source();

        // Регистрация /promocodes должна содержать __return_true как permission
        $this->assertSame(
            1,
            preg_match(
                '/register_rest_route\s*\(\s*self::NAMESPACE\s*,\s*[\'"]\/promocodes[\'"][\s\S]*?[\'"]permission_callback[\'"]\s*=>\s*[\'"]__return_true[\'"]/',
                $src
            ),
            '/promocodes должен быть public (permission_callback => __return_true), как /stores'
        );
    }

    public function test_promocodes_validates_product_id_param(): void
    {
        $src = $this->rest_api_source();

        // args.product_id required, integer, minimum 1
        $this->assertSame(
            1,
            preg_match(
                '/register_rest_route\s*\(\s*self::NAMESPACE\s*,\s*[\'"]\/promocodes[\'"][\s\S]*?[\'"]product_id[\'"]\s*=>\s*array\s*\([\s\S]*?[\'"]required[\'"]\s*=>\s*true/',
                $src
            ),
            '/promocodes args.product_id должен быть required'
        );
    }

    public function test_promocodes_uses_repository_get_active_for_campaign(): void
    {
        $src = $this->rest_api_source();

        $this->assertMatchesRegularExpression(
            '/new\s+Cashback_Promocodes_Repository\s*\(\s*\)/',
            $src,
            'get_promocodes должен инстанциировать Cashback_Promocodes_Repository'
        );

        $this->assertMatchesRegularExpression(
            '/->get_active_for_campaign\s*\(\s*\$network_id\s*,\s*\$advcampaign_id/',
            $src,
            'get_promocodes должен звать repository->get_active_for_campaign($network_id, $advcampaign_id, ...)'
        );
    }

    public function test_promocodes_redirect_url_uses_promo_click_handler(): void
    {
        $src = $this->rest_api_source();

        $this->assertMatchesRegularExpression(
            '/[\'"]cashback_promo_click[\'"]\s*=>\s*\$promo_id/',
            $src,
            'format_promocode_row должен строить redirect_url с query-param cashback_promo_click=ID (тот же поток, что Cashback_Promocodes_Shortcode)'
        );
    }

    public function test_promocodes_response_fields_present(): void
    {
        $src = $this->rest_api_source();

        $required_fields = array(
            'id', 'species', 'name', 'promocode', 'discount',
            'date_end', 'is_exclusive', 'redirect_url',
        );

        foreach ($required_fields as $field) {
            $this->assertMatchesRegularExpression(
                '/[\'"]' . preg_quote($field, '/') . '[\'"]\s*=>/',
                $src,
                "format_promocode_row должен возвращать поле '{$field}'"
            );
        }
    }

    public function test_promocodes_returns_404_when_product_not_indexed(): void
    {
        $src = $this->rest_api_source();

        $this->assertMatchesRegularExpression(
            '/[\'"]code[\'"]\s*=>\s*[\'"]product_not_indexed[\'"]/',
            $src,
            'get_promocodes должен возвращать code=product_not_indexed для продукта без _affiliate_network_id+_offer_id'
        );
    }

    public function test_promocodes_returns_400_when_invalid_product_id(): void
    {
        $src = $this->rest_api_source();

        $this->assertMatchesRegularExpression(
            '/[\'"]code[\'"]\s*=>\s*[\'"]invalid_product_id[\'"]/',
            $src,
            'get_promocodes должен возвращать code=invalid_product_id при product_id <= 0'
        );
    }

    public function test_promocodes_uses_versioned_cache_key(): void
    {
        $src = $this->rest_api_source();

        // Ключ должен включать версию из option
        $this->assertMatchesRegularExpression(
            '/get_option\s*\(\s*self::PROMOCODES_CACHE_VERSION_OPT/',
            $src,
            'promocodes_cache_key должен читать generation counter из option (O(1) инвалидация)'
        );

        $this->assertMatchesRegularExpression(
            '/self::PROMOCODES_CACHE_PREFIX\s*\.\s*[\'"]v[\'"]\s*\.\s*\$version/',
            $src,
            'Cache key должен иметь формат PREFIX + "v" + $version + "_" + product_id'
        );
    }

    // ─── Cache invalidation ───

    public function test_flush_stores_cache_also_bumps_promocodes_version(): void
    {
        $src = $this->rest_api_source();

        // Метод flush_stores_cache должен вызывать bump_promocodes_cache_version
        $this->assertSame(
            1,
            preg_match(
                '/function\s+flush_stores_cache\s*\([^)]*\)\s*:\s*void\s*\{\s*'
                . 'delete_transient\s*\(\s*self::STORES_CACHE_KEY\s*\)\s*;\s*'
                . '\$this->bump_promocodes_cache_version\s*\(\s*\)\s*;/s',
                $src
            ),
            'flush_stores_cache должен также bumpить версию промокодов (после save_post могла измениться связка _affiliate_network_id/_offer_id)'
        );
    }

    public function test_cron_upsert_hook_bumps_promocodes_cache(): void
    {
        $src = $this->rest_api_source();

        $this->assertMatchesRegularExpression(
            '/add_action\s*\(\s*[\'"]cashback_promocodes_upserted_after_cron[\'"]\s*,\s*array\s*\(\s*\$this\s*,\s*[\'"]bump_promocodes_cache_version[\'"]/',
            $src,
            'REST API должен слушать cashback_promocodes_upserted_after_cron для bump кэша промокодов'
        );
    }

    public function test_fetcher_fires_upserted_action_on_cron(): void
    {
        $src = $this->fetcher_source();

        $this->assertMatchesRegularExpression(
            '/do_action\s*\(\s*[\'"]cashback_promocodes_upserted_after_cron[\'"]/',
            $src,
            'Cashback_Promocodes_Fetcher должен эмитить do_action("cashback_promocodes_upserted_after_cron", $network_id) после успешного upsert'
        );
    }
}
