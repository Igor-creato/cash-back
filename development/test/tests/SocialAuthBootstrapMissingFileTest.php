<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Группа 12b ADR — tolerance policy для отсутствующих файлов social-auth модуля.
 *
 * Closes F-15-002 (social-auth-bootstrap: silent skip при missing file).
 *
 * Текущее поведение (до рефактора):
 *   foreach ($files as $file) {
 *       if (file_exists($file)) {
 *           require_once $file;
 *       }
 *   }
 *   // silent skip: ни error_log, ни admin_notice, ни флага.
 *
 * Контракт (TDD RED):
 *  1. `load_files()` должен регистрировать admin_notices hook, когда хотя бы один
 *     файл отсутствует, чтобы админ видел деградацию модуля (а не искал причину
 *     fatal в subsequent calls).
 *  2. Имя отсутствующего файла (basename, без полного пути) должно попадать в
 *     текст notice / error_log — без утечки абсолютных путей в UI.
 *  3. Модуль НЕ должен валиться fatal'ом при отсутствии файла — бизнес-логика
 *     «tolerance, but visible» сохраняется (совместимость с частичными deploy'ями).
 *  4. Существующий silent-skip паттерн (continue без fatal) должен уйти — вместо
 *     него осознанная регистрация notice.
 *
 * Тест source-grep: проверяет текст bootstrap-файла по regex'ам.
 */
#[Group('security')]
#[Group('group12')]
#[Group('social-auth')]
class SocialAuthBootstrapMissingFileTest extends TestCase
{
    private string $bootstrap_source = '';

    protected function setUp(): void
    {
        $plugin_root = dirname(__DIR__, 3);
        $path        = $plugin_root . '/includes/social-auth/class-social-auth-bootstrap.php';

        self::assertFileExists($path, 'Bootstrap должен присутствовать');

        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Не удалось прочитать bootstrap');
        $this->bootstrap_source = $source;
    }

    // =====================================================================
    // 1. admin_notices hook при отсутствующем файле
    // =====================================================================

    public function test_bootstrap_registers_admin_notices_hook_for_missing_files(): void
    {
        self::assertMatchesRegularExpression(
            "/add_action\s*\(\s*['\"]admin_notices['\"]/",
            $this->bootstrap_source,
            'F-15-002: при отсутствии файла модуля bootstrap должен регистрировать admin_notices hook, '
            . 'иначе админ не узнает о деградации (только fatal в subsequent calls).'
        );
    }

    // =====================================================================
    // 2. error_log c префиксом [cashback-social]
    // =====================================================================

    public function test_bootstrap_logs_missing_file_to_error_log(): void
    {
        // Ищем error_log(... '[cashback-social]' ... 'missing' или подобное).
        self::assertMatchesRegularExpression(
            "/error_log\s*\([^)]*\[cashback-social\][^)]*missing/i",
            $this->bootstrap_source,
            'F-15-002: missing file должен логироваться через error_log с префиксом [cashback-social] '
            . 'и ключевым словом "missing" — чтобы grep по логам на production был надёжным.'
        );
    }

    // =====================================================================
    // 3. Silent-skip паттерн УШЁЛ (regression-guard)
    // =====================================================================

    public function test_bootstrap_no_longer_silently_skips_missing_files(): void
    {
        // Было: foreach { if (file_exists($file)) { require_once $file; } }
        // Стало: должно быть else-branch с report (error_log + admin_notice) или
        // явный helper вызывается для missing.
        //
        // Критерий: в load_files() теперь присутствует слово "missing" (переменная
        // $missing_files / метод report_missing / ключ в state).
        $has_load_files = preg_match(
            "/public\s+static\s+function\s+load_files\s*\([^)]*\)\s*:\s*void\s*\{([\s\S]*?)^\s\s\s\s\}/m",
            $this->bootstrap_source,
            $matches
        );

        if ($has_load_files !== 1) {
            self::fail('load_files()-метод не найден — структура изменилась?');
        }

        $body = $matches[1];

        self::assertMatchesRegularExpression(
            "/missing/i",
            $body,
            'F-15-002: load_files() должен явно обрабатывать missing-случай (переменная / вызов helper), '
            . 'а не молча пропускать через пустой else.'
        );
    }

    // =====================================================================
    // 4. Модуль НЕ валится fatal'ом (regression guard — require_once остаётся
    //    под if(file_exists), fatal не вводим)
    // =====================================================================

    public function test_bootstrap_still_tolerates_missing_files_without_fatal(): void
    {
        // `require_once` должен оставаться под защитой `file_exists` — иначе
        // при частичном деплое получим fatal вместо деградации.
        self::assertMatchesRegularExpression(
            "/if\s*\(\s*file_exists\s*\(\s*\\\$file\s*\)\s*\)\s*\{[\s\S]*?require_once\s+\\\$file/",
            $this->bootstrap_source,
            'Regression: require_once должен остаться под if(file_exists) — fatal не вводим.'
        );
    }

    // =====================================================================
    // 5. Имя файла в notice — только basename (не абсолютный путь в UI)
    // =====================================================================

    public function test_bootstrap_notice_uses_basename_not_absolute_path(): void
    {
        // Ожидаем вызов basename() рядом с местом формирования notice / log.
        self::assertMatchesRegularExpression(
            "/basename\s*\(/",
            $this->bootstrap_source,
            'F-15-002: имя отсутствующего файла в UI/логе должно быть basename(), '
            . 'иначе абсолютный путь утекает в admin UI (information disclosure).'
        );
    }
}
