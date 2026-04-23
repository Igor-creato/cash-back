<?php
/**
 * Cashback_Money — immutable value-object для денежных сумм.
 *
 * Закрывает класс багов «float-на-деньгах» (ADR Группа 10): убирает (float)-cast'ы,
 * `%f` в $wpdb->prepare, ad-hoc BCMath-вызовы. Внутреннее представление — canonical
 * money-string `^-?\d+\.\d{2}$`; вся арифметика идёт через BCMath со scale=2.
 *
 * Границы:
 *   - client → server: `from_string()` strict (только 0–2 знака, без `,`, `e`, `+`).
 *   - DB → server:     `from_db_value()` принимает legacy rows с 0–2 знаками; >2 → fail-closed.
 *   - server → DB:     `to_db_value()` → передавать в `$wpdb->prepare('%s', ...)`.
 *
 * Rate/percent поля (cashback_rate, affiliate_rate) — НЕ money; хранятся как string,
 * принимаются `mul_rate()` без normalization до 2 знаков (могут иметь больше точности).
 *
 * См. ADR: obsidian/knowledge/decisions/security-refactor-plan-2026-04-21.md, Группа 10.
 *
 * @package Cashback
 * @since   1.3.0
 */

if (! defined('ABSPATH') && ! defined('PHPUNIT_RUNNING')) {
    exit;
}

if (class_exists('Cashback_Money', false)) {
    return;
}

final class Cashback_Money
{
    private const SCALE = 2;

    /**
     * Canonical regex для money-string: опциональный минус, целая часть, точка, ровно 2 знака.
     */
    private const CANONICAL_REGEX = '/^-?\d+\.\d{2}$/';

    /**
     * Regex на вход `from_string` (strict client boundary): 0–2 знака после точки.
     */
    private const INPUT_REGEX = '/^-?\d+(?:\.\d{1,2})?$/';

    /**
     * Regex для rate (процент): допускает больше точности, например "5.5" или "3.333".
     */
    private const RATE_REGEX = '/^-?\d+(?:\.\d+)?$/';

    /**
     * Canonical-строка формата `^-?\d+\.\d{2}$`.
     *
     * @var string
     */
    private string $value;

    private function __construct(string $canonical_value)
    {
        $this->value = $canonical_value;
    }

    // ================================================================
    // Factories
    // ================================================================

