<?php

/**
 * Cashback_Advcake_Coupons_Adapter — code-adapter для импорта Advcake-промокодов.
 *
 * Реализует Cashback_Coupons_Adapter_Interface (см. interface-coupons-adapter.php).
 * Регистрируется в Cashback_Coupons_Adapter_Registry через hook
 * `cashback_register_coupons_code_adapters` (см. bootstrap-advcake-coupons.php).
 *
 * Endpoint: GET https://api.advcake.ru/promocodes?pass={token}&type=json&limit=&offset=
 *
 * Реальный shape ответа (diagnostic 2026-05-14, staging):
 *   {success: true, total: int, data: [
 *      {id: 'pcXXXXX', name: '<сам промокод>', short_name: '<displayed title>',
 *       offer_id: int, offer_name: 'gb.ru', offer_url: '…',
 *       description, min_price, discount, discount_type,
 *       date_start: 'YYYY-MM-DD', date_end: 'YYYY-MM-DD',
 *       active: bool, referral_link: '<goto URL>', private: bool, status: 'active', …},
 *      …
 *   ]}
 *
 * Ключевые особенности маппинга:
 *   - `name` в payload — это сам **код промокода** ("geekpromo"), а `short_name` —
 *     отображаемое название → DTO.promocode = raw.name, DTO.name = raw.short_name.
 *   - `goto_link` ← raw.referral_link (не raw.link).
 *   - regions у /promocodes отсутствуют → пустой массив.
 *   - Server-side filter ?offer={id} НЕ работает (подтверждено diagnostic'ом) →
 *     supports_campaign_filter() возвращает false; fetch_coupons делает client-side
 *     filter по offer_id (после загрузки всех страниц).
 *
 * Soft-fail: на любую HTTP/JSON ошибку возвращает [], пишет в error_log. Fetcher
 * (Cashback_Promocodes_Fetcher) сам логирует «soft-fail per-network» и продолжает
 * с другими сетями.
 *
 * @package CashbackPlugin
 * @since   12.3.0
 */

declare(strict_types=1);

if (!defined('ABSPATH') && !defined('PHPUNIT_RUNNING')) {
    exit;
}

if (class_exists('Cashback_Advcake_Coupons_Adapter', false)) {
    return;
}

final class Cashback_Advcake_Coupons_Adapter implements Cashback_Coupons_Adapter_Interface {

    private const PROMOCODES_PATH  = '/promocodes';
    private const PAGE_LIMIT       = 100;
    private const MAX_PAGES        = 50;
    private const REQUEST_TIMEOUT  = 30;
    private const NETWORK_SLUG     = 'advcake';

    private Cashback_Advcake_Adapter $shared_adapter;
    private object $api_client;

    /**
     * @param Cashback_Advcake_Adapter $shared_adapter  Для get_token() (валидация api_key, расшифровка).
     * @param object                   $api_client      Cashback_API_Client (или stub в тестах) —
     *                                                  должен иметь get_credentials(int):?array и
     *                                                  get_network_config(string):?array.
     */
    public function __construct( Cashback_Advcake_Adapter $shared_adapter, object $api_client ) {
        $this->shared_adapter = $shared_adapter;
        $this->api_client     = $api_client;
    }

    public function get_network_slug(): string {
        return self::NETWORK_SLUG;
    }

    public function supports_campaign_filter(): bool {
        // /promocodes?offer={id} не фильтрует на стороне сервера — проверено staging-diagnostic'ом
        // 2026-05-14 (probe вернул все 4 промокода независимо от offer=403). Адаптер
        // делает client-side filter после fetch'а — fetcher грузит данные один раз
        // (loadall + filter), это не дорого, промокодов мало (4 на staging-аккаунт).
        return false;
    }

    public function get_required_scope(): ?string {
        // Advcake использует api_key (token-in-URL), не OAuth → scope не применим.
        return null;
    }

