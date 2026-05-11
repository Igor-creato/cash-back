<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Раннее снятие $_GET['per_page'] для REST-запросов.
 *
 * Зачем: тема WoodMart (third-party) на любом запросе с $_GET['per_page']
 * шлёт `Set-Cookie: shop_per_page=<value>; path=/` (functions.php в WoodMart),
 * не разделяя frontend и REST. Расширение делает фоновые fetch на REST
 * (например `/wp-json/cashback/v1/me/transactions?per_page=5`) — в ответ
 * прилетает Set-Cookie, и WoodMart-storefront начинает рендерить только
 * 5 карточек на странице каталога магазинов.
 *
 * Фикс — снимаем $_GET['per_page'] для всех REST-запросов до того, как
 * WoodMart-хук успеет считать суперглобал. WP_REST_Request получает per_page
 * через WP_REST_Server (распарсенные args), не через $_GET — поэтому
 * контракт нашего REST не ломается.
 *
 * Функция выделена в отдельный файл и принимает $_GET по reference, чтобы
 * unit-тестировать в изоляции от глобального состояния.
 *
 * @param string|null $request_uri Текущий URI из $_SERVER['REQUEST_URI'].
 *                                  Null = безопасный default (frontend, не трогаем).
 * @param array<string,mixed> $get Ссылка на $_GET (или его копию в тестах).
 */
function cashback_apply_rest_per_page_shield( ?string $request_uri, array &$get ): void {
    if (!is_string($request_uri) || $request_uri === '') {
        return;
    }
    if (false === strpos($request_uri, '/wp-json/')) {
        return;
    }
    if (!array_key_exists('per_page', $get)) {
        return;
    }
    unset($get['per_page']);
}
