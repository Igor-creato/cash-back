<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Structural regression-guard для Registration Gate.
 *
 * Цель: страховать, что в КАЖДОЙ из 5 точек регистрации остаётся вызов
 * Cashback_Registration_Gate::is_allowed(). Если кто-то его уберёт при
 * рефакторинге — тест упадёт.
 *
 * Это grep по исходникам — он не запускает код, только проверяет наличие
 * паттернов в строках файла.
 */
#[Group('registration-gate')]
#[Group('structural')]
final class RegistrationGateStructuralTest extends TestCase
{
    private string $plugin_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin_root = dirname(__DIR__, 3);
    }

    public function test_sc_auth_pages_register_post_handler_has_gate(): void
    {
        $source = $this->read('includes/sc-auth-pages/class-sc-auth-pages-register.php');
        $this->assertStringContainsString(
            'Cashback_Registration_Gate::is_allowed',
            $source,
            'POST /register/ handler должен проверять Cashback_Registration_Gate'
        );
    }

    public function test_sc_auth_pages_shortcode_register_has_gate(): void
    {
        $source = $this->read('includes/sc-auth-pages/class-sc-auth-pages-shortcodes.php');
        $this->assertStringContainsString(
            'Cashback_Registration_Gate::is_allowed',
            $source,
            'render_register shortcode должен проверять Cashback_Registration_Gate'
        );
        $this->assertStringContainsString(
            'form-register-disabled.php',
            $source,
            'render_register должен подключать disabled-template при выкл'
        );
    }

    public function test_form_register_disabled_template_exists(): void
    {
        $path = $this->plugin_root . '/includes/sc-auth-pages/templates/form-register-disabled.php';
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);
        $this->assertStringContainsString('$denial_message', $source);
        $this->assertStringContainsString('$login_url', $source);
    }

    public function test_form_login_template_guards_register_links(): void
    {
        $source = $this->read('includes/sc-auth-pages/templates/form-login.php');
        $this->assertStringContainsString(
            'Cashback_Registration_Gate',
            $source,
            'form-login.php должен скрывать ссылки «Регистрация» при выкл'
        );
        $this->assertStringContainsString('$sc_login_show_register', $source);
    }

    public function test_social_auth_renderer_has_register_context_gate(): void
    {
        $source = $this->read('includes/social-auth/class-social-auth-renderer.php');
        $this->assertStringContainsString(
            "\$context === 'register'",
            $source
        );
        $this->assertStringContainsString(
            'Cashback_Registration_Gate::is_allowed',
            $source,
            'render_buttons должен скрывать кнопки на /register/ при выкл'
        );
    }

    public function test_social_auth_account_manager_has_all_three_gates(): void
    {
        $source = $this->read('includes/social-auth/class-social-auth-account-manager.php');

        // Минимум 4 вхождения: handle_callback, handle_email_prompt_submission,
        // handle_register_consent_submission, create_pending_user_and_link (DiD).
        $count = substr_count($source, 'Cashback_Registration_Gate::is_allowed');
        $this->assertGreaterThanOrEqual(
            4,
            $count,
            'Account_Manager должен иметь как минимум 4 gate-проверки (callback dispatch + email_prompt + consent_submission + create_pending_user_and_link)'
        );

        // callback_dispatch audit stage.
        $this->assertStringContainsString(
            "'callback_dispatch'",
            $source,
            'handle_callback должен логировать stage=callback_dispatch при отказе'
        );
    }

    public function test_social_auth_router_get_handlers_have_friendly_gate(): void
    {
        $source = $this->read('includes/social-auth/class-social-auth-router.php');

        // Gate стоит в GET HTML-handlers (handle_email_prompt_form +
        // handle_register_consent_form), а НЕ в permission_callback — это
        // защищает от опасного «голого REST 403 JSON» для юзера, попавшего на
        // ссылку после OAuth-flow.
        $this->assertStringContainsString(
            'Cashback_Registration_Gate::is_allowed',
            $source,
            'GET HTML-handlers должны проверять gate внутри callback'
        );
        $this->assertStringContainsString(
            "redirect_to_login_with_error('registration_disabled')",
            $source,
            'отказ должен редиректить юзера на /login/ с flash-сообщением'
        );
        // POST routes имеют permission_callback => __return_true (gate внутри
        // Account_Manager). Это намеренно — POST callback re-render'ит форму с
        // понятным сообщением вместо JSON 403.
        $this->assertStringNotContainsString(
            "'permission_callback' => \$registration_gate",
            $source,
            'permission_callback НЕ должен использовать gate (вызовет REST 403 JSON)'
        );
    }

    public function test_social_auth_router_maps_registration_disabled_error_code(): void
    {
        $source = $this->read('includes/social-auth/class-social-auth-router.php');
        // Router должен пробрасывать error_code из result в URL вместо generic
        // 'account_error', чтобы пользователь после OAuth-flow увидел понятное
        // сообщение «Регистрация недоступна».
        $this->assertStringContainsString(
            "\$result['error_code']",
            $source,
            'handle_callback должен читать error_code из Account_Manager result'
        );
        $this->assertStringContainsString(
            "'registration_disabled'",
            $source,
            'resolve_flash_message должен иметь case registration_disabled'
        );
    }

    public function test_account_manager_gates_return_error_code(): void
    {
        $source = $this->read('includes/social-auth/class-social-auth-account-manager.php');
        // Минимум 3 вхождения 'error_code' => 'registration_disabled' для трёх
        // публичных handler'ов (handle_callback dispatch, handle_email_prompt_submission,
        // handle_register_consent_submission). DiD-guard в create_pending_user_and_link
        // возвращает 'error' string и не использует error_code (caller'ы уже отбили).
        $count = substr_count($source, "'registration_disabled'");
        $this->assertGreaterThanOrEqual(
            3,
            $count,
            'gate-возвраты в Account_Manager должны включать error_code=registration_disabled (минимум 3 callsite)'
        );
    }

    public function test_social_auth_redirect_uses_sc_auth_pages_login_url(): void
    {
        $source = $this->read('includes/social-auth/class-social-auth-router.php');
        // redirect_to_login_with_* должен таргетить наш /login/ напрямую, иначе
        // sc-auth-pages Redirector упакует cashback_social_* внутрь redirect_to и
        // юзер увидит чистую форму без сообщения. Helper resolve_login_base_url()
        // отдаёт SC Auth Pages URL при наличии класса.
        $this->assertStringContainsString(
            'resolve_login_base_url',
            $source,
            'social-auth router должен иметь helper для /login/ URL'
        );
        $this->assertStringContainsString(
            'Cashback_SC_Auth_Pages_Login::get_login_url',
            $source,
            'resolve_login_base_url должен использовать SC Auth Pages URL когда доступен'
        );
    }

    public function test_sc_auth_pages_login_shortcode_consumes_social_flash(): void
    {
        $source = $this->read('includes/sc-auth-pages/class-sc-auth-pages-shortcodes.php');
        // render_login должен читать social-auth flash и выводить его прямо в
        // HTML — БЕЗ wc_add_notice, потому что wc_add_notice создаёт WC session
        // на каждом анонимном GET (DoS-amplification через wp_woocommerce_sessions).
        $this->assertStringContainsString(
            'Cashback_Social_Auth_Router::resolve_flash_message',
            $source,
            'render_login должен читать social-auth flash через static helper'
        );
        $this->assertStringContainsString(
            'print_social_auth_flash_html',
            $source,
            'render_login должен использовать stateless HTML render для flash'
        );
        // Защита от регрессии: ранее использовали wc_add_notice для flash, что
        // открывало amplification-vector. Убеждаемся что paттерн не вернулся.
        $this->assertStringNotContainsString(
            'inject_social_auth_flash_notice',
            $source,
            'старый wc_add_notice-based helper НЕ должен присутствовать'
        );
    }

    public function test_account_manager_burns_pending_tokens_on_gate_block(): void
    {
        $source = $this->read('includes/social-auth/class-social-auth-account-manager.php');
        // Kill-switch semantics: при выкл. регистрации pending-токены должны
        // сгорать через consume_pending, иначе атакующий сможет завершить
        // регистрацию после re-enable (gate работал бы как pause, не stop).
        $count = substr_count($source, 'Cashback_Social_Auth_DB::consume_pending($token)');
        // Минимум 2 burn-callsite'а: handle_email_prompt_submission и
        // handle_register_consent_submission. (Существующие caller'ы
        // consume_pending в нормальном flow вызываются через переменную
        // $pending = consume_pending — не считаются.)
        $this->assertGreaterThanOrEqual(
            2,
            $count,
            'gate в email_prompt и register_consent submission должны сжигать pending-токен'
        );
    }

    public function test_router_get_handlers_burn_pending_tokens_on_gate_block(): void
    {
        $source = $this->read('includes/social-auth/class-social-auth-router.php');
        // GET HTML-handlers (email-prompt-form, register-consent-form) при выкл.
        // регистрации должны сжигать pending-токен — иначе ссылка в почте
        // юзера/бота останется валидной до TTL, и после re-enable админом
        // POST/confirm flow завершится успешно (sleeper-vector).
        $count = substr_count($source, 'Cashback_Social_Auth_DB::consume_pending($token)');
        $this->assertGreaterThanOrEqual(
            2,
            $count,
            'handle_email_prompt_form и handle_register_consent_form должны сжигать pending-токен при отказе'
        );
    }

    public function test_email_verify_finish_refuses_activation_when_registration_disabled(): void
    {
        $source = $this->read('includes/social-auth/class-social-auth-account-manager.php');
        // KIND_EMAIL_VERIFY sleeper-vector: pending verify-email link уже выдан
        // юзеру в почту. Если админ выключил регистрацию ПОСЛЕ отправки письма,
        // email_verify_finish() должен отказать активацию и удалить pending user
        // + link, чтобы flow не возобновился после re-enable.
        $this->assertMatchesRegularExpression(
            '/email_verify_finish.*Cashback_Registration_Gate::is_allowed/s',
            $source,
            'email_verify_finish должен проверять Registration Gate'
        );
        $this->assertMatchesRegularExpression(
            '/email_verify_finish.*wp_delete_user\(\$user_id\)/s',
            $source,
            'email_verify_finish при gate-trigger должен удалить pending user'
        );
        $this->assertMatchesRegularExpression(
            '/email_verify_finish.*Cashback_Social_Auth_DB::delete_link/s',
            $source,
            'email_verify_finish при gate-trigger должен удалить link'
        );
    }

    public function test_settings_admin_registers_users_can_register_option(): void
    {
        $source = $this->read('admin/class-cashback-settings-admin.php');
        $this->assertStringContainsString(
            "'users_can_register'",
            $source,
            'Settings admin должен регистрировать опцию users_can_register'
        );
        $this->assertStringContainsString(
            'render_field_registration',
            $source,
            'Settings admin должен иметь render_field_registration callback'
        );
        $this->assertStringContainsString(
            'cashback_settings_registration',
            $source,
            'должна быть отдельная section «Регистрация»'
        );
    }

    public function test_plugin_main_includes_gate_helper(): void
    {
        $source = $this->read('cashback-plugin.php');
        $this->assertStringContainsString(
            'includes/auth/class-cashback-registration-gate.php',
            $source,
            'helper должен подключаться через require_file в load_dependencies'
        );
    }

    private function read( string $relative_path ): string
    {
        $abs = $this->plugin_root . '/' . ltrim($relative_path, '/');
        $this->assertFileExists($abs);
        return (string) file_get_contents($abs);
    }
}
