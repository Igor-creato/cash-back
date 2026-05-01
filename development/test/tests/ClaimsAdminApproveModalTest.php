<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты frontend-модала combined approve+tx (Session 4-bis,
 * P0.1 F-S7-NO-MANUAL-CREDIT).
 *
 * Backend-handler `Cashback_Claims_Admin::ajax_approve_with_tx` уже на main
 * (commit 13e08a5, тесты в ClaimApproveWithTxAjaxTest). Этот файл проверяет
 * UI-обвязку: trigger-кнопку, разметку модала, JS-asset, enqueue + localize.
 *
 * Source-based парсинг — никаких WP-bootstrap'ов.
 */
#[Group('claims')]
#[Group('group-p0-1')]
final class ClaimsAdminApproveModalTest extends TestCase
{
    private function source(string $rel): string
    {
        $path    = dirname(__DIR__, 3) . '/' . $rel;
        $content = file_get_contents($path);
        $this->assertIsString($content, "{$rel} must be readable");
        return $content;
    }

    private function admin_src(): string
    {
        return $this->source('claims/class-claims-admin.php');
    }

    private function js_src(): string
    {
        return $this->source('assets/js/admin-claim-approve-tx.js');
    }

    // =========================================================================
    // Trigger-кнопка в render_claim_detail (заменяет legacy approve-кнопку)
    // =========================================================================

    public function test_render_claim_detail_has_approve_with_tx_trigger(): void
    {
        $src = $this->admin_src();
        $this->assertStringContainsString(
            'cashback-claim-approve-tx',
            $src,
            'trigger-кнопка должна иметь class cashback-claim-approve-tx'
        );
        $this->assertMatchesRegularExpression(
            '/class="[^"]*cashback-claim-approve-tx[^"]*"[^>]*\sdata-claim-id="<\?php\s+echo\s+esc_attr/',
            $src,
            'trigger-кнопка должна нести data-claim-id (esc_attr) для JS-делегата'
        );
    }

    public function test_legacy_approve_action_button_removed(): void
    {
        $src = $this->admin_src();
        // F-S7-NO-MANUAL-CREDIT: legacy `data-action="approved"` через
        // claims_admin_transition меняет только статус, не создаёт tx — это
        // оригинальный баг. Должен быть удалён, чтобы admin не имел способа
        // одобрить claim без парной транзакции.
        $this->assertDoesNotMatchRegularExpression(
            '/data-action="approved"/',
            $src,
            'legacy approve-кнопка (data-action="approved") должна быть удалена — её заменяет combined modal'
        );
    }

    public function test_render_page_calls_approve_modal_renderer(): void
    {
        $src = $this->admin_src();
        $this->assertMatchesRegularExpression(
            '/(\$this->|self::)render_claim_approve_modal\(\)/',
            $src,
            'render_page должен вызывать render_claim_approve_modal в footer'
        );
    }

    // =========================================================================
    // HTML-разметка модала
    // =========================================================================

    public function test_modal_has_backdrop_with_correct_id(): void
    {
        $src = $this->admin_src();
        $this->assertStringContainsString(
            'id="cashback-claim-approve-tx-backdrop"',
            $src,
            'модал должен иметь backdrop с id cashback-claim-approve-tx-backdrop'
        );
    }

    public function test_modal_dialog_has_aria_attributes(): void
    {
        $src = $this->admin_src();
        $this->assertMatchesRegularExpression(
            '/role="dialog"\s+aria-modal="true"\s+aria-labelledby="cashback-claim-approve-tx-title"/',
            $src,
            'dialog должен иметь role/aria-modal/aria-labelledby (a11y)'
        );
    }

