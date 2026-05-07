<?php
/**
 * Cashback_Shop_Tariff_DTO — value-object для одного тарифа магазина из API
 * CPA-сети (v12).
 *
 * Возвращается из adapter::fetch_shop_tariffs() (массив на campaign) и
 * потребляется Cashback_Shop_Tariff_Sync для upsert в wp_cashback_shop_tariffs.
 *
 * Иммутабельный, factory from_array() с валидацией tariff_type ENUM('percent','fix').
 * Все остальные поля имеют безопасные дефолты.
 *
 * @package CashbackPlugin
 * @since   12.0.0
 */

declare(strict_types=1);

if (! defined('ABSPATH') && ! defined('PHPUNIT_RUNNING')) {
    exit;
}

if (class_exists('Cashback_Shop_Tariff_DTO', false)) {
    return;
}

final class Cashback_Shop_Tariff_DTO {

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIX     = 'fix';

    public string $tariff_id;
    public string $name;
    /** @var 'percent'|'fix' */
    public string $tariff_type;
    public float $payment_size;
    public ?float $payment_min;
    public ?float $payment_max;
    public string $currency;
    public bool $is_default;
    /** @var array<string, mixed> */
    public array $raw;

    private function __construct(
        string $tariff_id,
        string $name,
        string $tariff_type,
        float $payment_size,
        ?float $payment_min,
        ?float $payment_max,
        string $currency,
        bool $is_default,
        array $raw
    ) {
        $this->tariff_id    = $tariff_id;
        $this->name         = $name;
        $this->tariff_type  = $tariff_type;
        $this->payment_size = $payment_size;
        $this->payment_min  = $payment_min;
        $this->payment_max  = $payment_max;
        $this->currency     = $currency;
        $this->is_default   = $is_default;
        $this->raw          = $raw;
    }

    /**
     * Создать DTO из массива.
     *
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException при пустом tariff_id или невалидном tariff_type.
     */
    public static function from_array( array $data ): self {
        $tariff_id = isset($data['tariff_id']) ? (string) $data['tariff_id'] : '';
        if ($tariff_id === '') {
            throw new \InvalidArgumentException(
                'Cashback_Shop_Tariff_DTO: field "tariff_id" is required and must be non-empty'
            );
        }

        $tariff_type = isset($data['tariff_type']) ? strtolower((string) $data['tariff_type']) : '';
        if ($tariff_type !== self::TYPE_PERCENT && $tariff_type !== self::TYPE_FIX) {
            $message = 'Cashback_Shop_Tariff_DTO: field "tariff_type" must be "'
                . self::TYPE_PERCENT . '" or "' . self::TYPE_FIX
                . '", got "' . $tariff_type . '"';
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message используется только для логирования/throw, не выводится в HTML.
            throw new \InvalidArgumentException($message);
        }

        $payment_size = isset($data['payment_size']) && is_numeric($data['payment_size'])
            ? max(0.0, (float) $data['payment_size'])
            : 0.0;
        $payment_min  = isset($data['payment_min']) && is_numeric($data['payment_min'])
            ? max(0.0, (float) $data['payment_min'])
            : null;
        $payment_max  = isset($data['payment_max']) && is_numeric($data['payment_max'])
            ? max(0.0, (float) $data['payment_max'])
            : null;

        $currency = isset($data['currency']) ? strtoupper((string) $data['currency']) : 'RUB';
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'RUB';
        }

        $raw = isset($data['raw']) && is_array($data['raw']) ? $data['raw'] : array();

        return new self(
            $tariff_id,
            isset($data['name']) ? (string) $data['name'] : '',
            $tariff_type,
            $payment_size,
            $payment_min,
            $payment_max,
            $currency,
            ! empty($data['is_default']),
            $raw
        );
    }

    /**
     * Сериализовать в массив.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return array(
            'tariff_id'    => $this->tariff_id,
            'name'         => $this->name,
            'tariff_type'  => $this->tariff_type,
            'payment_size' => $this->payment_size,
            'payment_min'  => $this->payment_min,
            'payment_max'  => $this->payment_max,
            'currency'     => $this->currency,
            'is_default'   => $this->is_default,
            'raw'          => $this->raw,
        );
    }
}
