<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурный тест /me/transactions REST callback'а на поддержку двух
 * имён параметра пагинации:
 *  - `limit` (preferred): новое имя, не пересекается с WoodMart-хуком
 *    `setcookie('shop_per_page', $_GET['per_page'])` в теме.
 *  - `per_page` (legacy): сохраняем для backward-compat со старыми версиями
 *    расширения, которые передают per_page=5 (до bump'а api.js).
 *
 * При наличии обоих параметров `limit` имеет приоритет. Если ни один не передан
 * — используется константа TRANSACTIONS_PER_PAGE (= 10).
 *
 * Зачем тест: контракт REST не должен ломаться при будущем рефакторе.
 *
 * @see knowledge/debugging/woodmart-rest-per_page-leak.md
 */
#[Group('rest-api')]
#[Group('per-page')]
final class RestApiTransactionsLimitParamTest extends TestCase
{
    private function rest_api_source(): string
    {
        $path = dirname(__DIR__, 3) . '/includes/class-cashback-rest-api.php';
        $c    = file_get_contents($path);
        self::assertIsString($c, 'includes/class-cashback-rest-api.php must be readable');
        return $c;
    }

    public function test_route_args_define_limit_parameter(): void
    {
        $src = $this->rest_api_source();

        // 'limit' должен быть в args рядом с 'page' и 'per_page' (легаси).
        self::assertMatchesRegularExpression(
            '/[\'"]limit[\'"]\s*=>\s*array\s*\(/',
            $src,
            "register_rest_route для /me/transactions должен объявлять 'limit' в args"
        );
    }

    public function test_route_args_keep_per_page_for_backward_compat(): void
    {
        $src = $this->rest_api_source();

        self::assertMatchesRegularExpression(
            '/[\'"]per_page[\'"]\s*=>\s*array\s*\(/',
            $src,
            "register_rest_route должен сохранить legacy-параметр 'per_page' для backward-compat"
        );
    }

    public function test_limit_args_has_max_50(): void
    {
        $src = $this->rest_api_source();

        // В args для limit должно быть maximum 50 (как и у per_page) —
        // защита от запросов с заведомо чрезмерной нагрузкой.
        self::assertMatchesRegularExpression(
            '/[\'"]limit[\'"]\s*=>\s*array\s*\([^)]*[\'"]maximum[\'"]\s*=>\s*50/s',
            $src,
            "args[limit] должен включать maximum=50 для защиты от излишней нагрузки"
        );
    }

    public function test_callback_prefers_limit_over_per_page(): void
    {
        $src = $this->rest_api_source();

        // Callback должен читать limit и fall-back-ить на per_page.
        // Регэксп ищет null-coalesce-цепочку: get_param('limit') ?? get_param('per_page')
        self::assertMatchesRegularExpression(
            '/get_param\s*\(\s*[\'"]limit[\'"]\s*\)\s*\?\?\s*\$request->get_param\s*\(\s*[\'"]per_page[\'"]\s*\)/',
            $src,
            "get_transactions() должен читать limit и fall-back-ить на per_page через ?? (limit priority)"
        );
    }

    public function test_callback_fallbacks_to_default_constant(): void
    {
        $src = $this->rest_api_source();

        // Если ни limit, ни per_page не переданы — используем константу.
        self::assertMatchesRegularExpression(
            '/get_param\s*\(\s*[\'"]per_page[\'"]\s*\)\s*\?\?\s*self::TRANSACTIONS_PER_PAGE/',
            $src,
            "Финальный fallback должен быть self::TRANSACTIONS_PER_PAGE (= 10)"
        );
    }

    public function test_per_page_args_no_longer_have_default(): void
    {
        $src = $this->rest_api_source();

        // Извлекаем блок args для /me/transactions:
        // Найдём register_rest_route(...'/me/transactions'...) и его args-секцию.
        $matched = preg_match(
            '#register_rest_route\s*\(\s*self::NAMESPACE\s*,\s*[\'"]/me/transactions[\'"]\s*,\s*array\s*\((.+?)\)\s*\)\s*;#s',
            $src,
            $matches
        );
        self::assertSame(1, $matched, 'Должен найтись register_rest_route для /me/transactions');

        $route_def = (string) $matches[1];

        // Внутри найдём блок 'per_page' => array(...) — он не должен иметь default=10
        // (старая версия имела 'default' => self::TRANSACTIONS_PER_PAGE).
        // Это критично: иначе get_param('per_page') всегда возвращает 10,
        // и null-coalesce-цепочка с limit не сработает.
        $per_page_block_matched = preg_match(
            '/[\'"]per_page[\'"]\s*=>\s*array\s*\((.+?)\)/s',
            $route_def,
            $pp
        );
        self::assertSame(1, $per_page_block_matched);

        self::assertDoesNotMatchRegularExpression(
            '/[\'"]default[\'"]\s*=>/',
            (string) $pp[1],
            "args[per_page] больше не должен иметь default — иначе limit-fallback не сработает"
        );
    }
}
