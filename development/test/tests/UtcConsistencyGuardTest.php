<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * UTC-everywhere guard: статически сканирует production-исходники плагина
 * и fail'ит при появлении timezone-небезопасных паттернов.
 *
 * Производит проверку только по code-токенам (T_STRING, T_CONSTANT_ENCAPSED_STRING,
 * etc.); комментарии (T_COMMENT, T_DOC_COMMENT) пропускаются — там можно
 * обсуждать запрещённые паттерны в documentation.
 *
 * См. ADR: obsidian/knowledge/decisions/utc-everywhere.md
 */
#[Group('timezone-consistency')]
#[Group('utc-everywhere')]
final class UtcConsistencyGuardTest extends TestCase
{
    /**
     * Корни сканирования: всё, что под плагином, кроме vendor/тестов/IDE-cache.
     */
    private const EXCLUDED_DIRS = [
        '/development/',           // тестовые fixtures могут содержать NOW()/current_time
        '/includes/tests/',        // wp-cli smoke-тесты (test-cashback-*.php)
        '/assets/vendor/',          // FingerprintJS / третьи lib'ы
        '/vendor/',                 // composer-зависимости
        '/node_modules/',
        '/obsidian/',               // knowledge vault
        '/context/',                // legacy-документация
    ];

    /**
     * Файлы-исключения: helper и историко-комментированный сервис, чьи docblock'и
     * описывают запрещённые паттерны намеренно.
     */
    private const EXCLUDED_FILES = [
        'includes/class-cashback-time.php',                    // helper docblock описывает паттерны
        'includes/class-cashback-click-session-service.php',   // history-комментарий о timezone-баге
        'cashback-plugin.php',                                  // мой комментарий о helper подключении
    ];

    /**
     * @return iterable<string>
     */
    private function plugin_php_files(): iterable
    {
        $root = dirname(__DIR__, 3);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $f) {
            $path = (string) $f;
            if (substr($path, -4) !== '.php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
            foreach (self::EXCLUDED_DIRS as $dir) {
                if (strpos('/' . $rel, $dir) === 0 || strpos($rel, ltrim($dir, '/')) === 0) {
                    continue 2;
                }
            }
            if (in_array($rel, self::EXCLUDED_FILES, true)) {
                continue;
            }
            yield $path;
        }
    }

    /**
     * Извлекает только code-токены, склеивая их в строку с разделителем.
     * Комментарии и whitespace отбрасываются — паттерны в docblock'ах не ловятся.
     */
    private function code_only(string $source): string
    {
        $tokens = token_get_all($source);
        $out    = '';
        foreach ($tokens as $tok) {
            if (is_array($tok)) {
                $type = $tok[0];
                if ($type === T_COMMENT || $type === T_DOC_COMMENT || $type === T_WHITESPACE || $type === T_INLINE_HTML) {
                    continue;
                }
                $out .= $tok[1] . "\x1F";
            } else {
                $out .= $tok . "\x1F";
            }
        }
        return $out;
    }

    public function test_no_current_time_mysql_in_production_code(): void
    {
        $offenders = [];
        foreach ($this->plugin_php_files() as $path) {
            $code = $this->code_only((string) file_get_contents($path));
            // current_time('mysql') без второго аргумента — производит зону сайта,
            // не UTC. Используй Cashback_Time::now_mysql().
            if (preg_match('/current_time\x1F\(\x1F[\'"]\x1Fmysql\x1F[\'"]\x1F\)/', $code)) {
                $offenders[] = $path;
            }
        }
        self::assertSame(
            [],
            $offenders,
            "current_time('mysql') без `, true` пишет в зоне сайта, не UTC. Используй Cashback_Time::now_mysql():\n  - "
            . implode("\n  - ", $offenders)
        );
    }

    public function test_no_bare_now_in_sql(): void
    {
        $offenders = [];
        foreach ($this->plugin_php_files() as $path) {
            $raw = (string) file_get_contents($path);
            // Сначала убираем комментарии (NOW() в тексте — допустимо),
            // потом ищем NOW() в любом строковом литерале / открытом коде.
            $code = $this->code_only($raw);
            if (preg_match('/\bNOW\x1F\(\x1F\)/', $code)) {
                $offenders[] = $path;
            }
        }
        self::assertSame(
            [],
            $offenders,
            "NOW() в SQL отдаёт server local time. Используй UTC_TIMESTAMP() (или UTC_TIMESTAMP(6)):\n  - "
            . implode("\n  - ", $offenders)
        );
    }

    public function test_no_gmdate_strtotime_display_pattern(): void
    {
        $offenders = [];
        foreach ($this->plugin_php_files() as $path) {
            $raw  = (string) file_get_contents($path);
            $code = $this->code_only($raw);
            // gmdate(format, strtotime($var)) — отображение в UTC без локализации.
            // Должно быть Cashback_Time::display() или wp_date()/date_i18n().
            if (preg_match('/gmdate\x1F\(\x1F[\'"][^\'"\x1F]+[\'"]\x1F,\x1Fstrtotime\x1F\(\x1F\$/', $code)) {
                $offenders[] = $path;
            }
        }
        self::assertSame(
            [],
            $offenders,
            "gmdate(\$fmt, strtotime(\$db_value)) отображает UTC без локализации. Используй Cashback_Time::display() / wp_date():\n  - "
            . implode("\n  - ", $offenders)
        );
    }
}
