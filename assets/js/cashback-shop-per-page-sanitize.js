/**
 * Однократный санитизатор cookie `shop_per_page` на текущем origin.
 *
 * Контекст: тема WoodMart выставляет cookie `shop_per_page` на любой запрос
 * с $_GET['per_page'] (включая REST). Из-за бага в раннем браузерном
 * расширении (api.js: fetchTransactions(perPage = 5)) у части пользователей
 * накоплено `shop_per_page=5`, что выходит за разрешённый набор значений
 * тулбара WoodMart и форсит storefront в режим «5 карточек».
 *
 * Допустимые значения = admin-список WoodMart Theme Options ('9,12,18,24').
 * Если значение cookie не из этого набора — стираем его (одна frontend-
 * страница, один раз). При следующей загрузке WoodMart возьмёт fallback
 * к Theme Option, и сетка отрисуется ожидаемо.
 *
 * Защитное (не функциональное): после серверного REST-shield утечка
 * закрыта у источника. Этот скрипт чистит stale-cookies у тех, кто уже
 * пострадал до выкатки фикса.
 */
(function () {
    var ALLOWED = [9, 12, 18, 24];
    var match = document.cookie.match(/(?:^|;\s*)shop_per_page=(\d+)/);
    if (!match) {
        return;
    }
    var current = parseInt(match[1], 10);
    if (ALLOWED.indexOf(current) !== -1) {
        return;
    }
    document.cookie = 'shop_per_page=; Max-Age=0; path=/';
    document.cookie = 'shop_per_page=; Max-Age=0; path=/; domain=' + location.hostname;
})();
