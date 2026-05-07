<?php
/**
 * Cashback_Campaign_Detail_DTO — value-object для детальной кампании из API
 * CPA-сети (v12).
 *
 * Возвращается из adapter::fetch_campaigns_detailed() и потребляется
 * Cashback_Shop_Importer для создания/обновления WC external products.
 *
 * Поля собраны под worst-case Admitad/EPN: site_url для дедупа по домену,
 * image_url для featured image, currency для FIX-тарифов, raw для отладки.
 *
 * Иммутабельный: factory from_array() → typed properties → to_array() для записи.
 * Все getter'ы возвращают безопасные дефолты; ID считается обязательным.
 *
 * @package CashbackPlugin
 * @since   12.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH') && ! defined('PHPUNIT_RUNNING')) {
    exit;
}

if (class_exists('Cashback_Campaign_Detail_DTO', false)) {
    return;
}

final class Cashback_Campaign_Detail_DTO {

    public string $id;
    public string $name;
    public string $site_url;
    public string $image_url;
    public string $description;
    public string $status_raw;
    public bool $is_active;
    /**
     * Статус подключения паблишера к программе (Admitad-only).
     * Значения: 'active' / 'pending' / 'declined' / 'suspend'.
     * '' если адаптер не возвращает (старый endpoint или не-Admitad сеть).
     */
    public string $connection_status;
    /** @var array<int, string> */
    public array $regions;
    /** @var array<int, string> */
    public array $categories;
    public string $currency;
    public string $goto_link;
    /** @var array<string, mixed> */
    public array $raw;
    /**
     * Тарифы, пришедшие inline в payload детальной кампании
     * (Admitad website-scoped endpoint: actions_detail[].tariffs[]).
     * Каждый элемент — массив, совместимый с Cashback_Shop_Tariff_DTO::from_array().
     * Если адаптер не отдаёт inline (или payload пуст) — массив пустой; импортёр
     * fallback'ом дёргает adapter::fetch_shop_tariffs().
     *
     * @var array<int, array<string, mixed>>
     */
    public array $inline_tariffs;

    private function __construct(
        string $id,
        string $name,
        string $site_url,
        string $image_url,
        string $description,
        string $status_raw,
        bool $is_active,
        string $connection_status,
        array $regions,
        array $categories,
        string $currency,
        string $goto_link,
        array $raw,
        array $inline_tariffs
    ) {
        $this->id                = $id;
        $this->name              = $name;
        $this->site_url          = $site_url;
        $this->image_url         = $image_url;
        $this->description       = $description;
        $this->status_raw        = $status_raw;
        $this->is_active         = $is_active;
        $this->connection_status = $connection_status;
        $this->regions           = $regions;
        $this->categories        = $categories;
        $this->currency          = $currency;
        $this->goto_link         = $goto_link;
        $this->raw               = $raw;
        $this->inline_tariffs    = $inline_tariffs;
    }

    /**
     * Создать DTO из массива (от адаптера). ID — обязательное поле.
     *
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException если 'id' пустой.
     */
    public static function from_array( array $data ): self {
        $id = isset($data['id']) ? (string) $data['id'] : '';
        if ($id === '') {
            throw new \InvalidArgumentException(
                'Cashback_Campaign_Detail_DTO: field "id" is required and must be non-empty'
            );
        }

        $regions    = isset($data['regions']) && is_array($data['regions'])
            ? array_values(array_map('strval', $data['regions']))
            : array();
        $categories = isset($data['categories']) && is_array($data['categories'])
            ? array_values(array_map('strval', $data['categories']))
            : array();
        $raw        = isset($data['raw']) && is_array($data['raw']) ? $data['raw'] : array();

        $inline_tariffs = array();
        if (isset($data['inline_tariffs']) && is_array($data['inline_tariffs'])) {
            foreach ($data['inline_tariffs'] as $entry) {
                if (is_array($entry)) {
                    $inline_tariffs[] = $entry;
                }
            }
        }

        $currency = isset($data['currency']) ? strtoupper((string) $data['currency']) : 'RUB';
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'RUB';
        }

        $connection_status = isset($data['connection_status'])
            ? strtolower(trim((string) $data['connection_status']))
            : '';

        return new self(
            $id,
            isset($data['name']) ? (string) $data['name'] : '',
            isset($data['site_url']) ? (string) $data['site_url'] : '',
            isset($data['image_url']) ? (string) $data['image_url'] : '',
            isset($data['description']) ? (string) $data['description'] : '',
            isset($data['status_raw']) ? (string) $data['status_raw'] : '',
            ! empty($data['is_active']),
            $connection_status,
            $regions,
            $categories,
            $currency,
            isset($data['goto_link']) ? (string) $data['goto_link'] : '',
            $raw,
            $inline_tariffs
        );
    }

    /**
     * Сериализовать в массив (для логирования / сохранения).
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return array(
            'id'                => $this->id,
            'name'              => $this->name,
            'site_url'          => $this->site_url,
            'image_url'         => $this->image_url,
            'description'       => $this->description,
            'status_raw'        => $this->status_raw,
            'is_active'         => $this->is_active,
            'connection_status' => $this->connection_status,
            'regions'           => $this->regions,
            'categories'        => $this->categories,
            'currency'          => $this->currency,
            'goto_link'         => $this->goto_link,
            'raw'               => $this->raw,
            'inline_tariffs'    => $this->inline_tariffs,
        );
    }
}
