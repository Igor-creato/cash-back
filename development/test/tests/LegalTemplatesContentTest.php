<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 (Phase 2 plan greedy-soaring-salamander): структурная проверка
 * наличия в default-шаблонах юридически грамотных формулировок и отсутствия
 * запрещённых.
 *
 * 5 правовых позиций сервиса, обязательных для отражения в текстах оферты и
 * связанных согласий:
 *
 *   1. Сервис не участвует в расчётах между Пользователями и Интернет-магазинами
 *      (ст. 437 ГК РФ как форма публичной оферты, разделение ролей).
 *   2. Пользователь самостоятельно декларирует и платит налоги (НДФЛ): ст. 226
 *      НК РФ — Сервис не налоговый агент; ст. 217 п. 68 НК РФ + письмо Минфина
 *      14.08.2024 № 03-04-05/76142 — программы лояльности; ст. 228 НК — порядок
 *      самостоятельной декларации в случаях вне ст. 217 п. 68.
 *   3. Сервис не является кредитной/платёжной/финансовой организацией; кэшбэк
 *      ≠ ЭДС, ≠ вклад, ≠ финансовый инструмент (161-ФЗ, 395-1, Определение
 *      Третьего кассационного суда от 04.09.2023).
 *   4. Используется термин «бонусное вознаграждение», а не «денежное
 *      вознаграждение» (квалификация бонусов как не средства платежа).
 *   5. Запрет множественных учётных записей и связанных недобросовестных
 *      практик (раздел Terms_Offer + Affiliate_Program; ст. 1102 ГК РФ как
 *      основание удержания накопленного при выявлении нарушения).
 *
 * Проверяется: каждый из 9 шаблонов в legal/templates/*.php содержит
 * необходимые маркеры из списка для своего типа и не содержит запрещённых
 * формулировок. Плашка LAWYER_REVIEW_REQUIRED обязательна до финального
 * утверждения юристом отдельным коммитом.
 *
 * Тест намеренно проверяет seed-PHP-шаблон напрямую (include $path), а не
 * через Cashback_Legal_Documents::get_rendered() — DB-override (UI-редактор)
 * сюда не попадает: source of truth для default-инсталляций — это PHP-файлы.
 */