    /**
     * @param string             $advcampaign_id offer_id WC-продукта (например, '403' для gb.ru).
     * @param array<string,mixed> $context        Дополнительный контекст (не используется в v1).
     * @return Cashback_Coupon_DTO[]
     */
    public function fetch_coupons( string $advcampaign_id, array $context = array() ): array {
        $network_config = $this->api_client->get_network_config(self::NETWORK_SLUG);
        if (!is_array($network_config) || !isset($network_config['id'])) {
            return array();
        }
        $network_id  = (int) $network_config['id'];
        $credentials = $this->api_client->get_credentials($network_id);
        if (!is_array($credentials)) {
            return array();
        }
        $token = $this->shared_adapter->get_token($credentials, $network_config);
        if ($token === null || $token === '') {
            return array();
        }

        $base_url = rtrim((string) ( $network_config['api_base_url'] ?? 'https://api.advcake.ru' ), '/');

        $all_dtos = array();
        $offset   = 0;
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $page_result = $this->fetch_page($base_url, $token, $offset);
            if (!$page_result['success']) {
                // Soft-fail: останов pagination, отдаём всё что собрали.
                break;
            }
            $batch = $page_result['data'];
            foreach ($batch as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                if (!empty($raw['active']) === false && array_key_exists('active', $raw)) {
                    // Явная проверка: active=false / 0 / null → skip.
                    if (empty($raw['active'])) {
                        continue;
                    }
                }
                // Client-side filter по offer_id (server не фильтрует — см. supports_campaign_filter()).
                $offer_id = $raw['offer_id'] ?? null;
                if (!is_scalar($offer_id) || (string) $offer_id !== $advcampaign_id) {
                    continue;
                }

                try {
                    $dto = $this->build_dto($raw);
                } catch (\Throwable $e) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
                    error_log('[Cashback Advcake Coupons] skip promo ' . (string) ( $raw['id'] ?? 'no-id' ) . ': ' . $e->getMessage());
                    continue;
                }
                $all_dtos[] = $dto;
            }