    public function test_modal_has_comission_input_with_strict_pattern(): void
    {
        $src = $this->admin_src();
        $this->assertMatchesRegularExpression(
            '/id="cashback-claim-approve-tx-comission"[\s\S]{0,300}name="comission"/',
            $src,
            'модал должен содержать input#cashback-claim-approve-tx-comission name="comission"'
        );
        $this->assertMatchesRegularExpression(
            '/id="cashback-claim-approve-tx-comission"[\s\S]{0,400}pattern="\^\\\\d\+\(\\\\\.\\\\d\{1,2\}\)\?\$"/',
            $src,
            'comission input должен иметь pattern совпадающий с серверной regex'
        );
        $this->assertMatchesRegularExpression(
            '/id="cashback-claim-approve-tx-comission"[\s\S]{0,400}required/',
            $src,
            'comission input должен быть required'
        );
    }

    public function test_modal_funds_ready_dropdown_has_three_options(): void
    {
        $src = $this->admin_src();
        $this->assertMatchesRegularExpression(
            '/id="cashback-claim-approve-tx-funds-ready"[\s\S]{0,200}name="funds_ready"[\s\S]{0,40}required/',
            $src,
            'select#cashback-claim-approve-tx-funds-ready name="funds_ready" required'
        );
        // Default + Yes + No.
        $this->assertMatchesRegularExpression(
            '/<option value=""[\s\S]{0,80}\'Выберите вариант\'/u',
            $src,
            'default option «Выберите вариант» с value=""'
        );
        $this->assertMatchesRegularExpression(
            '/<option value="1">[\s\S]{0,80}\'Да\'/u',
            $src,
            'option «Да» c value=1'
        );
        $this->assertMatchesRegularExpression(
            '/<option value="0">[\s\S]{0,80}\'Нет\'/u',
            $src,
            'option «Нет» с value=0'
        );
    }

    public function test_modal_has_cancel_and_submit_buttons(): void
    {
        $src = $this->admin_src();
        $this->assertMatchesRegularExpression(
            '/data-role="cancel"/',
            $src,
            'модал должен содержать button с data-role="cancel"'
        );
        $this->assertMatchesRegularExpression(
            '/data-role="submit"/',
            $src,
            'модал должен содержать button с data-role="submit"'
        );
    }

    public function test_modal_has_no_preview_load_block(): void
    {
        $src = $this->admin_src();
        // Claim уже на странице — preview не нужен (отличие от stuck-claim модала).
        $this->assertDoesNotMatchRegularExpression(
            '/id="cashback-claim-approve-tx-backdrop"[\s\S]+?cashback_stuck_claim_load/',
            $src,
            'модал не должен делать preview-AJAX (claim уже отрендерен на странице)'
        );
    }

    // =========================================================================
    // Enqueue + localize
    // =========================================================================

    public function test_enqueues_approve_tx_js_and_css(): void
    {
        $src = $this->admin_src();
        $this->assertStringContainsString(
            'admin-claim-approve-tx.js',
            $src,
            'claims-admin должен enqueue-ить admin-claim-approve-tx.js'
        );
        $this->assertStringContainsString(
            'admin-stuck-claim-tx.css',
            $src,
            'CSS переиспользуем из stuck-claim flow (admin-stuck-claim-tx.css)'
        );
    }

    public function test_localize_script_uses_correct_var_and_nonce(): void
    {
        $src = $this->admin_src();
        $this->assertStringContainsString(
            "'cashbackClaimApproveTx'",
            $src,
            "wp_localize_script должен передавать данные в window.cashbackClaimApproveTx"
        );
        $this->assertStringContainsString(
            "wp_create_nonce('cashback_stuck_claim_nonce')",
            $src,
            'localize должен включать nonce cashback_stuck_claim_nonce (тот же что у backend handler)'
        );
        $this->assertMatchesRegularExpression(
            "/'ajaxUrl'\s*=>\s*admin_url\(\s*'admin-ajax\.php'\s*\)/",
            $src,
            'localize должен включать ajaxUrl'
        );
    }

    // =========================================================================
    // JS asset
    // =========================================================================

