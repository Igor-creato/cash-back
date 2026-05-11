<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cashback_Woodmart_Per_Page_Floor
 *
 * Defense-in-depth: поднимает minimum для woodmart_get_current_products_per_page()
 * до 9 (нижняя граница admin-списка 9/12/18/24). Без этого WoodMart-функция
 * принимает любое значение в диапазоне [-1, 500] из $_REQUEST['per_page'] /
 * $_COOKIE['shop_per_page'], что позволяло мусорным cookies (например, '5')
 * форсить storefront в режим «5 карточек на страницу».
 *
 * Цель — не «починить через перебивание», а отбросить значения, которые
 * заведомо не из допустимого набора. Если пользователь легитимно выберет
 * 9/12/18/24 через WoodMart-тулбар — фильтр пропускает выбор как есть.
 *
 * @see knowledge/debugging/woodmart-rest-per_page-leak.md
 * @since 4.1.0
 */
final class Cashback_Woodmart_Per_Page_Floor {

    /**
     * Нижняя граница admin-списка WoodMart Theme Options
     * («Shop products per page» → '9,12,18,24').
     *
     * @var int
     */
    private const MIN_ALLOWED = 9;

    /**
     * Регистрация фильтра. Идемпотентна — повторный вызов не создаёт дублей
     * (WP add_filter сам дедуплицирует по callable).
     */
    public static function init(): void {
        if (!function_exists('add_filter')) {
            return;
        }
        add_filter(
            'woodmart_get_min_per_page',
            array( self::class, 'apply_floor' ),
            99,
            1
        );
    }

    /**
     * Чистый расчёт floor — изолирован для unit-теста без apply_filters.
     *
     * @param int $min Текущее значение минимума (WoodMart дефолт = -1).
     * @return int Не ниже self::MIN_ALLOWED.
     */
    public static function floor( int $min ): int {
        return max($min, self::MIN_ALLOWED);
    }

    /**
     * Адаптер для filter-callback. WP отдаёт mixed, мы приводим к int.
     *
     * @param mixed $min Входящее значение фильтра.
     * @return int
     */
    public static function apply_floor( $min ): int {
        return self::floor((int) $min);
    }
}