            // Pagination stop: если страница неполная — больше данных нет.
            if (count($batch) < self::PAGE_LIMIT) {
                break;
            }
            $offset += self::PAGE_LIMIT;
        }

        return $all_dtos;
    }

    /**
     * Один HTTP-вызов /promocodes с одной страницей offset/limit.
     *
     * @return array{success:bool, data:array<int,mixed>, error:string}
     */
    private function fetch_page( string $base_url, string $token, int $offset ): array {
        $query = http_build_query(array(
            'pass'   => $token,
            'type'   => 'json',
            'limit'  => self::PAGE_LIMIT,
            'offset' => $offset,
        ));
        $url = $base_url . self::PROMOCODES_PATH . '?' . $query;

        $check = Cashback_Outbound_HTTP_Guard::validate_url($url);
        if (is_wp_error($check)) {
            return $this->page_error('outbound guard blocked: ' . $check->get_error_message());
        }

        $response = wp_remote_get($url, array(
            'timeout'            => self::REQUEST_TIMEOUT,
            'headers'            => array(),
            'sslverify'          => true,
            'reject_unsafe_urls' => true,
        ));
        if (is_wp_error($response)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback Advcake Coupons] HTTP error: ' . $response->get_error_message());
            return $this->page_error('wp_error: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log("[Cashback Advcake Coupons] HTTP {$code} on /promocodes");
            return $this->page_error("HTTP {$code}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback Advcake Coupons] malformed JSON in /promocodes response');
            return $this->page_error('malformed JSON');
        }
        if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
            $api_error = isset($decoded['error']) ? (string) $decoded['error'] : 'unknown';
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Plugin diagnostic.
            error_log('[Cashback Advcake Coupons] API success=false: ' . $api_error);
            return $this->page_error('API success=false: ' . $api_error);
        }

        $data = $decoded['data'] ?? array();
        if (!is_array($data)) {
            $data = array();
        }
        return array(
            'success' => true,
            'data'    => $data,
            'error'   => '',
        );
    }

    /** @return array{success:bool, data:array<int,mixed>, error:string} */
    private function page_error( string $error ): array {
        return array(
            'success' => false,
            'data'    => array(),
            'error'   => $error,
        );
    }

    /**
     * Преобразовать raw-promocode Advcake в Cashback_Coupon_DTO.
     *
     * Маппинг (подтверждён diagnostic'ом v4.3.3):
     *   - raw.id            → DTO.external_id
     *   - raw.name          → DTO.promocode (это **сам код**, не отображаемое имя)
     *   - raw.short_name    → DTO.name + DTO.short_name (отображаемое название)
     *   - raw.referral_link → DTO.goto_link (НЕ raw.link)
     *   - raw.description   → DTO.description
     *   - raw.discount+discount_type → DTO.discount (например, "7%")
     *   - raw.date_start/date_end (формат 'YYYY-MM-DD') → DTO.date_start/end
     *   - species = 'promocode' если raw.name (=код) непуст, иначе 'deal'
     *
     * @param array<string,mixed> $raw
     */
    private function build_dto( array $raw ): Cashback_Coupon_DTO {
        $external_id = isset($raw['id']) && is_scalar($raw['id']) ? trim((string) $raw['id']) : '';

        $promocode_value = isset($raw['name']) && is_scalar($raw['name']) ? trim((string) $raw['name']) : '';
        $species         = $promocode_value !== '' ? 'promocode' : 'deal';

        $display_name = isset($raw['short_name']) && is_scalar($raw['short_name'])
            ? trim((string) $raw['short_name'])
            : '';
        if ($display_name === '' && $promocode_value !== '') {
            // У 'deal'-кампании без короткого имени fallback на сам код (хотя в реальном
            // payload Advcake short_name всегда непуст).
            $display_name = $promocode_value;
        }

        $goto_link = isset($raw['referral_link']) && is_scalar($raw['referral_link'])
            ? trim((string) $raw['referral_link'])
            : '';

        $description = isset($raw['description']) && is_scalar($raw['description'])
            ? trim((string) $raw['description'])
            : null;

        $discount_str = null;
        if (isset($raw['discount']) && is_numeric($raw['discount'])) {
            $value = $raw['discount'] + 0;  // numeric-safe cast (int|float)
            $value_str = is_int($value) ? (string) $value : (string) (float) $value;
            $discount_type = isset($raw['discount_type']) && is_scalar($raw['discount_type'])
                ? strtolower(trim((string) $raw['discount_type']))
                : '';
            if ($discount_type === 'percent') {
                $discount_str = $value_str . '%';
            } elseif ($discount_type === 'fixed' || $discount_type === 'fix') {
                $discount_str = $value_str;
            } else {
                $discount_str = $value_str;
            }
        }

        $data = array(
            'external_id' => $external_id,
            'species'     => $species,
            'promocode'   => $promocode_value !== '' ? $promocode_value : null,
            'name'        => $display_name,
            'short_name'  => $display_name !== '' ? $display_name : null,
            'description' => $description,
            'discount'    => $discount_str,
            'date_start'  => $this->normalize_date($raw['date_start'] ?? null),
            'date_end'    => $this->normalize_date($raw['date_end'] ?? null),
            'regions'     => array(),
            'categories'  => array(),
            'image_url'   => null,
            'goto_link'   => $goto_link,
            'is_exclusive' => !empty($raw['private']),
            'rating'      => null,
            'raw_payload' => $raw,
        );

        return Cashback_Coupon_DTO::from_array($data);
    }

    /**
     * Нормализовать дату для DTO::parse_date.
     *
     * Advcake возвращает 'YYYY-MM-DD' (только дата, без времени). DateTimeImmutable
     * парсит это корректно; null/'' возвращаем как null (DTO примет).
     */
    private function normalize_date( mixed $value ): ?string {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