    /**
     * Строгий разбор client-side значения. Принимает string вида `100`, `100.5`, `100.50`.
     * Отклоняет: запятую как separator, scientific notation, `+`-префикс, whitespace,
     * >2 знаков после точки.
     *
     * @param mixed $value
     * @throws InvalidArgumentException если значение не соответствует контракту.
     */
    public static function from_string($value): self
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Cashback_Money::from_string expects string, got ' . gettype($value)
            );
        }
        if (! preg_match(self::INPUT_REGEX, $value)) {
            throw new InvalidArgumentException(
                'Cashback_Money::from_string rejects non-canonical money input: ' . var_export($value, true)
            );
        }

        return new self(self::normalize($value));
    }

    /**
     * Разбор значения из БД DECIMAL(18,2). Функционально идентичен from_string (DECIMAL
     * всегда хранит ≤2 знаков), отдельный метод документирует boundary: при получении
     * row с >2 знаками (data corruption / miscast) — fail-closed через InvalidArgumentException.
     *
     * Принимает только string (DECIMAL из wpdb приходит строкой). null / float / int → reject.
     *
     * @param mixed $value
     * @throws InvalidArgumentException
     */
    public static function from_db_value($value): self
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Cashback_Money::from_db_value expects string (DECIMAL), got ' . gettype($value)
            );
        }
        if (! preg_match(self::INPUT_REGEX, $value)) {
            throw new InvalidArgumentException(
                'Cashback_Money::from_db_value rejects non-canonical DB value: ' . var_export($value, true)
            );
        }

        return new self(self::normalize($value));
    }

    public static function zero(): self
    {
        return new self('0.00');
    }

    // ================================================================
    // Arithmetic (immutable)
    // ================================================================

    public function add(self $other): self
    {
        return new self(self::normalize(bcadd($this->value, $other->value, self::SCALE)));
    }

    public function sub(self $other): self
    {
        return new self(self::normalize(bcsub($this->value, $other->value, self::SCALE)));
    }

    /**
     * Умножает сумму на rate в процентах: `money * rate / 100`. Результат truncate до 2 знаков
     * (BCMath default). Для half-up округления — отдельный метод в будущем, если понадобится.
     *
     * @param string $rate_percent Процент как string, например "5", "5.5", "3.333".
     *                             Допускается >2 знаков (rate — не money).
     * @throws InvalidArgumentException если rate не string или не numeric.
     */
    public function mul_rate(string $rate_percent): self
    {
        if (! preg_match(self::RATE_REGEX, $rate_percent)) {
            throw new InvalidArgumentException(
                'Cashback_Money::mul_rate rejects non-numeric rate: ' . var_export($rate_percent, true)
            );
        }
        // Промежуточная scale выше 2, чтобы rate с >2 знаками не обрезался до умножения.
        $scale_intermediate = max(self::SCALE + 2, strlen($rate_percent));
        $product            = bcmul($this->value, $rate_percent, $scale_intermediate);
        $result             = bcdiv($product, '100', self::SCALE);

        return new self(self::normalize($result));
    }

    public function negate(): self
    {
        return new self(self::normalize(bcsub('0', $this->value, self::SCALE)));
    }

    // ================================================================
    // Comparison
    // ================================================================

    public function compare(self $other): int
    {
        return bccomp($this->value, $other->value, self::SCALE);
    }

    public function equals(self $other): bool
    {
        return 0 === $this->compare($other);
    }

    public function is_zero(): bool
    {
        return 0 === bccomp($this->value, '0', self::SCALE);
    }

    public function is_negative(): bool
    {
        return -1 === bccomp($this->value, '0', self::SCALE);
    }

    public function is_positive(): bool
    {
        return 1 === bccomp($this->value, '0', self::SCALE);
    }

    public function is_greater_than(self $other): bool
    {
        return 1 === $this->compare($other);
    }

    public function is_less_than(self $other): bool
    {
        return -1 === $this->compare($other);
    }

    // ================================================================
    // Serialization
    // ================================================================

    public function to_string(): string
    {
        return $this->value;
    }

    /**
     * Алиас to_string() — семантически выделяет wpdb-границу: `$wpdb->prepare('%s', $money->to_db_value())`.
     */
    public function to_db_value(): string
    {
        return $this->value;
    }

    // ================================================================
    // Static assertions
    // ================================================================

    /**
     * Валидация canonical money-string (ровно 2 знака, без `,`/`+`/`e`). Используется
     * на границах wpdb и REST — когда значение уже должно быть canonical, но хочется
     * fail-closed на случай регрессии.
     *
     * @param mixed $value
     * @throws InvalidArgumentException
     */
    public static function assert_money_string($value): void
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'assert_money_string expects canonical string, got ' . gettype($value)
            );
        }
        if (! preg_match(self::CANONICAL_REGEX, $value)) {
            throw new InvalidArgumentException(
                'assert_money_string rejects non-canonical money string: ' . var_export($value, true)
            );
        }
    }

    // ================================================================
    // Internal
    // ================================================================

    /**
     * Приводит BCMath-output / input к canonical формату: `-?\d+\.\d{2}` без «отрицательного нуля».
     * BCMath возвращает строки вроде `-0.00` для `bcsub('0','0',2)` — конвертируем в `0.00`.
     */
    private static function normalize(string $raw): string
    {
        // bcadd с scale=2 всегда возвращает `.\d{2}` если второй аргумент тоже в scale;
        // но from_string/from_db_value могут получить `100` без точки — добавим.
        $normalized = bcadd($raw, '0', self::SCALE);

        // `-0.00` → `0.00`.
        if ('-0.00' === $normalized) {
            $normalized = '0.00';
        }

        return $normalized;
    }
}