    public function test_js_asset_exists(): void
    {
        $path = dirname(__DIR__, 3) . '/assets/js/admin-claim-approve-tx.js';
        $this->assertFileExists($path, 'JS asset admin-claim-approve-tx.js должен существовать');
    }

    public function test_js_posts_to_combined_action(): void
    {
        $src = $this->js_src();
        $this->assertStringContainsString(
            "'cashback_claim_approve_with_tx'",
            $src,
            'JS должен слать action=cashback_claim_approve_with_tx (combined backend handler)'
        );
        // Не должен отправлять старый stuck-claim-tx action — это другой flow.
        $this->assertStringNotContainsString(
            'cashback_stuck_claim_create_tx',
            $src,
            'JS не должен ссылаться на stuck-claim-tx action — это другой flow'
        );
        $this->assertStringNotContainsString(
            'cashback_stuck_claim_load',
            $src,
            'JS не должен делать preview-load (claim уже на странице)'
        );
    }

    public function test_js_uses_localize_var_window_cashback_claim_approve_tx(): void
    {
        $src = $this->js_src();
        $this->assertStringContainsString(
            'window.cashbackClaimApproveTx',
            $src,
            'JS должен читать конфиг из window.cashbackClaimApproveTx'
        );
    }

    public function test_js_delegates_clicks_on_trigger_class(): void
    {
        $src = $this->js_src();
        $this->assertMatchesRegularExpression(
            "/closest\(\s*['\"]\\.cashback-claim-approve-tx['\"]\s*\)/",
            $src,
            'JS должен делегировать клики на .cashback-claim-approve-tx (кнопка внутри AJAX-loaded HTML)'
        );
    }

    public function test_js_validates_funds_ready_strict_strings(): void
    {
        $src = $this->js_src();
        $this->assertMatchesRegularExpression(
            "/fundsReady\s*!==\s*'0'\s*&&\s*fundsReady\s*!==\s*'1'/",
            $src,
            'client-side: funds_ready должен быть строго строкой 0 или 1'
        );
    }

    public function test_js_validates_comission_regex_and_positive(): void
    {
        $src = $this->js_src();
        $this->assertMatchesRegularExpression(
            '#/\^\\\\d\+\(\\\\\.\\\\d\{1,2\}\)\?\$/#',
            $src,
            'JS должен валидировать comission той же regex что и сервер'
        );
        $this->assertMatchesRegularExpression(
            '/parseFloat\(\s*comission\s*\)\s*<=\s*0/',
            $src,
            'JS должен отклонять comission <= 0'
        );
    }

    public function test_js_uses_crypto_random_uuid_for_request_id(): void
    {
        $src = $this->js_src();
        $this->assertStringContainsString(
            'window.crypto.randomUUID',
            $src,
            'request_id должен генерироваться через crypto.randomUUID (с fallback)'
        );
        $this->assertMatchesRegularExpression(
            '/body\.append\(\s*[\'"]request_id[\'"]/',
            $src,
            'submit должен включать request_id (server-side дедуп)'
        );
    }

    public function test_js_has_focus_trap_and_escape(): void
    {
        $src = $this->js_src();
        $this->assertStringContainsString(
            "'Escape'",
            $src,
            'Escape должен закрывать модал'
        );
        $this->assertStringContainsString(
            'trapFocus',
            $src,
            'Tab-focus-trap должен быть реализован'
        );
    }

    public function test_js_reloads_after_success(): void
    {
        $src = $this->js_src();
        $this->assertMatchesRegularExpression(
            '/window\.location\.reload\(\)/',
            $src,
            'после успешного approve должен быть reload (claim уйдёт из видимого списка)'
        );
    }

    public function test_js_exposes_global_object(): void
    {
        $src = $this->js_src();
        $this->assertMatchesRegularExpression(
            '/window\.CashbackClaimApproveTx\s*=/',
            $src,
            'JS должен экспортировать window.CashbackClaimApproveTx с open/close (зеркало stuck-claim flow)'
        );
    }
}
