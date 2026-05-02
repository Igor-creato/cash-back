<?php

/**
 * Контракт адаптера купонов CPA-сети.
 *
 * Generic JSON адаптер реализует это для большинства сетей через admin-конфиг.
 * Code-адаптеры (escape hatch для XML/CSV/non-standard) тоже реализуют этот
 * интерфейс и регистрируются с приоритетом выше generic.
 *
 * @package CashbackPlugin
 * @since   7.2.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

interface Cashback_Coupons_Adapter_Interface {

    /**
     * Slug сети (например, 'admitad', 'cityads').
     */
    public function get_network_slug(): string;

    /**
     * Загрузить купоны кампании из API сети.
     *
     * @param string             $advcampaign_id  ID кампании в сети (offer_id).
     * @param array<string,mixed> $context        Дополнительный контекст (limits, дебаг).
     * @return Cashback_Coupon_DTO[] Массив DTO. Пустой при ошибке (soft-fail) — адаптер пишет в audit-log.
     */
    public function fetch_coupons( string $advcampaign_id, array $context = array() ): array;

    /**
     * Поддерживает ли API сети фильтрацию по advcampaign_id (server-side)?
     * Если false — fetcher должен фильтровать на клиенте (загружая больше данных).
     */
    public function supports_campaign_filter(): bool;

    /**
     * OAuth2 scope, требуемый для coupons API. Возвращает null для не-OAuth сетей.
     * Используется для admin-warning, если scope не настроен.
     */
    public function get_required_scope(): ?string;
}
