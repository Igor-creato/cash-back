<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 12a ADR — uninstall cleanup completeness.
 *
 * Closes F-2-004 (fragment): социальные таблицы, опции и cron-хук модуля social-auth
 * отсутствуют в uninstall.php → после удаления плагина остаются orphan-данные
 * (включая зашифрованные refresh-токены в cashback_social_tokens).
 *
 * Контракт (TDD RED phase):
 *  1. `uninstall.php` объявляет DROP для трёх таблиц social-auth:
 *     cashback_social_links, cashback_social_tokens, cashback_social_pending.
 *  2. `uninstall.php` удаляет три опции модуля:
 *     cashback_social_enabled, cashback_social_provider_yandex, cashback_social_provider_vkid.
 *  3. `uninstall.php` отменяет cron-хук `cashback_social_cleanup_pending_cron`.
 *  4. Regression guard: unlink ключа шифрования (.cashback-encryption-key.php) остаётся.
 *
 * Тест текстовый — читает uninstall.php как файл и ищет подстроки. Интеграционный
 * прогон реального `wp_uninstall_plugin()` оставлен на ручную верификацию
 * (см. verification-раздел плана).
 *
 * Запуск:
 *   cd development/test && ./vendor/bin/phpunit --filter UninstallSocialCleanupTest
 */
#[Group('security')]
#[Group('group12')]
#[Group('uninstall')]
class UninstallSocialCleanupTest extends TestCase
{
    private string $uninstall_source = '';

    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $path        = $plugin_root . '/uninstall.php';

        self::assertFileExists($path, 'uninstall.php должен присутствовать в корне плагина');

        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Не удалось прочитать uninstall.php');
        $this->uninstall_source = $source;
    }

    // =====================================================================
    // 1. Социальные таблицы в DROP-списке
    // =====================================================================

    public function test_uninstall_drops_social_links_table(): void
    {
        self::assertStringContainsString(
            'cashback_social_links',
            $this->uninstall_source,
            'uninstall.php должен включать таблицу cashback_social_links в DROP (F-2-004)'
        );
    }

    public function test_uninstall_drops_social_tokens_table(): void
    {
        self::assertStringContainsString(
            'cashback_social_tokens',
            $this->uninstall_source,
            'uninstall.php должен включать таблицу cashback_social_tokens в DROP — иначе зашифрованные refresh_token остаются (F-2-004)'
        );
    }

    public function test_uninstall_drops_social_pending_table(): void
    {
        self::assertStringContainsString(
            'cashback_social_pending',
            $this->uninstall_source,
            'uninstall.php должен включать таблицу cashback_social_pending в DROP (F-2-004)'
        );
    }

    // =====================================================================
    // 2. Социальные опции в delete_option списке
    // =====================================================================

    public function test_uninstall_deletes_social_enabled_option(): void
    {
        self::assertMatchesRegularExpression(
            "/['\"]cashback_social_enabled['\"]/",
            $this->uninstall_source,
            'uninstall.php должен удалять опцию cashback_social_enabled (F-2-004)'
        );
    }

    public function test_uninstall_deletes_yandex_provider_option(): void
    {
        self::assertMatchesRegularExpression(
            "/['\"]cashback_social_provider_yandex['\"]/",
            $this->uninstall_source,
            'uninstall.php должен удалять опцию cashback_social_provider_yandex (F-2-004)'
        );
    }

    public function test_uninstall_deletes_vkid_provider_option(): void
    {
        self::assertMatchesRegularExpression(
            "/['\"]cashback_social_provider_vkid['\"]/",
            $this->uninstall_source,
            'uninstall.php должен удалять опцию cashback_social_provider_vkid (F-2-004)'
        );
    }

    // =====================================================================
    // 3. Cron-хук social-auth отменяется
    // =====================================================================

    public function test_uninstall_unschedules_social_cleanup_cron(): void
    {
        self::assertMatchesRegularExpression(
            "/['\"]cashback_social_cleanup_pending_cron['\"]/",
            $this->uninstall_source,
            'uninstall.php должен отменять cron-хук cashback_social_cleanup_pending_cron (F-2-004)'
        );
    }

    // =====================================================================
    // 4. Regression guard — удаление ключа шифрования
    // =====================================================================

    public function test_uninstall_still_deletes_encryption_key_file(): void
    {
        self::assertStringContainsString(
            '.cashback-encryption-key.php',
            $this->uninstall_source,
            'Regression: uninstall.php должен по-прежнему удалять файл ключа шифрования'
        );
        self::assertStringContainsString(
            'unlink(',
            $this->uninstall_source,
            'Regression: unlink() для ключа шифрования должен остаться'
        );
    }
}
