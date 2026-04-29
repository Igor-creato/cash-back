<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Контрактные тесты для шаблона формы вывода кэшбэка
 * (`cashback-withdrawal.php::endpoint_content`) — defense-in-depth follow-ups
 * из E2E прогона 2026-04-29:
 *   - A6-1 P1: plaintext payout_account в `<input value="">`
 *   - A3-1 P2: ARIA labels отсутствовали
 *   - A3-2 P2: `<form method="get">` (нет атрибута → дефолт GET)
 */
#[Group('frontend')]
#[Group('security')]
final class WithdrawalFormPiiAndAriaTest extends TestCase
{
    private const TEMPLATE_FILE = __DIR__ . '/../../../cashback-withdrawal.php';

    private function template_source(): string
    {
        $src = file_get_contents(self::TEMPLATE_FILE);
        $this->assertIsString($src);
        return $src;
    }

    /**
     * P1-A6-1: при наличии активных настроек НЕ рендерить full plaintext
     * payout_account в DOM. Должно быть: либо value="" с placeholder, либо
     * условный render. Любой `value="' . esc_attr($payout_account)` в форме
     * настроек — leak.
     */
    public function test_payout_account_not_rendered_with_plaintext_value(): void
    {
        $src   = $this->template_source();
        $lines = explode("\n", $src);

        foreach ($lines as $i => $line) {
            if (strpos($line, 'id="payout_account"') === false) {
                continue;
            }
            // Линия с input должна НЕ ссылаться на $payout_account-переменную.
            // Допустимо: value="" или value="' . esc_attr($masked_account) . '" (маска).
            // Запрещено: esc_attr($payout_account) или $payout_account в значении.
            $this->assertStringNotContainsString(
                '$payout_account',
                $line,
                sprintf(
                    'P1-A6-1: line %d renders $payout_account in <input id="payout_account"> — PII leak in DOM. '
                    . 'Use value="" with placeholder, or value="$masked_account" (digest), but never plaintext.',
                    $i + 1
                )
            );
            return;
        }
        $this->fail('Line with id="payout_account" not found in template.');
    }

    /**
     * P2-A3-2: форма вывода должна явно объявлять method="post". Без атрибута
     * браузер использует GET по умолчанию, и при сбое JS native submit
     * отправит nonce + amount в URL/Referrer.
     */
    public function test_withdrawal_form_uses_post_method(): void
    {
        $src = $this->template_source();

        // Ищем строку с открывающим тегом form withdrawal
        if (!preg_match('/<form[^>]*id="withdrawal-form"[^>]*>/', $src, $m)) {
            $this->fail('Form #withdrawal-form not found.');
        }
        $form_tag = $m[0];

        $this->assertStringContainsString(
            'method="post"',
            $form_tag,
            'P2-A3-2: <form id="withdrawal-form"> must declare method="post" to prevent GET-leak on JS-fail.'
        );
    }

    /**
     * P2-A3-1: форма должна иметь aria-label или role="form" для screen-reader
     * users. Минимально допустимая фиксация — naming через aria-labelledby или
     * aria-label.
     */
    public function test_withdrawal_form_has_aria_naming(): void
    {
        $src = $this->template_source();

        if (!preg_match('/<form[^>]*id="withdrawal-form"[^>]*>/', $src, $m)) {
            $this->fail('Form #withdrawal-form not found.');
        }
        $form_tag = $m[0];

        $has_aria = (
            strpos($form_tag, 'aria-label') !== false
            || strpos($form_tag, 'aria-labelledby') !== false
        );

        $this->assertTrue(
            $has_aria,
            'P2-A3-1: <form id="withdrawal-form"> must have aria-label or aria-labelledby for screen-reader naming.'
        );
    }

    /**
     * P2-A3-1: amount input должен иметь aria-describedby к подсказке
     * "Минимальная сумма выплаты".
     */
    public function test_amount_input_has_aria_describedby(): void
    {
        $src = $this->template_source();

        if (!preg_match('/<input[^>]*id="withdrawal-amount"[^>]*>/', $src, $m)) {
            $this->fail('Input #withdrawal-amount not found.');
        }
        $input = $m[0];

        $this->assertStringContainsString(
            'aria-describedby',
            $input,
            'P2-A3-1: amount <input> must have aria-describedby pointing to min-payout hint for accessibility.'
        );
    }
}