#[Group('legal')]
#[Group('legal-compliance')]
#[Group('legal-templates-content')]
final class LegalTemplatesContentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        require_once $plugin_root . '/legal/class-cashback-legal-documents.php';
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<int, array{0: string, 1: string}>, 3: array<int, string>}>
     *   Каждая строка: type → [type, template_path, required_phrases, forbidden_phrases].
     *   required_phrases: list of [needle_lower, reason_for_failure_message].
     *   forbidden_phrases: list of needle_lower.
     */
    public static function templates_provider(): array
    {
        return array(
            'terms_offer' => array(
                'terms_offer',
                'legal/templates/terms-offer.php',
                array(
                    array( 'бонусное вознаграждение', 'Универсальный термин — должен использоваться вместо «денежное вознаграждение»' ),
                    array( 'не является налоговым агентом', 'Прямая декларация: Сервис не налоговый агент в смысле ст. 226 НК РФ' ),
                    array( 'не участвует в расчётах', 'Декларация о неучастии Сервиса в расчётах между Пользователем и Партнёром (Интернет-магазином)' ),
                    array( 'не является продавцом', 'Декларация о том, что Сервис не Продавец товаров/услуг' ),
                    array( '437 гражданского', 'Ссылка на ст. 437 ГК РФ (публичная оферта)' ),
                    array( '226 налогового', 'Ссылка на ст. 226 НК РФ (налоговый агент)' ),
                    array( '217 налогового', 'Ссылка на ст. 217 НК РФ (программы лояльности, п. 68)' ),
                    array( '228 налогового', 'Ссылка на ст. 228 НК РФ (самостоятельная декларация дохода)' ),
                    array( '1102 гражданского', 'Ссылка на ст. 1102 ГК РФ (неосновательное обогащение — основание удержания)' ),
                    array( '161-фз', 'Ссылка на 161-ФЗ для квалификации статуса (не оператор НПС)' ),
                    array( '149-фз', 'Ссылка на 149-ФЗ (раскрытие сведений о владельце сайта, ст. 10)' ),
                    array( 'кредитной организацией', 'Перечисление: Сервис не кредитная организация' ),
                    array( 'оператором электронных денежных средств', 'Прямая декларация: Сервис не оператор ЭДС' ),
                    array( 'множественных учётных записей', 'Запрет создания второй и последующих учётных записей' ),
                    array( '14 (четырнадцати) календарных дней', 'Срок апелляции по применённым мерам (14 дней)' ),
                    array( 'минфина', 'Ссылка на письмо Минфина (квалификация программ лояльности по ст. 217 п. 68 НК)' ),
                    array( 'кассационного суда', 'Ссылка на Определение Третьего кассационного суда (бонусы ≠ ЭДС)' ),
                    array( '2300-1', 'Ссылка на Закон РФ № 2300-1 «О защите прав потребителей» (отношения с Партнёром)' ),
                    array( '395-1', 'Ссылка на Закон РФ № 395-1 «О банках и банковской деятельности»' ),
                    array( 'правовой статус', 'Должен присутствовать выделенный раздел «Правовой статус Сервиса и бонусного вознаграждения»' ),
                    array( '{{operator_full_name}}', 'Должен сохраняться плейсхолдер реквизитов оператора' ),
                    array( '{{site_url}}', 'Должен сохраняться плейсхолдер site_url' ),
                ),
                array(
                    'денежное вознаграждение',
                    'кэшбэк — денежное',
                ),
            ),
            'pd_policy' => array(
                'pd_policy',
                'legal/templates/pd-policy.php',
                array(
                    array( '152-фз', 'Базовая ссылка на 152-ФЗ' ),
                    array( '5 статьи 18 федерального закона', 'Ссылка на ч. 5 ст. 18 152-ФЗ — локализация баз данных на территории РФ' ),
                    array( 'на территории российской федерации', 'Прямая декларация о хранении ПД на территории РФ (локализация)' ),
                    array( '5 части 1 статьи 6', 'Правовое основание исполнения договора (ч. 1 п. 5 ст. 6 152-ФЗ)' ),
                    array( '7 части 1 статьи 6', 'Правовое основание законного интереса оператора — антифрод (ч. 1 п. 7 ст. 6 152-ФЗ)' ),
                    array( '01.09.2025', 'Ссылка на редакцию 152-ФЗ от 01.09.2025 (отдельные согласия)' ),
                    array( '30.05.2025', 'Ссылка на редакцию 152-ФЗ от 30.05.2025 (cookies → ПД, ужесточение ответственности)' ),
                    array( 'роскомнадзор', 'Должен упоминаться РКН как надзорный орган' ),
                    array( '{{operator_full_name}}', 'Должен сохраняться плейсхолдер реквизитов оператора' ),
                ),
                array(
                    'денежное вознаграждение',
                ),
            ),
            'pd_consent' => array(
                'pd_consent',
                'legal/templates/pd-consent.php',
                array(
                    array( '9 федерального закона', 'Ссылка на ст. 9 ФЗ № 152-ФЗ (основание согласия) — стабильная привязка по номеру и наименованию закона' ),
                    array( '152-фз', 'Базовая ссылка на 152-ФЗ' ),
                    array( 'свободно, своей волей', 'Формула свободного волеизъявления — обязательное условие действительности согласия (ст. 9 152-ФЗ)' ),
                    array( 'отдельным', 'Декларация об отдельности согласий (платёжные/маркетинг/cookies/tech) — требование 01.09.2025' ),
                    array( 'до момента отзыва', 'Срок действия согласия должен быть указан явно' ),
                    array( '{{operator_full_name}}', 'Должен сохраняться плейсхолдер реквизитов оператора' ),
                ),
                array(
                    'денежное вознаграждение',
                ),
            ),
            'payment_pd' => array(
                'payment_pd',
                'legal/templates/payment-pd.php',
                array(
                    array( '161-фз', 'Базовая ссылка на 161-ФЗ' ),
                    array( '115-фз', 'Базовая ссылка на 115-ФЗ (ПОД/ФТ, срок хранения 5 лет)' ),
                    array( 'не является оператором', 'Декларация: Оператор не является оператором НПС/ЭДС в смысле 161-ФЗ' ),
                    array( '5 (пяти) лет', 'Срок хранения по 115-ФЗ — 5 лет' ),
                    array( 'aes-256-gcm', 'Технический контроль шифрования (authenticated encryption)' ),
                    array( '{{operator_full_name}}', 'Должен сохраняться плейсхолдер реквизитов оператора' ),
                ),
                array(
                    'денежное вознаграждение',
                ),
            ),
            'marketing' => array(
                'marketing',
                'legal/templates/marketing.php',
                array(
                    array( '18 федерального закона', 'Ссылка на ст. 18 38-ФЗ (стабильная привязка по номеру + наименованию закона)' ),
                    array( '38-фз', 'Базовая ссылка на 38-ФЗ «О рекламе»' ),
                    array( 'журнал', 'Декларация о фиксации согласия в журнале (защищает рекламораспространителя по ст. 18 38-ФЗ)' ),
                    array( 'выключен', 'Согласие должно быть OFF by default — это явное условие отдельного opt-in' ),
                    array( 'отписаться', 'Должна быть указана возможность отписаться (через Личный кабинет либо ссылку в письме)' ),
                    array( '{{operator_full_name}}', 'Должен сохраняться плейсхолдер реквизитов оператора' ),
                ),
                array(
                    'денежное вознаграждение',
                ),
            ),
            'cookies_policy' => array(
                'cookies_policy',
                'legal/templates/cookies-policy.php',
                array(
                    array( '152-фз', 'Базовая ссылка на 152-ФЗ — cookies с идентификаторами приравнены к ПД' ),
                    array( '30.05.2025', 'Ссылка на редакцию 152-ФЗ от 30.05.2025 (cookies → ПД)' ),
                    array( 'технические', 'Должна быть категория «технические» (обязательные)' ),
                    array( 'аналитические', 'Должна быть категория «аналитические» (опт-ин)' ),
                    array( 'маркетинговые', 'Должна быть категория «маркетинговые/атрибуционные» (опт-ин)' ),
                    array( 'до получения явного согласия', 'Категории non-essential не активируются до явного согласия' ),
                    array( '{{operator_full_name}}', 'Должен сохраняться плейсхолдер реквизитов оператора' ),
                ),
                array(
                    'денежное вознаграждение',
                ),
            ),
            'contact_form_pd' => array(
                'contact_form_pd',
                'legal/templates/contact-form-pd.php',
                array(
                    array( '9 федерального закона', 'Ссылка на ст. 9 ФЗ № 152-ФЗ' ),
                    array( '152-фз', 'Базовая ссылка на 152-ФЗ' ),
                    array( '1 (одного) года', 'Срок обработки обращения — не более 1 года' ),
                    array( '{{operator_full_name}}', 'Должен сохраняться плейсхолдер реквизитов оператора' ),
                ),
                array(
                    'денежное вознаграждение',
                ),
            ),
            'tech_data' => array(
                'tech_data',
                'legal/templates/tech-data.php',
                array(
                    array( '149-фз', 'Базовая ссылка на 149-ФЗ' ),
                    array( '10 федерального закона', 'Ссылка на ст. 10 (либо 10.1) 149-ФЗ' ),
                    array( '6 федерального закона', 'Ссылка на ст. 6 152-ФЗ — правовое основание обработки' ),
                    array( 'fingerprint', 'Должны упоминаться идентификаторы устройства (browser fingerprint)' ),
                    array( 'localstorage', 'Должны упоминаться LocalStorage / IndexedDB как составляющие технических данных' ),
                    array( 'антифрод', 'Должна быть указана цель «защита от мошенничества» (антифрод)' ),
                    array( 'ори', 'Должна быть оговорка про статус ОРИ (149-ФЗ ст. 10.1) и порог трафика' ),
                    array( '{{operator_full_name}}', 'Должен сохраняться плейсхолдер реквизитов оператора' ),
                ),
                array(
                    'денежное вознаграждение',
                ),
            ),
            'affiliate_program' => array(
                'affiliate_program',
                'legal/templates/affiliate-program.php',
                array(
                    array( 'бонусное вознаграждение', 'Универсальный термин' ),
                    array( 'не является налоговым агентом', 'Прямая декларация: Сервис не налоговый агент' ),
                    array( 'не участвует в расчётах', 'Декларация о неучастии Сервиса в расчётах между Пользователями и Партнёрами' ),
                    array( '437 гражданского', 'Ссылка на ст. 437 ГК РФ' ),
                    array( '226 налогового', 'Ссылка на ст. 226 НК РФ' ),
                    array( '1102 гражданского', 'Ссылка на ст. 1102 ГК РФ (основание удержания при фроде)' ),
                    array( '161-фз', 'Ссылка на 161-ФЗ' ),
                    array( 'партнёрский токен', 'Должен присутствовать термин «Партнёрский токен»' ),
                    array( 'auto-promote', 'Должен описываться механизм Auto-promote' ),
                    array( '{{operator_full_name}}', 'Должен сохраняться плейсхолдер реквизитов оператора' ),
                ),
                array(
                    'денежное вознаграждение',
                ),
            ),
        );
    }

    /**
     * @param array<int, array{0: string, 1: string}> $required_phrases
     * @param array<int, string> $forbidden_phrases
     */
    #[DataProvider('templates_provider')]
    public function test_template_contains_required_legal_assertions(
        string $type,
        string $template_path,
        array $required_phrases,
        array $forbidden_phrases
    ): void {
        unset($forbidden_phrases);
        $rendered = $this->load_template_body($template_path);
        $this->assertNotSame('', $rendered, "Шаблон {$template_path} должен возвращать непустую строку");

        $haystack_lower = $this->normalize_for_match($rendered);

        foreach ($required_phrases as $pair) {
            list($needle_lower, $reason) = $pair;
            $this->assertStringContainsString(
                $needle_lower,
                $haystack_lower,
                "[{$type}] {$reason} (искомая подстрока: «{$needle_lower}»)"
            );
        }
    }

    /**
     * @param array<int, array{0: string, 1: string}> $required_phrases
     * @param array<int, string> $forbidden_phrases
     */
    #[DataProvider('templates_provider')]
    public function test_template_does_not_contain_forbidden_phrases(
        string $type,
        string $template_path,
        array $required_phrases,
        array $forbidden_phrases
    ): void {
        unset($required_phrases);
        $rendered       = $this->load_template_body($template_path);
        $haystack_lower = $this->normalize_for_match($rendered);

        foreach ($forbidden_phrases as $needle_lower) {
            $this->assertStringNotContainsString(
                $needle_lower,
                $haystack_lower,
                "[{$type}] Запрещённая формулировка обнаружена: «{$needle_lower}». Должна быть заменена юридически грамотным аналогом."
            );
        }
    }

    /**
     * @param array<int, array{0: string, 1: string}> $required_phrases
     * @param array<int, string> $forbidden_phrases
     */
    #[DataProvider('templates_provider')]
    public function test_template_keeps_lawyer_review_marker(
        string $type,
        string $template_path,
        array $required_phrases,
        array $forbidden_phrases
    ): void {
        unset($required_phrases, $forbidden_phrases);
        $rendered = $this->load_template_body($template_path);
        $this->assertStringContainsString(
            'LAWYER_REVIEW_REQUIRED',
            $rendered,
            "[{$type}] Плашка LAWYER_REVIEW_REQUIRED обязательна до финального утверждения юристом (снимается отдельным коммитом)."
        );
    }

    /**
     * @param array<int, array{0: string, 1: string}> $required_phrases
     * @param array<int, string> $forbidden_phrases
     */
    #[DataProvider('templates_provider')]
    public function test_template_size_within_validator_limits(
        string $type,
        string $template_path,
        array $required_phrases,
        array $forbidden_phrases
    ): void {
        unset($required_phrases, $forbidden_phrases);
        $rendered = $this->load_template_body($template_path);
        $size     = strlen($rendered);
        // Cashback_Legal_Template_Validator::MAX_BODY_BYTES = 200_000.
        $this->assertLessThanOrEqual(
            200000,
            $size,
            "[{$type}] Размер шаблона ({$size} байт) превышает лимит валидатора (200000)."
        );
        $this->assertGreaterThan(
            500,
            $size,
            "[{$type}] Шаблон подозрительно мал ({$size} байт) — после переработки ожидается содержательный текст."
        );
    }

    public function test_meta_paths_match_filesystem_for_all_types(): void
    {
        // Sanity: пути из get_meta() совпадают с реальными файлами шаблонов.
        $plugin_root = dirname(__DIR__, 3);
        foreach (Cashback_Legal_Documents::all_types() as $type) {
            $meta = Cashback_Legal_Documents::get_meta($type);
            $this->assertNotEmpty(
                $meta['template_path'] ?? '',
                "get_meta({$type})['template_path'] не должен быть пустым"
            );
            $path = $plugin_root . '/' . $meta['template_path'];
            $this->assertFileExists(
                $path,
                "Файл шаблона {$meta['template_path']} должен существовать (тип {$type})"
            );
        }
    }

    private function load_template_body(string $relative_path): string
    {
        $plugin_root = dirname(__DIR__, 3);
        $path        = $plugin_root . '/' . $relative_path;
        if (!file_exists($path)) {
            return '';
        }
        // PHP-шаблон возвращает строку через `return '...';` без вывода.
        $content = include $path;
        return is_string($content) ? $content : '';
    }

    /**
     * Нормализация HTML-шаблона для substring-матчинга:
     *   - strip HTML-тегов (чтобы «не участвует</strong> в расчётах» матчилось как
     *     «не участвует в расчётах»);
     *   - схлопывание любых пробелов/переносов в один пробел;
     *   - lowercase.
     *
     * Плейсхолдеры {{operator_*}} переживают strip_tags без потерь — они вне тегов.
     */
    private function normalize_for_match(string $html): string
    {
        // phpcs:ignore WordPressVIPMinimum.Functions.StripTags.StripTagsTwoParameters -- normalization для assertion-match'а; XSS не релевантен.
        $stripped   = strip_tags($html);
        $collapsed  = (string) preg_replace('/\s+/u', ' ', $stripped);
        return mb_strtolower($collapsed);
    }
}
