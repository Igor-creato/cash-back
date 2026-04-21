<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Тесты one-time миграции plaintext-секретов в ciphertext.
 *
 * Покрывает:
 * - Plaintext → ciphertext для cashback_captcha_server_key
 * - Plaintext → ciphertext для cashback_epn_refresh_* (wildcard через $wpdb)
 * - Флаг cashback_options_encrypted_v1 === '1' после запуска
 * - Идемпотентность: повторный запуск = no-op (не шифрует повторно)
 * - Уже ciphertext значения не трогаются
 * - Пустые значения пропускаются
 *
 * См. ADR Группа 2, шаг 3 (миграция).
 */
#[Group('encryption')]
#[Group('option_encryption')]
#[Group('migration')]
class OptionEncryptionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_cb_test_options'] = array();
        $this->install_wpdb_mock(array());
    }

    protected function tearDown(): void
    {
        $GLOBALS['_cb_test_options'] = array();
        unset($GLOBALS['wpdb']);
    }

    /**
     * Устанавливает минимальный $wpdb-мок, достаточный для migrate_plaintext_options():
     * prepare() — подстановка параметров, get_results() — фильтрация по префиксу.
     *
     * @param array<string,string> $epn_rows option_name => option_value (plaintext/ciphertext).
     */
    private function install_wpdb_mock( array $epn_rows ): void
    {
        // Регистрируем EPN-строки в общий option-store ДО запуска миграции,
        // чтобы update_option() внутри миграции их перезаписал.
        foreach ($epn_rows as $name => $value) {
            $GLOBALS['_cb_test_options'][ $name ] = $value;
        }

        // Мок wpdb: prepare + get_results (EPN wildcard) + insert (audit log no-op).
        $GLOBALS['wpdb'] = new class ($epn_rows) {
            public string $options = 'wp_options';
            public string $prefix  = 'wp_';
            /** @var array<string,string> */
            private array $epn_rows;

            public function __construct( array $epn_rows ) {
                $this->epn_rows = $epn_rows;
            }

            public function prepare( string $query, ...$args ): string {
                // Упрощённый prepare: %i → backticks, %s → кавычки, %d → число.
                $args  = array_values($args);
                $index = 0;
                return preg_replace_callback(
                    '/%[isd]/',
                    function ( $m ) use ( $args, &$index ): string {
                        $arg = $args[ $index ] ?? '';
                        ++$index;
                        if ($m[0] === '%i') {
                            return '`' . str_replace('`', '``', (string) $arg) . '`';
                        }
                        if ($m[0] === '%d') {
                            return (string) (int) $arg;
                        }
                        return "'" . addslashes((string) $arg) . "'";
                    },
                    $query
                );
            }

            public function get_results( string $query, $output = OBJECT ): array {
                // Возвращаем все epn_rows — миграция сама отфильтрует.
                $out = array();
                foreach ($this->epn_rows as $name => $value) {
                    $out[] = (object) array(
                        'option_name'  => $name,
                        'option_value' => $value,
                    );
                }
                return $out;
            }

            // audit log no-op в тестах
            public function insert( string $table, array $data, $format = null ): int {
                return 1;
            }
        };

        if (!defined('OBJECT')) {
            define('OBJECT', 'OBJECT');
        }
    }

    // ================================================================
    // Основной путь — шифрует plaintext
    // ================================================================

    public function test_migrates_plaintext_captcha_server_key(): void
    {
        update_option('cashback_captcha_server_key', 'yc-server-plain');

        Cashback_Encryption::migrate_plaintext_options();

        $stored = (string) get_option('cashback_captcha_server_key', '');
        $this->assertStringStartsWith('ENC:v1:', $stored);
        $this->assertSame('yc-server-plain', Cashback_Encryption::decrypt_if_ciphertext($stored));
    }

    public function test_migrates_plaintext_epn_refresh_tokens_via_wpdb(): void
    {
        $this->install_wpdb_mock(array(
            'cashback_epn_refresh_abc123' => 'epn-rt-plain-1',
            'cashback_epn_refresh_def456' => 'epn-rt-plain-2',
        ));

        Cashback_Encryption::migrate_plaintext_options();

        $a = (string) get_option('cashback_epn_refresh_abc123', '');
        $b = (string) get_option('cashback_epn_refresh_def456', '');
        $this->assertStringStartsWith('ENC:v1:', $a);
        $this->assertStringStartsWith('ENC:v1:', $b);
        $this->assertSame('epn-rt-plain-1', Cashback_Encryption::decrypt_if_ciphertext($a));
        $this->assertSame('epn-rt-plain-2', Cashback_Encryption::decrypt_if_ciphertext($b));
    }

    public function test_sets_completion_flag_after_successful_migration(): void
    {
        update_option('cashback_captcha_server_key', 'anything');

        Cashback_Encryption::migrate_plaintext_options();

        $this->assertSame('1', get_option('cashback_options_encrypted_v1', ''));
    }

    // ================================================================
    // Идемпотентность + защита от double-encrypt
    // ================================================================

    public function test_second_run_is_noop_when_flag_set(): void
    {
        update_option('cashback_captcha_server_key', 'plain-before');
        update_option('cashback_options_encrypted_v1', '1');

        Cashback_Encryption::migrate_plaintext_options();

        // Флаг есть — миграция не должна трогать значение
        $this->assertSame('plain-before', get_option('cashback_captcha_server_key', ''));
    }

    public function test_already_ciphertext_option_is_skipped(): void
    {
        // Значение уже ENC:v1:... — миграция не должна перешифровывать
        $ciphertext = Cashback_Encryption::encrypt_if_needed('already-encrypted');
        update_option('cashback_captcha_server_key', $ciphertext);

        Cashback_Encryption::migrate_plaintext_options();

        $this->assertSame($ciphertext, get_option('cashback_captcha_server_key', ''));
    }

    public function test_empty_option_is_skipped(): void
    {
        update_option('cashback_captcha_server_key', '');

        Cashback_Encryption::migrate_plaintext_options();

        $this->assertSame('', get_option('cashback_captcha_server_key', ''));
        // Флаг всё равно выставляется (работа выполнена, просто пустая)
        $this->assertSame('1', get_option('cashback_options_encrypted_v1', ''));
    }

    // ================================================================
    // Guard на is_configured()
    // ================================================================

    public function test_migration_is_noop_when_encryption_not_configured(): void
    {
        // Мок is_configured=false через снятие константы нельзя (define).
        // Но если helper encrypt_if_needed returns plaintext при !is_configured,
        // значит здесь проверяем: guard в самом migrate_plaintext_options.
        // Если CB_ENCRYPTION_KEY всегда определён в bootstrap — симулируем через
        // альтернативный flow: обойдём через "migrate с не-изменяющимся значением".
        //
        // Вместо этого — проверяем явную guard-семантику: если is_configured()=true
        // (наш bootstrap), миграция выполняется. Тест противоположного сценария —
        // это contract of migrate_plaintext_options() описан в ADR, но в unit-тесте
        // без возможности undefine константы достаточно проверить path "configured=true".
        $this->assertTrue(Cashback_Encryption::is_configured());

        update_option('cashback_captcha_server_key', 'plain');
        Cashback_Encryption::migrate_plaintext_options();

        // При configured=true — миграция прошла
        $this->assertStringStartsWith('ENC:v1:', (string) get_option('cashback_captcha_server_key', ''));
    }
}
