<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Структурные тесты для фикса промо-клик dedup'а (миграция v11).
 *
 * Контракт:
 *   - cashback_click_sessions ADD COLUMN promocode_id BIGINT UNSIGNED NULL
 *     → разделение dedup-key (user_id, product_id) на (user_id, product_id, promocode_id),
 *     чтобы промо-клик не reuse-ил товарную сессию (баг: купонный goto_link
 *     терялся, в click_log писался товарный affiliate_url).
 *   - cashback_promocode_clicks ADD COLUMN affiliate_url VARCHAR(2048) NULL
 *     → отображение партнёрского URL на админ-вкладке «Промо клики» для
 *     self-verification оператором.
 *   - do_activate(): SELECT FOR UPDATE отфильтровывает по promocode_id
 *     (IS NULL для product-кликов, = %d для промо-кликов); INSERT включает
 *     колонку promocode_id.
 *   - record_click_internal(): принимает optional ?string $affiliate_url и
 *     сохраняет в БД когда передан (graceful degrade на схеме без колонки —
 *     тот же паттерн что для click_id v9).
 *   - Cashback_Promocodes_Redirect: пробрасывает $result['affiliate_url'] в
 *     record_click_internal (success-путь). Fallback record_stat_only остаётся
 *     без affiliate_url (там URL неизвестен).
 *   - admin/click-log.php (вкладка «Промо клики»): SELECT pc.affiliate_url +
 *     render новой колонки «Партнёрский URL».
 *
 * Почему structural: миграции v8/v9/v10 уже зарекомендовали этот паттерн
 * (PromocodesV8MigrationStructuralTest как образец). Мокать INFORMATION_SCHEMA
 * хрупко (per memory feedback_alter_table_no_prepare).
 *
 * @group migration
 * @group promocodes
 * @group click-sessions
 */
#[Group('migration')]
#[Group('promocodes')]
#[Group('click-sessions')]
final class PromoSessionDedupV11StructuralTest extends TestCase
{
    private static string $plugin_root;
    private static string $mariadb_php;
    private static string $cashback_plugin_php;
    private static string $click_session_service_php;
    private static string $promocodes_tracker_php;
    private static string $promocodes_redirect_php;
    private static string $admin_click_log_php;

    public static function setUpBeforeClass(): void
    {
        self::$plugin_root               = dirname(__DIR__, 3);
        self::$mariadb_php               = file_get_contents(self::$plugin_root . '/mariadb.php');
        self::$cashback_plugin_php       = file_get_contents(self::$plugin_root . '/cashback-plugin.php');
        self::$click_session_service_php = file_get_contents(self::$plugin_root . '/includes/class-cashback-click-session-service.php');
        self::$promocodes_tracker_php    = file_get_contents(self::$plugin_root . '/includes/promocodes/class-cashback-promocodes-tracker.php');
        self::$promocodes_redirect_php   = file_get_contents(self::$plugin_root . '/includes/promocodes/class-cashback-promocodes-redirect.php');
        self::$admin_click_log_php       = file_get_contents(self::$plugin_root . '/admin/click-log.php');
    }

    // =====================================================================
    // 1. Миграция v11: метод существует, fast-path, ALTER, version bump.
    // =====================================================================

    public function test_mariadb_php_declares_migrate_v11_method(): void
    {
        $this->assertMatchesRegularExpression(
            '/public function migrate_promocodes_v11_session_promocode_id\s*\(\s*\)\s*:\s*void/i',
            self::$mariadb_php,
            'Должен быть public method migrate_promocodes_v11_session_promocode_id(): void'
        );
    }

    public function test_migration_v11_has_db_version_fast_path(): void
    {
        $this->assertMatchesRegularExpression(
            '/current_version\s*>=\s*11\s*\)\s*\{\s*return/s',
            self::$mariadb_php,
            'Должен быть fast-path return при current_version >= 11'
        );
    }

    public function test_migration_v11_bumps_db_version_to_11(): void
    {
        $this->assertMatchesRegularExpression(
            "/update_option\s*\(\s*'cashback_db_version'\s*,\s*11\b/",
            self::$mariadb_php,
            'Должен быть update_option(cashback_db_version, 11) в конце миграции v11'
        );
    }

