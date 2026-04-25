<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты ручного создания транзакции из зависшего approved claim.
 *
 * Source-based: парсят PHP/JS/CSS файлы и проверяют ключевые конструкции —
 * nonce, capability, idempotency, FOR UPDATE, INSERT mapping, audit-log,
 * dropdown «Готова к выплате?», обязательная валидация funds_ready, JS-модал.
 */
#[Group('reconciliation')]
#[Group('group-15')]
final class StuckClaimTxAjaxTest extends TestCase
{
    private function source(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $content = file_get_contents($path);
        $this->assertIsString($content, "{$rel} must be readable");
        return $content;
    }

    private function recon_admin_src(): string
    {
        return $this->source('admin/class-cashback-balance-reconciliation-admin.php');
    }

    // =========================================================================
    // Регистрация AJAX hook'ов + методы handler'ов
    // =========================================================================

    public function test_init_registers_load_ajax_hook(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'wp_ajax_cashback_stuck_claim_load'\s*,/",
            $this->recon_admin_src(),
            'init() должен регистрировать wp_ajax_cashback_stuck_claim_load'
        );
    }

    public function test_init_registers_create_ajax_hook(): void
    {
        $this->assertMatchesRegularExpression(
            "/add_action\s*\(\s*'wp_ajax_cashback_stuck_claim_create_tx'\s*,/",
            $this->recon_admin_src(),
            'init() должен регистрировать wp_ajax_cashback_stuck_claim_create_tx'
        );
    }

    public function test_handle_load_stuck_claim_method_exists(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+handle_load_stuck_claim\s*\(\s*\)\s*:\s*void/',
            $this->recon_admin_src(),
            'handle_load_stuck_claim должен быть public static function(): void'
        );
    }

    public function test_handle_create_stuck_claim_tx_method_exists(): void
    {
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+handle_create_stuck_claim_tx\s*\(\s*\)\s*:\s*void/',
            $this->recon_admin_src(),
            'handle_create_stuck_claim_tx должен быть public static function(): void'
        );
    }

    // =========================================================================
    // Защита: nonce + capability в обоих handler'ах
    // =========================================================================

    public function test_handlers_use_shared_nonce_action(): void
    {
        $src = $this->recon_admin_src();
        // Один nonce action на оба обработчика — load + create.
        $matches = preg_match_all(
            "/'cashback_stuck_claim_nonce'/",
            $src
        );
        $this->assertGreaterThanOrEqual(
            3,
            $matches,
            'cashback_stuck_claim_nonce должен встречаться минимум 3 раза (load verify, create verify, wp_create_nonce)'
        );
    }

    public function test_handlers_check_manage_options(): void
    {
        $src = $this->recon_admin_src();
        $count = preg_match_all(
            "/current_user_can\(\s*'manage_options'\s*\)/",
            $src
        );
        // get_stuck_claims (render_page), handle_load_stuck_claim, handle_create_stuck_claim_tx,
        // handle_manual_run, render_page (1 раз) — минимум 4.
        $this->assertGreaterThanOrEqual(
            4,
            $count,
            'manage_options должен проверяться в каждом из новых AJAX-обработчиков'
        );
    }

    // =========================================================================
    // Idempotency (Группа 5 ADR)
    // =========================================================================

    public function test_create_handler_uses_idempotency_claim_with_correct_scope(): void
    {
        $src = $this->recon_admin_src();
        $this->assertStringContainsString(
            'Cashback_Idempotency::claim',
            $src,
            'create-handler должен использовать Cashback_Idempotency::claim'
        );
        $this->assertStringContainsString(
            "'admin_stuck_claim_tx'",
            $src,
            'idempotency scope должен быть admin_stuck_claim_tx'
        );
        $this->assertStringContainsString(
            'Cashback_Idempotency::normalize_request_id',
            $src,
            'request_id должен пройти normalize_request_id'
        );
        $this->assertStringContainsString(
            'Cashback_Idempotency::store_result',
            $src,
            'store_result должен сохранять результат для idempotent retry'
        );
        $this->assertStringContainsString(
            'Cashback_Idempotency::forget',
            $src,
            'forget должен вызываться на ROLLBACK / валидационных отказах'
        );
    }

    // =========================================================================
    // Валидация comission + funds_ready
    // =========================================================================

    public function test_create_handler_validates_comission_strict_regex(): void
    {
        $src = $this->recon_admin_src();
        $this->assertMatchesRegularExpression(
            "#preg_match\(\s*'/\\^\\\\d\\+\\(\\\\\\.\\\\d\\{1,2\\}\\)\\?\\$/'#",
            $src,
            'comission должна валидироваться строгой regex ^\\d+(\\.\\d{1,2})?$'
        );
    }

    public function test_create_handler_rejects_zero_comission(): void
    {
        $src = $this->recon_admin_src();
        // bccomp comparison или float fallback — оба валидны.
        $this->assertMatchesRegularExpression(
            '/bccomp\(\s*\$raw_comission\s*,\s*[\'"]0[\'"]|float\)\s*\$raw_comission\s*>\s*0\.0/',
            $src,
            'комиссия должна быть строго > 0'
        );
    }

    public function test_create_handler_strict_string_funds_ready(): void
    {
        $src = $this->recon_admin_src();
        // Строгая строковая проверка ДО cast, иначе '2'/true/'' пройдут как 1.
        $this->assertMatchesRegularExpression(
            "/\\\$raw_funds_ready\s*!==\s*'0'\s*&&\s*\\\$raw_funds_ready\s*!==\s*'1'/",
            $src,
            'funds_ready должен сравниваться строго со строками 0 и 1'
        );
    }

    public function test_create_handler_returns_select_value_message(): void
    {
        $src = $this->recon_admin_src();
        // Точная фраза, согласованная с пользователем.
        $this->assertStringContainsString(
            "'Выберите значение'",
            $src,
            'при пустом funds_ready handler должен вернуть «Выберите значение»'
        );
    }

    // =========================================================================
    // Транзакция БД + FOR UPDATE
    // =========================================================================

    public function test_create_handler_wraps_writes_in_transaction(): void
    {
        $src = $this->recon_admin_src();
        $this->assertMatchesRegularExpression(
            '/handle_create_stuck_claim_tx[\s\S]+?START TRANSACTION[\s\S]+?COMMIT/i',
            $src,
            'INSERT должен быть обёрнут в START TRANSACTION/COMMIT'
        );
        $this->assertMatchesRegularExpression(
            '/ROLLBACK/',
            $src,
            'на исключение должен быть ROLLBACK'
        );
    }

    public function test_create_handler_locks_claim_for_update(): void
    {
        $src = $this->recon_admin_src();
        $this->assertMatchesRegularExpression(
            '/SELECT[\s\S]+?cashback_claims[\s\S]+?FOR UPDATE|claims_table[\s\S]+?FOR UPDATE/i',
            $src,
            'SELECT claim должен использовать FOR UPDATE'
        );
    }

    public function test_create_handler_locks_existing_tx_for_update(): void
    {
        $src = $this->recon_admin_src();
        $this->assertMatchesRegularExpression(
            '/SELECT id FROM %i WHERE user_id = %d AND click_id = %s LIMIT 1 FOR UPDATE/i',
            $src,
            'pre-flight check существующей транзакции должен использовать FOR UPDATE (race-safe)'
        );
    }

    public function test_create_handler_rejects_non_approved_claim(): void
    {
        $src = $this->recon_admin_src();
        $this->assertStringContainsString(
            "!== 'approved'",
            $src,
            'handler должен отказывать если claim не в статусе approved'
        );
    }

    // =========================================================================
    // INSERT mapping + триггеры
    // =========================================================================

    public function test_create_handler_uses_idempotency_key_manual_claim(): void
    {
        $src = $this->recon_admin_src();
        $this->assertMatchesRegularExpression(
            "/'manual_claim_'\s*\.\s*\\\$claim_id/",
            $src,
            'idempotency_key для tx должен быть manual_claim_<claim_id> (UNIQUE на cashback_transactions)'
        );
    }

    public function test_create_handler_sets_api_verified_one(): void
    {
        $src = $this->recon_admin_src();
        $this->assertMatchesRegularExpression(
            "/'api_verified'\s*=>\s*1/",
            $src,
            'api_verified должен ставиться = 1 (как если бы пришло по API)'
        );
    }

    public function test_create_handler_sets_order_status_completed(): void
    {
        $src = $this->recon_admin_src();
        $this->assertMatchesRegularExpression(
            "/'order_status'\s*=>\s*'completed'/",
            $src,
            "order_status должен быть 'completed' (claim approved 14+ дней)"
        );
    }

    public function test_create_handler_sets_currency_rub(): void
    {
        $src = $this->recon_admin_src();
        $this->assertMatchesRegularExpression(
            "/'currency'\s*=>\s*'RUB'/",
            $src,
            "currency должна быть 'RUB' по умолчанию"
        );
    }

    public function test_create_handler_sets_created_by_admin_one(): void
    {
        $src = $this->recon_admin_src();
        $this->assertMatchesRegularExpression(
            "/'created_by_admin'\s*=>\s*1/",
            $src,
            'created_by_admin должен ставиться = 1 (флаг для UI «Добавлена админом»)'
        );
        // Поле должно быть в списке %d-форматов, иначе $wpdb->insert пошлёт его как строку.
        $this->assertMatchesRegularExpression(
            "/'created_by_admin'[^\)]*?\),\s*true\s*\)\s*\)/",
            $src,
            'created_by_admin должен быть в списке %d-полей $wpdb->insert format'
        );
    }

    // =========================================================================
    // Schema + миграция created_by_admin
    // =========================================================================

    public function test_cashback_transactions_schema_has_created_by_admin(): void
    {
        $src = $this->source('mariadb.php');
        $this->assertMatchesRegularExpression(
            '/`created_by_admin`\s+tinyint\(1\)\s+NOT\s+NULL\s+DEFAULT\s+0/i',
            $src,
            'CREATE TABLE cashback_transactions должна включать created_by_admin tinyint(1) DEFAULT 0'
        );
    }

    public function test_migrate_add_transaction_created_by_admin_registered(): void
    {
        $src = $this->source('mariadb.php');
        $this->assertStringContainsString(
            '$instance->migrate_add_transaction_created_by_admin();',
            $src,
            'миграция должна вызываться в activate()'
        );
        $this->assertMatchesRegularExpression(
            '/public\s+function\s+migrate_add_transaction_created_by_admin\s*\(\s*\)\s*:\s*void/',
            $src,
            'метод migrate_add_transaction_created_by_admin должен быть public function(): void'
        );
        // Идемпотентность через INFORMATION_SCHEMA precheck.
        $this->assertMatchesRegularExpression(
            "/INFORMATION_SCHEMA\.COLUMNS[\s\S]+?'created_by_admin'/",
            $src,
            'миграция должна проверять наличие колонки через INFORMATION_SCHEMA до ALTER'
        );
        // Post-verify через SHOW COLUMNS — конвенция плагина для DDL с raw query.
        $this->assertMatchesRegularExpression(
            "/SHOW COLUMNS FROM %i LIKE %s[\s\S]+?'created_by_admin'/",
            $src,
            'миграция должна post-verify через SHOW COLUMNS'
        );
    }

    // =========================================================================
    // API validation: missing_api carries created_by_admin
    // =========================================================================

    public function test_api_client_passes_created_by_admin_in_missing_api(): void
    {
        $src = $this->source('includes/class-cashback-api-client.php');
        // Поле должно появиться в обоих местах построения $missing_api[]
        // (registered + unregistered ветки).
        $count = preg_match_all(
            "/'created_by_admin'\s*=>\s*isset\(\s*\\\$tx\['created_by_admin'\]\s*\)/",
            $src
        );
        $this->assertGreaterThanOrEqual(
            2,
            $count,
            'оба missing_api builders должны передавать created_by_admin (registered + unregistered)'
        );
    }

    public function test_api_client_selects_created_by_admin_column(): void
    {
        $src = $this->source('includes/class-cashback-api-client.php');
        $this->assertStringContainsString(
            't.created_by_admin',
            $src,
            'SELECT в validate_user должен включать t.created_by_admin'
        );
    }

    // =========================================================================
    // JS: thead + cell rendering
    // =========================================================================

    public function test_api_validation_js_thead_has_admin_column(): void
    {
        $src = $this->source('admin/js/api-validation.js');
        $this->assertStringContainsString(
            '<th>Добавлена админом</th>',
            $src,
            'thead missing_api таблицы должен содержать колонку «Добавлена админом»'
        );
    }

    public function test_api_validation_js_renders_green_bold_yes_for_admin_tx(): void
    {
        $src = $this->source('admin/js/api-validation.js');
        // Зелёным жирным «Да» — точная формулировка пользователя.
        $this->assertMatchesRegularExpression(
            '/parseInt\(\s*m\.created_by_admin\s*,\s*10\s*\)\s*===\s*1/',
            $src,
            'JS должен проверять created_by_admin === 1'
        );
        $this->assertMatchesRegularExpression(
            '/color:\s*#1f8f3a\s*;\s*font-weight:\s*bold\s*;[\s\S]{0,40}Да/u',
            $src,
            'для admin-tx должна рендериться «Да» зелёным жирным'
        );
    }

    public function test_create_handler_does_not_set_reference_id_or_cashback_in_insert(): void
    {
        $src = $this->recon_admin_src();
        // Эти поля проставляет триггер calculate_cashback_before_insert. Извлекаем
        // блок $insert_data = array(...); и проверяем что reference_id/cashback там
        // не упомянуты как ключи. (В audit-log details они присутствуют — это OK.)
        $matched = preg_match(
            '/\$insert_data\s*=\s*array\((.*?)\);/s',
            $src,
            $m
        );
        $this->assertSame(1, $matched, 'не нашёл блок $insert_data = array(...);');

        $insert_block = (string) $m[1];

        $this->assertDoesNotMatchRegularExpression(
            "/'reference_id'\s*=>/",
            $insert_block,
            'reference_id не должен передаваться в INSERT — его генерирует триггер'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'cashback'\s*=>/",
            $insert_block,
            'cashback не должен передаваться в INSERT — его рассчитывает триггер'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'applied_cashback_rate'\s*=>/",
            $insert_block,
            'applied_cashback_rate не должна передаваться — её ставит триггер из user profile'
        );
    }

    public function test_create_handler_reads_back_trigger_generated_fields(): void
    {
        $src = $this->recon_admin_src();
        // После INSERT нужно SELECT'ом прочесть reference_id/cashback, проставленные триггером.
        $this->assertMatchesRegularExpression(
            '/SELECT reference_id, cashback, applied_cashback_rate/',
            $src,
            'после INSERT handler должен прочитать значения, сгенерированные триггером'
        );
    }

    // =========================================================================
    // Audit-log
    // =========================================================================

    public function test_create_handler_writes_audit_log(): void
    {
        $src = $this->recon_admin_src();
        $this->assertStringContainsString(
            "'manual_tx_from_stuck_claim'",
            $src,
            "audit-action должен быть manual_tx_from_stuck_claim"
        );
        $this->assertStringContainsString(
            'Cashback_Encryption::write_audit_log',
            $src,
            'handler должен вызывать write_audit_log'
        );
    }

    // =========================================================================
    // Render: button и модал
    // =========================================================================

    public function test_render_stuck_claims_uses_button_not_redirect_link(): void
    {
        $src = $this->recon_admin_src();
        $this->assertStringContainsString(
            'cashback-stuck-create-tx',
            $src,
            'кнопка должна иметь class cashback-stuck-create-tx (триггер для JS)'
        );
        $this->assertMatchesRegularExpression(
            '/data-claim-id="<\?php\s+echo\s+esc_attr/',
            $src,
            'кнопка должна иметь data-claim-id'
        );
        // Старый редирект-URL должен быть удалён.
        $this->assertDoesNotMatchRegularExpression(
            '/prefill_from_claim/',
            $src,
            'старая redirect-сборка URL (prefill_from_claim) должна быть удалена'
        );
    }

    public function test_render_page_calls_modal_renderer(): void
    {
        $src = $this->recon_admin_src();
        $this->assertMatchesRegularExpression(
            '/self::render_stuck_claim_modal\(\)/',
            $src,
            'render_page должен вызывать render_stuck_claim_modal'
        );
    }

    public function test_modal_dropdown_has_three_options(): void
    {
        $src = $this->recon_admin_src();
        // Выпадающий список — обязательное требование пользователя.
        $this->assertStringContainsString(
            "Выберите вариант",
            $src,
            'модал должен содержать default option «Выберите вариант»'
        );
        // Между `>` и литералом «Да» в шаблоне рендерится esc_html_e блок;
        // матчим короткий участок до строкового литерала.
        $this->assertMatchesRegularExpression(
            '/<option value="1">[\s\S]{0,80}\'Да\'/u',
            $src,
            'option «Да» с value=1'
        );
        $this->assertMatchesRegularExpression(
            '/<option value="0">[\s\S]{0,80}\'Нет\'/u',
            $src,
            'option «Нет» с value=0'
        );
    }

    // =========================================================================
    // Enqueue assets
    // =========================================================================

    public function test_recon_admin_enqueues_stuck_claim_js_and_css(): void
    {
        $src = $this->recon_admin_src();
        $this->assertStringContainsString(
            'admin-stuck-claim-tx.js',
            $src,
            'recon-page должна enqueue-ить admin-stuck-claim-tx.js'
        );
        $this->assertStringContainsString(
            'admin-stuck-claim-tx.css',
            $src,
            'recon-page должна enqueue-ить admin-stuck-claim-tx.css'
        );
        $this->assertStringContainsString(
            "wp_create_nonce( 'cashback_stuck_claim_nonce'",
            $src,
            'wp_localize_script должен передавать nonce cashback_stuck_claim_nonce'
        );
    }

    // =========================================================================
    // JS-модал
    // =========================================================================

    public function test_stuck_claim_js_asset_exists(): void
    {
        $path = dirname(__DIR__, 3) . '/assets/js/admin-stuck-claim-tx.js';
        $this->assertFileExists($path, 'JS модала должен существовать');
    }

    public function test_stuck_claim_css_asset_exists(): void
    {
        $path = dirname(__DIR__, 3) . '/assets/css/admin-stuck-claim-tx.css';
        $this->assertFileExists($path, 'CSS модала должен существовать');
    }

    public function test_stuck_claim_js_posts_to_correct_actions(): void
    {
        $src = $this->source('assets/js/admin-stuck-claim-tx.js');
        $this->assertStringContainsString(
            "'cashback_stuck_claim_load'",
            $src,
            'fetch #1 должен отправлять action=cashback_stuck_claim_load'
        );
        $this->assertStringContainsString(
            "'cashback_stuck_claim_create_tx'",
            $src,
            'fetch #2 должен отправлять action=cashback_stuck_claim_create_tx'
        );
        $this->assertMatchesRegularExpression(
            '/body\.append\(\s*[\'"]request_id[\'"]/',
            $src,
            'submit должен включать request_id (server-side дедуп)'
        );
    }

    public function test_stuck_claim_js_validates_funds_ready_client_side(): void
    {
        $src = $this->source('assets/js/admin-stuck-claim-tx.js');
        $this->assertMatchesRegularExpression(
            "/fundsReady\s*!==\s*'0'\s*&&\s*fundsReady\s*!==\s*'1'/",
            $src,
            'JS должен валидировать funds_ready перед отправкой (без выбора → ошибка)'
        );
    }

    public function test_stuck_claim_js_has_focus_trap_and_escape(): void
    {
        $src = $this->source('assets/js/admin-stuck-claim-tx.js');
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

    public function test_stuck_claim_js_uses_crypto_random_uuid(): void
    {
        $src = $this->source('assets/js/admin-stuck-claim-tx.js');
        $this->assertStringContainsString(
            'window.crypto.randomUUID',
            $src,
            'request_id должен генерироваться через crypto.randomUUID (с fallback)'
        );
    }
}
