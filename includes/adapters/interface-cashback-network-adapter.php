<?php

/**
 * Интерфейс адаптера CPA-сети
 *
 * Каждая CPA-сеть (Admitad, EPN и др.) реализует этот интерфейс.
 * Новая сеть = новый класс-адаптер без изменения ядра API-клиента.
 *
 * @package CashbackPlugin
 * @since   7.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

interface Cashback_Network_Adapter_Interface {

    /**
     * Slug сети, которую обслуживает адаптер (например, 'admitad', 'epn')
     */
    public function get_slug(): string;

    /**
     * Альтернативные slug'и, под которыми адаптер тоже регистрируется
     *
     * Позволяет адаптеру обслуживать сети с укороченными/альтернативными slug в БД.
     *
     * @return string[]
     */
    public function get_aliases(): array;

    /**
     * Получить OAuth2/API токен
     *
     * @param array $credentials   Расшифрованные credentials
     * @param array $network_config Строка из cashback_affiliate_networks
     * @return string|null Токен или null при ошибке
     */
    public function get_token( array $credentials, array $network_config ): ?string;

    /**
     * Сформировать заголовки авторизации для API-запросов
     *
     * @param array $credentials   Расшифрованные credentials
     * @param array $network_config Конфигурация сети
     * @return array|null Массив заголовков или null при ошибке
     */
    public function build_auth_headers( array $credentials, array $network_config ): ?array;

    /**
     * Получить ВСЕ действия из API с автоматической пагинацией
     *
     * Возвращает действия в нормализованном (Admitad-совместимом) формате
     * для совместимости с background_sync() и validate_user().
     *
     * @param array $credentials   Расшифрованные credentials
     * @param array $params        Параметры запроса (даты, фильтры, пагинация)
     * @param int   $max_pages     Максимальное количество страниц
     * @param array $network_config Конфигурация сети
     * @return array ['success' => bool, 'actions' => array, 'total' => int, 'error' => ?string]
     */
    public function fetch_all_actions( array $credentials, array $params, int $max_pages, array $network_config ): array;

    /**
     * Получить список кампаний/офферов с их статусами из API CPA-сети
     *
     * Возвращает список кампаний для определения активности магазинов.
     * Каждая кампания содержит:
     *   - 'id' (string) — ID кампании/оффера (advcampaign_id в Admitad, offer_id в EPN)
     *   - 'name' (string) — название кампании
     *   - 'is_active' (bool) — активна ли кампания
     *   - 'status' (string) — статус из API
     *   - 'connection_status' (string) — статус подключения (если есть)
     *
     * @param array $credentials    Расшифрованные credentials
     * @param array $network_config Строка из cashback_affiliate_networks
     * @return array ['success' => bool, 'campaigns' => array, 'error' => ?string]
     */
    public function fetch_campaigns( array $credentials, array $network_config ): array;

    /**
     * Получить детальную информацию о кампаниях для импорта-витрины (v12).
     *
     * Возвращает расширенный набор полей по сравнению с fetch_campaigns():
     * site_url, image, description, regions, categories, currency, goto_link.
     * Используется Cashback_Shop_Importer для создания/обновления WC external
     * products и привязки к группам по домену.
     *
     * Каждый элемент 'campaigns' содержит:
     *   - 'id' (string)             — campaign_id / advcampaign_id / offer_id
     *   - 'name' (string)           — название
     *   - 'site_url' (string)       — URL сайта рекламодателя (для дедупа по домену)
     *   - 'image_url' (string)      — URL логотипа
     *   - 'description' (string)
     *   - 'status_raw' (string)     — статус из API ('active'/'disabled'/...)
     *   - 'is_active' (bool)
     *   - 'regions' (array<string>) — ['RU','BY']
     *   - 'categories' (array<string>)
     *   - 'currency' (string)       — RUB/USD/EUR
     *   - 'goto_link' (string)      — прямая партнёрская ссылка
     *   - 'raw' (array)             — сырой payload для отладки/raw_payload
     *
     * @param array $credentials   Расшифрованные credentials
     * @param array $network_config Строка из cashback_affiliate_networks
     * @param int   $offset        offset (Admitad) или page*limit (EPN)
     * @param int   $limit         сколько вернуть за один вызов (default 100)
     * @return array ['success' => bool, 'campaigns' => array, 'has_next' => bool, 'next_offset' => int, 'error' => ?string]
     */
    public function fetch_campaigns_detailed( array $credentials, array $network_config, int $offset = 0, int $limit = 100 ): array;

    /**
     * Получить тарифы (actions/rates) одной кампании (v12).
     *
     * Каждый элемент 'tariffs' содержит:
     *   - 'tariff_id' (string)         — native ID тарифа в API сети
     *   - 'name' (string)              — название (например, 'Оплаченный заказ из категории 5')
     *   - 'tariff_type' (string)       — 'percent' | 'fix'
     *   - 'payment_size' (float)       — % или фиксированная сумма
     *   - 'payment_min' (?float)       — нижняя граница (опционально)
     *   - 'payment_max' (?float)       — верхняя граница (опционально)
     *   - 'currency' (string)          — RUB/USD/EUR
     *   - 'is_default' (bool)
     *   - 'raw' (array)                — сырой payload
     *
     * @param array  $credentials    Расшифрованные credentials
     * @param array  $network_config Строка из cashback_affiliate_networks
     * @param string $campaign_id    ID кампании (advcampaign_id / offer_id)
     * @return array ['success' => bool, 'tariffs' => array, 'error' => ?string]
     */
    public function fetch_shop_tariffs( array $credentials, array $network_config, string $campaign_id ): array;

    /**
     * Маппинг статусов API → локальные по умолчанию
     *
     * @return array<string, string> API status => local status (waiting/completed/declined)
     */
    public function get_default_status_map(): array;

    /**
     * Инвалидировать закешированный OAuth2/API токен
     *
     * Вызывается при обновлении credentials/scope, чтобы следующий запрос
     * получил новый токен с актуальными параметрами.
     *
     * @param array $credentials Расшифрованные credentials (для вычисления cache key)
     */
    public function invalidate_token( array $credentials ): void;

    /**
     * Последняя ошибка получения токена (для UI)
     */
    public function get_last_token_error(): string;
}