    public function test_migration_v11_alters_click_sessions_promocode_id(): void
    {
        $body = $this->extract_method_body(self::$mariadb_php, 'migrate_promocodes_v11_session_promocode_id');
        // Имя таблицы хранится в локальной переменной → паттерн как в
        // ClickSessionsSchemaTest::test_migration_alters_click_log_click_session_id:
        // три отдельных assertion на ключевые токены тела миграции.
        $this->assertStringContainsString(
            'cashback_click_sessions',
            $body,
            'Migration v11 должна оперировать таблицей cashback_click_sessions'
        );
        $this->assertMatchesRegularExpression(
            '/ALTER\s+TABLE.*ADD\s+COLUMN.*`?promocode_id`?\s+BIGINT/is',
            $body,
            'Migration v11 должна ALTER TABLE ADD COLUMN promocode_id BIGINT (на cashback_click_sessions)'
        );
    }

    public function test_migration_v11_alters_promocode_clicks_affiliate_url(): void
    {
        $body = $this->extract_method_body(self::$mariadb_php, 'migrate_promocodes_v11_session_promocode_id');
        $this->assertStringContainsString(
            'cashback_promocode_clicks',
            $body,
            'Migration v11 должна оперировать таблицей cashback_promocode_clicks'
        );
        $this->assertMatchesRegularExpression(
            '/ALTER\s+TABLE.*ADD\s+COLUMN.*`?affiliate_url`?\s+VARCHAR/is',
            $body,
            'Migration v11 должна ALTER TABLE ADD COLUMN affiliate_url VARCHAR (на cashback_promocode_clicks)'
        );
    }

    public function test_migration_v11_uses_information_schema_guard(): void
    {
        $body = $this->extract_method_body(self::$mariadb_php, 'migrate_promocodes_v11_session_promocode_id');
        $this->assertMatchesRegularExpression(
            '/INFORMATION_SCHEMA/i',
            $body,
            'Migration должна использовать INFORMATION_SCHEMA guards (idempotency)'
        );
    }

    public function test_migration_v11_registered_in_activate(): void
    {
        $body = $this->extract_method_body(self::$mariadb_php, 'activate');
        $this->assertMatchesRegularExpression(
            '/\$instance->migrate_promocodes_v11_session_promocode_id\s*\(\s*\)/',
            $body,
            'migrate_promocodes_v11_session_promocode_id должна вызываться из Mariadb_Plugin::activate()'
        );
    }

    public function test_migration_v11_called_in_maybe_run_migrations(): void
    {
        $this->assertStringContainsString(
            'migrate_promocodes_v11_session_promocode_id',
            self::$cashback_plugin_php,
            'cashback-plugin.php должен вызывать migrate_promocodes_v11_session_promocode_id() в maybe_run_migrations (для existing installs)'
        );
    }

    // =====================================================================
    // 2. Fresh-install CREATE TABLE: новые колонки.
    // =====================================================================

    public function test_click_sessions_create_table_has_promocode_id_column(): void
    {
        $block = $this->extract_create_table_block(self::$mariadb_php, 'cashback_click_sessions');
        $this->assertMatchesRegularExpression(
            '/`promocode_id`\s+BIGINT/i',
            $block,
            'cashback_click_sessions CREATE TABLE должен содержать promocode_id BIGINT (fresh installs)'
        );
    }

    public function test_promocode_clicks_create_table_has_affiliate_url_column(): void
    {
        $block = $this->extract_create_table_block(self::$mariadb_php, 'cashback_promocode_clicks');
        $this->assertMatchesRegularExpression(
            '/`affiliate_url`\s+VARCHAR/i',
            $block,
            'cashback_promocode_clicks CREATE TABLE должен содержать affiliate_url VARCHAR (fresh installs)'
        );
    }

    // =====================================================================
    // 3. do_activate(): dedup-key + INSERT включают promocode_id.
    // =====================================================================

    public function test_do_activate_select_filters_by_promocode_id(): void
    {
        $body = $this->extract_method_body(self::$click_session_service_php, 'do_activate');
        // Любая из двух форм допустима: NULL-safe `<=>` или explicit branch IS NULL/= %d.
        $this->assertMatchesRegularExpression(
            '/promocode_id\s+IS\s+NULL|promocode_id\s*<=>\s*%d|promocode_id\s*=\s*%d/i',
            $body,
            'do_activate() SELECT FOR UPDATE должен фильтровать по promocode_id (IS NULL для product-кликов, = %d для промо-кликов)'
        );
    }

