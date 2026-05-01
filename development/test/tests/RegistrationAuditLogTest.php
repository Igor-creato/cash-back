<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Структурный тест: BUG-02 (152-ФЗ ст. 9, audit gap на регистрации).
 *
 * Закрывает пробел audit_log: при регистрации через WooCommerce должны писаться
 * audit-события `user_registered` (один раз на регистрацию) и `consent_granted`
 * (по одному на каждый записанный consent_type в журнале согласий).
 *
 * Source-based regression: предохраняет от удаления вызовов при будущих
 * рефакторингах. Проверяет файл-источник на наличие правильных вызовов в
 * правильных местах. Не делает full functional test (для него нужна реальная
 * установка WP+WooCommerce), но гарантирует, что compliance-вызовы не теряются.
 */
#[Group('legal')]
#[Group('audit')]
#[Group('legal-compliance')]
final class RegistrationAuditLogTest extends TestCase
{
    private function registration_source(): string
    {
        $path = dirname(__DIR__, 3) . '/legal/class-cashback-legal-registration-checkboxes.php';
        $c    = file_get_contents($path);
        $this->assertIsString($c, 'class-cashback-legal-registration-checkboxes.php must be readable');
        return $c;
    }

    private function consent_manager_source(): string
    {
        $path = dirname(__DIR__, 3) . '/legal/class-cashback-legal-consent-manager.php';
        $c    = file_get_contents($path);
        $this->assertIsString($c, 'class-cashback-legal-consent-manager.php must be readable');
        return $c;
    }

    public function test_save_consents_on_registration_writes_user_registered_audit(): void
    {
        $src = $this->registration_source();

        $this->assertMatchesRegularExpression(
            '/Cashback_Encryption::write_audit_log\s*\(\s*[\'"]user_registered[\'"]/s',
            $src,
            'save_consents_on_registration() должен вызывать Cashback_Encryption::write_audit_log("user_registered", ...) — 152-ФЗ ст. 9 audit-trail на акт регистрации (BUG-02)'
        );
    }

    public function test_user_registered_audit_passes_user_entity(): void
    {
        $src = $this->registration_source();

        $this->assertMatchesRegularExpression(
            '/Cashback_Encryption::write_audit_log\s*\(\s*[\'"]user_registered[\'"]\s*,\s*\$user_id\s*,\s*[\'"]user[\'"]/s',
            $src,
            'audit user_registered должен иметь actor=$user_id + entity_type="user" — для cron-сверки и compliance отчётов'
        );
    }

    public function test_user_registered_audit_wrapped_in_try_catch(): void
    {
        $src = $this->registration_source();

        // G2: audit-вызовы должны быть non-throwing — обёрнуты в try/Throwable,
        // чтобы fail audit не сломал основной flow регистрации.
        $this->assertSame(
            1,
            preg_match(
                '/try\s*\{\s*Cashback_Encryption::write_audit_log\s*\(\s*[\'"]user_registered[\'"][^}]+\}\s*catch\s*\(\s*\\\\?Throwable/s',
                $src
            ),
            'audit user_registered должен быть в try { ... } catch (\\Throwable $e) — G2 non-throwing audit (regression-proof)'
        );
    }

    public function test_record_consent_writes_consent_granted_audit(): void
    {
        $src = $this->consent_manager_source();

        $this->assertMatchesRegularExpression(
            '/Cashback_Encryption::write_audit_log\s*\(\s*[\'"]consent_granted[\'"]/s',
            $src,
            'Cashback_Legal_Consent_Manager::record_consent() должен вызывать write_audit_log("consent_granted", ...) после успешной вставки в журнал — 152-ФЗ ст. 9 evidence chain (BUG-02)'
        );
    }

    public function test_consent_granted_audit_after_insert_log_row(): void
    {
        $src = $this->consent_manager_source();

        // G3: audit пишется ПОСЛЕ INSERT, чтобы не было orphan audit-row при duplicate request_id.
        $this->assertSame(
            1,
            preg_match(
                '/Cashback_Legal_DB::insert_log_row\s*\(\s*\$row\s*\)\s*;\s*'
                . '.*?'
                . 'Cashback_Encryption::write_audit_log\s*\(\s*[\'"]consent_granted[\'"]/s',
                $src
            ),
            'consent_granted audit должен вызываться ПОСЛЕ insert_log_row(), а не до — иначе duplicate request_id (UNIQUE) даст orphan audit-row (G3)'
        );
    }

    public function test_consent_granted_audit_wrapped_in_try_catch(): void
    {
        $src = $this->consent_manager_source();

        $this->assertSame(
            1,
            preg_match(
                '/try\s*\{\s*Cashback_Encryption::write_audit_log\s*\(\s*[\'"]consent_granted[\'"][^}]+\}\s*catch\s*\(\s*\\\\?Throwable/s',
                $src
            ),
            'audit consent_granted должен быть в try { ... } catch (\\Throwable $e) — G2 non-throwing audit'
        );
    }

    public function test_consent_granted_audit_passes_consent_log_entity(): void
    {
        $src = $this->consent_manager_source();

        $this->assertMatchesRegularExpression(
            '/Cashback_Encryption::write_audit_log\s*\(\s*[\'"]consent_granted[\'"]\s*,[^)]*[\'"]consent_log[\'"]/s',
            $src,
            'audit consent_granted должен иметь entity_type="consent_log" — нужно для сверки audit↔consent_log по entity_id'
        );
    }
}
