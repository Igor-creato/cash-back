<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 12b ADR — компенсация wp_insert_user при сбое save_link/save_pending.
 *
 * Closes F-13-004 (social-auth-account-manager: orphan user на полу-созданной
 * связке).
 *
 * Сценарий orphan'а (до рефактора):
 *   1. wp_insert_user() ← success, $user_id = N
 *   2. update_user_meta(N, META_PENDING, 1) ← success
 *   3. save_link(...) ← fail (0/negative)
 *   4. audit log stage='save_link' + return error
 *   5. Юзер N остаётся в wp_users с META_PENDING=1, без связки, без verify-токена
 *      — не может ни войти паролем (block_pending_login), ни пройти через OAuth
 *      (связки нет). Email-адрес залочен (UNIQUE в wp_users).
 *
 * Второй сценарий (после save_link, но до save_pending):
 *   1. wp_insert_user ← success
 *   2. save_link ← success, link_id = L
 *   3. save_pending(email_verify) ← fail (empty string)
 *   4. return error
 *   5. Остаётся orphan user + orphan link (связка без verify-процесса).
 *
 * Контракт компенсации (TDD RED):
 *  1. В error-branch после `save_link` fail (stage='save_link') должен быть
 *     wp_delete_user($user_id) — откат вставленного юзера.
 *  2. В error-branch после `save_pending` fail (verify_token === '') должен
 *     быть wp_delete_user($user_id) + Cashback_Social_Auth_DB::delete_link($link_id).
 *  3. Перед каждым компенсирующим удалением — audit log (stage='compensation_*').
 *  4. Позитивная ветка (всё прошло) НЕ вызывает wp_delete_user / delete_link.
 *
 * Тест source-grep: проверяет текст account-manager'а по regex'ам с
 * привязкой к существующим audit-маркерам (stage='save_link' и verify_token).
 */
#[Group('security')]
#[Group('group12')]
#[Group('social-auth')]
class SocialAuthCreatePendingUserOrphanTest extends TestCase
{
    private string $account_manager_source = '';

    /** @var array<int,string> Строки файла (1-based). */
    private array $lines = array();

    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $path        = $plugin_root . '/includes/social-auth/class-social-auth-account-manager.php';

        self::assertFileExists($path, 'account-manager должен присутствовать');

        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Не удалось прочитать account-manager');
        $this->account_manager_source = $source;
        $this->lines                  = array_merge(array( 0 => '' ), explode("\n", $source));
    }

    /**
     * Найти позицию первого совпадения regex и вернуть окно строк вокруг.
     */
    private function window_around( string $regex, int $before = 2, int $after = 12 ): ?string
    {
        if (preg_match($regex, $this->account_manager_source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $offset    = (int) $m[0][1];
        $line_no   = substr_count(substr($this->account_manager_source, 0, $offset), "\n") + 1;
        $start     = max(1, $line_no - $before);
        $end       = min(count($this->lines) - 1, $line_no + $after);
        $snippet   = array();
        for ($i = $start; $i <= $end; $i++) {
            $snippet[] = $this->lines[$i];
        }
        return implode("\n", $snippet);
    }

    // =====================================================================
    // 1. Компенсация при сбое save_link
    // =====================================================================

    public function test_save_link_failure_triggers_wp_delete_user(): void
    {
        $window = $this->window_around(
            "/'stage'\s*=>\s*'save_link'/",
            2,
            25
        );

        self::assertNotNull(
            $window,
            'Не найден audit-маркер stage=save_link — структура account-manager изменилась?'
        );

        self::assertMatchesRegularExpression(
            "/wp_delete_user\s*\(\s*\\\$user_id/",
            $window,
            'F-13-004: при сбое save_link должна быть компенсация через wp_delete_user($user_id) '
            . 'до return error. Иначе юзер с META_PENDING=1 остаётся в wp_users orphan\'ом.'
        );
    }

    // =====================================================================
    // 2. Компенсация при сбое save_pending (verify_token === '')
    // =====================================================================

    public function test_save_pending_failure_triggers_wp_delete_user_and_delete_link(): void
    {
        $window = $this->window_around(
            "/\\\$verify_token\s*===\s*''/",
            2,
            20
        );

        self::assertNotNull(
            $window,
            'Не найден маркер verify_token==="" — структура account-manager изменилась?'
        );

        self::assertMatchesRegularExpression(
            "/wp_delete_user\s*\(\s*\\\$user_id/",
            $window,
            'F-13-004: при пустом verify_token должна быть компенсация wp_delete_user($user_id) '
            . 'до return error — иначе orphan юзер без возможности verify.'
        );

        self::assertMatchesRegularExpression(
            "/Cashback_Social_Auth_DB::delete_link\s*\(\s*\\\$link_id/",
            $window,
            'F-13-004: вместе с wp_delete_user должна удаляться связка — иначе orphan link '
            . 'в cashback_social_links с external_id, блокирующий повторную попытку того же провайдер-аккаунта.'
        );
    }

    // =====================================================================
    // 3. Audit-маркер компенсации (чтобы incidents грепались)
    // =====================================================================

    public function test_compensation_is_audit_logged(): void
    {
        self::assertMatchesRegularExpression(
            "/'stage'\s*=>\s*'compensation/",
            $this->account_manager_source,
            'F-13-004: компенсирующие удаления должны иметь audit-маркер stage=compensation_* '
            . 'для post-mortem-grep (отличать от ручных admin-delete).'
        );
    }

    // =====================================================================
    // 4. Regression-guard: в позитивной ветке wp_delete_user НЕ вызывается
    // =====================================================================

    public function test_happy_path_does_not_call_wp_delete_user(): void
    {
        // Уникальный маркер happy-path: audit-лог с status=pending_verify
        // (встречается ровно один раз — в позитивной ветке create_pending_user_and_link).
        $marker     = "'status'   => 'pending_verify'";
        $marker_pos = strpos($this->account_manager_source, $marker);
        self::assertNotFalse(
            $marker_pos,
            'Не найден happy-path audit-маркер status=pending_verify — структура изменилась?'
        );

        // Окно: 5 строк ПЕРЕД маркером (захватывает audit-массив + send_verify_email_email
        // call) + 7 ПОСЛЕ (позитивный return). Не заходит выше save_pending-compensation.
        $marker_line = substr_count(substr($this->account_manager_source, 0, $marker_pos), "\n") + 1;
        $start       = max(1, $marker_line - 5);
        $end         = min(count($this->lines) - 1, $marker_line + 7);
        $snippet     = array();
        for ($i = $start; $i <= $end; $i++) {
            $snippet[] = $this->lines[$i];
        }
        $happy_path_window = implode("\n", $snippet);

        self::assertDoesNotMatchRegularExpression(
            "/wp_delete_user/",
            $happy_path_window,
            'Regression: happy-path окно вокруг audit-маркера pending_verify '
            . 'НЕ должно содержать wp_delete_user.'
        );
    }
}
