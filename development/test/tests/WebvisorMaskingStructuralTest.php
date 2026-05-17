<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Структурная проверка: чувствительные зоны Личного кабинета помечены
 * CSS-классами маскирования Яндекс.Вебвизора.
 *
 * Контекст: на сайт подключён счётчик Яндекс.Метрики с webvisor:true.
 * Вебвизор записывает сессии пользователей, включая ЛК с финансовыми и
 * персональными данными (баланс, история выплат, реквизиты счёта, переписка
 * поддержки). Без маскирования это передача ПДн в ООО «Яндекс» в нарушение
 * 152-ФЗ. Механика (офиц. документация Яндекса):
 *
 *   - ym-hide-content  — на контейнере: содержимое размывается; вложенные
 *                        поля форм → звёздочки.
 *   - ym-disable-keys  — на поле ввода: значение → звёздочки, окружение видно.
 *
 * Тест намеренно читает PHP-шаблоны напрямую (file_get_contents) и проверяет
 * наличие точных подстрок «селектор + класс». Это deterministic-привязка,
 * которая ловит регрессию, если класс пропадёт при будущей правке разметки.
 * Substring-match по реальному содержимому файла, без regex по комментариям.
 */
#[Group('privacy')]
#[Group('webvisor-masking')]
final class WebvisorMaskingStructuralTest extends TestCase
{
    /**
     * Каждая строка: метка → [относительный путь, список обязательных подстрок].
     * Подстрока = фрагмент HTML с селектором чувствительного элемента и
     * ожидаемым классом маскирования, как он зафиксирован в шаблоне.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function masking_provider(): array
    {
        return array(
            'shortcodes_balance' => array(
                'includes/class-cashback-shortcodes.php',
                array(
                    'cashback-balance__amount ym-hide-content',
                    'cashback-balance-widget__amount ym-hide-content',
                ),
            ),
            'withdrawal_balance_and_form' => array(
                'cashback-withdrawal.php',
                array(
                    'balance-amount ym-hide-content',
                    'balance-info-value ym-hide-content',
                    'ym-disable-keys" name="withdrawal_amount"',
                    'input-text ym-disable-keys" name="payout_account"',
                    'input-text ym-disable-keys" id="bank_search_input"',
                    'id="payout_settings_display" class="payout-settings-display ym-hide-content"',
                ),
            ),
            'history_transactions' => array(
                'cashback-history.php',
                array(
                    'id="transactions-table-container" class="ym-hide-content"',
                    'id="history-search" class="clicks-filter-input ym-disable-keys"',
                ),
            ),
            'history_payouts' => array(
                'history-payout.php',
                array(
                    'id="payouts-table-container" class="ym-hide-content"',
                    'id="payout-search" class="clicks-filter-input ym-disable-keys"',
                ),
            ),
            'affiliate' => array(
                'affiliate/class-affiliate-frontend.php',
                array(
                    'cashback-affiliate-link-input ym-disable-keys',
                    'cashback-affiliate-section cashback-affiliate-stats ym-hide-content',
                    'id="affiliate-accruals-container" class="ym-hide-content"',
                    'id="affiliate-referrals-container" class="ym-hide-content"',
                ),
            ),
            'claims_form' => array(
                'claims/class-claims-frontend.php',
                array(
                    'id="claim-order-id" name="order_id" required class="ym-disable-keys"',
                    'id="claim-order-value" name="order_value" step="0.01" min="0.01" required class="ym-disable-keys"',
                    'id="claim-order-date" name="order_date" required class="ym-disable-keys"',
                    'id="claim-comment" name="comment" rows="3" class="ym-disable-keys"',
                    'id="claim-modal" class="claim-modal ym-hide-content"',
                    'id="clicks-table-container" class="ym-hide-content"',
                    'id="claims-table-container" class="ym-hide-content"',
                ),
            ),
            'support' => array(
                'support/user-support.php',
                array(
                    'id="support-tickets-container" class="ym-hide-content"',
                    'id="support-ticket-detail" class="ym-hide-content"',
                    'id="support-subject" name="subject" maxlength="255" class="ym-disable-keys"',
                    'id="support-message" name="message" maxlength="5000" class="ym-disable-keys"',
                    'class="support-form-group ym-hide-content"',
                ),
            ),
        );
    }

    /**
     * @param array<int, string> $needles
     */
    #[DataProvider('masking_provider')]
    #[Group('privacy')]
    public function test_sensitive_zone_carries_webvisor_masking_class( string $relative_path, array $needles ): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $path        = $plugin_root . '/' . $relative_path;

        $this->assertFileExists($path, "Шаблон не найден: {$relative_path}");

        $content = (string) file_get_contents($path);
        $this->assertNotSame('', $content, "Пустой шаблон: {$relative_path}");

        foreach ($needles as $needle) {
            $this->assertStringContainsString(
                $needle,
                $content,
                sprintf(
                    'В %s отсутствует маскирующий класс Вебвизора. Ожидалась подстрока: %s',
                    $relative_path,
                    $needle
                )
            );
        }
    }
}