    public function test_do_activate_insert_includes_promocode_id_column(): void
    {
        $body = $this->extract_method_body(self::$click_session_service_php, 'do_activate');
        // INSERT блок: должен включить `promocode_id` в список колонок.
        $this->assertMatchesRegularExpression(
            '/INSERT\s+INTO\s+%i[\s\S]+?promocode_id[\s\S]+?VALUES/i',
            $body,
            'do_activate() INSERT в cashback_click_sessions должен включать колонку promocode_id'
        );
    }

    // =====================================================================
    // 4. Tracker: новый параметр $affiliate_url.
    // =====================================================================

    public function test_record_click_internal_signature_accepts_affiliate_url(): void
    {
        // Сигнатура: последний параметр ?string $affiliate_url = null
        $this->assertMatchesRegularExpression(
            '/public\s+static\s+function\s+record_click_internal\s*\([\s\S]*?\?string\s+\$affiliate_url\s*=\s*null[\s\S]*?\)\s*:\s*void/i',
            self::$promocodes_tracker_php,
            'record_click_internal() должен принимать optional ?string $affiliate_url = null'
        );
    }

    public function test_record_click_internal_inserts_affiliate_url_when_set(): void
    {
        // INSERT-блок должен содержать optional set $row['affiliate_url'] (graceful degrade pattern).
        $this->assertMatchesRegularExpression(
            '/\$row\[\s*[\'"]affiliate_url[\'"]\s*\]\s*=/i',
            self::$promocodes_tracker_php,
            'record_click_internal() должен записывать affiliate_url в $row при передаче не-null значения'
        );
    }

    // =====================================================================
    // 5. Redirect: проброс affiliate_url в record_click_internal.
    // =====================================================================

    public function test_redirect_handler_passes_affiliate_url_to_tracker(): void
    {
        // Success-путь: после activate_for_promocode → record_click_internal с $affiliate_url 8-м аргументом.
        // Граничное условие: вызов содержит $affiliate_url (а не только $click_id).
        $this->assertMatchesRegularExpression(
            '/Cashback_Promocodes_Tracker::record_click_internal\s*\([\s\S]*?\$affiliate_url[\s\S]*?\)\s*;/',
            self::$promocodes_redirect_php,
            'Cashback_Promocodes_Redirect должен передавать $affiliate_url в record_click_internal на success-пути'
        );
    }

    // =====================================================================
    // 6. Admin UI: колонка «Партнёрский URL» на вкладке «Промо клики».
    // =====================================================================

    public function test_admin_click_log_promo_tab_selects_affiliate_url(): void
    {
        $this->assertMatchesRegularExpression(
            '/pc\.affiliate_url/i',
            self::$admin_click_log_php,
            'admin/click-log.php SELECT для вкладки «Промо клики» должен выбирать pc.affiliate_url'
        );
    }

    public function test_admin_click_log_promo_tab_renders_affiliate_url_header(): void
    {
        // TH header «Партнёрский URL» (i18n строка через esc_html_e/esc_html__).
        $this->assertMatchesRegularExpression(
            '/Партнёрский\s+URL/u',
            self::$admin_click_log_php,
            'admin/click-log.php должен рендерить TH «Партнёрский URL» на вкладке «Промо клики»'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function extract_method_body( string $source, string $name ): string
    {
        $pattern = '/(?:public|private|protected)\s+(?:static\s+)?function\s+' . preg_quote($name, '/')
            . '\s*\([^)]*\)(?:\s*:\s*\??[\w\\\\]+)?\s*\{/';

        if (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            self::fail('Метод ' . $name . '() не найден в исходнике.');
        }

        $start = (int) $m[0][1];
        $brace = strpos($source, '{', $start);
        if ($brace === false) {
            self::fail('Нет открывающей скобки у ' . $name);
        }

        $depth = 0;
        $len   = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }
        self::fail('Нет закрывающей скобки у ' . $name);
    }

    private function extract_create_table_block( string $source, string $table_suffix ): string
    {
        $pattern = '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`\{\$wpdb->prefix\}' . preg_quote($table_suffix, '/') . '`\s*\(([\s\S]*?)\)\s*ENGINE=InnoDB/i';
        if (preg_match($pattern, $source, $m) !== 1) {
            self::fail('CREATE TABLE для ' . $table_suffix . ' не найден.');
        }
        return (string) $m[1];
    }
}
